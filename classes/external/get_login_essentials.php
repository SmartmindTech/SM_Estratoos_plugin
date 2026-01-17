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
 * Get all essential data needed for login in a single call.
 *
 * Replaces multiple API calls: get_site_info + get_users_courses + enrolled_users (for roles) + user_details.
 * Reduces login time from ~6 seconds to <500ms.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_login_essentials extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
            'options' => new external_value(PARAM_RAW,
                'JSON options: includeroles, includecourses, includesiteinfo',
                VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Get all essential data needed for login in a single call.
     *
     * @param int $userid Moodle user ID.
     * @param string $options JSON options string.
     * @return array Login essentials data.
     */
    public static function execute(int $userid, string $options = '{}'): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'options' => $options,
        ]);

        $opts = json_decode($params['options'], true) ?: [];
        $includeroles = $opts['includeroles'] ?? true;
        $includecourses = $opts['includecourses'] ?? true;
        $includesiteinfo = $opts['includesiteinfo'] ?? true;

        // Determine context based on IOMAD or standard Moodle.
        $companyid = 0;
        $context = context_system::instance(); // Default to system context.

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

                            // Verify user is in the company.
                            $isincompany = $DB->record_exists('company_users', [
                                'companyid' => $companyid,
                                'userid' => $params['userid'],
                            ]);
                            if (!$isincompany && $USER->id != $params['userid']) {
                                throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin');
                            }
                        }
                    }
                }
            }
        } catch (\moodle_exception $e) {
            // Re-throw moodle_exceptions (like usernotincompany) - these are intentional.
            throw $e;
        } catch (\Exception $e) {
            // Database error - fall back to standard Moodle mode.
            debugging('get_login_essentials: IOMAD query failed, falling back to standard mode - ' . $e->getMessage(), DEBUG_DEVELOPER);
            $companyid = 0;
            $context = context_system::instance();
        }

        self::validate_context($context);

        // Check permission to view other users' data.
        if ($USER->id != $params['userid']) {
            require_capability('moodle/user:viewdetails', $context);
        }

        // Check cache first.
        $cache = \cache::make('local_sm_estratoos_plugin', 'login_essentials');
        $cachekey = "login_essentials_{$params['userid']}";
        $cached = $cache->get($cachekey);

        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        $result = [];

        // 1. Get user info.
        $user = $DB->get_record('user', ['id' => $params['userid']], '*', MUST_EXIST);
        $result['user'] = [
            'id' => (int)$user->id,
            'username' => $user->username,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'fullname' => fullname($user),
            'profileimageurl' => self::get_user_picture_url($user),
            'lang' => $user->lang ?: 'en',
            'timezone' => $user->timezone ?: '99',
            'lastaccess' => (int)$user->lastaccess,
        ];

        // 2. Get site info.
        if ($includesiteinfo) {
            $result['site'] = [
                'sitename' => format_string($CFG->fullname),
                'siteurl' => $CFG->wwwroot,
                'release' => $CFG->release ?? '',
                'version' => $CFG->version ?? '',
                'lang' => current_language(),
            ];
        } else {
            $result['site'] = null;
        }

        // 3. Get roles (optimized single query).
        if ($includeroles) {
            $result['roles'] = self::get_user_roles_optimized($params['userid']);
        } else {
            $result['roles'] = null;
        }

        // 4. Get enrolled courses with progress.
        if ($includecourses) {
            $result['courses'] = self::get_user_courses_with_progress($params['userid'], $companyid);
        } else {
            $result['courses'] = [];
        }

        $result['cached'] = false;
        $result['cache_expires'] = time() + 300; // 5 minutes.

        // Cache the result.
        $cache->set($cachekey, $result);

        return $result;
    }

    /**
     * Get user profile picture URL.
     *
     * @param object $user User object.
     * @return string Profile image URL.
     */
    private static function get_user_picture_url($user): string {
        global $PAGE;

        try {
            $userpicture = new \user_picture($user);
            $userpicture->size = 100;
            return $userpicture->get_url($PAGE)->out(false);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get user roles across all contexts in a single optimized query.
     *
     * @param int $userid User ID.
     * @return array Roles data.
     */
    private static function get_user_roles_optimized(int $userid): array {
        global $DB;

        // Single query to get all role assignments.
        $sql = "SELECT DISTINCT r.shortname, r.id, ctx.contextlevel, ctx.instanceid
                FROM {role_assignments} ra
                JOIN {role} r ON r.id = ra.roleid
                JOIN {context} ctx ON ctx.id = ra.contextid
                WHERE ra.userid = ?
                ORDER BY ctx.contextlevel, r.sortorder";

        $assignments = $DB->get_records_sql($sql, [$userid]);

        $systemroles = [];
        $courseroles = [];
        $highest = 'user';
        $hierarchy = [
            'admin' => 1,
            'manager' => 2,
            'coursecreator' => 3,
            'editingteacher' => 4,
            'teacher' => 5,
            'student' => 6,
            'user' => 7,
        ];

        foreach ($assignments as $ra) {
            if ($ra->contextlevel == CONTEXT_SYSTEM) {
                $systemroles[] = $ra->shortname;
            } elseif ($ra->contextlevel == CONTEXT_COURSE) {
                if (!isset($courseroles[$ra->instanceid])) {
                    $courseroles[$ra->instanceid] = [];
                }
                $courseroles[$ra->instanceid][] = $ra->shortname;
            }

            // Track highest role.
            if (isset($hierarchy[$ra->shortname]) &&
                $hierarchy[$ra->shortname] < $hierarchy[$highest]) {
                $highest = $ra->shortname;
            }
        }

        // Check if site admin.
        $isadmin = is_siteadmin($userid);
        if ($isadmin) {
            $highest = 'admin';
        }

        return [
            'system_roles' => array_values(array_unique($systemroles)),
            'highest_role' => $highest,
            'is_admin' => $isadmin,
            'is_manager' => in_array('manager', $systemroles),
            'is_teacher' => $highest === 'editingteacher' || $highest === 'teacher',
            'course_roles' => $courseroles,
        ];
    }

    /**
     * Get user courses with completion progress in a single optimized query.
     *
     * @param int $userid User ID.
     * @param int $companyid Company ID for IOMAD filtering (0 for no filtering).
     * @return array Courses with progress.
     */
    private static function get_user_courses_with_progress(int $userid, int $companyid = 0): array {
        global $DB;

        // Build company filter if IOMAD (using named parameters for clarity).
        $companyjoin = '';
        $companywhere = '';
        $params = [
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
        ];

        if ($companyid > 0 && $DB->get_manager()->table_exists('company_course')) {
            $companyjoin = "JOIN {company_course} cc ON cc.courseid = c.id";
            $companywhere = "AND cc.companyid = :companyid";
            $params['companyid'] = $companyid;
        }

        // Single query for courses + progress.
        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.category,
                       c.startdate, c.enddate, c.visible,
                       (SELECT MAX(ue2.timeaccess) FROM {user_enrolments} ue2
                        JOIN {enrol} e2 ON e2.id = ue2.enrolid
                        WHERE e2.courseid = c.id AND ue2.userid = :userid1) as lastaccess,
                       (SELECT COUNT(*) FROM {course_modules} cm
                        WHERE cm.course = c.id AND cm.completion > 0 AND cm.deletioninprogress = 0) as total_activities,
                       (SELECT COUNT(*) FROM {course_modules_completion} cmc
                        JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                        WHERE cm.course = c.id AND cmc.userid = :userid2 AND cmc.completionstate > 0
                        AND cm.deletioninprogress = 0) as completed_activities
                FROM {course} c
                JOIN {enrol} e ON e.courseid = c.id
                JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid3
                $companyjoin
                WHERE c.id != 1 AND ue.status = 0
                $companywhere
                ORDER BY c.sortorder";

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
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'user' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User ID'),
                'username' => new external_value(PARAM_RAW, 'Username'),
                'firstname' => new external_value(PARAM_RAW, 'First name'),
                'lastname' => new external_value(PARAM_RAW, 'Last name'),
                'email' => new external_value(PARAM_RAW, 'Email'),
                'fullname' => new external_value(PARAM_RAW, 'Full name'),
                'profileimageurl' => new external_value(PARAM_URL, 'Profile image URL', VALUE_OPTIONAL),
                'lang' => new external_value(PARAM_RAW, 'Language'),
                'timezone' => new external_value(PARAM_RAW, 'Timezone'),
                'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp'),
            ]),
            'site' => new external_single_structure([
                'sitename' => new external_value(PARAM_RAW, 'Site name'),
                'siteurl' => new external_value(PARAM_URL, 'Site URL'),
                'release' => new external_value(PARAM_RAW, 'Moodle release'),
                'version' => new external_value(PARAM_RAW, 'Moodle version'),
                'lang' => new external_value(PARAM_RAW, 'Site language'),
            ], 'Site information', VALUE_OPTIONAL),
            'roles' => new external_single_structure([
                'system_roles' => new external_multiple_structure(
                    new external_value(PARAM_RAW, 'Role shortname'),
                    'System-level roles'
                ),
                'highest_role' => new external_value(PARAM_RAW, 'Highest role'),
                'is_admin' => new external_value(PARAM_BOOL, 'Is site admin'),
                'is_manager' => new external_value(PARAM_BOOL, 'Is manager'),
                'is_teacher' => new external_value(PARAM_BOOL, 'Is teacher'),
                'course_roles' => new external_value(PARAM_RAW, 'Course roles as JSON object'),
            ], 'User roles', VALUE_OPTIONAL),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_RAW, 'Course full name'),
                    'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                    'category' => new external_value(PARAM_INT, 'Category ID'),
                    'startdate' => new external_value(PARAM_INT, 'Start date'),
                    'enddate' => new external_value(PARAM_INT, 'End date'),
                    'visible' => new external_value(PARAM_BOOL, 'Is visible'),
                    'progress' => new external_value(PARAM_INT, 'Completion progress percentage'),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp'),
                ]),
                'Enrolled courses'
            ),
            'cached' => new external_value(PARAM_BOOL, 'Whether result was from cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry timestamp'),
        ]);
    }
}
