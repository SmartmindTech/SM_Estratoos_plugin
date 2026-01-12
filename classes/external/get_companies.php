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
 * External function for getting list of companies.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_companies extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Get list of companies.
     *
     * @return array Companies.
     */
    public static function execute(): array {
        global $DB;

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/sm_estratoos_plugin:viewreports', $context);

        // Only site admins can use this API.
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Get companies.
        $companies = $DB->get_records('company', [], 'name ASC', 'id, name, shortname, category');

        // Format results.
        $result = [];
        foreach ($companies as $company) {
            $result[] = [
                'id' => $company->id,
                'name' => $company->name,
                'shortname' => $company->shortname,
                'categoryid' => $company->category,
            ];
        }

        return ['companies' => $result];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'companies' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Company ID'),
                    'name' => new external_value(PARAM_TEXT, 'Company name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Company short name'),
                    'categoryid' => new external_value(PARAM_INT, 'Course category ID'),
                ]),
                'List of companies'
            ),
        ]);
    }
}
