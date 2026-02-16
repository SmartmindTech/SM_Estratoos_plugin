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
 * External function to toggle plugin access globally (Standard Moodle).
 *
 * Called by SmartLearning when a superadmin enables/disables a Moodle instance.
 * For non-IOMAD Moodles, this toggles the plugin's activated state globally.
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
use local_sm_estratoos_plugin\webhook;

/**
 * Toggle plugin access globally for standard (non-IOMAD) Moodle installations.
 *
 * Enables or disables the plugin's webhook/activation state,
 * effectively suspending or restoring SmartLearning integration.
 */
class toggle_access extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enabled' => new external_value(PARAM_INT, '1 to enable, 0 to disable'),
        ]);
    }

    /**
     * Toggle plugin access globally.
     *
     * @param int $enabled 1 to enable, 0 to disable.
     * @return array Result with success status.
     */
    public static function execute(int $enabled): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'enabled' => $enabled,
        ]);

        // Require site admin or manageaccess capability.
        $context = \context_system::instance();
        self::validate_context($context);
        if (!is_siteadmin() && !has_capability('local/sm_estratoos_plugin:manageaccess', $context)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'toggle plugin access');
        }

        $newstate = $params['enabled'] ? '1' : '0';
        set_config(webhook::CONFIG_ACTIVATED, $newstate, 'local_sm_estratoos_plugin');
        webhook::clear_cache();

        // Log the event.
        $eventtype = $params['enabled'] ? 'system.access_enabled' : 'system.access_disabled';
        webhook::log_event($eventtype, 'system', [
            'enabled' => $params['enabled'],
            'source' => 'smartlearning_api',
        ]);

        $action = $params['enabled'] ? 'enabled' : 'disabled';

        return [
            'success' => true,
            'message' => "Plugin access {$action} successfully.",
            'activated' => (bool) $params['enabled'],
        ];
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'activated' => new external_value(PARAM_BOOL, 'Current activation state'),
        ]);
    }
}
