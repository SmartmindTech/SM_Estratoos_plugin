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
 * Single-call dashboard summary with all user data.
 *
 * Performance target: < 500ms (vs 2-5 seconds with multiple calls)
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_dashboard_summary extends external_api {

    /** @var int Cache TTL in seconds */
    const CACHE_TTL = 60;

    /**
     * Parameter definitions.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'include_courses' => new external_value(PARAM_BOOL, 'Include courses with progress', VALUE_DEFAULT, true),
            'include_assignments' => new external_value(PARAM_BOOL, 'Include assignments due', VALUE_DEFAULT, true),
            'include_quizzes' => new external_value(PARAM_BOOL, 'Include quizzes due', VALUE_DEFAULT, true),
            'include_events' => new external_value(PARAM_BOOL, 'Include calendar events', VALUE_DEFAULT, true),
            'include_grades' => new external_value(PARAM_BOOL, 'Include recent grades', VALUE_DEFAULT, true),
            'include_messages' => new external_value(PARAM_BOOL, 'Include unread count', VALUE_DEFAULT, true),
            'events_days_ahead' => new external_value(PARAM_INT, 'Days ahead for events', VALUE_DEFAULT, 7),
            'max_courses' => new external_value(PARAM_INT, 'Max courses', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * Execute dashboard summary fetch.
     *
     * @param int $userid User ID
     * @param bool $include_courses Include courses
     * @param bool $include_assignments Include assignments
     * @param bool $include_quizzes Include quizzes
     * @param bool $include_events Include events
     * @param bool $include_grades Include grades
     * @param bool $include_messages Include messages
     * @param int $events_days_ahead Days ahead
     * @param int $max_courses Max courses
     * @return array
     */
    public static function execute(
        int $userid,
        bool $include_courses = true,
        bool $include_assignments = true,
        bool $include_quizzes = true,
        bool $include_events = true,
        bool $include_grades = true,
        bool $include_messages = true,
        int $events_days_ahead = 7,
        int $max_courses = 100
    ): array {
        global $DB, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'include_courses' => $include_courses,
            'include_assignments' => $include_assignments,
            'include_quizzes' => $include_quizzes,
            'include_events' => $include_events,
            'include_grades' => $include_grades,
            'include_messages' => $include_messages,
            'events_days_ahead' => $events_days_ahead,
            'max_courses' => $max_courses,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        // Build cache key based on options.
        $options_hash = md5(json_encode([
            $include_courses, $include_assignments, $include_quizzes,
            $include_events, $include_grades, $include_messages,
            $events_days_ahead, $max_courses
        ]));
        $cache_key = "dashboard_{$userid}_{$options_hash}";

        // Try session cache first.
        $cache = cache::make('local_sm_estratoos_plugin', 'user_dashboard');
        $cached = $cache->get($cache_key);
        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        $now = time();
        $result = [
            'userid' => $userid,
            'generated_at' => $now,
            'courses' => [],
            'assignments_due' => [],
            'quizzes_due' => [],
            'events' => [],
            'recent_grades' => [],
            'stats' => [
                'total_courses' => 0,
                'completed_courses' => 0,
                'in_progress_courses' => 0,
                'overall_progress' => 0.0,
                'assignments_due_count' => 0,
                'quizzes_due_count' => 0,
                'unread_messages' => 0,
            ],
            'cached' => false,
            'cache_expires' => $now + self::CACHE_TTL,
        ];

        // =====================================
        // COURSES WITH PROGRESS (Optimized Query)
        // =====================================
        if ($include_courses) {
            $courses_sql = "
                SELECT
                    c.id,
                    c.fullname,
                    c.shortname,
                    c.startdate,
                    c.enddate,
                    c.visible,
                    cc.id as categoryid,
                    cc.name as categoryname,
                    ue.timestart as enroldate
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                LEFT JOIN {course_categories} cc ON cc.id = c.category
                WHERE ue.userid = :userid
                  AND ue.status = 0
                  AND e.status = 0
                  AND c.visible = 1
                  AND c.id != 1
                ORDER BY ue.timestart DESC
            ";

            $courses = $DB->get_records_sql($courses_sql, ['userid' => $userid], 0, $max_courses);

            // Get completion progress for all courses in batch.
            $course_ids = array_keys($courses);
            $progress_by_course = [];

            if (!empty($course_ids)) {
                list($in_sql, $in_params) = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED);
                $in_params['userid'] = $userid;

                $progress_sql = "
                    SELECT
                        cm.course,
                        SUM(CASE WHEN cmc.completionstate > 0 THEN 1 ELSE 0 END) as completed,
                        COUNT(cm.id) as total
                    FROM {course_modules} cm
                    LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id
                        AND cmc.userid = :userid
                    WHERE cm.course {$in_sql}
                      AND cm.completion > 0
                      AND cm.visible = 1
                      AND cm.deletioninprogress = 0
                    GROUP BY cm.course
                ";

                $progress_records = $DB->get_records_sql($progress_sql, $in_params);
                foreach ($progress_records as $record) {
                    $progress_by_course[$record->course] = [
                        'completed' => (int)$record->completed,
                        'total' => (int)$record->total,
                        'progress' => $record->total > 0
                            ? round(100.0 * $record->completed / $record->total, 1)
                            : 0,
                    ];
                }
            }

            $total_progress = 0;
            $completed = 0;
            $in_progress = 0;

            foreach ($courses as $course) {
                $cp = $progress_by_course[$course->id] ?? ['completed' => 0, 'total' => 0, 'progress' => 0];
                $progress = (float)$cp['progress'];
                $total_progress += $progress;

                if ($progress >= 100) {
                    $completed++;
                } else if ($progress > 0) {
                    $in_progress++;
                }

                $result['courses'][] = [
                    'id' => (int)$course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'startdate' => (int)$course->startdate,
                    'enddate' => (int)($course->enddate ?? 0),
                    'categoryid' => (int)($course->categoryid ?? 0),
                    'categoryname' => $course->categoryname ?? '',
                    'enroldate' => (int)($course->enroldate ?? 0),
                    'progress' => $progress,
                    'completed_modules' => $cp['completed'],
                    'total_modules' => $cp['total'],
                ];
            }

            $course_count = count($courses);
            $result['stats']['total_courses'] = $course_count;
            $result['stats']['completed_courses'] = $completed;
            $result['stats']['in_progress_courses'] = $in_progress;
            $result['stats']['overall_progress'] = $course_count > 0
                ? round($total_progress / $course_count, 1)
                : 0;
        }

        // =====================================
        // ASSIGNMENTS DUE (Single Query)
        // =====================================
        if ($include_assignments) {
            $future = $now + ($events_days_ahead * 86400);

            $assignments_sql = "
                SELECT
                    a.id,
                    a.name,
                    a.duedate,
                    c.id as courseid,
                    c.fullname as coursename,
                    cm.id as cmid,
                    s.status as submission_status
                FROM {assign} a
                JOIN {course_modules} cm ON cm.instance = a.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                JOIN {course} c ON c.id = a.course
                JOIN {user_enrolments} ue ON ue.userid = :userid1
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
                LEFT JOIN {assign_submission} s ON s.assignment = a.id
                    AND s.userid = :userid2
                    AND s.latest = 1
                WHERE a.duedate > :now
                  AND a.duedate < :future
                  AND cm.visible = 1
                  AND cm.deletioninprogress = 0
                  AND c.visible = 1
                  AND ue.status = 0
                  AND e.status = 0
                  AND (s.status IS NULL OR s.status != 'submitted')
                ORDER BY a.duedate ASC
            ";

            try {
                $assignments = $DB->get_records_sql($assignments_sql, [
                    'userid1' => $userid,
                    'userid2' => $userid,
                    'now' => $now,
                    'future' => $future,
                ], 0, 20);

                foreach ($assignments as $assignment) {
                    $result['assignments_due'][] = [
                        'id' => (int)$assignment->id,
                        'name' => $assignment->name,
                        'duedate' => (int)$assignment->duedate,
                        'courseid' => (int)$assignment->courseid,
                        'coursename' => $assignment->coursename,
                        'cmid' => (int)$assignment->cmid,
                        'status' => $assignment->submission_status ?? 'not_submitted',
                    ];
                }
                $result['stats']['assignments_due_count'] = count($assignments);
            } catch (\Exception $e) {
                // Assignment module may not be installed.
                $result['stats']['assignments_due_count'] = 0;
            }
        }

        // =====================================
        // QUIZZES DUE (Single Query)
        // =====================================
        if ($include_quizzes) {
            $future = $now + ($events_days_ahead * 86400);

            $quizzes_sql = "
                SELECT
                    q.id,
                    q.name,
                    q.timeclose,
                    c.id as courseid,
                    c.fullname as coursename,
                    cm.id as cmid
                FROM {quiz} q
                JOIN {course_modules} cm ON cm.instance = q.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                JOIN {course} c ON c.id = q.course
                JOIN {user_enrolments} ue ON ue.userid = :userid
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = c.id
                WHERE q.timeclose > :now
                  AND q.timeclose < :future
                  AND cm.visible = 1
                  AND cm.deletioninprogress = 0
                  AND c.visible = 1
                  AND ue.status = 0
                  AND e.status = 0
                ORDER BY q.timeclose ASC
            ";

            try {
                $quizzes = $DB->get_records_sql($quizzes_sql, [
                    'userid' => $userid,
                    'now' => $now,
                    'future' => $future,
                ], 0, 20);

                // Get attempt counts in batch.
                $quiz_ids = array_keys($quizzes);
                $attempts_by_quiz = [];

                if (!empty($quiz_ids)) {
                    list($in_sql, $in_params) = $DB->get_in_or_equal($quiz_ids, SQL_PARAMS_NAMED);
                    $in_params['userid'] = $userid;

                    $attempts_sql = "
                        SELECT quiz, COUNT(*) as attempts
                        FROM {quiz_attempts}
                        WHERE quiz {$in_sql}
                          AND userid = :userid
                          AND state = 'finished'
                        GROUP BY quiz
                    ";
                    $attempts_records = $DB->get_records_sql($attempts_sql, $in_params);
                    foreach ($attempts_records as $record) {
                        $attempts_by_quiz[$record->quiz] = (int)$record->attempts;
                    }
                }

                foreach ($quizzes as $quiz) {
                    $result['quizzes_due'][] = [
                        'id' => (int)$quiz->id,
                        'name' => $quiz->name,
                        'timeclose' => (int)$quiz->timeclose,
                        'courseid' => (int)$quiz->courseid,
                        'coursename' => $quiz->coursename,
                        'cmid' => (int)$quiz->cmid,
                        'attempts' => $attempts_by_quiz[$quiz->id] ?? 0,
                    ];
                }
                $result['stats']['quizzes_due_count'] = count($quizzes);
            } catch (\Exception $e) {
                // Quiz module may not be installed.
                $result['stats']['quizzes_due_count'] = 0;
            }
        }

        // =====================================
        // CALENDAR EVENTS (Single Query)
        // =====================================
        if ($include_events) {
            $future = $now + ($events_days_ahead * 86400);

            // Get user's enrolled course IDs.
            $enrolled_courses_sql = "
                SELECT DISTINCT e.courseid
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0
            ";
            $enrolled_courses = $DB->get_fieldset_sql($enrolled_courses_sql, ['userid' => $userid]);

            $events_sql = "
                SELECT
                    e.id,
                    e.name,
                    e.description,
                    e.eventtype,
                    e.timestart,
                    e.timeduration,
                    e.courseid,
                    COALESCE(c.fullname, 'Site') as coursename
                FROM {event} e
                LEFT JOIN {course} c ON c.id = e.courseid
                WHERE (
                    (e.userid = :userid AND e.eventtype = 'user')
                    OR (e.eventtype = 'site')
                )
                  AND e.timestart >= :now
                  AND e.timestart < :future
                  AND e.visible = 1
                ORDER BY e.timestart ASC
            ";

            $events = $DB->get_records_sql($events_sql, [
                'userid' => $userid,
                'now' => $now,
                'future' => $future,
            ], 0, 50);

            // Also get course events for enrolled courses.
            if (!empty($enrolled_courses)) {
                list($in_sql, $in_params) = $DB->get_in_or_equal($enrolled_courses, SQL_PARAMS_NAMED);
                $in_params['now'] = $now;
                $in_params['future'] = $future;

                $course_events_sql = "
                    SELECT
                        e.id,
                        e.name,
                        e.description,
                        e.eventtype,
                        e.timestart,
                        e.timeduration,
                        e.courseid,
                        c.fullname as coursename
                    FROM {event} e
                    JOIN {course} c ON c.id = e.courseid
                    WHERE e.courseid {$in_sql}
                      AND e.eventtype IN ('course', 'group')
                      AND e.timestart >= :now
                      AND e.timestart < :future
                      AND e.visible = 1
                    ORDER BY e.timestart ASC
                ";

                $course_events = $DB->get_records_sql($course_events_sql, $in_params, 0, 50);
                $events = $events + $course_events;

                // Sort by timestart.
                usort($events, function($a, $b) {
                    return $a->timestart - $b->timestart;
                });

                // Limit to 50.
                $events = array_slice($events, 0, 50);
            }

            foreach ($events as $event) {
                $result['events'][] = [
                    'id' => (int)$event->id,
                    'name' => $event->name,
                    'description' => strip_tags($event->description ?? ''),
                    'eventtype' => $event->eventtype,
                    'timestart' => (int)$event->timestart,
                    'timeduration' => (int)($event->timeduration ?? 0),
                    'courseid' => (int)($event->courseid ?? 0),
                    'coursename' => $event->coursename,
                ];
            }
        }

        // =====================================
        // RECENT GRADES (Single Query)
        // =====================================
        if ($include_grades) {
            $since = $now - (30 * 86400); // Last 30 days.

            $grades_sql = "
                SELECT
                    gg.id,
                    gg.finalgrade,
                    gg.rawgrademax,
                    gg.timemodified,
                    gi.itemname,
                    gi.itemtype,
                    gi.courseid,
                    c.fullname as coursename
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                JOIN {course} c ON c.id = gi.courseid
                WHERE gg.userid = :userid
                  AND gg.finalgrade IS NOT NULL
                  AND gg.timemodified > :since
                ORDER BY gg.timemodified DESC
            ";

            $grades = $DB->get_records_sql($grades_sql, [
                'userid' => $userid,
                'since' => $since,
            ], 0, 10);

            foreach ($grades as $grade) {
                $max = floatval($grade->rawgrademax);
                $final = floatval($grade->finalgrade);
                $percentage = $max > 0 ? round(($final / $max) * 100, 1) : 0;

                $result['recent_grades'][] = [
                    'id' => (int)$grade->id,
                    'itemname' => $grade->itemname ?? 'Grade',
                    'itemtype' => $grade->itemtype,
                    'finalgrade' => $final,
                    'maxgrade' => $max,
                    'percentage' => $percentage,
                    'timemodified' => (int)$grade->timemodified,
                    'courseid' => (int)$grade->courseid,
                    'coursename' => $grade->coursename,
                ];
            }
        }

        // =====================================
        // UNREAD MESSAGES (Single Query)
        // =====================================
        if ($include_messages) {
            try {
                $unread_sql = "
                    SELECT COUNT(DISTINCT m.id) as unread
                    FROM {messages} m
                    JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                    LEFT JOIN {message_user_actions} mua ON mua.messageid = m.id
                        AND mua.userid = :userid3
                        AND mua.action = 1
                    WHERE mcm.userid = :userid
                      AND m.useridfrom != :userid2
                      AND mua.id IS NULL
                ";

                $unread = $DB->get_field_sql($unread_sql, [
                    'userid' => $userid,
                    'userid2' => $userid,
                    'userid3' => $userid,
                ]);
                $result['stats']['unread_messages'] = (int)($unread ?? 0);
            } catch (\Exception $e) {
                // Fallback to 0 on error.
                $result['stats']['unread_messages'] = 0;
            }
        }

        // Cache the result.
        $cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'generated_at' => new external_value(PARAM_INT, 'Generation timestamp'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_RAW, 'Course full name'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'startdate' => new external_value(PARAM_INT, 'Start date'),
                    'enddate' => new external_value(PARAM_INT, 'End date'),
                    'categoryid' => new external_value(PARAM_INT, 'Category ID'),
                    'categoryname' => new external_value(PARAM_RAW, 'Category name'),
                    'enroldate' => new external_value(PARAM_INT, 'Enrollment date'),
                    'progress' => new external_value(PARAM_FLOAT, 'Completion progress'),
                    'completed_modules' => new external_value(PARAM_INT, 'Completed modules'),
                    'total_modules' => new external_value(PARAM_INT, 'Total modules'),
                ])
            ),
            'assignments_due' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Assignment ID'),
                    'name' => new external_value(PARAM_RAW, 'Assignment name'),
                    'duedate' => new external_value(PARAM_INT, 'Due date'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                    'status' => new external_value(PARAM_RAW, 'Submission status'),
                ])
            ),
            'quizzes_due' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Quiz ID'),
                    'name' => new external_value(PARAM_RAW, 'Quiz name'),
                    'timeclose' => new external_value(PARAM_INT, 'Close time'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                    'attempts' => new external_value(PARAM_INT, 'Attempt count'),
                ])
            ),
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Event ID'),
                    'name' => new external_value(PARAM_RAW, 'Event name'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'eventtype' => new external_value(PARAM_RAW, 'Event type'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                ])
            ),
            'recent_grades' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Grade ID'),
                    'itemname' => new external_value(PARAM_RAW, 'Item name'),
                    'itemtype' => new external_value(PARAM_RAW, 'Item type'),
                    'finalgrade' => new external_value(PARAM_FLOAT, 'Final grade'),
                    'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                    'percentage' => new external_value(PARAM_FLOAT, 'Percentage'),
                    'timemodified' => new external_value(PARAM_INT, 'Modified time'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                ])
            ),
            'stats' => new external_single_structure([
                'total_courses' => new external_value(PARAM_INT, 'Total courses'),
                'completed_courses' => new external_value(PARAM_INT, 'Completed courses'),
                'in_progress_courses' => new external_value(PARAM_INT, 'In progress courses'),
                'overall_progress' => new external_value(PARAM_FLOAT, 'Overall progress'),
                'assignments_due_count' => new external_value(PARAM_INT, 'Assignments due count'),
                'quizzes_due_count' => new external_value(PARAM_INT, 'Quizzes due count'),
                'unread_messages' => new external_value(PARAM_INT, 'Unread messages'),
            ]),
            'cached' => new external_value(PARAM_BOOL, 'Result from cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry timestamp'),
        ]);
    }
}
