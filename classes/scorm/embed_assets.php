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
 * SCORM embed mode CSS and JavaScript for the SmartMind Estratoos plugin.
 *
 * When SCORM content is viewed through the SmartLearning embed endpoint,
 * we need to hide Moodle's SCORM player navigation controls and make the
 * content fill the entire viewport. This class provides the CSS and JS
 * to achieve that.
 *
 * What gets hidden:
 *   - #scormtop: Top bar with TOC dropdown
 *   - #scormnav, .scorm-right: SCO navigation dropdown
 *   - #scorm_toc: Table of contents sidebar (NOT #tocbox/#toctree which hold content)
 *   - #scorm_toc_toggle: TOC toggle button
 *   - #scorm_navpanel: Floating navigation panel
 *   - .toast-wrapper: Toast notifications
 *
 * What gets maximized:
 *   - html, body: Fill viewport, no margins/padding, overflow hidden
 *   - #page, .embedded-main, [role="main"]: Absolute position, fill viewport
 *   - #scormpage, #tocbox, #toctree, #scorm_layout: Fill viewport chain
 *   - #scorm_content, #scorm_object, .scoframe: Content area fills container
 *
 * CSS specificity note:
 *   We use !important on all rules because Moodle's YUI-based SCORM player
 *   sets inline styles and uses its own !important declarations.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\scorm;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides CSS and JS for hiding SCORM navigation in embed mode.
 *
 * Called by the thin delegator in lib.php:
 *   local_sm_estratoos_plugin_get_embed_css_js() → embed_assets::get_css_js()
 *
 * Also called directly from the before_footer hook when embed mode is detected.
 */
class embed_assets {

    /**
     * Get the CSS and JS to hide SCORM navigation and maximize content in embed mode.
     *
     * Returns a complete HTML block with <style> and <script> tags that:
     *   1. CSS: Hides all Moodle SCORM player navigation elements
     *   2. CSS: Makes the SCORM content fill the entire browser viewport
     *   3. JS: Programmatically hides elements and adjusts layout (backup for CSS)
     *
     * Example flow:
     *   1. User accesses SCORM via SmartLearning embed URL
     *   2. embed.php sets the 'sm_estratoos_embed' cookie
     *   3. before_footer() detects the cookie and calls this method
     *   4. CSS hides navigation, JS reinforces and adjusts content width
     *
     * @return string HTML containing <style> and <script> blocks.
     */
    public static function get_css_js() {
        return <<<HTML
<style type="text/css">
/* ============================================================
 * SCORM Embed Mode Styles
 * Hide Moodle's SCORM player navigation when viewed through SmartLearning.
 * IMPORTANT: Do NOT hide #tocbox or #toctree - they contain the content!
 * ============================================================ */

/* --- HIDE NAVIGATION ELEMENTS --- */

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

/* Hide the YUI left column in the SCORM layout */
#scorm_layout > .yui3-u-1-5 {
    display: none !important;
    width: 0 !important;
}

/* --- MAXIMIZE VIEWPORT --- */

/* Make html and body fill viewport with no scrollbars */
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
// ============================================================
// SCORM Embed Mode JavaScript (backup)
// Programmatically hides navigation elements in case CSS isn't
// sufficient (e.g., elements loaded dynamically after initial render).
// ============================================================
(function() {
    // Mark the body for CSS targeting.
    document.body.classList.add('sm-embed-mode');

    // Hide only navigation elements (NOT content containers like #tocbox).
    ['scormtop', 'scormnav', 'scorm_toc', 'scorm_toc_toggle', 'scorm_toc_toggle_btn', 'scorm_navpanel'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // Hide .scorm-right elements (SCO navigation).
    document.querySelectorAll('.scorm-right').forEach(function(el) {
        el.style.display = 'none';
    });

    // Make SCORM content area fill the full width (remove left margin from hidden TOC).
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
}
