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

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * Web service result filter class.
 *
 * Filters web service results based on company and enrollment restrictions.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservice_filter {

    /** @var object Token restrictions. */
    private $restrictions;

    /** @var int Company ID. */
    private $companyid;

    /** @var int User ID (token owner). */
    private $userid;

    /** @var bool Restrict to company. */
    private $restricttocompany;

    /** @var bool Restrict to enrollment. */
    private $restricttoenrolment;

    /** @var array|null Cached company course IDs. */
    private $companycourseids = null;

    /** @var array|null Cached company user IDs. */
    private $companyuserids = null;

    /** @var array|null Cached company category IDs. */
    private $companycategoryids = null;

    /** @var array|null Cached user enrolled course IDs. */
    private $userenrolledcourseids = null;

    /**
     * Constructor.
     *
     * @param object $restrictions Token restrictions object.
     */
    public function __construct(object $restrictions) {
        $this->restrictions = $restrictions;
        $this->companyid = $restrictions->companyid;
        $this->userid = $restrictions->userid;
        $this->restricttocompany = (bool)$restrictions->restricttocompany;
        $this->restricttoenrolment = (bool)$restrictions->restricttoenrolment;
    }

    /**
     * Filter core_course_get_courses results.
     *
     * @param array $courses Original course list.
     * @return array Filtered course list.
     */
    public function filter_courses(array $courses): array {
        $allowedcourseids = $this->get_allowed_course_ids();

        return array_values(array_filter($courses, function($course) use ($allowedcourseids) {
            $courseid = is_object($course) ? $course->id : $course['id'];
            return in_array($courseid, $allowedcourseids);
        }));
    }

    /**
     * Filter core_course_get_categories results.
     *
     * @param array $categories Original category list.
     * @return array Filtered category list.
     */
    public function filter_categories(array $categories): array {
        $allowedcategoryids = $this->get_company_category_ids();

        return array_values(array_filter($categories, function($category) use ($allowedcategoryids) {
            $categoryid = is_object($category) ? $category->id : $category['id'];
            return in_array($categoryid, $allowedcategoryids);
        }));
    }

    /**
     * Filter core_user_get_users results.
     *
     * @param array $result Original result with 'users' array.
     * @return array Filtered result.
     */
    public function filter_users(array $result): array {
        if (!isset($result['users'])) {
            return $result;
        }

        $alloweduserids = $this->get_company_user_ids();

        $result['users'] = array_values(array_filter($result['users'], function($user) use ($alloweduserids) {
            $userid = is_object($user) ? $user->id : $user['id'];
            return in_array($userid, $alloweduserids);
        }));

        return $result;
    }

    /**
     * Filter core_user_get_users_by_field results.
     *
     * @param array $users Original user list.
     * @return array Filtered user list.
     */
    public function filter_users_by_field(array $users): array {
        $alloweduserids = $this->get_company_user_ids();

        return array_values(array_filter($users, function($user) use ($alloweduserids) {
            $userid = is_object($user) ? $user->id : $user['id'];
            return in_array($userid, $alloweduserids);
        }));
    }

    /**
     * Filter core_enrol_get_enrolled_users results.
     *
     * @param array $users Original enrolled users list.
     * @return array Filtered user list.
     */
    public function filter_enrolled_users(array $users): array {
        $alloweduserids = $this->get_company_user_ids();

        return array_values(array_filter($users, function($user) use ($alloweduserids) {
            $userid = is_object($user) ? $user->id : $user['id'];
            return in_array($userid, $alloweduserids);
        }));
    }

    /**
     * Filter core_enrol_get_users_courses results.
     *
     * @param array $courses Original course list.
     * @return array Filtered course list.
     */
    public function filter_user_courses(array $courses): array {
        $allowedcourseids = $this->get_allowed_course_ids();

        return array_values(array_filter($courses, function($course) use ($allowedcourseids) {
            $courseid = is_object($course) ? $course->id : $course['id'];
            return in_array($courseid, $allowedcourseids);
        }));
    }

    /**
     * Get course IDs allowed for this token.
     *
     * Combines company restriction and enrollment restriction.
     *
     * @return array Array of course IDs.
     */
    private function get_allowed_course_ids(): array {
        $companycourseids = $this->get_company_course_ids();

        if (!$this->restricttoenrolment) {
            // Only company filter, return all company courses.
            return $companycourseids;
        }

        // Also filter by enrollment.
        $enrolledids = $this->get_user_enrolled_course_ids();

        // Intersection of company courses and enrolled courses.
        return array_intersect($companycourseids, $enrolledids);
    }

    /**
     * Get all course IDs for the company.
     *
     * @return array Array of course IDs.
     */
    public function get_company_course_ids(): array {
        if ($this->companycourseids !== null) {
            return $this->companycourseids;
        }

        global $DB;

        $this->companycourseids = $DB->get_fieldset_select(
            'company_course',
            'courseid',
            'companyid = ?',
            [$this->companyid]
        );

        return $this->companycourseids;
    }

    /**
     * Get all user IDs for the company.
     *
     * @return array Array of user IDs.
     */
    public function get_company_user_ids(): array {
        if ($this->companyuserids !== null) {
            return $this->companyuserids;
        }

        global $DB;

        $this->companyuserids = $DB->get_fieldset_select(
            'company_users',
            'userid',
            'companyid = ?',
            [$this->companyid]
        );

        return $this->companyuserids;
    }

    /**
     * Get company category and its subcategories.
     *
     * @return array Array of category IDs.
     */
    public function get_company_category_ids(): array {
        if ($this->companycategoryids !== null) {
            return $this->companycategoryids;
        }

        global $DB;

        // Get company's main category.
        $companycategory = $this->restrictions->companycategory;
        $this->companycategoryids = [$companycategory];

        // Get the category path.
        $category = $DB->get_record('course_categories', ['id' => $companycategory]);
        if ($category) {
            // Get all subcategories.
            $subcats = $DB->get_records_sql(
                "SELECT id FROM {course_categories} WHERE path LIKE ?",
                [$category->path . '/%']
            );
            foreach ($subcats as $subcat) {
                $this->companycategoryids[] = $subcat->id;
            }
        }

        return $this->companycategoryids;
    }

    /**
     * Get course IDs the user is enrolled in.
     *
     * @return array Array of course IDs.
     */
    public function get_user_enrolled_course_ids(): array {
        if ($this->userenrolledcourseids !== null) {
            return $this->userenrolledcourseids;
        }

        $courses = enrol_get_all_users_courses($this->userid, true);
        $this->userenrolledcourseids = array_keys($courses);

        return $this->userenrolledcourseids;
    }

    /**
     * Check if a course belongs to the company.
     *
     * @param int $courseid Course ID.
     * @return bool True if course belongs to company.
     */
    public function is_company_course(int $courseid): bool {
        return in_array($courseid, $this->get_company_course_ids());
    }

    /**
     * Check if a user belongs to the company.
     *
     * @param int $userid User ID.
     * @return bool True if user belongs to company.
     */
    public function is_company_user(int $userid): bool {
        return in_array($userid, $this->get_company_user_ids());
    }

    /**
     * Check if the token user is enrolled in a course.
     *
     * @param int $courseid Course ID.
     * @return bool True if enrolled.
     */
    public function is_user_enrolled(int $courseid): bool {
        return in_array($courseid, $this->get_user_enrolled_course_ids());
    }

    /**
     * Validate access to a specific course.
     *
     * @param int $courseid Course ID to validate.
     * @throws \moodle_exception If access is denied.
     */
    public function validate_course_access(int $courseid): void {
        if (!$this->is_company_course($courseid)) {
            throw new \moodle_exception('coursenotincompany', 'local_sm_estratoos_plugin');
        }

        if ($this->restricttoenrolment && !$this->is_user_enrolled($courseid)) {
            throw new \moodle_exception('usernotenrolled', 'local_sm_estratoos_plugin');
        }
    }

    /**
     * Validate access to a specific user.
     *
     * @param int $userid User ID to validate.
     * @throws \moodle_exception If access is denied.
     */
    public function validate_user_access(int $userid): void {
        if (!$this->is_company_user($userid)) {
            throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin', '',
                ['userid' => $userid, 'companyid' => $this->companyid]);
        }
    }
}
