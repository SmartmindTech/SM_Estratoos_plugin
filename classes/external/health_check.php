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
 * Health check external API for SmartLearning platform.
 *
 * Ultra-lightweight endpoint designed for high-frequency polling (every 10-30 seconds)
 * to detect Moodle connectivity issues instantly.
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

/**
 * Health check API class.
 *
 * Provides a minimal-latency endpoint for connectivity verification.
 * Returns clear status codes for different failure scenarios.
 *
 * Status codes:
 * - "ok" = Moodle is healthy, token is valid
 * - "error" = Problem detected (see error_code for details)
 *
 * Error codes:
 * - "invalid_token" = Token expired or revoked, user must reconnect Moodle
 * - "maintenance" = Moodle is in maintenance mode
 * - "user_suspended" = User account is suspended
 * - "internal_error" = Unexpected server error
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_check extends external_api {

    /**
     * Describes the parameters accepted by the health check function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'client_ts' => new external_value(
                PARAM_INT,
                'Client timestamp in milliseconds (optional). If provided, server calculates round-trip latency.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Execute the health check.
     *
     * This function is intentionally minimal to ensure fastest possible response.
     * It validates token authenticity and returns basic status information.
     *
     * @param int $client_ts Client timestamp in milliseconds for latency calculation
     * @return array Health check response
     */
    public static function execute(int $client_ts = 0): array {
        global $USER, $CFG;

        // Calculate server timestamp immediately for accurate latency measurement.
        $server_ts = (int) (microtime(true) * 1000);

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'client_ts' => $client_ts,
        ]);

        // Build base response structure.
        $response = [
            'status' => 'ok',
            'error_code' => '',
            'error_message' => '',
            'server_ts' => $server_ts,
            'user_id' => 0,
            'username' => '',
            'moodle_version' => '',
            'plugin_version' => '',
            'latency_ms' => 0,
        ];

        // Check 1: Maintenance mode.
        if (!empty($CFG->maintenance_enabled)) {
            $response['status'] = 'error';
            $response['error_code'] = 'maintenance';
            $response['error_message'] = get_string('healthcheck_maintenance', 'local_sm_estratoos_plugin');
            return $response;
        }

        // Check 2: User validation (token already validated by Moodle at this point).
        // If we reach here, token is valid, but let's verify user status.
        if (empty($USER->id) || isguestuser($USER)) {
            $response['status'] = 'error';
            $response['error_code'] = 'invalid_token';
            $response['error_message'] = get_string('healthcheck_invalid_token', 'local_sm_estratoos_plugin');
            return $response;
        }

        // Check 3: User suspended.
        if (!empty($USER->suspended)) {
            $response['status'] = 'error';
            $response['error_code'] = 'user_suspended';
            $response['error_message'] = get_string('healthcheck_user_suspended', 'local_sm_estratoos_plugin');
            return $response;
        }

        // All checks passed - populate success response.
        $response['user_id'] = (int) $USER->id;
        $response['username'] = $USER->username;
        $response['moodle_version'] = $CFG->release ?? $CFG->version;

        // Get plugin version.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $response['plugin_version'] = $plugin ? $plugin->release : 'unknown';

        // Calculate latency if client timestamp was provided.
        if ($params['client_ts'] > 0) {
            $response['latency_ms'] = $server_ts - $params['client_ts'];
        }

        return $response;
    }

    /**
     * Describes the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                PARAM_ALPHA,
                'Health status: "ok" if healthy, "error" if problem detected'
            ),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when status is "error": "invalid_token", "maintenance", "user_suspended", "internal_error". Empty string if status is "ok".',
                VALUE_OPTIONAL
            ),
            'error_message' => new external_value(
                PARAM_TEXT,
                'Human-readable error message in user\'s language. Empty string if status is "ok".',
                VALUE_OPTIONAL
            ),
            'server_ts' => new external_value(
                PARAM_INT,
                'Server timestamp in milliseconds (Unix epoch). Use for latency calculation.'
            ),
            'user_id' => new external_value(
                PARAM_INT,
                'Moodle user ID. 0 if error.'
            ),
            'username' => new external_value(
                PARAM_TEXT,
                'Moodle username. Empty string if error.',
                VALUE_OPTIONAL
            ),
            'moodle_version' => new external_value(
                PARAM_TEXT,
                'Moodle version string. Empty string if error.',
                VALUE_OPTIONAL
            ),
            'plugin_version' => new external_value(
                PARAM_TEXT,
                'SM Estratoos Plugin version. Empty string if error.',
                VALUE_OPTIONAL
            ),
            'latency_ms' => new external_value(
                PARAM_INT,
                'Round-trip latency in milliseconds. Only populated if client_ts was provided. 0 otherwise.',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
