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
 * External function for getting courses belonging to a company.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_company_courses extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID (0 for all visible courses in non-IOMAD mode)'),
        ]);
    }

    /**
     * Get courses belonging to a company (or all visible courses in non-IOMAD mode).
     *
     * @param int $companyid Company ID (0 for all visible courses).
     * @return array Courses.
     */
    public static function execute(int $companyid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid,
        ]);

        // Check capabilities — same pattern as get_company_users.
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

        // Allow site admins, or company managers for their own companies.
        $issiteadmin = is_siteadmin();

        if (!$issiteadmin) {
            if ($isiomad && $params['companyid'] > 0) {
                if (!\local_sm_estratoos_plugin\util::can_manage_company($params['companyid'])) {
                    throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
                }
            } else {
                throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
            }
        }

        // Get courses based on mode.
        if ($isiomad && $params['companyid'] > 0) {
            // IOMAD MODE: Get courses in the company's category tree.
            $company = $DB->get_record('company', ['id' => $params['companyid']]);
            if (!$company || empty($company->category)) {
                return ['courses' => []];
            }

            // Resolve company category + all subcategories.
            $categoryids = self::get_company_category_ids($company->category);

            if (empty($categoryids)) {
                return ['courses' => []];
            }

            // Build IN clause.
            list($insql, $inparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
            $inparams['siteid'] = SITEID;

            $courses = $DB->get_records_sql(
                "SELECT c.id, c.fullname, c.shortname
                 FROM {course} c
                 WHERE c.category $insql
                   AND c.visible = 1
                   AND c.id != :siteid
                 ORDER BY c.fullname",
                $inparams
            );
        } else {
            // STANDARD MOODLE MODE: Get all visible courses.
            $courses = $DB->get_records_sql(
                "SELECT c.id, c.fullname, c.shortname
                 FROM {course} c
                 WHERE c.visible = 1
                   AND c.id != :siteid
                 ORDER BY c.fullname",
                ['siteid' => SITEID]
            );
        }

        // Format results.
        $result = [];
        foreach ($courses as $course) {
            $result[] = [
                'id' => (int)$course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
            ];
        }

        return ['courses' => $result];
    }

    /**
     * Get company category and all its subcategories.
     *
     * Reuses the same logic as webservice_filter::get_company_category_ids().
     *
     * @param int $companycategory The company's main category ID.
     * @return array Array of category IDs.
     */
    private static function get_company_category_ids(int $companycategory): array {
        global $DB;

        $categoryids = [$companycategory];

        // Get the category path.
        $category = $DB->get_record('course_categories', ['id' => $companycategory]);
        if ($category) {
            // Get all subcategories using sql_like for cross-database compatibility.
            $likepath = $DB->sql_like('path', ':pathpattern');
            $subcats = $DB->get_records_sql(
                "SELECT id FROM {course_categories} WHERE $likepath",
                ['pathpattern' => $DB->sql_like_escape($category->path) . '/%']
            );
            foreach ($subcats as $subcat) {
                $categoryids[] = $subcat->id;
            }
        }

        return $categoryids;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                ]),
                'Courses'
            ),
        ]);
    }
}
