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
 * This file contains Moodle hook callback functions that MUST remain here
 * because Moodle discovers them by naming convention (local_{plugin}_{hookname}).
 *
 * All function bodies have been extracted into autoloaded class files under classes/.
 * Each function here is a thin delegator — it calls a static method on the
 * corresponding class and returns the result. Moodle's PSR-0 autoloader handles
 * class loading automatically (no require_once needed).
 *
 * Delegation map:
 *   lib.php function                                    → Class method
 *   ─────────────────────────────────────────────────────────────────────────────
 *   local_sm_estratoos_plugin_pre_processor()           → hooks\webservice_hooks::pre_process()
 *   local_sm_estratoos_plugin_post_processor()          → hooks\webservice_hooks::post_process()
 *   local_sm_estratoos_plugin_extend_navigation()       → hooks\navigation_hooks::extend_navigation()
 *   local_sm_estratoos_plugin_extend_settings_navigation() → hooks\navigation_hooks::extend_settings_navigation()
 *   local_sm_estratoos_plugin_before_footer()           → (orchestrator — calls multiple classes)
 *   local_sm_estratoos_plugin_get_scorm_slidecount()    → scorm\slidecount::detect()
 *   local_sm_estratoos_plugin_get_postmessage_tracking_js() → scorm\tracking_js::get_script()
 *   local_sm_estratoos_plugin_get_embed_css_js()        → scorm\embed_assets::get_css_js()
 *   local_sm_estratoos_plugin_get_activity_embed_css_js() → activity_embed_assets::get_css_js()
 *   local_sm_estratoos_plugin_render_navbar_output()    → hooks\navbar_hooks::render_navbar_output()
 *   local_sm_estratoos_plugin_before_standard_top_of_body_html() → hooks\navbar_hooks::before_standard_top_of_body_html()
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @var array Static storage for web service parameters between pre and post processor.
 *
 * Moodle calls pre_processor() and post_processor() separately during web service
 * execution. The pre_processor stores the request parameters here so the post_processor
 * can use them to filter results (e.g., courseid, userid).
 */
global $local_sm_estratoos_plugin_params;
$local_sm_estratoos_plugin_params = [];

// ============================================================
// WEB SERVICE HOOKS
// Called by Moodle's web service layer before/after function execution.
// ============================================================

/**
 * Pre-processor: validate token and store parameters before web service execution.
 *
 * @param string $functionname The web service function name.
 * @param array $params The parameters passed to the function.
 * @return array The (unmodified) parameters.
 * @throws \moodle_exception If the token is suspended.
 */
function local_sm_estratoos_plugin_pre_processor($functionname, $params) {
    return \local_sm_estratoos_plugin\hooks\webservice_hooks::pre_process($functionname, $params);
}

/**
 * Post-processor: filter web service results by company scope.
 *
 * @param string $functionname The web service function name.
 * @param mixed $result The result from the web service function.
 * @return mixed Filtered result or original result.
 */
function local_sm_estratoos_plugin_post_processor($functionname, $result) {
    return \local_sm_estratoos_plugin\hooks\webservice_hooks::post_process($functionname, $result);
}

// ============================================================
// NAVIGATION HOOKS
// Called by Moodle on every page load for authenticated users.
// ============================================================

/**
 * Extend global navigation: add plugin to sidebar and handle post-install redirect.
 *
 * @param global_navigation $navigation The navigation node.
 */
function local_sm_estratoos_plugin_extend_navigation(global_navigation $navigation) {
    \local_sm_estratoos_plugin\hooks\navigation_hooks::extend_navigation($navigation);
}

/**
 * Extend settings navigation (placeholder for future use).
 *
 * @param settings_navigation $settingsnav The settings navigation.
 * @param context $context The context.
 */
function local_sm_estratoos_plugin_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    \local_sm_estratoos_plugin\hooks\navigation_hooks::extend_settings_navigation($settingsnav, $context);
}

// ============================================================
// PAGE HOOKS
// Called before the footer on every page — injects SCORM tracking,
// embed assets for SCORM, and embed assets for other activities.
// ============================================================

/**
 * Before footer hook: inject embed CSS and SCORM tracking JS.
 *
 * Flow:
 *   1. Check if current page is /mod/scorm/player.php
 *   2. If yes AND embed cookie is set → inject SCORM embed CSS/JS (hides SCORM nav)
 *   3. If yes → always inject PostMessage tracking JS (real-time progress)
 *   4. If NOT SCORM AND embed cookie is set AND page is /mod/* → inject activity embed CSS
 *      (quiz pages get special treatment: right drawer kept for question navigation)
 *   5. For site admins → run update checker
 */
function local_sm_estratoos_plugin_before_footer() {
    global $CFG, $PAGE, $DB;

    // Check if we're on the SCORM player page.
    $pagepath = $PAGE->url->get_path() ?? '';
    $isscormplayer = strpos($pagepath, '/mod/scorm/player.php') !== false;

    if ($isscormplayer) {
        // Inject embed CSS if in SmartLearning embed mode (cookie set by embed.php).
        if (!empty($_COOKIE['sm_estratoos_embed'])) {
            echo \local_sm_estratoos_plugin\scorm\embed_assets::get_css_js();
        }

        // Always inject PostMessage tracking script for real-time progress updates.
        // SCORM player URL uses 'a' parameter for SCORM instance ID.
        $scormid = optional_param('a', 0, PARAM_INT);

        // Look up the course module ID from the SCORM instance ID.
        $cmid = 0;
        $slidescount = 0;
        if ($scormid > 0) {
            $cm = $DB->get_record_sql(
                "SELECT cm.id
                 FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
                 WHERE cm.instance = :instance",
                ['instance' => $scormid]
            );
            if ($cm) {
                $cmid = (int)$cm->id;
                $slidescount = \local_sm_estratoos_plugin\scorm\slidecount::detect($cmid, $scormid);
            }
        }

        echo \local_sm_estratoos_plugin\scorm\tracking_js::get_script($cmid, $scormid, $slidescount);
    }

    // ============================================================
    // NON-SCORM ACTIVITY EMBED MODE
    // Hide Moodle Boost chrome for all /mod/ pages when in embed mode.
    // Quiz pages get special treatment: right drawer kept for question navigation.
    // ============================================================
    if (!$isscormplayer && !empty($_COOKIE['sm_estratoos_embed'])) {
        if (strpos($pagepath, '/mod/') !== false) {
            $isQuizPage = (strpos($pagepath, '/mod/quiz/') !== false);
            if ($isQuizPage) {
                echo \local_sm_estratoos_plugin\activity_embed_assets::get_quiz_css_js();
            } else {
                echo \local_sm_estratoos_plugin\activity_embed_assets::get_css_js();
            }

            // Activity position tracking JS for quiz, book, and lesson.
            // Sends scorm-progress postMessages for frontend position bar, tagging, go-back.
            $cmid = optional_param('id', 0, PARAM_INT);
            if (!$cmid) {
                $cmid = optional_param('cmid', 0, PARAM_INT);
            }

            if ($cmid > 0) {
                $cm = get_coursemodule_from_id('', $cmid);
                if ($cm) {
                    switch ($cm->modname) {
                        case 'quiz':
                            // Only on attempt.php and review.php (not view.php).
                            if (strpos($pagepath, '/attempt.php') !== false
                                || strpos($pagepath, '/review.php') !== false) {
                                echo \local_sm_estratoos_plugin\activity\tracking_js::get_quiz_script(
                                    $cmid, (int)$cm->instance);
                            }
                            break;
                        case 'book':
                            echo \local_sm_estratoos_plugin\activity\tracking_js::get_book_script(
                                $cmid, (int)$cm->instance);
                            break;
                        case 'lesson':
                            echo \local_sm_estratoos_plugin\activity\tracking_js::get_lesson_script(
                                $cmid, (int)$cm->instance);
                            break;
                    }
                }
            }
        }
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

// ============================================================
// SCORM HELPER DELEGATORS
// Thin wrappers that delegate to classes/scorm/ for reuse.
// ============================================================

/**
 * Detect the total number of slides in a SCORM package.
 *
 * @param int $cmid Course module ID.
 * @param int $scormid SCORM instance ID.
 * @return int Slide count (minimum 1).
 */
function local_sm_estratoos_plugin_get_scorm_slidecount($cmid, $scormid) {
    return \local_sm_estratoos_plugin\scorm\slidecount::detect($cmid, $scormid);
}

/**
 * Get the PostMessage tracking JavaScript for real-time SCORM progress.
 *
 * @param int $cmid Course module ID.
 * @param int $scormid SCORM instance ID.
 * @param int $slidescount Total slides count.
 * @return string Complete HTML <script> block.
 */
function local_sm_estratoos_plugin_get_postmessage_tracking_js($cmid, $scormid, $slidescount) {
    return \local_sm_estratoos_plugin\scorm\tracking_js::get_script($cmid, $scormid, $slidescount);
}

/**
 * Get the CSS and JS to hide SCORM navigation in embed mode.
 *
 * @return string HTML with <style> and <script> tags.
 */
function local_sm_estratoos_plugin_get_embed_css_js() {
    return \local_sm_estratoos_plugin\scorm\embed_assets::get_css_js();
}

// ============================================================
// NAVBAR HOOKS
// Called by Boost theme to render additional navbar elements.
// ============================================================

/**
 * Render the Token Manager icon for the Boost navbar.
 *
 * @param \renderer_base $renderer The Moodle renderer.
 * @return string HTML for the navbar icon.
 */
function local_sm_estratoos_plugin_render_navbar_output(\renderer_base $renderer) {
    return \local_sm_estratoos_plugin\hooks\navbar_hooks::render_navbar_output($renderer);
}

/**
 * Inject JavaScript to reposition the Token Manager icon next to the notification bell.
 *
 * @return string HTML/JS to inject at top of <body>.
 */
function local_sm_estratoos_plugin_before_standard_top_of_body_html() {
    return \local_sm_estratoos_plugin\hooks\navbar_hooks::before_standard_top_of_body_html();
}
