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
 * External function to create a single user account.
 *
 * Requires site admin or IOMAD company manager privileges.
 * Delegates all business logic to user_manager::create_user().
 *
 * Returns structured error codes instead of throwing exceptions for
 * expected error cases, so the frontend can display specific messages.
 *
 * Error codes (returned by user_manager):
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
use external_value;
use external_warnings;

/**
 * API to create a single user account.
 */
class create_user extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
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
                'User email address (validated internally to return error_code instead of exception)'
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
                'Generate a random password for the user',
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
            'document_type' => new external_value(
                PARAM_ALPHANUMEXT,
                'Document type: dni, nie, or passport (required for Spain compliance)',
                VALUE_DEFAULT,
                ''
            ),
            'document_id' => new external_value(
                PARAM_ALPHANUMEXT,
                'Document ID number (validated per document_type)',
                VALUE_DEFAULT,
                ''
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID (0 for standard Moodle)',
                VALUE_DEFAULT,
                0
            ),
            'serviceid' => new external_value(
                PARAM_INT,
                'External service ID for token creation (0 to skip token)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Create a single user account.
     *
     * @param string $firstname User first name.
     * @param string $lastname User last name.
     * @param string $email User email address.
     * @param string $username Username (auto-generated if empty).
     * @param string $password Plain-text password.
     * @param bool $generate_password Generate random password.
     * @param string $phone_intl_code International phone code.
     * @param string $phone Phone number.
     * @param string $birthdate Date of birth.
     * @param string $city City.
     * @param string $state_province State or province.
     * @param string $country Country code.
     * @param string $timezone Timezone.
     * @param string $document_type Document type (dni, nie, passport).
     * @param string $document_id Document ID number.
     * @param int $companyid IOMAD company ID.
     * @param int $serviceid External service ID for token creation.
     * @return array Result with success flag and user details or error_code.
     */
    public static function execute(
        string $firstname,
        string $lastname,
        string $email,
        string $username = '',
        string $password = '',
        bool $generate_password = false,
        string $phone_intl_code = '',
        string $phone = '',
        string $birthdate = '',
        string $city = '',
        string $state_province = '',
        string $country = '',
        string $timezone = '',
        string $document_type = '',
        string $document_id = '',
        int $companyid = 0,
        int $serviceid = 0
    ): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'generate_password' => $generate_password,
            'phone_intl_code' => $phone_intl_code,
            'phone' => $phone,
            'birthdate' => $birthdate,
            'city' => $city,
            'state_province' => $state_province,
            'country' => $country,
            'timezone' => $timezone,
            'document_type' => $document_type,
            'document_id' => $document_id,
            'companyid' => $companyid,
            'serviceid' => $serviceid,
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

        // Build userdata array for user_manager.
        $userdata = [
            'firstname' => $params['firstname'],
            'lastname' => $params['lastname'],
            'email' => $params['email'],
            'username' => $params['username'],
            'password' => $params['password'],
            'generate_password' => $params['generate_password'],
            'phone_intl_code' => $params['phone_intl_code'],
            'phone' => $params['phone'],
            'birthdate' => $params['birthdate'],
            'city' => $params['city'],
            'state_province' => $params['state_province'],
            'country' => $params['country'],
            'timezone' => $params['timezone'],
            'document_type' => $params['document_type'],
            'document_id' => $params['document_id'],
            'companyid' => $params['companyid'],
            'serviceid' => $params['serviceid'],
        ];

        // Delegate to user_manager.
        $result = \local_sm_estratoos_plugin\user_manager::create_user($userdata, 'api');

        return [
            'success' => $result->success,
            'error_code' => $result->error_code ?? '',
            'message' => $result->message ?? '',
            'userid' => (int)($result->userid ?? 0),
            'username' => $result->username ?? '',
            'token' => $result->token ?? '',
            'password' => $result->password ?? '',
            'encrypted_password' => $result->encrypted_password ?? '',
            'moodle_url' => $result->moodle_url ?? '',
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
            'success' => new external_value(PARAM_BOOL, 'Whether the user was created successfully'),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when success=false: empty_firstname, empty_lastname, empty_email, invalid_email, ' .
                'email_taken, username_taken, empty_password, password_policy, company_not_found, ' .
                'user_creation_failed. Empty on success.',
                VALUE_DEFAULT,
                ''
            ),
            'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
            'userid' => new external_value(PARAM_INT, 'Created user ID (0 on failure)', VALUE_DEFAULT, 0),
            'username' => new external_value(PARAM_TEXT, 'Assigned username', VALUE_DEFAULT, ''),
            'token' => new external_value(PARAM_RAW, 'Web service token (if serviceid was provided)', VALUE_DEFAULT, ''),
            'password' => new external_value(PARAM_RAW, 'Generated password (only if generate_password was true)', VALUE_DEFAULT, ''),
            'encrypted_password' => new external_value(PARAM_RAW, 'Encrypted password for secure transmission', VALUE_DEFAULT, ''),
            'moodle_url' => new external_value(PARAM_URL, 'Moodle site URL', VALUE_DEFAULT, ''),
            'warnings' => new external_warnings(),
        ]);
    }
}
