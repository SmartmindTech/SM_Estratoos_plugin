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

/**
 * External function for creating admin (system-wide) token.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_admin_token extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'serviceid' => new external_value(PARAM_INT, 'External service ID'),
            'iprestriction' => new external_value(PARAM_TEXT, 'IP restriction (optional)', VALUE_DEFAULT, ''),
            'validuntil' => new external_value(PARAM_INT, 'Valid until timestamp (optional, 0 = never)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Create admin token.
     *
     * @param int $serviceid Service ID.
     * @param string $iprestriction IP restriction.
     * @param int $validuntil Valid until timestamp.
     * @return array Result.
     */
    public static function execute(int $serviceid, string $iprestriction = '', int $validuntil = 0): array {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'serviceid' => $serviceid,
            'iprestriction' => $iprestriction,
            'validuntil' => $validuntil,
        ]);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:managetokens', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Prepare options.
        $options = [];
        if (!empty($params['iprestriction'])) {
            // Validate IP format.
            if (!\local_sm_estratoos_plugin\util::validate_ip_format($params['iprestriction'])) {
                throw new \moodle_exception('invalidiprestriction', 'local_sm_estratoos_plugin');
            }
            $options['iprestriction'] = $params['iprestriction'];
        }
        if ($params['validuntil'] > 0) {
            $options['validuntil'] = $params['validuntil'];
        }

        // Create the admin token.
        $token = \local_sm_estratoos_plugin\company_token_manager::create_admin_token(
            $USER->id,
            $params['serviceid'],
            $options
        );

        return [
            'success' => true,
            'token' => $token,
            'userid' => $USER->id,
            'message' => get_string('admintokencreated', 'local_sm_estratoos_plugin'),
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
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Generated token string'),
            'userid' => new external_value(PARAM_INT, 'User ID the token belongs to'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }
}
