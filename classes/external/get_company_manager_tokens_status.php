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
use external_multiple_structure;
use external_warnings;

/**
 * External function for getting manager tokens status for a company.
 *
 * This function returns information about whether a company has any manager
 * tokens created, along with details about those managers.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_company_manager_tokens_status extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID'),
        ]);
    }

    /**
     * Get manager tokens status for a company.
     *
     * @param int $companyid Company ID.
     * @return array Manager tokens status.
     */
    public static function execute(int $companyid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
        ]);
        $companyid = $params['companyid'];

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Check IOMAD is installed.
        if (!\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            throw new \moodle_exception('iomadnotinstalled', 'local_sm_estratoos_plugin');
        }

        // Get company information.
        $company = $DB->get_record('company', ['id' => $companyid]);
        if (!$company) {
            throw new \moodle_exception('invalidcompany', 'local_sm_estratoos_plugin');
        }

        // Get all managers with tokens for this company.
        // A manager is defined by company_users.managertype > 0.
        $sql = "SELECT DISTINCT
                    u.id as userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    lit.timecreated as token_created,
                    lit.active as token_active
                FROM {company_users} cu
                JOIN {user} u ON u.id = cu.userid
                JOIN {local_sm_estratoos_plugin} lit ON lit.companyid = cu.companyid
                JOIN {external_tokens} et ON et.id = lit.tokenid AND et.userid = u.id
                WHERE cu.companyid = :companyid
                  AND cu.managertype > 0
                  AND u.deleted = 0
                ORDER BY u.lastname, u.firstname";

        $managers = $DB->get_records_sql($sql, ['companyid' => $companyid]);

        // Format managers data.
        $managersdata = [];
        foreach ($managers as $manager) {
            $managersdata[] = [
                'userid' => (int)$manager->userid,
                'username' => $manager->username,
                'firstname' => $manager->firstname,
                'lastname' => $manager->lastname,
                'email' => $manager->email,
                'token_created' => (int)$manager->token_created,
                'token_active' => (bool)$manager->token_active,
            ];
        }

        return [
            'has_manager_tokens' => !empty($managersdata),
            'company' => [
                'id' => (int)$company->id,
                'name' => $company->name,
                'shortname' => $company->shortname,
                'category' => (int)$company->category,
            ],
            'managers' => $managersdata,
            'warnings' => [],
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_manager_tokens' => new external_value(PARAM_BOOL, 'Whether the company has manager tokens'),
            'company' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Company ID'),
                'name' => new external_value(PARAM_TEXT, 'Company name'),
                'shortname' => new external_value(PARAM_TEXT, 'Company short name'),
                'category' => new external_value(PARAM_INT, 'Course category ID'),
            ], 'Company information'),
            'managers' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'email' => new external_value(PARAM_TEXT, 'Email address'),
                    'token_created' => new external_value(PARAM_INT, 'Token creation timestamp'),
                    'token_active' => new external_value(PARAM_BOOL, 'Whether token is active'),
                ]),
                'List of managers with tokens'
            ),
            'warnings' => new external_warnings(),
        ]);
    }
}
