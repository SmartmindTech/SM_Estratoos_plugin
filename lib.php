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
 * Library functions for local_sm_estratoos_plugin.
 *
 * Contains the callback for overriding web service execution.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** @var array Static storage for params between pre and post processor. */
global $local_sm_estratoos_plugin_params;
$local_sm_estratoos_plugin_params = [];

/**
 * Hook into web service execution to filter results by company.
 *
 * This function is called by Moodle's web service layer after a function
 * has been executed. We intercept the results and filter them based on
 * the company associated with the token.
 *
 * @param string $functionname The web service function name.
 * @param array $params The parameters passed to the function.
 * @param mixed $result The result from the web service function.
 * @return mixed Filtered result or original result.
 */
function local_sm_estratoos_plugin_pre_processor($functionname, $params) {
    // Store params for use in post_processor.
    global $local_sm_estratoos_plugin_params;
    $local_sm_estratoos_plugin_params = $params;
    return $params;
}

/**
 * Post-processor callback for web service results.
 *
 * @param string $functionname The function name.
 * @param mixed $result The result to filter.
 * @return mixed Filtered result.
 */
function local_sm_estratoos_plugin_post_processor($functionname, $result) {
    global $local_sm_estratoos_plugin_params;

    // Get the current token.
    $token = \local_sm_estratoos_plugin\util::get_current_request_token();
    if (!$token) {
        return $result;
    }

    // Check if this is a company-scoped token.
    $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
    if (!$restrictions) {
        // Not a company token, return unfiltered.
        return $result;
    }

    if (!$restrictions->restricttocompany) {
        // Company filtering is disabled for this token.
        return $result;
    }

    // Apply filtering based on function name.
    $filter = new \local_sm_estratoos_plugin\webservice_filter($restrictions);
    $params = $local_sm_estratoos_plugin_params ?? [];

    switch ($functionname) {
        // ========================================
        // COURSE FUNCTIONS
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
        // ========================================
        case 'core_user_get_users':
            return $filter->filter_users($result);

        case 'core_user_get_users_by_field':
            return $filter->filter_users_by_field($result);

        // ========================================
        // ENROLLMENT FUNCTIONS
        // ========================================
        case 'core_enrol_get_enrolled_users':
            return $filter->filter_enrolled_users($result);

        case 'core_enrol_get_users_courses':
            return $filter->filter_user_courses($result);

        // ========================================
        // COMPLETION FUNCTIONS
        // ========================================
        case 'core_completion_get_activities_completion_status':
            $courseid = $params['courseid'] ?? 0;
            $userid = $params['userid'] ?? 0;
            return $filter->filter_completion_status($result, (int)$courseid, (int)$userid);

        // ========================================
        // ASSIGNMENT FUNCTIONS
        // ========================================
        case 'mod_assign_get_assignments':
            return $filter->filter_assignments($result);

        case 'mod_assign_get_submissions':
            return $filter->filter_submissions($result);

        case 'mod_assign_get_grades':
            return $filter->filter_assignment_grades($result);

        // ========================================
        // QUIZ FUNCTIONS
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
        // ========================================
        case 'core_calendar_get_calendar_events':
            return $filter->filter_calendar_events($result);

        // ========================================
        // MESSAGING FUNCTIONS
        // ========================================
        case 'core_message_get_conversations':
            return $filter->filter_conversations($result);

        // ========================================
        // FORUM FUNCTIONS
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
        // ========================================
        case 'mod_lesson_get_user_grade':
            $lessonid = $params['lessonid'] ?? 0;
            return $filter->filter_lesson_grade($result, (int)$lessonid);

        default:
            return $result;
    }
}

/**
 * Extend navigation for admin menu.
 *
 * @param navigation_node $navigation The navigation node.
 */
function local_sm_estratoos_plugin_extend_navigation(navigation_node $navigation) {
    // Navigation extension if needed.
}

/**
 * Add settings to admin tree.
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param context $context The context.
 */
function local_sm_estratoos_plugin_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    // Settings navigation extension if needed.
}

/**
 * Hook that runs before the footer on every page.
 * Used to trigger update checks for site administrators.
 */
function local_sm_estratoos_plugin_before_footer() {
    global $CFG;

    // Only check for site administrators.
    if (!is_siteadmin()) {
        return;
    }

    // Use our custom update checker (it handles timing internally).
    try {
        require_once($CFG->dirroot . '/local/sm_estratoos_plugin/classes/update_checker.php');
        \local_sm_estratoos_plugin\update_checker::check();
    } catch (\Exception $e) {
        // Silently fail - don't break the page.
        debugging('SmartMind update check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}
