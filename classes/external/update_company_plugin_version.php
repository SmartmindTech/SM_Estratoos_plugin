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
 * External function to update company plugin version.
 *
 * This allows external systems to track and update the plugin version
 * independently for each company, enabling gradual rollouts.
 *
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
 * Update company plugin version for independent version tracking.
 */
class update_company_plugin_version extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID to update'),
            'version' => new external_value(PARAM_TEXT, 'New plugin version (e.g., "1.7.37")'),
        ]);
    }

    /**
     * Update the plugin version for a company.
     *
     * @param int $companyid Company ID.
     * @param string $version New plugin version.
     * @return array Result with success status.
     */
    public static function execute(int $companyid, string $version): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'version' => $version,
        ]);

        // Validate context based on token type.
        $usercompanyid = util::get_company_id_from_token();
        if ($usercompanyid && util::is_iomad_installed()) {
            // IOMAD: Use company's category context.
            $company = $DB->get_record('company', ['id' => $usercompanyid], '*', MUST_EXIST);
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

        // Validate version format (e.g., 1.7.37).
        if (!preg_match('/^\d+\.\d+\.\d+$/', $params['version'])) {
            return [
                'success' => false,
                'message' => 'Invalid version format. Expected format: X.Y.Z (e.g., 1.7.37)',
                'companyid' => $params['companyid'],
                'version' => '',
                'previousversion' => '',
                'warnings' => [],
            ];
        }

        // Check if IOMAD is installed.
        if (!util::is_iomad_installed()) {
            return [
                'success' => false,
                'message' => 'IOMAD is not installed',
                'companyid' => $params['companyid'],
                'version' => '',
                'previousversion' => '',
                'warnings' => [
                    ['warningcode' => 'iomadnotinstalled', 'message' => 'IOMAD is not installed']
                ],
            ];
        }

        // Check if company exists.
        $company = $DB->get_record('company', ['id' => $params['companyid']], 'id, name, shortname');
        if (!$company) {
            return [
                'success' => false,
                'message' => 'Company not found',
                'companyid' => $params['companyid'],
                'version' => '',
                'previousversion' => '',
                'warnings' => [
                    ['warningcode' => 'companynotfound', 'message' => 'Company with ID ' . $params['companyid'] . ' not found']
                ],
            ];
        }

        // Check if user's token is for this company (IOMAD security).
        if ($usercompanyid > 0 && $usercompanyid != $params['companyid'] && !is_siteadmin()) {
            return [
                'success' => false,
                'message' => 'Access denied. You can only update the version for your own company.',
                'companyid' => $params['companyid'],
                'version' => '',
                'previousversion' => '',
                'warnings' => [
                    ['warningcode' => 'accessdenied', 'message' => 'Token is for a different company']
                ],
            ];
        }

        // Get or create access record.
        $accessrecord = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $params['companyid']]);

        if ($accessrecord) {
            // Update existing record.
            $previousversion = $accessrecord->plugin_version ?? '';
            $accessrecord->plugin_version = $params['version'];
            $accessrecord->timemodified = time();
            $DB->update_record('local_sm_estratoos_plugin_access', $accessrecord);
        } else {
            // Create new access record with version.
            $previousversion = '';
            $accessrecord = new \stdClass();
            $accessrecord->companyid = $params['companyid'];
            $accessrecord->enabled = 1;
            $accessrecord->plugin_version = $params['version'];
            $accessrecord->enabledby = $USER->id;
            $accessrecord->timecreated = time();
            $accessrecord->timemodified = time();
            $DB->insert_record('local_sm_estratoos_plugin_access', $accessrecord);
        }

        return [
            'success' => true,
            'message' => 'Plugin version updated successfully for company ' . $company->shortname,
            'companyid' => (int)$params['companyid'],
            'version' => $params['version'],
            'previousversion' => $previousversion,
            'warnings' => [],
        ];
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the update was successful'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'companyid' => new external_value(PARAM_INT, 'Company ID'),
            'version' => new external_value(PARAM_TEXT, 'New plugin version'),
            'previousversion' => new external_value(PARAM_TEXT, 'Previous plugin version'),
            'warnings' => new external_warnings(),
        ]);
    }
}
