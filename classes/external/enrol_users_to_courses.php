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
 * External function to enrol users into courses with specified roles.
 *
 * Uses the manual enrolment plugin under the hood, with company-scoped
 * permission validation for IOMAD instances.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/enrollib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

/**
 * API to enrol users into courses.
 */
class enrol_users_to_courses extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'enrolments' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                    'courseid' => new external_value(PARAM_INT, 'Moodle course ID'),
                    'roleid' => new external_value(
                        PARAM_INT,
                        'Role ID for enrolment (5=student, 3=editingteacher, 2=coursecreator, etc.)',
                        VALUE_DEFAULT,
                        5
                    ),
                ]),
                'Array of enrolment entries'
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID (0 for non-IOMAD)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Enrol users into courses.
     *
     * @param array $enrolments Array of enrolment entries.
     * @param int $companyid Company ID for IOMAD validation.
     * @return array Results per enrolment.
     */
    public static function execute(array $enrolments, int $companyid = 0): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'enrolments' => $enrolments,
            'companyid' => $companyid,
        ]);

        // Check user is logged in and not guest.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // Permission check: site admin OR IOMAD company manager.
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
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

        // For IOMAD: build set of company course IDs for validation.
        $companycourseids = null;
        if ($isiomad && $params['companyid'] > 0) {
            $company = $DB->get_record('company', ['id' => $params['companyid']]);
            if ($company && !empty($company->category)) {
                $companycourseids = self::get_company_course_ids($company->category);
            }
        }

        // Get the manual enrolment plugin.
        $enrolplugin = enrol_get_plugin('manual');
        if (!$enrolplugin) {
            throw new \moodle_exception('enrolpluginnotinstalled', 'local_sm_estratoos_plugin');
        }

        $results = [];

        foreach ($params['enrolments'] as $enrolment) {
            $userid = (int) $enrolment['userid'];
            $courseid = (int) $enrolment['courseid'];
            $roleid = (int) $enrolment['roleid'];

            $result = [
                'userid' => $userid,
                'courseid' => $courseid,
                'success' => false,
                'message' => '',
            ];

            try {
                // Validate user exists.
                $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                if (!$user) {
                    $result['message'] = "User $userid not found";
                    $results[] = $result;
                    continue;
                }

                // Validate course exists and is visible.
                $course = $DB->get_record('course', ['id' => $courseid]);
                if (!$course || $courseid == SITEID) {
                    $result['message'] = "Course $courseid not found";
                    $results[] = $result;
                    continue;
                }

                // For IOMAD: validate course belongs to the company.
                if ($companycourseids !== null && !in_array($courseid, $companycourseids)) {
                    $result['message'] = "Course $courseid does not belong to company {$params['companyid']}";
                    $results[] = $result;
                    continue;
                }

                // Check if already enrolled.
                if (is_enrolled(\context_course::instance($courseid), $user)) {
                    $result['success'] = true;
                    $result['message'] = 'Already enrolled';
                    $results[] = $result;
                    continue;
                }

                // Get or create manual enrol instance for this course.
                $instance = $DB->get_record('enrol', [
                    'courseid' => $courseid,
                    'enrol' => 'manual',
                    'status' => ENROL_INSTANCE_ENABLED,
                ]);

                if (!$instance) {
                    // Try to create a manual enrol instance.
                    $instanceid = $enrolplugin->add_default_instance($course);
                    if ($instanceid) {
                        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
                    }
                }

                if (!$instance) {
                    $result['message'] = "Could not get or create manual enrol instance for course $courseid";
                    $results[] = $result;
                    continue;
                }

                // Enrol the user.
                $enrolplugin->enrol_user($instance, $userid, $roleid);

                $result['success'] = true;
                $result['message'] = 'Enrolled successfully';

            } catch (\Exception $e) {
                $result['message'] = $e->getMessage();
            }

            $results[] = $result;
        }

        return ['results' => $results];
    }

    /**
     * Get course IDs belonging to a company's category tree.
     *
     * @param int $companycategory The company's main category ID.
     * @return array Array of course IDs.
     */
    private static function get_company_course_ids(int $companycategory): array {
        global $DB;

        // Get all category IDs (same logic as get_company_courses).
        $categoryids = [$companycategory];
        $category = $DB->get_record('course_categories', ['id' => $companycategory]);
        if ($category) {
            $likepath = $DB->sql_like('path', ':pathpattern');
            $subcats = $DB->get_records_sql(
                "SELECT id FROM {course_categories} WHERE $likepath",
                ['pathpattern' => $DB->sql_like_escape($category->path) . '/%']
            );
            foreach ($subcats as $subcat) {
                $categoryids[] = $subcat->id;
            }
        }

        // Get course IDs in those categories.
        list($insql, $inparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $inparams['siteid'] = SITEID;

        $courses = $DB->get_records_sql(
            "SELECT id FROM {course} WHERE category $insql AND id != :siteid",
            $inparams
        );

        return array_keys($courses);
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'User ID'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'success' => new external_value(PARAM_BOOL, 'Whether enrolment succeeded'),
                    'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, ''),
                ]),
                'Enrolment results'
            ),
        ]);
    }
}
