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
use context_system;
use cache;

/**
 * Extended health check with optional summary counts.
 *
 * Performance target: < 200ms even with summary (cached)
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class health_check_extended extends external_api {

    const SUMMARY_CACHE_TTL = 60;

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'client_timestamp' => new external_value(PARAM_INT, 'Client timestamp (unix)', VALUE_DEFAULT, 0),
            'include_summary' => new external_value(PARAM_BOOL, 'Include summary counts', VALUE_DEFAULT, false),
            'userid' => new external_value(PARAM_INT, 'User ID for personalized counts', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $client_timestamp = 0,
        bool $include_summary = false,
        int $userid = 0
    ): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'client_timestamp' => $client_timestamp,
            'include_summary' => $include_summary,
            'userid' => $userid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        $server_timestamp = time();
        $latency_ms = 0;
        if ($client_timestamp > 0) {
            $latency_ms = max(0, ($server_timestamp - $client_timestamp) * 1000);
        }

        // Get plugin version.
        $plugin_version = '';
        try {
            $plugin = $DB->get_record('config_plugins', [
                'plugin' => 'local_sm_estratoos_plugin',
                'name' => 'version'
            ]);
            $plugin_version = $plugin ? $plugin->value : '';
        } catch (\Exception $e) {
            $plugin_version = 'unknown';
        }

        $result = [
            'status' => 'ok',
            'server_timestamp' => $server_timestamp,
            'client_timestamp' => $client_timestamp,
            'latency_ms' => $latency_ms,
            'moodle_version' => $CFG->version ?? '',
            'moodle_release' => $CFG->release ?? '',
            'plugin_version' => $plugin_version,
            'summary' => [
                'total_users' => 0,
                'total_courses' => 0,
                'user_courses' => 0,
                'unread_messages' => 0,
                'pending_assignments' => 0,
            ],
            'summary_cached' => false,
        ];

        // Include summary if requested.
        if ($include_summary) {
            $cache = cache::make('local_sm_estratoos_plugin', 'health_summary');
            $cache_key = "summary_{$userid}";
            $cached_summary = $cache->get($cache_key);

            if ($cached_summary !== false) {
                $result['summary'] = $cached_summary;
                $result['summary_cached'] = true;
            } else {
                $summary = [
                    'total_users' => 0,
                    'total_courses' => 0,
                    'user_courses' => 0,
                    'unread_messages' => 0,
                    'pending_assignments' => 0,
                ];

                // Site-wide counts (fast, cached aggressively).
                try {
                    $summary['total_users'] = (int)$DB->count_records('user', ['deleted' => 0, 'suspended' => 0]);
                } catch (\Exception $e) {
                    $summary['total_users'] = 0;
                }

                try {
                    $summary['total_courses'] = max(0, (int)$DB->count_records('course', ['visible' => 1]) - 1);
                } catch (\Exception $e) {
                    $summary['total_courses'] = 0;
                }

                // User-specific counts if userid provided.
                if ($userid > 0) {
                    try {
                        $summary['user_courses'] = (int)$DB->count_records_sql("
                            SELECT COUNT(DISTINCT c.id)
                            FROM {course} c
                            JOIN {enrol} e ON e.courseid = c.id
                            JOIN {user_enrolments} ue ON ue.enrolid = e.id
                            WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0 AND c.visible = 1",
                            ['userid' => $userid]);
                    } catch (\Exception $e) {
                        $summary['user_courses'] = 0;
                    }

                    try {
                        $summary['unread_messages'] = (int)$DB->count_records_sql("
                            SELECT COUNT(DISTINCT m.id)
                            FROM {messages} m
                            JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                            LEFT JOIN {message_user_actions} mua ON mua.messageid = m.id
                                AND mua.userid = :userid3
                                AND mua.action = 1
                            WHERE mcm.userid = :userid AND m.useridfrom != :userid2 AND mua.id IS NULL",
                            ['userid' => $userid, 'userid2' => $userid, 'userid3' => $userid]);
                    } catch (\Exception $e) {
                        $summary['unread_messages'] = 0;
                    }

                    try {
                        $summary['pending_assignments'] = (int)$DB->count_records_sql("
                            SELECT COUNT(DISTINCT a.id)
                            FROM {assign} a
                            JOIN {course} c ON c.id = a.course
                            JOIN {enrol} e ON e.courseid = c.id
                            JOIN {user_enrolments} ue ON ue.enrolid = e.id
                            LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = :userid2 AND s.latest = 1
                            WHERE ue.userid = :userid AND ue.status = 0 AND e.status = 0
                              AND a.duedate > :now AND (s.status IS NULL OR s.status != 'submitted')",
                            ['userid' => $userid, 'userid2' => $userid, 'now' => time()]);
                    } catch (\Exception $e) {
                        $summary['pending_assignments'] = 0;
                    }
                }

                $cache->set($cache_key, $summary);
                $result['summary'] = $summary;
                $result['summary_cached'] = false;
            }
        }

        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'server_timestamp' => new external_value(PARAM_INT, 'Server timestamp'),
            'client_timestamp' => new external_value(PARAM_INT, 'Client timestamp'),
            'latency_ms' => new external_value(PARAM_INT, 'Latency in ms'),
            'moodle_version' => new external_value(PARAM_RAW, 'Moodle version number'),
            'moodle_release' => new external_value(PARAM_RAW, 'Moodle release string'),
            'plugin_version' => new external_value(PARAM_RAW, 'Plugin version'),
            'summary' => new external_single_structure([
                'total_users' => new external_value(PARAM_INT, 'Total users'),
                'total_courses' => new external_value(PARAM_INT, 'Total courses'),
                'user_courses' => new external_value(PARAM_INT, 'User courses'),
                'unread_messages' => new external_value(PARAM_INT, 'Unread messages'),
                'pending_assignments' => new external_value(PARAM_INT, 'Pending assignments'),
            ]),
            'summary_cached' => new external_value(PARAM_BOOL, 'Summary from cache'),
        ]);
    }
}
