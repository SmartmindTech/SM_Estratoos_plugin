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
        $companycategory = $this->restrictions->companycategory ?? null;

        // If no valid company category, return empty array.
        if (empty($companycategory) || !is_numeric($companycategory)) {
            $this->companycategoryids = [];
            return $this->companycategoryids;
        }

        $companycategory = (int)$companycategory;
        $this->companycategoryids = [$companycategory];

        // Get the category path.
        $category = $DB->get_record('course_categories', ['id' => $companycategory]);
        if ($category && !empty($category->path)) {
            // Get all subcategories using sql_like for cross-database compatibility.
            $likepath = $DB->sql_like('path', ':pathpattern');
            $subcats = $DB->get_records_sql(
                "SELECT id FROM {course_categories} WHERE $likepath",
                ['pathpattern' => $DB->sql_like_escape($category->path) . '/%']
            );
            foreach ($subcats as $subcat) {
                $this->companycategoryids[] = (int)$subcat->id;
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

    // ========================================
    // COURSE CONTENT FILTERS
    // ========================================

    /**
     * Filter core_course_get_courses_by_field results.
     *
     * @param array $result Result containing 'courses' array.
     * @return array Filtered result.
     */
    public function filter_courses_by_field(array $result): array {
        if (!isset($result['courses'])) {
            return $result;
        }

        $allowedcourseids = $this->get_allowed_course_ids();

        $result['courses'] = array_values(array_filter($result['courses'], function($course) use ($allowedcourseids) {
            $courseid = is_object($course) ? $course->id : $course['id'];
            return in_array($courseid, $allowedcourseids);
        }));

        return $result;
    }

    /**
     * Filter core_course_get_contents results.
     * Validates course access before returning contents.
     *
     * @param array $contents Course contents.
     * @param int $courseid Course ID being accessed.
     * @return array Contents if access allowed, empty if not.
     */
    public function filter_course_contents(array $contents, int $courseid): array {
        if (!$this->is_allowed_course($courseid)) {
            return [];
        }
        return $contents;
    }

    /**
     * Filter core_completion_get_activities_completion_status results.
     *
     * @param array $result Result with 'statuses' array.
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return array Filtered result.
     */
    public function filter_completion_status(array $result, int $courseid, int $userid): array {
        if (!$this->is_allowed_course($courseid) || !$this->is_company_user($userid)) {
            return ['statuses' => []];
        }
        return $result;
    }

    // ========================================
    // ASSIGNMENT FILTERS
    // ========================================

    /**
     * Filter mod_assign_get_assignments results.
     *
     * @param array $result Result with 'courses' array containing assignments.
     * @return array Filtered result.
     */
    public function filter_assignments(array $result): array {
        if (!isset($result['courses'])) {
            return $result;
        }

        $allowedcourseids = $this->get_allowed_course_ids();

        $result['courses'] = array_values(array_filter($result['courses'], function($course) use ($allowedcourseids) {
            $courseid = is_object($course) ? $course->id : $course['id'];
            return in_array($courseid, $allowedcourseids);
        }));

        return $result;
    }

    /**
     * Filter mod_assign_get_submissions results.
     *
     * @param array $result Result with 'assignments' array.
     * @return array Filtered result.
     */
    public function filter_submissions(array $result): array {
        if (!isset($result['assignments'])) {
            return $result;
        }

        $allowedassignmentids = $this->get_allowed_assignment_ids();
        $alloweduserids = $this->get_company_user_ids();

        $result['assignments'] = array_values(array_filter($result['assignments'], function($assignment) use ($allowedassignmentids) {
            $assignid = is_object($assignment) ? $assignment->assignmentid : $assignment['assignmentid'];
            return in_array($assignid, $allowedassignmentids);
        }));

        // Also filter submissions within each assignment to only company users.
        foreach ($result['assignments'] as &$assignment) {
            $submissions = is_object($assignment) ? $assignment->submissions : $assignment['submissions'];
            if (is_array($submissions)) {
                $filtered = array_values(array_filter($submissions, function($sub) use ($alloweduserids) {
                    $userid = is_object($sub) ? $sub->userid : $sub['userid'];
                    return in_array($userid, $alloweduserids);
                }));
                if (is_object($assignment)) {
                    $assignment->submissions = $filtered;
                } else {
                    $assignment['submissions'] = $filtered;
                }
            }
        }

        return $result;
    }

    /**
     * Filter mod_assign_get_grades results.
     *
     * @param array $result Result with 'assignments' array.
     * @return array Filtered result.
     */
    public function filter_assignment_grades(array $result): array {
        if (!isset($result['assignments'])) {
            return $result;
        }

        $allowedassignmentids = $this->get_allowed_assignment_ids();
        $alloweduserids = $this->get_company_user_ids();

        $result['assignments'] = array_values(array_filter($result['assignments'], function($assignment) use ($allowedassignmentids) {
            $assignid = is_object($assignment) ? $assignment->assignmentid : $assignment['assignmentid'];
            return in_array($assignid, $allowedassignmentids);
        }));

        // Filter grades to only company users.
        foreach ($result['assignments'] as &$assignment) {
            $grades = is_object($assignment) ? $assignment->grades : $assignment['grades'];
            if (is_array($grades)) {
                $filtered = array_values(array_filter($grades, function($grade) use ($alloweduserids) {
                    $userid = is_object($grade) ? $grade->userid : $grade['userid'];
                    return in_array($userid, $alloweduserids);
                }));
                if (is_object($assignment)) {
                    $assignment->grades = $filtered;
                } else {
                    $assignment['grades'] = $filtered;
                }
            }
        }

        return $result;
    }

    // ========================================
    // QUIZ FILTERS
    // ========================================

    /**
     * Filter mod_quiz_get_quizzes_by_courses results.
     *
     * @param array $result Result with 'quizzes' array.
     * @return array Filtered result.
     */
    public function filter_quizzes(array $result): array {
        if (!isset($result['quizzes'])) {
            return $result;
        }

        $allowedcourseids = $this->get_allowed_course_ids();

        $result['quizzes'] = array_values(array_filter($result['quizzes'], function($quiz) use ($allowedcourseids) {
            $courseid = is_object($quiz) ? $quiz->course : $quiz['course'];
            return in_array($courseid, $allowedcourseids);
        }));

        return $result;
    }

    /**
     * Filter mod_quiz_get_user_attempts results.
     *
     * @param array $result Result with 'attempts' array.
     * @param int $quizid Quiz ID.
     * @return array Filtered result.
     */
    public function filter_quiz_attempts(array $result, int $quizid): array {
        if (!$this->is_company_quiz($quizid)) {
            return ['attempts' => []];
        }

        // Also filter by company users.
        if (isset($result['attempts'])) {
            $alloweduserids = $this->get_company_user_ids();
            $result['attempts'] = array_values(array_filter($result['attempts'], function($attempt) use ($alloweduserids) {
                $userid = is_object($attempt) ? $attempt->userid : $attempt['userid'];
                return in_array($userid, $alloweduserids);
            }));
        }

        return $result;
    }

    /**
     * Filter mod_quiz_get_user_best_grade results.
     *
     * @param array $result Grade result.
     * @param int $quizid Quiz ID.
     * @return array Filtered result.
     */
    public function filter_quiz_grade(array $result, int $quizid): array {
        if (!$this->is_company_quiz($quizid)) {
            return [];
        }
        return $result;
    }

    // ========================================
    // CALENDAR FILTERS
    // ========================================

    /**
     * Filter core_calendar_get_calendar_events results.
     *
     * @param array $result Result with 'events' array.
     * @return array Filtered result.
     */
    public function filter_calendar_events(array $result): array {
        if (!isset($result['events'])) {
            return $result;
        }

        $allowedcourseids = $this->get_allowed_course_ids();
        $alloweduserids = $this->get_company_user_ids();

        $result['events'] = array_values(array_filter($result['events'], function($event) use ($allowedcourseids, $alloweduserids) {
            $courseid = is_object($event) ? ($event->courseid ?? 0) : ($event['courseid'] ?? 0);
            $userid = is_object($event) ? ($event->userid ?? 0) : ($event['userid'] ?? 0);
            $eventtype = is_object($event) ? ($event->eventtype ?? '') : ($event['eventtype'] ?? '');

            // Site events are allowed.
            if ($eventtype === 'site') {
                return true;
            }

            // Course events must be in company courses.
            if ($courseid > 0 && !in_array($courseid, $allowedcourseids)) {
                return false;
            }

            // User events must be for company users.
            if ($userid > 0 && !in_array($userid, $alloweduserids)) {
                return false;
            }

            return true;
        }));

        return $result;
    }

    // ========================================
    // MESSAGING FILTERS
    // ========================================

    /**
     * Validate message recipient is a company user.
     *
     * @param int $userid Recipient user ID.
     * @return bool True if recipient is allowed.
     */
    public function validate_message_recipient(int $userid): bool {
        return $this->is_company_user($userid);
    }

    /**
     * Filter core_message_get_conversations results.
     *
     * @param array $result Result with 'conversations' array.
     * @return array Filtered result.
     */
    public function filter_conversations(array $result): array {
        if (!isset($result['conversations'])) {
            return $result;
        }

        $alloweduserids = $this->get_company_user_ids();

        $result['conversations'] = array_values(array_filter($result['conversations'], function($conv) use ($alloweduserids) {
            // Check if any member is in the company.
            $members = is_object($conv) ? ($conv->members ?? []) : ($conv['members'] ?? []);
            foreach ($members as $member) {
                $memberid = is_object($member) ? $member->id : $member['id'];
                if (in_array($memberid, $alloweduserids)) {
                    return true;
                }
            }
            return false;
        }));

        return $result;
    }

    // ========================================
    // FORUM FILTERS
    // ========================================

    /**
     * Filter mod_forum_get_forums_by_courses results.
     *
     * @param array $forums Array of forums.
     * @return array Filtered forums.
     */
    public function filter_forums(array $forums): array {
        $allowedcourseids = $this->get_allowed_course_ids();

        return array_values(array_filter($forums, function($forum) use ($allowedcourseids) {
            $courseid = is_object($forum) ? $forum->course : $forum['course'];
            return in_array($courseid, $allowedcourseids);
        }));
    }

    /**
     * Filter mod_forum_get_forum_discussions results.
     *
     * @param array $result Result with 'discussions' array.
     * @param int $forumid Forum ID.
     * @return array Filtered result.
     */
    public function filter_discussions(array $result, int $forumid): array {
        if (!$this->is_company_forum($forumid)) {
            return ['discussions' => []];
        }
        return $result;
    }

    /**
     * Filter mod_forum_get_discussion_posts results.
     *
     * @param array $result Result with 'posts' array.
     * @param int $discussionid Discussion ID.
     * @return array Filtered result.
     */
    public function filter_discussion_posts(array $result, int $discussionid): array {
        if (!$this->is_company_discussion($discussionid)) {
            return ['posts' => []];
        }
        return $result;
    }

    /**
     * Validate forum access for creating discussions.
     *
     * @param int $forumid Forum ID.
     * @throws \moodle_exception If access denied.
     */
    public function validate_forum_access(int $forumid): void {
        if (!$this->is_company_forum($forumid)) {
            throw new \moodle_exception('forumnotincompany', 'local_sm_estratoos_plugin');
        }
    }

    /**
     * Validate discussion access for posting replies.
     *
     * @param int $discussionid Discussion ID.
     * @throws \moodle_exception If access denied.
     */
    public function validate_discussion_access(int $discussionid): void {
        if (!$this->is_company_discussion($discussionid)) {
            throw new \moodle_exception('discussionnotincompany', 'local_sm_estratoos_plugin');
        }
    }

    // ========================================
    // GRADE FILTERS
    // ========================================

    /**
     * Filter gradereport_user_get_grade_items results.
     *
     * @param array $result Result with 'usergrades' array.
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return array Filtered result.
     */
    public function filter_grade_items(array $result, int $courseid, int $userid): array {
        if (!$this->is_allowed_course($courseid) || !$this->is_company_user($userid)) {
            return ['usergrades' => []];
        }
        return $result;
    }

    /**
     * Filter gradereport_user_get_grade_table results.
     *
     * @param array $result Grade table result.
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @return array Filtered result.
     */
    public function filter_grade_table(array $result, int $courseid, int $userid): array {
        if (!$this->is_allowed_course($courseid) || !$this->is_company_user($userid)) {
            return ['tables' => []];
        }
        return $result;
    }

    // ========================================
    // LESSON FILTERS
    // ========================================

    /**
     * Filter mod_lesson_get_user_grade results.
     *
     * @param array $result Lesson grade result.
     * @param int $lessonid Lesson ID.
     * @return array Filtered result.
     */
    public function filter_lesson_grade(array $result, int $lessonid): array {
        if (!$this->is_company_lesson($lessonid)) {
            return [];
        }
        return $result;
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Check if a course is allowed (company + optional enrollment).
     *
     * @param int $courseid Course ID.
     * @return bool True if allowed.
     */
    public function is_allowed_course(int $courseid): bool {
        return in_array($courseid, $this->get_allowed_course_ids());
    }

    /**
     * Get allowed course IDs (public accessor).
     *
     * @return array Array of course IDs.
     */
    public function get_allowed_course_ids(): array {
        $companycourseids = $this->get_company_course_ids();

        if (!$this->restricttoenrolment) {
            return $companycourseids;
        }

        $enrolledids = $this->get_user_enrolled_course_ids();
        return array_intersect($companycourseids, $enrolledids);
    }

    /**
     * Get all assignment IDs in company courses.
     *
     * @return array Array of assignment IDs.
     */
    public function get_allowed_assignment_ids(): array {
        global $DB;

        $allowedcourseids = $this->get_allowed_course_ids();
        if (empty($allowedcourseids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($allowedcourseids, SQL_PARAMS_NAMED);
        return $DB->get_fieldset_select('assign', 'id', "course $insql", $params);
    }

    /**
     * Get all quiz IDs in company courses.
     *
     * @return array Array of quiz IDs.
     */
    public function get_allowed_quiz_ids(): array {
        global $DB;

        $allowedcourseids = $this->get_allowed_course_ids();
        if (empty($allowedcourseids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($allowedcourseids, SQL_PARAMS_NAMED);
        return $DB->get_fieldset_select('quiz', 'id', "course $insql", $params);
    }

    /**
     * Get all forum IDs in company courses.
     *
     * @return array Array of forum IDs.
     */
    public function get_allowed_forum_ids(): array {
        global $DB;

        $allowedcourseids = $this->get_allowed_course_ids();
        if (empty($allowedcourseids)) {
            return [];
        }

        list($insql, $params) = $DB->get_in_or_equal($allowedcourseids, SQL_PARAMS_NAMED);
        return $DB->get_fieldset_select('forum', 'id', "course $insql", $params);
    }

    /**
     * Check if an assignment belongs to a company course.
     *
     * @param int $assignmentid Assignment ID.
     * @return bool True if in company course.
     */
    public function is_company_assignment(int $assignmentid): bool {
        global $DB;

        $assignment = $DB->get_record('assign', ['id' => $assignmentid], 'course');
        if (!$assignment) {
            return false;
        }

        return $this->is_allowed_course($assignment->course);
    }

    /**
     * Check if a quiz belongs to a company course.
     *
     * @param int $quizid Quiz ID.
     * @return bool True if in company course.
     */
    public function is_company_quiz(int $quizid): bool {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'course');
        if (!$quiz) {
            return false;
        }

        return $this->is_allowed_course($quiz->course);
    }

    /**
     * Check if a forum belongs to a company course.
     *
     * @param int $forumid Forum ID.
     * @return bool True if in company course.
     */
    public function is_company_forum(int $forumid): bool {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $forumid], 'course');
        if (!$forum) {
            return false;
        }

        return $this->is_allowed_course($forum->course);
    }

    /**
     * Check if a discussion belongs to a company forum.
     *
     * @param int $discussionid Discussion ID.
     * @return bool True if in company forum.
     */
    public function is_company_discussion(int $discussionid): bool {
        global $DB;

        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], 'forum');
        if (!$discussion) {
            return false;
        }

        return $this->is_company_forum($discussion->forum);
    }

    /**
     * Check if a lesson belongs to a company course.
     *
     * @param int $lessonid Lesson ID.
     * @return bool True if in company course.
     */
    public function is_company_lesson(int $lessonid): bool {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $lessonid], 'course');
        if (!$lesson) {
            return false;
        }

        return $this->is_allowed_course($lesson->course);
    }
}
