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
 * Navbar hook handlers for the SmartMind Estratoos plugin.
 *
 * Renders the SmartMind Token Manager icon in the Boost theme's top navbar,
 * positioned next to the notification bell for easy access by administrators.
 *
 * Two-phase rendering flow:
 *   Phase 1 (render_navbar_output):
 *     - Moodle's Boost theme calls this hook to get extra navbar HTML
 *     - We return a key icon (fa-key) wrapped in a popover-region container
 *     - The icon is initially placed wherever Boost inserts extra navbar output
 *
 *   Phase 2 (before_standard_top_of_body_html):
 *     - Moodle calls this hook to inject HTML/JS at the top of <body>
 *     - We inject a small DOMContentLoaded script that:
 *       1. Finds our key icon by ID (sm-tokens-navbar-icon)
 *       2. Finds the notification bell (.popover-region-notifications)
 *       3. Moves our icon to be immediately LEFT of the bell
 *     - This ensures consistent positioning regardless of theme layout
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\hooks;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles navbar icon rendering and repositioning for the Token Manager.
 *
 * Called by the thin delegator functions in lib.php:
 *   local_sm_estratoos_plugin_render_navbar_output()             → navbar_hooks::render_navbar_output()
 *   local_sm_estratoos_plugin_before_standard_top_of_body_html() → navbar_hooks::before_standard_top_of_body_html()
 */
class navbar_hooks {

    /**
     * Render the Token Manager icon for the Boost navbar.
     *
     * Creates a key icon (fa-key) that links to the Token Manager dashboard.
     * The icon is styled to match Moodle's notification bell structure so it
     * blends naturally into the Boost theme navbar.
     *
     * @param \renderer_base $renderer The Moodle renderer (not used directly, but required by hook signature).
     * @return string HTML for the navbar icon, or empty string if user is not authorized.
     */
    public static function render_navbar_output(\renderer_base $renderer) {
        global $CFG;

        // Only show for site admins and company managers.
        if (!\local_sm_estratoos_plugin\util::is_token_admin()) {
            return '';
        }

        $url = new \moodle_url('/local/sm_estratoos_plugin/index.php');
        $title = get_string('pluginname', 'local_sm_estratoos_plugin');

        // Build the icon container matching the notification bell's popover-region structure.
        // This ensures consistent styling with other navbar icons.
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
     * Inject JavaScript to reposition the Token Manager icon next to the notification bell.
     *
     * This runs on every page load (for authorized users) and moves the icon
     * from wherever Boost initially placed it to be immediately before the
     * notification bell in the DOM. This is needed because Boost's hook
     * insertion point doesn't guarantee position relative to other navbar elements.
     *
     * @return string HTML/JS to inject at the top of <body>, or empty string if not applicable.
     */
    public static function before_standard_top_of_body_html() {
        // Only inject for authorized users.
        if (!\local_sm_estratoos_plugin\util::is_token_admin()) {
            return '';
        }

        // Don't inject on AJAX or web service calls (no navbar to modify).
        if (defined('AJAX_SCRIPT') || defined('WS_SERVER')) {
            return '';
        }

        // JavaScript to reposition the icon to the LEFT of the notification bell.
        $js = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Find our icon (rendered by render_navbar_output above).
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
    // insertBefore(newNode, referenceNode) places newNode right before referenceNode.
    notificationBell.parentNode.insertBefore(tokenIcon, notificationBell);
});
</script>
JS;

        return $js;
    }
}
