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
 * Web service hook handlers for the SmartMind Estratoos plugin.
 *
 * This class handles the pre-processor and post-processor callbacks that Moodle
 * invokes during web service execution. It implements company-scoped data filtering
 * for IOMAD multi-tenant environments.
 *
 * Flow:
 *   1. Client sends API request with a company-scoped token
 *   2. Moodle calls pre_process() BEFORE executing the web service function
 *      → Validates the token is active (not suspended due to disabled company access)
 *      → Stores the request parameters for use in post_process()
 *   3. Moodle executes the actual web service function (e.g., core_course_get_courses)
 *   4. Moodle calls post_process() AFTER execution with the raw results
 *      → Looks up the company associated with the token
 *      → Filters the results to only include data belonging to that company
 *      → Returns the filtered results to the client
 *
 * Example:
 *   A token linked to Company A calls core_course_get_courses.
 *   Moodle returns ALL courses. post_process() filters to only Company A's courses.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\hooks;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles web service pre-processing and post-processing for company-scoped filtering.
 *
 * Called by the thin delegator functions in lib.php:
 *   local_sm_estratoos_plugin_pre_processor()  → webservice_hooks::pre_process()
 *   local_sm_estratoos_plugin_post_processor() → webservice_hooks::post_process()
 */
class webservice_hooks {

    /**
     * Pre-process web service requests: validate token and store parameters.
     *
     * This runs BEFORE Moodle executes the web service function.
     * It blocks suspended tokens (company access disabled) from executing any API call.
     *
     * Flow:
     *   1. Extract token from the current HTTP request
     *   2. Check if the token is active (company access not disabled/expired)
     *   3. If suspended → throw exception (API call blocked)
     *   4. If active → store params in global for post_process() and return
     *
     * @param string $functionname The web service function being called (e.g., 'core_course_get_courses').
     * @param array $params The parameters passed to the function.
     * @return array The (unmodified) parameters.
     * @throws \moodle_exception If the token is suspended.
     */
    public static function pre_process($functionname, $params) {
        // Store params in the global so post_process() can access them.
        // This is needed because Moodle calls pre and post separately,
        // and some post-processing filters need the original request parameters
        // (e.g., courseid, userid) to determine what to filter.
        global $local_sm_estratoos_plugin_params;
        $local_sm_estratoos_plugin_params = $params;

        // Check if the token is suspended (company access disabled).
        // This blocks API calls for suspended tokens BEFORE execution,
        // saving server resources by not running the actual function.
        $token = \local_sm_estratoos_plugin\util::get_current_request_token();
        if ($token) {
            if (!\local_sm_estratoos_plugin\company_token_manager::is_token_active($token)) {
                throw new \moodle_exception('tokensuspended', 'local_sm_estratoos_plugin');
            }
        }

        return $params;
    }

    /**
     * Post-process web service results: filter by company scope.
     *
     * This runs AFTER Moodle executes the web service function.
     * It intercepts the raw results and filters them based on the company
     * associated with the token that made the request.
     *
     * Flow:
     *   1. Extract token from the current HTTP request
     *   2. Look up token restrictions (company ID, filtering settings)
     *   3. If not a company token or filtering disabled → return unfiltered results
     *   4. Create a webservice_filter instance for the company
     *   5. Route to the appropriate filter method based on the function name
     *   6. Return filtered results (only data belonging to the token's company)
     *
     * Supported function groups:
     *   - Course functions: get_courses, get_categories, get_courses_by_field, get_contents
     *   - User functions: get_users, get_users_by_field
     *   - Enrollment functions: get_enrolled_users, get_users_courses
     *   - Completion functions: get_activities_completion_status
     *   - Assignment functions: get_assignments, get_submissions, get_grades
     *   - Quiz functions: get_quizzes_by_courses, get_user_attempts, get_user_best_grade
     *   - Calendar functions: get_calendar_events
     *   - Messaging functions: get_conversations
     *   - Forum functions: get_forums, get_discussions, get_discussion_posts
     *   - Grade functions: get_grade_items, get_grades_table
     *   - Lesson functions: get_user_grade
     *
     * @param string $functionname The web service function name.
     * @param mixed $result The raw result from the web service function.
     * @return mixed Filtered result (only company-scoped data) or original result.
     */
    public static function post_process($functionname, $result) {
        global $local_sm_estratoos_plugin_params;

        // Get the current token from the HTTP request.
        $token = \local_sm_estratoos_plugin\util::get_current_request_token();
        if (!$token) {
            return $result;
        }

        // Check if this is a company-scoped token (has restrictions metadata).
        $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
        if (!$restrictions) {
            // Not a company token — return unfiltered results.
            return $result;
        }

        if (!$restrictions->restricttocompany) {
            // Company filtering is explicitly disabled for this token.
            return $result;
        }

        // Create the filter instance for this company.
        // The filter knows which courses, users, and categories belong to the company.
        $filter = new \local_sm_estratoos_plugin\webservice_filter($restrictions);
        $params = $local_sm_estratoos_plugin_params ?? [];

        // Route to the appropriate filter based on the web service function name.
        switch ($functionname) {

            // ========================================
            // COURSE FUNCTIONS
            // Filter courses to only those assigned to the company.
            // ========================================
            case 'core_course_get_courses':
                return $filter->filter_courses($result);

            case 'core_course_get_categories':
                return $filter->filter_categories($result);

            case 'core_course_get_courses_by_field':
                return $filter->filter_courses_by_field($result);

            case 'core_course_get_contents':
                $courseid = $params['courseid'] ?? 0;
                return $filter->filter_course_contents($result, (int)$courseid);

            // ========================================
            // USER FUNCTIONS
            // Filter users to only those belonging to the company.
            // ========================================
            case 'core_user_get_users':
                return $filter->filter_users($result);

            case 'core_user_get_users_by_field':
                return $filter->filter_users_by_field($result);

            // ========================================
            // ENROLLMENT FUNCTIONS
            // Filter enrolled users and user courses by company membership.
            // ========================================
            case 'core_enrol_get_enrolled_users':
                return $filter->filter_enrolled_users($result);

            case 'core_enrol_get_users_courses':
                return $filter->filter_user_courses($result);

            // ========================================
            // COMPLETION FUNCTIONS
            // Validate that the course and user belong to the company.
            // ========================================
            case 'core_completion_get_activities_completion_status':
                $courseid = $params['courseid'] ?? 0;
                $userid = $params['userid'] ?? 0;
                return $filter->filter_completion_status($result, (int)$courseid, (int)$userid);

            // ========================================
            // ASSIGNMENT FUNCTIONS
            // Filter assignments, submissions, and grades by company courses.
            // ========================================
            case 'mod_assign_get_assignments':
                return $filter->filter_assignments($result);

            case 'mod_assign_get_submissions':
                return $filter->filter_submissions($result);

            case 'mod_assign_get_grades':
                return $filter->filter_assignment_grades($result);

            // ========================================
            // QUIZ FUNCTIONS
            // Filter quizzes and attempts by company courses.
            // ========================================
            case 'mod_quiz_get_quizzes_by_courses':
                return $filter->filter_quizzes($result);

            case 'mod_quiz_get_user_attempts':
                $quizid = $params['quizid'] ?? 0;
                return $filter->filter_quiz_attempts($result, (int)$quizid);

            case 'mod_quiz_get_user_best_grade':
                $quizid = $params['quizid'] ?? 0;
                return $filter->filter_quiz_grade($result, (int)$quizid);

            // ========================================
            // CALENDAR FUNCTIONS
            // Filter events to company courses only.
            // ========================================
            case 'core_calendar_get_calendar_events':
                return $filter->filter_calendar_events($result);

            // ========================================
            // MESSAGING FUNCTIONS
            // Filter conversations to company users only.
            // ========================================
            case 'core_message_get_conversations':
                return $filter->filter_conversations($result);

            // ========================================
            // FORUM FUNCTIONS
            // Filter forums, discussions, and posts by company courses.
            // ========================================
            case 'mod_forum_get_forums_by_courses':
                return $filter->filter_forums($result);

            case 'mod_forum_get_forum_discussions':
                $forumid = $params['forumid'] ?? 0;
                return $filter->filter_discussions($result, (int)$forumid);

            case 'mod_forum_get_discussion_posts':
                $discussionid = $params['discussionid'] ?? 0;
                return $filter->filter_discussion_posts($result, (int)$discussionid);

            // ========================================
            // GRADE FUNCTIONS
            // Filter grade items and grade tables by company courses/users.
            // ========================================
            case 'gradereport_user_get_grade_items':
                $courseid = $params['courseid'] ?? 0;
                $userid = $params['userid'] ?? 0;
                return $filter->filter_grade_items($result, (int)$courseid, (int)$userid);

            case 'gradereport_user_get_grades_table':
                $courseid = $params['courseid'] ?? 0;
                $userid = $params['userid'] ?? 0;
                return $filter->filter_grade_table($result, (int)$courseid, (int)$userid);

            // ========================================
            // LESSON FUNCTIONS
            // Filter lesson grades by company courses.
            // ========================================
            case 'mod_lesson_get_user_grade':
                $lessonid = $params['lessonid'] ?? 0;
                return $filter->filter_lesson_grade($result, (int)$lessonid);

            // ========================================
            // UNHANDLED FUNCTIONS
            // Functions not listed above pass through unfiltered.
            // ========================================
            default:
                return $result;
        }
    }
}
