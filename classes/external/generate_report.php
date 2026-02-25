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
use context_system;

/**
 * Generate a report from a list of variable names.
 *
 * Receives variables from the backend (which stores them as templates),
 * queries Moodle data, and returns JSON with headers + rows.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_report extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'variables' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Variable name (e.g. user.firstname, course.fullname)'),
                'List of variable names to include in the report'
            ),
            'filters' => new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Filter by course ID (0 = all)', VALUE_DEFAULT, 0),
                'userid' => new external_value(PARAM_INT, 'Filter by user ID (0 = all)', VALUE_DEFAULT, 0),
                'datefrom' => new external_value(PARAM_INT, 'Enrollment from timestamp (0 = no limit)', VALUE_DEFAULT, 0),
                'dateto' => new external_value(PARAM_INT, 'Enrollment until timestamp (0 = no limit)', VALUE_DEFAULT, 0),
            ], 'Optional filters', VALUE_DEFAULT, []),
            'limit' => new external_value(PARAM_INT, 'Max rows (max 10000)', VALUE_DEFAULT, 1000),
            'offset' => new external_value(PARAM_INT, 'Row offset for pagination', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute report generation.
     *
     * @param array $variables Variable names.
     * @param array $filters   Optional filters.
     * @param int   $limit     Max rows.
     * @param int   $offset    Pagination offset.
     * @return array Report data.
     */
    public static function execute(
        array $variables,
        array $filters = [],
        int $limit = 1000,
        int $offset = 0
    ): array {
        global $USER;

        // 1. Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'variables' => $variables,
            'filters' => $filters,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        // 2. Validate variables.
        $validation = \local_sm_estratoos_plugin\reporting\variable_catalog::validate($params['variables']);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'grain' => '',
                'headers' => [],
                'rows' => [],
                'total_rows' => 0,
                'has_more' => false,
                'message' => 'Invalid variables: ' . implode(', ', $validation['invalid']),
            ];
        }

        // 4. Determine company scope from token.
        $companyid = 0;
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $companyid = \local_sm_estratoos_plugin\util::get_company_id_from_token();
        }

        // 5. Generate report.
        try {
            $result = \local_sm_estratoos_plugin\reporting\report_engine::generate(
                $params['variables'],
                $companyid,
                $params['filters'],
                $params['limit'],
                $params['offset']
            );

            // Encode each row as JSON string for transport via Moodle external API.
            $encodedrows = [];
            foreach ($result['rows'] as $row) {
                $encodedrows[] = ['data' => json_encode((array) $row, JSON_UNESCAPED_UNICODE)];
            }

            return [
                'success' => true,
                'grain' => $result['grain'],
                'headers' => $result['headers'],
                'rows' => $encodedrows,
                'total_rows' => $result['total_rows'],
                'has_more' => $result['has_more'],
                'message' => '',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'grain' => '',
                'headers' => [],
                'rows' => [],
                'total_rows' => 0,
                'has_more' => false,
                'message' => 'Report generation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the report was generated'),
            'grain' => new external_value(PARAM_TEXT, 'Report grain: user, course, or activity'),
            'headers' => new external_multiple_structure(
                new external_single_structure([
                    'key' => new external_value(PARAM_TEXT, 'Column key'),
                    'label' => new external_value(PARAM_TEXT, 'Column label'),
                    'type' => new external_value(PARAM_TEXT, 'Data type: int, string, float, timestamp'),
                ]),
                'Column definitions'
            ),
            'rows' => new external_multiple_structure(
                new external_single_structure([
                    'data' => new external_value(PARAM_RAW, 'JSON-encoded row data'),
                ]),
                'Report rows'
            ),
            'total_rows' => new external_value(PARAM_INT, 'Total row count'),
            'has_more' => new external_value(PARAM_BOOL, 'More rows available'),
            'message' => new external_value(PARAM_TEXT, 'Error or status message'),
        ]);
    }
}
