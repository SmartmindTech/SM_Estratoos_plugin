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
 * External function to update the authenticated user's own username.
 *
 * Self-service only: the user identified by the API token can change
 * their own username. No admin capability required.
 *
 * Returns structured error codes instead of throwing exceptions for
 * expected error cases, so the frontend can display specific messages.
 *
 * Error codes:
 * - empty_username: Username is empty after cleaning
 * - username_taken: Username is already used by another user
 * - auth_not_supported: Auth plugin doesn't support username changes
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
 * API to update the authenticated user's own username.
 */
class update_username extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'username' => new external_value(
                PARAM_USERNAME,
                'The new username for the authenticated user'
            ),
        ]);
    }

    /**
     * Update the authenticated user's username.
     *
     * @param string $username The new username.
     * @return array Result with success flag and error_code for specific failures.
     */
    public static function execute(string $username): array {
        global $DB, $USER, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'username' => $username,
        ]);

        $warnings = [];

        // Basic validation — these are unexpected errors, so throw exceptions.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // No context validation needed — self-service only (token owner modifies own data).
        // Skipping validate_context() to support IOMAD company-scoped tokens (category context).

        $newusername = trim(\core_text::strtolower($params['username']));

        // Get current user record.
        $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

        // Check user is not deleted or suspended.
        if ($user->deleted) {
            throw new \moodle_exception('userdeleted');
        }
        if ($user->suspended) {
            throw new \moodle_exception('suspended', 'auth');
        }

        $previoususername = $user->username;

        // Validate new username is not empty after cleaning.
        if (empty($newusername)) {
            return [
                'success' => false,
                'error_code' => 'empty_username',
                'username' => $previoususername,
                'previoususername' => $previoususername,
                'message' => 'Username cannot be empty.',
                'warnings' => $warnings,
            ];
        }

        // Check if username is actually changing.
        if ($newusername === $previoususername) {
            return [
                'success' => true,
                'error_code' => '',
                'username' => $newusername,
                'previoususername' => $previoususername,
                'message' => 'Username is already set to this value.',
                'warnings' => $warnings,
            ];
        }

        // Check auth plugin allows username changes.
        $authplugin = get_auth_plugin($user->auth);
        if (!$authplugin->is_internal()) {
            return [
                'success' => false,
                'error_code' => 'auth_not_supported',
                'username' => $previoususername,
                'previoususername' => $previoususername,
                'message' => 'Your authentication method (' . $user->auth . ') does not allow username changes.',
                'warnings' => $warnings,
            ];
        }

        // Check username is not already taken by another user.
        $existing = $DB->get_record('user', ['username' => $newusername, 'mnethostid' => $user->mnethostid]);
        if ($existing && $existing->id != $user->id) {
            return [
                'success' => false,
                'error_code' => 'username_taken',
                'username' => $previoususername,
                'previoususername' => $previoususername,
                'message' => 'Username "' . $newusername . '" is already taken.',
                'warnings' => $warnings,
            ];
        }

        // Update the username.
        $user->username = $newusername;
        $user->timemodified = time();
        $DB->update_record('user', $user);

        // Fire user_updated event.
        \core\event\user_updated::create_from_userid($user->id)->trigger();

        return [
            'success' => true,
            'error_code' => '',
            'username' => $newusername,
            'previoususername' => $previoususername,
            'message' => 'Username updated successfully.',
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
            'success' => new external_value(PARAM_BOOL, 'Whether the update was successful'),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when success=false: empty_username, username_taken, auth_not_supported. Empty on success.',
                VALUE_DEFAULT,
                ''
            ),
            'username' => new external_value(PARAM_USERNAME, 'The current username (new on success, unchanged on failure)'),
            'previoususername' => new external_value(PARAM_USERNAME, 'The previous username'),
            'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
            'warnings' => new external_warnings(),
        ]);
    }
}
