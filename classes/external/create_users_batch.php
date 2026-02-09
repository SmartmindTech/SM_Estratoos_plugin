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
 * External function to create multiple user accounts in batch.
 *
 * Accepts either a structured users array or raw CSV data.
 * Requires site admin or IOMAD company manager privileges.
 * Delegates all business logic to user_manager::create_users_batch().
 *
 * Returns per-user results with structured error codes instead of
 * throwing exceptions, so the frontend can display specific messages
 * and show which users succeeded/failed.
 *
 * Error codes (per-user, returned by user_manager):
 * - empty_firstname: First name is empty
 * - empty_lastname: Last name is empty
 * - empty_email: Email is empty
 * - invalid_email: Email format is invalid
 * - email_taken: Email is already used by another user
 * - username_taken: Username is already in use
 * - empty_password: No password provided and generate_password is false
 * - password_policy: Password does not meet Moodle password policy
 * - company_not_found: IOMAD company ID does not exist
 * - user_creation_failed: Unexpected error during user creation
 * - csv_parse_error: CSV data could not be parsed
 * - no_users: No users provided (empty array and empty CSV)
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
 * API to create multiple user accounts in batch.
 */
class create_users_batch extends external_api {

    /**
     * Define input parameters.
     *
     * Accepts users as a structured array, raw CSV data, or both.
     * When CSV is provided, it is parsed and merged with the users array.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'firstname' => new external_value(
                        PARAM_TEXT,
                        'User first name'
                    ),
                    'lastname' => new external_value(
                        PARAM_TEXT,
                        'User last name'
                    ),
                    'email' => new external_value(
                        PARAM_TEXT,
                        'User email address'
                    ),
                    'username' => new external_value(
                        PARAM_TEXT,
                        'Username (auto-generated from email if empty)',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'password' => new external_value(
                        PARAM_RAW,
                        'Plain-text password (ignored if generate_password is true)',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'generate_password' => new external_value(
                        PARAM_BOOL,
                        'Generate a random password for this user',
                        VALUE_DEFAULT,
                        false
                    ),
                    'phone_intl_code' => new external_value(
                        PARAM_TEXT,
                        'International phone code (e.g., +55)',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'phone' => new external_value(
                        PARAM_TEXT,
                        'Phone number',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'birthdate' => new external_value(
                        PARAM_TEXT,
                        'Date of birth (YYYY-MM-DD format)',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'city' => new external_value(
                        PARAM_TEXT,
                        'City',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'state_province' => new external_value(
                        PARAM_TEXT,
                        'State or province',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'country' => new external_value(
                        PARAM_TEXT,
                        'Country code (e.g., BR, US, ES)',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'timezone' => new external_value(
                        PARAM_TEXT,
                        'Timezone (e.g., America/Sao_Paulo)',
                        VALUE_DEFAULT,
                        ''
                    ),
                ]),
                'Array of user data objects to create',
                VALUE_DEFAULT,
                []
            ),
            'csvdata' => new external_value(
                PARAM_RAW,
                'Raw CSV data with user records (alternative to users array)',
                VALUE_DEFAULT,
                ''
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID to assign all users to (0 for standard Moodle)',
                VALUE_DEFAULT,
                0
            ),
            'serviceid' => new external_value(
                PARAM_INT,
                'External service ID for token creation (0 to skip tokens)',
                VALUE_DEFAULT,
                0
            ),
            'generate_password' => new external_value(
                PARAM_BOOL,
                'Generate random passwords for all users that do not have one',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Create multiple user accounts in batch.
     *
     * @param array $users Array of user data.
     * @param string $csvdata Raw CSV data.
     * @param int $companyid IOMAD company ID.
     * @param int $serviceid External service ID for token creation.
     * @param bool $generate_password Generate passwords for users without one.
     * @return array Batch result with per-user outcomes.
     */
    public static function execute(
        array $users = [],
        string $csvdata = '',
        int $companyid = 0,
        int $serviceid = 0,
        bool $generate_password = true
    ): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'users' => $users,
            'csvdata' => $csvdata,
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'generate_password' => $generate_password,
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

        // Collect users from CSV if provided.
        $allusers = [];

        if (!empty($params['csvdata'])) {
            $csvresult = \local_sm_estratoos_plugin\user_manager::parse_csv_users($params['csvdata']);

            // Add CSV parse errors as warnings.
            if (!empty($csvresult['errors'])) {
                foreach ($csvresult['errors'] as $csverr) {
                    $warnings[] = [
                        'item' => 'csvdata',
                        'itemid' => $csverr->line ?? 0,
                        'warningcode' => 'csv_parse_error',
                        'message' => $csverr->error ?? 'CSV parse error',
                    ];
                }
            }

            // For CSV users without passwords, apply the global generate_password flag.
            foreach ($csvresult['users'] as $csvuser) {
                if (empty($csvuser['password']) && $params['generate_password']) {
                    $csvuser['generate_password'] = true;
                }
                $allusers[] = $csvuser;
            }
        }

        // Add structured users from the array parameter.
        if (!empty($params['users'])) {
            foreach ($params['users'] as $userdata) {
                // For users without passwords, apply the global generate_password flag.
                if (empty($userdata['password']) && !$userdata['generate_password'] && $params['generate_password']) {
                    $userdata['generate_password'] = true;
                }
                $allusers[] = $userdata;
            }
        }

        // Check we have at least one user.
        if (empty($allusers)) {
            return [
                'batchid' => '',
                'successcount' => 0,
                'failcount' => 0,
                'results' => [],
                'warnings' => [[
                    'item' => 'users',
                    'itemid' => 0,
                    'warningcode' => 'no_users',
                    'message' => 'No users provided. Supply a users array or csvdata.',
                ]],
            ];
        }

        // Apply companyid and serviceid to all users.
        foreach ($allusers as &$userdata) {
            $userdata['companyid'] = $params['companyid'];
            $userdata['serviceid'] = $params['serviceid'];
        }
        unset($userdata);

        // Delegate to user_manager.
        $batchresult = \local_sm_estratoos_plugin\user_manager::create_users_batch(
            $allusers, $params['companyid'], $params['serviceid'], 'batch_api'
        );

        // Format results for return.
        $results = [];
        if (!empty($batchresult->results)) {
            foreach ($batchresult->results as $r) {
                $results[] = [
                    'success' => $r->success,
                    'error_code' => $r->error_code ?? '',
                    'message' => $r->message ?? '',
                    'userid' => (int)($r->userid ?? 0),
                    'username' => $r->username ?? '',
                    'email' => $r->email ?? '',
                    'token' => $r->token ?? '',
                    'password' => $r->password ?? '',
                    'encrypted_password' => $r->encrypted_password ?? '',
                ];
            }
        }

        return [
            'batchid' => $batchresult->batchid ?? '',
            'successcount' => (int)($batchresult->successcount ?? 0),
            'failcount' => (int)($batchresult->failcount ?? 0),
            'results' => $results,
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
            'batchid' => new external_value(PARAM_ALPHANUMEXT, 'Batch operation ID for tracking'),
            'successcount' => new external_value(PARAM_INT, 'Number of users created successfully'),
            'failcount' => new external_value(PARAM_INT, 'Number of users that failed to create'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'success' => new external_value(PARAM_BOOL, 'Whether this user was created successfully'),
                    'error_code' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Error code when success=false: empty_firstname, empty_lastname, empty_email, ' .
                        'invalid_email, email_taken, username_taken, empty_password, password_policy, ' .
                        'company_not_found, user_creation_failed. Empty on success.',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
                    'userid' => new external_value(PARAM_INT, 'Created user ID (0 on failure)', VALUE_DEFAULT, 0),
                    'username' => new external_value(PARAM_TEXT, 'Assigned username', VALUE_DEFAULT, ''),
                    'email' => new external_value(PARAM_TEXT, 'User email address', VALUE_DEFAULT, ''),
                    'token' => new external_value(PARAM_RAW, 'Web service token (if serviceid was provided)', VALUE_DEFAULT, ''),
                    'password' => new external_value(PARAM_RAW, 'Generated password (only if generated)', VALUE_DEFAULT, ''),
                    'encrypted_password' => new external_value(PARAM_RAW, 'Encrypted password for secure transmission', VALUE_DEFAULT, ''),
                ]),
                'Per-user creation results'
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
