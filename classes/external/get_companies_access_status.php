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
 * External function to get company access status and expiration info.
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
use external_multiple_structure;
use external_warnings;
use local_sm_estratoos_plugin\util;

/**
 * Get company access status and expiration information for monitoring.
 */
class get_companies_access_status extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID (0 = all companies)', VALUE_DEFAULT, 0),
            'includeexpired' => new external_value(PARAM_BOOL, 'Include expired companies', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Get company access status and expiration info.
     *
     * @param int $companyid Company ID (0 = all).
     * @param bool $includeexpired Include expired companies.
     * @return array Companies with access status.
     */
    public static function execute(int $companyid = 0, bool $includeexpired = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'includeexpired' => $includeexpired,
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

        // Check if IOMAD is installed.
        if (!util::is_iomad_installed()) {
            return ['companies' => [], 'warnings' => [
                ['warningcode' => 'iomadnotinstalled', 'message' => 'IOMAD is not installed']
            ]];
        }

        // Get companies (all or specific).
        if ($params['companyid'] > 0) {
            $company = $DB->get_record('company', ['id' => $params['companyid']], 'id, name, shortname');
            $companies = $company ? [$company->id => $company] : [];
        } else {
            $companies = util::get_companies();
        }

        if (empty($companies)) {
            return ['companies' => [], 'warnings' => []];
        }

        // Get access records.
        $companyids = array_keys($companies);
        list($insql, $inparams) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED);
        $accessrecords = $DB->get_records_select(
            'local_sm_estratoos_plugin_access',
            "companyid $insql",
            $inparams,
            '',
            'companyid, enabled, expirydate, plugin_version, enabledby, timecreated, timemodified'
        );

        // Get token counts per company.
        $tokencounts = $DB->get_records_sql(
            "SELECT companyid, COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active
             FROM {local_sm_estratoos_plugin}
             WHERE companyid $insql
             GROUP BY companyid",
            $inparams
        );

        // Get enabledby user names.
        $enabledbyids = [];
        foreach ($accessrecords as $rec) {
            if (!empty($rec->enabledby)) {
                $enabledbyids[$rec->enabledby] = $rec->enabledby;
            }
        }
        $enabledbyusers = [];
        if (!empty($enabledbyids)) {
            $enabledbyusers = $DB->get_records_list('user', 'id', array_keys($enabledbyids), '', 'id, firstname, lastname');
        }

        // Build result.
        $now = time();
        $result = [];

        foreach ($companies as $company) {
            $access = isset($accessrecords[$company->id]) ? $accessrecords[$company->id] : null;
            $tokens = isset($tokencounts[$company->id]) ? $tokencounts[$company->id] : null;

            $enabled = $access && $access->enabled;
            $expirydate = $access ? ($access->expirydate ?? 0) : 0;
            $expired = !empty($expirydate) && $expirydate < $now;

            // Skip expired if not including them.
            if ($expired && !$params['includeexpired']) {
                continue;
            }

            // Calculate days remaining.
            $daysremaining = -1; // -1 = never expires.
            if (!empty($expirydate)) {
                $diff = $expirydate - $now;
                $daysremaining = (int)floor($diff / 86400); // Can be negative if expired.
            }

            // Get enabledby name.
            $enabledbyname = '';
            if ($access && !empty($access->enabledby) && isset($enabledbyusers[$access->enabledby])) {
                $enabledbyname = fullname($enabledbyusers[$access->enabledby]);
            }

            $result[] = [
                'companyid' => (int)$company->id,
                'companyname' => $company->name,
                'companyshortname' => $company->shortname,
                'enabled' => $enabled,
                'expirydate' => (int)$expirydate,
                'expired' => $expired,
                'daysremaining' => $daysremaining,
                'pluginversion' => $access ? ($access->plugin_version ?? '') : '',
                'enabledby' => (int)($access ? ($access->enabledby ?? 0) : 0),
                'enabledbyname' => $enabledbyname,
                'timecreated' => (int)($access ? ($access->timecreated ?? 0) : 0),
                'timemodified' => (int)($access ? ($access->timemodified ?? 0) : 0),
                'tokencount' => (int)($tokens ? $tokens->total : 0),
                'activetokencount' => (int)($tokens ? $tokens->active : 0),
            ];
        }

        return ['companies' => $result, 'warnings' => []];
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'companies' => new external_multiple_structure(
                new external_single_structure([
                    'companyid' => new external_value(PARAM_INT, 'Company ID'),
                    'companyname' => new external_value(PARAM_TEXT, 'Company name'),
                    'companyshortname' => new external_value(PARAM_TEXT, 'Company shortname'),
                    'enabled' => new external_value(PARAM_BOOL, 'Whether company access is enabled'),
                    'expirydate' => new external_value(PARAM_INT, 'Expiration timestamp (0 = never)'),
                    'expired' => new external_value(PARAM_BOOL, 'Whether access has expired'),
                    'daysremaining' => new external_value(PARAM_INT, 'Days until expiration (-1 = never, negative = expired)'),
                    'pluginversion' => new external_value(PARAM_TEXT, 'Plugin version for this company (empty if not set)'),
                    'enabledby' => new external_value(PARAM_INT, 'User ID who last modified access'),
                    'enabledbyname' => new external_value(PARAM_TEXT, 'Full name of user who modified access'),
                    'timecreated' => new external_value(PARAM_INT, 'Access record creation timestamp'),
                    'timemodified' => new external_value(PARAM_INT, 'Access record modification timestamp'),
                    'tokencount' => new external_value(PARAM_INT, 'Total tokens for this company'),
                    'activetokencount' => new external_value(PARAM_INT, 'Active tokens for this company'),
                ]),
                'Companies with access status'
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
