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

namespace local_sm_estratoos_plugin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to clean up expired company tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_expired_tokens extends \core\task\scheduled_task {

    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:cleanupexpiredtokens', 'local_sm_estratoos_plugin');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        // Check if cleanup is enabled.
        if (!get_config('local_sm_estratoos_plugin', 'cleanup_expired_tokens')) {
            mtrace('Cleanup of expired tokens is disabled.');
            return;
        }

        $now = time();

        // Find expired tokens from our table where validuntil has passed.
        $sql = "SELECT lit.id, lit.tokenid
                FROM {local_sm_estratoos_plugin} lit
                JOIN {external_tokens} et ON et.id = lit.tokenid
                WHERE (lit.validuntil > 0 AND lit.validuntil < :now1)
                   OR (et.validuntil > 0 AND et.validuntil < :now2)";

        $expiredtokens = $DB->get_records_sql($sql, ['now1' => $now, 'now2' => $now]);

        if (empty($expiredtokens)) {
            mtrace('No expired company tokens found.');
            return;
        }

        $count = 0;
        foreach ($expiredtokens as $token) {
            try {
                // Get user info before deleting.
                $user = null;
                if (!empty($token->tokenid)) {
                    $et = $DB->get_record('external_tokens', ['id' => $token->tokenid], 'userid');
                    if ($et) {
                        $user = $DB->get_record('user', ['id' => $et->userid], 'id, username, email, firstname, lastname');
                    }
                }

                // Write audit record.
                $deletion = new \stdClass();
                $deletion->tokenid = $token->tokenid ?? 0;
                $deletion->userid = $user ? $user->id : 0;
                $deletion->companyid = $token->companyid ?? 0;
                $deletion->reason = 'expired';
                $deletion->deletedby = 0; // System/cron.
                $deletion->timedeleted = time();
                $DB->insert_record('local_sm_estratoos_plugin_del', $deletion);

                // Delete our record.
                $DB->delete_records('local_sm_estratoos_plugin', ['id' => $token->id]);

                // Log token.expired event so SmartLearning is notified.
                try {
                    \local_sm_estratoos_plugin\webhook::log_event('token.expired', 'token', [
                        'tokenid' => $token->id,
                        'userid' => $user ? $user->id : 0,
                        'companyid' => $token->companyid ?? 0,
                        'username' => $user ? $user->username : '',
                        'email' => $user ? $user->email : '',
                        'firstname' => $user ? $user->firstname : '',
                        'lastname' => $user ? $user->lastname : '',
                    ], 0, $token->companyid ?? 0);
                } catch (\Exception $e) {
                    // Non-fatal.
                }

                $count++;
            } catch (\Exception $e) {
                mtrace('Error deleting token ' . $token->id . ': ' . $e->getMessage());
            }
        }

        // Dispatch webhook events immediately so SmartLearning is notified.
        if ($count > 0) {
            try {
                \local_sm_estratoos_plugin\webhook::dispatch_pending();
            } catch (\Exception $e) {
                mtrace('Webhook dispatch failed, will retry on next cron: ' . $e->getMessage());
            }
        }

        mtrace("Cleaned up {$count} expired company token records.");
    }
}
