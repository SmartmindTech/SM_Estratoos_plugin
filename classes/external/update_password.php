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
 * External function to update the authenticated user's own password.
 *
 * Self-service only: requires the current password for verification.
 * Uses Moodle core password functions for hashing and policy enforcement.
 *
 * Returns structured error codes instead of throwing exceptions for
 * expected error cases, so the frontend can display specific messages.
 *
 * Error codes:
 * - wrong_current_password: The current password provided is incorrect
 * - password_policy: New password doesn't meet Moodle password policy
 * - empty_password: New password is empty
 * - auth_not_supported: Auth plugin doesn't support password changes
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
 * API to update the authenticated user's own password.
 */
class update_password extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'currentpassword' => new external_value(
                PARAM_RAW,
                'The user\'s current password for verification'
            ),
            'newpassword' => new external_value(
                PARAM_RAW,
                'The new password'
            ),
        ]);
    }

    /**
     * Update the authenticated user's password.
     *
     * @param string $currentpassword Current password for verification.
     * @param string $newpassword New password.
     * @return array Result with success flag and error_code for specific failures.
     */
    public static function execute(string $currentpassword, string $newpassword): array {
        global $DB, $USER, $CFG;

        require_once($CFG->libdir . '/authlib.php');

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'currentpassword' => $currentpassword,
            'newpassword' => $newpassword,
        ]);

        $warnings = [];

        // Basic validation — these are unexpected errors, so throw exceptions.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // No context validation needed — self-service only (token owner modifies own data).
        // Skipping validate_context() to support IOMAD company-scoped tokens (category context).

        // Get current user record with full data (including password hash).
        $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

        // Check user is not deleted.
        if ($user->deleted) {
            throw new \moodle_exception('userdeleted');
        }

        // Check auth plugin supports password changes.
        $authplugin = get_auth_plugin($user->auth);
        if (!$authplugin->is_internal() || !$authplugin->can_change_password()) {
            return [
                'success' => false,
                'error_code' => 'auth_not_supported',
                'message' => 'Your authentication method (' . $user->auth . ') does not allow password changes via this API.',
                'warnings' => $warnings,
            ];
        }

        // Verify current password.
        if (!validate_internal_user_password($user, $params['currentpassword'])) {
            return [
                'success' => false,
                'error_code' => 'wrong_current_password',
                'message' => 'Current password is incorrect.',
                'warnings' => $warnings,
            ];
        }

        // Check new password is not empty.
        if (empty($params['newpassword'])) {
            return [
                'success' => false,
                'error_code' => 'empty_password',
                'message' => 'New password cannot be empty.',
                'warnings' => $warnings,
            ];
        }

        // Check new password meets Moodle password policy.
        $errmsg = '';
        if (!check_password_policy($params['newpassword'], $errmsg)) {
            // Strip HTML tags from Moodle's policy error (may contain <ul>, <li>, etc.).
            $cleanmsg = strip_tags($errmsg);
            $cleanmsg = trim(preg_replace('/\s+/', ' ', $cleanmsg));
            return [
                'success' => false,
                'error_code' => 'password_policy',
                'message' => $cleanmsg ?: 'Password does not meet the policy requirements.',
                'warnings' => $warnings,
            ];
        }

        // Update the password using Moodle core function.
        // This handles hashing, event firing, and session management.
        update_internal_user_password($user, $params['newpassword']);

        return [
            'success' => true,
            'error_code' => '',
            'message' => 'Password updated successfully.',
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
            'success' => new external_value(PARAM_BOOL, 'Whether the password update was successful'),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when success=false: wrong_current_password, password_policy, empty_password, auth_not_supported. Empty on success.',
                VALUE_DEFAULT,
                ''
            ),
            'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
            'warnings' => new external_warnings(),
        ]);
    }
}
