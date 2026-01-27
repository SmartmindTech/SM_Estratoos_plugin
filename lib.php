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
 * Used to trigger update checks for site administrators, inject embed CSS for SCORM,
 * and inject PostMessage tracking script for real-time SCORM progress.
 */
function local_sm_estratoos_plugin_before_footer() {
    global $CFG, $PAGE, $DB;

    // Inject CSS for SCORM player when accessed via embed endpoint.
    $pagepath = $PAGE->url->get_path() ?? '';
    $isscormplayer = strpos($pagepath, '/mod/scorm/player.php') !== false;

    if ($isscormplayer) {
        // Inject embed CSS if in embed mode.
        if (!empty($_COOKIE['sm_estratoos_embed'])) {
            echo local_sm_estratoos_plugin_get_embed_css_js();
        }

        // Always inject PostMessage tracking script for real-time progress updates.
        // This allows SmartLearning to receive slide changes without polling.
        // SCORM player URL uses 'a' parameter for SCORM instance ID (not 'cm' or 'id').
        $scormid = optional_param('a', 0, PARAM_INT);
        $scoid = optional_param('scoid', 0, PARAM_INT);

        // Get cmid from course_modules table using scorm instance ID.
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
                $slidescount = local_sm_estratoos_plugin_get_scorm_slidecount($cmid, $scormid);
            }
        }

        echo local_sm_estratoos_plugin_get_postmessage_tracking_js($cmid, $scormid, $slidescount);
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
 * Get slide count for a SCORM module.
 *
 * @param int $cmid Course module ID.
 * @param int $scormid SCORM instance ID.
 * @return int Slide count.
 */
function local_sm_estratoos_plugin_get_scorm_slidecount($cmid, $scormid) {
    global $DB;

    // First try SCO count.
    $scocount = $DB->count_records('scorm_scoes', ['scorm' => $scormid, 'scormtype' => 'sco']);

    // Try to detect slides from content files.
    try {
        $context = context_module::instance($cmid);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_scorm', 'content', 0, 'sortorder', false);

        $slidenumbers = [];
        $slidesxmlfile = null;

        foreach ($files as $file) {
            $path = $file->get_filepath() . $file->get_filename();

            // Articulate Storyline: story_content/slideXXX.xml
            if (preg_match('#/story_content/slide(\d+)\.xml$#i', $path, $m)) {
                $slidenumbers[$m[1]] = true;
            }
            // Generic slide files.
            if (preg_match('#/(?:res/data|slides|content|data)/slide(\d+)\.(js|html|css)$#i', $path, $m)) {
                $slidenumbers[$m[1]] = true;
            }

            // Keep track of slides.xml for Storyline.
            if ($path === '/story_content/slides.xml') {
                $slidesxmlfile = $file;
            }
        }

        if (!empty($slidenumbers)) {
            return count($slidenumbers);
        }

        // Try reading slides.xml for Storyline.
        if ($slidesxmlfile) {
            $content = $slidesxmlfile->get_content();
            $count = preg_match_all('/<sld\s/i', $content);
            if ($count > 0) {
                return $count;
            }
        }
    } catch (\Exception $e) {
        debugging('Error detecting SCORM slides: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    return $scocount ?: 1;
}

/**
 * Get PostMessage tracking JavaScript for real-time SCORM progress.
 *
 * @param int $cmid Course module ID.
 * @param int $scormid SCORM instance ID.
 * @param int $slidescount Total slides count.
 * @return string JavaScript code.
 */
function local_sm_estratoos_plugin_get_postmessage_tracking_js($cmid, $scormid, $slidescount) {
    return <<<JS
<script>
(function() {
    var cmid = {$cmid};
    var scormid = {$scormid};
    var slidescount = {$slidescount};
    var lastLocation = null;
    var lastStatus = null;
    var lastSlide = null;
    var lastSuspendData = null;

    // Function to parse slide number from lesson_location.
    function parseSlideNumber(location) {
        if (!location || location === '') return null;

        // Pure number: "5"
        if (/^\d+$/.test(location)) {
            return parseInt(location, 10);
        }
        // Trailing number: "slide_5", "scene1_slide5"
        var match = location.match(/(\d+)$/);
        if (match) {
            return parseInt(match[1], 10);
        }
        // Fraction format: "5/10"
        match = location.match(/^(\d+)\//);
        if (match) {
            return parseInt(match[1], 10);
        }
        // Articulate format: "slide5" or "#/slides/xxx"
        match = location.match(/slide(\d+)/i);
        if (match) {
            return parseInt(match[1], 10);
        }
        return null;
    }

    // Function to parse slide from suspend_data (multiple vendor formats).
    function parseSlideFromSuspendData(data) {
        if (!data) return null;

        try {
            // 1. Try JSON parse (iSpring, some custom tools).
            var parsed = JSON.parse(data);
            if (parsed.currentSlide !== undefined) return parseInt(parsed.currentSlide, 10);
            if (parsed.slide !== undefined) return parseInt(parsed.slide, 10);
            if (parsed.current !== undefined) return parseInt(parsed.current, 10);
            if (parsed.position !== undefined) return parseInt(parsed.position, 10);
            if (parsed.resume !== undefined) {
                // Articulate format: resume might contain slide ref.
                var match = parsed.resume.match(/(\d+)/);
                if (match) return parseInt(match[1], 10);
            }
            // Check for nested structures.
            if (parsed.v && parsed.v.current !== undefined) return parseInt(parsed.v.current, 10);
            if (parsed.data && parsed.data.slide !== undefined) return parseInt(parsed.data.slide, 10);
        } catch (e) {
            // Not JSON, try other patterns.
        }

        // 2. Articulate Storyline Base64 pattern - look for slide index in decoded content.
        if (data.match(/^[A-Za-z0-9+/=]{20,}$/)) {
            try {
                var decoded = atob(data);
                // Look for slide patterns in decoded data.
                var match = decoded.match(/slide[_\-]?(\d+)/i);
                if (match) return parseInt(match[1], 10);
                // Look for scene_slide pattern.
                match = decoded.match(/(\d+)[_\-](\d+)/);
                if (match) return parseInt(match[2], 10);
            } catch (e) {
                // Not valid Base64.
            }
        }

        // 3. URL-encoded format (Adobe Captivate style).
        if (data.indexOf('=') !== -1 && data.indexOf('&') !== -1) {
            try {
                var params = new URLSearchParams(data);
                if (params.has('slide')) return parseInt(params.get('slide'), 10);
                if (params.has('current')) return parseInt(params.get('current'), 10);
                if (params.has('page')) return parseInt(params.get('page'), 10);
            } catch (e) {
                // URLSearchParams not supported or invalid data.
            }
        }

        // 4. Articulate Storyline pattern: look for slide numbers in raw string.
        var match = data.match(/["']?(?:slide|currentSlide|resume|current|position)["']?\s*[:=]\s*["']?(\d+)/i);
        if (match) return parseInt(match[1], 10);

        // 5. Look for scene/slide pattern (scene_slide format) - but NOT in Base64 data.
        // Only apply if the data doesn't look like Base64.
        if (!data.match(/^[A-Za-z0-9+/=]{20,}$/)) {
            match = data.match(/(\d+)[_\-](\d+)/);
            if (match) return parseInt(match[2], 10);
        }

        // If we can't parse suspend_data, return null.
        // The score-based calculation will be used as fallback.
        return null;
    }

    // Function to send progress to parent window.
    function sendProgressUpdate(location, status, score, directSlide) {
        var currentSlide = directSlide || parseSlideNumber(location) || lastSlide;

        // Update lastSlide if we have a new value.
        if (currentSlide !== null) {
            lastSlide = currentSlide;
        }

        // Build message object.
        var message = {
            type: 'scorm-progress',
            cmid: cmid,
            scormid: scormid,
            currentSlide: currentSlide,
            totalSlides: slidescount,
            lessonLocation: location || lastLocation,
            lessonStatus: status || lastStatus,
            score: score,
            timestamp: Date.now()
        };

        // Calculate progress percentage if we have slide info.
        if (currentSlide !== null && slidescount > 0) {
            message.progressPercent = Math.round((currentSlide / slidescount) * 100);
        }

        // Send to parent (SmartLearning app).
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(message, '*');
        }
        // Also try top window in case of nested iframes.
        if (window.top && window.top !== window && window.top !== window.parent) {
            window.top.postMessage(message, '*');
        }
    }

    // Wait for SCORM API to be available, then wrap it.
    function wrapScormApi() {
        // SCORM 1.2 API
        if (typeof window.API !== 'undefined' && window.API.LMSSetValue) {
            var originalSetValue = window.API.LMSSetValue;
            window.API.LMSSetValue = function(element, value) {
                var result = originalSetValue.call(window.API, element, value);

                // DEBUG: Log all SCORM API calls to understand what the content sends.
                console.log('[SCORM 1.2] LMSSetValue:', element, '=', value && value.substring ? value.substring(0, 200) : value);

                // Track lesson_location changes.
                if (element === 'cmi.core.lesson_location' && value !== lastLocation) {
                    lastLocation = value;
                    sendProgressUpdate(value, lastStatus, null, null);
                }
                // Track lesson_status changes.
                if (element === 'cmi.core.lesson_status') {
                    lastStatus = value;
                    sendProgressUpdate(lastLocation, value, null, null);
                }
                // Track score changes - some SCORM packages use score as progress percentage.
                if (element === 'cmi.core.score.raw') {
                    var score = parseFloat(value);
                    if (!isNaN(score) && slidescount > 0 && score <= 100) {
                        // Score represents percentage, calculate current slide.
                        var calculatedSlide = Math.round((score / 100) * slidescount);
                        calculatedSlide = Math.max(1, Math.min(calculatedSlide, slidescount));
                        sendProgressUpdate(null, lastStatus, value, calculatedSlide);
                    } else {
                        sendProgressUpdate(lastLocation, lastStatus, value, null);
                    }
                }
                // Track suspend_data changes (Articulate Storyline stores slide position here).
                if (element === 'cmi.suspend_data' && value !== lastSuspendData) {
                    lastSuspendData = value;
                    var slideNum = parseSlideFromSuspendData(value);
                    if (slideNum !== null && slideNum !== lastSlide) {
                        sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
                    }
                }

                return result;
            };
            return true;
        }

        // SCORM 2004 API
        if (typeof window.API_1484_11 !== 'undefined' && window.API_1484_11.SetValue) {
            var originalSetValue2004 = window.API_1484_11.SetValue;
            window.API_1484_11.SetValue = function(element, value) {
                var result = originalSetValue2004.call(window.API_1484_11, element, value);

                // DEBUG: Log all SCORM API calls to understand what the content sends.
                console.log('[SCORM 2004] SetValue:', element, '=', value && value.substring ? value.substring(0, 200) : value);

                // Track location changes.
                if (element === 'cmi.location' && value !== lastLocation) {
                    lastLocation = value;
                    sendProgressUpdate(value, lastStatus, null, null);
                }
                // Track completion_status changes.
                if (element === 'cmi.completion_status') {
                    lastStatus = value;
                    sendProgressUpdate(lastLocation, value, null, null);
                }
                // Track score changes - some SCORM packages use score as progress percentage.
                if (element === 'cmi.score.raw') {
                    var score = parseFloat(value);
                    if (!isNaN(score) && slidescount > 0 && score <= 100) {
                        // Score represents percentage, calculate current slide.
                        var calculatedSlide = Math.round((score / 100) * slidescount);
                        calculatedSlide = Math.max(1, Math.min(calculatedSlide, slidescount));
                        sendProgressUpdate(null, lastStatus, value, calculatedSlide);
                    } else {
                        sendProgressUpdate(lastLocation, lastStatus, value, null);
                    }
                }
                // Track suspend_data changes (Articulate Storyline stores slide position here).
                if (element === 'cmi.suspend_data' && value !== lastSuspendData) {
                    lastSuspendData = value;
                    var slideNum = parseSlideFromSuspendData(value);
                    if (slideNum !== null && slideNum !== lastSlide) {
                        sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
                    }
                }

                return result;
            };
            return true;
        }

        return false;
    }

    // Try to wrap immediately, then retry with intervals.
    if (!wrapScormApi()) {
        var attempts = 0;
        var interval = setInterval(function() {
            attempts++;
            if (wrapScormApi() || attempts > 50) {
                clearInterval(interval);
            }
        }, 200);
    }

    // Send initial progress message when page loads.
    setTimeout(function() {
        sendProgressUpdate(null, null, null, null);
    }, 1000);

    // Fallback: Poll the SCORM API for current position every 2 seconds.
    // Some SCORM content doesn't call SetValue on navigation.
    var pollInterval = setInterval(function() {
        var currentLocation = null;
        var currentSuspendData = null;

        // Try SCORM 1.2
        if (window.API && window.API.LMSGetValue) {
            try {
                currentLocation = window.API.LMSGetValue('cmi.core.lesson_location');
                currentSuspendData = window.API.LMSGetValue('cmi.suspend_data');
            } catch (e) {}
        }
        // Try SCORM 2004
        else if (window.API_1484_11 && window.API_1484_11.GetValue) {
            try {
                currentLocation = window.API_1484_11.GetValue('cmi.location');
                currentSuspendData = window.API_1484_11.GetValue('cmi.suspend_data');
            } catch (e) {}
        }

        // Check if location changed.
        if (currentLocation && currentLocation !== lastLocation) {
            lastLocation = currentLocation;
            console.log('[SCORM Poll] Location changed:', currentLocation);
            sendProgressUpdate(currentLocation, lastStatus, null, null);
        }

        // Check if suspend_data changed.
        if (currentSuspendData && currentSuspendData !== lastSuspendData) {
            lastSuspendData = currentSuspendData;
            var slideNum = parseSlideFromSuspendData(currentSuspendData);
            if (slideNum !== null && slideNum !== lastSlide) {
                console.log('[SCORM Poll] Detected slide change from suspend_data:', slideNum);
                sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
            }
        }
    }, 2000);

    // Clean up polling when page unloads.
    window.addEventListener('beforeunload', function() {
        clearInterval(pollInterval);
    });

    // Listen for internal navigation events (Storyline, Captivate, etc.).
    window.addEventListener('message', function(event) {
        // Some SCORM content sends internal navigation messages.
        if (event.data && typeof event.data === 'object') {
            if (event.data.slide !== undefined) {
                var slideNum = parseInt(event.data.slide, 10);
                if (!isNaN(slideNum) && slideNum !== lastSlide) {
                    console.log('[SCORM Internal] Detected slide from message:', slideNum);
                    sendProgressUpdate(null, null, null, slideNum);
                }
            }
        }
    }, false);

    // For Articulate Storyline: watch for slide change via DOM mutation.
    if (typeof MutationObserver !== 'undefined') {
        var mutationDebounce = null;
        var observer = new MutationObserver(function(mutations) {
            // Debounce to avoid excessive polling.
            if (mutationDebounce) return;
            mutationDebounce = setTimeout(function() {
                mutationDebounce = null;

                var currentSuspendData = null;
                if (window.API && window.API.LMSGetValue) {
                    try {
                        currentSuspendData = window.API.LMSGetValue('cmi.suspend_data');
                    } catch (e) {}
                } else if (window.API_1484_11 && window.API_1484_11.GetValue) {
                    try {
                        currentSuspendData = window.API_1484_11.GetValue('cmi.suspend_data');
                    } catch (e) {}
                }

                if (currentSuspendData && currentSuspendData !== lastSuspendData) {
                    lastSuspendData = currentSuspendData;
                    var slideNum = parseSlideFromSuspendData(currentSuspendData);
                    if (slideNum !== null && slideNum !== lastSlide) {
                        console.log('[SCORM DOM] Detected slide change:', slideNum);
                        sendProgressUpdate(lastLocation, lastStatus, null, slideNum);
                    }
                }
            }, 500);
        });

        // Start observing after a delay to let SCORM content initialize.
        setTimeout(function() {
            if (document.body) {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: false
                });
            }
        }, 3000);
    }

    // ==========================================================================
    // ARTICULATE STORYLINE SPECIFIC: Direct slide detection from player
    // ==========================================================================

    var storylineSlideIndex = null;
    var storylineCheckInterval = null;

    // Function to find the Storyline iframe and access its player.
    function findStorylinePlayer() {
        // Look for the SCORM content iframe.
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            var iframe = iframes[i];
            try {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                var iframeWin = iframe.contentWindow;

                // Check if this is a Storyline player.
                // Storyline has a GetPlayer() function.
                if (iframeWin.GetPlayer) {
                    return { iframe: iframe, window: iframeWin, document: iframeDoc };
                }

                // Also check for nested iframes (Storyline often nests content).
                var nestedIframes = iframeDoc.querySelectorAll('iframe');
                for (var j = 0; j < nestedIframes.length; j++) {
                    try {
                        var nestedWin = nestedIframes[j].contentWindow;
                        if (nestedWin && nestedWin.GetPlayer) {
                            return { iframe: nestedIframes[j], window: nestedWin, document: nestedWin.document };
                        }
                    } catch (e) {
                        // Cross-origin, skip.
                    }
                }
            } catch (e) {
                // Cross-origin or other error, skip.
            }
        }
        return null;
    }

    // Function to get current slide from Storyline player.
    function getStorylineCurrentSlide(playerInfo) {
        if (!playerInfo || !playerInfo.window) return null;

        try {
            var win = playerInfo.window;
            var doc = playerInfo.document;

            // Method 1: Use Storyline's GetPlayer() API.
            if (win.GetPlayer) {
                var player = win.GetPlayer();
                if (player) {
                    // Try to get current slide index.
                    // Storyline stores slide info internally.
                    if (player.GetVar) {
                        // Some Storyline versions expose a "Menu.SlideNumber" or similar.
                        var slideNum = player.GetVar('Menu.SlideNumber');
                        if (slideNum) {
                            console.log('[Storyline] GetVar Menu.SlideNumber:', slideNum);
                            return parseInt(slideNum, 10);
                        }
                    }
                }
            }

            // Method 2: Check the hash/URL for slide reference.
            var hash = win.location.hash;
            if (hash) {
                // Format: #/scenes/xxx/slides/yyy or similar.
                var match = hash.match(/slides?[\/\-_]?(\d+)/i);
                if (match) {
                    console.log('[Storyline] Hash slide:', match[1]);
                    return parseInt(match[1], 10);
                }
            }

            // Method 3: Look for visible slide container in DOM.
            // Storyline uses elements with data-slide-index or similar.
            var slideContainers = doc.querySelectorAll('[data-slide-index], [data-acc-slide], .slide-container, .slide-layer');
            for (var i = 0; i < slideContainers.length; i++) {
                var container = slideContainers[i];
                // Check if visible.
                var style = win.getComputedStyle(container);
                if (style.display !== 'none' && style.visibility !== 'hidden') {
                    var slideIdx = container.getAttribute('data-slide-index') ||
                                   container.getAttribute('data-acc-slide');
                    if (slideIdx) {
                        console.log('[Storyline] DOM slide-index:', slideIdx);
                        return parseInt(slideIdx, 10) + 1; // Convert 0-based to 1-based.
                    }
                }
            }

            // Method 4: Check Storyline's internal state object.
            if (win.g_slideObject || win.g_PlayerInfo) {
                var slideObj = win.g_slideObject || win.g_PlayerInfo;
                if (slideObj.slideIndex !== undefined) {
                    console.log('[Storyline] g_slideObject.slideIndex:', slideObj.slideIndex);
                    return slideObj.slideIndex + 1;
                }
            }

            // Method 5: Look for the active slide in the slide container.
            var activeSlide = doc.querySelector('.slide.active, .slide-object.active, .slide-layer.active, [class*="slide"][class*="active"]');
            if (activeSlide) {
                // Try to get index from class name.
                var classes = activeSlide.className;
                var match = classes.match(/slide[_\-]?(\d+)/i);
                if (match) {
                    console.log('[Storyline] Active slide class:', match[1]);
                    return parseInt(match[1], 10);
                }
                // Try to get index from siblings.
                var siblings = activeSlide.parentElement.children;
                for (var i = 0; i < siblings.length; i++) {
                    if (siblings[i] === activeSlide) {
                        console.log('[Storyline] Active slide sibling index:', i + 1);
                        return i + 1;
                    }
                }
            }

        } catch (e) {
            console.log('[Storyline] Error accessing player:', e.message);
        }

        return null;
    }

    // Start Storyline-specific monitoring after content loads.
    setTimeout(function() {
        storylineCheckInterval = setInterval(function() {
            var playerInfo = findStorylinePlayer();
            if (playerInfo) {
                var currentSlide = getStorylineCurrentSlide(playerInfo);
                if (currentSlide !== null && currentSlide !== storylineSlideIndex) {
                    storylineSlideIndex = currentSlide;
                    if (currentSlide !== lastSlide) {
                        console.log('[Storyline] Slide changed to:', currentSlide);
                        sendProgressUpdate(null, null, null, currentSlide);
                    }
                }
            }
        }, 1000); // Check every second.
    }, 4000); // Wait 4 seconds for content to fully load.

    // Clean up Storyline interval on unload.
    window.addEventListener('beforeunload', function() {
        if (storylineCheckInterval) {
            clearInterval(storylineCheckInterval);
        }
    });

    // ==========================================================================
    // ISPRING SPECIFIC: Slide detection from iSpring Presentation API
    // ==========================================================================

    var iSpringSlideIndex = null;
    var iSpringCheckInterval = null;

    // Function to find the iSpring player in iframes.
    function findISpringPlayer() {
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var iframeWin = iframes[i].contentWindow;
                // iSpring exposes iSpringPresentationAPI or window.frames.content
                if (iframeWin.iSpringPresentationAPI ||
                    iframeWin.ispringPresentationConnector ||
                    iframeWin.ISPRING) {
                    return { iframe: iframes[i], window: iframeWin };
                }
                // Check for iSpring's player object
                if (iframeWin.player && typeof iframeWin.player.view !== 'undefined') {
                    return { iframe: iframes[i], window: iframeWin };
                }
            } catch (e) {
                // Cross-origin, skip.
            }
        }
        return null;
    }

    // Function to get current slide from iSpring player.
    function getISpringCurrentSlide(playerInfo) {
        if (!playerInfo || !playerInfo.window) return null;

        try {
            var win = playerInfo.window;

            // Method 1: iSpring Presentation API (newer versions)
            if (win.iSpringPresentationAPI) {
                var api = win.iSpringPresentationAPI;
                if (api.slidesCount && api.currentSlideIndex !== undefined) {
                    console.log('[iSpring] API currentSlideIndex:', api.currentSlideIndex);
                    return api.currentSlideIndex + 1; // 0-based to 1-based
                }
                if (api.player && api.player.currentSlide !== undefined) {
                    console.log('[iSpring] API player.currentSlide:', api.player.currentSlide);
                    return api.player.currentSlide;
                }
            }

            // Method 2: iSpring presentation connector
            if (win.ispringPresentationConnector) {
                var connector = win.ispringPresentationConnector;
                if (connector.currentSlideIndex !== undefined) {
                    console.log('[iSpring] Connector currentSlideIndex:', connector.currentSlideIndex);
                    return connector.currentSlideIndex + 1;
                }
            }

            // Method 3: Direct player object
            if (win.player) {
                if (win.player.currentSlideIndex !== undefined) {
                    console.log('[iSpring] player.currentSlideIndex:', win.player.currentSlideIndex);
                    return win.player.currentSlideIndex + 1;
                }
                if (win.player.currentSlide !== undefined) {
                    console.log('[iSpring] player.currentSlide:', win.player.currentSlide);
                    return win.player.currentSlide;
                }
            }

            // Method 4: Check for ISPRING global object
            if (win.ISPRING && win.ISPRING.presentation) {
                var pres = win.ISPRING.presentation;
                if (pres.slideIndex !== undefined) {
                    console.log('[iSpring] ISPRING.presentation.slideIndex:', pres.slideIndex);
                    return pres.slideIndex + 1;
                }
            }

            // Method 5: Look for iSpring-specific DOM elements
            var slideElements = win.document.querySelectorAll('.ispring-slide, .slide-wrapper, [data-slide-index]');
            for (var i = 0; i < slideElements.length; i++) {
                var elem = slideElements[i];
                var style = win.getComputedStyle(elem);
                if (style.display !== 'none' && style.visibility !== 'hidden') {
                    var idx = elem.getAttribute('data-slide-index');
                    if (idx) {
                        console.log('[iSpring] DOM data-slide-index:', idx);
                        return parseInt(idx, 10) + 1;
                    }
                }
            }

        } catch (e) {
            console.log('[iSpring] Error accessing player:', e.message);
        }

        return null;
    }

    // Start iSpring-specific monitoring.
    setTimeout(function() {
        iSpringCheckInterval = setInterval(function() {
            var playerInfo = findISpringPlayer();
            if (playerInfo) {
                var currentSlide = getISpringCurrentSlide(playerInfo);
                if (currentSlide !== null && currentSlide !== iSpringSlideIndex) {
                    iSpringSlideIndex = currentSlide;
                    if (currentSlide !== lastSlide) {
                        console.log('[iSpring] Slide changed to:', currentSlide);
                        sendProgressUpdate(null, null, null, currentSlide);
                    }
                }
            }
        }, 1000);
    }, 4000);

    window.addEventListener('beforeunload', function() {
        if (iSpringCheckInterval) {
            clearInterval(iSpringCheckInterval);
        }
    });

    // ==========================================================================
    // ADOBE CAPTIVATE SPECIFIC: Slide detection from Captivate API
    // ==========================================================================

    var captivateSlideIndex = null;
    var captivateCheckInterval = null;

    // Function to find the Captivate player in iframes.
    function findCaptivatePlayer() {
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var iframeWin = iframes[i].contentWindow;
                // Captivate exposes cpAPIInterface, cpCmndGotoSlide, or cp object
                if (iframeWin.cpAPIInterface ||
                    iframeWin.cpCmndGotoSlide ||
                    iframeWin.cp ||
                    iframeWin.Captivate) {
                    return { iframe: iframes[i], window: iframeWin };
                }
                // Also check for cpInfoCurrentSlide variable
                if (typeof iframeWin.cpInfoCurrentSlide !== 'undefined') {
                    return { iframe: iframes[i], window: iframeWin };
                }
            } catch (e) {
                // Cross-origin, skip.
            }
        }
        return null;
    }

    // Function to get current slide from Captivate player.
    function getCaptivateCurrentSlide(playerInfo) {
        if (!playerInfo || !playerInfo.window) return null;

        try {
            var win = playerInfo.window;

            // Method 1: cpInfoCurrentSlide variable (most common)
            if (typeof win.cpInfoCurrentSlide !== 'undefined') {
                console.log('[Captivate] cpInfoCurrentSlide:', win.cpInfoCurrentSlide);
                return win.cpInfoCurrentSlide + 1; // 0-based to 1-based
            }

            // Method 2: cp.movie object
            if (win.cp && win.cp.movie) {
                var movie = win.cp.movie;
                if (movie.cpInfoCurrentSlide !== undefined) {
                    console.log('[Captivate] cp.movie.cpInfoCurrentSlide:', movie.cpInfoCurrentSlide);
                    return movie.cpInfoCurrentSlide + 1;
                }
                if (movie.currentSlide !== undefined) {
                    console.log('[Captivate] cp.movie.currentSlide:', movie.currentSlide);
                    return movie.currentSlide;
                }
            }

            // Method 3: cpAPIInterface
            if (win.cpAPIInterface) {
                var api = win.cpAPIInterface;
                if (api.getCurrentSlide) {
                    var slide = api.getCurrentSlide();
                    console.log('[Captivate] cpAPIInterface.getCurrentSlide():', slide);
                    return slide + 1;
                }
                if (api.currentSlide !== undefined) {
                    console.log('[Captivate] cpAPIInterface.currentSlide:', api.currentSlide);
                    return api.currentSlide + 1;
                }
            }

            // Method 4: Captivate global object
            if (win.Captivate) {
                if (win.Captivate.currentSlide !== undefined) {
                    console.log('[Captivate] Captivate.currentSlide:', win.Captivate.currentSlide);
                    return win.Captivate.currentSlide;
                }
            }

            // Method 5: cpCmndSlideEnter (event listener based)
            // Store the value if the function was called
            if (win._captivateLastSlide !== undefined) {
                console.log('[Captivate] _captivateLastSlide:', win._captivateLastSlide);
                return win._captivateLastSlide;
            }

            // Method 6: Look for Captivate-specific DOM elements
            var cpSlides = win.document.querySelectorAll('.cp-slide, .captivate-slide, [id^="cpSlide"], [class*="cpSlide"]');
            for (var i = 0; i < cpSlides.length; i++) {
                var elem = cpSlides[i];
                var style = win.getComputedStyle(elem);
                if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
                    // Try to extract slide number from ID or class
                    var id = elem.id || elem.className;
                    var match = id.match(/slide[_\-]?(\d+)/i);
                    if (match) {
                        console.log('[Captivate] DOM slide element:', match[1]);
                        return parseInt(match[1], 10);
                    }
                    // Count visible slides
                    return i + 1;
                }
            }

        } catch (e) {
            console.log('[Captivate] Error accessing player:', e.message);
        }

        return null;
    }

    // Inject Captivate event listener to track slide changes.
    function injectCaptivateListener(playerInfo) {
        if (!playerInfo || !playerInfo.window) return;

        try {
            var win = playerInfo.window;

            // Inject a slide enter event handler if not already done.
            if (win._captivateListenerInjected) return;
            win._captivateListenerInjected = true;

            // Captivate uses cpCmndSlideEnter callback
            var originalSlideEnter = win.cpCmndSlideEnter;
            win.cpCmndSlideEnter = function(slideIndex) {
                win._captivateLastSlide = slideIndex + 1;
                console.log('[Captivate] cpCmndSlideEnter:', slideIndex);
                if (originalSlideEnter) {
                    originalSlideEnter.apply(this, arguments);
                }
            };

            // Also listen for cpSlideEnter event
            if (win.addEventListener) {
                win.addEventListener('cpSlideEnter', function(e) {
                    if (e.detail && e.detail.slideIndex !== undefined) {
                        win._captivateLastSlide = e.detail.slideIndex + 1;
                        console.log('[Captivate] cpSlideEnter event:', e.detail.slideIndex);
                    }
                });
            }

            console.log('[Captivate] Event listener injected successfully');

        } catch (e) {
            console.log('[Captivate] Error injecting listener:', e.message);
        }
    }

    // Start Captivate-specific monitoring.
    setTimeout(function() {
        captivateCheckInterval = setInterval(function() {
            var playerInfo = findCaptivatePlayer();
            if (playerInfo) {
                // Inject event listener on first detection
                injectCaptivateListener(playerInfo);

                var currentSlide = getCaptivateCurrentSlide(playerInfo);
                if (currentSlide !== null && currentSlide !== captivateSlideIndex) {
                    captivateSlideIndex = currentSlide;
                    if (currentSlide !== lastSlide) {
                        console.log('[Captivate] Slide changed to:', currentSlide);
                        sendProgressUpdate(null, null, null, currentSlide);
                    }
                }
            }
        }, 1000);
    }, 4000);

    window.addEventListener('beforeunload', function() {
        if (captivateCheckInterval) {
            clearInterval(captivateCheckInterval);
        }
    });

    // ==========================================================================
    // ARTICULATE RISE 360 SPECIFIC: Section/lesson detection
    // ==========================================================================

    var rise360SectionIndex = null;
    var rise360CheckInterval = null;

    // Function to find the Rise 360 player in iframes.
    function findRise360Player() {
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var iframeWin = iframes[i].contentWindow;
                var iframeDoc = iframeWin.document;

                // Rise 360 has specific class patterns in its DOM
                if (iframeDoc.querySelector('.rise-blocks, .rise-lesson, [data-block-id], [class*="rise"]')) {
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for Rise's app container
                if (iframeDoc.querySelector('#app, .rise-app, [data-rise-version]')) {
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
            } catch (e) {
                // Cross-origin, skip.
            }
        }
        return null;
    }

    // Function to get current section from Rise 360 player.
    function getRise360CurrentSection(playerInfo) {
        if (!playerInfo || !playerInfo.document) return null;

        try {
            var doc = playerInfo.document;
            var win = playerInfo.window;

            // Method 1: Check URL hash for lesson/section index
            var hash = win.location.hash;
            if (hash) {
                // Rise 360 uses format like #/lessons/xxx or #/sections/xxx
                var match = hash.match(/(?:lessons?|sections?|pages?)[\/\-](\d+)/i);
                if (match) {
                    console.log('[Rise 360] Hash section:', match[1]);
                    return parseInt(match[1], 10);
                }
                // Also try just extracting number from hash
                match = hash.match(/\/(\d+)/);
                if (match) {
                    console.log('[Rise 360] Hash index:', match[1]);
                    return parseInt(match[1], 10);
                }
            }

            // Method 2: Count active/visible Rise blocks
            var blocks = doc.querySelectorAll('.rise-blocks > div, .rise-lesson, [data-block-id]');
            var visibleBlockIndex = 0;
            for (var i = 0; i < blocks.length; i++) {
                var block = blocks[i];
                var rect = block.getBoundingClientRect();
                // Check if block is in viewport
                if (rect.top < win.innerHeight && rect.bottom > 0) {
                    // This block is at least partially visible
                    var blockId = block.getAttribute('data-block-id');
                    if (blockId) {
                        console.log('[Rise 360] Visible block ID:', blockId, 'at index', i + 1);
                    }
                    visibleBlockIndex = i + 1;
                    break; // Take the first visible one
                }
            }
            if (visibleBlockIndex > 0) {
                return visibleBlockIndex;
            }

            // Method 3: Check for active navigation item
            var navItems = doc.querySelectorAll('.rise-nav-item, .lesson-nav-item, [data-lesson-index]');
            for (var i = 0; i < navItems.length; i++) {
                var item = navItems[i];
                if (item.classList.contains('active') || item.classList.contains('current') || item.getAttribute('aria-current') === 'true') {
                    var idx = item.getAttribute('data-lesson-index') || i;
                    console.log('[Rise 360] Active nav item index:', idx);
                    return parseInt(idx, 10) + 1;
                }
            }

            // Method 4: Check Rise's internal state
            if (win.__RISE_STATE__ || win.riseState || win.Rise) {
                var state = win.__RISE_STATE__ || win.riseState || (win.Rise && win.Rise.state);
                if (state && state.currentLesson !== undefined) {
                    console.log('[Rise 360] Internal state currentLesson:', state.currentLesson);
                    return state.currentLesson + 1;
                }
                if (state && state.currentSection !== undefined) {
                    console.log('[Rise 360] Internal state currentSection:', state.currentSection);
                    return state.currentSection + 1;
                }
            }

        } catch (e) {
            console.log('[Rise 360] Error accessing player:', e.message);
        }

        return null;
    }

    // Start Rise 360-specific monitoring.
    setTimeout(function() {
        rise360CheckInterval = setInterval(function() {
            var playerInfo = findRise360Player();
            if (playerInfo) {
                var currentSection = getRise360CurrentSection(playerInfo);
                if (currentSection !== null && currentSection !== rise360SectionIndex) {
                    rise360SectionIndex = currentSection;
                    if (currentSection !== lastSlide) {
                        console.log('[Rise 360] Section changed to:', currentSection);
                        sendProgressUpdate(null, null, null, currentSection);
                    }
                }
            }
        }, 1000);
    }, 4000);

    window.addEventListener('beforeunload', function() {
        if (rise360CheckInterval) {
            clearInterval(rise360CheckInterval);
        }
    });

    // ==========================================================================
    // LECTORA SPECIFIC: Page detection from Lectora player
    // ==========================================================================

    var lectoraPageIndex = null;
    var lectoraCheckInterval = null;

    // Function to find the Lectora player in iframes.
    function findLectoraPlayer() {
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var iframeWin = iframes[i].contentWindow;
                // Lectora has trivExternalCall, trivantis object, or lectora global
                if (iframeWin.trivExternalCall ||
                    iframeWin.trivantis ||
                    iframeWin.lectora ||
                    iframeWin.TrivAPI) {
                    return { iframe: iframes[i], window: iframeWin };
                }
                // Check for Lectora's page tracking variable
                if (typeof iframeWin.currentPage !== 'undefined' ||
                    typeof iframeWin.pageNum !== 'undefined') {
                    return { iframe: iframes[i], window: iframeWin };
                }
            } catch (e) {
                // Cross-origin, skip.
            }
        }
        return null;
    }

    // Function to get current page from Lectora player.
    function getLectoraCurrentPage(playerInfo) {
        if (!playerInfo || !playerInfo.window) return null;

        try {
            var win = playerInfo.window;

            // Method 1: Direct currentPage or pageNum variable
            if (typeof win.currentPage !== 'undefined') {
                console.log('[Lectora] currentPage:', win.currentPage);
                return win.currentPage;
            }
            if (typeof win.pageNum !== 'undefined') {
                console.log('[Lectora] pageNum:', win.pageNum);
                return win.pageNum;
            }

            // Method 2: trivantis object
            if (win.trivantis) {
                if (win.trivantis.currentPage !== undefined) {
                    console.log('[Lectora] trivantis.currentPage:', win.trivantis.currentPage);
                    return win.trivantis.currentPage;
                }
                if (win.trivantis.pageIndex !== undefined) {
                    console.log('[Lectora] trivantis.pageIndex:', win.trivantis.pageIndex);
                    return win.trivantis.pageIndex + 1;
                }
            }

            // Method 3: TrivAPI object
            if (win.TrivAPI) {
                if (win.TrivAPI.GetCurrentPage) {
                    var page = win.TrivAPI.GetCurrentPage();
                    console.log('[Lectora] TrivAPI.GetCurrentPage():', page);
                    return page;
                }
                if (win.TrivAPI.currentPage !== undefined) {
                    console.log('[Lectora] TrivAPI.currentPage:', win.TrivAPI.currentPage);
                    return win.TrivAPI.currentPage;
                }
            }

            // Method 4: lectora global object
            if (win.lectora) {
                if (win.lectora.currentPageNumber !== undefined) {
                    console.log('[Lectora] lectora.currentPageNumber:', win.lectora.currentPageNumber);
                    return win.lectora.currentPageNumber;
                }
                if (win.lectora.pageNum !== undefined) {
                    console.log('[Lectora] lectora.pageNum:', win.lectora.pageNum);
                    return win.lectora.pageNum;
                }
            }

            // Method 5: Look for Lectora's page elements in DOM
            var doc = win.document;
            var pages = doc.querySelectorAll('.page, .lectora-page, [id^="page"], [class*="lecPage"]');
            for (var i = 0; i < pages.length; i++) {
                var page = pages[i];
                var style = win.getComputedStyle(page);
                if (style.display !== 'none' && style.visibility !== 'hidden') {
                    // Try to extract page number from ID or class
                    var id = page.id || page.className;
                    var match = id.match(/page[_\-]?(\d+)/i);
                    if (match) {
                        console.log('[Lectora] DOM page element:', match[1]);
                        return parseInt(match[1], 10);
                    }
                    return i + 1;
                }
            }

            // Method 6: Check URL hash for page reference
            var hash = win.location.hash;
            if (hash) {
                var match = hash.match(/page[_\-]?(\d+)/i);
                if (match) {
                    console.log('[Lectora] Hash page:', match[1]);
                    return parseInt(match[1], 10);
                }
            }

        } catch (e) {
            console.log('[Lectora] Error accessing player:', e.message);
        }

        return null;
    }

    // Start Lectora-specific monitoring.
    setTimeout(function() {
        lectoraCheckInterval = setInterval(function() {
            var playerInfo = findLectoraPlayer();
            if (playerInfo) {
                var currentPage = getLectoraCurrentPage(playerInfo);
                if (currentPage !== null && currentPage !== lectoraPageIndex) {
                    lectoraPageIndex = currentPage;
                    if (currentPage !== lastSlide) {
                        console.log('[Lectora] Page changed to:', currentPage);
                        sendProgressUpdate(null, null, null, currentPage);
                    }
                }
            }
        }, 1000);
    }, 4000);

    window.addEventListener('beforeunload', function() {
        if (lectoraCheckInterval) {
            clearInterval(lectoraCheckInterval);
        }
    });

    // ==========================================================================
    // GENERIC HTML5 SCORM: Universal fallback detection
    // ==========================================================================

    var genericSlideIndex = null;
    var genericCheckInterval = null;

    // Function to detect generic HTML5 SCORM content.
    function findGenericScormContent() {
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var iframeWin = iframes[i].contentWindow;
                var iframeDoc = iframeWin.document;

                // Check if it has generic slide/page patterns
                if (iframeDoc.querySelector('.slide, .page, [data-slide], [data-page], [class*="slide"], [class*="page"]')) {
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
            } catch (e) {
                // Cross-origin, skip.
            }
        }
        return null;
    }

    // Function to get current position from generic SCORM content.
    function getGenericCurrentPosition(playerInfo) {
        if (!playerInfo || !playerInfo.document) return null;

        try {
            var doc = playerInfo.document;
            var win = playerInfo.window;

            // Method 1: Check for common slide/page variables
            var varNames = ['currentSlide', 'currentPage', 'slideIndex', 'pageIndex', 'slideNum', 'pageNum', 'currentIndex'];
            for (var i = 0; i < varNames.length; i++) {
                if (typeof win[varNames[i]] !== 'undefined' && !isNaN(win[varNames[i]])) {
                    console.log('[Generic] Variable ' + varNames[i] + ':', win[varNames[i]]);
                    return parseInt(win[varNames[i]], 10);
                }
            }

            // Method 2: Look for visible slide/page elements
            var selectors = [
                '.slide:not([style*="display: none"]):not([style*="visibility: hidden"])',
                '.page:not([style*="display: none"]):not([style*="visibility: hidden"])',
                '[data-slide]:not([style*="display: none"])',
                '[data-page]:not([style*="display: none"])',
                '.slide.active',
                '.page.active',
                '.slide.current',
                '.page.current',
                '[class*="slide"][class*="active"]',
                '[class*="page"][class*="active"]'
            ];

            for (var i = 0; i < selectors.length; i++) {
                var elem = doc.querySelector(selectors[i]);
                if (elem) {
                    // Try to get index from data attribute
                    var dataSlide = elem.getAttribute('data-slide') || elem.getAttribute('data-page') || elem.getAttribute('data-index');
                    if (dataSlide) {
                        console.log('[Generic] Data attribute:', dataSlide);
                        return parseInt(dataSlide, 10);
                    }

                    // Try to get index from class name
                    var classes = elem.className;
                    var match = classes.match(/(?:slide|page)[_\-]?(\d+)/i);
                    if (match) {
                        console.log('[Generic] Class match:', match[1]);
                        return parseInt(match[1], 10);
                    }

                    // Try sibling count
                    if (elem.parentElement) {
                        var siblings = elem.parentElement.children;
                        for (var j = 0; j < siblings.length; j++) {
                            if (siblings[j] === elem) {
                                console.log('[Generic] Sibling index:', j + 1);
                                return j + 1;
                            }
                        }
                    }
                }
            }

            // Method 3: Check URL hash
            var hash = win.location.hash;
            if (hash) {
                var match = hash.match(/(?:slide|page|section|chapter)[_\-\/]?(\d+)/i);
                if (match) {
                    console.log('[Generic] Hash match:', match[1]);
                    return parseInt(match[1], 10);
                }
                // Try just number in hash
                match = hash.match(/#(\d+)/);
                if (match) {
                    console.log('[Generic] Hash number:', match[1]);
                    return parseInt(match[1], 10);
                }
            }

        } catch (e) {
            console.log('[Generic] Error detecting position:', e.message);
        }

        return null;
    }

    // Start generic SCORM monitoring (lower priority, longer delay).
    setTimeout(function() {
        genericCheckInterval = setInterval(function() {
            // Only run generic detection if no other tool detected anything
            if (storylineSlideIndex !== null || iSpringSlideIndex !== null ||
                captivateSlideIndex !== null || rise360SectionIndex !== null ||
                lectoraPageIndex !== null) {
                return; // Another detector is working
            }

            var content = findGenericScormContent();
            if (content) {
                var currentPosition = getGenericCurrentPosition(content);
                if (currentPosition !== null && currentPosition !== genericSlideIndex) {
                    genericSlideIndex = currentPosition;
                    if (currentPosition !== lastSlide) {
                        console.log('[Generic] Position changed to:', currentPosition);
                        sendProgressUpdate(null, null, null, currentPosition);
                    }
                }
            }
        }, 1500); // Check less frequently
    }, 5000); // Start later to let specific detectors run first

    window.addEventListener('beforeunload', function() {
        if (genericCheckInterval) {
            clearInterval(genericCheckInterval);
        }
    });

    // Log which SCORM tools are detected
    setTimeout(function() {
        console.log('[SCORM Multi-Tool Support] Checking for supported authoring tools...');

        var detected = [];

        // Check for each tool
        if (findStorylinePlayer()) detected.push('Articulate Storyline');
        if (findISpringPlayer()) detected.push('iSpring');
        if (findCaptivatePlayer()) detected.push('Adobe Captivate');
        if (findRise360Player()) detected.push('Articulate Rise 360');
        if (findLectoraPlayer()) detected.push('Lectora');
        if (findGenericScormContent()) detected.push('Generic HTML5 SCORM');

        if (detected.length > 0) {
            console.log('[SCORM Multi-Tool Support] Detected: ' + detected.join(', '));
        } else {
            console.log('[SCORM Multi-Tool Support] No specific authoring tool detected. Using SCORM API tracking.');
        }
    }, 5000);
})();
</script>
JS;
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
