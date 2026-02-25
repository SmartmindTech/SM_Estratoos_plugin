<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Report engine — builds and executes dynamic SQL from a list of variables.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\reporting;

defined('MOODLE_INTERNAL') || die();

class report_engine {

    /** @var int Hard row limit to prevent runaway queries. */
    const MAX_ROWS = 10000;

    /**
     * Generate report data.
     *
     * @param array  $variables  Variable names from the catalog.
     * @param int    $companyid  IOMAD company ID (0 = no company filter).
     * @param array  $filters    Optional: courseid, userid, datefrom, dateto.
     * @param int    $limit      Max rows.
     * @param int    $offset     Row offset for pagination.
     * @return array {headers: [], rows: [], total_rows: int, grain: string, has_more: bool}
     */
    public static function generate(
        array $variables,
        int $companyid = 0,
        array $filters = [],
        int $limit = 1000,
        int $offset = 0
    ): array {
        global $DB;

        $catalog = variable_catalog::get_catalog();
        $grain = variable_catalog::determine_grain($variables);
        $limit = min($limit, self::MAX_ROWS);

        // Build SELECT columns.
        $selects = [];
        $headers = [];
        $requiredalljoinkeys = [];
        $postprocessors = [];

        // Always include identification columns for post-processing.
        $needsactivityname = false;
        $needsprogress = false;

        foreach ($variables as $var) {
            if (!isset($catalog[$var])) {
                continue;
            }
            $meta = $catalog[$var];
            $selects[] = $meta['select'] . ' AS ' . $meta['alias'];
            $headers[] = [
                'key' => $meta['alias'],
                'label' => $meta['label'],
                'type' => $meta['type'],
            ];
            foreach ($meta['joins'] as $jk) {
                $requiredalljoinkeys[$jk] = true;
            }
            if (!empty($meta['post_process'])) {
                if ($meta['post_process'] === 'activity_name') {
                    $needsactivityname = true;
                }
                if ($meta['post_process'] === 'progress') {
                    $needsprogress = true;
                }
                $postprocessors[$meta['alias']] = $meta['post_process'];
            }
        }

        if (empty($selects)) {
            return ['headers' => [], 'rows' => [], 'total_rows' => 0, 'grain' => $grain, 'has_more' => false];
        }

        // For activity_name post-processing we need cm.id, m.name, cm.instance in the result.
        if ($needsactivityname) {
            if (!in_array('cm.id AS _cm_id', $selects)) {
                $selects[] = 'cm.id AS _cm_id';
            }
            if (!in_array('m.name AS _mod_name', $selects)) {
                $selects[] = 'm.name AS _mod_name';
            }
            if (!in_array('cm.instance AS _cm_instance', $selects)) {
                $selects[] = 'cm.instance AS _cm_instance';
            }
        }

        // For progress post-processing we need u.id and c.id.
        if ($needsprogress) {
            if (!in_array('u.id AS _uid', $selects)) {
                $selects[] = 'u.id AS _uid';
            }
            if (!in_array('c.id AS _cid', $selects)) {
                $selects[] = 'c.id AS _cid';
            }
        }

        $joinkeys = array_keys($requiredalljoinkeys);

        // Build SQL.
        $selectsql = implode(",\n       ", $selects);
        $joinsql = self::build_joins($joinkeys);
        list($wheresql, $params) = self::build_where($companyid, $filters, $grain);

        // ORDER BY — deterministic ordering.
        $orderby = 'u.lastname, u.firstname, u.id';
        if ($grain === 'course' || $grain === 'activity') {
            $orderby .= ', c.fullname, c.id';
        }
        if ($grain === 'activity') {
            $orderby .= ', cm.id';
        }

        // Count query.
        $countsql = "SELECT COUNT(*) FROM {user} u {$joinsql} {$wheresql}";
        $totalrows = $DB->count_records_sql($countsql, $params);

        // Data query.
        $sql = "SELECT {$selectsql}
                FROM {user} u
                {$joinsql}
                {$wheresql}
                ORDER BY {$orderby}";

        $rows = $DB->get_records_sql($sql, $params, $offset, $limit);
        $rows = array_values($rows); // Re-index.

        // Post-processing.
        if ($needsactivityname) {
            $rows = self::resolve_activity_names($rows);
        }
        if ($needsprogress) {
            $rows = self::resolve_progress($rows);
        }

        // Clean internal columns and convert to plain arrays.
        $cleanrows = [];
        foreach ($rows as $row) {
            $rowdata = (array) $row;
            // Remove internal helper columns.
            unset($rowdata['_cm_id'], $rowdata['_mod_name'], $rowdata['_cm_instance']);
            unset($rowdata['_uid'], $rowdata['_cid']);
            $cleanrows[] = $rowdata;
        }

        return [
            'headers' => $headers,
            'rows' => $cleanrows,
            'total_rows' => (int) $totalrows,
            'grain' => $grain,
            'has_more' => ($offset + $limit) < $totalrows,
        ];
    }

    /**
     * Build JOIN clauses from required join keys.
     * Order matters — dependencies are resolved automatically.
     *
     * @param array $joinkeys
     * @return string SQL JOIN fragment.
     */
    private static function build_joins(array $joinkeys): string {
        $registry = [
            'enrol' =>
                "JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                 JOIN {enrol} e ON e.id = ue.enrolid AND e.status = 0",

            'course' =>
                "JOIN {course} c ON c.id = e.courseid AND c.id != 1",

            'role' =>
                "LEFT JOIN (
                    SELECT ra.userid, ctx.instanceid AS courseid, r.shortname
                    FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                    JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = 50
                    WHERE ra.id = (
                        SELECT MIN(ra2.id) FROM {role_assignments} ra2
                        JOIN {context} ctx2 ON ctx2.id = ra2.contextid AND ctx2.contextlevel = 50
                        WHERE ra2.userid = ra.userid AND ctx2.instanceid = ctx.instanceid
                    )
                ) role_sub ON role_sub.userid = u.id AND role_sub.courseid = c.id",

            'completion' =>
                "LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = c.id",

            'grade' =>
                "LEFT JOIN {grade_items} gi ON gi.courseid = c.id AND gi.itemtype = 'course'
                 LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = u.id",

            'activity' =>
                "JOIN {course_modules} cm ON cm.course = c.id AND cm.deletioninprogress = 0
                 JOIN {modules} m ON m.id = cm.module",

            'activity_completion' =>
                "LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = u.id",

            'activity_grade' =>
                "LEFT JOIN {grade_items} agi ON agi.courseid = c.id AND agi.itemtype = 'mod'
                     AND agi.itemmodule = m.name AND agi.iteminstance = cm.instance
                 LEFT JOIN {grade_grades} agg ON agg.itemid = agi.id AND agg.userid = u.id",
        ];

        // Dependency ordering: a join key may depend on another.
        $order = ['enrol', 'course', 'role', 'completion', 'grade', 'activity', 'activity_completion', 'activity_grade'];
        $parts = [];
        $added = [];

        foreach ($order as $key) {
            if (in_array($key, $joinkeys) && !isset($added[$key])) {
                $parts[] = $registry[$key];
                $added[$key] = true;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Build WHERE clause with company filter and optional filters.
     *
     * @param int   $companyid
     * @param array $filters
     * @param string $grain
     * @return array [sql_string, params_array]
     */
    private static function build_where(int $companyid, array $filters, string $grain): array {
        $conditions = ['u.deleted = 0', 'u.suspended = 0'];
        $params = [];

        // Company filter (IOMAD).
        if ($companyid > 0 && \local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $conditions[] = "u.id IN (SELECT cu.userid FROM {company_users} cu WHERE cu.companyid = :companyid)";
            $params['companyid'] = $companyid;
        }

        // Optional filters.
        if (!empty($filters['courseid']) && ($grain === 'course' || $grain === 'activity')) {
            $conditions[] = 'c.id = :filtercourseid';
            $params['filtercourseid'] = (int) $filters['courseid'];
        }
        if (!empty($filters['userid'])) {
            $conditions[] = 'u.id = :filteruserid';
            $params['filteruserid'] = (int) $filters['userid'];
        }
        if (!empty($filters['datefrom']) && ($grain === 'course' || $grain === 'activity')) {
            $conditions[] = 'ue.timecreated >= :datefrom';
            $params['datefrom'] = (int) $filters['datefrom'];
        }
        if (!empty($filters['dateto']) && ($grain === 'course' || $grain === 'activity')) {
            $conditions[] = 'ue.timecreated <= :dateto';
            $params['dateto'] = (int) $filters['dateto'];
        }

        $sql = 'WHERE ' . implode(' AND ', $conditions);
        return [$sql, $params];
    }

    /**
     * Resolve activity names by batch-querying each module table.
     *
     * @param array $rows
     * @return array
     */
    private static function resolve_activity_names(array $rows): array {
        global $DB;

        // Group instances by module type.
        $groups = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            if (!empty($row->_mod_name) && !empty($row->_cm_instance)) {
                $groups[$row->_mod_name][$row->_cm_instance] = true;
            }
        }

        // Batch-query each module table for names.
        $namemap = []; // "modname:instanceid" => name
        $dbman = $DB->get_manager();
        foreach ($groups as $modname => $instances) {
            $ids = array_keys($instances);
            if (empty($ids) || !$dbman->table_exists($modname)) {
                continue;
            }
            list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
            try {
                $records = $DB->get_records_select($modname, "id {$insql}", $inparams, '', 'id, name');
                foreach ($records as $rec) {
                    $namemap["{$modname}:{$rec->id}"] = $rec->name;
                }
            } catch (\Exception $e) {
                // Module table may not have a 'name' column — skip.
                continue;
            }
        }

        // Map names back to rows.
        foreach ($rows as &$row) {
            $row = (array) $row;
            if (isset($row['activity_name']) && !empty($row['_mod_name']) && !empty($row['_cm_instance'])) {
                $key = $row['_mod_name'] . ':' . $row['_cm_instance'];
                $row['activity_name'] = $namemap[$key] ?? ($row['_mod_name'] . ' #' . $row['_cm_instance']);
            }
            $row = (object) $row;
        }
        unset($row);

        return $rows;
    }

    /**
     * Resolve completion progress percentages.
     *
     * For each (user, course) pair, calculates:
     *   completed_trackable_activities / total_trackable_activities * 100
     *
     * @param array $rows
     * @return array
     */
    private static function resolve_progress(array $rows): array {
        global $DB;

        // Collect unique (userid, courseid) pairs.
        $pairs = [];
        foreach ($rows as $row) {
            $row = (object) $row;
            if (isset($row->_uid) && isset($row->_cid)) {
                $pairs["{$row->_uid}:{$row->_cid}"] = ['uid' => $row->_uid, 'cid' => $row->_cid];
            }
        }

        if (empty($pairs)) {
            return $rows;
        }

        // For each course, get total trackable modules.
        $courseids = array_unique(array_column($pairs, 'cid'));
        $coursetotals = [];
        foreach ($courseids as $cid) {
            $coursetotals[$cid] = $DB->count_records_select(
                'course_modules',
                'course = :cid AND completion > 0 AND deletioninprogress = 0',
                ['cid' => $cid]
            );
        }

        // For each pair, get completed count.
        $progressmap = [];
        foreach ($pairs as $key => $pair) {
            $total = $coursetotals[$pair['cid']] ?? 0;
            if ($total === 0) {
                $progressmap[$key] = 0.0;
                continue;
            }
            $completed = $DB->count_records_sql(
                "SELECT COUNT(*)
                   FROM {course_modules_completion} cmc
                   JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                  WHERE cmc.userid = :uid AND cm.course = :cid
                    AND cmc.completionstate > 0 AND cm.completion > 0 AND cm.deletioninprogress = 0",
                ['uid' => $pair['uid'], 'cid' => $pair['cid']]
            );
            $progressmap[$key] = round(($completed / $total) * 100, 1);
        }

        // Map back.
        foreach ($rows as &$row) {
            $row = (array) $row;
            if (isset($row['completion_progress']) && isset($row['_uid']) && isset($row['_cid'])) {
                $key = $row['_uid'] . ':' . $row['_cid'];
                $row['completion_progress'] = $progressmap[$key] ?? 0.0;
            }
            $row = (object) $row;
        }
        unset($row);

        return $rows;
    }
}
