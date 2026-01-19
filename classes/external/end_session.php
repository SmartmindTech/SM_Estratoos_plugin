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
 * End a SmartLearning presence session in Moodle.
 *
 * This function deletes the session record from mdl_sessions, making the
 * user appear "offline" in Moodle reports and user online blocks.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class end_session extends external_api {

    /**
     * Describes the parameters accepted by end_session.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sid' => new external_value(PARAM_TEXT, 'The session ID (must start with slp_)'),
        ]);
    }

    /**
     * End a presence session for the current user.
     *
     * Deletes the session record from mdl_sessions, making the user appear offline.
     *
     * @param string $sid The session ID returned from start_session.
     * @return array Session summary including total duration.
     */
    public static function execute(string $sid): array {
        global $USER, $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), ['sid' => $sid]);

        // Validate context based on token type.
        $companyid = \local_sm_estratoos_plugin\util::get_company_id_from_token();
        if ($companyid && \local_sm_estratoos_plugin\util::is_iomad_installed()) {
            // IOMAD: Use company's category context.
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);
            $context = \context_coursecat::instance($company->category);
        } else if (is_siteadmin()) {
            // Site admin: Use system context.
            $context = \context_system::instance();
        } else {
            // Non-IOMAD normal user: Use top-level category context.
            $topcategory = $DB->get_record('course_categories', ['parent' => 0], 'id', IGNORE_MULTIPLE);
            if ($topcategory) {
                $context = \context_coursecat::instance($topcategory->id);
            } else {
                $context = \context_system::instance();
            }
        }
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

        $timeended = time();
        $duration = $timeended - $session->timecreated;

        // Delete the session (user goes "offline").
        $DB->delete_records('sessions', ['id' => $session->id]);

        // Get user record.
        $user = $DB->get_record('user', ['id' => $USER->id], 'id, username, firstname, lastname, email');

        // Get company info if IOMAD is installed.
        $companyinfo = self::get_company_info($USER->id);

        return [
            'userid' => (int) $USER->id,
            'username' => $user->username,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'companyid' => $companyinfo['id'],
            'companyname' => $companyinfo['name'],
            'timecreated' => (int) $session->timecreated,
            'timeended' => $timeended,
            'total_duration_seconds' => $duration,
            'total_duration_formatted' => self::format_duration($duration),
            'status' => 'offline',
        ];
    }

    /**
     * Get company information for a user.
     *
     * @param int $userid The user ID.
     * @return array Array with 'id' and 'name' keys.
     */
    private static function get_company_info(int $userid): array {
        global $DB;

        $result = ['id' => 0, 'name' => ''];

        if (!\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            return $result;
        }

        // Get the user's primary company.
        $sql = "SELECT c.id, c.name
                FROM {company} c
                JOIN {company_users} cu ON cu.companyid = c.id
                WHERE cu.userid = ?
                ORDER BY cu.id ASC
                LIMIT 1";

        $company = $DB->get_record_sql($sql, [$userid]);

        if ($company) {
            $result['id'] = (int) $company->id;
            $result['name'] = $company->name;
        }

        return $result;
    }

    /**
     * Format a duration in seconds as a human-readable string.
     *
     * @param int $seconds Duration in seconds.
     * @return string Formatted duration (e.g., "1h 30m 45s").
     */
    private static function format_duration(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return "{$hours}h {$minutes}m {$secs}s";
    }

    /**
     * Describes the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'The user ID'),
            'username' => new external_value(PARAM_TEXT, 'The username'),
            'firstname' => new external_value(PARAM_TEXT, 'User first name'),
            'lastname' => new external_value(PARAM_TEXT, 'User last name'),
            'email' => new external_value(PARAM_TEXT, 'User email'),
            'companyid' => new external_value(PARAM_INT, 'IOMAD company ID (0 if not IOMAD)'),
            'companyname' => new external_value(PARAM_TEXT, 'IOMAD company name (empty if not IOMAD)'),
            'timecreated' => new external_value(PARAM_INT, 'Timestamp when session was created'),
            'timeended' => new external_value(PARAM_INT, 'Timestamp when session ended'),
            'total_duration_seconds' => new external_value(PARAM_INT, 'Total session duration in seconds'),
            'total_duration_formatted' => new external_value(PARAM_TEXT, 'Total duration formatted (e.g., 1h 30m 45s)'),
            'status' => new external_value(PARAM_TEXT, 'Session status (offline)'),
        ]);
    }
}
