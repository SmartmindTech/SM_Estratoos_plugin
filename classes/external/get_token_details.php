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
 * External function to get comprehensive token details.
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
 * Get comprehensive token details including user roles, restrictions, creation info, etc.
 */
class get_token_details extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tokenid' => new external_value(PARAM_INT, 'Plugin token ID (local_sm_estratoos_plugin.id)', VALUE_DEFAULT, 0),
            'token' => new external_value(PARAM_ALPHANUMEXT, 'Token hash string', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get comprehensive token details.
     *
     * @param int $tokenid Plugin token ID.
     * @param string $token Token hash string.
     * @return array Token details.
     */
    public static function execute(int $tokenid = 0, string $token = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'tokenid' => $tokenid,
            'token' => $token,
        ]);

        // Validate context based on token type.
        $companyid = util::get_company_id_from_token();
        if ($companyid && util::is_iomad_installed()) {
            // IOMAD: Use company's category context.
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);
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

        // Must provide either tokenid or token.
        if (empty($params['tokenid']) && empty($params['token'])) {
            throw new \moodle_exception('invalidtoken', 'local_sm_estratoos_plugin');
        }

        $plugintoken = null;
        $externaltoken = null;

        // Find the token record.
        if (!empty($params['token'])) {
            // Lookup by token hash using sql_compare_text for cross-database TEXT column compatibility.
            // Only wrap the column, not the parameter placeholder.
            $tokencompare = $DB->sql_compare_text('token');
            $externaltoken = $DB->get_record_sql(
                "SELECT * FROM {external_tokens} WHERE {$tokencompare} = :token",
                ['token' => $params['token']]
            );
            if ($externaltoken) {
                $plugintoken = $DB->get_record('local_sm_estratoos_plugin', ['tokenid' => $externaltoken->id]);
            }
        } else {
            // Lookup by plugin tokenid.
            $plugintoken = $DB->get_record('local_sm_estratoos_plugin', ['id' => $params['tokenid']]);
            if ($plugintoken && $plugintoken->tokenid) {
                $externaltoken = $DB->get_record('external_tokens', ['id' => $plugintoken->tokenid]);
            } else if ($plugintoken && !empty($plugintoken->token_backup)) {
                // Token is suspended - get info from backup.
                $externaltoken = json_decode($plugintoken->token_backup);
            }
        }

        if (!$plugintoken) {
            throw new \moodle_exception('tokennotfound', 'local_sm_estratoos_plugin');
        }

        // Security: Non-admin users can only see their own tokens or tokens in their managed companies.
        $issiteadmin = is_siteadmin();
        if (!$issiteadmin) {
            $managedcompanies = util::get_user_managed_companies();
            $canview = false;

            // Check if token belongs to a company the user manages.
            if ($plugintoken->companyid && isset($managedcompanies[$plugintoken->companyid])) {
                $canview = true;
            }

            // Check if it's the user's own token.
            if ($externaltoken && $externaltoken->userid == $USER->id) {
                $canview = true;
            }

            if (!$canview) {
                throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
            }
        }

        // Build response.
        $result = self::build_token_details($plugintoken, $externaltoken);

        return ['token' => $result, 'warnings' => []];
    }

    /**
     * Build comprehensive token details array.
     *
     * @param object $plugintoken Plugin token record.
     * @param object|null $externaltoken External token record (may be from backup).
     * @return array Token details.
     */
    private static function build_token_details($plugintoken, $externaltoken): array {
        global $DB;

        // Get token owner user.
        $userid = $externaltoken ? ($externaltoken->userid ?? 0) : 0;
        $user = $userid ? $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname, email') : null;

        // Get creator user.
        $creatorid = $plugintoken->createdby ?? ($externaltoken ? ($externaltoken->creatorid ?? 0) : 0);
        $creator = $creatorid ? $DB->get_record('user', ['id' => $creatorid], 'id, username, firstname, lastname, email') : null;

        // Get service info.
        $serviceid = $externaltoken ? ($externaltoken->externalserviceid ?? 0) : 0;
        $service = $serviceid ? $DB->get_record('external_services', ['id' => $serviceid], 'id, name, shortname') : null;

        // Get company info (IOMAD).
        $company = null;
        if ($plugintoken->companyid && util::is_iomad_installed()) {
            $company = $DB->get_record('company', ['id' => $plugintoken->companyid], 'id, name, shortname');
        }

        // Get user roles across all contexts.
        $roles = [];
        if ($userid) {
            $sql = "SELECT DISTINCT r.id, r.shortname, r.name, ctx.contextlevel
                    FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.userid = :userid
                    ORDER BY ctx.contextlevel, r.sortorder";
            $userroles = $DB->get_records_sql($sql, ['userid' => $userid]);

            foreach ($userroles as $role) {
                $contextname = '';
                switch ($role->contextlevel) {
                    case CONTEXT_SYSTEM:
                        $contextname = 'system';
                        break;
                    case CONTEXT_COURSECAT:
                        $contextname = 'category';
                        break;
                    case CONTEXT_COURSE:
                        $contextname = 'course';
                        break;
                    case CONTEXT_MODULE:
                        $contextname = 'module';
                        break;
                    case CONTEXT_USER:
                        $contextname = 'user';
                        break;
                    default:
                        $contextname = 'other';
                }
                $roles[] = [
                    'id' => (int)$role->id,
                    'shortname' => $role->shortname,
                    'name' => $role->name,
                    'contextlevel' => $contextname,
                ];
            }
        }

        return [
            'id' => (int)$plugintoken->id,
            'tokenid' => (int)($plugintoken->tokenid ?? 0),
            'token' => $externaltoken ? ($externaltoken->token ?? '') : '',
            'active' => (bool)$plugintoken->active,
            'userid' => (int)$userid,
            'username' => $user ? $user->username : '',
            'userfullname' => $user ? fullname($user) : '',
            'useremail' => $user ? $user->email : '',
            'creatorid' => (int)$creatorid,
            'creatorfullname' => $creator ? fullname($creator) : '',
            'serviceid' => (int)$serviceid,
            'servicename' => $service ? $service->name : '',
            'serviceshortname' => $service ? $service->shortname : '',
            'companyid' => (int)$plugintoken->companyid,
            'companyname' => $company ? $company->name : '',
            'companyshortname' => $company ? $company->shortname : '',
            'restricttocompany' => (bool)$plugintoken->restricttocompany,
            'restricttoenrolment' => (bool)$plugintoken->restricttoenrolment,
            'iprestriction' => $plugintoken->iprestriction ?? '',
            'validuntil' => (int)($externaltoken && isset($externaltoken->validuntil) ? $externaltoken->validuntil : ($plugintoken->validuntil ?? 0)),
            'timecreated' => (int)$plugintoken->timecreated,
            'lastaccess' => (int)($externaltoken && isset($externaltoken->lastaccess) ? $externaltoken->lastaccess : 0),
            'notes' => $plugintoken->notes ?? '',
            'roles' => $roles,
        ];
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Plugin token record ID'),
                'tokenid' => new external_value(PARAM_INT, 'External token ID'),
                'token' => new external_value(PARAM_TEXT, 'Token hash string'),
                'active' => new external_value(PARAM_BOOL, 'Whether token is active'),
                'userid' => new external_value(PARAM_INT, 'Token owner user ID'),
                'username' => new external_value(PARAM_TEXT, 'Token owner username'),
                'userfullname' => new external_value(PARAM_TEXT, 'Token owner full name'),
                'useremail' => new external_value(PARAM_TEXT, 'Token owner email'),
                'creatorid' => new external_value(PARAM_INT, 'Token creator user ID'),
                'creatorfullname' => new external_value(PARAM_TEXT, 'Token creator full name'),
                'serviceid' => new external_value(PARAM_INT, 'External service ID'),
                'servicename' => new external_value(PARAM_TEXT, 'Service name'),
                'serviceshortname' => new external_value(PARAM_TEXT, 'Service shortname'),
                'companyid' => new external_value(PARAM_INT, 'Company ID (IOMAD)'),
                'companyname' => new external_value(PARAM_TEXT, 'Company name'),
                'companyshortname' => new external_value(PARAM_TEXT, 'Company shortname'),
                'restricttocompany' => new external_value(PARAM_BOOL, 'Restricted to company'),
                'restricttoenrolment' => new external_value(PARAM_BOOL, 'Restricted to enrollment'),
                'iprestriction' => new external_value(PARAM_TEXT, 'IP restriction'),
                'validuntil' => new external_value(PARAM_INT, 'Expiration timestamp'),
                'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp'),
                'notes' => new external_value(PARAM_TEXT, 'Admin notes'),
                'roles' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Role ID'),
                        'shortname' => new external_value(PARAM_TEXT, 'Role shortname'),
                        'name' => new external_value(PARAM_TEXT, 'Role name'),
                        'contextlevel' => new external_value(PARAM_TEXT, 'Context level'),
                    ]),
                    'User roles'
                ),
            ]),
            'warnings' => new external_warnings(),
        ]);
    }
}
