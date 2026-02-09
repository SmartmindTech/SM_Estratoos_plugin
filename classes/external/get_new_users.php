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
 * External function to retrieve newly created users.
 *
 * Returns users created via user_manager (tracked in the plugin's
 * user metadata table) since a given timestamp, with optional
 * company filtering and notification marking.
 *
 * Requires site admin or IOMAD company manager privileges.
 * Delegates data retrieval to user_manager::get_new_users().
 *
 * Supports pagination via the limit parameter and returns has_more
 * to indicate whether additional results exist.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use external_warnings;

/**
 * API to retrieve newly created users.
 */
class get_new_users extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(
                PARAM_INT,
                'Unix timestamp — return users created after this time (0 for all)',
                VALUE_DEFAULT,
                0
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID to filter by (0 for all companies)',
                VALUE_DEFAULT,
                0
            ),
            'markasnotified' => new external_value(
                PARAM_BOOL,
                'Mark returned users as notified after retrieval',
                VALUE_DEFAULT,
                false
            ),
            'limit' => new external_value(
                PARAM_INT,
                'Maximum number of users to return (1-1000)',
                VALUE_DEFAULT,
                100
            ),
            'onlyunnotified' => new external_value(
                PARAM_BOOL,
                'Only return users that have not been marked as notified',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Retrieve newly created users.
     *
     * @param int $since Unix timestamp filter.
     * @param int $companyid IOMAD company ID filter.
     * @param bool $markasnotified Mark results as notified.
     * @param int $limit Maximum results to return.
     * @param bool $onlyunnotified Only return un-notified users.
     * @return array List of new users with metadata.
     */
    public static function execute(
        int $since = 0,
        int $companyid = 0,
        bool $markasnotified = false,
        int $limit = 100,
        bool $onlyunnotified = true
    ): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'since' => $since,
            'companyid' => $companyid,
            'markasnotified' => $markasnotified,
            'limit' => $limit,
            'onlyunnotified' => $onlyunnotified,
        ]);

        $warnings = [];

        // Check user is logged in and not guest.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // Permission check: site admin OR IOMAD company manager.
        $haspermission = false;
        if (is_siteadmin()) {
            $haspermission = true;
        } else if (\local_sm_estratoos_plugin\util::is_iomad_installed() && $params['companyid'] > 0) {
            $haspermission = \local_sm_estratoos_plugin\util::can_manage_company($params['companyid']);
        }

        if (!$haspermission) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Enforce limit bounds (1-1000).
        $limit = max(1, min($params['limit'], 1000));

        // Delegate to user_manager.
        $result = \local_sm_estratoos_plugin\user_manager::get_new_users(
            $params['since'],
            $params['companyid'],
            $params['markasnotified'],
            $limit,
            $params['onlyunnotified']
        );

        // Format user records for return.
        $users = [];
        if (!empty($result['users'])) {
            foreach ($result['users'] as $u) {
                $users[] = [
                    'userid' => (int)($u->userid ?? 0),
                    'firstname' => $u->firstname ?? '',
                    'lastname' => $u->lastname ?? '',
                    'email' => $u->email ?? '',
                    'username' => $u->username ?? '',
                    'encrypted_password' => $u->encrypted_password ?? '',
                    'phone_intl_code' => $u->phone_intl_code ?? '',
                    'phone' => $u->phone ?? '',
                    'birthdate' => $u->birthdate ?? '',
                    'city' => $u->city ?? '',
                    'state_province' => $u->state_province ?? '',
                    'country_name' => $u->country_name ?? '',
                    'country_code' => $u->country_code ?? '',
                    'timezone' => $u->timezone ?? '',
                    'moodle_token' => $u->moodle_token ?? '',
                    'moodle_url' => $u->moodle_url ?? '',
                    'companyid' => (int)($u->companyid ?? 0),
                    'timecreated' => (int)($u->timecreated ?? 0),
                    'notified' => (bool)($u->notified ?? false),
                ];
            }
        }

        return [
            'users' => $users,
            'count' => (int)($result['count'] ?? count($users)),
            'has_more' => (bool)($result['has_more'] ?? false),
            'warnings' => $warnings,
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'email' => new external_value(PARAM_TEXT, 'Email address'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'encrypted_password' => new external_value(PARAM_RAW, 'Encrypted password for secure transmission'),
                    'phone_intl_code' => new external_value(PARAM_TEXT, 'International phone code'),
                    'phone' => new external_value(PARAM_TEXT, 'Phone number'),
                    'birthdate' => new external_value(PARAM_TEXT, 'Date of birth (YYYY-MM-DD)'),
                    'city' => new external_value(PARAM_TEXT, 'City'),
                    'state_province' => new external_value(PARAM_TEXT, 'State or province'),
                    'country_name' => new external_value(PARAM_TEXT, 'Country full name'),
                    'country_code' => new external_value(PARAM_TEXT, 'Country code (e.g., BR, US)'),
                    'timezone' => new external_value(PARAM_TEXT, 'Timezone'),
                    'moodle_token' => new external_value(PARAM_RAW, 'Web service token'),
                    'moodle_url' => new external_value(PARAM_URL, 'Moodle site URL'),
                    'companyid' => new external_value(PARAM_INT, 'IOMAD company ID (0 if no company)'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp of user creation'),
                    'notified' => new external_value(PARAM_BOOL, 'Whether external system was notified about this user'),
                ]),
                'List of newly created users'
            ),
            'count' => new external_value(PARAM_INT, 'Number of users returned in this response'),
            'has_more' => new external_value(PARAM_BOOL, 'Whether more users exist beyond the limit'),
            'warnings' => new external_warnings(),
        ]);
    }
}
