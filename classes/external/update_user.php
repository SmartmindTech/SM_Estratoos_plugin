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
 * External function to update the authenticated user's own profile fields.
 *
 * Self-service only: the user identified by the API token can update
 * their own profile. No admin capability required.
 *
 * Mirrors core_user_update_users but restricted to:
 * - Only the token owner's profile (no userid parameter)
 * - Only safe profile fields (no auth, suspended, password, username)
 * - Username and password have their own dedicated functions
 *
 * Returns structured error codes instead of throwing exceptions for
 * expected error cases, so the frontend can display specific messages.
 *
 * Error codes:
 * - empty_firstname: First name is empty
 * - empty_lastname: Last name is empty
 * - empty_email: Email is empty
 * - invalid_email: Email format is invalid
 * - email_taken: Email is already used by another user
 * - invalid_country: Country code is not valid
 * - invalid_lang: Language code is not installed
 * - invalid_calendartype: Calendar type is not available
 * - invalid_maildisplay: Mail display value is not 0, 1, or 2
 * - invalid_mailformat: Mail format value is not 0 or 1
 * - custom_field_error: Error saving a custom profile field
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use external_warnings;

/**
 * API to update the authenticated user's own profile.
 */
class update_user extends external_api {

    /** Sentinel value meaning "field was not provided by the caller". */
    const NOT_PROVIDED = '___SM_NOT_PROVIDED___';

    /**
     * Define input parameters.
     *
     * All string fields use VALUE_DEFAULT with a sentinel value so Moodle's
     * external API always includes them in the validated params array
     * (prevents positional argument shifting with VALUE_OPTIONAL).
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $np = self::NOT_PROVIDED;

        return new external_function_parameters([
            // Personal information.
            'firstname' => new external_value(
                PARAM_NOTAGS, 'First name', VALUE_DEFAULT, $np
            ),
            'lastname' => new external_value(
                PARAM_NOTAGS, 'Last name', VALUE_DEFAULT, $np
            ),
            'email' => new external_value(
                PARAM_RAW, 'Email address (must be unique)', VALUE_DEFAULT, $np
            ),
            'description' => new external_value(
                PARAM_RAW, 'User profile description (no HTML)', VALUE_DEFAULT, $np
            ),

            // Additional names.
            'firstnamephonetic' => new external_value(
                PARAM_NOTAGS, 'First name phonetically', VALUE_DEFAULT, $np
            ),
            'lastnamephonetic' => new external_value(
                PARAM_NOTAGS, 'Last name phonetically', VALUE_DEFAULT, $np
            ),
            'middlename' => new external_value(
                PARAM_NOTAGS, 'Middle name', VALUE_DEFAULT, $np
            ),
            'alternatename' => new external_value(
                PARAM_NOTAGS, 'Alternate name', VALUE_DEFAULT, $np
            ),

            // Location.
            'city' => new external_value(
                PARAM_NOTAGS, 'City of the user', VALUE_DEFAULT, $np
            ),
            'country' => new external_value(
                PARAM_RAW, 'Country code (e.g., BR, US, ES)', VALUE_DEFAULT, $np
            ),
            'timezone' => new external_value(
                PARAM_RAW, 'Timezone (e.g., America/Sao_Paulo, or 99 for server default)', VALUE_DEFAULT, $np
            ),

            // Institutional.
            'institution' => new external_value(
                PARAM_TEXT, 'Institution', VALUE_DEFAULT, $np
            ),
            'department' => new external_value(
                PARAM_TEXT, 'Department', VALUE_DEFAULT, $np
            ),
            'phone1' => new external_value(
                PARAM_NOTAGS, 'Phone number', VALUE_DEFAULT, $np
            ),
            'phone2' => new external_value(
                PARAM_NOTAGS, 'Mobile phone number', VALUE_DEFAULT, $np
            ),
            'address' => new external_value(
                PARAM_TEXT, 'Postal address', VALUE_DEFAULT, $np
            ),
            'idnumber' => new external_value(
                PARAM_RAW, 'ID number (institutional)', VALUE_DEFAULT, $np
            ),

            // Display preferences.
            'lang' => new external_value(
                PARAM_RAW, 'Language code (e.g., en, es, pt_br)', VALUE_DEFAULT, $np
            ),
            'calendartype' => new external_value(
                PARAM_RAW, 'Calendar type (e.g., gregorian)', VALUE_DEFAULT, $np
            ),
            'maildisplay' => new external_value(
                PARAM_INT, 'Email display: 0=hidden, 1=visible to participants, 2=visible to all', VALUE_DEFAULT, -1
            ),
            'mailformat' => new external_value(
                PARAM_INT, 'Mail format: 0=plain text, 1=HTML', VALUE_DEFAULT, -1
            ),

            // Custom profile fields.
            'customfields' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'The shortname of the custom profile field'),
                    'value' => new external_value(PARAM_RAW, 'The value of the custom profile field'),
                ]),
                'Custom profile fields to update',
                VALUE_DEFAULT,
                []
            ),

            // User preferences.
            'preferences' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_RAW, 'The name of the preference'),
                    'value' => new external_value(PARAM_RAW, 'The value of the preference'),
                ]),
                'User preferences to update',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @param string $description
     * @param string $firstnamephonetic
     * @param string $lastnamephonetic
     * @param string $middlename
     * @param string $alternatename
     * @param string $city
     * @param string $country
     * @param string $timezone
     * @param string $institution
     * @param string $department
     * @param string $phone1
     * @param string $phone2
     * @param string $address
     * @param string $idnumber
     * @param string $lang
     * @param string $calendartype
     * @param int $maildisplay
     * @param int $mailformat
     * @param array $customfields
     * @param array $preferences
     * @return array Result with success flag and error details.
     */
    public static function execute(
        $firstname = null,
        $lastname = null,
        $email = null,
        $description = null,
        $firstnamephonetic = null,
        $lastnamephonetic = null,
        $middlename = null,
        $alternatename = null,
        $city = null,
        $country = null,
        $timezone = null,
        $institution = null,
        $department = null,
        $phone1 = null,
        $phone2 = null,
        $address = null,
        $idnumber = null,
        $lang = null,
        $calendartype = null,
        $maildisplay = -1,
        $mailformat = -1,
        $customfields = [],
        $preferences = []
    ): array {
        global $DB, $USER, $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $np = self::NOT_PROVIDED;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'description' => $description,
            'firstnamephonetic' => $firstnamephonetic,
            'lastnamephonetic' => $lastnamephonetic,
            'middlename' => $middlename,
            'alternatename' => $alternatename,
            'city' => $city,
            'country' => $country,
            'timezone' => $timezone,
            'institution' => $institution,
            'department' => $department,
            'phone1' => $phone1,
            'phone2' => $phone2,
            'address' => $address,
            'idnumber' => $idnumber,
            'lang' => $lang,
            'calendartype' => $calendartype,
            'maildisplay' => $maildisplay,
            'mailformat' => $mailformat,
            'customfields' => $customfields,
            'preferences' => $preferences,
        ]);

        $warnings = [];

        // Basic validation — unexpected errors throw exceptions.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // No context validation needed — self-service only (token owner modifies own data).
        // Skipping validate_context() to support IOMAD company-scoped tokens (category context).

        // Get current user record.
        $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

        if ($user->deleted) {
            throw new \moodle_exception('userdeleted');
        }
        if ($user->suspended) {
            throw new \moodle_exception('suspended', 'auth');
        }

        // String fields that can be updated — only include if caller provided a value.
        $stringfields = [
            'firstname', 'lastname', 'email', 'description',
            'firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename',
            'city', 'country', 'timezone',
            'institution', 'department', 'phone1', 'phone2', 'address', 'idnumber',
            'lang', 'calendartype',
        ];

        // Build the user update object — only fields that were actually provided.
        $userupdate = new \stdClass();
        $userupdate->id = $user->id;
        $haschanges = false;

        foreach ($stringfields as $field) {
            if ($params[$field] !== $np) {
                $userupdate->$field = $params[$field];
                $haschanges = true;
            }
        }

        // Integer fields — sentinel is -1.
        if ($params['maildisplay'] >= 0) {
            $userupdate->maildisplay = $params['maildisplay'];
            $haschanges = true;
        }
        if ($params['mailformat'] >= 0) {
            $userupdate->mailformat = $params['mailformat'];
            $haschanges = true;
        }

        // --- Validate specific fields before updating ---

        // Firstname cannot be empty.
        if (isset($userupdate->firstname) && trim($userupdate->firstname) === '') {
            return self::error_response('empty_firstname', 'First name cannot be empty.');
        }

        // Lastname cannot be empty.
        if (isset($userupdate->lastname) && trim($userupdate->lastname) === '') {
            return self::error_response('empty_lastname', 'Last name cannot be empty.');
        }

        // Email validation.
        if (isset($userupdate->email)) {
            $newemail = trim($userupdate->email);
            if (empty($newemail)) {
                return self::error_response('empty_email', 'Email cannot be empty.');
            }
            if (!validate_email($newemail)) {
                return self::error_response('invalid_email', 'Email format is invalid.');
            }
            // Check uniqueness (unless allowaccountssameemail is enabled).
            if (empty($CFG->allowaccountssameemail)) {
                $existing = $DB->get_record('user', ['email' => $newemail, 'mnethostid' => $user->mnethostid]);
                if ($existing && $existing->id != $user->id) {
                    return self::error_response('email_taken', 'Email "' . $newemail . '" is already used by another user.');
                }
            }
        }

        // Country code validation.
        if (isset($userupdate->country) && $userupdate->country !== '') {
            $countries = get_string_manager()->get_list_of_countries();
            if (!isset($countries[$userupdate->country])) {
                return self::error_response('invalid_country', 'Country code "' . $userupdate->country . '" is not valid.');
            }
        }

        // Language validation.
        if (isset($userupdate->lang)) {
            $langs = get_string_manager()->get_list_of_translations();
            if (!isset($langs[$userupdate->lang])) {
                return self::error_response('invalid_lang', 'Language "' . $userupdate->lang . '" is not installed on this site.');
            }
        }

        // Calendar type validation.
        if (isset($userupdate->calendartype)) {
            $calendartypes = \core_calendar\type_factory::get_list_of_calendar_types();
            if (!isset($calendartypes[$userupdate->calendartype])) {
                return self::error_response('invalid_calendartype',
                    'Calendar type "' . $userupdate->calendartype . '" is not available.');
            }
        }

        // Maildisplay validation (0, 1, or 2).
        if (isset($userupdate->maildisplay) && !in_array($userupdate->maildisplay, [0, 1, 2])) {
            return self::error_response('invalid_maildisplay', 'Mail display must be 0 (hidden), 1 (participants), or 2 (everyone).');
        }

        // Mailformat validation (0 or 1).
        if (isset($userupdate->mailformat) && !in_array($userupdate->mailformat, [0, 1])) {
            return self::error_response('invalid_mailformat', 'Mail format must be 0 (plain text) or 1 (HTML).');
        }

        // --- Perform the update ---
        if ($haschanges) {
            $userupdate->timemodified = time();
            user_update_user($userupdate, false, false);
        }

        // Handle custom profile fields.
        if (!empty($params['customfields'])) {
            $haschanges = true;
            foreach ($params['customfields'] as $customfield) {
                $fieldname = 'profile_field_' . $customfield['type'];
                $userupdate->$fieldname = $customfield['value'];
            }
            try {
                profile_save_data($userupdate);
            } catch (\Throwable $e) {
                return self::error_response('custom_field_error',
                    'Error saving custom profile field: ' . $e->getMessage());
            }
        }

        // Handle user preferences.
        if (!empty($params['preferences'])) {
            $haschanges = true;
            foreach ($params['preferences'] as $pref) {
                set_user_preference($pref['type'], $pref['value'], $user->id);
            }
        }

        if (!$haschanges) {
            return [
                'success' => true,
                'error_code' => '',
                'message' => 'No fields to update.',
                'warnings' => $warnings,
            ];
        }

        // Fire user_updated event.
        \core\event\user_updated::create_from_userid($user->id)->trigger();

        return [
            'success' => true,
            'error_code' => '',
            'message' => 'User profile updated successfully.',
            'warnings' => $warnings,
        ];
    }

    /**
     * Build a standardized error response.
     *
     * @param string $code Error code.
     * @param string $message Human-readable message.
     * @return array
     */
    private static function error_response(string $code, string $message): array {
        return [
            'success' => false,
            'error_code' => $code,
            'message' => $message,
            'warnings' => [],
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the update was successful'),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when success=false: empty_firstname, empty_lastname, empty_email, invalid_email, ' .
                'email_taken, invalid_country, invalid_lang, invalid_calendartype, invalid_maildisplay, ' .
                'invalid_mailformat, custom_field_error. Empty on success.',
                VALUE_DEFAULT,
                ''
            ),
            'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
            'warnings' => new external_warnings(),
        ]);
    }
}
