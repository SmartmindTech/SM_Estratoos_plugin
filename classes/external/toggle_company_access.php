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
 * External function to toggle company access (IOMAD only).
 *
 * Called by SmartLearning when a superadmin enables/disables a Moodle instance.
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
use local_sm_estratoos_plugin\util;

/**
 * Toggle company access for IOMAD installations.
 *
 * Enables or disables plugin access for a specific company,
 * including suspending/reactivating all company tokens.
 */
class toggle_company_access extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'IOMAD company ID'),
            'enabled' => new external_value(PARAM_INT, '1 to enable, 0 to disable'),
            'clear_activation' => new external_value(PARAM_INT, '1 to also clear activation code (full deactivation)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Toggle company access.
     *
     * @param int $companyid IOMAD company ID.
     * @param int $enabled 1 to enable, 0 to disable.
     * @param int $clear_activation 1 to also clear the activation code (full deactivation).
     * @return array Result with success status.
     */
    public static function execute(int $companyid, int $enabled, int $clear_activation = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'enabled' => $enabled,
            'clear_activation' => $clear_activation,
        ]);

        // Verify IOMAD is installed.
        if (!util::is_iomad_installed()) {
            return [
                'success' => false,
                'message' => 'IOMAD is not installed on this Moodle instance.',
            ];
        }

        // Verify company exists.
        $company = $DB->get_record('company', ['id' => $params['companyid']], 'id, name, category');
        if (!$company) {
            return [
                'success' => false,
                'message' => 'Company not found: ' . $params['companyid'],
            ];
        }

        // Use company's category context so company-scoped service tokens can call this.
        if (!empty($company->category)) {
            $context = \context_coursecat::instance($company->category);
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);
        if (!is_siteadmin() && !has_capability('local/sm_estratoos_plugin:manageaccess', $context)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'toggle company access');
        }

        // Toggle access.
        if ($params['enabled']) {
            $result = util::enable_company_access($params['companyid']);
        } else {
            $result = util::disable_company_access($params['companyid']);
        }

        // Full deactivation: clear activation code so the company can be re-activated.
        if ($params['clear_activation'] && $result) {
            $access = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $params['companyid']]);
            if ($access) {
                $access->activation_code = null;
                $access->contract_start = null;
                $access->expirydate = null;
                $access->timemodified = time();
                $DB->update_record('local_sm_estratoos_plugin_access', $access);
            }
        }

        $action = $params['enabled'] ? 'enabled' : 'disabled';
        if ($params['clear_activation']) {
            $action = 'deactivated';
        }

        return [
            'success' => $result,
            'message' => $result
                ? "Company {$company->name} access {$action} successfully."
                : "Failed to {$action} company {$company->name} access.",
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
        ]);
    }
}
