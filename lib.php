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

    // Check if the token is suspended (company access disabled).
    // This blocks API calls for suspended tokens BEFORE execution.
    $token = \local_sm_estratoos_plugin\util::get_current_request_token();
    if ($token) {
        if (!\local_sm_estratoos_plugin\company_token_manager::is_token_active($token)) {
            throw new \moodle_exception('tokensuspended', 'local_sm_estratoos_plugin');
        }
    }

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
 * Adds the SmartMind Token Manager to the navigation for:
 * - Site administrators
 * - IOMAD company managers (managertype > 0)
 *
 * @param global_navigation $navigation The navigation node.
 */
function local_sm_estratoos_plugin_extend_navigation(global_navigation $navigation) {
    global $CFG, $PAGE;

    // Check for redirect flag after install/upgrade (only for site admins).
    if (is_siteadmin() && !defined('ABORT_AFTER_CONFIG') && !CLI_SCRIPT) {
        $redirectflag = get_config('local_sm_estratoos_plugin', 'redirect_to_dashboard');
        if ($redirectflag) {
            // Only redirect if flag was set within last 5 minutes (upgrade can take time).
            if ((time() - $redirectflag) < 300) {
                // Don't redirect if already on the plugin dashboard or during AJAX/API calls.
                $currenturl = $PAGE->url->get_path();
                $ispluginpage = strpos($currenturl, '/local/sm_estratoos_plugin/') !== false;
                $iswebservice = strpos($currenturl, '/webservice/') !== false;
                $isajax = defined('AJAX_SCRIPT') && AJAX_SCRIPT;

                // Block redirect during upgrade process pages (but allow admin/index.php which is the post-upgrade landing).
                $isupgradepage = strpos($currenturl, '/admin/upgradesettings.php') !== false
                    || strpos($currenturl, '/admin/environment.php') !== false
                    || (strpos($currenturl, '/admin/index.php') !== false && optional_param('cache', 0, PARAM_INT));

                // Redirect from admin/index.php (post-upgrade) or any non-admin page.
                if (!$ispluginpage && !$iswebservice && !$isajax && !$isupgradepage) {
                    // Clear the flag first to prevent loops.
                    unset_config('redirect_to_dashboard', 'local_sm_estratoos_plugin');
                    // Redirect to plugin dashboard.
                    redirect(new moodle_url('/local/sm_estratoos_plugin/index.php'));
                }
            } else {
                // Clear stale flag.
                unset_config('redirect_to_dashboard', 'local_sm_estratoos_plugin');
            }
        }
    }

    // Check if user has access (site admin or company manager).
    if (!\local_sm_estratoos_plugin\util::is_token_admin()) {
        return;
    }

    // Add to flat navigation (appears in left sidebar/drawer).
    $url = new moodle_url('/local/sm_estratoos_plugin/index.php');
    $nodename = get_string('pluginname', 'local_sm_estratoos_plugin');

    // Add to the site administration node if it exists.
    $siteadmin = $navigation->find('siteadministration', navigation_node::TYPE_SITE_ADMIN);
    if ($siteadmin) {
        $siteadmin->add(
            $nodename,
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'sm_estratoos_plugin',
            new pix_icon('i/settings', '')
        );
    }

    // Also add as a top-level node for easier access.
    $node = $navigation->add(
        $nodename,
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'sm_estratoos_plugin_main',
        new pix_icon('i/settings', '')
    );
    $node->showinflatnavigation = true;
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
 * Used to trigger update checks for site administrators and inject embed CSS for SCORM.
 */
function local_sm_estratoos_plugin_before_footer() {
    global $CFG, $PAGE;

    // Inject CSS for SCORM player when accessed via embed endpoint.
    $pagepath = $PAGE->url->get_path() ?? '';
    $isscormplayer = strpos($pagepath, '/mod/scorm/player.php') !== false;

    if ($isscormplayer && !empty($_COOKIE['sm_estratoos_embed'])) {
        echo local_sm_estratoos_plugin_get_embed_css_js();
    }

    // Update check for site administrators.
    if (!is_siteadmin()) {
        return;
    }

    try {
        require_once($CFG->dirroot . '/local/sm_estratoos_plugin/classes/update_checker.php');
        \local_sm_estratoos_plugin\update_checker::check();
    } catch (\Exception $e) {
        debugging('SmartMind update check failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Get the CSS and JS to hide SCORM navigation in embed mode.
 *
 * @return string HTML with style and script tags.
 */
function local_sm_estratoos_plugin_get_embed_css_js() {
    return <<<HTML
<style type="text/css">
/* Hide SCORM navigation when in SmartLearning embed mode */
/* IMPORTANT: Do NOT hide #tocbox or #toctree - they contain the content! */

/* Hide the top bar with TOC dropdown */
#scormtop {
    display: none !important;
}

/* Hide the SCO navigation dropdown */
#scormnav,
.scorm-right {
    display: none !important;
}

/* Hide the TOC sidebar (left panel) but NOT its parent containers */
#scorm_toc {
    display: none !important;
}

/* Hide TOC toggle button */
#scorm_toc_toggle,
#scorm_toc_toggle_btn {
    display: none !important;
}

/* Hide the floating navigation panel */
#scorm_navpanel {
    display: none !important;
}

/* Hide any visible toast/notifications */
.toast-wrapper {
    display: none !important;
}

/* Make the left column in layout invisible */
#scorm_layout > .yui3-u-1-5 {
    display: none !important;
    width: 0 !important;
}

/* Make html and body fill viewport */
html, body {
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    height: 100% !important;
    overflow: hidden !important;
}

/* Remove all Moodle wrapper padding/margins and make them fill viewport */
#page, .embedded-main, [role="main"] {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    width: 100% !important;
    height: 100% !important;
}

/* SCORM container chain - all must fill viewport with absolute positioning */
#scormpage, #tocbox, #toctree, #scorm_layout {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Make the SCORM content area fill the entire viewport */
#scorm_content {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Make the SCORM iframe fill its container */
#scorm_object,
.scoframe,
#scorm_content iframe {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    border: none !important;
}
</style>
<script>
// Apply embed mode styles via JavaScript
(function() {
    document.body.classList.add('sm-embed-mode');

    // Hide only navigation elements (NOT content containers)
    ['scormtop', 'scormnav', 'scorm_toc', 'scorm_toc_toggle', 'scorm_toc_toggle_btn', 'scorm_navpanel'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // Hide .scorm-right elements
    document.querySelectorAll('.scorm-right').forEach(function(el) {
        el.style.display = 'none';
    });

    // Make content full width
    var content = document.getElementById('scorm_content');
    if (content) {
        content.style.width = '100%';
        content.style.left = '0';
        content.style.marginLeft = '0';
    }
})();
</script>
HTML;
}

/**
 * Add token manager icon to the navbar (next to notification bell).
 *
 * This hook is called by Boost-based themes to render additional navbar output.
 * Returns the icon HTML, and JavaScript repositions it to the left of the bell.
 *
 * @param renderer_base $renderer The renderer.
 * @return string HTML for the navbar icon.
 */
function local_sm_estratoos_plugin_render_navbar_output(\renderer_base $renderer) {
    global $CFG;

    // Check if user has access (site admin or company manager).
    if (!\local_sm_estratoos_plugin\util::is_token_admin()) {
        return '';
    }

    $url = new moodle_url('/local/sm_estratoos_plugin/index.php');
    $title = get_string('pluginname', 'local_sm_estratoos_plugin');

    // Create the icon container matching the notification bell structure.
    $html = \html_writer::start_div('popover-region', ['id' => 'sm-tokens-navbar-icon', 'style' => 'display: flex; align-items: center;']);
    $html .= \html_writer::link(
        $url,
        \html_writer::tag('i', '', ['class' => 'icon fa fa-key fa-fw', 'aria-hidden' => 'true']),
        [
            'class' => 'nav-link position-relative icon-no-margin',
            'title' => $title,
            'aria-label' => $title,
        ]
    );
    $html .= \html_writer::end_div();

    return $html;
}

/**
 * Inject JavaScript to reposition the token icon to the left of the notification bell.
 *
 * @return string HTML/JS to inject.
 */
function local_sm_estratoos_plugin_before_standard_top_of_body_html() {
    // Check if user has access (site admin or company manager).
    if (!\local_sm_estratoos_plugin\util::is_token_admin()) {
        return '';
    }

    // Don't add on AJAX requests or web service calls.
    if (defined('AJAX_SCRIPT') || defined('WS_SERVER')) {
        return '';
    }

    // JavaScript to move the icon to the LEFT of the notification bell.
    $js = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Find our icon (rendered by render_navbar_output).
    var tokenIcon = document.getElementById('sm-tokens-navbar-icon');
    if (!tokenIcon) {
        return;
    }

    // Find the notification bell container.
    var notificationBell = document.querySelector('.popover-region-notifications');
    if (!notificationBell) {
        return;
    }

    // Move the token icon to be immediately before the notification bell.
    notificationBell.parentNode.insertBefore(tokenIcon, notificationBell);
});
</script>
JS;

    return $js;
}
