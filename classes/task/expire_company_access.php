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
 * Scheduled task to expire company access.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to automatically disable companies with expired access.
 */
class expire_company_access extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:expirecompanyaccess', 'local_sm_estratoos_plugin');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        global $DB;

        $now = time();

        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            // IOMAD: check per-company expiry in access table.
            $this->expire_company_access($DB, $now);
        } else {
            // Standard Moodle: check system-level contract expiry in config.
            $this->expire_system_access($now);
        }
    }

    /**
     * Expire IOMAD company access records.
     */
    private function expire_company_access(\moodle_database $DB, int $now): void {
        // Find enabled companies with expired access.
        // Dates are stored at noon UTC; add 12h buffer so the company stays active
        // for the entire expiry day and only expires after midnight UTC.
        $sql = "SELECT id, companyid
                FROM {local_sm_estratoos_plugin_access}
                WHERE enabled = 1
                  AND expirydate IS NOT NULL
                  AND (expirydate + 43200) < :now";

        $expiredcompanies = $DB->get_records_sql($sql, ['now' => $now]);

        if (empty($expiredcompanies)) {
            mtrace('No expired company access found.');
            return;
        }

        $count = 0;
        $adminid = get_admin()->id;

        foreach ($expiredcompanies as $record) {
            // Disable company access (this also suspends all tokens).
            \local_sm_estratoos_plugin\util::disable_company_access($record->companyid, $adminid);
            $count++;

            // Get company name for logging.
            $company = $DB->get_record('company', ['id' => $record->companyid], 'name');
            $companyname = $company ? $company->name : "ID: {$record->companyid}";
            mtrace("Disabled expired company access: {$companyname}");

            // Log company.access_expired event.
            try {
                \local_sm_estratoos_plugin\webhook::log_event('company.access_expired', 'company', [
                    'companyid' => $record->companyid,
                    'company_name' => $companyname,
                ], $adminid, $record->companyid);
            } catch (\Exception $e) {
                // Non-fatal.
            }
        }

        mtrace("Expired {$count} company access records.");
    }

    /**
     * Expire standard Moodle system-level access when contract_end has passed.
     */
    private function expire_system_access(int $now): void {
        $contractend = get_config('local_sm_estratoos_plugin', 'contract_end');

        if (empty($contractend)) {
            mtrace('No contract end date configured, skipping.');
            return;
        }

        $isactivated = (bool) get_config('local_sm_estratoos_plugin', 'is_activated');
        if (!$isactivated) {
            mtrace('Plugin already deactivated, skipping.');
            return;
        }

        // Add 12h buffer (same as IOMAD): stay active for the entire expiry day.
        if (($contractend + 43200) < $now) {
            set_config('is_activated', '0', 'local_sm_estratoos_plugin');
            \local_sm_estratoos_plugin\webhook::clear_cache();
            mtrace('System-level contract expired. Plugin deactivated.');

            // Log event.
            try {
                \local_sm_estratoos_plugin\webhook::log_event('system.contract_expired', 'system', [
                    'contract_end' => $contractend,
                ]);
            } catch (\Exception $e) {
                // Non-fatal.
            }
        } else {
            mtrace('Contract still active.');
        }
    }
}
