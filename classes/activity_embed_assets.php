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
     * Applies: SmartLearning theme styling for visual consistency.
     *
     * @return string HTML containing <style> and <script> blocks.
     */
    public static function get_css_js() {
        return self::get_smartlearning_theme_css() . self::get_base_css()
            . self::get_drawer_hide_css() . self::get_base_js(false);
    }

    /**
     * Get the CSS and JS for quiz pages — keeps right drawer for question navigation.
     *
     * Same as get_css_js() but re-shows the right drawer containing the quiz
     * question navigation panel (#mod_quiz_navblock).
     * Applies: SmartLearning theme styling for visual consistency.
     *
     * @return string HTML containing <style> and <script> blocks.
     */
    public static function get_quiz_css_js() {
        return self::get_smartlearning_theme_css() . self::get_base_css()
            . self::get_quiz_drawer_css() . self::get_base_js(true);
    }

    /**
     * SmartLearning theme CSS — applies consistent styling to embedded activities.
     *
     * Defines CSS custom properties matching the SmartLearning design system
     * and overrides Moodle Boost styles for visual consistency.
     *
     * @return string HTML <style> block.
     */
    private static function get_smartlearning_theme_css() {
        return <<<HTML
<style type="text/css">
/* ============================================================
 * SmartLearning Theme Styles
 * Applies SmartLearning design system to embedded Moodle activities.
 * CSS custom properties match inboxfrontend/assets/scss/abstracts/_variables.scss
 * ============================================================ */

/* --- CSS CUSTOM PROPERTIES (Design Tokens) --- */
:root {
    /* Primary Colors */
    --sl-primary: #007bff;
    --sl-primary-dark: #0056b3;
    --sl-primary-light: #40b5ff;
    --sl-primary-rgb: 0, 123, 255;

    /* Semantic Colors */
    --sl-secondary: #6c757d;
    --sl-success: #28a745;
    --sl-danger: #dc3545;
    --sl-warning: #ffc107;
    --sl-info: #17a2b8;

    /* Background & Text */
    --sl-bg: #ffffff;
    --sl-bg-secondary: #f8f9fa;
    --sl-bg-tertiary: #f1f3f5;
    --sl-text: #111827;
    --sl-text-secondary: #6b7280;
    --sl-text-muted: #9ca3af;
    --sl-border: #e5e7eb;

    /* Gray Scale */
    --sl-gray-50: #f9fafb;
    --sl-gray-100: #f3f4f6;
    --sl-gray-200: #e5e7eb;
    --sl-gray-300: #d1d5db;
    --sl-gray-400: #9ca3af;
    --sl-gray-500: #6b7280;
    --sl-gray-600: #4b5563;
    --sl-gray-700: #374151;
    --sl-gray-800: #1f2937;
    --sl-gray-900: #111827;

    /* Typography */
    --sl-font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
    --sl-font-size-xs: 0.75rem;
    --sl-font-size-sm: 0.875rem;
    --sl-font-size-base: 1rem;
    --sl-font-size-lg: 1.125rem;
    --sl-font-size-xl: 1.25rem;

    /* Spacing */
    --sl-spacing-xs: 0.25rem;
    --sl-spacing-sm: 0.5rem;
    --sl-spacing-md: 1rem;
    --sl-spacing-lg: 1.5rem;
    --sl-spacing-xl: 2rem;

    /* Border Radius */
    --sl-radius-sm: 0.25rem;
    --sl-radius-md: 0.375rem;
    --sl-radius-lg: 0.5rem;
    --sl-radius-xl: 0.75rem;
    --sl-radius-full: 9999px;

    /* Shadows */
    --sl-shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --sl-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    --sl-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);

    /* Transitions */
    --sl-transition-fast: 150ms ease-in-out;
    --sl-transition-base: 200ms ease-in-out;
}

/* --- GLOBAL TYPOGRAPHY --- */
body.sm-activity-embed-mode {
    font-family: var(--sl-font-family) !important;
    font-size: var(--sl-font-size-base) !important;
    color: var(--sl-text) !important;
    background-color: var(--sl-bg) !important;
    line-height: 1.5 !important;
    -webkit-font-smoothing: antialiased !important;
    -moz-osx-font-smoothing: grayscale !important;
}

body.sm-activity-embed-mode h1,
body.sm-activity-embed-mode h2,
body.sm-activity-embed-mode h3,
body.sm-activity-embed-mode h4,
body.sm-activity-embed-mode h5,
body.sm-activity-embed-mode h6 {
    font-family: var(--sl-font-family) !important;
    color: var(--sl-text) !important;
    font-weight: 600 !important;
    line-height: 1.25 !important;
}

/* --- BUTTONS --- */
body.sm-activity-embed-mode .btn-primary,
body.sm-activity-embed-mode .btn.btn-primary {
    background-color: var(--sl-primary) !important;
    border-color: var(--sl-primary) !important;
    color: #fff !important;
    font-weight: 500 !important;
    border-radius: var(--sl-radius-md) !important;
    padding: var(--sl-spacing-sm) var(--sl-spacing-lg) !important;
    transition: all var(--sl-transition-fast) !important;
}

body.sm-activity-embed-mode .btn-primary:hover,
body.sm-activity-embed-mode .btn.btn-primary:hover {
    background-color: var(--sl-primary-dark) !important;
    border-color: var(--sl-primary-dark) !important;
    transform: translateY(-1px) !important;
    box-shadow: var(--sl-shadow-md) !important;
}

body.sm-activity-embed-mode .btn-secondary,
body.sm-activity-embed-mode .btn.btn-secondary {
    background-color: var(--sl-bg-secondary) !important;
    border-color: var(--sl-border) !important;
    color: var(--sl-text) !important;
    font-weight: 500 !important;
    border-radius: var(--sl-radius-md) !important;
}

body.sm-activity-embed-mode .btn-secondary:hover,
body.sm-activity-embed-mode .btn.btn-secondary:hover {
    background-color: var(--sl-gray-200) !important;
    border-color: var(--sl-gray-300) !important;
}

/* --- CARDS --- */
body.sm-activity-embed-mode .card {
    background-color: var(--sl-bg) !important;
    border: 1px solid var(--sl-border) !important;
    border-radius: var(--sl-radius-lg) !important;
    box-shadow: var(--sl-shadow-sm) !important;
}

body.sm-activity-embed-mode .card-header {
    background-color: var(--sl-bg-secondary) !important;
    border-bottom: 1px solid var(--sl-border) !important;
    padding: var(--sl-spacing-md) var(--sl-spacing-lg) !important;
}

body.sm-activity-embed-mode .card-body {
    padding: var(--sl-spacing-lg) !important;
}

/* --- FORMS --- */
body.sm-activity-embed-mode .form-control,
body.sm-activity-embed-mode input[type="text"],
body.sm-activity-embed-mode input[type="number"],
body.sm-activity-embed-mode textarea,
body.sm-activity-embed-mode select {
    border: 1px solid var(--sl-border) !important;
    border-radius: var(--sl-radius-md) !important;
    padding: var(--sl-spacing-sm) var(--sl-spacing-md) !important;
    font-size: var(--sl-font-size-sm) !important;
    transition: border-color var(--sl-transition-fast), box-shadow var(--sl-transition-fast) !important;
}

body.sm-activity-embed-mode .form-control:focus,
body.sm-activity-embed-mode input:focus,
body.sm-activity-embed-mode textarea:focus,
body.sm-activity-embed-mode select:focus {
    border-color: var(--sl-primary) !important;
    box-shadow: 0 0 0 3px rgba(var(--sl-primary-rgb), 0.15) !important;
    outline: none !important;
}

/* --- QUIZ SPECIFIC STYLES --- */
body.sm-activity-embed-mode .que {
    background-color: var(--sl-bg) !important;
    border: 1px solid var(--sl-border) !important;
    border-radius: var(--sl-radius-lg) !important;
    margin-bottom: var(--sl-spacing-lg) !important;
    box-shadow: var(--sl-shadow-sm) !important;
}

body.sm-activity-embed-mode .que .info {
    background-color: var(--sl-bg-secondary) !important;
    border-radius: var(--sl-radius-lg) var(--sl-radius-lg) 0 0 !important;
    padding: var(--sl-spacing-md) !important;
    border-bottom: 1px solid var(--sl-border) !important;
}

body.sm-activity-embed-mode .que .content {
    padding: var(--sl-spacing-lg) !important;
}

body.sm-activity-embed-mode .que .qtext {
    color: var(--sl-text) !important;
    font-size: var(--sl-font-size-base) !important;
    line-height: 1.6 !important;
    margin-bottom: var(--sl-spacing-md) !important;
}

body.sm-activity-embed-mode .que .answer {
    margin-top: var(--sl-spacing-md) !important;
}

body.sm-activity-embed-mode .que .answer label {
    display: flex !important;
    align-items: flex-start !important;
    padding: var(--sl-spacing-sm) var(--sl-spacing-md) !important;
    margin-bottom: var(--sl-spacing-xs) !important;
    border-radius: var(--sl-radius-md) !important;
    cursor: pointer !important;
    transition: background-color var(--sl-transition-fast) !important;
}

body.sm-activity-embed-mode .que .answer label:hover {
    background-color: var(--sl-bg-secondary) !important;
}

body.sm-activity-embed-mode .que .answer input[type="radio"],
body.sm-activity-embed-mode .que .answer input[type="checkbox"] {
    margin-right: var(--sl-spacing-sm) !important;
    margin-top: 0.25em !important;
}

/* Quiz navigation block */
body.sm-activity-embed-mode #mod_quiz_navblock {
    background-color: var(--sl-bg) !important;
}

body.sm-activity-embed-mode #mod_quiz_navblock .qnbutton {
    border-radius: var(--sl-radius-md) !important;
    font-weight: 500 !important;
}

/* --- BOOK SPECIFIC STYLES --- */
body.sm-activity-embed-mode .book_content {
    padding: var(--sl-spacing-xl) !important;
    max-width: 800px !important;
    margin: 0 auto !important;
}

body.sm-activity-embed-mode .book_content h1,
body.sm-activity-embed-mode .book_content h2,
body.sm-activity-embed-mode .book_content h3 {
    margin-top: var(--sl-spacing-xl) !important;
    margin-bottom: var(--sl-spacing-md) !important;
}

body.sm-activity-embed-mode .book_content p {
    margin-bottom: var(--sl-spacing-md) !important;
    line-height: 1.7 !important;
}

/* Book navigation */
body.sm-activity-embed-mode .book_navigation {
    display: flex !important;
    justify-content: space-between !important;
    padding: var(--sl-spacing-lg) 0 !important;
    border-top: 1px solid var(--sl-border) !important;
    margin-top: var(--sl-spacing-xl) !important;
}

/* --- LESSON SPECIFIC STYLES --- */
body.sm-activity-embed-mode .lesson-content {
    padding: var(--sl-spacing-xl) !important;
    max-width: 800px !important;
    margin: 0 auto !important;
}

/* Hide database read errors - show clean UI instead of raw errors */
body.sm-activity-embed-mode .alert-danger,
body.sm-activity-embed-mode .notifyproblem,
body.sm-activity-embed-mode .errorbox,
body.sm-activity-embed-mode .error,
body.sm-activity-embed-mode .errormessage,
body.sm-activity-embed-mode div[style*="background-color: #FFCCBB"],
body.sm-activity-embed-mode div[class*="error"]:not(.que):not(.formfielderror):not(.error-feedback),
body.sm-activity-embed-mode .dbreaderror,
body.sm-activity-embed-mode span.error {
    display: none !important;
}

/* Lesson progress container - modern card style */
body.sm-activity-embed-mode .lessonprogress,
body.sm-activity-embed-mode .progress_bar_stage,
body.sm-activity-embed-mode div[class*="progress"]:not(.progress-bar):not(.que .progress),
body.sm-activity-embed-mode .lesson_timer_progress {
    background: linear-gradient(135deg, var(--sl-bg-secondary) 0%, var(--sl-bg) 100%) !important;
    border: 1px solid var(--sl-border) !important;
    border-radius: var(--sl-radius-lg) !important;
    padding: var(--sl-spacing-lg) !important;
    margin: var(--sl-spacing-lg) 0 !important;
    box-shadow: var(--sl-shadow-sm) !important;
}

/* Lesson progress text */
body.sm-activity-embed-mode .lessonprogress span,
body.sm-activity-embed-mode .progress_bar_stage span,
body.sm-activity-embed-mode .lesson_timer_progress span {
    font-size: var(--sl-font-size-sm) !important;
    color: var(--sl-text-secondary) !important;
    font-weight: 500 !important;
    display: block !important;
    margin-bottom: var(--sl-spacing-sm) !important;
}

/* Lesson progress bar wrapper */
body.sm-activity-embed-mode .progress_bar,
body.sm-activity-embed-mode .lesson-progress-bar,
body.sm-activity-embed-mode .lessonprogress .progress {
    height: 10px !important;
    background-color: var(--sl-gray-200) !important;
    border-radius: var(--sl-radius-full) !important;
    overflow: hidden !important;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.08) !important;
    margin-top: var(--sl-spacing-sm) !important;
}

/* Lesson progress bar fill - gradient animation */
body.sm-activity-embed-mode .progress_bar_innertube,
body.sm-activity-embed-mode .lesson-progress-fill,
body.sm-activity-embed-mode .lessonprogress .progress-bar,
body.sm-activity-embed-mode .progress_bar .bar,
body.sm-activity-embed-mode table.progress_bar tr td[style*="background"] {
    background: linear-gradient(90deg, var(--sl-primary) 0%, var(--sl-primary-light) 100%) !important;
    border-radius: var(--sl-radius-full) !important;
    height: 100% !important;
    transition: width 0.4s ease-out !important;
    box-shadow: 0 2px 4px rgba(0, 123, 255, 0.25) !important;
}

/* Progress bar table cleanup (Moodle uses tables for progress) */
body.sm-activity-embed-mode table.progress_bar {
    display: block !important;
    width: 100% !important;
    height: 10px !important;
    border: none !important;
    background-color: var(--sl-gray-200) !important;
    border-radius: var(--sl-radius-full) !important;
    overflow: hidden !important;
}

body.sm-activity-embed-mode table.progress_bar tbody,
body.sm-activity-embed-mode table.progress_bar tr {
    display: block !important;
    width: 100% !important;
    height: 100% !important;
}

body.sm-activity-embed-mode table.progress_bar td {
    display: inline-block !important;
    height: 100% !important;
    padding: 0 !important;
    border: none !important;
    vertical-align: top !important;
}

body.sm-activity-embed-mode table.progress_bar td:first-child {
    border-radius: var(--sl-radius-full) 0 0 var(--sl-radius-full) !important;
}

/* Hide table borders in progress bar */
body.sm-activity-embed-mode table.progress_bar,
body.sm-activity-embed-mode table.progress_bar td,
body.sm-activity-embed-mode table.progress_bar tr {
    border-collapse: collapse !important;
    border-spacing: 0 !important;
}

/* Lesson navigation buttons styling */
body.sm-activity-embed-mode .lessonbutton,
body.sm-activity-embed-mode .mod_lesson .singlebutton,
body.sm-activity-embed-mode form[action*="lesson"] input[type="submit"] {
    background-color: var(--sl-primary) !important;
    color: white !important;
    border: none !important;
    border-radius: var(--sl-radius-md) !important;
    padding: var(--sl-spacing-sm) var(--sl-spacing-lg) !important;
    font-weight: 500 !important;
    cursor: pointer !important;
    transition: all var(--sl-transition-fast) !important;
    box-shadow: var(--sl-shadow-sm) !important;
}

body.sm-activity-embed-mode .lessonbutton:hover,
body.sm-activity-embed-mode .mod_lesson .singlebutton:hover,
body.sm-activity-embed-mode form[action*="lesson"] input[type="submit"]:hover {
    background-color: var(--sl-primary-dark) !important;
    transform: translateY(-1px) !important;
    box-shadow: var(--sl-shadow-md) !important;
}

/* Lesson question/answer area */
body.sm-activity-embed-mode .contents {
    background-color: var(--sl-bg) !important;
    border: 1px solid var(--sl-border) !important;
    border-radius: var(--sl-radius-lg) !important;
    padding: var(--sl-spacing-xl) !important;
    margin: var(--sl-spacing-lg) 0 !important;
}

/* --- PAGE/RESOURCE SPECIFIC STYLES --- */
body.sm-activity-embed-mode .page-content-container,
body.sm-activity-embed-mode #region-main > .box {
    padding: var(--sl-spacing-xl) !important;
    max-width: 900px !important;
    margin: 0 auto !important;
}

/* --- ALERTS --- */
body.sm-activity-embed-mode .alert {
    border-radius: var(--sl-radius-md) !important;
    border: none !important;
    padding: var(--sl-spacing-md) var(--sl-spacing-lg) !important;
}

body.sm-activity-embed-mode .alert-info {
    background-color: rgba(23, 162, 184, 0.1) !important;
    color: var(--sl-info) !important;
}

body.sm-activity-embed-mode .alert-success {
    background-color: rgba(40, 167, 69, 0.1) !important;
    color: var(--sl-success) !important;
}

body.sm-activity-embed-mode .alert-warning {
    background-color: rgba(255, 193, 7, 0.1) !important;
    color: #856404 !important;
}

body.sm-activity-embed-mode .alert-danger {
    background-color: rgba(220, 53, 69, 0.1) !important;
    color: var(--sl-danger) !important;
}

/* --- LINKS --- */
body.sm-activity-embed-mode a {
    color: var(--sl-primary) !important;
    text-decoration: none !important;
    transition: color var(--sl-transition-fast) !important;
}

body.sm-activity-embed-mode a:hover {
    color: var(--sl-primary-dark) !important;
    text-decoration: underline !important;
}

/* --- TABLES --- */
body.sm-activity-embed-mode table {
    border-collapse: collapse !important;
    width: 100% !important;
}

body.sm-activity-embed-mode th,
body.sm-activity-embed-mode td {
    padding: var(--sl-spacing-sm) var(--sl-spacing-md) !important;
    border: 1px solid var(--sl-border) !important;
}

body.sm-activity-embed-mode th {
    background-color: var(--sl-bg-secondary) !important;
    font-weight: 600 !important;
}

body.sm-activity-embed-mode tr:nth-child(even) {
    background-color: var(--sl-gray-50) !important;
}

/* --- PROGRESS BAR --- */
body.sm-activity-embed-mode .progress {
    height: 6px !important;
    background-color: var(--sl-gray-200) !important;
    border-radius: var(--sl-radius-full) !important;
    overflow: hidden !important;
}

body.sm-activity-embed-mode .progress-bar {
    background-color: var(--sl-primary) !important;
    border-radius: var(--sl-radius-full) !important;
}

/* --- SCROLLBAR --- */
body.sm-activity-embed-mode ::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

body.sm-activity-embed-mode ::-webkit-scrollbar-track {
    background: var(--sl-gray-100);
    border-radius: var(--sl-radius-full);
}

body.sm-activity-embed-mode ::-webkit-scrollbar-thumb {
    background: var(--sl-gray-400);
    border-radius: var(--sl-radius-full);
}

body.sm-activity-embed-mode ::-webkit-scrollbar-thumb:hover {
    background: var(--sl-gray-500);
}
</style>
HTML;
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

/* Mobile menu toggle button (hamburger icon) */
button[data-toggler="drawers"],
.btn-icon.drawer-toggle,
button.btn.drawertoggle,
.drawertoggle,
button[aria-controls="theme_boost-drawers-primary"],
[data-action="toggle-drawer"] {
    display: none !important;
}

/* Any button that looks like a drawer toggle */
.btn.icon-no-margin[data-toggler] {
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
    /* Allow body to scroll for page/lesson content */
    overflow-y: auto !important;
    overflow-x: hidden !important;
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
    padding-bottom: 20px !important;
}

/* Page wrapper - don't force min-height that prevents scrolling */
#page {
    min-height: auto !important;
}

/* region-main - let content flow, don't create scroll context here */
#region-main {
    overflow: visible !important;
}

/* --- BOOK SPECIFIC --- */
/* Prevent horizontal scrollbar in book content */
.book_content,
#book-content,
.book_toc,
.generalbox.book_content {
    overflow-x: hidden !important;
    overflow-y: auto !important;
    max-width: 100% !important;
}

/* Book chapter navigation arrows */
.book_content pre,
.book_content code {
    white-space: pre-wrap !important;
    word-wrap: break-word !important;
}

/* --- PAGE AND LESSON SCROLL FIX --- */
/* Enable proper scrolling for page and lesson content in iframe */
html,
body.sm-activity-embed-mode {
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Allow body to scroll */
body.sm-activity-embed-mode {
    overflow-y: auto !important;
    overflow-x: hidden !important;
}

/* Main page wrapper */
body.sm-activity-embed-mode #page {
    min-height: auto !important;
    height: auto !important;
}

body.sm-activity-embed-mode #page-wrapper {
    min-height: auto !important;
}

/* Page content area */
body.sm-activity-embed-mode #page-content {
    padding-bottom: 20px !important;
}

/* Region main - let content flow naturally */
body.sm-activity-embed-mode #region-main {
    overflow: visible !important;
    height: auto !important;
}

body.sm-activity-embed-mode #region-main-box {
    overflow: visible !important;
}

/* Page module content - remove any height restrictions */
.page-content-container,
#page-mod-page-content,
.mod_page .generalbox,
body.sm-activity-embed-mode .box.generalbox,
body.sm-activity-embed-mode .generalbox {
    overflow: visible !important;
    max-height: none !important;
    height: auto !important;
}

/* Lesson content */
body.sm-activity-embed-mode .lesson-content,
body.sm-activity-embed-mode #lesson-content,
body.sm-activity-embed-mode .mod_lesson .generalbox {
    overflow: visible !important;
    max-height: none !important;
    height: auto !important;
}

/* Page content inside region-main */
#region-main-box .generalbox.box {
    overflow: visible !important;
}

/* --- GENERAL CONTENT OVERFLOW --- */
/* Prevent horizontal overflow in content areas */
.activity-content,
.activityinstance,
#intro,
.box.generalbox {
    max-width: 100% !important;
    overflow-x: hidden !important;
}

/* Images should not overflow */
img {
    max-width: 100% !important;
    height: auto !important;
}

/* Tables should scroll horizontally if needed */
.table-responsive,
table {
    max-width: 100% !important;
}

table:not(.table-responsive table):not(.fp-filename-field):not(.filemanager table):not(.foldertree table) {
    display: block !important;
    overflow-x: auto !important;
}

/* --- FOLDER SPECIFIC STYLING --- */
/* Clean up folder file tree appearance */
.foldertree,
.filemanager,
.fp-filename-field {
    display: table !important;
    width: 100% !important;
}

/* Folder file listing */
.folder-content,
.box.generalbox.foldertree {
    padding: var(--sl-spacing-md) !important;
}

/* Folder tree table - clean modern look */
.foldertree table,
.fp-folder table {
    display: table !important;
    width: 100% !important;
    border-collapse: collapse !important;
    border: none !important;
}

.foldertree table td,
.foldertree table th,
.fp-folder table td {
    border: none !important;
    padding: 8px 12px !important;
    vertical-align: middle !important;
}

/* File rows */
.foldertree tr,
.fp-folder tr {
    border-bottom: 1px solid var(--sl-gray-200) !important;
    transition: background-color var(--sl-transition-fast) !important;
}

.foldertree tr:hover,
.fp-folder tr:hover {
    background-color: var(--sl-gray-50) !important;
}

.foldertree tr:last-child,
.fp-folder tr:last-child {
    border-bottom: none !important;
}

/* File icons */
.foldertree .fp-icon,
.foldertree .icon,
.fp-folder .fp-icon {
    width: 24px !important;
    height: 24px !important;
    margin-right: 8px !important;
}

/* File names as links */
.foldertree a,
.fp-folder a {
    color: var(--sl-primary) !important;
    text-decoration: none !important;
    font-weight: 500 !important;
}

.foldertree a:hover,
.fp-folder a:hover {
    text-decoration: underline !important;
}

/* Remove tree connector lines */
.foldertree .tree_item::before,
.foldertree .tree_item::after,
.fp-folder .fp-filename::before {
    display: none !important;
}

/* Download folder button */
.folder-download-button,
.singlebutton {
    margin-top: var(--sl-spacing-md) !important;
}

.folder-download-button input[type="submit"],
.folder-download-button button {
    background-color: var(--sl-primary) !important;
    color: white !important;
    border: none !important;
    padding: 10px 20px !important;
    border-radius: var(--sl-radius) !important;
    cursor: pointer !important;
    font-weight: 500 !important;
}

.folder-download-button input[type="submit"]:hover,
.folder-download-button button:hover {
    background-color: var(--sl-primary-dark) !important;
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

    // ============================================================
    // FALLBACK PROTECTION
    // Detect when page navigates away from the activity (/mod/).
    // Show blank page instead of Moodle dashboard or other pages.
    // This prevents users from accidentally leaving the embed context.
    // ============================================================
    (function() {
        var currentPath = window.location.pathname;
        var isActivityPage = currentPath.indexOf('/mod/') !== -1;
        var isLocalEmbed = currentPath.indexOf('/local/sm_estratoos_plugin/') !== -1;

        // If we're not on an activity page or embed page, blank the page
        if (!isActivityPage && !isLocalEmbed) {
            // We've navigated away from the activity - show blank page
            document.documentElement.innerHTML = '<html><head><style>body{background:#f8f9fa;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#6c757d;}</style></head><body><div style="text-align:center;"><p>Activity session ended.</p><p style="font-size:0.875rem;">Please return to the course to continue.</p></div></body></html>';
            return;
        }

        // Intercept all link clicks to prevent navigation away
        document.addEventListener('click', function(e) {
            var target = e.target.closest('a');
            if (!target) return;

            var href = target.getAttribute('href');
            if (!href) return;

            // Allow same-page anchors
            if (href.startsWith('#')) return;

            // Allow javascript: links
            if (href.startsWith('javascript:')) return;

            // Check if link goes outside /mod/ or /local/sm_estratoos_plugin/
            try {
                var url = new URL(href, window.location.origin);
                var linkPath = url.pathname;
                var isAllowed = linkPath.indexOf('/mod/') !== -1 ||
                                linkPath.indexOf('/local/sm_estratoos_plugin/') !== -1 ||
                                linkPath.indexOf('/pluginfile.php') !== -1 ||
                                linkPath.indexOf('/draftfile.php') !== -1;

                if (!isAllowed) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Show message in place of navigation
                    document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:#f8f9fa;font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#6c757d;text-align:center;"><div><p>Activity session ended.</p><p style="font-size:0.875rem;">Please return to the course to continue.</p></div></div>';
                }
            } catch (err) {
                // Invalid URL, block it
                e.preventDefault();
            }
        }, true);

        // Also intercept form submissions
        document.addEventListener('submit', function(e) {
            var form = e.target;
            var action = form.getAttribute('action') || '';

            try {
                var url = new URL(action, window.location.origin);
                var formPath = url.pathname;
                var isAllowed = formPath.indexOf('/mod/') !== -1 ||
                                formPath.indexOf('/local/sm_estratoos_plugin/') !== -1;

                if (!isAllowed && action !== '') {
                    e.preventDefault();
                    document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:#f8f9fa;font-family:-apple-system,BlinkMacSystemFont,sans-serif;color:#6c757d;text-align:center;"><div><p>Activity session ended.</p><p style="font-size:0.875rem;">Please return to the course to continue.</p></div></div>';
                }
            } catch (err) {
                // Allow form submission if we can't parse the URL
            }
        }, true);
    })();
})();
</script>
HTML;
    }
}
