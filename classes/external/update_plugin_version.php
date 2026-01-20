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
 * External function to update plugin version (legacy).
 *
 * @deprecated since v1.7.45 - Use get_plugin_status instead.
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_warnings;
use local_sm_estratoos_plugin\util;

/**
 * Legacy function - redirects to get_plugin_status.
 *
 * @deprecated since v1.7.45
 */
class update_plugin_version extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'checkforupdates' => new external_value(PARAM_BOOL, 'Force check for updates (default: false)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Get the plugin version status (legacy wrapper).
     *
     * @param bool $checkforupdates Whether to force check for updates.
     * @return array Plugin status information.
     */
    public static function execute(bool $checkforupdates = false): array {
        // Delegate to the new function.
        return get_plugin_status::execute($checkforupdates);
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_plugin_status::execute_returns();
    }
}
