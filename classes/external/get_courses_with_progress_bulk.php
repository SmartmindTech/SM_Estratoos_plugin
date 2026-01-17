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
use cache;

/**
 * Bulk course fetch with completion and grades.
 *
 * Performance target: 500 courses in < 3 seconds
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_courses_with_progress_bulk extends external_api {

    const CACHE_TTL = 120;
    const MAX_PER_PAGE = 500;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'include_completion' => new external_value(PARAM_BOOL, 'Include completion', VALUE_DEFAULT, true),
            'include_grades' => new external_value(PARAM_BOOL, 'Include grades', VALUE_DEFAULT, true),
            'include_teachers' => new external_value(PARAM_BOOL, 'Include teachers', VALUE_DEFAULT, false),
            'visible_only' => new external_value(PARAM_BOOL, 'Visible only', VALUE_DEFAULT, true),
            'page' => new external_value(PARAM_INT, 'Page number', VALUE_DEFAULT, 0),
            'per_page' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 100),
        ]);
    }

    public static function execute(
        int $userid,
        bool $include_completion = true,
        bool $include_grades = true,
        bool $include_teachers = false,
        bool $visible_only = true,
        int $page = 0,
        int $per_page = 100
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'include_completion' => $include_completion,
            'include_grades' => $include_grades,
            'include_teachers' => $include_teachers,
            'visible_only' => $visible_only,
            'page' => $page,
            'per_page' => $per_page,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $per_page = min($per_page, self::MAX_PER_PAGE);
        $offset = $page * $per_page;

        // Cache key.
        $cache_key = "courses_progress_{$userid}_{$include_completion}_{$include_grades}_{$include_teachers}_{$visible_only}_{$page}_{$per_page}";
        $cache = cache::make('local_sm_estratoos_plugin', 'course_progress');
        $cached = $cache->get($cache_key);
        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        // Build visibility condition.
        $visible_condition = $visible_only ? "AND c.visible = 1" : "";

        // Count total.
        $count_sql = "
            SELECT COUNT(DISTINCT c.id)
            FROM {course} c
            JOIN {enrol} e ON e.courseid = c.id
            JOIN {user_enrolments} ue ON ue.enrolid = e.id
            WHERE ue.userid = :userid
              AND ue.status = 0
              AND e.status = 0
              AND c.id != 1
              {$visible_condition}
        ";
        $total_count = $DB->count_records_sql($count_sql, ['userid' => $userid]);

        // Main query.
        $main_sql = "
            SELECT DISTINCT
                c.id,
                c.fullname,
                c.shortname,
                c.summary,
                c.startdate,
                c.enddate,
                c.visible,
                c.format,
                cc.id as categoryid,
                cc.name as categoryname,
                ue.timestart as enroldate,
                ue.timeend as enrolend
            FROM {course} c
            JOIN {enrol} e ON e.courseid = c.id
            JOIN {user_enrolments} ue ON ue.enrolid = e.id
            LEFT JOIN {course_categories} cc ON cc.id = c.category
            WHERE ue.userid = :userid
              AND ue.status = 0
              AND e.status = 0
              AND c.id != 1
              {$visible_condition}
            ORDER BY c.fullname ASC
        ";

        $courses_raw = $DB->get_records_sql($main_sql, ['userid' => $userid], $offset, $per_page);

        // Get course IDs for batch operations.
        $course_ids = array_keys($courses_raw);

        // Batch fetch completion data.
        $completion_by_course = [];
        if ($include_completion && !empty($course_ids)) {
            list($in_sql, $in_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $in_params['userid'] = $userid;

            $completion_sql = "
                SELECT
                    cm.course,
                    SUM(CASE WHEN cmc.completionstate > 0 THEN 1 ELSE 0 END) as completed_modules,
                    COUNT(cm.id) as total_modules
                FROM {course_modules} cm
                LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id
                    AND cmc.userid = :userid
                WHERE cm.course {$in_sql}
                  AND cm.completion > 0
                  AND cm.visible = 1
                  AND cm.deletioninprogress = 0
                GROUP BY cm.course
            ";

            $completion_records = $DB->get_records_sql($completion_sql, $in_params);
            foreach ($completion_records as $record) {
                $total = (int)$record->total_modules;
                $completed = (int)$record->completed_modules;
                $completion_by_course[$record->course] = [
                    'completed_modules' => $completed,
                    'total_modules' => $total,
                    'progress' => $total > 0 ? round(100.0 * $completed / $total, 1) : 0,
                ];
            }
        }

        // Batch fetch grades.
        $grades_by_course = [];
        if ($include_grades && !empty($course_ids)) {
            list($in_sql, $in_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
            $in_params['userid'] = $userid;

            $grades_sql = "
                SELECT gi.courseid, gg.finalgrade, gi.grademax
                FROM {grade_items} gi
                JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                WHERE gi.courseid {$in_sql}
                  AND gi.itemtype = 'course'
            ";

            $grades_records = $DB->get_records_sql($grades_sql, $in_params);
            foreach ($grades_records as $record) {
                $max = (float)$record->grademax;
                $final = (float)($record->finalgrade ?? 0);
                $grades_by_course[$record->courseid] = [
                    'course_grade' => $final,
                    'course_grade_max' => $max > 0 ? $max : 100,
                    'grade_percentage' => $max > 0 ? round(100.0 * $final / $max, 1) : 0,
                ];
            }
        }

        // Batch fetch teachers if requested.
        $teachers_by_course = [];
        if ($include_teachers && !empty($course_ids)) {
            $teachers_by_course = self::get_teachers_for_courses($course_ids);
        }

        // Build result.
        $courses = [];
        foreach ($courses_raw as $course) {
            $course_data = [
                'id' => (int)$course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'summary' => strip_tags($course->summary ?? ''),
                'startdate' => (int)$course->startdate,
                'enddate' => (int)($course->enddate ?? 0),
                'visible' => (bool)$course->visible,
                'format' => $course->format,
                'categoryid' => (int)($course->categoryid ?? 0),
                'categoryname' => $course->categoryname ?? '',
                'enroldate' => (int)($course->enroldate ?? 0),
                'enrolend' => (int)($course->enrolend ?? 0),
            ];

            if ($include_completion) {
                $cp = $completion_by_course[$course->id] ?? [
                    'completed_modules' => 0,
                    'total_modules' => 0,
                    'progress' => 0,
                ];
                $course_data['completed_modules'] = $cp['completed_modules'];
                $course_data['total_modules'] = $cp['total_modules'];
                $course_data['progress'] = $cp['progress'];
            }

            if ($include_grades) {
                $gd = $grades_by_course[$course->id] ?? [
                    'course_grade' => 0,
                    'course_grade_max' => 100,
                    'grade_percentage' => 0,
                ];
                $course_data['course_grade'] = $gd['course_grade'];
                $course_data['course_grade_max'] = $gd['course_grade_max'];
                $course_data['grade_percentage'] = $gd['grade_percentage'];
            }

            if ($include_teachers) {
                $course_data['teachers'] = $teachers_by_course[$course->id] ?? [];
            }

            $courses[] = $course_data;
        }

        $result = [
            'courses' => $courses,
            'total_count' => (int)$total_count,
            'page' => $page,
            'per_page' => $per_page,
            'has_more' => ($offset + count($courses)) < $total_count,
            'cached' => false,
            'cache_expires' => time() + self::CACHE_TTL,
        ];

        $cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Batch fetch teachers for multiple courses.
     */
    private static function get_teachers_for_courses(array $course_ids): array {
        global $DB;

        if (empty($course_ids)) {
            return [];
        }

        list($in_sql, $params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);

        // Get users with editingteacher or teacher role in these courses.
        $sql = "
            SELECT DISTINCT
                c.id as courseid,
                u.id as userid,
                u.firstname,
                u.lastname,
                u.email,
                r.shortname as role
            FROM {course} c
            JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
            JOIN {role_assignments} ra ON ra.contextid = ctx.id
            JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
            JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
            WHERE c.id {$in_sql}
            ORDER BY r.shortname DESC, u.lastname ASC
        ";

        $records = $DB->get_records_sql($sql, $params);

        $teachers_by_course = [];
        foreach ($records as $record) {
            if (!isset($teachers_by_course[$record->courseid])) {
                $teachers_by_course[$record->courseid] = [];
            }
            $teachers_by_course[$record->courseid][] = [
                'id' => (int)$record->userid,
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'fullname' => $record->firstname . ' ' . $record->lastname,
                'email' => $record->email,
                'role' => $record->role,
            ];
        }

        return $teachers_by_course;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_RAW, 'Full name'),
                    'shortname' => new external_value(PARAM_RAW, 'Short name'),
                    'summary' => new external_value(PARAM_RAW, 'Summary'),
                    'startdate' => new external_value(PARAM_INT, 'Start date'),
                    'enddate' => new external_value(PARAM_INT, 'End date'),
                    'visible' => new external_value(PARAM_BOOL, 'Visible'),
                    'format' => new external_value(PARAM_RAW, 'Format'),
                    'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                    'categoryname' => new external_value(PARAM_RAW, 'Category name'),
                    'enroldate' => new external_value(PARAM_INT, 'Enrol date'),
                    'enrolend' => new external_value(PARAM_INT, 'Enrol end'),
                    'completed_modules' => new external_value(PARAM_INT, 'Completed modules', VALUE_OPTIONAL),
                    'total_modules' => new external_value(PARAM_INT, 'Total modules', VALUE_OPTIONAL),
                    'progress' => new external_value(PARAM_FLOAT, 'Progress percentage', VALUE_OPTIONAL),
                    'course_grade' => new external_value(PARAM_FLOAT, 'Course grade', VALUE_OPTIONAL),
                    'course_grade_max' => new external_value(PARAM_FLOAT, 'Max grade', VALUE_OPTIONAL),
                    'grade_percentage' => new external_value(PARAM_FLOAT, 'Grade percentage', VALUE_OPTIONAL),
                    'teachers' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Teacher ID'),
                            'firstname' => new external_value(PARAM_RAW, 'First name'),
                            'lastname' => new external_value(PARAM_RAW, 'Last name'),
                            'fullname' => new external_value(PARAM_RAW, 'Full name'),
                            'email' => new external_value(PARAM_RAW, 'Email'),
                            'role' => new external_value(PARAM_RAW, 'Role'),
                        ]),
                        'Teachers',
                        VALUE_OPTIONAL
                    ),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total count'),
            'page' => new external_value(PARAM_INT, 'Page'),
            'per_page' => new external_value(PARAM_INT, 'Per page'),
            'has_more' => new external_value(PARAM_BOOL, 'Has more'),
            'cached' => new external_value(PARAM_BOOL, 'Cached'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expires'),
        ]);
    }
}
