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
 * Start a SmartLearning presence session in Moodle.
 *
 * This function creates a record in mdl_sessions to make the user appear
 * "online" in Moodle reports and user online blocks. The session ID is
 * prefixed with "slp_" (SmartLearning Presence) to distinguish from
 * browser sessions.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class start_session extends external_api {

    /**
     * Describes the parameters accepted by start_session.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Start a presence session for the current user.
     *
     * Creates a record in mdl_sessions so the user appears online in Moodle.
     * Any existing SmartLearning sessions for this user are removed first.
     *
     * @return array Session details including sid, user info, and company info.
     */
    public static function execute(): array {
        global $USER, $DB;

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Generate unique session ID with SmartLearning prefix.
        $sid = 'slp_' . md5(uniqid($USER->id . '_', true));

        // Remove any existing SmartLearning sessions for this user.
        $DB->delete_records_select('sessions',
            "userid = ? AND sid LIKE 'slp_%'",
            [$USER->id]
        );

        // Create session record in mdl_sessions.
        $session = new \stdClass();
        $session->state = 0;  // Active session.
        $session->sid = $sid;
        $session->userid = $USER->id;
        $session->sessdata = null;
        $session->timecreated = time();
        $session->timemodified = time();
        $session->firstip = getremoteaddr();
        $session->lastip = getremoteaddr();

        $session->id = $DB->insert_record('sessions', $session);

        // Also update user's lastaccess.
        $DB->set_field('user', 'lastaccess', time(), ['id' => $USER->id]);

        // Get user record.
        $user = $DB->get_record('user', ['id' => $USER->id], 'id, username, firstname, lastname, email');

        // Get company info if IOMAD is installed.
        $companyinfo = self::get_company_info($USER->id);

        return [
            'session_id' => (int) $session->id,
            'sid' => $sid,
            'userid' => (int) $USER->id,
            'username' => $user->username,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
            'companyid' => $companyinfo['id'],
            'companyname' => $companyinfo['name'],
            'timecreated' => $session->timecreated,
            'status' => 'online',
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
     * Describes the return value structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'session_id' => new external_value(PARAM_INT, 'The database ID of the session record'),
            'sid' => new external_value(PARAM_TEXT, 'The session ID (prefixed with slp_)'),
            'userid' => new external_value(PARAM_INT, 'The user ID'),
            'username' => new external_value(PARAM_TEXT, 'The username'),
            'firstname' => new external_value(PARAM_TEXT, 'User first name'),
            'lastname' => new external_value(PARAM_TEXT, 'User last name'),
            'email' => new external_value(PARAM_TEXT, 'User email'),
            'companyid' => new external_value(PARAM_INT, 'IOMAD company ID (0 if not IOMAD)'),
            'companyname' => new external_value(PARAM_TEXT, 'IOMAD company name (empty if not IOMAD)'),
            'timecreated' => new external_value(PARAM_INT, 'Timestamp when session was created'),
            'status' => new external_value(PARAM_TEXT, 'Session status (online)'),
        ]);
    }
}
