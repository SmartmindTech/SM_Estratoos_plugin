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
 * Variable catalog for report generation.
 *
 * Maps variable names to SQL fragments, join requirements, and metadata.
 * Three grain levels: user < course < activity.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\reporting;

defined('MOODLE_INTERNAL') || die();

class variable_catalog {

    /** @var array Grain priority. Higher = more detail = more rows. */
    const GRAIN_PRIORITY = [
        'user' => 1,
        'course' => 2,
        'activity' => 3,
    ];

    /**
     * Full catalog of available report variables.
     *
     * Each entry:
     *  - grain: user | course | activity
     *  - select: SQL SELECT expression (using Moodle table aliases)
     *  - alias: Column alias in result
     *  - joins: Required join keys (resolved by report_engine)
     *  - label: Human-readable column header
     *  - type: int | string | float | timestamp
     *  - post_process: (optional) name of post-processing step
     *
     * @return array
     */
    public static function get_catalog(): array {
        return [
            // === USER ===
            'user.id' => [
                'grain' => 'user', 'select' => 'u.id', 'alias' => 'user_id',
                'joins' => [], 'label' => 'User ID', 'type' => 'int',
            ],
            'user.username' => [
                'grain' => 'user', 'select' => 'u.username', 'alias' => 'username',
                'joins' => [], 'label' => 'Username', 'type' => 'string',
            ],
            'user.firstname' => [
                'grain' => 'user', 'select' => 'u.firstname', 'alias' => 'firstname',
                'joins' => [], 'label' => 'First name', 'type' => 'string',
            ],
            'user.lastname' => [
                'grain' => 'user', 'select' => 'u.lastname', 'alias' => 'lastname',
                'joins' => [], 'label' => 'Last name', 'type' => 'string',
            ],
            'user.email' => [
                'grain' => 'user', 'select' => 'u.email', 'alias' => 'email',
                'joins' => [], 'label' => 'Email', 'type' => 'string',
            ],
            'user.city' => [
                'grain' => 'user', 'select' => 'u.city', 'alias' => 'city',
                'joins' => [], 'label' => 'City', 'type' => 'string',
            ],
            'user.country' => [
                'grain' => 'user', 'select' => 'u.country', 'alias' => 'country',
                'joins' => [], 'label' => 'Country', 'type' => 'string',
            ],
            'user.lastaccess' => [
                'grain' => 'user', 'select' => 'u.lastaccess', 'alias' => 'lastaccess',
                'joins' => [], 'label' => 'Last access', 'type' => 'timestamp',
            ],
            'user.timecreated' => [
                'grain' => 'user', 'select' => 'u.timecreated', 'alias' => 'user_timecreated',
                'joins' => [], 'label' => 'Created at', 'type' => 'timestamp',
            ],

            // === COURSE ===
            'course.id' => [
                'grain' => 'course', 'select' => 'c.id', 'alias' => 'course_id',
                'joins' => ['enrol', 'course'], 'label' => 'Course ID', 'type' => 'int',
            ],
            'course.fullname' => [
                'grain' => 'course', 'select' => 'c.fullname', 'alias' => 'course_fullname',
                'joins' => ['enrol', 'course'], 'label' => 'Course name', 'type' => 'string',
            ],
            'course.shortname' => [
                'grain' => 'course', 'select' => 'c.shortname', 'alias' => 'course_shortname',
                'joins' => ['enrol', 'course'], 'label' => 'Course shortname', 'type' => 'string',
            ],
            'course.category' => [
                'grain' => 'course', 'select' => 'c.category', 'alias' => 'course_category',
                'joins' => ['enrol', 'course'], 'label' => 'Category ID', 'type' => 'int',
            ],
            'course.startdate' => [
                'grain' => 'course', 'select' => 'c.startdate', 'alias' => 'course_startdate',
                'joins' => ['enrol', 'course'], 'label' => 'Course start', 'type' => 'timestamp',
            ],
            'course.enddate' => [
                'grain' => 'course', 'select' => 'c.enddate', 'alias' => 'course_enddate',
                'joins' => ['enrol', 'course'], 'label' => 'Course end', 'type' => 'timestamp',
            ],

            // === ENROLLMENT ===
            'enrollment.date' => [
                'grain' => 'course', 'select' => 'ue.timecreated', 'alias' => 'enrollment_date',
                'joins' => ['enrol'], 'label' => 'Enrollment date', 'type' => 'timestamp',
            ],
            'enrollment.status' => [
                'grain' => 'course',
                'select' => "CASE WHEN ue.status = 0 THEN 'active' ELSE 'suspended' END",
                'alias' => 'enrollment_status',
                'joins' => ['enrol'], 'label' => 'Enrollment status', 'type' => 'string',
            ],
            'enrollment.role' => [
                'grain' => 'course', 'select' => 'role_sub.shortname',
                'alias' => 'enrollment_role',
                'joins' => ['enrol', 'course', 'role'], 'label' => 'Role', 'type' => 'string',
            ],

            // === COMPLETION ===
            'completion.status' => [
                'grain' => 'course',
                'select' => "CASE WHEN cc.timecompleted IS NOT NULL THEN 'completed' "
                          . "WHEN cc.id IS NOT NULL THEN 'in_progress' ELSE 'not_started' END",
                'alias' => 'completion_status',
                'joins' => ['enrol', 'course', 'completion'], 'label' => 'Completion', 'type' => 'string',
            ],
            'completion.date' => [
                'grain' => 'course', 'select' => 'COALESCE(cc.timecompleted, 0)',
                'alias' => 'completion_date',
                'joins' => ['enrol', 'course', 'completion'], 'label' => 'Completion date', 'type' => 'timestamp',
            ],
            'completion.progress' => [
                'grain' => 'course', 'select' => '0', 'alias' => 'completion_progress',
                'joins' => ['enrol', 'course'], 'label' => 'Progress %', 'type' => 'float',
                'post_process' => 'progress',
            ],

            // === GRADES ===
            'grade.course_grade' => [
                'grain' => 'course', 'select' => 'COALESCE(gg.finalgrade, 0)',
                'alias' => 'course_grade',
                'joins' => ['enrol', 'course', 'grade'], 'label' => 'Grade', 'type' => 'float',
            ],
            'grade.course_grade_max' => [
                'grain' => 'course', 'select' => 'COALESCE(gi.grademax, 0)',
                'alias' => 'course_grade_max',
                'joins' => ['enrol', 'course', 'grade'], 'label' => 'Grade max', 'type' => 'float',
            ],

            // === ACTIVITY ===
            'activity.id' => [
                'grain' => 'activity', 'select' => 'cm.id', 'alias' => 'activity_id',
                'joins' => ['enrol', 'course', 'activity'], 'label' => 'Activity ID', 'type' => 'int',
            ],
            'activity.name' => [
                'grain' => 'activity', 'select' => "''", 'alias' => 'activity_name',
                'joins' => ['enrol', 'course', 'activity'], 'label' => 'Activity', 'type' => 'string',
                'post_process' => 'activity_name',
            ],
            'activity.type' => [
                'grain' => 'activity', 'select' => 'm.name', 'alias' => 'activity_type',
                'joins' => ['enrol', 'course', 'activity'], 'label' => 'Type', 'type' => 'string',
            ],
            'activity.completion_state' => [
                'grain' => 'activity', 'select' => 'COALESCE(cmc.completionstate, 0)',
                'alias' => 'activity_completion_state',
                'joins' => ['enrol', 'course', 'activity', 'activity_completion'],
                'label' => 'Activity completion', 'type' => 'int',
            ],
            'activity.grade' => [
                'grain' => 'activity', 'select' => 'COALESCE(agg.finalgrade, 0)',
                'alias' => 'activity_grade',
                'joins' => ['enrol', 'course', 'activity', 'activity_grade'],
                'label' => 'Activity grade', 'type' => 'float',
            ],
        ];
    }

    /**
     * Validate variable names against the catalog.
     *
     * @param array $variables
     * @return array ['valid' => bool, 'invalid' => string[]]
     */
    public static function validate(array $variables): array {
        $catalog = self::get_catalog();
        $invalid = [];
        foreach ($variables as $var) {
            if (!isset($catalog[$var])) {
                $invalid[] = $var;
            }
        }
        return ['valid' => empty($invalid), 'invalid' => $invalid];
    }

    /**
     * Determine the grain level for a set of variables.
     *
     * @param array $variables
     * @return string user|course|activity
     */
    public static function determine_grain(array $variables): string {
        $catalog = self::get_catalog();
        $max = 'user';
        foreach ($variables as $var) {
            if (isset($catalog[$var])) {
                $g = $catalog[$var]['grain'];
                if (self::GRAIN_PRIORITY[$g] > self::GRAIN_PRIORITY[$max]) {
                    $max = $g;
                }
            }
        }
        return $max;
    }
}
