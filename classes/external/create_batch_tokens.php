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

/**
 * External function for creating batch tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_batch_tokens extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID'),
                'Array of user IDs to create tokens for'
            ),
            'companyid' => new external_value(PARAM_INT, 'Company ID'),
            'serviceid' => new external_value(PARAM_INT, 'External service ID'),
            'options' => new external_single_structure([
                'restricttocompany' => new external_value(PARAM_BOOL, 'Restrict to company', VALUE_DEFAULT, true),
                'restricttoenrolment' => new external_value(PARAM_BOOL, 'Restrict to enrollment', VALUE_DEFAULT, true),
                'iprestriction' => new external_value(PARAM_TEXT, 'IP restriction', VALUE_DEFAULT, ''),
                'validuntil' => new external_value(PARAM_INT, 'Valid until timestamp', VALUE_DEFAULT, 0),
                'notes' => new external_value(PARAM_TEXT, 'Notes', VALUE_DEFAULT, ''),
            ], 'Additional options', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Create batch tokens.
     *
     * @param array $userids User IDs.
     * @param int $companyid Company ID.
     * @param int $serviceid Service ID.
     * @param array $options Options.
     * @return array Result.
     */
    public static function execute(array $userids, int $companyid, int $serviceid, array $options = []): array {
        global $USER, $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userids' => $userids,
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'options' => $options,
        ]);

        // Check capabilities — use company category context for IOMAD so
        // company-scoped tokens (e.g., superadmin tokens) can call this.
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
        if ($isiomad && $params['companyid'] > 0) {
            $company = $DB->get_record('company', ['id' => $params['companyid']], 'id, category');
            if ($company && $company->category) {
                $context = \context_coursecat::instance($company->category);
            } else {
                $context = \context_system::instance();
            }
        } else {
            $context = \context_system::instance();
        }
        self::validate_context($context);

        // Allow site admins or company managers.
        if (!is_siteadmin()) {
            if ($isiomad && $params['companyid'] > 0) {
                if (!\local_sm_estratoos_plugin\util::can_manage_company($params['companyid'])) {
                    throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
                }
            } else {
                throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
            }
        }

        // Resolve service ID if not provided (use plugin default).
        if (empty($params['serviceid'])) {
            $defaultservice = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin'], 'id');
            if ($defaultservice) {
                $params['serviceid'] = (int) $defaultservice->id;
            }
        }

        // Prepare options.
        $tokenoptions = [
            'source' => 'api',
            'restricttocompany' => $params['options']['restricttocompany'] ?? true,
            'restricttoenrolment' => $params['options']['restricttoenrolment'] ?? true,
        ];

        if (!empty($params['options']['iprestriction'])) {
            $tokenoptions['iprestriction'] = $params['options']['iprestriction'];
        }

        if (!empty($params['options']['validuntil'])) {
            $tokenoptions['validuntil'] = $params['options']['validuntil'];
        }

        if (!empty($params['options']['notes'])) {
            $tokenoptions['notes'] = $params['options']['notes'];
        }

        // Create batch tokens.
        $results = \local_sm_estratoos_plugin\company_token_manager::create_batch_tokens(
            $params['userids'],
            $params['companyid'],
            $params['serviceid'],
            $tokenoptions
        );

        // Format results.
        $tokens = [];
        foreach ($results->tokens as $token) {
            $tokens[] = [
                'userid' => $token->userid,
                'token' => $token->token,
                'tokenid' => $token->id,
            ];
        }

        $errors = [];
        foreach ($results->errors as $error) {
            $errors[] = [
                'userid' => $error['userid'],
                'error' => $error['error'],
            ];
        }

        return [
            'batchid' => $results->batchid,
            'successcount' => $results->successcount,
            'failcount' => $results->failcount,
            'tokens' => $tokens,
            'errors' => $errors,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'batchid' => new external_value(PARAM_ALPHANUMEXT, 'Batch ID'),
            'successcount' => new external_value(PARAM_INT, 'Number of tokens created'),
            'failcount' => new external_value(PARAM_INT, 'Number of failures'),
            'tokens' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'token' => new external_value(PARAM_ALPHANUMEXT, 'Token string'),
                    'tokenid' => new external_value(PARAM_INT, 'Token record ID'),
                ]),
                'Created tokens'
            ),
            'errors' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'error' => new external_value(PARAM_TEXT, 'Error message'),
                ]),
                'Errors'
            ),
        ]);
    }
}
