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
 * External function for getting company tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_company_tokens extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID (0 for all)', VALUE_DEFAULT, 0),
            'serviceid' => new external_value(PARAM_INT, 'Service ID filter (0 for all)', VALUE_DEFAULT, 0),
            'batchid' => new external_value(PARAM_ALPHANUMEXT, 'Batch ID filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Get company tokens.
     *
     * @param int $companyid Company ID.
     * @param int $serviceid Service ID.
     * @param string $batchid Batch ID.
     * @return array Tokens.
     */
    public static function execute(int $companyid = 0, int $serviceid = 0, string $batchid = ''): array {
        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'batchid' => $batchid,
        ]);

        // Check capabilities.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:viewreports', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Build filters.
        $filters = [];
        if ($params['serviceid'] > 0) {
            $filters['serviceid'] = $params['serviceid'];
        }
        if (!empty($params['batchid'])) {
            $filters['batchid'] = $params['batchid'];
        }

        // Get tokens.
        $tokens = \local_sm_estratoos_plugin\company_token_manager::get_company_tokens(
            $params['companyid'] > 0 ? $params['companyid'] : null,
            $filters
        );

        // Format results.
        $result = [];
        foreach ($tokens as $token) {
            $result[] = [
                'id' => $token->id,
                'tokenid' => $token->tokenid,
                'userid' => $token->userid,
                'username' => $token->username,
                'email' => $token->email,
                'fullname' => fullname($token),
                'companyid' => $token->companyid,
                'companyname' => $token->companyname,
                'servicename' => $token->servicename,
                'restricttocompany' => (bool)$token->restricttocompany,
                'restricttoenrolment' => (bool)$token->restricttoenrolment,
                'iprestriction' => $token->iprestriction ?? '',
                'validuntil' => $token->validuntil ?? 0,
                'timecreated' => $token->timecreated,
                'lastaccess' => $token->lastaccess ?? 0,
                'batchid' => $token->batchid ?? '',
            ];
        }

        return ['tokens' => $result];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tokens' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Token record ID'),
                    'tokenid' => new external_value(PARAM_INT, 'External token ID'),
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'email' => new external_value(PARAM_EMAIL, 'Email'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'companyid' => new external_value(PARAM_INT, 'Company ID'),
                    'companyname' => new external_value(PARAM_TEXT, 'Company name'),
                    'servicename' => new external_value(PARAM_TEXT, 'Service name'),
                    'restricttocompany' => new external_value(PARAM_BOOL, 'Restricted to company'),
                    'restricttoenrolment' => new external_value(PARAM_BOOL, 'Restricted to enrollment'),
                    'iprestriction' => new external_value(PARAM_TEXT, 'IP restriction'),
                    'validuntil' => new external_value(PARAM_INT, 'Valid until timestamp'),
                    'timecreated' => new external_value(PARAM_INT, 'Created timestamp'),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp'),
                    'batchid' => new external_value(PARAM_ALPHANUMEXT, 'Batch ID'),
                ]),
                'Tokens'
            ),
        ]);
    }
}
