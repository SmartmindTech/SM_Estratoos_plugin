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

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;
use context_coursecat;

/**
 * Get dashboard statistics in a single call.
 *
 * Returns course count, deadlines count, urgent count, to-grade count (for teachers),
 * and optional per-course breakdown. Reduces dashboard loading from ~3.7s to <300ms.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_dashboard_stats extends external_api {

    /**
     * Cache TTL in seconds.
     */
    const CACHE_TTL = 60;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'options' => new external_value(PARAM_RAW,
                'JSON options: include_progress, include_deadlines, include_to_grade, include_per_course',
                VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Get dashboard statistics.
     *
     * @param int $userid User ID.
     * @param string $options JSON options.
     * @return array Dashboard statistics.
     */
    public static function execute(int $userid, string $options = '{}'): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'options' => $options,
        ]);

        $opts = json_decode($params['options'], true) ?: [];
        $includeprogress = $opts['include_progress'] ?? true;
        $includedeadlines = $opts['include_deadlines'] ?? true;
        $includetograde = $opts['include_to_grade'] ?? true;
        $includepercourse = $opts['include_per_course'] ?? false;

        // Determine context based on IOMAD or standard Moodle.
        $companyid = 0;
        $context = context_system::instance();

        try {
            if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
                $token = \local_sm_estratoos_plugin\util::get_current_request_token();
                if ($token) {
                    $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                    if ($restrictions && !empty($restrictions->companyid)) {
                        $companyid = $restrictions->companyid;

                        // IOMAD: validate at category context.
                        $company = $DB->get_record('company', ['id' => $companyid]);
                        if ($company && !empty($company->category)) {
                            $context = context_coursecat::instance($company->category);
                        }
                    }
                }
            }
        } catch (\dml_exception $e) {
            // Database error - fall back to standard Moodle mode.
            debugging('get_dashboard_stats: IOMAD database error, falling back - ' . $e->getMessage(), DEBUG_DEVELOPER);
            $companyid = 0;
            $context = context_system::instance();
        } catch (\Exception $e) {
            // Other errors - fall back to standard Moodle mode.
            debugging('get_dashboard_stats: IOMAD error, falling back - ' . $e->getMessage(), DEBUG_DEVELOPER);
            $companyid = 0;
            $context = context_system::instance();
        }

        self::validate_context($context);

        // Check permission to view other users' data.
        if ($USER->id != $params['userid']) {
            require_capability('moodle/user:viewdetails', $context);
        }

        // Check cache first.
        $cache = \cache::make('local_sm_estratoos_plugin', 'dashboard_stats');
        $cachekey = "stats_{$params['userid']}_" . md5($params['options']) . "_{$companyid}";
        $cached = $cache->get($cachekey);

        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        $now = time();
        $userid = $params['userid'];

        // Build result.
        $result = [
            'courses_count' => 0,
            'to_grade_count' => 0,
            'deadlines_count' => 0,
            'urgent_count' => 0,
            'courses' => [],
            'cached' => false,
            'cache_expires' => $now + self::CACHE_TTL,
        ];

        // Get enrolled course IDs for this user.
        $courseids = self::get_enrolled_course_ids($userid, $companyid);
        $result['courses_count'] = count($courseids);

        if (empty($courseids)) {
            $cache->set($cachekey, $result);
            return $result;
        }

        // Get deadlines count (events in next 90 days).
        if ($includedeadlines) {
            $deadlineinfo = self::get_deadlines_info($userid, $courseids, $now);
            $result['deadlines_count'] = $deadlineinfo['total'];
            $result['urgent_count'] = $deadlineinfo['urgent'];
        }

        // Get to-grade count (only for teachers/managers).
        if ($includetograde) {
            $result['to_grade_count'] = self::get_to_grade_count($userid, $courseids);
        }

        // Get per-course stats if requested.
        if ($includepercourse) {
            $result['courses'] = self::get_per_course_stats($userid, $courseids, $companyid, $now);
        }

        // Cache the result.
        $cache->set($cachekey, $result);

        return $result;
    }

    /**
     * Get enrolled course IDs for user.
     *
     * @param int $userid User ID.
     * @param int $companyid Company ID (0 for no filtering).
     * @return array Course IDs.
     */
    private static function get_enrolled_course_ids(int $userid, int $companyid = 0): array {
        global $DB;

        $companyjoin = '';
        $companywhere = '';
        $params = ['userid' => $userid];

        if ($companyid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $companyjoin = "JOIN {company_course} cc ON cc.courseid = e.courseid";
            $companywhere = "AND cc.companyid = :companyid";
            $params['companyid'] = $companyid;
        }

        $sql = "SELECT DISTINCT e.courseid
                FROM {user_enrolments} ue
                JOIN {enrol} e ON ue.enrolid = e.id
                $companyjoin
                WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0 AND e.courseid != 1
                $companywhere";

        $records = $DB->get_records_sql($sql, $params);
        return array_keys($records);
    }

    /**
     * Get deadlines info (total and urgent).
     *
     * @param int $userid User ID.
     * @param array $courseids Course IDs.
     * @param int $now Current timestamp.
     * @return array Deadlines info.
     */
    private static function get_deadlines_info(int $userid, array $courseids, int $now): array {
        global $DB;

        if (empty($courseids)) {
            return ['total' => 0, 'urgent' => 0];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');
        $deadlinelimit = $now + (90 * 24 * 60 * 60); // 90 days
        $urgentlimit = $now + 86400; // 24 hours

        // Count total deadlines (90 days).
        $sql = "SELECT COUNT(*) as count
                FROM {event} e
                WHERE e.timestart > :now AND e.timestart < :deadline_limit
                AND (e.userid = :userid OR e.courseid $insql)
                AND e.eventtype IN ('due', 'user', 'course', 'site')";

        $params = array_merge([
            'now' => $now,
            'deadline_limit' => $deadlinelimit,
            'userid' => $userid,
        ], $inparams);

        $total = (int)$DB->count_records_sql($sql, $params);

        // Count urgent deadlines (24 hours).
        $sql = "SELECT COUNT(*) as count
                FROM {event} e
                WHERE e.timestart > :now AND e.timestart < :urgent_limit
                AND (e.userid = :userid OR e.courseid $insql)
                AND e.eventtype IN ('due', 'user', 'course', 'site')";

        $params = array_merge([
            'now' => $now,
            'urgent_limit' => $urgentlimit,
            'userid' => $userid,
        ], $inparams);

        $urgent = (int)$DB->count_records_sql($sql, $params);

        return ['total' => $total, 'urgent' => $urgent];
    }

    /**
     * Get to-grade count for teachers/managers.
     *
     * @param int $userid User ID.
     * @param array $courseids Course IDs.
     * @return int To-grade count.
     */
    private static function get_to_grade_count(int $userid, array $courseids): int {
        global $DB;

        if (empty($courseids)) {
            return 0;
        }

        // First check if user is teacher/manager in any course.
        $isteacher = $DB->record_exists_sql("
            SELECT 1
            FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid
            WHERE ra.userid = :userid AND r.archetype IN ('editingteacher', 'teacher', 'manager')
        ", ['userid' => $userid]);

        if (!$isteacher) {
            return 0;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');

        // Count submissions that need grading.
        // A submission needs grading if:
        // 1. It's status = 'submitted'
        // 2. It's the latest submission
        // 3. There's no grade record OR grade is older than submission.
        $sql = "SELECT COUNT(DISTINCT asub.id) as count
                FROM {assign_submission} asub
                JOIN {assign} a ON asub.assignment = a.id
                WHERE a.course $insql
                AND asub.status = 'submitted'
                AND asub.latest = 1
                AND NOT EXISTS (
                    SELECT 1 FROM {assign_grades} ag
                    WHERE ag.assignment = asub.assignment
                    AND ag.userid = asub.userid
                    AND ag.timemodified > asub.timemodified
                )";

        return (int)$DB->count_records_sql($sql, $inparams);
    }

    /**
     * Get per-course statistics.
     *
     * @param int $userid User ID.
     * @param array $courseids Course IDs.
     * @param int $companyid Company ID.
     * @param int $now Current timestamp.
     * @return array Per-course stats.
     */
    private static function get_per_course_stats(int $userid, array $courseids, int $companyid, int $now): array {
        global $DB;

        if (empty($courseids)) {
            return [];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'course');

        // Get course info.
        $sql = "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id $insql ORDER BY c.sortorder";
        $courses = $DB->get_records_sql($sql, $inparams);

        // Get progress for each course.
        $progresssql = "SELECT cm.course,
                               COUNT(cm.id) as total_activities,
                               SUM(CASE WHEN cmc.completionstate IN (1, 2) THEN 1 ELSE 0 END) as completed
                        FROM {course_modules} cm
                        LEFT JOIN {course_modules_completion} cmc
                            ON cmc.coursemoduleid = cm.id AND cmc.userid = :userid
                        WHERE cm.completion > 0
                        AND cm.deletioninprogress = 0
                        AND cm.visible = 1
                        AND cm.course $insql
                        GROUP BY cm.course";

        $progressparams = array_merge(['userid' => $userid], $inparams);
        $progressdata = $DB->get_records_sql($progresssql, $progressparams);

        // Get deadlines per course.
        $deadlinelimit = $now + (90 * 24 * 60 * 60);
        $urgentlimit = $now + 86400;

        $deadlinessql = "SELECT e.courseid,
                                COUNT(*) as deadline_count,
                                SUM(CASE WHEN e.timestart < :urgent_limit THEN 1 ELSE 0 END) as urgent_count
                         FROM {event} e
                         WHERE e.timestart > :now AND e.timestart < :deadline_limit
                         AND e.courseid $insql
                         AND e.eventtype IN ('due', 'user', 'course', 'site')
                         GROUP BY e.courseid";

        $deadlineparams = array_merge([
            'now' => $now,
            'deadline_limit' => $deadlinelimit,
            'urgent_limit' => $urgentlimit,
        ], $inparams);
        $deadlinesdata = $DB->get_records_sql($deadlinessql, $deadlineparams);

        // Get next deadline per course.
        $nextdeadlinesql = "SELECT e.courseid, e.name, e.timestart, e.eventtype,
                                   ROW_NUMBER() OVER (PARTITION BY e.courseid ORDER BY e.timestart) as rn
                            FROM {event} e
                            WHERE e.timestart > :now
                            AND e.courseid $insql
                            AND e.eventtype IN ('due', 'user', 'course', 'site')";

        $nextdeadlineparams = array_merge(['now' => $now], $inparams);

        // Some databases don't support window functions, so use a subquery approach.
        try {
            $nextdeadlines = [];
            $nextdeadlinesql = "SELECT e.courseid, e.name, e.timestart, e.eventtype
                                FROM {event} e
                                WHERE e.timestart > :now
                                AND e.courseid $insql
                                AND e.eventtype IN ('due', 'user', 'course', 'site')
                                ORDER BY e.timestart";
            $allevents = $DB->get_records_sql($nextdeadlinesql, $nextdeadlineparams);
            foreach ($allevents as $event) {
                if (!isset($nextdeadlines[$event->courseid])) {
                    $nextdeadlines[$event->courseid] = $event;
                }
            }
        } catch (\Exception $e) {
            $nextdeadlines = [];
        }

        // Build result.
        $result = [];
        foreach ($courses as $course) {
            $cid = $course->id;
            $progress = 0;
            if (isset($progressdata[$cid]) && $progressdata[$cid]->total_activities > 0) {
                $progress = (int)round(($progressdata[$cid]->completed / $progressdata[$cid]->total_activities) * 100);
            }

            $deadlinecount = isset($deadlinesdata[$cid]) ? (int)$deadlinesdata[$cid]->deadline_count : 0;
            $urgentcount = isset($deadlinesdata[$cid]) ? (int)$deadlinesdata[$cid]->urgent_count : 0;

            $nextdeadline = null;
            if (isset($nextdeadlines[$cid])) {
                $nd = $nextdeadlines[$cid];
                $daysremaining = max(0, (int)floor(($nd->timestart - $now) / 86400));
                $nextdeadline = [
                    'name' => $nd->name,
                    'timestart' => (int)$nd->timestart,
                    'days_remaining' => $daysremaining,
                    'type' => $nd->eventtype,
                ];
            }

            $result[] = [
                'id' => (int)$cid,
                'fullname' => format_string($course->fullname),
                'shortname' => $course->shortname,
                'progress' => $progress,
                'to_grade_count' => 0, // Would need per-course teacher check.
                'deadlines_count' => $deadlinecount,
                'urgent_count' => $urgentcount,
                'next_deadline' => $nextdeadline,
            ];
        }

        return $result;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses_count' => new external_value(PARAM_INT, 'Total enrolled courses'),
            'to_grade_count' => new external_value(PARAM_INT, 'Submissions needing grading (teachers only)'),
            'deadlines_count' => new external_value(PARAM_INT, 'Deadlines in next 90 days'),
            'urgent_count' => new external_value(PARAM_INT, 'Deadlines in next 24 hours'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_RAW, 'Course full name'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'progress' => new external_value(PARAM_INT, 'Progress percentage'),
                    'to_grade_count' => new external_value(PARAM_INT, 'To grade count'),
                    'deadlines_count' => new external_value(PARAM_INT, 'Deadlines count'),
                    'urgent_count' => new external_value(PARAM_INT, 'Urgent deadlines count'),
                    'next_deadline' => new external_single_structure([
                        'name' => new external_value(PARAM_RAW, 'Deadline name'),
                        'timestart' => new external_value(PARAM_INT, 'Deadline timestamp'),
                        'days_remaining' => new external_value(PARAM_INT, 'Days remaining'),
                        'type' => new external_value(PARAM_RAW, 'Event type'),
                    ], 'Next deadline info', VALUE_OPTIONAL),
                ]),
                'Per-course statistics (if include_per_course is true)',
                VALUE_OPTIONAL
            ),
            'cached' => new external_value(PARAM_BOOL, 'From cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry timestamp'),
        ]);
    }
}
