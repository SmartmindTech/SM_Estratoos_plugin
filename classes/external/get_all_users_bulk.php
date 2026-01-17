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
use cache_store;
use user_picture;

/**
 * Bulk user fetch with embedded roles and pagination support.
 *
 * Performance target: 5000 users in < 2 seconds
 * Supports both IOMAD (company_users table) and non-IOMAD installations.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_all_users_bulk extends external_api {

    /** @var int Maximum users per page */
    const MAX_PER_PAGE = 1000;

    /** @var int Cache TTL in seconds */
    const CACHE_TTL = 300;

    /**
     * Parameter definitions for the external function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID (0 for all users in non-IOMAD)', VALUE_DEFAULT, 0),
            'include_roles' => new external_value(PARAM_BOOL, 'Include role assignments', VALUE_DEFAULT, true),
            'include_courses' => new external_value(PARAM_BOOL, 'Include enrolled courses', VALUE_DEFAULT, false),
            'page' => new external_value(PARAM_INT, 'Page number (0-indexed)', VALUE_DEFAULT, 0),
            'per_page' => new external_value(PARAM_INT, 'Users per page', VALUE_DEFAULT, 500),
            'sort_by' => new external_value(PARAM_ALPHA, 'Sort field', VALUE_DEFAULT, 'lastname'),
            'sort_order' => new external_value(PARAM_ALPHA, 'Sort order', VALUE_DEFAULT, 'ASC'),
            'search' => new external_value(PARAM_TEXT, 'Search term', VALUE_DEFAULT, ''),
            'role_filter' => new external_value(PARAM_ALPHANUMEXT, 'Filter by role shortname', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Check if IOMAD is installed.
     *
     * @return bool
     */
    private static function is_iomad_installed(): bool {
        global $DB;
        $dbman = $DB->get_manager();
        return $dbman->table_exists('company') && $dbman->table_exists('company_users');
    }

    /**
     * Execute the bulk user fetch.
     *
     * @param int $companyid Company ID (0 for all users)
     * @param bool $include_roles Include role assignments
     * @param bool $include_courses Include enrolled courses
     * @param int $page Page number
     * @param int $per_page Users per page
     * @param string $sort_by Sort field
     * @param string $sort_order Sort order
     * @param string $search Search term
     * @param string $role_filter Role filter
     * @return array
     */
    public static function execute(
        int $companyid = 0,
        bool $include_roles = true,
        bool $include_courses = false,
        int $page = 0,
        int $per_page = 500,
        string $sort_by = 'lastname',
        string $sort_order = 'ASC',
        string $search = '',
        string $role_filter = ''
    ): array {
        global $DB, $PAGE;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'include_roles' => $include_roles,
            'include_courses' => $include_courses,
            'page' => $page,
            'per_page' => $per_page,
            'sort_by' => $sort_by,
            'sort_order' => $sort_order,
            'search' => $search,
            'role_filter' => $role_filter,
        ]);

        // Validate context and capabilities.
        $context = context_system::instance();
        self::validate_context($context);

        // Enforce limits.
        $per_page = min($params['per_page'], self::MAX_PER_PAGE);
        $offset = $params['page'] * $per_page;

        // Validate sort field to prevent SQL injection.
        $allowed_sort_fields = ['id', 'username', 'firstname', 'lastname', 'email', 'lastaccess'];
        $sort_by = in_array($params['sort_by'], $allowed_sort_fields) ? $params['sort_by'] : 'lastname';
        $sort_order = strtoupper($params['sort_order']) === 'DESC' ? 'DESC' : 'ASC';

        // Check if IOMAD is installed.
        $is_iomad = self::is_iomad_installed();

        // Build cache key.
        $cache_key = "users_bulk_{$companyid}_{$include_roles}_{$include_courses}_{$page}_{$per_page}_{$sort_by}_{$sort_order}_" . md5($search . $role_filter);

        // Try cache first.
        $cache = cache::make('local_sm_estratoos_plugin', 'company_users');
        $cached = $cache->get($cache_key);
        if ($cached !== false) {
            $cached['cached'] = true;
            return $cached;
        }

        // Build WHERE clause.
        $where_parts = [
            'u.deleted = 0',
            'u.suspended = 0',
            "u.id != 1", // Exclude guest user.
        ];
        $sql_params = [];

        // Add company filter for IOMAD.
        if ($is_iomad && $companyid > 0) {
            $where_parts[] = 'cu.companyid = :companyid';
            $sql_params['companyid'] = $companyid;
        }

        // Add search filter.
        if (!empty($params['search'])) {
            $search_term = '%' . $DB->sql_like_escape($params['search']) . '%';
            $where_parts[] = "(
                " . $DB->sql_like('u.firstname', ':search1', false) . "
                OR " . $DB->sql_like('u.lastname', ':search2', false) . "
                OR " . $DB->sql_like('u.email', ':search3', false) . "
                OR " . $DB->sql_like('u.username', ':search4', false) . "
            )";
            $sql_params['search1'] = $search_term;
            $sql_params['search2'] = $search_term;
            $sql_params['search3'] = $search_term;
            $sql_params['search4'] = $search_term;
        }

        $where_clause = implode(' AND ', $where_parts);

        // Build joins based on IOMAD presence.
        $company_join = '';
        $company_select = '';
        if ($is_iomad) {
            if ($companyid > 0) {
                $company_join = 'JOIN {company_users} cu ON cu.userid = u.id';
                $company_select = ', cu.managertype';
            } else {
                $company_join = 'LEFT JOIN {company_users} cu ON cu.userid = u.id';
                $company_select = ', COALESCE(cu.managertype, 0) as managertype';
            }
        }

        // Count total (separate query for efficiency on large datasets).
        $count_sql = "SELECT COUNT(DISTINCT u.id)
                      FROM {user} u
                      {$company_join}
                      WHERE {$where_clause}";
        $total_count = $DB->count_records_sql($count_sql, $sql_params);

        // Build role select and join using subquery for compatibility.
        $roles_select = '';
        $roles_join = '';
        $group_by = '';

        if ($include_roles) {
            // Use subquery approach for better compatibility.
            $roles_select = ", (
                SELECT GROUP_CONCAT(DISTINCT r.shortname ORDER BY r.shortname SEPARATOR ',')
                FROM {role_assignments} ra
                JOIN {role} r ON r.id = ra.roleid
                WHERE ra.userid = u.id
            ) as roles";
        }

        // Role filter.
        if (!empty($params['role_filter']) && $include_roles) {
            $where_parts[] = "EXISTS (
                SELECT 1 FROM {role_assignments} ra2
                JOIN {role} r2 ON r2.id = ra2.roleid
                WHERE ra2.userid = u.id AND r2.shortname = :rolefilter
            )";
            $sql_params['rolefilter'] = $params['role_filter'];
            $where_clause = implode(' AND ', $where_parts);
        }

        $main_sql = "SELECT DISTINCT
                        u.id,
                        u.username,
                        u.firstname,
                        u.lastname,
                        u.email,
                        u.firstnamephonetic,
                        u.lastnamephonetic,
                        u.middlename,
                        u.alternatename,
                        u.city,
                        u.country,
                        u.timezone,
                        u.lang,
                        u.lastaccess,
                        u.picture,
                        u.description,
                        u.institution,
                        u.department
                        {$company_select}
                        {$roles_select}
                     FROM {user} u
                     {$company_join}
                     WHERE {$where_clause}
                     ORDER BY u.{$sort_by} {$sort_order}";

        $users_raw = $DB->get_records_sql($main_sql, $sql_params, $offset, $per_page);

        // Process users.
        $users = [];
        $user_ids = [];
        foreach ($users_raw as $user) {
            $user_ids[] = $user->id;
            $user_data = [
                'id' => (int)$user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'fullname' => fullname($user),
                'email' => $user->email,
                'city' => $user->city ?? '',
                'country' => $user->country ?? '',
                'timezone' => $user->timezone ?? '',
                'lang' => $user->lang ?? 'en',
                'lastaccess' => (int)($user->lastaccess ?? 0),
                'picture' => (int)($user->picture ?? 0),
                'description' => $user->description ?? '',
                'institution' => $user->institution ?? '',
                'department' => $user->department ?? '',
                'managertype' => (int)($user->managertype ?? 0),
                'roles' => [],
                'profile_image_url' => '',
                'enrolled_courses' => [],
            ];

            // Parse roles from subquery result.
            if ($include_roles && !empty($user->roles)) {
                $user_data['roles'] = explode(',', $user->roles);
            }

            // Generate profile image URL.
            $user_data['profile_image_url'] = self::get_user_picture_url($user);

            $users[] = $user_data;
        }

        // Fetch enrolled courses if requested (batch query).
        if ($include_courses && !empty($user_ids)) {
            $courses_by_user = self::get_courses_for_users($user_ids);
            foreach ($users as &$user) {
                $user['enrolled_courses'] = $courses_by_user[$user['id']] ?? [];
            }
        }

        // Build result.
        $result = [
            'users' => $users,
            'total_count' => (int)$total_count,
            'page' => $params['page'],
            'per_page' => $per_page,
            'has_more' => ($offset + count($users)) < $total_count,
            'is_iomad' => $is_iomad,
            'cached' => false,
            'cache_expires' => time() + self::CACHE_TTL,
        ];

        // Cache the result.
        $cache->set($cache_key, $result);

        return $result;
    }

    /**
     * Get user profile picture URL.
     *
     * @param object $user User record
     * @return string
     */
    private static function get_user_picture_url($user): string {
        global $PAGE, $CFG;

        try {
            $userpicture = new user_picture($user);
            return $userpicture->get_url($PAGE)->out(false);
        } catch (\Exception $e) {
            return $CFG->wwwroot . '/pix/u/f1.png';
        }
    }

    /**
     * Get enrolled courses for multiple users in a single batch query.
     *
     * @param array $user_ids User IDs
     * @return array Courses indexed by user ID
     */
    private static function get_courses_for_users(array $user_ids): array {
        global $DB;

        if (empty($user_ids)) {
            return [];
        }

        list($in_sql, $params) = $DB->get_in_or_equal($user_ids, SQL_PARAMS_NAMED);

        $sql = "SELECT ue.userid, c.id as courseid, c.fullname, c.shortname, c.visible
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                WHERE ue.userid {$in_sql}
                  AND ue.status = 0
                  AND e.status = 0
                  AND c.visible = 1
                ORDER BY c.fullname ASC";

        $records = $DB->get_records_sql($sql, $params);

        $courses_by_user = [];
        foreach ($records as $record) {
            if (!isset($courses_by_user[$record->userid])) {
                $courses_by_user[$record->userid] = [];
            }
            $courses_by_user[$record->userid][] = [
                'id' => (int)$record->courseid,
                'fullname' => $record->fullname,
                'shortname' => $record->shortname,
            ];
        }

        return $courses_by_user;
    }

    /**
     * Return structure definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_RAW, 'Username'),
                    'firstname' => new external_value(PARAM_RAW, 'First name'),
                    'lastname' => new external_value(PARAM_RAW, 'Last name'),
                    'fullname' => new external_value(PARAM_RAW, 'Full name'),
                    'email' => new external_value(PARAM_RAW, 'Email'),
                    'city' => new external_value(PARAM_RAW, 'City'),
                    'country' => new external_value(PARAM_RAW, 'Country'),
                    'timezone' => new external_value(PARAM_RAW, 'Timezone'),
                    'lang' => new external_value(PARAM_RAW, 'Language'),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp'),
                    'picture' => new external_value(PARAM_INT, 'Picture ID'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'institution' => new external_value(PARAM_RAW, 'Institution'),
                    'department' => new external_value(PARAM_RAW, 'Department'),
                    'managertype' => new external_value(PARAM_INT, 'Manager type (IOMAD)'),
                    'roles' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'Role shortname'),
                        'User roles',
                        VALUE_OPTIONAL
                    ),
                    'profile_image_url' => new external_value(PARAM_URL, 'Profile image URL'),
                    'enrolled_courses' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Course ID'),
                            'fullname' => new external_value(PARAM_RAW, 'Course full name'),
                            'shortname' => new external_value(PARAM_RAW, 'Course short name'),
                        ]),
                        'Enrolled courses',
                        VALUE_OPTIONAL
                    ),
                ])
            ),
            'total_count' => new external_value(PARAM_INT, 'Total user count'),
            'page' => new external_value(PARAM_INT, 'Current page'),
            'per_page' => new external_value(PARAM_INT, 'Users per page'),
            'has_more' => new external_value(PARAM_BOOL, 'More pages available'),
            'is_iomad' => new external_value(PARAM_BOOL, 'IOMAD installation'),
            'cached' => new external_value(PARAM_BOOL, 'Result from cache'),
            'cache_expires' => new external_value(PARAM_INT, 'Cache expiry timestamp'),
        ]);
    }
}
