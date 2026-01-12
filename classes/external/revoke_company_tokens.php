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
 * External function for revoking tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_company_tokens extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tokenids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Token record ID'),
                'Array of token IDs to revoke'
            ),
        ]);
    }

    /**
     * Revoke tokens.
     *
     * @param array $tokenids Token IDs.
     * @return array Result.
     */
    public static function execute(array $tokenids): array {
        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'tokenids' => $tokenids,
        ]);

        // Check capabilities.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:managetokens', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Revoke tokens.
        $count = \local_sm_estratoos_plugin\company_token_manager::revoke_tokens($params['tokenids']);

        return [
            'success' => true,
            'revokedcount' => $count,
            'message' => get_string('tokensrevoked', 'local_sm_estratoos_plugin', $count),
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'revokedcount' => new external_value(PARAM_INT, 'Number of tokens revoked'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }
}
