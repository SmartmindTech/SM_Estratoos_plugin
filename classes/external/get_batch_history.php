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
 * External function for getting batch creation history.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_batch_history extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID filter (0 for all)', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximum number of records to return', VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Get batch history.
     *
     * @param int $companyid Company ID filter.
     * @param int $limit Maximum records.
     * @return array Batches.
     */
    public static function execute(int $companyid = 0, int $limit = 50): array {
        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
            'limit' => $limit,
        ]);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:viewreports', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Get batch history.
        $batches = \local_sm_estratoos_plugin\company_token_manager::get_batch_history(
            $params['companyid'] > 0 ? $params['companyid'] : null,
            $params['limit']
        );

        // Format results.
        $result = [];
        foreach ($batches as $batch) {
            $result[] = [
                'id' => $batch->id,
                'batchid' => $batch->batchid,
                'companyid' => $batch->companyid,
                'companyname' => $batch->companyname,
                'serviceid' => $batch->serviceid,
                'servicename' => $batch->servicename,
                'totalusers' => $batch->totalusers,
                'successcount' => $batch->successcount,
                'failcount' => $batch->failcount,
                'source' => $batch->source,
                'status' => $batch->status,
                'createdby' => $batch->createdby,
                'createdbyname' => fullname($batch),
                'timecreated' => $batch->timecreated,
            ];
        }

        return ['batches' => $result];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'batches' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Batch record ID'),
                    'batchid' => new external_value(PARAM_ALPHANUMEXT, 'Batch UUID'),
                    'companyid' => new external_value(PARAM_INT, 'Company ID'),
                    'companyname' => new external_value(PARAM_TEXT, 'Company name'),
                    'serviceid' => new external_value(PARAM_INT, 'Service ID'),
                    'servicename' => new external_value(PARAM_TEXT, 'Service name'),
                    'totalusers' => new external_value(PARAM_INT, 'Total users in batch'),
                    'successcount' => new external_value(PARAM_INT, 'Successful creations'),
                    'failcount' => new external_value(PARAM_INT, 'Failed creations'),
                    'source' => new external_value(PARAM_TEXT, 'Selection source (company or csv)'),
                    'status' => new external_value(PARAM_TEXT, 'Batch status'),
                    'createdby' => new external_value(PARAM_INT, 'Creator user ID'),
                    'createdbyname' => new external_value(PARAM_TEXT, 'Creator full name'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                ]),
                'List of batch operations'
            ),
        ]);
    }
}
