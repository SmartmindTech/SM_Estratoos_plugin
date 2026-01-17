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

/**
 * Delta sync - fetch only changes since last sync.
 *
 * Performance: Typical response < 100ms when no changes
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_changes_since extends external_api {

    const DEFAULT_DATATYPES = ['courses', 'grades', 'assignments', 'messages', 'events', 'completions'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'since' => new external_value(PARAM_INT, 'Unix timestamp of last sync'),
            'datatypes' => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'Datatype to check'),
                'Data types to check',
                VALUE_DEFAULT,
                []
            ),
            'include_data' => new external_value(PARAM_BOOL, 'Include changed records', VALUE_DEFAULT, true),
            'limit_per_type' => new external_value(PARAM_INT, 'Max records per type', VALUE_DEFAULT, 100),
        ]);
    }

    public static function execute(
        int $userid,
        int $since,
        array $datatypes = [],
        bool $include_data = true,
        int $limit_per_type = 100
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'since' => $since,
            'datatypes' => $datatypes,
            'include_data' => $include_data,
            'limit_per_type' => $limit_per_type,
        ]);

        // Use default datatypes if none specified.
        if (empty($params['datatypes'])) {
            $params['datatypes'] = self::DEFAULT_DATATYPES;
        }

        $context = context_system::instance();
        self::validate_context($context);

        $now = time();
        $since = $params['since'];
        $limit = min($params['limit_per_type'], 500);

        $result = [
            'userid' => $userid,
            'checked_at' => $now,
            'since' => $since,
            'has_changes' => false,
            'changes' => [
                'courses' => [],
                'grades' => [],
                'assignments' => [],
                'messages' => [],
                'events' => [],
                'completions' => [],
            ],
            'summary' => [
                'total_changes' => 0,
                'courses_changed' => 0,
                'grades_changed' => 0,
                'assignments_changed' => 0,
                'messages_changed' => 0,
                'events_changed' => 0,
                'completions_changed' => 0,
            ],
        ];

        // COURSES CHANGED
        if (in_array('courses', $params['datatypes'])) {
            $courses_sql = "
                SELECT c.id, c.fullname, c.shortname, c.timemodified
                FROM {course} c
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0
                  AND c.timemodified > :since
                ORDER BY c.timemodified DESC";
            $changed = $DB->get_records_sql($courses_sql, ['userid' => $userid, 'since' => $since], 0, $limit);
            $count = count($changed);
            $result['summary']['courses_changed'] = $count;
            if ($count > 0) {
                $result['has_changes'] = true;
                $result['summary']['total_changes'] += $count;
                if ($include_data) {
                    foreach ($changed as $c) {
                        $result['changes']['courses'][] = [
                            'id' => (int)$c->id,
                            'fullname' => $c->fullname,
                            'shortname' => $c->shortname,
                            'timemodified' => (int)$c->timemodified,
                        ];
                    }
                }
            }
        }

        // GRADES CHANGED
        if (in_array('grades', $params['datatypes'])) {
            $grades_sql = "
                SELECT gg.id, gg.finalgrade, gg.rawgrademax, gg.timemodified,
                       gi.itemname, gi.courseid, c.fullname as coursename
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                JOIN {course} c ON c.id = gi.courseid
                WHERE gg.userid = :userid AND gg.timemodified > :since AND gg.finalgrade IS NOT NULL
                ORDER BY gg.timemodified DESC";
            $changed = $DB->get_records_sql($grades_sql, ['userid' => $userid, 'since' => $since], 0, $limit);
            $count = count($changed);
            $result['summary']['grades_changed'] = $count;
            if ($count > 0) {
                $result['has_changes'] = true;
                $result['summary']['total_changes'] += $count;
                if ($include_data) {
                    foreach ($changed as $g) {
                        $result['changes']['grades'][] = [
                            'id' => (int)$g->id,
                            'itemname' => $g->itemname ?? 'Grade',
                            'finalgrade' => (float)$g->finalgrade,
                            'maxgrade' => (float)$g->rawgrademax,
                            'courseid' => (int)$g->courseid,
                            'coursename' => $g->coursename,
                            'timemodified' => (int)$g->timemodified,
                        ];
                    }
                }
            }
        }

        // ASSIGNMENTS CHANGED
        if (in_array('assignments', $params['datatypes'])) {
            try {
                $assignments_sql = "
                    SELECT a.id, a.name, a.duedate, a.timemodified, c.id as courseid, c.fullname as coursename
                    FROM {assign} a
                    JOIN {course} c ON c.id = a.course
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0
                      AND a.timemodified > :since
                    ORDER BY a.timemodified DESC";
                $changed = $DB->get_records_sql($assignments_sql, ['userid' => $userid, 'since' => $since], 0, $limit);
                $count = count($changed);
                $result['summary']['assignments_changed'] = $count;
                if ($count > 0) {
                    $result['has_changes'] = true;
                    $result['summary']['total_changes'] += $count;
                    if ($include_data) {
                        foreach ($changed as $a) {
                            $result['changes']['assignments'][] = [
                                'id' => (int)$a->id,
                                'name' => $a->name,
                                'duedate' => (int)$a->duedate,
                                'courseid' => (int)$a->courseid,
                                'coursename' => $a->coursename,
                                'timemodified' => (int)$a->timemodified,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // Assignment module may not be installed.
            }
        }

        // MESSAGES CHANGED
        if (in_array('messages', $params['datatypes'])) {
            try {
                $count = (int)$DB->count_records_sql("
                    SELECT COUNT(*) FROM {messages} m
                    JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                    WHERE mcm.userid = :userid AND m.useridfrom != :userid2 AND m.timecreated > :since",
                    ['userid' => $userid, 'userid2' => $userid, 'since' => $since]);
                $result['summary']['messages_changed'] = $count;
                if ($count > 0) {
                    $result['has_changes'] = true;
                    $result['summary']['total_changes'] += $count;
                }
            } catch (\Exception $e) {
                // Messaging may be disabled.
            }
        }

        // EVENTS CHANGED
        if (in_array('events', $params['datatypes'])) {
            // Get enrolled course IDs.
            $enrolled_sql = "
                SELECT DISTINCT e.courseid
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0";
            $enrolled_courses = $DB->get_fieldset_sql($enrolled_sql, ['userid' => $userid]);

            $events_sql = "
                SELECT e.id, e.name, e.eventtype, e.timestart, e.timemodified, e.courseid
                FROM {event} e
                WHERE (
                    (e.userid = :userid AND e.eventtype = 'user')
                    OR (e.eventtype = 'site')
                )
                  AND e.timemodified > :since
                  AND e.visible = 1
                ORDER BY e.timemodified DESC";
            $changed = $DB->get_records_sql($events_sql, ['userid' => $userid, 'since' => $since], 0, $limit);

            // Also check course events.
            if (!empty($enrolled_courses)) {
                list($in_sql, $in_params) = $DB->get_in_or_equal($enrolled_courses, SQL_PARAMS_NAMED);
                $in_params['since'] = $since;

                $course_events_sql = "
                    SELECT e.id, e.name, e.eventtype, e.timestart, e.timemodified, e.courseid
                    FROM {event} e
                    WHERE e.courseid {$in_sql}
                      AND e.eventtype IN ('course', 'group')
                      AND e.timemodified > :since
                      AND e.visible = 1
                    ORDER BY e.timemodified DESC";
                $course_events = $DB->get_records_sql($course_events_sql, $in_params, 0, $limit);
                $changed = $changed + $course_events;
            }

            $count = count($changed);
            $result['summary']['events_changed'] = $count;
            if ($count > 0) {
                $result['has_changes'] = true;
                $result['summary']['total_changes'] += $count;
                if ($include_data) {
                    foreach ($changed as $e) {
                        $result['changes']['events'][] = [
                            'id' => (int)$e->id,
                            'name' => $e->name,
                            'eventtype' => $e->eventtype,
                            'timestart' => (int)$e->timestart,
                            'courseid' => (int)($e->courseid ?? 0),
                            'timemodified' => (int)$e->timemodified,
                        ];
                    }
                }
            }
        }

        // COMPLETIONS CHANGED
        if (in_array('completions', $params['datatypes'])) {
            $completions_sql = "
                SELECT cmc.id, cmc.coursemoduleid, cmc.completionstate, cmc.timemodified,
                       cm.course as courseid, c.fullname as coursename
                FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                JOIN {course} c ON c.id = cm.course
                WHERE cmc.userid = :userid AND cmc.timemodified > :since
                ORDER BY cmc.timemodified DESC";
            $changed = $DB->get_records_sql($completions_sql, ['userid' => $userid, 'since' => $since], 0, $limit);
            $count = count($changed);
            $result['summary']['completions_changed'] = $count;
            if ($count > 0) {
                $result['has_changes'] = true;
                $result['summary']['total_changes'] += $count;
                if ($include_data) {
                    foreach ($changed as $cmc) {
                        $result['changes']['completions'][] = [
                            'id' => (int)$cmc->id,
                            'coursemoduleid' => (int)$cmc->coursemoduleid,
                            'completionstate' => (int)$cmc->completionstate,
                            'courseid' => (int)$cmc->courseid,
                            'coursename' => $cmc->coursename,
                            'timemodified' => (int)$cmc->timemodified,
                        ];
                    }
                }
            }
        }

        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'checked_at' => new external_value(PARAM_INT, 'Check timestamp'),
            'since' => new external_value(PARAM_INT, 'Since timestamp'),
            'has_changes' => new external_value(PARAM_BOOL, 'Has any changes'),
            'changes' => new external_single_structure([
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Course ID'),
                        'fullname' => new external_value(PARAM_RAW, 'Full name'),
                        'shortname' => new external_value(PARAM_RAW, 'Short name'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    ]),
                    'Changed courses',
                    VALUE_OPTIONAL
                ),
                'grades' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Grade ID'),
                        'itemname' => new external_value(PARAM_RAW, 'Item name'),
                        'finalgrade' => new external_value(PARAM_FLOAT, 'Final grade'),
                        'maxgrade' => new external_value(PARAM_FLOAT, 'Max grade'),
                        'courseid' => new external_value(PARAM_INT, 'Course ID'),
                        'coursename' => new external_value(PARAM_RAW, 'Course name'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    ]),
                    'Changed grades',
                    VALUE_OPTIONAL
                ),
                'assignments' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Assignment ID'),
                        'name' => new external_value(PARAM_RAW, 'Name'),
                        'duedate' => new external_value(PARAM_INT, 'Due date'),
                        'courseid' => new external_value(PARAM_INT, 'Course ID'),
                        'coursename' => new external_value(PARAM_RAW, 'Course name'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    ]),
                    'Changed assignments',
                    VALUE_OPTIONAL
                ),
                'messages' => new external_multiple_structure(
                    new external_single_structure([]),
                    'Changed messages (count only)',
                    VALUE_OPTIONAL
                ),
                'events' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Event ID'),
                        'name' => new external_value(PARAM_RAW, 'Name'),
                        'eventtype' => new external_value(PARAM_RAW, 'Event type'),
                        'timestart' => new external_value(PARAM_INT, 'Start time'),
                        'courseid' => new external_value(PARAM_INT, 'Course ID'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    ]),
                    'Changed events',
                    VALUE_OPTIONAL
                ),
                'completions' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Completion ID'),
                        'coursemoduleid' => new external_value(PARAM_INT, 'Course module ID'),
                        'completionstate' => new external_value(PARAM_INT, 'Completion state'),
                        'courseid' => new external_value(PARAM_INT, 'Course ID'),
                        'coursename' => new external_value(PARAM_RAW, 'Course name'),
                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    ]),
                    'Changed completions',
                    VALUE_OPTIONAL
                ),
            ]),
            'summary' => new external_single_structure([
                'total_changes' => new external_value(PARAM_INT, 'Total changes'),
                'courses_changed' => new external_value(PARAM_INT, 'Courses changed'),
                'grades_changed' => new external_value(PARAM_INT, 'Grades changed'),
                'assignments_changed' => new external_value(PARAM_INT, 'Assignments changed'),
                'messages_changed' => new external_value(PARAM_INT, 'Messages changed'),
                'events_changed' => new external_value(PARAM_INT, 'Events changed'),
                'completions_changed' => new external_value(PARAM_INT, 'Completions changed'),
            ]),
        ]);
    }
}
