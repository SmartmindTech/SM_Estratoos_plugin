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
 * Get enrolled users for a course with IOMAD company validation.
 *
 * Wraps core get_enrolled_users() but adds:
 * - IOMAD: validates the course belongs to the company's category tree
 * - Includes role information per user
 * - Works with both IOMAD and non-IOMAD tokens
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/enrollib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;

class get_enrolled_users extends external_api {

    /**
     * Parameter definition.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'companyid'      => new external_value(PARAM_INT, 'IOMAD company ID (0 for non-IOMAD)', VALUE_DEFAULT, 0),
            'includeroles'   => new external_value(PARAM_BOOL, 'Include role info per user', VALUE_DEFAULT, true),
            'onlyactive'     => new external_value(PARAM_BOOL, 'Return only active enrolments', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * Get enrolled users for a course.
     *
     * @param int  $courseid     Course ID.
     * @param int  $companyid    IOMAD company ID (0 for non-IOMAD).
     * @param bool $includeroles Include role information per user.
     * @param bool $onlyactive   Only return active enrolments.
     * @return array
     */
    public static function execute(int $courseid, int $companyid = 0,
                                   bool $includeroles = true, bool $onlyactive = true): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'companyid'      => $companyid,
            'includeroles'   => $includeroles,
            'onlyactive'     => $onlyactive,
        ]);

        // 1. Validate course exists.
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // 2. Permission check.
        $issiteadmin = is_siteadmin();
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

        if (!$issiteadmin) {
            // Try course-level capability first.
            $hascap = has_capability('moodle/course:viewparticipants', $context);

            if (!$hascap) {
                // IOMAD: company manager can also view.
                if ($isiomad && $params['companyid'] > 0) {
                    if (!\local_sm_estratoos_plugin\util::can_manage_company($params['companyid'])) {
                        throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
                    }
                } else {
                    throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
                }
            }
        }

        // 3. IOMAD: validate course belongs to the company's category tree.
        if ($isiomad && $params['companyid'] > 0) {
            $company = $DB->get_record('company', ['id' => $params['companyid']]);
            if ($company && !empty($company->category)) {
                $companycourseids = self::get_company_course_ids($company->category);
                if (!in_array($params['courseid'], $companycourseids)) {
                    throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin',
                        '', null, 'Course does not belong to company');
                }
            }
        }

        // 4. Get enrolled users.
        $enrolledusers = get_enrolled_users(
            $context,
            '',           // withcapability
            0,            // groupid
            'u.*',
            'u.lastname, u.firstname',
            0,            // limitfrom
            0,            // limitnum
            $params['onlyactive']
        );

        // 5. Format results.
        $users = [];
        foreach ($enrolledusers as $user) {
            $userdata = [
                'id'         => (int)$user->id,
                'username'   => $user->username,
                'email'      => $user->email ?? '',
                'firstname'  => $user->firstname,
                'lastname'   => $user->lastname,
                'fullname'   => fullname($user),
                'idnumber'   => $user->idnumber ?? '',
                'lastaccess' => (int)($user->lastaccess ?? 0),
                'roles'      => [],
            ];

            if ($params['includeroles']) {
                $userroles = get_user_roles($context, $user->id, false);
                foreach ($userroles as $role) {
                    $userdata['roles'][] = [
                        'roleid'    => (int)$role->roleid,
                        'shortname' => $role->shortname,
                        'name'      => role_get_name($role, $context),
                    ];
                }
            }

            $users[] = $userdata;
        }

        return ['users' => $users, 'count' => count($users)];
    }

    /**
     * Get all course IDs under a company's category tree.
     *
     * @param int $companycategory The root category ID.
     * @return array Course IDs.
     */
    private static function get_company_course_ids(int $companycategory): array {
        global $DB;

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

        list($insql, $inparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $inparams['siteid'] = SITEID;
        $courses = $DB->get_records_sql(
            "SELECT id FROM {course} WHERE category $insql AND id != :siteid",
            $inparams
        );
        return array_keys($courses);
    }

    /**
     * Return definition.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id'         => new external_value(PARAM_INT, 'User ID'),
                    'username'   => new external_value(PARAM_TEXT, 'Username'),
                    'email'      => new external_value(PARAM_TEXT, 'Email address'),
                    'firstname'  => new external_value(PARAM_TEXT, 'First name'),
                    'lastname'   => new external_value(PARAM_TEXT, 'Last name'),
                    'fullname'   => new external_value(PARAM_TEXT, 'Full name'),
                    'idnumber'   => new external_value(PARAM_TEXT, 'ID number', VALUE_OPTIONAL),
                    'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp', VALUE_OPTIONAL),
                    'roles'      => new external_multiple_structure(
                        new external_single_structure([
                            'roleid'    => new external_value(PARAM_INT, 'Role ID'),
                            'shortname' => new external_value(PARAM_TEXT, 'Role shortname (e.g. editingteacher, student)'),
                            'name'      => new external_value(PARAM_TEXT, 'Role display name'),
                        ]),
                        'User roles in this course',
                        VALUE_OPTIONAL
                    ),
                ]),
                'Enrolled users'
            ),
            'count' => new external_value(PARAM_INT, 'Total number of enrolled users'),
        ]);
    }
}
