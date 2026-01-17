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
 * Get completion status for ALL users in a course at once.
 *
 * Eliminates the N+1 query pattern where completion is fetched per-user.
 * Reduces completion fetch from 300ms per student to <100ms total.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_completion_bulk extends external_api {

    /**
     * Maximum users per request.
     */
    const MAX_USERS_PER_REQUEST = 500;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'userids' => new external_value(PARAM_RAW,
                'JSON array of user IDs (empty = all enrolled)',
                VALUE_DEFAULT, '[]'),
            'includedetails' => new external_value(PARAM_BOOL,
                'Include per-activity completion details',
                VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Get completion status for all users in a course.
     *
     * @param int $courseid Course ID.
     * @param string $userids JSON array of user IDs.
     * @param bool $includedetails Include per-activity details.
     * @return array Completion data.
     */
    public static function execute(int $courseid, string $userids = '[]', bool $includedetails = true): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userids' => $userids,
            'includedetails' => $includedetails,
        ]);

        $useridarray = json_decode($params['userids'], true) ?: [];

        // Determine context and validate.
        $companyid = 0;

        try {
            if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
                $token = \local_sm_estratoos_plugin\util::get_current_request_token();
                if ($token) {
                    $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                    if ($restrictions && !empty($restrictions->companyid)) {
                        $companyid = $restrictions->companyid;
                    }
                }
            }
        } catch (\Exception $e) {
            // Database error - continue without company filtering.
            debugging('get_course_completion_bulk: IOMAD query failed - ' . $e->getMessage(), DEBUG_DEVELOPER);
            $companyid = 0;
        }

        // Validate course context.
        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);

        // Check capability to view participants.
        require_capability('moodle/course:viewparticipants', $coursecontext);

        // Check cache.
        $cache = \cache::make('local_sm_estratoos_plugin', 'course_completion');
        $cachekey = "completion_{$params['courseid']}_" . md5(json_encode($useridarray)) . "_" . ($params['includedetails'] ? '1' : '0');
        $cached = $cache->get($cachekey);

        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        // Get course modules with completion enabled.
        $sql = "SELECT cm.id, cm.instance, m.name as modulename
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = ? AND cm.completion > 0 AND cm.deletioninprogress = 0
                ORDER BY cm.section, cm.id";

        $modules = $DB->get_records_sql($sql, [$params['courseid']]);
        $totalactivities = count($modules);
        $cmids = array_keys($modules);

        // Get enrolled users (or specified subset).
        if (empty($useridarray)) {
            $enrolledusers = self::get_enrolled_users_basic($params['courseid'], $companyid);
            $useridarray = array_keys($enrolledusers);
        } else {
            // Limit to MAX_USERS_PER_REQUEST.
            $useridarray = array_slice($useridarray, 0, self::MAX_USERS_PER_REQUEST);
            $enrolledusers = self::get_users_by_ids($useridarray);
        }

        if (empty($useridarray) || empty($cmids)) {
            return [
                'courseid' => $params['courseid'],
                'total_activities' => $totalactivities,
                'users' => [],
                'summary' => [
                    'avg_progress' => 0.0,
                    'completed_count' => 0,
                    'in_progress_count' => 0,
                    'not_started_count' => 0,
                ],
                'cached' => false,
                'cache_expires' => time() + 60,
            ];
        }

        // Get ALL completion data in ONE query.
        list($cminsql, $cmparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
        list($userinsql, $userparams) = $DB->get_in_or_equal($useridarray, SQL_PARAMS_NAMED, 'u');

        $sql = "SELECT cmc.id, cmc.coursemoduleid, cmc.userid, cmc.completionstate, cmc.timemodified
                FROM {course_modules_completion} cmc
                WHERE cmc.coursemoduleid $cminsql AND cmc.userid $userinsql";

        $completions = $DB->get_records_sql($sql, array_merge($cmparams, $userparams));

        // Organize completion data by user.
        $usercompletions = [];
        foreach ($completions as $c) {
            if (!isset($usercompletions[$c->userid])) {
                $usercompletions[$c->userid] = [];
            }
            $usercompletions[$c->userid][$c->coursemoduleid] = $c;
        }

        // Build result.
        $users = [];
        $totalprogress = 0;
        $completedcount = 0;
        $inprogresscount = 0;
        $notstartedcount = 0;

        foreach ($useridarray as $uid) {
            $usercomps = $usercompletions[$uid] ?? [];
            $completedactivities = 0;
            $lastcompletiontime = 0;
            $details = [];

            foreach ($modules as $cmid => $mod) {
                $comp = $usercomps[$cmid] ?? null;
                $state = $comp ? (int)$comp->completionstate : 0;

                if ($state > 0) {
                    $completedactivities++;
                    if ($comp && $comp->timemodified > $lastcompletiontime) {
                        $lastcompletiontime = (int)$comp->timemodified;
                    }
                }

                if ($params['includedetails']) {
                    $details[] = [
                        'cmid' => (int)$cmid,
                        'modulename' => $mod->modulename,
                        'state' => $state,
                        'timemodified' => $comp ? (int)$comp->timemodified : 0,
                    ];
                }
            }

            $progress = $totalactivities > 0 ?
                round(($completedactivities / $totalactivities) * 100, 1) : 0.0;

            $totalprogress += $progress;

            if ($progress >= 100) {
                $completedcount++;
            } elseif ($progress > 0) {
                $inprogresscount++;
            } else {
                $notstartedcount++;
            }

            $userdata = [
                'userid' => (int)$uid,
                'fullname' => $enrolledusers[$uid]['fullname'] ?? "User $uid",
                'email' => $enrolledusers[$uid]['email'] ?? '',
                'completed_activities' => $completedactivities,
                'progress_percent' => $progress,
                'last_completion_time' => $lastcompletiontime,
            ];

            if ($params['includedetails']) {
                $userdata['completion_details'] = $details;
            }

            $users[] = $userdata;
        }

        $result = [
            'courseid' => $params['courseid'],
            'total_activities' => $totalactivities,
            'users' => $users,
            'summary' => [
                'avg_progress' => count($users) > 0 ? round($totalprogress / count($users), 1) : 0.0,
                'completed_count' => $completedcount,
                'in_progress_count' => $inprogresscount,
                'not_started_count' => $notstartedcount,
            ],
            'cached' => false,
            'cache_expires' => time() + 60,
        ];

        $cache->set($cachekey, $result);

        return $result;
    }

    /**
     * Get enrolled users basic info.
     *
     * @param int $courseid Course ID.
     * @param int $companyid Company ID for filtering.
     * @return array Users indexed by ID.
     */
    private static function get_enrolled_users_basic(int $courseid, int $companyid = 0): array {
        global $DB;

        $companyjoin = '';
        $companywhere = '';
        $params = [$courseid];

        if ($companyid > 0 && $DB->get_manager()->table_exists('company_users')) {
            $companyjoin = "JOIN {company_users} cu ON cu.userid = u.id";
            $companywhere = "AND cu.companyid = ?";
            $params[] = $companyid;
        }

        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                $companyjoin
                WHERE e.courseid = ? AND ue.status = 0 AND u.deleted = 0
                $companywhere
                ORDER BY u.lastname, u.firstname
                LIMIT " . self::MAX_USERS_PER_REQUEST;

        $users = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($users as $u) {
            $result[$u->id] = [
                'id' => (int)$u->id,
                'fullname' => fullname($u),
                'email' => $u->email,
            ];
        }

        return $result;
    }

    /**
     * Get users by IDs.
     *
     * @param array $userids User IDs.
     * @return array Users indexed by ID.
     */
    private static function get_users_by_ids(array $userids): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sql = "SELECT id, firstname, lastname, email FROM {user} WHERE id $insql AND deleted = 0";
        $users = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($users as $u) {
            $result[$u->id] = [
                'id' => (int)$u->id,
                'fullname' => fullname($u),
                'email' => $u->email,
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
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'total_activities' => new external_value(PARAM_INT, 'Total activities with completion'),
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_RAW, 'User full name'),
                    'email' => new external_value(PARAM_RAW, 'User email'),
                    'completed_activities' => new external_value(PARAM_INT, 'Completed activities count'),
                    'progress_percent' => new external_value(PARAM_FLOAT, 'Progress percentage'),
                    'last_completion_time' => new external_value(PARAM_INT, 'Last completion timestamp'),
                    'completion_details' => new external_multiple_structure(
                        new external_single_structure([
                            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                            'modulename' => new external_value(PARAM_RAW, 'Module name'),
                            'state' => new external_value(PARAM_INT, 'Completion state'),
                            'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                        ]),
                        'Per-activity completion details',
                        VALUE_OPTIONAL
                    ),
                ]),
                'User completion data'
            ),
            'summary' => new external_single_structure([
                'avg_progress' => new external_value(PARAM_FLOAT, 'Average progress'),
                'completed_count' => new external_value(PARAM_INT, 'Completed users count'),
                'in_progress_count' => new external_value(PARAM_INT, 'In progress users count'),
                'not_started_count' => new external_value(PARAM_INT, 'Not started users count'),
            ]),
            'cached' => new external_value(PARAM_BOOL, 'From cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry'),
        ]);
    }
}
