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
 * Get complete dashboard data in a single call.
 *
 * Replaces 10+ sequential API calls per Moodle with one optimized request.
 * Reduces dashboard loading from ~20 seconds to <2 seconds.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_dashboard_complete extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'options' => new external_value(PARAM_RAW, 'JSON options', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Get complete dashboard data in a single call.
     *
     * @param int $userid Moodle user ID.
     * @param string $options JSON options string.
     * @return array Dashboard data.
     */
    public static function execute(int $userid, string $options = '{}'): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'options' => $options,
        ]);

        $opts = json_decode($params['options'], true) ?: [];
        $includecourses = $opts['include_courses'] ?? true;
        $includeassignments = $opts['include_assignments'] ?? true;
        $includequizzes = $opts['include_quizzes'] ?? true;
        $includeevents = $opts['include_events'] ?? true;
        $includegrades = $opts['include_grades'] ?? true;
        $includemessages = $opts['include_messages'] ?? true;
        $includedeadlines = $opts['include_deadlines'] ?? true;
        $includerecentactivity = $opts['include_recent_activity'] ?? true;
        $eventslookahead = $opts['events_lookahead_days'] ?? 90;
        $recentdays = $opts['recent_activity_days'] ?? 7;

        // Determine context based on IOMAD or standard Moodle.
        $companyid = 0;
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $token = \local_sm_estratoos_plugin\util::get_current_request_token();
            if ($token) {
                $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                if ($restrictions && !empty($restrictions->companyid)) {
                    $companyid = $restrictions->companyid;
                }
            }
        }

        if ($companyid > 0) {
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);
            $context = context_coursecat::instance($company->category);
            self::validate_context($context);
        } else {
            $context = context_system::instance();
            self::validate_context($context);
        }

        // Check permission.
        if ($USER->id != $params['userid']) {
            require_capability('moodle/user:viewdetails', $context);
        }

        // Check cache.
        $cache = \cache::make('local_sm_estratoos_plugin', 'dashboard_complete');
        $cachekey = "dashboard_{$params['userid']}_" . md5($params['options']);
        $cached = $cache->get($cachekey);

        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        $result = [
            'courses' => [],
            'events' => [],
            'deadlines' => [],
            'grades_summary' => [
                'overall_average' => 0.0,
                'courses_completed' => 0,
                'courses_in_progress' => 0,
            ],
            'messages' => [
                'unread_count' => 0,
                'latest_sender' => '',
            ],
            'recent_activity' => [],
            'cached' => false,
            'cache_expires' => time() + 120, // 2 minutes.
        ];

        // 1. Get courses with assignments and quizzes in bulk.
        if ($includecourses) {
            $result['courses'] = self::get_courses_with_activities(
                $params['userid'],
                $companyid,
                $includeassignments,
                $includequizzes,
                $includegrades
            );
        }

        // 2. Get calendar events.
        if ($includeevents) {
            $result['events'] = self::get_calendar_events_bulk($params['userid'], $eventslookahead, $companyid);
        }

        // 3. Build deadlines from assignments and quizzes.
        if ($includedeadlines && $includecourses) {
            $result['deadlines'] = self::build_deadlines($result['courses']);
        }

        // 4. Get grades summary.
        if ($includegrades && $includecourses) {
            $result['grades_summary'] = self::calculate_grades_summary($result['courses']);
        }

        // 5. Get messages count.
        if ($includemessages) {
            $result['messages'] = self::get_messages_summary($params['userid']);
        }

        // 6. Get recent activity.
        if ($includerecentactivity) {
            $result['recent_activity'] = self::get_recent_activity($params['userid'], $recentdays);
        }

        // Cache result.
        $cache->set($cachekey, $result);

        return $result;
    }

    /**
     * Get all courses with their assignments and quizzes in optimized queries.
     *
     * @param int $userid User ID.
     * @param int $companyid Company ID for filtering.
     * @param bool $includeassignments Include assignments.
     * @param bool $includequizzes Include quizzes.
     * @param bool $includegrades Include grades.
     * @return array Courses with activities.
     */
    private static function get_courses_with_activities(
        int $userid,
        int $companyid,
        bool $includeassignments,
        bool $includequizzes,
        bool $includegrades
    ): array {
        global $DB;

        // Get enrolled courses with progress.
        $courses = self::get_user_courses_basic($userid, $companyid);

        if (empty($courses)) {
            return [];
        }

        $courseids = array_column($courses, 'id');
        $coursesbyid = [];
        foreach ($courses as $course) {
            $course['assignments'] = [];
            $course['quizzes'] = [];
            $course['grade'] = null;
            $coursesbyid[$course['id']] = $course;
        }

        // Get assignments in bulk for all courses.
        if ($includeassignments && $DB->get_manager()->table_exists('assign')) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $inparams['userid'] = $userid;
            $inparams['userid2'] = $userid;

            $sql = "SELECT a.id, a.course, a.name, a.duedate, a.intro,
                           s.id as submissionid, s.status as submissionstatus,
                           g.grade as usergrade, a.grade as maxgrade
                    FROM {assign} a
                    LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = :userid AND s.latest = 1
                    LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = :userid2
                    WHERE a.course $insql
                    ORDER BY a.duedate";

            try {
                $assignments = $DB->get_records_sql($sql, $inparams);

                foreach ($assignments as $a) {
                    if (isset($coursesbyid[$a->course])) {
                        $coursesbyid[$a->course]['assignments'][] = [
                            'id' => (int)$a->id,
                            'name' => format_string($a->name),
                            'duedate' => (int)$a->duedate,
                            'submitted' => !empty($a->submissionid) && $a->submissionstatus === 'submitted',
                            'grade' => $a->usergrade !== null ? round((float)$a->usergrade, 2) : null,
                            'maxgrade' => (float)$a->maxgrade,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Assign module not installed or error, continue without assignments.
            }
        }

        // Get quizzes in bulk for all courses.
        if ($includequizzes && $DB->get_manager()->table_exists('quiz')) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $inparams['userid'] = $userid;
            $inparams['userid2'] = $userid;

            $sql = "SELECT q.id, q.course, q.name, q.timeopen, q.timeclose, q.grade as maxgrade,
                           (SELECT COUNT(*) FROM {quiz_attempts} qa WHERE qa.quiz = q.id AND qa.userid = :userid) as attempts,
                           (SELECT MAX(qa2.sumgrades) FROM {quiz_attempts} qa2
                            WHERE qa2.quiz = q.id AND qa2.userid = :userid2 AND qa2.state = 'finished') as bestsumgrades,
                           q.sumgrades as quizsumgrades
                    FROM {quiz} q
                    WHERE q.course $insql
                    ORDER BY q.timeclose";

            try {
                $quizzes = $DB->get_records_sql($sql, $inparams);

                foreach ($quizzes as $q) {
                    if (isset($coursesbyid[$q->course])) {
                        $bestgrade = null;
                        if ($q->bestsumgrades !== null && $q->quizsumgrades > 0) {
                            $bestgrade = round(($q->bestsumgrades / $q->quizsumgrades) * $q->maxgrade, 2);
                        }

                        $coursesbyid[$q->course]['quizzes'][] = [
                            'id' => (int)$q->id,
                            'name' => format_string($q->name),
                            'timeopen' => (int)$q->timeopen,
                            'timeclose' => (int)$q->timeclose,
                            'attempts' => (int)$q->attempts,
                            'bestgrade' => $bestgrade,
                            'maxgrade' => (float)$q->maxgrade,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Quiz module not installed or error, continue without quizzes.
            }
        }

        // Get course grades in bulk.
        if ($includegrades) {
            list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $inparams['userid'] = $userid;

            $sql = "SELECT gi.courseid, gg.finalgrade, gi.grademax
                    FROM {grade_items} gi
                    JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                    WHERE gi.itemtype = 'course' AND gi.courseid $insql";

            $grades = $DB->get_records_sql($sql, $inparams);

            foreach ($grades as $g) {
                if (isset($coursesbyid[$g->courseid]) && $g->grademax > 0 && $g->finalgrade !== null) {
                    $coursesbyid[$g->courseid]['grade'] = round(($g->finalgrade / $g->grademax) * 100, 1);
                }
            }
        }

        return array_values($coursesbyid);
    }

    /**
     * Get user courses basic info.
     *
     * @param int $userid User ID.
     * @param int $companyid Company ID for filtering.
     * @return array Courses.
     */
    private static function get_user_courses_basic(int $userid, int $companyid = 0): array {
        global $DB;

        $companyjoin = '';
        $companywhere = '';
        $params = [$userid, $userid];

        if ($companyid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $companyjoin = "JOIN {company_course} cc ON cc.courseid = c.id";
            $companywhere = "AND cc.companyid = ?";
            $params[] = $companyid;
        }

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.category,
                       c.startdate, c.enddate, c.visible,
                       (SELECT MAX(ue2.timeaccess) FROM {user_enrolments} ue2
                        JOIN {enrol} e2 ON e2.id = ue2.enrolid
                        WHERE e2.courseid = c.id AND ue2.userid = ?) as lastaccess,
                       (SELECT COUNT(*) FROM {course_modules} cm
                        WHERE cm.course = c.id AND cm.completion > 0 AND cm.deletioninprogress = 0) as total_activities,
                       (SELECT COUNT(*) FROM {course_modules_completion} cmc
                        JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                        WHERE cm.course = c.id AND cmc.userid = ? AND cmc.completionstate > 0
                        AND cm.deletioninprogress = 0) as completed_activities
                FROM {course} c
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = ?
                $companyjoin
                WHERE c.id != 1 AND ue.status = 0
                $companywhere
                ORDER BY c.sortorder";

        $params = [$userid, $userid, $userid];
        if ($companyid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $params[] = $companyid;
        }

        $courses = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($courses as $course) {
            $progress = 0;
            if ($course->total_activities > 0) {
                $progress = round(($course->completed_activities / $course->total_activities) * 100);
            }

            $result[] = [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname),
                'shortname' => $course->shortname,
                'category' => (int)$course->category,
                'startdate' => (int)$course->startdate,
                'enddate' => (int)$course->enddate,
                'visible' => (bool)$course->visible,
                'progress' => $progress,
                'lastaccess' => (int)($course->lastaccess ?: 0),
            ];
        }

        return $result;
    }

    /**
     * Get calendar events for all user's courses in a single query.
     *
     * @param int $userid User ID.
     * @param int $lookaheaddays Days to look ahead.
     * @param int $companyid Company ID for filtering.
     * @return array Calendar events.
     */
    private static function get_calendar_events_bulk(int $userid, int $lookaheaddays, int $companyid = 0): array {
        global $DB;

        $now = time();
        $until = $now + ($lookaheaddays * 86400);

        // Get course IDs.
        $courses = self::get_user_courses_basic($userid, $companyid);
        $courseids = array_column($courses, 'id');
        if (empty($courseids)) {
            $courseids = [SITEID];
        }
        $courseids[] = SITEID; // Include site events.

        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $inparams['userid'] = $userid;
        $inparams['timestart'] = $now;
        $inparams['timeend'] = $until;

        $sql = "SELECT e.id, e.name, e.description, e.format, e.timestart,
                       e.timeduration, e.courseid, e.eventtype, e.instance, e.modulename
                FROM {event} e
                WHERE (e.courseid $insql OR (e.userid = :userid AND e.courseid = 0))
                AND e.timestart BETWEEN :timestart AND :timeend
                AND e.visible = 1
                ORDER BY e.timestart
                LIMIT 100";

        $events = $DB->get_records_sql($sql, $inparams);

        $result = [];
        foreach ($events as $e) {
            $result[] = [
                'id' => (int)$e->id,
                'name' => format_string($e->name),
                'description' => format_text($e->description, $e->format),
                'timestart' => (int)$e->timestart,
                'timeduration' => (int)$e->timeduration,
                'courseid' => (int)$e->courseid,
                'eventtype' => $e->eventtype,
                'instance' => (int)$e->instance,
                'modulename' => $e->modulename ?: '',
            ];
        }

        return $result;
    }

    /**
     * Build deadlines from course activities.
     *
     * @param array $courses Courses with activities.
     * @return array Deadlines.
     */
    private static function build_deadlines(array $courses): array {
        $now = time();
        $deadlines = [];

        foreach ($courses as $course) {
            // Assignment deadlines.
            foreach ($course['assignments'] ?? [] as $a) {
                if ($a['duedate'] > $now && !$a['submitted']) {
                    $deadlines[] = [
                        'type' => 'assignment',
                        'id' => $a['id'],
                        'name' => $a['name'],
                        'courseid' => $course['id'],
                        'coursename' => $course['fullname'],
                        'duedate' => $a['duedate'],
                        'urgent' => ($a['duedate'] - $now) < 86400 * 3, // Less than 3 days.
                    ];
                }
            }

            // Quiz deadlines.
            foreach ($course['quizzes'] ?? [] as $q) {
                if ($q['timeclose'] > $now && $q['attempts'] == 0) {
                    $deadlines[] = [
                        'type' => 'quiz',
                        'id' => $q['id'],
                        'name' => $q['name'],
                        'courseid' => $course['id'],
                        'coursename' => $course['fullname'],
                        'duedate' => $q['timeclose'],
                        'urgent' => ($q['timeclose'] - $now) < 86400 * 3,
                    ];
                }
            }
        }

        // Sort by deadline.
        usort($deadlines, fn($a, $b) => $a['duedate'] <=> $b['duedate']);

        return array_slice($deadlines, 0, 20); // Limit to 20 deadlines.
    }

    /**
     * Calculate grades summary.
     *
     * @param array $courses Courses with grades.
     * @return array Grades summary.
     */
    private static function calculate_grades_summary(array $courses): array {
        $totalgrade = 0;
        $gradedcourses = 0;
        $completed = 0;
        $inprogress = 0;

        foreach ($courses as $course) {
            if ($course['progress'] >= 100) {
                $completed++;
            } elseif ($course['progress'] > 0) {
                $inprogress++;
            }

            if ($course['grade'] !== null) {
                $totalgrade += $course['grade'];
                $gradedcourses++;
            }
        }

        return [
            'overall_average' => $gradedcourses > 0 ? round($totalgrade / $gradedcourses, 1) : 0.0,
            'courses_completed' => $completed,
            'courses_in_progress' => $inprogress,
        ];
    }

    /**
     * Get messages summary.
     *
     * @param int $userid User ID.
     * @return array Messages summary.
     */
    private static function get_messages_summary(int $userid): array {
        global $DB, $CFG;

        if (empty($CFG->messaging)) {
            return ['unread_count' => 0, 'latest_sender' => ''];
        }

        // Count unread messages.
        $sql = "SELECT COUNT(*) as unread
                FROM {message_conversation_members} mcm
                JOIN {messages} m ON m.conversationid = mcm.conversationid
                LEFT JOIN {message_user_actions} mua ON mua.messageid = m.id
                    AND mua.userid = mcm.userid AND mua.action = 1
                WHERE mcm.userid = ? AND m.useridfrom != ? AND mua.id IS NULL";

        try {
            $unread = $DB->get_field_sql($sql, [$userid, $userid]);
        } catch (\Exception $e) {
            $unread = 0;
        }

        // Get latest sender.
        $latestsender = '';
        $sql = "SELECT u.firstname, u.lastname
                FROM {messages} m
                JOIN {user} u ON u.id = m.useridfrom
                JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                WHERE mcm.userid = ? AND m.useridfrom != ?
                ORDER BY m.timecreated DESC
                LIMIT 1";

        try {
            $sender = $DB->get_record_sql($sql, [$userid, $userid]);
            if ($sender) {
                $latestsender = fullname($sender);
            }
        } catch (\Exception $e) {
            // Ignore errors.
        }

        return [
            'unread_count' => (int)$unread,
            'latest_sender' => $latestsender,
        ];
    }

    /**
     * Get recent activity.
     *
     * @param int $userid User ID.
     * @param int $days Number of days to look back.
     * @return array Recent activity.
     */
    private static function get_recent_activity(int $userid, int $days): array {
        global $DB;

        $since = time() - ($days * 86400);
        $activity = [];

        // Recent grade changes.
        $sql = "SELECT gg.id, gg.finalgrade, gi.itemname, gi.courseid, gg.timemodified
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gi.id = gg.itemid
                WHERE gg.userid = ? AND gg.timemodified > ? AND gg.finalgrade IS NOT NULL
                ORDER BY gg.timemodified DESC
                LIMIT 10";

        try {
            $grades = $DB->get_records_sql($sql, [$userid, $since]);
            foreach ($grades as $g) {
                $activity[] = [
                    'type' => 'grade',
                    'courseid' => (int)$g->courseid,
                    'itemname' => format_string($g->itemname),
                    'grade' => round((float)$g->finalgrade, 2),
                    'timemodified' => (int)$g->timemodified,
                ];
            }
        } catch (\Exception $e) {
            // Ignore errors.
        }

        // Recent completions.
        $sql = "SELECT cmc.id, cmc.coursemoduleid, cmc.timemodified, cm.course,
                       m.name as modulename
                FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                JOIN {modules} m ON m.id = cm.module
                WHERE cmc.userid = ? AND cmc.timemodified > ? AND cmc.completionstate > 0
                ORDER BY cmc.timemodified DESC
                LIMIT 10";

        try {
            $completions = $DB->get_records_sql($sql, [$userid, $since]);
            foreach ($completions as $c) {
                $activity[] = [
                    'type' => 'completion',
                    'courseid' => (int)$c->course,
                    'itemname' => $c->modulename,
                    'grade' => null,
                    'timemodified' => (int)$c->timemodified,
                ];
            }
        } catch (\Exception $e) {
            // Ignore errors.
        }

        // Sort by time.
        usort($activity, fn($a, $b) => $b['timemodified'] <=> $a['timemodified']);

        return array_slice($activity, 0, 20);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_RAW, 'Course full name'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'category' => new external_value(PARAM_INT, 'Category ID'),
                    'startdate' => new external_value(PARAM_INT, 'Start date'),
                    'enddate' => new external_value(PARAM_INT, 'End date'),
                    'visible' => new external_value(PARAM_BOOL, 'Is visible'),
                    'progress' => new external_value(PARAM_INT, 'Completion progress'),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access'),
                    'grade' => new external_value(PARAM_FLOAT, 'Course grade', VALUE_OPTIONAL),
                    'assignments' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Assignment ID'),
                            'name' => new external_value(PARAM_RAW, 'Name'),
                            'duedate' => new external_value(PARAM_INT, 'Due date'),
                            'submitted' => new external_value(PARAM_BOOL, 'Is submitted'),
                            'grade' => new external_value(PARAM_FLOAT, 'Grade', VALUE_OPTIONAL),
                            'maxgrade' => new external_value(PARAM_FLOAT, 'Max grade'),
                        ]),
                        'Assignments', VALUE_OPTIONAL
                    ),
                    'quizzes' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Quiz ID'),
                            'name' => new external_value(PARAM_RAW, 'Name'),
                            'timeopen' => new external_value(PARAM_INT, 'Open time'),
                            'timeclose' => new external_value(PARAM_INT, 'Close time'),
                            'attempts' => new external_value(PARAM_INT, 'Attempts'),
                            'bestgrade' => new external_value(PARAM_FLOAT, 'Best grade', VALUE_OPTIONAL),
                            'maxgrade' => new external_value(PARAM_FLOAT, 'Max grade'),
                        ]),
                        'Quizzes', VALUE_OPTIONAL
                    ),
                ]),
                'Enrolled courses'
            ),
            'events' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Event ID'),
                    'name' => new external_value(PARAM_RAW, 'Name'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'timestart' => new external_value(PARAM_INT, 'Start time'),
                    'timeduration' => new external_value(PARAM_INT, 'Duration'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'eventtype' => new external_value(PARAM_RAW, 'Event type'),
                    'instance' => new external_value(PARAM_INT, 'Instance ID'),
                    'modulename' => new external_value(PARAM_RAW, 'Module name'),
                ]),
                'Calendar events'
            ),
            'deadlines' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_RAW, 'Deadline type'),
                    'id' => new external_value(PARAM_INT, 'Item ID'),
                    'name' => new external_value(PARAM_RAW, 'Name'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_RAW, 'Course name'),
                    'duedate' => new external_value(PARAM_INT, 'Due date'),
                    'urgent' => new external_value(PARAM_BOOL, 'Is urgent'),
                ]),
                'Upcoming deadlines'
            ),
            'grades_summary' => new external_single_structure([
                'overall_average' => new external_value(PARAM_FLOAT, 'Overall grade average'),
                'courses_completed' => new external_value(PARAM_INT, 'Completed courses count'),
                'courses_in_progress' => new external_value(PARAM_INT, 'In progress courses count'),
            ]),
            'messages' => new external_single_structure([
                'unread_count' => new external_value(PARAM_INT, 'Unread messages count'),
                'latest_sender' => new external_value(PARAM_RAW, 'Latest message sender'),
            ]),
            'recent_activity' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_RAW, 'Activity type'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'itemname' => new external_value(PARAM_RAW, 'Item name'),
                    'grade' => new external_value(PARAM_FLOAT, 'Grade', VALUE_OPTIONAL),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                ]),
                'Recent activity'
            ),
            'cached' => new external_value(PARAM_BOOL, 'From cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry'),
        ]);
    }
}
