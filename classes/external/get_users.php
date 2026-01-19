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
 * Search users in company by criteria.
 *
 * This function works with category-scoped tokens (CONTEXT_COURSECAT) and returns
 * only users that belong to the token's company. It mirrors core_user_get_users
 * but validates against category context instead of system context.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_users extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_ALPHA, 'Search key: firstname, lastname, email, username, idnumber'),
                    'value' => new external_value(PARAM_RAW, 'Search value (supports % wildcard)'),
                ]),
                'Search criteria (all criteria are ANDed together)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Search users in the token's company by criteria.
     *
     * @param array $criteria Array of search criteria.
     * @return array Array with 'users' key containing user objects.
     */
    public static function execute(array $criteria = []): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'criteria' => $criteria,
        ]);

        // Determine if we need to apply company filtering.
        $companyuserids = null;
        $companyid = 0;

        // Check if IOMAD is installed and token has company restrictions.
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
            // IOMAD company-scoped token: validate at category context.
            // No capability required - users in the same company can view each other's basic info.
            // Security is enforced by company filtering below.
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);
            $context = \context_coursecat::instance($company->category);
            self::validate_context($context);

            // Get company user IDs for filtering.
            $companyuserids = $DB->get_fieldset_select(
                'company_users',
                'userid',
                'companyid = ?',
                [$companyid]
            );

            if (empty($companyuserids)) {
                return ['users' => [], 'warnings' => []];
            }
        } else {
            // Standard Moodle token (non-IOMAD or no company).
            // No capability required for basic user info needed for messaging.
            if (is_siteadmin()) {
                // Site admin: Use system context.
                $context = \context_system::instance();
            } else {
                // Non-IOMAD normal user: Use top-level category context.
                $topcategory = $DB->get_record('course_categories', ['parent' => 0], 'id', IGNORE_MULTIPLE);
                if ($topcategory) {
                    $context = \context_coursecat::instance($topcategory->id);
                } else {
                    $context = \context_system::instance();
                }
            }
            self::validate_context($context);
        }

        // Build WHERE clause from criteria.
        $allowedkeys = ['firstname', 'lastname', 'email', 'username', 'idnumber', 'id'];
        $where = ['u.deleted = 0', 'u.suspended = 0'];
        $queryparams = [];

        // Add company user filter if applicable.
        if ($companyuserids !== null) {
            list($insql, $inparams) = $DB->get_in_or_equal($companyuserids, SQL_PARAMS_NAMED, 'uid');
            $where[] = "u.id {$insql}";
            $queryparams = array_merge($queryparams, $inparams);
        }

        // Process search criteria.
        $warnings = [];
        $i = 0;
        foreach ($params['criteria'] as $criterion) {
            $key = strtolower($criterion['key']);
            $value = $criterion['value'];

            if (!in_array($key, $allowedkeys)) {
                $warnings[] = [
                    'item' => $key,
                    'warningcode' => 'invalidfieldparameter',
                    'message' => "Invalid search key: {$key}. Allowed: " . implode(', ', $allowedkeys),
                ];
                continue;
            }

            $paramname = 'crit' . $i++;

            // Handle ID field as exact match (integer).
            if ($key === 'id') {
                $where[] = "u.id = :{$paramname}";
                $queryparams[$paramname] = (int)$value;
            } else {
                // Use LIKE for text fields (support % wildcard).
                if (strpos($value, '%') === false) {
                    // If no wildcard provided, add them for partial matching.
                    $value = '%' . $DB->sql_like_escape($value) . '%';
                } else {
                    // User provided wildcards, escape only special chars (not %).
                    $value = str_replace(['_', '\\'], ['\\_', '\\\\'], $value);
                }
                $where[] = $DB->sql_like("u.{$key}", ":{$paramname}", false, false);
                $queryparams[$paramname] = $value;
            }
        }

        // Execute query.
        $sql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                       u.city, u.country, u.timezone, u.description, u.descriptionformat,
                       u.institution, u.department, u.phone1, u.phone2, u.address,
                       u.lang, u.theme, u.picture, u.imagealt
                FROM {user} u
                WHERE " . implode(' AND ', $where) . "
                ORDER BY u.lastname ASC, u.firstname ASC";

        // Limit results to prevent performance issues.
        $users = $DB->get_records_sql($sql, $queryparams, 0, 500);

        // Format results.
        $result = [];
        global $PAGE;

        foreach ($users as $user) {
            // Build profile image URLs.
            $userpicture = new \user_picture($user);
            $userpicture->size = 1; // Size f1 (small).
            $profileimageurl = $userpicture->get_url($PAGE)->out(false);
            $userpicture->size = 0; // Size f2 (large).
            $profileimageurlsmall = $userpicture->get_url($PAGE)->out(false);

            $result[] = [
                'id' => (int)$user->id,
                'username' => $user->username,
                'email' => $user->email ?? '',
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'fullname' => fullname($user),
                'firstnamephonetic' => $user->firstnamephonetic ?? '',
                'lastnamephonetic' => $user->lastnamephonetic ?? '',
                'middlename' => $user->middlename ?? '',
                'alternatename' => $user->alternatename ?? '',
                'city' => $user->city ?? '',
                'country' => $user->country ?? '',
                'timezone' => $user->timezone ?? '',
                'description' => $user->description ?? '',
                'descriptionformat' => (int)($user->descriptionformat ?? FORMAT_HTML),
                'institution' => $user->institution ?? '',
                'department' => $user->department ?? '',
                'phone1' => $user->phone1 ?? '',
                'phone2' => $user->phone2 ?? '',
                'address' => $user->address ?? '',
                'lang' => $user->lang ?? '',
                'theme' => $user->theme ?? '',
                'profileimageurl' => $profileimageurl,
                'profileimageurlsmall' => $profileimageurlsmall,
            ];
        }

        return [
            'users' => $result,
            'warnings' => $warnings,
        ];
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
                    'username' => new external_value(PARAM_RAW, 'Username'),
                    'email' => new external_value(PARAM_TEXT, 'Email address'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'firstnamephonetic' => new external_value(PARAM_TEXT, 'First name phonetic', VALUE_OPTIONAL),
                    'lastnamephonetic' => new external_value(PARAM_TEXT, 'Last name phonetic', VALUE_OPTIONAL),
                    'middlename' => new external_value(PARAM_TEXT, 'Middle name', VALUE_OPTIONAL),
                    'alternatename' => new external_value(PARAM_TEXT, 'Alternate name', VALUE_OPTIONAL),
                    'city' => new external_value(PARAM_TEXT, 'City', VALUE_OPTIONAL),
                    'country' => new external_value(PARAM_TEXT, 'Country code', VALUE_OPTIONAL),
                    'timezone' => new external_value(PARAM_TEXT, 'Timezone', VALUE_OPTIONAL),
                    'description' => new external_value(PARAM_RAW, 'User profile description', VALUE_OPTIONAL),
                    'descriptionformat' => new external_value(PARAM_INT, 'Description format', VALUE_OPTIONAL),
                    'institution' => new external_value(PARAM_TEXT, 'Institution', VALUE_OPTIONAL),
                    'department' => new external_value(PARAM_TEXT, 'Department', VALUE_OPTIONAL),
                    'phone1' => new external_value(PARAM_TEXT, 'Phone 1', VALUE_OPTIONAL),
                    'phone2' => new external_value(PARAM_TEXT, 'Phone 2', VALUE_OPTIONAL),
                    'address' => new external_value(PARAM_TEXT, 'Address', VALUE_OPTIONAL),
                    'lang' => new external_value(PARAM_TEXT, 'Language code', VALUE_OPTIONAL),
                    'theme' => new external_value(PARAM_TEXT, 'Theme', VALUE_OPTIONAL),
                    'profileimageurl' => new external_value(PARAM_URL, 'Profile image URL', VALUE_OPTIONAL),
                    'profileimageurlsmall' => new external_value(PARAM_URL, 'Small profile image URL', VALUE_OPTIONAL),
                ])
            ),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_TEXT, 'Item name', VALUE_OPTIONAL),
                    'warningcode' => new external_value(PARAM_ALPHANUM, 'Warning code'),
                    'message' => new external_value(PARAM_TEXT, 'Warning message'),
                ]),
                'Warnings',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
