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
 * External function for getting manager tokens status.
 *
 * This function returns information about whether managers have tokens.
 * - IOMAD mode: Returns managers for a specific company (managertype > 0)
 * - Non-IOMAD mode: Returns all users with manager-like roles who have tokens
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
     * Get manager tokens status.
     *
     * For IOMAD: Returns managers for a specific company.
     * For non-IOMAD: Returns all users with manager-like roles who have tokens.
     *
     * @param int $companyid Company ID (used in IOMAD mode, ignored in non-IOMAD).
     * @return array Manager tokens status.
     */
    public static function execute(int $companyid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
        ]);
        $companyid = $params['companyid'];

        // Validate context based on token type.
        $usercompanyid = \local_sm_estratoos_plugin\util::get_company_id_from_token();
        if ($usercompanyid && \local_sm_estratoos_plugin\util::is_iomad_installed()) {
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
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

        if ($isiomad) {
            // IOMAD MODE: Get managers for a specific company.
            return self::get_iomad_managers($companyid);
        } else {
            // NON-IOMAD MODE: Get all users with manager-like roles who have tokens.
            return self::get_standard_managers();
        }
    }

    /**
     * Get managers for IOMAD mode.
     *
     * @param int $companyid Company ID.
     * @return array Manager tokens status.
     */
    private static function get_iomad_managers(int $companyid): array {
        global $DB;

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
     * Get managers for non-IOMAD (standard Moodle) mode.
     *
     * Detects managers via role shortname patterns: manager, admin, gerente, administrador.
     *
     * @return array Manager tokens status.
     */
    private static function get_standard_managers(): array {
        global $DB;

        // Get all users with manager-like roles who have tokens.
        // Manager detection via role shortname patterns (multilingual support).
        $sql = "SELECT DISTINCT
                    u.id as userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    u.email,
                    lit.timecreated as token_created,
                    lit.active as token_active
                FROM {local_sm_estratoos_plugin} lit
                JOIN {external_tokens} et ON et.id = lit.tokenid
                JOIN {user} u ON u.id = et.userid
                JOIN {role_assignments} ra ON ra.userid = u.id
                JOIN {role} r ON r.id = ra.roleid
                WHERE u.deleted = 0
                  AND (
                      LOWER(r.shortname) LIKE '%manager%'
                      OR LOWER(r.shortname) LIKE '%admin%'
                      OR LOWER(r.shortname) LIKE '%gerente%'
                      OR LOWER(r.shortname) LIKE '%administrador%'
                  )
                ORDER BY u.lastname, u.firstname";

        $managers = $DB->get_records_sql($sql);

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
                'id' => 0,
                'name' => '',
                'shortname' => '',
                'category' => 0,
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
