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
use context_course;

/**
 * Get statistics for multiple courses at once.
 *
 * Useful for teacher dashboards showing overview of all their courses.
 * Fetches enrollment, completion, grades, and activity stats in bulk.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_stats_bulk extends external_api {

    /**
     * Maximum courses per request.
     */
    const MAX_COURSES_PER_REQUEST = 100;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_value(PARAM_RAW, 'JSON array of course IDs'),
        ]);
    }

    /**
     * Get statistics for multiple courses.
     *
     * @param string $courseids JSON array of course IDs.
     * @return array Course statistics.
     */
    public static function execute(string $courseids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseids' => $courseids,
        ]);

        $ids = json_decode($params['courseids'], true) ?: [];
        if (empty($ids)) {
            return ['courses' => [], 'cached' => false, 'cache_expires' => time() + 60];
        }

        // Limit courses.
        $ids = array_slice($ids, 0, self::MAX_COURSES_PER_REQUEST);

        // Determine context and validate.
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

        // Check cache.
        $cache = \cache::make('local_sm_estratoos_plugin', 'course_stats');
        $cachekey = "stats_" . md5(json_encode($ids));
        $cached = $cache->get($cachekey);

        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        // 1. Get basic course info.
        $sql = "SELECT c.id, c.fullname, c.shortname FROM {course} c WHERE c.id $insql";
        $courses = $DB->get_records_sql($sql, $inparams);

        if (empty($courses)) {
            return ['courses' => [], 'cached' => false, 'cache_expires' => time() + 60];
        }

        // Initialize course data.
        $coursedata = [];
        foreach ($courses as $c) {
            $coursedata[$c->id] = [
                'id' => (int)$c->id,
                'fullname' => format_string($c->fullname),
                'shortname' => $c->shortname,
                'enrolled_count' => 0,
                'active_count' => 0,
                'completed_count' => 0,
                'avg_progress' => 0.0,
                'avg_grade' => 0.0,
                'assignments' => [
                    'total' => 0,
                    'submitted_avg' => 0.0,
                    'graded_avg' => 0.0,
                ],
                'quizzes' => [
                    'total' => 0,
                    'attempted_avg' => 0.0,
                    'avg_score' => 0.0,
                ],
                'recent_activity' => [
                    'submissions_7d' => 0,
                    'quiz_attempts_7d' => 0,
                    'logins_7d' => 0,
                ],
            ];
        }

        // 2. Get enrollment counts.
        $sql = "SELECT e.courseid, COUNT(DISTINCT ue.userid) as enrolled
                FROM {enrol} e
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE e.courseid $insql AND ue.status = 0
                GROUP BY e.courseid";
        $enrollments = $DB->get_records_sql($sql, $inparams);
        foreach ($enrollments as $e) {
            if (isset($coursedata[$e->courseid])) {
                $coursedata[$e->courseid]['enrolled_count'] = (int)$e->enrolled;
            }
        }

        // 3. Get active users (accessed in last 30 days).
        $thirtydays = time() - (30 * 86400);
        $sql = "SELECT e.courseid, COUNT(DISTINCT ue.userid) as active
                FROM {enrol} e
                JOIN {user_enrolments} ue ON ue.enrolid = e.id
                WHERE e.courseid $insql AND ue.status = 0 AND ue.timeaccess > :thirtydays
                GROUP BY e.courseid";
        $inparams['thirtydays'] = $thirtydays;
        $activeusers = $DB->get_records_sql($sql, $inparams);
        unset($inparams['thirtydays']);

        foreach ($activeusers as $a) {
            if (isset($coursedata[$a->courseid])) {
                $coursedata[$a->courseid]['active_count'] = (int)$a->active;
            }
        }

        // 4. Get completion stats.
        $sql = "SELECT cc.course,
                       COUNT(CASE WHEN cc.timecompleted IS NOT NULL THEN 1 END) as completed
                FROM {course_completions} cc
                WHERE cc.course $insql
                GROUP BY cc.course";
        $completions = $DB->get_records_sql($sql, $inparams);
        foreach ($completions as $c) {
            if (isset($coursedata[$c->course])) {
                $coursedata[$c->course]['completed_count'] = (int)$c->completed;
            }
        }

        // 5. Calculate average progress per course.
        foreach ($ids as $courseid) {
            if (!isset($coursedata[$courseid])) {
                continue;
            }

            $sql = "SELECT
                       (SELECT COUNT(*) FROM {course_modules} cm
                        WHERE cm.course = ? AND cm.completion > 0 AND cm.deletioninprogress = 0) as total_activities";
            $totalactivities = $DB->get_field_sql($sql, [$courseid]);

            if ($totalactivities > 0) {
                $sql = "SELECT AVG(user_completed.completed_count * 100.0 / ?) as avg_progress
                        FROM (
                            SELECT cmc.userid, COUNT(*) as completed_count
                            FROM {course_modules_completion} cmc
                            JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                            WHERE cm.course = ? AND cmc.completionstate > 0 AND cm.deletioninprogress = 0
                            GROUP BY cmc.userid
                        ) user_completed";
                $avgprogress = $DB->get_field_sql($sql, [$totalactivities, $courseid]);
                $coursedata[$courseid]['avg_progress'] = round((float)($avgprogress ?: 0), 1);
            }
        }

        // 6. Get average grades.
        $sql = "SELECT gi.courseid, AVG(gg.finalgrade / gi.grademax * 100) as avg_grade
                FROM {grade_items} gi
                JOIN {grade_grades} gg ON gg.itemid = gi.id
                WHERE gi.itemtype = 'course' AND gi.courseid $insql AND gg.finalgrade IS NOT NULL AND gi.grademax > 0
                GROUP BY gi.courseid";
        $grades = $DB->get_records_sql($sql, $inparams);
        foreach ($grades as $g) {
            if (isset($coursedata[$g->courseid])) {
                $coursedata[$g->courseid]['avg_grade'] = round((float)$g->avg_grade, 1);
            }
        }

        // 7. Get assignment stats.
        if ($DB->get_manager()->table_exists('assign')) {
            $sql = "SELECT a.course, COUNT(DISTINCT a.id) as total_assignments
                    FROM {assign} a
                    WHERE a.course $insql
                    GROUP BY a.course";
            try {
                $assigncounts = $DB->get_records_sql($sql, $inparams);
                foreach ($assigncounts as $ac) {
                    if (isset($coursedata[$ac->course])) {
                        $coursedata[$ac->course]['assignments']['total'] = (int)$ac->total_assignments;
                    }
                }

                // Get submission/grading averages.
                $sql = "SELECT a.course,
                               AVG((SELECT COUNT(*) FROM {assign_submission} s
                                    WHERE s.assignment = a.id AND s.status = 'submitted' AND s.latest = 1)) as avg_submitted,
                               AVG((SELECT COUNT(*) FROM {assign_grades} g
                                    WHERE g.assignment = a.id AND g.grade >= 0)) as avg_graded
                        FROM {assign} a
                        WHERE a.course $insql
                        GROUP BY a.course";
                $assignstats = $DB->get_records_sql($sql, $inparams);
                foreach ($assignstats as $as) {
                    if (isset($coursedata[$as->course])) {
                        $coursedata[$as->course]['assignments']['submitted_avg'] = round((float)($as->avg_submitted ?: 0), 1);
                        $coursedata[$as->course]['assignments']['graded_avg'] = round((float)($as->avg_graded ?: 0), 1);
                    }
                }
            } catch (\Exception $e) {
                // Assign module not available.
            }
        }

        // 8. Get quiz stats.
        if ($DB->get_manager()->table_exists('quiz')) {
            $sql = "SELECT q.course, COUNT(DISTINCT q.id) as total_quizzes
                    FROM {quiz} q
                    WHERE q.course $insql
                    GROUP BY q.course";
            try {
                $quizcounts = $DB->get_records_sql($sql, $inparams);
                foreach ($quizcounts as $qc) {
                    if (isset($coursedata[$qc->course])) {
                        $coursedata[$qc->course]['quizzes']['total'] = (int)$qc->total_quizzes;
                    }
                }

                // Get attempt/score averages.
                $sql = "SELECT q.course,
                               AVG((SELECT COUNT(DISTINCT qa.userid) FROM {quiz_attempts} qa
                                    WHERE qa.quiz = q.id AND qa.state = 'finished')) as avg_attempted,
                               AVG((SELECT AVG(qa2.sumgrades / NULLIF(q.sumgrades, 0) * 100) FROM {quiz_attempts} qa2
                                    WHERE qa2.quiz = q.id AND qa2.state = 'finished')) as avg_score
                        FROM {quiz} q
                        WHERE q.course $insql
                        GROUP BY q.course";
                $quizstats = $DB->get_records_sql($sql, $inparams);
                foreach ($quizstats as $qs) {
                    if (isset($coursedata[$qs->course])) {
                        $coursedata[$qs->course]['quizzes']['attempted_avg'] = round((float)($qs->avg_attempted ?: 0), 1);
                        $coursedata[$qs->course]['quizzes']['avg_score'] = round((float)($qs->avg_score ?: 0), 1);
                    }
                }
            } catch (\Exception $e) {
                // Quiz module not available.
            }
        }

        // 9. Get recent activity (last 7 days).
        $sevendays = time() - (7 * 86400);

        // Recent submissions.
        if ($DB->get_manager()->table_exists('assign_submission')) {
            foreach ($ids as $courseid) {
                if (!isset($coursedata[$courseid])) {
                    continue;
                }

                try {
                    $sql = "SELECT COUNT(*) as submissions_7d
                            FROM {assign_submission} s
                            JOIN {assign} a ON a.id = s.assignment
                            WHERE a.course = ? AND s.timemodified > ? AND s.status = 'submitted'";
                    $submissions = $DB->get_field_sql($sql, [$courseid, $sevendays]);
                    $coursedata[$courseid]['recent_activity']['submissions_7d'] = (int)$submissions;
                } catch (\Exception $e) {
                    // Ignore.
                }
            }
        }

        // Recent quiz attempts.
        if ($DB->get_manager()->table_exists('quiz_attempts')) {
            foreach ($ids as $courseid) {
                if (!isset($coursedata[$courseid])) {
                    continue;
                }

                try {
                    $sql = "SELECT COUNT(*) as quiz_attempts_7d
                            FROM {quiz_attempts} qa
                            JOIN {quiz} q ON q.id = qa.quiz
                            WHERE q.course = ? AND qa.timefinish > ? AND qa.state = 'finished'";
                    $attempts = $DB->get_field_sql($sql, [$courseid, $sevendays]);
                    $coursedata[$courseid]['recent_activity']['quiz_attempts_7d'] = (int)$attempts;
                } catch (\Exception $e) {
                    // Ignore.
                }
            }
        }

        // Recent logins (course access).
        foreach ($ids as $courseid) {
            if (!isset($coursedata[$courseid])) {
                continue;
            }

            $sql = "SELECT COUNT(DISTINCT ue.userid) as logins_7d
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE e.courseid = ? AND ue.timeaccess > ?";
            $logins = $DB->get_field_sql($sql, [$courseid, $sevendays]);
            $coursedata[$courseid]['recent_activity']['logins_7d'] = (int)$logins;
        }

        $result = [
            'courses' => array_values($coursedata),
            'cached' => false,
            'cache_expires' => time() + 120,
        ];

        $cache->set($cachekey, $result);

        return $result;
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
                    'enrolled_count' => new external_value(PARAM_INT, 'Enrolled users count'),
                    'active_count' => new external_value(PARAM_INT, 'Active users (30 days)'),
                    'completed_count' => new external_value(PARAM_INT, 'Completed users count'),
                    'avg_progress' => new external_value(PARAM_FLOAT, 'Average progress'),
                    'avg_grade' => new external_value(PARAM_FLOAT, 'Average grade'),
                    'assignments' => new external_single_structure([
                        'total' => new external_value(PARAM_INT, 'Total assignments'),
                        'submitted_avg' => new external_value(PARAM_FLOAT, 'Avg submissions per assignment'),
                        'graded_avg' => new external_value(PARAM_FLOAT, 'Avg graded per assignment'),
                    ]),
                    'quizzes' => new external_single_structure([
                        'total' => new external_value(PARAM_INT, 'Total quizzes'),
                        'attempted_avg' => new external_value(PARAM_FLOAT, 'Avg attempts per quiz'),
                        'avg_score' => new external_value(PARAM_FLOAT, 'Average score'),
                    ]),
                    'recent_activity' => new external_single_structure([
                        'submissions_7d' => new external_value(PARAM_INT, 'Submissions in last 7 days'),
                        'quiz_attempts_7d' => new external_value(PARAM_INT, 'Quiz attempts in last 7 days'),
                        'logins_7d' => new external_value(PARAM_INT, 'Course logins in last 7 days'),
                    ]),
                ]),
                'Course statistics'
            ),
            'cached' => new external_value(PARAM_BOOL, 'From cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry'),
        ]);
    }
}
