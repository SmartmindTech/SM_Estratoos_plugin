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

    // LZ-String decompression for Articulate Storyline suspend_data.
    // Storyline uses LZ compression before Base64 encoding.
    var LZString = (function() {
        var f = String.fromCharCode;
        var keyStrBase64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
        var baseReverseDic = {};

        function getBaseValue(alphabet, character) {
            if (!baseReverseDic[alphabet]) {
                baseReverseDic[alphabet] = {};
                for (var i = 0; i < alphabet.length; i++) {
                    baseReverseDic[alphabet][alphabet.charAt(i)] = i;
                }
            }
            return baseReverseDic[alphabet][character];
        }

        function decompressFromBase64(input) {
            if (input == null || input === "") return null;
            try {
                return _decompress(input.length, 32, function(index) {
                    return getBaseValue(keyStrBase64, input.charAt(index));
                });
            } catch (e) {
                return null;
            }
        }

        function _decompress(length, resetValue, getNextValue) {
            var dictionary = [], enlargeIn = 4, dictSize = 4, numBits = 3;
            var entry = "", result = [], w, c, resb;
            var data = {val: getNextValue(0), position: resetValue, index: 1};

            for (var i = 0; i < 3; i++) dictionary[i] = i;

            var bits = 0, maxpower = Math.pow(2, 2), power = 1;
            while (power != maxpower) {
                resb = data.val & data.position;
                data.position >>= 1;
                if (data.position == 0) {
                    data.position = resetValue;
                    data.val = getNextValue(data.index++);
                }
                bits |= (resb > 0 ? 1 : 0) * power;
                power <<= 1;
            }

            switch (bits) {
                case 0:
                    bits = 0; maxpower = Math.pow(2, 8); power = 1;
                    while (power != maxpower) {
                        resb = data.val & data.position;
                        data.position >>= 1;
                        if (data.position == 0) { data.position = resetValue; data.val = getNextValue(data.index++); }
                        bits |= (resb > 0 ? 1 : 0) * power;
                        power <<= 1;
                    }
                    c = f(bits);
                    break;
                case 1:
                    bits = 0; maxpower = Math.pow(2, 16); power = 1;
                    while (power != maxpower) {
                        resb = data.val & data.position;
                        data.position >>= 1;
                        if (data.position == 0) { data.position = resetValue; data.val = getNextValue(data.index++); }
                        bits |= (resb > 0 ? 1 : 0) * power;
                        power <<= 1;
                    }
                    c = f(bits);
                    break;
                case 2:
                    return "";
            }
            dictionary[3] = c;
            w = c;
            result.push(c);

            while (true) {
                if (data.index > length) return "";
                bits = 0; maxpower = Math.pow(2, numBits); power = 1;
                while (power != maxpower) {
                    resb = data.val & data.position;
                    data.position >>= 1;
                    if (data.position == 0) { data.position = resetValue; data.val = getNextValue(data.index++); }
                    bits |= (resb > 0 ? 1 : 0) * power;
                    power <<= 1;
                }
                switch (c = bits) {
                    case 0:
                        bits = 0; maxpower = Math.pow(2, 8); power = 1;
                        while (power != maxpower) {
                            resb = data.val & data.position;
                            data.position >>= 1;
                            if (data.position == 0) { data.position = resetValue; data.val = getNextValue(data.index++); }
                            bits |= (resb > 0 ? 1 : 0) * power;
                            power <<= 1;
                        }
                        dictionary[dictSize++] = f(bits);
                        c = dictSize - 1;
                        enlargeIn--;
                        break;
                    case 1:
                        bits = 0; maxpower = Math.pow(2, 16); power = 1;
                        while (power != maxpower) {
                            resb = data.val & data.position;
                            data.position >>= 1;
                            if (data.position == 0) { data.position = resetValue; data.val = getNextValue(data.index++); }
                            bits |= (resb > 0 ? 1 : 0) * power;
                            power <<= 1;
                        }
                        dictionary[dictSize++] = f(bits);
                        c = dictSize - 1;
                        enlargeIn--;
                        break;
                    case 2:
                        return result.join('');
                }
                if (enlargeIn == 0) { enlargeIn = Math.pow(2, numBits); numBits++; }
                if (dictionary[c]) {
                    entry = dictionary[c];
                } else {
                    if (c === dictSize) { entry = w + w.charAt(0); }
                    else { return null; }
                }
                result.push(entry);
                dictionary[dictSize++] = w + entry.charAt(0);
                enlargeIn--;
                if (enlargeIn == 0) { enlargeIn = Math.pow(2, numBits); numBits++; }
                w = entry;
            }
        }

        // =============================================
        // LZ-String COMPRESSION (for modifying suspend_data)
        // =============================================

        function compressToBase64(input) {
            if (input == null || input === "") return "";
            var res = _compress(input, 6, function(a) {
                return keyStrBase64.charAt(a);
            });
            switch (res.length % 4) {
                case 0: return res;
                case 1: return res + "===";
                case 2: return res + "==";
                case 3: return res + "=";
            }
            return res;
        }

        function _compress(uncompressed, bitsPerChar, getCharFromInt) {
            if (uncompressed == null) return "";
            var i, value,
                context_dictionary = {},
                context_dictionaryToCreate = {},
                context_c = "",
                context_wc = "",
                context_w = "",
                context_enlargeIn = 2,
                context_dictSize = 3,
                context_numBits = 2,
                context_data = [],
                context_data_val = 0,
                context_data_position = 0,
                ii;

            for (ii = 0; ii < uncompressed.length; ii += 1) {
                context_c = uncompressed.charAt(ii);
                if (!Object.prototype.hasOwnProperty.call(context_dictionary, context_c)) {
                    context_dictionary[context_c] = context_dictSize++;
                    context_dictionaryToCreate[context_c] = true;
                }

                context_wc = context_w + context_c;
                if (Object.prototype.hasOwnProperty.call(context_dictionary, context_wc)) {
                    context_w = context_wc;
                } else {
                    if (Object.prototype.hasOwnProperty.call(context_dictionaryToCreate, context_w)) {
                        if (context_w.charCodeAt(0) < 256) {
                            for (i = 0; i < context_numBits; i++) {
                                context_data_val = (context_data_val << 1);
                                if (context_data_position == bitsPerChar - 1) {
                                    context_data_position = 0;
                                    context_data.push(getCharFromInt(context_data_val));
                                    context_data_val = 0;
                                } else {
                                    context_data_position++;
                                }
                            }
                            value = context_w.charCodeAt(0);
                            for (i = 0; i < 8; i++) {
                                context_data_val = (context_data_val << 1) | (value & 1);
                                if (context_data_position == bitsPerChar - 1) {
                                    context_data_position = 0;
                                    context_data.push(getCharFromInt(context_data_val));
                                    context_data_val = 0;
                                } else {
                                    context_data_position++;
                                }
                                value = value >> 1;
                            }
                        } else {
                            value = 1;
                            for (i = 0; i < context_numBits; i++) {
                                context_data_val = (context_data_val << 1) | value;
                                if (context_data_position == bitsPerChar - 1) {
                                    context_data_position = 0;
                                    context_data.push(getCharFromInt(context_data_val));
                                    context_data_val = 0;
                                } else {
                                    context_data_position++;
                                }
                                value = 0;
                            }
                            value = context_w.charCodeAt(0);
                            for (i = 0; i < 16; i++) {
                                context_data_val = (context_data_val << 1) | (value & 1);
                                if (context_data_position == bitsPerChar - 1) {
                                    context_data_position = 0;
                                    context_data.push(getCharFromInt(context_data_val));
                                    context_data_val = 0;
                                } else {
                                    context_data_position++;
                                }
                                value = value >> 1;
                            }
                        }
                        context_enlargeIn--;
                        if (context_enlargeIn == 0) {
                            context_enlargeIn = Math.pow(2, context_numBits);
                            context_numBits++;
                        }
                        delete context_dictionaryToCreate[context_w];
                    } else {
                        value = context_dictionary[context_w];
                        for (i = 0; i < context_numBits; i++) {
                            context_data_val = (context_data_val << 1) | (value & 1);
                            if (context_data_position == bitsPerChar - 1) {
                                context_data_position = 0;
                                context_data.push(getCharFromInt(context_data_val));
                                context_data_val = 0;
                            } else {
                                context_data_position++;
                            }
                            value = value >> 1;
                        }
                    }
                    context_enlargeIn--;
                    if (context_enlargeIn == 0) {
                        context_enlargeIn = Math.pow(2, context_numBits);
                        context_numBits++;
                    }
                    context_dictionary[context_wc] = context_dictSize++;
                    context_w = String(context_c);
                }
            }

            if (context_w !== "") {
                if (Object.prototype.hasOwnProperty.call(context_dictionaryToCreate, context_w)) {
                    if (context_w.charCodeAt(0) < 256) {
                        for (i = 0; i < context_numBits; i++) {
                            context_data_val = (context_data_val << 1);
                            if (context_data_position == bitsPerChar - 1) {
                                context_data_position = 0;
                                context_data.push(getCharFromInt(context_data_val));
                                context_data_val = 0;
                            } else {
                                context_data_position++;
                            }
                        }
                        value = context_w.charCodeAt(0);
                        for (i = 0; i < 8; i++) {
                            context_data_val = (context_data_val << 1) | (value & 1);
                            if (context_data_position == bitsPerChar - 1) {
                                context_data_position = 0;
                                context_data.push(getCharFromInt(context_data_val));
                                context_data_val = 0;
                            } else {
                                context_data_position++;
                            }
                            value = value >> 1;
                        }
                    } else {
                        value = 1;
                        for (i = 0; i < context_numBits; i++) {
                            context_data_val = (context_data_val << 1) | value;
                            if (context_data_position == bitsPerChar - 1) {
                                context_data_position = 0;
                                context_data.push(getCharFromInt(context_data_val));
                                context_data_val = 0;
                            } else {
                                context_data_position++;
                            }
                            value = 0;
                        }
                        value = context_w.charCodeAt(0);
                        for (i = 0; i < 16; i++) {
                            context_data_val = (context_data_val << 1) | (value & 1);
                            if (context_data_position == bitsPerChar - 1) {
                                context_data_position = 0;
                                context_data.push(getCharFromInt(context_data_val));
                                context_data_val = 0;
                            } else {
                                context_data_position++;
                            }
                            value = value >> 1;
                        }
                    }
                    context_enlargeIn--;
                    if (context_enlargeIn == 0) {
                        context_enlargeIn = Math.pow(2, context_numBits);
                        context_numBits++;
                    }
                    delete context_dictionaryToCreate[context_w];
                } else {
                    value = context_dictionary[context_w];
                    for (i = 0; i < context_numBits; i++) {
                        context_data_val = (context_data_val << 1) | (value & 1);
                        if (context_data_position == bitsPerChar - 1) {
                            context_data_position = 0;
                            context_data.push(getCharFromInt(context_data_val));
                            context_data_val = 0;
                        } else {
                            context_data_position++;
                        }
                        value = value >> 1;
                    }
                }
                context_enlargeIn--;
                if (context_enlargeIn == 0) {
                    context_enlargeIn = Math.pow(2, context_numBits);
                    context_numBits++;
                }
            }

            // Mark the end of the stream
            value = 2;
            for (i = 0; i < context_numBits; i++) {
                context_data_val = (context_data_val << 1) | (value & 1);
                if (context_data_position == bitsPerChar - 1) {
                    context_data_position = 0;
                    context_data.push(getCharFromInt(context_data_val));
                    context_data_val = 0;
                } else {
                    context_data_position++;
                }
                value = value >> 1;
            }

            // Flush the last char
            while (true) {
                context_data_val = (context_data_val << 1);
                if (context_data_position == bitsPerChar - 1) {
                    context_data.push(getCharFromInt(context_data_val));
                    break;
                } else {
                    context_data_position++;
                }
            }

            return context_data.join('');
        }

        return {
            decompressFromBase64: decompressFromBase64,
            compressToBase64: compressToBase64
        };
    })();

    // Track the source of slide position for priority handling.
    var slideSource = null; // 'suspend_data', 'navigation', 'score'
    var furthestSlide = null; // Track the furthest slide reached (from score)

    // Function to parse slide from suspend_data (multiple vendor formats).
    function parseSlideFromSuspendData(data) {
        if (!data || data.length < 5) return null;

        // 1. Try to parse as JSON directly (some tools don't compress).
        try {
            var parsed = JSON.parse(data);
            var slideNum = extractSlideFromParsedData(parsed);
            if (slideNum !== null) {
                console.log('[suspend_data] Parsed JSON directly, slide:', slideNum);
                slideSource = 'suspend_data';
                return slideNum;
            }
        } catch (e) {
            // Not JSON, try other patterns.
        }

        // 2. Articulate Storyline: LZ-compressed Base64.
        if (data.match(/^[A-Za-z0-9+/=]{20,}$/)) {
            try {
                // Try LZ decompression first (most common for Storyline).
                var decompressed = LZString.decompressFromBase64(data);
                if (decompressed && decompressed.length > 0) {
                    console.log('[suspend_data] LZ decompressed, length:', decompressed.length);

                    // Try to parse decompressed JSON.
                    try {
                        var parsed = JSON.parse(decompressed);
                        var slideNum = extractSlideFromParsedData(parsed);
                        if (slideNum !== null) {
                            console.log('[suspend_data] LZ+JSON slide:', slideNum);
                            slideSource = 'suspend_data';
                            return slideNum;
                        }
                    } catch (e) {
                        // Not JSON, search for patterns in decompressed string.
                    }

                    // Search for resume patterns in decompressed text.
                    var slideNum = extractSlideFromText(decompressed);
                    if (slideNum !== null) {
                        console.log('[suspend_data] LZ text slide:', slideNum);
                        slideSource = 'suspend_data';
                        return slideNum;
                    }
                }
            } catch (e) {
                console.log('[suspend_data] LZ decompression failed');
            }

            // Fallback: try plain Base64 decode.
            try {
                var decoded = atob(data);

                // Try JSON.
                try {
                    var parsed = JSON.parse(decoded);
                    var slideNum = extractSlideFromParsedData(parsed);
                    if (slideNum !== null) {
                        console.log('[suspend_data] Base64+JSON slide:', slideNum);
                        slideSource = 'suspend_data';
                        return slideNum;
                    }
                } catch (e) {}

                // Search for patterns.
                var slideNum = extractSlideFromText(decoded);
                if (slideNum !== null) {
                    console.log('[suspend_data] Base64 text slide:', slideNum);
                    slideSource = 'suspend_data';
                    return slideNum;
                }
            } catch (e) {
                // Not valid Base64.
            }
        }

        // 3. URL-encoded format (Adobe Captivate style).
        if (data.indexOf('=') !== -1 && data.indexOf('&') !== -1) {
            try {
                var params = new URLSearchParams(data);
                if (params.has('slide')) {
                    slideSource = 'suspend_data';
                    return parseInt(params.get('slide'), 10);
                }
                if (params.has('current')) {
                    slideSource = 'suspend_data';
                    return parseInt(params.get('current'), 10);
                }
                if (params.has('page')) {
                    slideSource = 'suspend_data';
                    return parseInt(params.get('page'), 10);
                }
            } catch (e) {}
        }

        // 4. Search for patterns in raw string.
        var slideNum = extractSlideFromText(data);
        if (slideNum !== null) {
            console.log('[suspend_data] Raw text slide:', slideNum);
            slideSource = 'suspend_data';
            return slideNum;
        }

        // If we can't parse suspend_data, return null.
        console.log('[suspend_data] Could not extract slide position from suspend_data');
        return null;
    }

    // Extract slide number from parsed JSON structure.
    function extractSlideFromParsedData(parsed) {
        if (!parsed) return null;

        // NOTE: The "l" field in Storyline suspend_data is the FURTHEST/RESUME position,
        // NOT the current viewing position. Do NOT use it for current slide detection.
        // The Poll mechanism is the source of truth for current position.

        // Direct properties.
        if (parsed.currentSlide !== undefined) return parseInt(parsed.currentSlide, 10);
        if (parsed.slide !== undefined) return parseInt(parsed.slide, 10);
        if (parsed.current !== undefined) return parseInt(parsed.current, 10);
        if (parsed.position !== undefined) return parseInt(parsed.position, 10);

        // Articulate Storyline "resume" format (e.g., "1_6" = scene 1, slide 6).
        // NOTE: This is the FURTHEST progress, not current position. Only use as fallback.
        if (parsed.resume !== undefined) {
            var resume = String(parsed.resume);
            // Format: scene_slide or just slide number.
            var match = resume.match(/^(\d+)_(\d+)$/);
            if (match) {
                // scene_slide format - return the slide number.
                console.log('[suspend_data] Resume format scene_slide:', match[1], '_', match[2]);
                return parseInt(match[2], 10);
            }
            match = resume.match(/^(\d+)$/);
            if (match) return parseInt(match[1], 10);
        }

        // Nested structures.
        if (parsed.v && parsed.v.current !== undefined) return parseInt(parsed.v.current, 10);
        if (parsed.data && parsed.data.slide !== undefined) return parseInt(parsed.data.slide, 10);

        // Storyline "d" array format: [{n: "Resume", v: "1_6"}, ...].
        if (parsed.d && Array.isArray(parsed.d)) {
            for (var i = 0; i < parsed.d.length; i++) {
                var item = parsed.d[i];
                if (item.n === 'Resume' || item.n === 'resume') {
                    var resume = String(item.v);
                    var match = resume.match(/^(\d+)_(\d+)$/);
                    if (match) {
                        console.log('[suspend_data] Storyline d-array Resume:', match[1], '_', match[2]);
                        return parseInt(match[2], 10);
                    }
                    match = resume.match(/^(\d+)$/);
                    if (match) return parseInt(match[1], 10);
                }
            }
        }

        // Check for Player.CurrentSlideIndex in variables.
        if (parsed.variables) {
            if (parsed.variables.CurrentSlideIndex !== undefined) {
                return parseInt(parsed.variables.CurrentSlideIndex, 10) + 1; // 0-based to 1-based.
            }
            if (parsed.variables['Player.CurrentSlideIndex'] !== undefined) {
                return parseInt(parsed.variables['Player.CurrentSlideIndex'], 10) + 1;
            }
        }

        return null;
    }

    // Extract slide number from text using patterns.
    // IMPORTANT: Storyline uses 0-based indexing internally, so we add 1 to get 1-based slide numbers.
    // NOTE: The "l" field in Storyline suspend_data is the FURTHEST/RESUME position,
    // NOT the current viewing position. Do NOT use it for current slide detection.
    // The Poll mechanism is the source of truth for current position.
    function extractSlideFromText(text) {
        if (!text || typeof text !== 'string') return null;

        // Look for explicit resume/slide patterns.
        // These patterns capture 0-based indices from Storyline's internal format.
        var patterns = [
            /["']?resume["']?\s*[:=]\s*["']?(\d+)_(\d+)["']?/i,     // "resume": "1_5" (scene_slide)
            /["']?resume["']?\s*[:=]\s*["']?(\d+)["']?/i,           // "resume": "5"
            /["']?currentSlide["']?\s*[:=]\s*["']?(\d+)["']?/i,     // "currentSlide": 5
            /["']?CurrentSlideIndex["']?\s*[:=]\s*["']?(\d+)["']?/i, // "CurrentSlideIndex": 5
            /["']?slide["']?\s*[:=]\s*["']?(\d+)["']?/i,            // "slide": 5
            /n["']?\s*[:=]\s*["']?Resume["']?.*?v["']?\s*[:=]\s*["']?(\d+)_(\d+)["']?/i, // Storyline d-array
        ];

        for (var i = 0; i < patterns.length; i++) {
            var match = text.match(patterns[i]);
            if (match) {
                var slideIndex;
                // If scene_slide format, get the slide number.
                if (match[2] !== undefined) {
                    slideIndex = parseInt(match[2], 10);
                } else {
                    slideIndex = parseInt(match[1], 10);
                }
                // Convert from 0-based to 1-based.
                return slideIndex + 1;
            }
        }

        // Look for scene_slide pattern only in non-random context.
        // Must be preceded by a keyword to avoid matching random number pairs.
        var match = text.match(/(?:resume|state|position|bookmark)['":\s]*(\d+)_(\d+)/i);
        if (match) {
            // Convert from 0-based to 1-based.
            return parseInt(match[2], 10) + 1;
        }

        return null;
    }

    // Function to send progress to parent window.
    function sendProgressUpdate(location, status, score, directSlide) {
        var currentSlide = directSlide || parseSlideNumber(location) || lastSlide;

        // Update lastSlide if we have a new value.
        if (currentSlide !== null && currentSlide !== lastSlide) {
            console.log('[SCORM Progress] Slide updated:', lastSlide, '->', currentSlide, '(source:', slideSource || 'unknown', ')');
            lastSlide = currentSlide;
        }

        // Build message object.
        var message = {
            type: 'scorm-progress',
            cmid: cmid,
            scormid: scormid,
            currentSlide: currentSlide,
            totalSlides: slidescount,
            furthestSlide: furthestSlide, // Furthest progress reached (from score)
            lessonLocation: location || lastLocation,
            lessonStatus: status || lastStatus,
            score: score,
            slideSource: slideSource, // Include source for debugging
            timestamp: Date.now()
        };

        // Calculate progress percentage based on FURTHEST progress, not current position.
        // This ensures the progress bar shows the maximum achieved, not the current view.
        var progressSlide = furthestSlide || currentSlide;
        if (progressSlide !== null && slidescount > 0) {
            message.progressPercent = Math.round((progressSlide / slidescount) * 100);
        }

        // Also include current position percentage for the position indicator.
        if (currentSlide !== null && slidescount > 0) {
            message.currentPercent = Math.round((currentSlide / slidescount) * 100);
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

    // Check for pending slide navigation from sessionStorage (set before reload).
    var pendingSlideNavigation = null;
    // Multiple intercept system: allow multiple SCORM API intercepts within a time window
    // Storyline calls LMSGetValue/LMSSetValue many times during initialization
    var suspendDataInterceptCount = 0;
    var MAX_INTERCEPTS = 999; // Effectively unlimited - rely on time window instead
    var interceptStartTime = null; // Set when navigation is detected (NOT on first read)
    var INTERCEPT_WINDOW_MS = 10000; // Intercept for 10 seconds to cover slow SCORM init
    try {
        var navData = sessionStorage.getItem('scorm_pending_navigation_' + cmid);
        if (navData) {
            pendingSlideNavigation = JSON.parse(navData);
            // Start the intercept timer immediately when navigation is detected
            // This is critical because Storyline WRITES before it READS
            interceptStartTime = Date.now();
            console.log('[SCORM Navigation] Found pending navigation:', pendingSlideNavigation);
            // Clear immediately to prevent re-use on subsequent reloads
            sessionStorage.removeItem('scorm_pending_navigation_' + cmid);
        }
    } catch (e) {
        console.log('[SCORM Navigation] Error reading pending navigation:', e.message);
    }

    /**
     * Modify suspend_data to change the resume position.
     * Modifies BOTH the "l" field AND the "resume" field for Storyline navigation.
     * The "l" field is the last slide index, "resume" is "scene_slide" format (e.g., "0_12").
     */
    function modifySuspendDataForSlide(originalData, targetSlide) {
        if (!originalData || originalData.length < 5) return originalData;

        var targetIndex = targetSlide - 1; // 0-based index
        console.log('[SCORM suspend_data] Modifying for slide:', targetSlide, '(index:', targetIndex, ')');

        // Try LZ decompression (Articulate Storyline format)
        if (originalData.match(/^[A-Za-z0-9+/=]{20,}$/)) {
            try {
                var decompressed = LZString.decompressFromBase64(originalData);
                if (decompressed && decompressed.length > 0) {
                    var modified = decompressed;
                    var anyChange = false;

                    // 1. Modify "l" field - last slide position (0-indexed)
                    modified = modified.replace(
                        /"l"\s*:\s*(\d+)/g,
                        function(match, oldValue) {
                            if (parseInt(oldValue) !== targetIndex) {
                                console.log('[SCORM suspend_data] "l":', oldValue, '->', targetIndex);
                                anyChange = true;
                                return '"l":' + targetIndex;
                            }
                            return match;
                        }
                    );

                    // 2. Modify "resume" field - scene_slide format "0_7"
                    // Keep the scene number, only change the slide number
                    modified = modified.replace(
                        /"resume"\s*:\s*"(\d+)_(\d+)"/g,
                        function(match, scene, slide) {
                            if (parseInt(slide) !== targetIndex) {
                                console.log('[SCORM suspend_data] "resume":', scene + '_' + slide, '->', scene + '_' + targetIndex);
                                anyChange = true;
                                return '"resume":"' + scene + '_' + targetIndex + '"';
                            }
                            return match;
                        }
                    );

                    // 3. Modify d-array Resume variable - {"n":"Resume","v":"0_7"}
                    modified = modified.replace(
                        /("n"\s*:\s*"Resume"\s*,\s*"v"\s*:\s*")(\d+)_(\d+)(")/gi,
                        function(match, prefix, scene, slide, suffix) {
                            if (parseInt(slide) !== targetIndex) {
                                console.log('[SCORM suspend_data] d-array Resume:', scene + '_' + slide, '->', scene + '_' + targetIndex);
                                anyChange = true;
                                return prefix + scene + '_' + targetIndex + suffix;
                            }
                            return match;
                        }
                    );

                    // 4. Modify reverse d-array - {"v":"0_7","n":"Resume"}
                    modified = modified.replace(
                        /("v"\s*:\s*")(\d+)_(\d+)("\s*,\s*"n"\s*:\s*"Resume")/gi,
                        function(match, prefix, scene, slide, suffix) {
                            if (parseInt(slide) !== targetIndex) {
                                console.log('[SCORM suspend_data] reverse d-array:', scene + '_' + slide, '->', scene + '_' + targetIndex);
                                anyChange = true;
                                return prefix + scene + '_' + targetIndex + suffix;
                            }
                            return match;
                        }
                    );

                    if (anyChange) {
                        // Re-compress with LZ-String
                        var recompressed = LZString.compressToBase64(modified);
                        if (recompressed) {
                            console.log('[SCORM suspend_data] Re-compressed successfully');
                            return recompressed;
                        }
                    } else {
                        console.log('[SCORM suspend_data] All fields already at target, no change needed');
                    }
                }
            } catch (e) {
                console.log('[SCORM suspend_data] LZ error:', e.message);
            }
        }

        return originalData;
    }

    // Wait for SCORM API to be available, then wrap it.
    function wrapScormApi() {
        // SCORM 1.2 API
        if (typeof window.API !== 'undefined' && window.API.LMSSetValue) {
            // Wrap LMSGetValue FIRST to intercept suspend_data reads
            if (window.API.LMSGetValue && pendingSlideNavigation) {
                var originalGetValue = window.API.LMSGetValue;
                window.API.LMSGetValue = function(element) {
                    var result = originalGetValue.call(window.API, element);

                    // Intercept suspend_data reads within the time/count window
                    // Storyline calls LMSGetValue multiple times during initialization
                    if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
                        // Start timer on first intercept
                        if (interceptStartTime === null) {
                            interceptStartTime = Date.now();
                        }

                        var withinWindow = (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
                        var underLimit = suspendDataInterceptCount < MAX_INTERCEPTS;

                        if (withinWindow && underLimit) {
                            suspendDataInterceptCount++;
                            console.log('[SCORM 1.2] LMSGetValue intercept #' + suspendDataInterceptCount + ' for slide:', pendingSlideNavigation.slide);

                            var modifiedData = modifySuspendDataForSlide(result, pendingSlideNavigation.slide);
                            if (modifiedData !== result) {
                                console.log('[SCORM 1.2] Returning modified suspend_data');
                                return modifiedData;
                            }
                        } else if (suspendDataInterceptCount > 0 && pendingSlideNavigation) {
                            // Window closed - stop intercepting
                            console.log('[SCORM 1.2] Intercept window closed after ' + suspendDataInterceptCount + ' intercepts');
                            pendingSlideNavigation = null; // Clear to stop future intercepts
                        }
                    }

                    return result;
                };
                console.log('[SCORM Navigation] LMSGetValue interceptor installed for pending navigation to slide:', pendingSlideNavigation.slide);
            }

            var originalSetValue = window.API.LMSSetValue;
            window.API.LMSSetValue = function(element, value) {
                var valueToWrite = value;

                // RE-ENABLED: Write interception for suspend_data during navigation window.
                // Only modifies the "l" field (slide position), NOT the visited array ("v").
                // This prevents Storyline from writing back its cached old position (e.g., slide 23)
                // when we're trying to navigate to a different slide (e.g., slide 13).
                if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
                    // Only intercept within the time/count window
                    var withinWindow = interceptStartTime !== null && (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
                    var underLimit = suspendDataInterceptCount <= MAX_INTERCEPTS;

                    if (withinWindow && underLimit) {
                        console.log('[SCORM 1.2] LMSSetValue intercepting suspend_data write for slide:', pendingSlideNavigation.slide);
                        var modifiedValue = modifySuspendDataForSlide(value, pendingSlideNavigation.slide);
                        if (modifiedValue !== value) {
                            console.log('[SCORM 1.2] Writing modified suspend_data to maintain slide:', pendingSlideNavigation.slide);
                            valueToWrite = modifiedValue;
                        }
                    }
                }

                var result = originalSetValue.call(window.API, element, valueToWrite);

                // DEBUG: Log all SCORM API calls to understand what the content sends.
                console.log('[SCORM 1.2] LMSSetValue:', element, '=', valueToWrite && valueToWrite.substring ? valueToWrite.substring(0, 200) : valueToWrite);

                // Track lesson_location changes.
                if (element === 'cmi.core.lesson_location' && valueToWrite !== lastLocation) {
                    lastLocation = valueToWrite;
                    sendProgressUpdate(valueToWrite, lastStatus, null, null);
                }
                // Track lesson_status changes.
                if (element === 'cmi.core.lesson_status') {
                    lastStatus = valueToWrite;
                    sendProgressUpdate(lastLocation, valueToWrite, null, null);
                }
                // Track score changes.
                // IMPORTANT: Score represents FURTHEST PROGRESS, not current position.
                if (element === 'cmi.core.score.raw') {
                    var score = parseFloat(valueToWrite);
                    if (!isNaN(score) && slidescount > 0 && score <= 100) {
                        // Calculate slide from score percentage.
                        var calculatedSlide = Math.round((score / 100) * slidescount);
                        calculatedSlide = Math.max(1, Math.min(calculatedSlide, slidescount));

                        // Always store as furthest progress (for progress bar).
                        if (furthestSlide === null || calculatedSlide > furthestSlide) {
                            furthestSlide = calculatedSlide;
                            console.log('[SCORM] Furthest progress updated:', furthestSlide);
                        }

                        // Check if we're in the intercept window - don't use score as current position
                        var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
                            (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

                        // Only use score-based slide for CURRENT position if no suspend_data AND not in intercept window.
                        // During intercept window, we have a pending navigation target, so don't override with score.
                        if (slideSource !== 'suspend_data' && lastSlide === null && !inInterceptWindow) {
                            console.log('[SCORM] Using score-based slide (fallback):', calculatedSlide);
                            slideSource = 'score';
                            sendProgressUpdate(null, lastStatus, valueToWrite, calculatedSlide);
                        } else {
                            // Don't change currentSlide, but send update with furthestSlide for progress bar.
                            console.log('[SCORM] Score indicates furthest progress:', furthestSlide, '(current slide:', lastSlide, ')');
                            sendProgressUpdate(lastLocation, lastStatus, valueToWrite, null);
                        }
                    } else {
                        sendProgressUpdate(lastLocation, lastStatus, valueToWrite, null);
                    }
                }
                // Track suspend_data changes (Articulate Storyline stores slide position here).
                // IMPORTANT: Storyline's suspend_data contains FURTHEST progress (resume), NOT current position.
                // The Poll mechanism is the source of truth for current position.
                // Only use suspend_data for furthest progress updates, not current position.
                if (element === 'cmi.suspend_data' && valueToWrite !== lastSuspendData) {
                    var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
                        (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

                    lastSuspendData = valueToWrite;
                    if (!inInterceptWindow) {
                        var slideNum = parseSlideFromSuspendData(valueToWrite);
                        if (slideNum !== null) {
                            // ONLY update furthestSlide from suspend_data, NOT current position
                            // Current position comes from Poll mechanism
                            if (furthestSlide === null || slideNum > furthestSlide) {
                                furthestSlide = slideNum;
                                console.log('[SCORM 1.2] suspend_data indicates furthest progress:', furthestSlide);
                                // Send update with furthest slide but DON'T change current position
                                sendProgressUpdate(lastLocation, lastStatus, null, null);
                            }
                        }
                    } else {
                        console.log('[SCORM 1.2] Skipping suspend_data tracking during intercept window');
                    }
                }

                return result;
            };
            return true;
        }

        // SCORM 2004 API
        if (typeof window.API_1484_11 !== 'undefined' && window.API_1484_11.SetValue) {
            // Wrap GetValue FIRST to intercept suspend_data reads
            if (window.API_1484_11.GetValue && pendingSlideNavigation) {
                var originalGetValue2004 = window.API_1484_11.GetValue;
                window.API_1484_11.GetValue = function(element) {
                    var result = originalGetValue2004.call(window.API_1484_11, element);

                    // Intercept suspend_data reads within the time/count window
                    // Storyline calls GetValue multiple times during initialization
                    if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
                        // Start timer on first intercept
                        if (interceptStartTime === null) {
                            interceptStartTime = Date.now();
                        }

                        var withinWindow = (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
                        var underLimit = suspendDataInterceptCount < MAX_INTERCEPTS;

                        if (withinWindow && underLimit) {
                            suspendDataInterceptCount++;
                            console.log('[SCORM 2004] GetValue intercept #' + suspendDataInterceptCount + ' for slide:', pendingSlideNavigation.slide);

                            var modifiedData = modifySuspendDataForSlide(result, pendingSlideNavigation.slide);
                            if (modifiedData !== result) {
                                console.log('[SCORM 2004] Returning modified suspend_data');
                                return modifiedData;
                            }
                        } else if (suspendDataInterceptCount > 0 && pendingSlideNavigation) {
                            // Window closed - stop intercepting
                            console.log('[SCORM 2004] Intercept window closed after ' + suspendDataInterceptCount + ' intercepts');
                            pendingSlideNavigation = null; // Clear to stop future intercepts
                        }
                    }

                    return result;
                };
                console.log('[SCORM Navigation] GetValue interceptor installed for pending navigation to slide:', pendingSlideNavigation.slide);
            }

            var originalSetValue2004 = window.API_1484_11.SetValue;
            window.API_1484_11.SetValue = function(element, value) {
                var valueToWrite = value;

                // RE-ENABLED: Write interception for suspend_data during navigation window.
                // Only modifies the "l" field (slide position), NOT the visited array ("v").
                // This prevents Storyline from writing back its cached old position (e.g., slide 23)
                // when we're trying to navigate to a different slide (e.g., slide 13).
                if (element === 'cmi.suspend_data' && pendingSlideNavigation) {
                    // Only intercept within the time/count window
                    var withinWindow = interceptStartTime !== null && (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;
                    var underLimit = suspendDataInterceptCount <= MAX_INTERCEPTS;

                    if (withinWindow && underLimit) {
                        console.log('[SCORM 2004] SetValue intercepting suspend_data write for slide:', pendingSlideNavigation.slide);
                        var modifiedValue = modifySuspendDataForSlide(value, pendingSlideNavigation.slide);
                        if (modifiedValue !== value) {
                            console.log('[SCORM 2004] Writing modified suspend_data to maintain slide:', pendingSlideNavigation.slide);
                            valueToWrite = modifiedValue;
                        }
                    }
                }

                var result = originalSetValue2004.call(window.API_1484_11, element, valueToWrite);

                // DEBUG: Log all SCORM API calls to understand what the content sends.
                console.log('[SCORM 2004] SetValue:', element, '=', valueToWrite && valueToWrite.substring ? valueToWrite.substring(0, 200) : valueToWrite);

                // Track location changes.
                if (element === 'cmi.location' && valueToWrite !== lastLocation) {
                    lastLocation = valueToWrite;
                    sendProgressUpdate(valueToWrite, lastStatus, null, null);
                }
                // Track completion_status changes.
                if (element === 'cmi.completion_status') {
                    lastStatus = valueToWrite;
                    sendProgressUpdate(lastLocation, valueToWrite, null, null);
                }
                // Track score changes.
                // IMPORTANT: Score represents FURTHEST PROGRESS, not current position.
                if (element === 'cmi.score.raw') {
                    var score = parseFloat(valueToWrite);
                    if (!isNaN(score) && slidescount > 0 && score <= 100) {
                        // Calculate slide from score percentage.
                        var calculatedSlide = Math.round((score / 100) * slidescount);
                        calculatedSlide = Math.max(1, Math.min(calculatedSlide, slidescount));

                        // Always store as furthest progress (for progress bar).
                        if (furthestSlide === null || calculatedSlide > furthestSlide) {
                            furthestSlide = calculatedSlide;
                            console.log('[SCORM 2004] Furthest progress updated:', furthestSlide);
                        }

                        // Check if we're in the intercept window - don't use score as current position
                        var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
                            (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

                        // Only use score-based slide for CURRENT position if no suspend_data AND not in intercept window.
                        // During intercept window, we have a pending navigation target, so don't override with score.
                        if (slideSource !== 'suspend_data' && lastSlide === null && !inInterceptWindow) {
                            console.log('[SCORM 2004] Using score-based slide (fallback):', calculatedSlide);
                            slideSource = 'score';
                            sendProgressUpdate(null, lastStatus, valueToWrite, calculatedSlide);
                        } else {
                            // Don't change currentSlide, but send update with furthestSlide for progress bar.
                            console.log('[SCORM 2004] Score indicates furthest progress:', furthestSlide, '(current slide:', lastSlide, ')');
                            sendProgressUpdate(lastLocation, lastStatus, valueToWrite, null);
                        }
                    } else {
                        sendProgressUpdate(lastLocation, lastStatus, valueToWrite, null);
                    }
                }
                // Track suspend_data changes (Articulate Storyline stores slide position here).
                // IMPORTANT: Storyline's suspend_data contains FURTHEST progress (resume), NOT current position.
                // The Poll mechanism is the source of truth for current position.
                // Only use suspend_data for furthest progress updates, not current position.
                if (element === 'cmi.suspend_data' && valueToWrite !== lastSuspendData) {
                    var inInterceptWindow = pendingSlideNavigation && interceptStartTime !== null &&
                        (Date.now() - interceptStartTime) < INTERCEPT_WINDOW_MS;

                    lastSuspendData = valueToWrite;
                    if (!inInterceptWindow) {
                        var slideNum = parseSlideFromSuspendData(valueToWrite);
                        if (slideNum !== null) {
                            // ONLY update furthestSlide from suspend_data, NOT current position
                            // Current position comes from Poll mechanism
                            if (furthestSlide === null || slideNum > furthestSlide) {
                                furthestSlide = slideNum;
                                console.log('[SCORM 2004] suspend_data indicates furthest progress:', furthestSlide);
                                // Send update with furthest slide but DON'T change current position
                                sendProgressUpdate(lastLocation, lastStatus, null, null);
                            }
                        }
                    } else {
                        console.log('[SCORM 2004] Skipping suspend_data tracking during intercept window');
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

    // DISABLED: Direct navigation fallback was causing conflicts with suspend_data interception.
    // The multiple-intercept approach to LMSGetValue is now the primary navigation mechanism.
    // Keeping sessionStorage clear logic only to prevent stale data.
    if (pendingSlideNavigation) {
        console.log('[SCORM Navigation] Pending navigation detected for slide:', pendingSlideNavigation.slide);
        console.log('[SCORM Navigation] Using LMSGetValue intercept (no direct API fallback)');

        // Note: sessionStorage is cleared after reading (line ~1103), so no additional cleanup needed
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
                var iframeDoc = iframeWin.document;

                // iSpring exposes iSpringPresentationAPI or window.frames.content
                if (iframeWin.iSpringPresentationAPI ||
                    iframeWin.ispringPresentationConnector ||
                    iframeWin.ISPRING) {
                    console.log('[iSpring] Found via API objects');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's player object
                if (iframeWin.player && typeof iframeWin.player.view !== 'undefined') {
                    console.log('[iSpring] Found via player.view');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's g_oPresentation global (PowerPoint export)
                if (iframeWin.g_oPresentation || iframeWin.g_oPres || iframeWin.oPresentation) {
                    console.log('[iSpring] Found via g_oPresentation');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's PresentationAPI or PresentationManager
                if (iframeWin.PresentationAPI || iframeWin.PresentationManager || iframeWin.presentationApi) {
                    console.log('[iSpring] Found via PresentationAPI/Manager');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's slide navigation functions
                if (iframeWin.gotoSlide || iframeWin.goToSlide || iframeWin.navigateToSlide) {
                    console.log('[iSpring] Found via gotoSlide function');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's player UI elements
                if (iframeDoc.querySelector('#ispring-player, .ispring-player, [class*="ispring"], #presentation-container')) {
                    console.log('[iSpring] Found via DOM elements');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for iSpring's slide container
                if (iframeDoc.querySelector('#slide-container, .slide-container, #slides-container, .slides-wrapper')) {
                    console.log('[iSpring] Found via slide container');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check nested iframes (iSpring often uses nested structure)
                var nestedIframes = iframeDoc.querySelectorAll('iframe');
                for (var j = 0; j < nestedIframes.length; j++) {
                    try {
                        var nestedWin = nestedIframes[j].contentWindow;
                        var nestedDoc = nestedWin.document;
                        if (nestedWin.iSpringPresentationAPI || nestedWin.g_oPresentation ||
                            nestedWin.gotoSlide || nestedWin.player) {
                            console.log('[iSpring] Found in nested iframe');
                            return { iframe: nestedIframes[j], window: nestedWin, document: nestedDoc };
                        }
                    } catch (e) {
                        // Cross-origin nested iframe
                    }
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
                    console.log('[Rise 360] Found via rise-blocks/rise-lesson');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for Rise's app container
                if (iframeDoc.querySelector('#app, .rise-app, [data-rise-version]')) {
                    console.log('[Rise 360] Found via #app/rise-app');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for Articulate Rise specific elements
                if (iframeDoc.querySelector('.blocks, .block-list, [class*="block-"], .outline, .outline__item')) {
                    console.log('[Rise 360] Found via blocks/outline');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check for Rise navigation patterns
                if (iframeDoc.querySelector('.course-nav, .lesson-nav, .nav-sidebar, [class*="nav-"]')) {
                    // Additional check to make sure it's Rise and not something else
                    if (iframeDoc.querySelector('[class*="lesson"], [class*="block"], [class*="outline"]')) {
                        console.log('[Rise 360] Found via navigation patterns');
                        return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                    }
                }
                // Check for Rise's internal state objects
                if (iframeWin.__RISE_STATE__ || iframeWin.Rise || iframeWin.riseState || iframeWin.riseNavigation) {
                    console.log('[Rise 360] Found via internal state object');
                    return { iframe: iframes[i], window: iframeWin, document: iframeDoc };
                }
                // Check nested iframes
                var nestedIframes = iframeDoc.querySelectorAll('iframe');
                for (var j = 0; j < nestedIframes.length; j++) {
                    try {
                        var nestedWin = nestedIframes[j].contentWindow;
                        var nestedDoc = nestedWin.document;
                        if (nestedDoc.querySelector('.rise-blocks, .rise-lesson, [class*="rise"], .blocks, .outline')) {
                            console.log('[Rise 360] Found in nested iframe');
                            return { iframe: nestedIframes[j], window: nestedWin, document: nestedDoc };
                        }
                    } catch (e) {
                        // Cross-origin nested iframe
                    }
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
    // IMPORTANT: This is a fallback only when SCORM API tracking is not working.
    setTimeout(function() {
        genericCheckInterval = setInterval(function() {
            // Only run generic detection if no other tool detected anything
            if (storylineSlideIndex !== null || iSpringSlideIndex !== null ||
                captivateSlideIndex !== null || rise360SectionIndex !== null ||
                lectoraPageIndex !== null) {
                return; // Another detector is working
            }

            // IMPORTANT: If SCORM API tracking has already given us a valid slide number,
            // don't override it with generic detection. The SCORM API (score.raw, suspend_data)
            // is more reliable than DOM-based detection which can find false positives.
            if (lastSlide !== null && lastSlide > 1) {
                // SCORM API tracking is working, skip generic detection
                return;
            }

            var content = findGenericScormContent();
            if (content) {
                var currentPosition = getGenericCurrentPosition(content);
                // Only report if:
                // 1. We got a valid position
                // 2. It's different from what we had
                // 3. It's greater than 1 (to avoid common false positives)
                // 4. It's within reasonable bounds (not more than slidescount if known)
                if (currentPosition !== null &&
                    currentPosition !== genericSlideIndex &&
                    currentPosition > 1 &&
                    (slidescount === 0 || currentPosition <= slidescount)) {
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

        // Report what's being used for tracking
        if (detected.length > 0) {
            console.log('[SCORM Multi-Tool Support] Detected: ' + detected.join(', '));
        } else if (lastSlide !== null && lastSlide > 0) {
            console.log('[SCORM Multi-Tool Support] Using SCORM API tracking (score/suspend_data). Current slide: ' + lastSlide);
        } else if (findGenericScormContent()) {
            console.log('[SCORM Multi-Tool Support] Using Generic HTML5 SCORM detection as fallback');
        } else {
            console.log('[SCORM Multi-Tool Support] No specific authoring tool detected. Using SCORM API tracking.');
        }
    }, 5000);

    // ==========================================================================
    // SLIDE NAVIGATION FROM PARENT WINDOW
    // Listen for navigation requests from SmartLearning
    // ==========================================================================

    /**
     * Attempt to navigate to a specific slide.
     * Tries different methods depending on the authoring tool.
     * @param {number} targetSlide - The 1-based slide number to navigate to.
     * @returns {boolean} True if navigation was attempted.
     */
    function navigateToSlide(targetSlide) {
        console.log('[SCORM Navigation] Attempting to navigate to slide:', targetSlide);

        // Try Articulate Storyline
        var storylinePlayer = findStorylinePlayer();
        if (storylinePlayer && storylinePlayer.window) {
            try {
                var win = storylinePlayer.window;

                // Method 1: Storyline's goToSlide function
                if (win.goToSlide) {
                    win.goToSlide(targetSlide - 1); // 0-based
                    console.log('[SCORM Navigation] Storyline goToSlide called');
                    return true;
                }

                // Method 2: GetPlayer().SetVar for slide navigation
                if (win.GetPlayer) {
                    var player = win.GetPlayer();
                    if (player && player.SetVar) {
                        // Try common Storyline variables for navigation
                        try {
                            player.SetVar('Jump', targetSlide);
                            console.log('[SCORM Navigation] Storyline SetVar Jump called');
                            return true;
                        } catch (e) {}
                    }
                }

                // Method 3: Direct hash navigation
                var hash = win.location.hash;
                if (hash && hash.includes('slide')) {
                    // Try to update hash to navigate
                    var newHash = hash.replace(/slide[_\-]?(\d+)/i, 'slide' + (targetSlide - 1));
                    if (newHash !== hash) {
                        win.location.hash = newHash;
                        console.log('[SCORM Navigation] Storyline hash navigation attempted');
                        return true;
                    }
                }
            } catch (e) {
                console.log('[SCORM Navigation] Storyline navigation error:', e.message);
            }
        }

        // Try Adobe Captivate
        var captivatePlayer = findCaptivatePlayer();
        if (captivatePlayer && captivatePlayer.window) {
            try {
                var win = captivatePlayer.window;

                // Method 1: cpCmndGotoSlide function
                if (win.cpCmndGotoSlide) {
                    win.cpCmndGotoSlide(targetSlide - 1); // 0-based
                    console.log('[SCORM Navigation] Captivate cpCmndGotoSlide called');
                    return true;
                }

                // Method 2: cpAPIInterface
                if (win.cpAPIInterface && win.cpAPIInterface.gotoSlide) {
                    win.cpAPIInterface.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] Captivate cpAPIInterface.gotoSlide called');
                    return true;
                }

                // Method 3: cp.movie.gotoSlide
                if (win.cp && win.cp.movie && win.cp.movie.gotoSlide) {
                    win.cp.movie.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] Captivate cp.movie.gotoSlide called');
                    return true;
                }
            } catch (e) {
                console.log('[SCORM Navigation] Captivate navigation error:', e.message);
            }
        }

        // Try iSpring
        var iSpringPlayer = findISpringPlayer();
        if (iSpringPlayer && iSpringPlayer.window) {
            try {
                var win = iSpringPlayer.window;
                var doc = iSpringPlayer.document;

                console.log('[SCORM Navigation] iSpring player found, trying navigation methods');

                // Method 1: iSpringPresentationAPI.gotoSlide
                if (win.iSpringPresentationAPI && win.iSpringPresentationAPI.gotoSlide) {
                    win.iSpringPresentationAPI.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring API gotoSlide called');
                    return true;
                }

                // Method 2: ispringPresentationConnector.gotoSlide
                if (win.ispringPresentationConnector && win.ispringPresentationConnector.gotoSlide) {
                    win.ispringPresentationConnector.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring connector gotoSlide called');
                    return true;
                }

                // Method 3: Direct gotoSlide/goToSlide function
                if (win.gotoSlide) {
                    win.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring gotoSlide() called');
                    return true;
                }
                if (win.goToSlide) {
                    win.goToSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring goToSlide() called');
                    return true;
                }

                // Method 4: g_oPresentation (PowerPoint export)
                if (win.g_oPresentation && win.g_oPresentation.gotoSlide) {
                    win.g_oPresentation.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring g_oPresentation.gotoSlide called');
                    return true;
                }
                if (win.g_oPres && win.g_oPres.gotoSlide) {
                    win.g_oPres.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring g_oPres.gotoSlide called');
                    return true;
                }

                // Method 5: PresentationAPI/Manager
                if (win.PresentationAPI && win.PresentationAPI.gotoSlide) {
                    win.PresentationAPI.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring PresentationAPI.gotoSlide called');
                    return true;
                }
                if (win.PresentationManager && win.PresentationManager.gotoSlide) {
                    win.PresentationManager.gotoSlide(targetSlide - 1);
                    console.log('[SCORM Navigation] iSpring PresentationManager.gotoSlide called');
                    return true;
                }

                // Method 6: player object
                if (win.player) {
                    if (win.player.gotoSlide) {
                        win.player.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] iSpring player.gotoSlide called');
                        return true;
                    }
                    if (win.player.goToSlide) {
                        win.player.goToSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] iSpring player.goToSlide called');
                        return true;
                    }
                    if (win.player.setSlideIndex) {
                        win.player.setSlideIndex(targetSlide - 1);
                        console.log('[SCORM Navigation] iSpring player.setSlideIndex called');
                        return true;
                    }
                }

                // Method 7: ISPRING global object
                if (win.ISPRING) {
                    if (win.ISPRING.gotoSlide) {
                        win.ISPRING.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] iSpring ISPRING.gotoSlide called');
                        return true;
                    }
                    if (win.ISPRING.presentation && win.ISPRING.presentation.gotoSlide) {
                        win.ISPRING.presentation.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] iSpring ISPRING.presentation.gotoSlide called');
                        return true;
                    }
                }

                // Method 8: Try clicking slide thumbnail/navigation
                var slideNavItems = doc.querySelectorAll('.slide-thumbnail, .slide-nav-item, [data-slide-index], .outline-item, .toc-item');
                console.log('[SCORM Navigation] iSpring found', slideNavItems.length, 'slide nav items');
                if (slideNavItems.length >= targetSlide) {
                    var targetItem = slideNavItems[targetSlide - 1];
                    if (targetItem) {
                        var clickTarget = targetItem.querySelector('a, button') || targetItem;
                        clickTarget.click();
                        console.log('[SCORM Navigation] iSpring clicked slide nav item', targetSlide - 1);
                        return true;
                    }
                }

                // Method 9: Try dispatching custom events that iSpring might listen to
                var slideEvent = new CustomEvent('gotoSlide', { detail: { slideIndex: targetSlide - 1 } });
                doc.dispatchEvent(slideEvent);
                win.dispatchEvent(slideEvent);
                console.log('[SCORM Navigation] iSpring dispatched gotoSlide event');

            } catch (e) {
                console.log('[SCORM Navigation] iSpring navigation error:', e.message);
            }
        }

        // Try Lectora
        var lectoraPlayer = findLectoraPlayer();
        if (lectoraPlayer && lectoraPlayer.window) {
            try {
                var win = lectoraPlayer.window;

                // Method 1: TrivAPI.GoToPage
                if (win.TrivAPI && win.TrivAPI.GoToPage) {
                    win.TrivAPI.GoToPage(targetSlide);
                    console.log('[SCORM Navigation] Lectora TrivAPI.GoToPage called');
                    return true;
                }

                // Method 2: trivExternalCall
                if (win.trivExternalCall) {
                    win.trivExternalCall('GoToPage', targetSlide);
                    console.log('[SCORM Navigation] Lectora trivExternalCall GoToPage called');
                    return true;
                }
            } catch (e) {
                console.log('[SCORM Navigation] Lectora navigation error:', e.message);
            }
        }

        // Try Articulate Rise 360
        var rise360Player = findRise360Player();
        if (rise360Player && rise360Player.window) {
            try {
                var win = rise360Player.window;
                var doc = rise360Player.document;

                // Method 1: Rise 360 hash navigation (lessons/sections)
                // Rise uses URL hash like #/lessons/0, #/lessons/1, etc.
                var currentHash = win.location.hash;
                console.log('[SCORM Navigation] Rise 360 current hash:', currentHash);

                // Try different Rise 360 hash patterns
                var hashPatterns = [
                    '#/lessons/' + (targetSlide - 1),
                    '#/lesson/' + (targetSlide - 1),
                    '#/sections/' + (targetSlide - 1),
                    '#/section/' + (targetSlide - 1),
                    '#/' + (targetSlide - 1)
                ];

                for (var p = 0; p < hashPatterns.length; p++) {
                    try {
                        win.location.hash = hashPatterns[p];
                        console.log('[SCORM Navigation] Rise 360 hash navigation attempted:', hashPatterns[p]);
                        // Give it a moment and check if it worked
                        return true;
                    } catch (e) {}
                }

                // Method 2: Rise 360 internal state/API
                if (win.__RISE_STATE__ && win.__RISE_STATE__.goToLesson) {
                    win.__RISE_STATE__.goToLesson(targetSlide - 1);
                    console.log('[SCORM Navigation] Rise 360 goToLesson called');
                    return true;
                }
                if (win.Rise && win.Rise.navigation && win.Rise.navigation.goTo) {
                    win.Rise.navigation.goTo(targetSlide - 1);
                    console.log('[SCORM Navigation] Rise 360 Rise.navigation.goTo called');
                    return true;
                }
                if (win.riseNavigation && win.riseNavigation.goToLesson) {
                    win.riseNavigation.goToLesson(targetSlide - 1);
                    console.log('[SCORM Navigation] Rise 360 riseNavigation.goToLesson called');
                    return true;
                }

                // Method 3: Click on navigation item
                var navItems = doc.querySelectorAll('.rise-nav-item, .lesson-nav-item, [data-lesson-index], .nav-item, .outline__item');
                console.log('[SCORM Navigation] Rise 360 found', navItems.length, 'nav items');
                if (navItems.length >= targetSlide) {
                    var targetNav = navItems[targetSlide - 1];
                    if (targetNav) {
                        // Try clicking the nav item or its link
                        var clickTarget = targetNav.querySelector('a, button') || targetNav;
                        clickTarget.click();
                        console.log('[SCORM Navigation] Rise 360 clicked nav item', targetSlide - 1);
                        return true;
                    }
                }

                // Method 4: Look for and click lesson links in sidebar/outline
                var lessonLinks = doc.querySelectorAll('a[href*="lesson"], a[href*="section"], .lesson-link, .outline-link');
                console.log('[SCORM Navigation] Rise 360 found', lessonLinks.length, 'lesson links');
                for (var l = 0; l < lessonLinks.length; l++) {
                    var link = lessonLinks[l];
                    var href = link.getAttribute('href') || '';
                    if (href.includes('/' + (targetSlide - 1)) || href.includes('/' + targetSlide)) {
                        link.click();
                        console.log('[SCORM Navigation] Rise 360 clicked lesson link:', href);
                        return true;
                    }
                }

            } catch (e) {
                console.log('[SCORM Navigation] Rise 360 navigation error:', e.message);
            }
        }

        // Generic: Try to set SCORM lesson_location and trigger refresh
        // This is a last resort and may not work with all content
        try {
            if (window.API && window.API.LMSSetValue) {
                // Try setting lesson_location to trigger navigation
                window.API.LMSSetValue('cmi.core.lesson_location', String(targetSlide));
                console.log('[SCORM Navigation] Set cmi.core.lesson_location to:', targetSlide);
                // Note: This alone won't cause navigation, but some content may respond
            }
            if (window.API_1484_11 && window.API_1484_11.SetValue) {
                window.API_1484_11.SetValue('cmi.location', String(targetSlide));
                console.log('[SCORM Navigation] Set cmi.location to:', targetSlide);
            }
        } catch (e) {
            console.log('[SCORM Navigation] SCORM API navigation error:', e.message);
        }

        // Try to find and navigate in inner SCORM content iframes
        var iframes = document.querySelectorAll('iframe');
        for (var i = 0; i < iframes.length; i++) {
            try {
                var innerWin = iframes[i].contentWindow;
                if (!innerWin) continue;

                // Try posting navigation message to inner iframe
                innerWin.postMessage({
                    type: 'scorm-navigate-to-slide',
                    cmid: cmid,
                    slide: targetSlide
                }, '*');
                console.log('[SCORM Navigation] Posted navigation to inner iframe');

                // Try direct navigation methods in inner iframe
                try {
                    var innerDoc = innerWin.document;

                    // Check for common slide navigation functions
                    if (innerWin.goToSlide) {
                        innerWin.goToSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe goToSlide called');
                        return true;
                    }
                    if (innerWin.gotoSlide) {
                        innerWin.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe gotoSlide called');
                        return true;
                    }
                    if (innerWin.setSlide) {
                        innerWin.setSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe setSlide called');
                        return true;
                    }
                    if (innerWin.jumpToSlide) {
                        innerWin.jumpToSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe jumpToSlide called');
                        return true;
                    }

                    // Try Storyline's GetPlayer in inner iframe
                    if (innerWin.GetPlayer) {
                        var player = innerWin.GetPlayer();
                        if (player) {
                            // Storyline uses 0-based slide indices, but some use variable names
                            try {
                                if (player.SetVar) player.SetVar('JumpToSlide', targetSlide);
                            } catch(e) {}
                            try {
                                if (player.SetVar) player.SetVar('Jump', targetSlide);
                            } catch(e) {}
                            console.log('[SCORM Navigation] Inner iframe GetPlayer SetVar attempted');
                        }
                    }

                    // Try iSpring APIs in inner iframe
                    if (innerWin.iSpringPresentationAPI && innerWin.iSpringPresentationAPI.gotoSlide) {
                        innerWin.iSpringPresentationAPI.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe iSpring API gotoSlide called');
                        return true;
                    }
                    if (innerWin.g_oPresentation && innerWin.g_oPresentation.gotoSlide) {
                        innerWin.g_oPresentation.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe iSpring g_oPresentation.gotoSlide called');
                        return true;
                    }
                    if (innerWin.ispringPresentationConnector && innerWin.ispringPresentationConnector.gotoSlide) {
                        innerWin.ispringPresentationConnector.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe iSpring connector gotoSlide called');
                        return true;
                    }
                    if (innerWin.player && innerWin.player.gotoSlide) {
                        innerWin.player.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe iSpring player.gotoSlide called');
                        return true;
                    }
                    if (innerWin.ISPRING && innerWin.ISPRING.gotoSlide) {
                        innerWin.ISPRING.gotoSlide(targetSlide - 1);
                        console.log('[SCORM Navigation] Inner iframe iSpring ISPRING.gotoSlide called');
                        return true;
                    }

                    // Try video player seek (if content is video-based)
                    if (innerWin.player && innerWin.player.seekTo) {
                        // Can't convert slide to time without mapping
                        console.log('[SCORM Navigation] Inner iframe has video player, cannot convert slide to time');
                    }

                } catch (e) {
                    // Cross-origin, continue to next iframe
                }
            } catch (e) {
                // Cross-origin frame access, skip
            }
        }

        // ==========================================================================
        // SUSPEND_DATA MODIFICATION: Last resort for SCORM that tracks via suspend_data
        // Modify the resume position and reload the SCORM content
        // ==========================================================================

        console.log('[SCORM Navigation] Trying suspend_data modification approach...');

        var suspendDataModified = modifySuspendDataAndReload(targetSlide);
        if (suspendDataModified) {
            console.log('[SCORM Navigation] suspend_data modified, SCORM content will reload');
            return true;
        }

        console.log('[SCORM Navigation] No navigation method available. User must navigate manually to slide:', targetSlide);
        return false;
    }

    /**
     * Store pending navigation in sessionStorage and reload SCORM content.
     * The LMSGetValue interceptor will modify suspend_data on the first read after reload.
     * This approach works because we intercept BEFORE the content initializes.
     * @param {number} targetSlide - The 1-based slide number to navigate to.
     * @returns {boolean} True if navigation was initiated.
     */
    function modifySuspendDataAndReload(targetSlide) {
        console.log('[SCORM suspend_data] Setting up pending navigation to slide:', targetSlide);

        // Store navigation target in sessionStorage
        // This will be read by the LMSGetValue interceptor on the next page load
        try {
            var navData = {
                slide: targetSlide,
                cmid: cmid,
                timestamp: Date.now()
            };
            sessionStorage.setItem('scorm_pending_navigation_' + cmid, JSON.stringify(navData));
            console.log('[SCORM suspend_data] Pending navigation stored:', navData);
        } catch (e) {
            console.log('[SCORM suspend_data] Failed to store pending navigation:', e.message);
            return false;
        }

        // Reload the SCORM content
        // When it reloads, the LMSGetValue interceptor will return modified suspend_data
        reloadScormContentIframe();

        return true;
    }

    /**
     * Modify suspend_data text (non-JSON) with new slide position.
     * This handles various SCORM authoring tool formats via regex replacement.
     * Uses flexible patterns similar to extractSlideFromText for detection.
     */
    function modifySuspendDataText(text, targetSlide) {
        var targetIndex = targetSlide - 1;
        var modified = text;
        var changesMade = false;

        console.log('[SCORM suspend_data text] Attempting text-based modification for slide:', targetSlide, '(index:', targetIndex, ')');
        console.log('[SCORM suspend_data text] Text sample (first 500 chars):', text.substring(0, 500));

        // Pattern 1: scene_slide format with flexible quoting - "resume":"0_7", resume:0_7, etc.
        // Captures: $1=prefix (resume + quotes/separator), $2=scene, $3=slide, $4=suffix
        var p1 = modified.replace(
            /(["']?resume["']?\s*[:=]\s*["']?)(\d+)_(\d+)(["']?)/gi,
            function(match, prefix, scene, slide, suffix) {
                console.log('[SCORM suspend_data text] Replacing resume scene_slide:', match, '->', prefix + scene + '_' + targetIndex + suffix);
                changesMade = true;
                return prefix + scene + '_' + targetIndex + suffix;
            }
        );
        if (p1 !== modified) modified = p1;

        // Pattern 2: Simple resume number - "resume":"7" or resume:7
        var p2 = modified.replace(
            /(["']?resume["']?\s*[:=]\s*["']?)(\d+)(["']?)(?![_\d])/gi,
            function(match, prefix, oldSlide, suffix) {
                // Skip if this is part of a scene_slide (already handled)
                if (match.indexOf('_') !== -1) return match;
                console.log('[SCORM suspend_data text] Replacing resume:', match, '->', prefix + targetIndex + suffix);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p2 !== modified) modified = p2;

        // Pattern 3: Storyline d-array - "n":"Resume"..."v":"0_7" or reverse
        var p3 = modified.replace(
            /("n"\s*:\s*"Resume"[^}]{0,50}"v"\s*:\s*")(\d+)_(\d+)(")/gi,
            function(match, prefix, scene, slide, suffix) {
                console.log('[SCORM suspend_data text] Replacing d-array Resume:', scene + '_' + slide, '->', scene + '_' + targetIndex);
                changesMade = true;
                return prefix + scene + '_' + targetIndex + suffix;
            }
        );
        if (p3 !== modified) modified = p3;

        // Pattern 4: Reverse order "v":"0_7"..."n":"Resume"
        var p4 = modified.replace(
            /("v"\s*:\s*")(\d+)_(\d+)("[^}]{0,50}"n"\s*:\s*"Resume")/gi,
            function(match, prefix, scene, slide, suffix) {
                console.log('[SCORM suspend_data text] Replacing reverse d-array:', scene + '_' + slide, '->', scene + '_' + targetIndex);
                changesMade = true;
                return prefix + scene + '_' + targetIndex + suffix;
            }
        );
        if (p4 !== modified) modified = p4;

        // Pattern 5: currentSlide with flexible quoting
        var p5 = modified.replace(
            /(["']?currentSlide["']?\s*[:=]\s*["']?)(\d+)(["']?)/gi,
            function(match, prefix, oldSlide, suffix) {
                console.log('[SCORM suspend_data text] Replacing currentSlide:', oldSlide, '->', targetIndex);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p5 !== modified) modified = p5;

        // Pattern 6: CurrentSlideIndex
        var p6 = modified.replace(
            /(["']?CurrentSlideIndex["']?\s*[:=]\s*["']?)(\d+)(["']?)/gi,
            function(match, prefix, oldSlide, suffix) {
                console.log('[SCORM suspend_data text] Replacing CurrentSlideIndex:', oldSlide, '->', targetIndex);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p6 !== modified) modified = p6;

        // Pattern 7: slide key
        var p7 = modified.replace(
            /(["']?slide["']?\s*[:=]\s*["']?)(\d+)(["']?)(?![_\d])/gi,
            function(match, prefix, oldSlide, suffix) {
                console.log('[SCORM suspend_data text] Replacing slide:', oldSlide, '->', targetIndex);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p7 !== modified) modified = p7;

        // Pattern 8: position key
        var p8 = modified.replace(
            /(["']?position["']?\s*[:=]\s*["']?)(\d+)(["']?)(?![_\d])/gi,
            function(match, prefix, oldSlide, suffix) {
                console.log('[SCORM suspend_data text] Replacing position:', oldSlide, '->', targetIndex);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p8 !== modified) modified = p8;

        // Pattern 9: state keyword followed by scene_slide (common bookmark format)
        var p9 = modified.replace(
            /(["']?(?:state|bookmark)["']?\s*[:=]\s*["']?)(\d+)_(\d+)(["']?)/gi,
            function(match, prefix, scene, slide, suffix) {
                console.log('[SCORM suspend_data text] Replacing state/bookmark:', scene + '_' + slide, '->', scene + '_' + targetIndex);
                changesMade = true;
                return prefix + scene + '_' + targetIndex + suffix;
            }
        );
        if (p9 !== modified) modified = p9;

        // Pattern 10: pageIndex or slideIndex
        var p10 = modified.replace(
            /(["']?(?:page|slide)Index["']?\s*[:=]\s*["']?)(\d+)(["']?)/gi,
            function(match, prefix, oldSlide, suffix) {
                console.log('[SCORM suspend_data text] Replacing Index:', oldSlide, '->', targetIndex);
                changesMade = true;
                return prefix + targetIndex + suffix;
            }
        );
        if (p10 !== modified) modified = p10;

        if (!changesMade) {
            console.log('[SCORM suspend_data text] No patterns matched in text');
            // Log what patterns ARE in the text for debugging
            var debugPatterns = [
                /resume/gi,
                /slide/gi,
                /position/gi,
                /current/gi,
                /\d+_\d+/g
            ];
            for (var dp = 0; dp < debugPatterns.length; dp++) {
                var matches = text.match(debugPatterns[dp]);
                if (matches) {
                    console.log('[SCORM suspend_data text] Found pattern:', debugPatterns[dp].source, '-> matches:', matches.slice(0, 5).join(', '));
                }
            }
        }

        return modified;
    }

    /**
     * Find and reload the SCORM content iframe.
     */
    function reloadScormContentIframe() {
        console.log('[SCORM suspend_data] Looking for SCORM content iframe to reload...');

        // Look for the SCORM content iframe
        var iframes = document.querySelectorAll('iframe');
        var reloaded = false;

        for (var i = 0; i < iframes.length; i++) {
            var iframe = iframes[i];
            var src = iframe.src || '';

            // Look for SCORM content iframes (typically contain the actual content)
            // Skip the outer Moodle player iframes
            if (src.indexOf('/mod/scorm/') === -1 && src.length > 0) {
                try {
                    console.log('[SCORM suspend_data] Reloading iframe:', src.substring(0, 100));
                    iframe.contentWindow.location.reload();
                    reloaded = true;
                } catch (e) {
                    // Cross-origin, try setting src
                    try {
                        var currentSrc = iframe.src;
                        iframe.src = '';
                        setTimeout(function() {
                            iframe.src = currentSrc;
                        }, 100);
                        console.log('[SCORM suspend_data] Reloaded iframe via src reassignment');
                        reloaded = true;
                    } catch (e2) {
                        console.log('[SCORM suspend_data] Could not reload iframe:', e2.message);
                    }
                }
                break;
            }
        }

        // If no iframe found, reload the whole page (will re-read suspend_data)
        if (!reloaded) {
            console.log('[SCORM suspend_data] No iframe found, reloading current window');
            window.location.reload();
        }
    }

    // Listen for navigation requests from SmartLearning parent window
    window.addEventListener('message', function(event) {
        // Debug: log all received messages
        console.log('[SCORM Navigation] Message received from:', event.origin, 'type:', event.data?.type, 'data:', event.data);

        // Check for navigation request message
        if (event.data && event.data.type === 'scorm-navigate-to-slide') {
            var targetCmid = event.data.cmid;
            var targetSlide = event.data.slide;

            // Verify this message is for this SCORM module
            if (targetCmid && targetCmid !== cmid) {
                console.log('[SCORM Navigation] Ignoring navigation request for different cmid:', targetCmid);
                return;
            }

            if (targetSlide && !isNaN(targetSlide)) {
                console.log('[SCORM Navigation] Received navigation request to slide:', targetSlide);
                var success = navigateToSlide(parseInt(targetSlide, 10));

                // Send response back to parent
                var response = {
                    type: 'scorm-navigation-result',
                    cmid: cmid,
                    targetSlide: targetSlide,
                    success: success,
                    currentSlide: lastSlide
                };

                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(response, '*');
                }
                if (window.top && window.top !== window && window.top !== window.parent) {
                    window.top.postMessage(response, '*');
                }
            }
        }
    }, false);

    console.log('[SCORM Navigation] Navigation listener registered for cmid:', cmid);
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
