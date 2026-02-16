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
 * External function to delete one or more user accounts.
 *
 * Requires site admin or IOMAD company manager privileges.
 * Delegates all business logic to user_manager::delete_user() / delete_users_batch().
 *
 * Performs full cleanup: revokes tokens, removes plugin metadata,
 * soft-deletes the Moodle user, and logs webhook events.
 *
 * Error codes (returned per user):
 * - user_not_found: User does not exist or is already deleted
 * - user_not_in_company: User does not belong to the specified company
 * - delete_failed: Moodle user deletion failed
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
 * API to delete one or more user accounts.
 */
class delete_users extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID to delete'),
                'List of user IDs to delete'
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID for company-scoped deletion (0 for any)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Delete one or more users.
     *
     * @param array $userids List of user IDs.
     * @param int $companyid IOMAD company ID (0 for any).
     * @return array Result with per-user success/failure details.
     */
    public static function execute(array $userids, int $companyid = 0): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userids' => $userids,
            'companyid' => $companyid,
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

        // Delegate to user_manager.
        $result = \local_sm_estratoos_plugin\user_manager::delete_users_batch(
            $params['userids'],
            $params['companyid']
        );

        // Build per-user results array.
        $results = [];
        foreach ($result->results as $r) {
            $results[] = [
                'userid' => (int) $r->userid,
                'success' => (bool) $r->success,
                'error_code' => $r->error_code ?? '',
                'message' => $r->message ?? '',
            ];
        }

        return [
            'success' => (bool) $result->success,
            'total' => (int) $result->total,
            'deleted' => (int) $result->deleted,
            'failed' => (int) $result->failed,
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
            'success' => new external_value(PARAM_BOOL, 'Whether all deletions succeeded'),
            'total' => new external_value(PARAM_INT, 'Total users requested for deletion'),
            'deleted' => new external_value(PARAM_INT, 'Number of users successfully deleted'),
            'failed' => new external_value(PARAM_INT, 'Number of users that failed to delete'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'success' => new external_value(PARAM_BOOL, 'Whether this user was deleted successfully'),
                    'error_code' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Error code: user_not_found, user_not_in_company, delete_failed. Empty on success.',
                        VALUE_DEFAULT,
                        ''
                    ),
                    'message' => new external_value(PARAM_TEXT, 'Result message or error details', VALUE_DEFAULT, ''),
                ]),
                'Per-user deletion results'
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
