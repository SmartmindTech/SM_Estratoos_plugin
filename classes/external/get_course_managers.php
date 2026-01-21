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
 * External function to retrieve managers for a course.
 *
 * Managers include users with manager roles at:
 * - Course context (course managers)
 * - Category context (category managers who manage the course's category)
 * - IOMAD company context (company managers for the company that owns the course)
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
use context_coursecat;

/**
 * External function to retrieve managers for a course.
 */
class get_course_managers extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'includeprofile' => new external_value(PARAM_BOOL, 'Include extended profile fields', VALUE_DEFAULT, false),
            'includecategorymanagers' => new external_value(PARAM_BOOL, 'Include category-level managers', VALUE_DEFAULT, true),
            'includecompanymanagers' => new external_value(PARAM_BOOL, 'Include IOMAD company managers', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Get managers for a course.
     *
     * @param int $courseid Course ID.
     * @param bool $includeprofile Include extended profile fields.
     * @param bool $includecategorymanagers Include category-level managers.
     * @param bool $includecompanymanagers Include IOMAD company managers.
     * @return array Managers data.
     */
    public static function execute(
        int $courseid,
        bool $includeprofile = false,
        bool $includecategorymanagers = true,
        bool $includecompanymanagers = true
    ): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'includeprofile' => $includeprofile,
            'includecategorymanagers' => $includecategorymanagers,
            'includecompanymanagers' => $includecompanymanagers,
        ]);

        // Validate context.
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check capability - user must be able to view participants.
        require_capability('moodle/course:viewparticipants', $context);

        // Get course to find its category.
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, category', MUST_EXIST);

        // Apply company filtering if IOMAD token.
        $companyuserids = null;
        $companyid = null;
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $token = \local_sm_estratoos_plugin\util::get_current_request_token();
            if ($token) {
                $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                if ($restrictions && !empty($restrictions->companyid) && $restrictions->restricttocompany) {
                    $filter = new \local_sm_estratoos_plugin\webservice_filter($restrictions);
                    $companyuserids = $filter->get_company_user_ids();
                    $companyid = $restrictions->companyid;
                }
            }
        }

        // Get manager role IDs.
        $managerroleids = self::get_manager_role_ids();

        $managers = [];
        $addedUserIds = [];

        // 1. Get course-level managers.
        if (!empty($managerroleids)) {
            $coursemanagers = self::get_context_managers(
                $context->id,
                $managerroleids,
                $companyuserids,
                'course'
            );

            foreach ($coursemanagers as $manager) {
                if (!isset($addedUserIds[$manager['id']])) {
                    $manager['scope'] = 'course';
                    $managers[] = self::enrich_manager_data($manager, $params['courseid'], $params['includeprofile']);
                    $addedUserIds[$manager['id']] = true;
                }
            }
        }

        // 2. Get category-level managers.
        if ($params['includecategorymanagers'] && !empty($managerroleids) && $course->category > 0) {
            $categorycontext = context_coursecat::instance($course->category);
            $categorymanagers = self::get_context_managers(
                $categorycontext->id,
                $managerroleids,
                $companyuserids,
                'category'
            );

            foreach ($categorymanagers as $manager) {
                if (!isset($addedUserIds[$manager['id']])) {
                    $manager['scope'] = 'category';
                    $managers[] = self::enrich_manager_data($manager, $params['courseid'], $params['includeprofile']);
                    $addedUserIds[$manager['id']] = true;
                }
            }

            // Also check parent categories.
            $parentcats = self::get_parent_category_ids($course->category);
            foreach ($parentcats as $parentcatid) {
                $parentcontext = context_coursecat::instance($parentcatid);
                $parentmanagers = self::get_context_managers(
                    $parentcontext->id,
                    $managerroleids,
                    $companyuserids,
                    'category'
                );

                foreach ($parentmanagers as $manager) {
                    if (!isset($addedUserIds[$manager['id']])) {
                        $manager['scope'] = 'category';
                        $managers[] = self::enrich_manager_data($manager, $params['courseid'], $params['includeprofile']);
                        $addedUserIds[$manager['id']] = true;
                    }
                }
            }
        }

        // 3. Get IOMAD company managers.
        if ($params['includecompanymanagers'] && \local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $iomadmanagers = self::get_iomad_company_managers($params['courseid'], $companyid, $companyuserids);

            foreach ($iomadmanagers as $manager) {
                if (!isset($addedUserIds[$manager['id']])) {
                    $manager['scope'] = 'company';
                    $managers[] = self::enrich_manager_data($manager, $params['courseid'], $params['includeprofile']);
                    $addedUserIds[$manager['id']] = true;
                }
            }
        }

        return [
            'managers' => $managers,
            'count' => count($managers),
        ];
    }

    /**
     * Get role IDs that are considered manager roles.
     *
     * @return array Array of role IDs.
     */
    private static function get_manager_role_ids(): array {
        global $DB;

        // Get roles with manager archetype or manager-like shortnames.
        $sql = "SELECT id FROM {role}
                WHERE archetype = 'manager'
                   OR LOWER(shortname) LIKE '%manager%'
                   OR LOWER(shortname) LIKE '%admin%'
                   OR LOWER(shortname) LIKE '%gestor%'
                   OR LOWER(shortname) LIKE '%gerente%'
                   OR LOWER(shortname) LIKE '%administrador%'
                   OR LOWER(shortname) LIKE '%coordinador%'
                   OR LOWER(shortname) LIKE '%coordinator%'
                   OR LOWER(shortname) = 'companymanager'";

        $roles = $DB->get_records_sql($sql);
        return array_keys($roles);
    }

    /**
     * Get managers from a specific context.
     *
     * @param int $contextid Context ID.
     * @param array $managerroleids Manager role IDs.
     * @param array|null $companyuserids Company user IDs filter.
     * @param string $scopetype Scope type for logging.
     * @return array Managers.
     */
    private static function get_context_managers(int $contextid, array $managerroleids, ?array $companyuserids, string $scopetype): array {
        global $DB;

        list($roleinsql, $roleparams) = $DB->get_in_or_equal($managerroleids, SQL_PARAMS_NAMED, 'role');

        $sql = "SELECT DISTINCT u.id, u.username, u.email, u.firstname, u.lastname,
                       u.idnumber, u.institution, u.department, u.phone1, u.phone2,
                       u.city, u.country, u.timezone, u.lang, u.lastaccess,
                       u.picture, u.imagealt, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename,
                       r.id as roleid, r.shortname as roleshortname, r.name as rolename, r.archetype as rolearchetype
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {role} r ON r.id = ra.roleid
                WHERE ra.contextid = :contextid
                  AND ra.roleid $roleinsql
                  AND u.deleted = 0
                  AND u.suspended = 0";

        $queryparams = array_merge(['contextid' => $contextid], $roleparams);

        // Add company filtering if applicable.
        if ($companyuserids !== null) {
            if (empty($companyuserids)) {
                return [];
            }
            list($userinsql, $userparams) = $DB->get_in_or_equal($companyuserids, SQL_PARAMS_NAMED, 'user');
            $sql .= " AND u.id $userinsql";
            $queryparams = array_merge($queryparams, $userparams);
        }

        $sql .= " ORDER BY u.lastname, u.firstname";

        $records = $DB->get_records_sql($sql, $queryparams);

        $managers = [];
        foreach ($records as $record) {
            $managers[] = [
                'id' => $record->id,
                'username' => $record->username,
                'email' => $record->email,
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'fullname' => fullname($record),
                'idnumber' => $record->idnumber ?? '',
                'lastaccess' => $record->lastaccess ?? 0,
                'role' => [
                    'id' => $record->roleid,
                    'shortname' => $record->roleshortname,
                    'name' => $record->rolename ?: role_get_name($DB->get_record('role', ['id' => $record->roleid])),
                    'archetype' => $record->rolearchetype,
                ],
                'user' => $record,
            ];
        }

        return $managers;
    }

    /**
     * Get parent category IDs for a category.
     *
     * @param int $categoryid Category ID.
     * @return array Parent category IDs.
     */
    private static function get_parent_category_ids(int $categoryid): array {
        global $DB;

        $parents = [];
        $category = $DB->get_record('course_categories', ['id' => $categoryid]);

        while ($category && $category->parent > 0) {
            $parents[] = $category->parent;
            $category = $DB->get_record('course_categories', ['id' => $category->parent]);
        }

        return $parents;
    }

    /**
     * Get IOMAD company managers for a course.
     *
     * @param int $courseid Course ID.
     * @param int|null $companyid Company ID to filter by.
     * @param array|null $companyuserids Company user IDs filter.
     * @return array Company managers.
     */
    private static function get_iomad_company_managers(int $courseid, ?int $companyid, ?array $companyuserids): array {
        global $DB;

        // Get company that owns this course.
        $coursecompanyid = null;
        if ($companyid) {
            $coursecompanyid = $companyid;
        } else {
            // Try to find the company from company_course table.
            $companycourse = $DB->get_record('company_course', ['courseid' => $courseid]);
            if ($companycourse) {
                $coursecompanyid = $companycourse->companyid;
            }
        }

        if (!$coursecompanyid) {
            return [];
        }

        // Get company managers (managertype > 0).
        $sql = "SELECT DISTINCT u.id, u.username, u.email, u.firstname, u.lastname,
                       u.idnumber, u.institution, u.department, u.phone1, u.phone2,
                       u.city, u.country, u.timezone, u.lang, u.lastaccess,
                       u.picture, u.imagealt, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename,
                       cu.managertype
                FROM {user} u
                JOIN {company_users} cu ON cu.userid = u.id
                WHERE cu.companyid = :companyid
                  AND cu.managertype > 0
                  AND u.deleted = 0
                  AND u.suspended = 0";

        $queryparams = ['companyid' => $coursecompanyid];

        // Add company user filtering if applicable.
        if ($companyuserids !== null) {
            if (empty($companyuserids)) {
                return [];
            }
            list($userinsql, $userparams) = $DB->get_in_or_equal($companyuserids, SQL_PARAMS_NAMED, 'user');
            $sql .= " AND u.id $userinsql";
            $queryparams = array_merge($queryparams, $userparams);
        }

        $sql .= " ORDER BY u.lastname, u.firstname";

        $records = $DB->get_records_sql($sql, $queryparams);

        $managers = [];
        foreach ($records as $record) {
            // Determine manager type label.
            $managertypelabel = 'Company User';
            if ($record->managertype == 1) {
                $managertypelabel = 'Department Manager';
            } else if ($record->managertype == 2) {
                $managertypelabel = 'Company Manager';
            }

            $managers[] = [
                'id' => $record->id,
                'username' => $record->username,
                'email' => $record->email,
                'firstname' => $record->firstname,
                'lastname' => $record->lastname,
                'fullname' => fullname($record),
                'idnumber' => $record->idnumber ?? '',
                'lastaccess' => $record->lastaccess ?? 0,
                'role' => [
                    'id' => 0,
                    'shortname' => 'companymanager',
                    'name' => $managertypelabel,
                    'archetype' => 'manager',
                ],
                'user' => $record,
                'managertype' => $record->managertype,
            ];
        }

        return $managers;
    }

    /**
     * Enrich manager data with profile image and optional extended fields.
     *
     * @param array $manager Manager data.
     * @param int $courseid Course ID.
     * @param bool $includeprofile Include extended profile.
     * @return array Enriched manager data.
     */
    private static function enrich_manager_data(array $manager, int $courseid, bool $includeprofile): array {
        global $PAGE;

        $user = $manager['user'];
        unset($manager['user']);

        // Add profile picture URL.
        $userpicture = new \user_picture($user);
        $userpicture->size = 100;
        $manager['profileimageurl'] = $userpicture->get_url($PAGE)->out(false);

        // Include extended profile fields.
        if ($includeprofile) {
            $manager['institution'] = $user->institution ?? '';
            $manager['department'] = $user->department ?? '';
            $manager['phone1'] = $user->phone1 ?? '';
            $manager['phone2'] = $user->phone2 ?? '';
            $manager['city'] = $user->city ?? '';
            $manager['country'] = $user->country ?? '';
            $manager['timezone'] = $user->timezone ?? '';
            $manager['lang'] = $user->lang ?? '';
        }

        return $manager;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'managers' => new external_multiple_structure(
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
                    'scope' => new external_value(PARAM_TEXT, 'Manager scope: course, category, or company'),
                    'role' => new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Role ID'),
                        'shortname' => new external_value(PARAM_TEXT, 'Role shortname'),
                        'name' => new external_value(PARAM_TEXT, 'Role display name'),
                        'archetype' => new external_value(PARAM_TEXT, 'Role archetype'),
                    ], 'Manager role'),
                    'managertype' => new external_value(PARAM_INT, 'IOMAD manager type (1=dept, 2=company)', VALUE_OPTIONAL),
                    'institution' => new external_value(PARAM_TEXT, 'Institution', VALUE_OPTIONAL),
                    'department' => new external_value(PARAM_TEXT, 'Department', VALUE_OPTIONAL),
                    'phone1' => new external_value(PARAM_TEXT, 'Phone 1', VALUE_OPTIONAL),
                    'phone2' => new external_value(PARAM_TEXT, 'Phone 2', VALUE_OPTIONAL),
                    'city' => new external_value(PARAM_TEXT, 'City', VALUE_OPTIONAL),
                    'country' => new external_value(PARAM_TEXT, 'Country', VALUE_OPTIONAL),
                    'timezone' => new external_value(PARAM_TEXT, 'Timezone', VALUE_OPTIONAL),
                    'lang' => new external_value(PARAM_TEXT, 'Language', VALUE_OPTIONAL),
                ]),
                'Managers'
            ),
            'count' => new external_value(PARAM_INT, 'Total number of managers'),
        ]);
    }
}
