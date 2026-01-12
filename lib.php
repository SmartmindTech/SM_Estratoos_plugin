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
    // This callback is called before web service execution.
    // We don't need to do anything here.
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

    switch ($functionname) {
        case 'core_course_get_courses':
            return $filter->filter_courses($result);

        case 'core_course_get_categories':
            return $filter->filter_categories($result);

        case 'core_user_get_users':
            return $filter->filter_users($result);

        case 'core_user_get_users_by_field':
            return $filter->filter_users_by_field($result);

        case 'core_enrol_get_enrolled_users':
            return $filter->filter_enrolled_users($result);

        case 'core_enrol_get_users_courses':
            return $filter->filter_user_courses($result);

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

    // Get the update check interval (default: 60 seconds = 1 minute).
    $checkinterval = get_config('local_sm_estratoos_plugin', 'update_check_interval');
    if ($checkinterval === false) {
        $checkinterval = 60; // Default 1 minute.
    }

    // Get last check time.
    $lastcheck = get_config('local_sm_estratoos_plugin', 'last_update_check');
    if ($lastcheck === false) {
        $lastcheck = 0;
    }

    // Check if enough time has passed.
    if (time() - $lastcheck < $checkinterval) {
        return;
    }

    // Update the last check time.
    set_config('last_update_check', time(), 'local_sm_estratoos_plugin');

    // Trigger Moodle's update checker.
    if (class_exists('\core\update\checker')) {
        try {
            $checker = \core\update\checker::instance();
            $checker->fetch();
        } catch (\Exception $e) {
            // Silently fail - don't break the page.
            debugging('SmartMind update check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
