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
 * Activity embed mode CSS and JavaScript for the SmartMind Estratoos plugin.
 *
 * When non-SCORM activities (quiz, assignment, lesson, book, etc.) are viewed
 * through the SmartLearning embed endpoint, we need to hide Moodle's Boost theme
 * chrome (navbar, header, footer, side drawers) and make the activity content
 * fill the viewport.
 *
 * Two variants:
 *   - get_css_js(): Hides all Moodle chrome for general activities.
 *   - get_quiz_css_js(): Same but keeps the right drawer for quiz question navigation.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides CSS and JS for hiding Moodle Boost chrome in embed mode for non-SCORM activities.
 *
 * Called from the before_footer hook in lib.php when embed mode is detected
 * on a non-SCORM /mod/ page.
 */
class activity_embed_assets {

    /**
     * Get the CSS and JS to hide Moodle chrome for general (non-quiz) activities.
     *
     * Hides: navbar, page header, secondary navigation, drawers, footer, toasts.
     * Keeps: #region-main (activity content), prev/next buttons.
     *
     * @return string HTML containing <style> and <script> blocks.
     */
    public static function get_css_js() {
        return self::get_base_css() . self::get_drawer_hide_css() . self::get_base_js(false);
    }

    /**
     * Get the CSS and JS for quiz pages — keeps right drawer for question navigation.
     *
     * Same as get_css_js() but re-shows the right drawer containing the quiz
     * question navigation panel (#mod_quiz_navblock).
     *
     * @return string HTML containing <style> and <script> blocks.
     */
    public static function get_quiz_css_js() {
        return self::get_base_css() . self::get_quiz_drawer_css() . self::get_base_js(true);
    }

    /**
     * Base CSS shared by all activity types — hides Moodle Boost chrome.
     *
     * @return string HTML <style> block.
     */
    private static function get_base_css() {
        return <<<HTML
<style type="text/css">
/* ============================================================
 * Activity Embed Mode Styles
 * Hide Moodle Boost theme chrome when viewed through SmartLearning.
 * Applied to all /mod/ pages (except SCORM which has its own embed_assets).
 * ============================================================ */

/* --- HIDE NAVIGATION ELEMENTS --- */

/* Top navigation bar */
nav.navbar,
nav.navbar.fixed-top {
    display: none !important;
}

/* Page header (breadcrumbs, context header, course header) */
#page-header {
    display: none !important;
}

/* Secondary navigation tabs */
.secondary-navigation {
    display: none !important;
}

/* Drawer toggle buttons */
.drawer-toggles {
    display: none !important;
}

/* Left drawer (course index) */
#theme_boost-drawers-courseindex,
.drawer.drawer-left {
    display: none !important;
}

/* Footer */
#page-footer,
footer#page-footer {
    display: none !important;
}

/* Toast notifications */
.toast-wrapper {
    display: none !important;
}

/* Back to top button */
.back-to-top {
    display: none !important;
}

/* Mobile primary drawer */
#theme_boost-drawers-primary {
    display: none !important;
}

/* Region main settings menu (gear icon) */
#region-main-settings-menu {
    display: none !important;
}

/* --- MAXIMIZE CONTENT --- */

/* Remove top offset caused by hidden fixed navbar */
body {
    margin-top: 0 !important;
    padding-top: 0 !important;
}

/* Remove drawer margin/padding displacement */
#page.drawers {
    margin-left: 0 !important;
    padding-left: 0 !important;
    transition: none !important;
}

#page.drawers.show-drawer-left {
    margin-left: 0 !important;
}

/* topofscroll normally has padding-top for fixed navbar */
#topofscroll {
    padding-top: 0 !important;
    margin-top: 0 !important;
}

/* page-content fills available space */
#page-content {
    padding-bottom: 0 !important;
}

/* Ensure page takes full height */
#page {
    min-height: 100vh !important;
}

/* region-main fills width and scrolls */
#region-main {
    overflow: auto !important;
}
</style>
HTML;
    }

    /**
     * CSS to hide BOTH drawers — used for general (non-quiz) activities.
     *
     * @return string HTML <style> block.
     */
    private static function get_drawer_hide_css() {
        return <<<HTML
<style type="text/css">
/* --- HIDE RIGHT DRAWER (general activities) --- */
#theme_boost-drawers-blocks,
.drawer.drawer-right {
    display: none !important;
}

#page.drawers.show-drawer-right {
    margin-right: 0 !important;
}
</style>
HTML;
    }

    /**
     * CSS for quiz pages — keeps right drawer visible for question navigation.
     *
     * The quiz question navigation panel is rendered as a "fake block" in
     * Moodle's side-pre block region, which Boost places in the right drawer.
     *
     * @return string HTML <style> block.
     */
    private static function get_quiz_drawer_css() {
        return <<<HTML
<style type="text/css">
/* --- QUIZ: Keep right drawer for question navigation --- */

/* Hide right drawer by default, then re-show for quiz */
#theme_boost-drawers-blocks,
.drawer.drawer-right {
    display: block !important;
    transform: translateX(0) !important;
    visibility: visible !important;
}

/* Ensure the quiz nav block is visible */
#mod_quiz_navblock {
    display: block !important;
}

/* Right margin for the visible drawer */
#page.drawers.show-drawer-right {
    margin-right: 285px !important;
}

/* If drawer-right was not open, still apply margin */
#page.drawers {
    margin-right: 285px !important;
}
</style>
HTML;
    }

    /**
     * JavaScript backup — programmatically hides elements for dynamic content.
     *
     * @param bool $isQuiz If true, keeps the right drawer open for quiz navigation.
     * @return string HTML <script> block.
     */
    private static function get_base_js(bool $isQuiz) {
        $quizJs = '';
        if ($isQuiz) {
            $quizJs = <<<'JS'

    // Quiz: ensure right drawer stays open for question navigation
    var rightDrawer = document.getElementById('theme_boost-drawers-blocks');
    if (rightDrawer) {
        rightDrawer.classList.add('show');
        rightDrawer.style.display = '';
        rightDrawer.style.visibility = 'visible';
    }
    var pageEl = document.getElementById('page');
    if (pageEl && !pageEl.classList.contains('show-drawer-right')) {
        pageEl.classList.add('show-drawer-right');
    }
JS;
        } else {
            $quizJs = <<<'JS'

    // Non-quiz: hide right drawer
    var rightDrawer = document.getElementById('theme_boost-drawers-blocks');
    if (rightDrawer) {
        rightDrawer.style.display = 'none';
    }
    var pageEl = document.getElementById('page');
    if (pageEl) {
        pageEl.classList.remove('show-drawer-right');
    }
JS;
        }

        return <<<HTML
<script>
// ============================================================
// Activity Embed Mode JavaScript (backup)
// Programmatically hides Moodle Boost chrome in case CSS isn't
// sufficient (e.g., elements loaded dynamically after render).
// ============================================================
(function() {
    // Mark body for CSS targeting.
    document.body.classList.add('sm-activity-embed-mode');

    // Remove top padding caused by hidden fixed navbar.
    document.body.style.paddingTop = '0';
    document.body.style.marginTop = '0';

    // Hide navbar.
    var navbar = document.querySelector('nav.navbar.fixed-top') || document.querySelector('nav.navbar');
    if (navbar) navbar.style.display = 'none';

    // Hide page header.
    var pageHeader = document.getElementById('page-header');
    if (pageHeader) pageHeader.style.display = 'none';

    // Hide secondary navigation.
    var secNav = document.querySelector('.secondary-navigation');
    if (secNav) secNav.style.display = 'none';

    // Hide drawer toggles.
    var toggles = document.querySelector('.drawer-toggles');
    if (toggles) toggles.style.display = 'none';

    // Hide left drawer and remove its margin effect.
    var leftDrawer = document.getElementById('theme_boost-drawers-courseindex');
    if (leftDrawer) {
        leftDrawer.classList.remove('show');
        leftDrawer.style.display = 'none';
    }
    var page = document.getElementById('page');
    if (page) {
        page.classList.remove('show-drawer-left');
    }

    // Hide footer.
    var footer = document.getElementById('page-footer');
    if (footer) footer.style.display = 'none';

    // Remove top scroll offset.
    var topScroll = document.getElementById('topofscroll');
    if (topScroll) topScroll.style.paddingTop = '0';
{$quizJs}
})();
</script>
HTML;
    }
}
