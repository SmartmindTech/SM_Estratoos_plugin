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
 * Scheduled task to dispatch webhook events to SmartLearning.
 *
 * Runs every minute. Dispatches up to 50 pending events per run.
 * Retries failed events with exponential backoff.
 * Cleans up old sent events (>30 days) and permanently failed events (>=10 attempts).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dispatch_webhooks extends \core\task\scheduled_task {

    /**
     * Get the task name for admin display.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_dispatch_webhooks', 'local_sm_estratoos_plugin');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute(): void {
        // Check if plugin is activated.
        if (!\local_sm_estratoos_plugin\webhook::is_activated()) {
            mtrace('SM Estratoos: Plugin not activated, skipping webhook dispatch.');
            return;
        }

        // Check if webhooks are enabled.
        if (!\local_sm_estratoos_plugin\util::get_env_config('webhook_enabled', '1')) {
            mtrace('SM Estratoos: Webhooks disabled, skipping dispatch.');
            return;
        }

        $dispatched = \local_sm_estratoos_plugin\webhook::dispatch_pending(50);
        mtrace("SM Estratoos: Dispatched {$dispatched} webhook events.");
    }
}
