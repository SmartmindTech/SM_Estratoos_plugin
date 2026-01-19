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
 * Send a heartbeat to keep a SmartLearning presence session alive.
 *
 * This function updates the timemodified field in mdl_sessions to keep
 * the user appearing "online" in Moodle. Should be called periodically
 * (recommended every 5 minutes).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_heartbeat extends external_api {

    /**
     * Describes the parameters accepted by session_heartbeat.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sid' => new external_value(PARAM_TEXT, 'The session ID (must start with slp_)'),
        ]);
    }

    /**
     * Send a heartbeat to keep the session alive.
     *
     * Updates the session's timemodified and the user's lastaccess.
     *
     * @param string $sid The session ID returned from start_session.
     * @return array Session status and timing information.
     */
    public static function execute(string $sid): array {
        global $USER, $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), ['sid' => $sid]);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Validate session ID format.
        if (strpos($params['sid'], 'slp_') !== 0) {
            throw new \moodle_exception('invalidsession', 'local_sm_estratoos_plugin');
        }

        // Find the session (must belong to current user).
        $session = $DB->get_record('sessions', [
            'sid' => $params['sid'],
            'userid' => $USER->id,
        ]);

        if (!$session) {
            throw new \moodle_exception('invalidsession', 'local_sm_estratoos_plugin');
        }

        $now = time();

        // Update session timemodified and lastip.
        $DB->set_field('sessions', 'timemodified', $now, ['id' => $session->id]);
        $DB->set_field('sessions', 'lastip', getremoteaddr(), ['id' => $session->id]);

        // Also update user's lastaccess.
        $DB->set_field('user', 'lastaccess', $now, ['id' => $USER->id]);

        // Calculate session duration.
        $duration = $now - $session->timecreated;

        return [
            'status' => 'online',
            'timemodified' => $now,
            'session_duration_seconds' => $duration,
            'next_heartbeat_before' => $now + 300,  // 5 minutes.
        ];
    }

    /**
     * Describes the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Session status (online)'),
            'timemodified' => new external_value(PARAM_INT, 'Timestamp when session was last updated'),
            'session_duration_seconds' => new external_value(PARAM_INT, 'Total session duration in seconds'),
            'next_heartbeat_before' => new external_value(PARAM_INT, 'Send next heartbeat before this timestamp'),
        ]);
    }
}
