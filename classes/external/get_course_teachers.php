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
 * External function to retrieve teachers enrolled in a course.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;

/**
 * External function to retrieve teachers enrolled in a course.
 */
class get_course_teachers extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'includeprofile' => new external_value(PARAM_BOOL, 'Include extended profile fields', VALUE_DEFAULT, false),
            'includegroups' => new external_value(PARAM_BOOL, 'Include user groups', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Get teachers enrolled in a course.
     *
     * @param int $courseid Course ID.
     * @param bool $includeprofile Include extended profile fields.
     * @param bool $includegroups Include user groups.
     * @return array Teachers data.
     */
    public static function execute(int $courseid, bool $includeprofile = false, bool $includegroups = false): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'includeprofile' => $includeprofile,
            'includegroups' => $includegroups,
        ]);

        // Validate context.
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check capability - user must be able to view participants.
        require_capability('moodle/course:viewparticipants', $context);

        // Get teacher role IDs.
        $teacherroleids = self::get_teacher_role_ids();

        if (empty($teacherroleids)) {
            return ['teachers' => [], 'count' => 0];
        }

        // Use Moodle's enrolment API to get enrolled users.
        // This is more reliable than manual queries and respects all enrolment plugins.
        $enrolledusers = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname, u.firstname');

        // Filter users to only those with teacher roles in this course.
        $teachers = [];
        foreach ($enrolledusers as $user) {
            // Check if user has a teacher role in this course.
            $userroles = get_user_roles($context, $user->id, false);
            $teacherrole = null;
            foreach ($userroles as $role) {
                if (in_array($role->roleid, $teacherroleids)) {
                    $teacherrole = $role;
                    break;
                }
            }

            if ($teacherrole) {
                $teacherdata = self::format_user_data($user, $params['courseid'], $params['includeprofile'], $params['includegroups']);

                // Get role details.
                $rolerecord = $DB->get_record('role', ['id' => $teacherrole->roleid]);
                $teacherdata['role'] = [
                    'id' => (int)$teacherrole->roleid,
                    'shortname' => $rolerecord->shortname,
                    'name' => $rolerecord->name ?: role_get_name($rolerecord),
                    'archetype' => $rolerecord->archetype,
                ];

                $teachers[] = $teacherdata;
            }
        }

        return [
            'teachers' => $teachers,
            'count' => count($teachers),
        ];
    }

    /**
     * Get role IDs that are considered teacher roles.
     *
     * @return array Array of role IDs.
     */
    private static function get_teacher_role_ids(): array {
        global $DB;

        // Get roles with teacher archetype or teacher-like shortnames.
        $sql = "SELECT id FROM {role}
                WHERE archetype IN ('teacher', 'editingteacher')
                   OR LOWER(shortname) LIKE '%teacher%'
                   OR LOWER(shortname) LIKE '%profesor%'
                   OR LOWER(shortname) LIKE '%professor%'
                   OR LOWER(shortname) LIKE '%tutor%'
                   OR LOWER(shortname) LIKE '%docente%'
                   OR LOWER(shortname) LIKE '%formador%'
                   OR LOWER(shortname) LIKE '%maestro%'
                   OR LOWER(shortname) LIKE '%instructor%'";

        $roles = $DB->get_records_sql($sql);
        return array_keys($roles);
    }

    /**
     * Format user data for response.
     *
     * @param object $user User record.
     * @param int $courseid Course ID.
     * @param bool $includeprofile Include extended profile.
     * @param bool $includegroups Include groups.
     * @return array Formatted user data.
     */
    private static function format_user_data(object $user, int $courseid, bool $includeprofile, bool $includegroups): array {
        global $DB, $PAGE;

        $userdata = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'fullname' => fullname($user),
            'idnumber' => $user->idnumber ?? '',
            'lastaccess' => $user->lastaccess ?? 0,
        ];

        // Add profile picture URL.
        $userpicture = new \user_picture($user);
        $userpicture->size = 100;
        $userdata['profileimageurl'] = $userpicture->get_url($PAGE)->out(false);

        // Include extended profile fields.
        if ($includeprofile) {
            $userdata['institution'] = $user->institution ?? '';
            $userdata['department'] = $user->department ?? '';
            $userdata['phone1'] = $user->phone1 ?? '';
            $userdata['phone2'] = $user->phone2 ?? '';
            $userdata['city'] = $user->city ?? '';
            $userdata['country'] = $user->country ?? '';
            $userdata['timezone'] = $user->timezone ?? '';
            $userdata['lang'] = $user->lang ?? '';
        }

        // Include groups.
        if ($includegroups) {
            $groups = groups_get_user_groups($courseid, $user->id);
            $userdata['groups'] = [];
            if (!empty($groups[0])) {
                foreach ($groups[0] as $groupid) {
                    $group = $DB->get_record('groups', ['id' => $groupid], 'id, name, idnumber');
                    if ($group) {
                        $userdata['groups'][] = [
                            'id' => $group->id,
                            'name' => $group->name,
                            'idnumber' => $group->idnumber ?? '',
                        ];
                    }
                }
            }
        }

        return $userdata;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'teachers' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'email' => new external_value(PARAM_EMAIL, 'Email'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'idnumber' => new external_value(PARAM_TEXT, 'ID number', VALUE_OPTIONAL),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp', VALUE_OPTIONAL),
                    'profileimageurl' => new external_value(PARAM_URL, 'Profile image URL', VALUE_OPTIONAL),
                    'role' => new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Role ID'),
                        'shortname' => new external_value(PARAM_TEXT, 'Role shortname'),
                        'name' => new external_value(PARAM_TEXT, 'Role display name'),
                        'archetype' => new external_value(PARAM_TEXT, 'Role archetype'),
                    ], 'Teacher role in this course'),
                    'institution' => new external_value(PARAM_TEXT, 'Institution', VALUE_OPTIONAL),
                    'department' => new external_value(PARAM_TEXT, 'Department', VALUE_OPTIONAL),
                    'phone1' => new external_value(PARAM_TEXT, 'Phone 1', VALUE_OPTIONAL),
                    'phone2' => new external_value(PARAM_TEXT, 'Phone 2', VALUE_OPTIONAL),
                    'city' => new external_value(PARAM_TEXT, 'City', VALUE_OPTIONAL),
                    'country' => new external_value(PARAM_TEXT, 'Country', VALUE_OPTIONAL),
                    'timezone' => new external_value(PARAM_TEXT, 'Timezone', VALUE_OPTIONAL),
                    'lang' => new external_value(PARAM_TEXT, 'Language', VALUE_OPTIONAL),
                    'groups' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Group ID'),
                            'name' => new external_value(PARAM_TEXT, 'Group name'),
                            'idnumber' => new external_value(PARAM_TEXT, 'Group ID number', VALUE_OPTIONAL),
                        ]),
                        'User groups',
                        VALUE_OPTIONAL
                    ),
                ]),
                'Teachers'
            ),
            'count' => new external_value(PARAM_INT, 'Total number of teachers'),
        ]);
    }
}
