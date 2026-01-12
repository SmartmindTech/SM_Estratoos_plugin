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

/**
 * External function for getting company users.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_company_users extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID'),
            'departmentid' => new external_value(PARAM_INT, 'Department ID (0 for all)', VALUE_DEFAULT, 0),
            'includeroles' => new external_value(PARAM_BOOL, 'Include user roles', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Get company users (or all users in non-IOMAD mode when companyid = 0).
     *
     * @param int $companyid Company ID (0 for all users in non-IOMAD mode).
     * @param int $departmentid Department ID.
     * @param bool $includeroles Include user roles.
     * @return array Users.
     */
    public static function execute(int $companyid, int $departmentid = 0, bool $includeroles = false): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'departmentid' => $departmentid,
            'includeroles' => $includeroles,
        ]);

        // Check capabilities.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:managecompanytokens', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

        // Get users based on mode.
        if ($isiomad && $params['companyid'] > 0) {
            // IOMAD MODE: Get company users.
            $users = \local_sm_estratoos_plugin\company_token_manager::get_company_users(
                $params['companyid'],
                $params['departmentid'] > 0 ? $params['departmentid'] : null
            );

            // Get company category for role lookup.
            $company = $DB->get_record('company', ['id' => $params['companyid']]);
            $categorycontext = null;
            if ($company && $company->category) {
                $categorycontext = \context_coursecat::instance($company->category, IGNORE_MISSING);
            }
        } else {
            // STANDARD MOODLE MODE: Get all active users (excluding guests and deleted).
            $users = $DB->get_records_select(
                'user',
                "deleted = 0 AND suspended = 0 AND id > 1 AND username != 'guest'",
                null,
                'lastname, firstname',
                'id, username, email, firstname, lastname'
            );
            $categorycontext = null;
        }

        // Format results.
        $result = [];
        foreach ($users as $user) {
            $userdata = [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'fullname' => fullname($user),
                'roles' => [],
            ];

            // Get user roles if requested.
            if ($params['includeroles']) {
                if ($isiomad && $params['companyid'] > 0) {
                    $userdata['roles'] = self::get_user_company_roles($user->id, $categorycontext);
                } else {
                    $userdata['roles'] = self::get_user_system_roles($user->id);
                }
            }

            $result[] = $userdata;
        }

        return ['users' => $result];
    }

    /**
     * Get user roles in company context.
     *
     * @param int $userid User ID.
     * @param \context|null $categorycontext Company category context.
     * @return array Roles.
     */
    private static function get_user_company_roles(int $userid, ?\context $categorycontext): array {
        global $DB;

        $roles = [];

        // Check system-level roles.
        $systemcontext = \context_system::instance();
        $systemroles = get_user_roles($systemcontext, $userid, false);
        foreach ($systemroles as $role) {
            $roles[] = [
                'id' => $role->roleid,
                'shortname' => $role->shortname,
                'name' => $role->name ?: role_get_name($DB->get_record('role', ['id' => $role->roleid])),
            ];
        }

        // Check category-level roles if available.
        if ($categorycontext) {
            $catroles = get_user_roles($categorycontext, $userid, false);
            foreach ($catroles as $role) {
                // Avoid duplicates.
                $exists = false;
                foreach ($roles as $r) {
                    if ($r['id'] == $role->roleid) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $roles[] = [
                        'id' => $role->roleid,
                        'shortname' => $role->shortname,
                        'name' => $role->name ?: role_get_name($DB->get_record('role', ['id' => $role->roleid])),
                    ];
                }
            }
        }

        // Also check company_users table for SmartMind - Estratoos-specific roles.
        $companyuser = $DB->get_record('company_users', ['userid' => $userid]);
        if ($companyuser) {
            // Check for manager role in IOMAD.
            if (!empty($companyuser->managertype) && $companyuser->managertype > 0) {
                $hasManager = false;
                foreach ($roles as $r) {
                    if (stripos($r['shortname'], 'manager') !== false) {
                        $hasManager = true;
                        break;
                    }
                }
                if (!$hasManager) {
                    $roles[] = [
                        'id' => 0,
                        'shortname' => 'companymanager',
                        'name' => get_string('companymanager', 'block_iomad_company_admin'),
                    ];
                }
            }
        }

        // If no roles found, assume student.
        if (empty($roles)) {
            $roles[] = [
                'id' => 0,
                'shortname' => 'student',
                'name' => get_string('student'),
            ];
        }

        return $roles;
    }

    /**
     * Get user roles at system level (for non-IOMAD mode).
     *
     * @param int $userid User ID.
     * @return array Roles.
     */
    private static function get_user_system_roles(int $userid): array {
        global $DB;

        $roles = [];

        // Check system-level roles.
        $systemcontext = \context_system::instance();
        $systemroles = get_user_roles($systemcontext, $userid, false);
        foreach ($systemroles as $role) {
            $roles[] = [
                'id' => $role->roleid,
                'shortname' => $role->shortname,
                'name' => $role->name ?: role_get_name($DB->get_record('role', ['id' => $role->roleid])),
            ];
        }

        // Check for manager/admin capabilities.
        if (is_siteadmin($userid)) {
            $hasAdmin = false;
            foreach ($roles as $r) {
                if ($r['shortname'] === 'admin' || stripos($r['shortname'], 'manager') !== false) {
                    $hasAdmin = true;
                    break;
                }
            }
            if (!$hasAdmin) {
                $roles[] = [
                    'id' => 0,
                    'shortname' => 'manager',
                    'name' => get_string('manager', 'role'),
                ];
            }
        }

        // Check if user has teacher role in any course.
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $noneditingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'teacher']);

        $hasteacherrole = $DB->record_exists_select(
            'role_assignments',
            "userid = ? AND roleid IN (?, ?)",
            [$userid, $teacherroleid, $noneditingteacherroleid]
        );

        if ($hasteacherrole) {
            $hasTeacher = false;
            foreach ($roles as $r) {
                if (stripos($r['shortname'], 'teacher') !== false) {
                    $hasTeacher = true;
                    break;
                }
            }
            if (!$hasTeacher) {
                $roles[] = [
                    'id' => $teacherroleid,
                    'shortname' => 'teacher',
                    'name' => get_string('teacher'),
                ];
            }
        }

        // If no roles found, assume student.
        if (empty($roles)) {
            $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
            $roles[] = [
                'id' => $studentroleid ?: 0,
                'shortname' => 'student',
                'name' => get_string('student'),
            ];
        }

        return $roles;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'email' => new external_value(PARAM_EMAIL, 'Email'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'roles' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Role ID'),
                            'shortname' => new external_value(PARAM_TEXT, 'Role shortname'),
                            'name' => new external_value(PARAM_TEXT, 'Role display name'),
                        ]),
                        'User roles',
                        VALUE_OPTIONAL
                    ),
                ]),
                'Users'
            ),
        ]);
    }
}
