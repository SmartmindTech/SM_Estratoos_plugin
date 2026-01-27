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
 * Chromeless activity renderer for SmartLearning iframe embedding.
 *
 * This class renders Moodle activities without navigation, header, or footer
 * for seamless embedding in SmartLearning's interface.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Chromeless activity renderer class.
 */
class embed_renderer {

    /** @var \cm_info Course module info */
    private $cm;

    /** @var \context_module Module context */
    private $context;

    /** @var string Activity type */
    private $activityType;

    /** @var int|null Target slide for SCORM navigation */
    private $targetSlide;

    /**
     * Constructor.
     *
     * @param \cm_info $cm Course module info
     * @param int|null $targetSlide Optional target slide for direct SCORM navigation
     */
    public function __construct(\cm_info $cm, ?int $targetSlide = null) {
        $this->cm = $cm;
        $this->context = \context_module::instance($cm->id);
        $this->activityType = $cm->modname;
        $this->targetSlide = $targetSlide;
    }

    /**
     * Render the activity content without Moodle chrome.
     *
     * For activities that require full Moodle functionality (SCORM, Quiz, etc.),
     * we redirect to the native Moodle page instead of using iframes. This avoids
     * cross-origin cookie issues that prevent session sharing in iframes.
     *
     * @return string HTML content or performs redirect
     */
    public function render(): string {
        global $PAGE, $OUTPUT, $CFG;

        // Set up minimal page.
        $PAGE->set_pagelayout('embedded');
        $PAGE->set_context($this->context);
        $PAGE->set_cm($this->cm);
        $PAGE->set_title($this->cm->name);

        // Trigger module viewed event (for completion tracking).
        $this->trigger_module_viewed();

        // For complex activities, redirect to native Moodle page.
        // This avoids cross-origin iframe cookie issues.
        switch ($this->activityType) {
            case 'scorm':
                return $this->redirect_to_scorm();
            case 'quiz':
                return $this->redirect_to_activity();
            case 'assign':
                return $this->redirect_to_activity();
            case 'lesson':
                return $this->redirect_to_activity();
            case 'book':
                return $this->redirect_to_activity();
            // Simple content types can be rendered inline.
            case 'page':
                return $this->render_page();
            case 'resource':
                return $this->render_resource();
            case 'url':
                return $this->render_url();
            default:
                return $this->redirect_to_activity();
        }
    }

    /**
     * Redirect to the native Moodle activity page.
     *
     * This is used for activities that need full Moodle session/JS support.
     * Redirecting instead of using iframe avoids cross-origin cookie issues.
     */
    private function redirect_to_activity(): string {
        global $CFG;

        $url = $CFG->wwwroot . '/mod/' . $this->activityType . '/view.php?id=' . $this->cm->id;
        header('Location: ' . $url);
        exit;
    }

    /**
     * Render SCORM player in an iframe with navigation hidden.
     *
     * Instead of redirecting to the SCORM player, we render it in an iframe
     * within our own page. This allows us to inject CSS via JavaScript to
     * hide the navigation elements (since same-origin allows iframe manipulation).
     */
    private function redirect_to_scorm(): string {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $scorm = $DB->get_record('scorm', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get first SCO.
        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        if (empty($scoes)) {
            return $this->render_error('No SCOs found in this SCORM package.');
        }

        $sco = reset($scoes);

        // Force TOC and navigation to be hidden in SCORM settings.
        $updates = [];
        if ($scorm->hidetoc != 2) {
            $updates['hidetoc'] = 2;
        }
        if (isset($scorm->nav) && $scorm->nav != 0) {
            $updates['nav'] = 0;
        }
        if (!empty($updates)) {
            $updates['id'] = $scorm->id;
            $DB->update_record('scorm', (object)$updates);
        }

        // Build SCORM player URL and render iframe wrapper.
        $playerUrl = $CFG->wwwroot . '/mod/scorm/player.php?a=' . $scorm->id . '&scoid=' . $sco->id . '&display=popup';
        return $this->render_scorm_iframe($playerUrl, $scorm->name);
    }

    /**
     * Render SCORM player in iframe with CSS injection to hide navigation.
     *
     * @param string $playerUrl SCORM player URL
     * @param string $title Activity title
     * @return string HTML content
     */
    private function render_scorm_iframe(string $playerUrl, string $title): string {
        global $CFG;

        // Generate JavaScript to manage sessionStorage for target slide navigation BEFORE iframe loads.
        // IMPORTANT: Always clear any existing entry first to prevent stale navigation on page reload.
        $cmid = (int)$this->cm->id;
        $slideSetupScript = "
    // Clear any stale navigation data first (prevents redirect on page reload)
    (function() {
        sessionStorage.removeItem('scorm_pending_navigation_{$cmid}');
    })();
";
        if ($this->targetSlide !== null) {
            $slide = (int)$this->targetSlide;
            $slideSetupScript .= "
    // Set up sessionStorage for direct slide navigation BEFORE iframe loads
    (function() {
        var navData = {
            slide: {$slide},
            cmid: {$cmid},
            timestamp: Date.now(),
            version: 3  // v3: multiple intercepts with time window
        };
        sessionStorage.setItem('scorm_pending_navigation_{$cmid}', JSON.stringify(navData));
        console.log('[Embed Renderer] Set pending navigation:', navData);
    })();
";
        }

        $html = '<!DOCTYPE html>
<html lang="' . current_language() . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . s($title) . '</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        #scorm-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
    </style>
    <script>' . $slideSetupScript . '</script>
</head>
<body>
    <iframe id="scorm-frame" src="' . s($playerUrl) . '" allowfullscreen></iframe>
    <script>
    (function() {
        var iframe = document.getElementById("scorm-frame");

        function hideNavigation() {
            try {
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc || !doc.body) return;

                // Inject CSS - only hide nav elements, NOT toctree or tocbox (they contain content)
                var styleId = "sm-embed-css";
                if (!doc.getElementById(styleId)) {
                    var style = doc.createElement("style");
                    style.id = styleId;
                    style.textContent = [
                        "/* Hide only navigation, not content containers */",
                        "#scormtop { display: none !important; }",
                        "#scormnav { display: none !important; }",
                        ".scorm-right { display: none !important; }",
                        "#scorm_toc { display: none !important; }",
                        "#scorm_toc_toggle { display: none !important; }",
                        "#scorm_toc_toggle_btn { display: none !important; }",
                        "#scorm_navpanel { display: none !important; }",
                        ".toast-wrapper { display: none !important; }",
                        "/* Make content area full width */",
                        "#scorm_layout { width: 100% !important; }",
                        "#scorm_layout > .yui3-u-1-5 { display: none !important; width: 0 !important; }",
                        "#scorm_content { width: 100% !important; left: 0 !important; margin: 0 !important; }",
                        "/* Full viewport */",
                        "body, #page, .embedded-main, #scormpage, #tocbox, #toctree { margin: 0 !important; padding: 0 !important; width: 100% !important; height: 100% !important; }",
                        "body { overflow: hidden !important; }"
                    ].join("\\n");
                    doc.head.appendChild(style);
                }

                // Direct hide
                ["scormtop", "scormnav", "scorm_toc", "scorm_toc_toggle", "scorm_toc_toggle_btn", "scorm_navpanel"].forEach(function(id) {
                    var el = doc.getElementById(id);
                    if (el) el.style.display = "none";
                });

            } catch (e) {
                // Cross-origin - ignore
            }
        }

        iframe.onload = function() {
            hideNavigation();
            setTimeout(hideNavigation, 300);
            setTimeout(hideNavigation, 1000);
            setTimeout(hideNavigation, 2000);
        };

        // Forward navigation messages from parent (ActivityEmbed) to inner iframe (player.php)
        window.addEventListener("message", function(event) {
            console.log("[Embed Renderer] Message received:", event.data?.type, event.origin);

            // Forward SCORM navigation requests to inner iframe
            if (event.data && event.data.type === "scorm-navigate-to-slide") {
                console.log("[Embed Renderer] Forwarding navigation to player.php iframe");
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.postMessage(event.data, "*");
                }
            }

            // Also forward any messages from player.php back to parent
            if (event.source === iframe.contentWindow && event.data) {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(event.data, "*");
                }
            }
        }, false);
    })();
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * Get JavaScript code to inject CSS into the SCORM player iframe.
     *
     * @return string JavaScript code
     */
    private function get_iframe_css_injection_script(): string {
        return <<<'JS'
(function() {
    var iframe = document.getElementById('scorm-frame');

    function injectCSS() {
        try {
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

            // Check if we can access the iframe (same-origin)
            if (!iframeDoc) {
                console.log('SM Embed: Cannot access iframe document');
                return;
            }

            console.log('SM Embed: Injecting CSS into iframe');

            // Create style element
            var style = iframeDoc.createElement('style');
            style.id = 'sm-embed-styles';
            style.type = 'text/css';
            style.innerHTML = `
                /* Hide SCORM navigation elements */
                #scormtop { display: none !important; }
                #scormnav { display: none !important; }
                .scorm-right { display: none !important; }
                #scorm_toc { display: none !important; width: 0 !important; }
                #scorm_toc_toggle { display: none !important; }
                #scorm_toc_toggle_btn { display: none !important; }
                #scorm_navpanel { display: none !important; }
                .toast-wrapper { display: none !important; }

                /* Hide the left column in the layout */
                #scorm_layout > .yui3-u-1-5 { display: none !important; width: 0 !important; }

                /* Make the content area take full width */
                #scorm_content {
                    width: 100% !important;
                    left: 0 !important;
                    margin-left: 0 !important;
                }

                /* Ensure scormpage fills the viewport */
                #scormpage {
                    width: 100% !important;
                    height: 100% !important;
                }

                #page, .embedded-main {
                    padding: 0 !important;
                    margin: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                }

                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: auto !important;
                }

                /* Hide tocbox but keep scormpage visible */
                #tocbox > *:not(#toctree) { display: none !important; }
                #toctree > *:not(#scorm_layout) { display: none !important; }
            `;

            // Remove old style if exists
            var oldStyle = iframeDoc.getElementById('sm-embed-styles');
            if (oldStyle) oldStyle.remove();

            iframeDoc.head.appendChild(style);

            // Hide specific elements directly
            var hideIds = ['scormtop', 'scormnav', 'scorm_toc', 'scorm_toc_toggle', 'scorm_toc_toggle_btn', 'scorm_navpanel'];
            hideIds.forEach(function(id) {
                var el = iframeDoc.getElementById(id);
                if (el) {
                    el.style.display = 'none';
                    console.log('SM Embed: Hidden element #' + id);
                }
            });

            // Hide .scorm-right elements
            var scormRights = iframeDoc.querySelectorAll('.scorm-right');
            scormRights.forEach(function(el) {
                el.style.display = 'none';
            });

            console.log('SM Embed: CSS injection complete');

        } catch (e) {
            // Cross-origin error - can't inject CSS
            console.log('SM Embed: Could not inject CSS into iframe', e);
        }
    }

    // Inject on load and also after delays (for dynamic content)
    iframe.onload = function() {
        console.log('SM Embed: iframe loaded');
        injectCSS();
        setTimeout(injectCSS, 500);
        setTimeout(injectCSS, 1000);
        setTimeout(injectCSS, 2000);
    };
})();
JS;
    }

    /**
     * Trigger module viewed event for completion tracking.
     */
    private function trigger_module_viewed(): void {
        global $USER, $DB;

        // Get course.
        $course = $DB->get_record('course', ['id' => $this->cm->course], '*', MUST_EXIST);

        // Create viewed event based on activity type.
        $eventclass = '\\mod_' . $this->activityType . '\\event\\course_module_viewed';
        if (class_exists($eventclass)) {
            $event = $eventclass::create([
                'objectid' => $this->cm->instance,
                'context' => $this->context,
            ]);
            $event->add_record_snapshot('course', $course);
            $event->trigger();
        }

        // Update completion state.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($this->cm) && $this->cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($this->cm, COMPLETION_COMPLETE);
        }
    }

    /**
     * Render Page content directly.
     *
     * @return string HTML content
     */
    private function render_page(): string {
        global $DB;

        $page = $DB->get_record('page', ['id' => $this->cm->instance], '*', MUST_EXIST);

        $content = file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $this->context->id,
            'mod_page',
            'content',
            0
        );
        $content = format_text($content, $page->contentformat, ['context' => $this->context]);

        $html = '<div class="embed-page-container" style="padding:20px;">';
        $html .= '<h1>' . format_string($page->name) . '</h1>';
        $html .= '<div class="page-content">' . $content . '</div>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Resource (file download/view).
     *
     * @return string HTML content
     */
    private function render_resource(): string {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/resource/locallib.php');

        $resource = $DB->get_record('resource', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get the file.
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);

        if (empty($files)) {
            return $this->render_error('No file found in this resource.');
        }

        $file = reset($files);
        $fileUrl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );

        $mimetype = $file->get_mimetype();

        // Handle based on file type.
        if (strpos($mimetype, 'pdf') !== false) {
            $html = '<div class="embed-resource-container" style="width:100%;height:100vh;">';
            $html .= '<iframe src="' . $fileUrl->out(false) . '" ';
            $html .= 'style="width:100%;height:100%;border:none;">';
            $html .= '</iframe>';
            $html .= '</div>';
        } elseif (strpos($mimetype, 'image') !== false) {
            $html = '<div class="embed-resource-container" style="text-align:center;padding:20px;">';
            $html .= '<img src="' . $fileUrl->out(false) . '" style="max-width:100%;max-height:90vh;" />';
            $html .= '</div>';
        } else {
            // Download link for other types.
            $html = '<div class="embed-resource-container" style="padding:20px;text-align:center;">';
            $html .= '<h2>' . format_string($resource->name) . '</h2>';
            $html .= '<p><a href="' . $fileUrl->out(false) . '" class="btn btn-primary" download>';
            $html .= get_string('download') . '</a></p>';
            $html .= '</div>';
        }

        return $this->wrap_content($html);
    }

    /**
     * Render URL module.
     *
     * @return string HTML content
     */
    private function render_url(): string {
        global $DB;

        $url = $DB->get_record('url', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Check display mode.
        if ($url->display == RESOURCELIB_DISPLAY_EMBED) {
            $html = '<div class="embed-url-container" style="width:100%;height:100vh;">';
            $html .= '<iframe src="' . $url->externalurl . '" ';
            $html .= 'style="width:100%;height:100%;border:none;" ';
            $html .= 'allowfullscreen>';
            $html .= '</iframe>';
            $html .= '</div>';
        } else {
            // Show link.
            $html = '<div class="embed-url-container" style="padding:20px;text-align:center;">';
            $html .= '<h2>' . format_string($url->name) . '</h2>';
            $html .= '<p><a href="' . $url->externalurl . '" target="_blank" class="btn btn-primary">';
            $html .= get_string('clicktoopen', 'url') . '</a></p>';
            $html .= '</div>';
        }

        return $this->wrap_content($html);
    }

    /**
     * Render error message.
     *
     * @param string $message Error message
     * @return string HTML content
     */
    private function render_error(string $message): string {
        $html = '<div class="embed-error-container" style="padding:40px;text-align:center;">';
        $html .= '<div class="alert alert-danger">' . s($message) . '</div>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Wrap content with minimal HTML structure.
     *
     * For embed mode, we intentionally skip Moodle's get_head_code() and get_end_code()
     * because they include JavaScript that expects the full Moodle JS framework to be
     * loaded. Instead, we only include the theme CSS and a minimal M.cfg object.
     *
     * @param string $content Inner content
     * @return string Complete HTML document
     */
    private function wrap_content(string $content): string {
        global $CFG, $PAGE;

        // Build the theme CSS URL directly - skip get_head_code() to avoid JS conflicts.
        $themename = $PAGE->theme->name ?? 'boost';
        $themerev = theme_get_revision();
        $cssurl = $CFG->wwwroot . '/theme/styles.php/' . $themename . '/' . $themerev . '/all';

        $html = '<!DOCTYPE html>';
        $html .= '<html lang="' . current_language() . '">';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . format_string($this->cm->name) . '</title>';

        // Include only theme CSS - no Moodle JavaScript framework.
        $html .= '<link rel="stylesheet" href="' . s($cssurl) . '" />';

        // Minimal M.cfg for any scripts that might check for it.
        $html .= '<script>';
        $html .= 'window.M = window.M || {};';
        $html .= 'M.cfg = {';
        $html .= 'wwwroot: ' . json_encode($CFG->wwwroot) . ',';
        $html .= 'sesskey: ' . json_encode(sesskey()) . ',';
        $html .= 'themerev: ' . json_encode($themerev) . ',';
        $html .= 'slasharguments: ' . json_encode($CFG->slasharguments ?? 1) . ',';
        $html .= 'theme: ' . json_encode($themename) . ',';
        $html .= 'jsrev: ' . json_encode($CFG->jsrev ?? -1) . ',';
        $html .= 'svgicons: true,';
        $html .= 'developerdebug: false,';
        $html .= 'loadingicon: ' . json_encode($CFG->wwwroot . '/pix/i/loading_small.gif') . ',';
        $html .= 'js_pending: []';
        $html .= '};';
        $html .= 'M.util = {';
        $html .= 'pending_js: [],';
        $html .= 'js_pending: function() { return 0; },';
        $html .= 'js_complete: function() {},';
        $html .= 'image_url: function(name, component) { return M.cfg.wwwroot + "/pix/" + name + ".svg"; }';
        $html .= '};';
        $html .= 'M.str = M.str || {};';
        $html .= 'M.yui = M.yui || {};';
        $html .= '</script>';

        $html .= '<style>';
        $html .= 'body { margin: 0; padding: 0; overflow: hidden; }';
        $html .= '.embed-container { width: 100%; height: 100vh; }';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body class="embed-mode">';
        $html .= '<main class="embed-container">';
        $html .= $content;
        $html .= '</main>';
        $html .= '</body>';
        $html .= '</html>';

        return $html;
    }
}
