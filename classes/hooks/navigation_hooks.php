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
 * Navigation hook handlers for the SmartMind Estratoos plugin.
 *
 * Adds the SmartMind Token Manager to Moodle's navigation tree so that
 * site administrators and IOMAD company managers can access it from the sidebar.
 *
 * Flow:
 *   1. Moodle calls extend_navigation() on every page load for authenticated users
 *   2. If user is a site admin or company manager:
 *      a. Check for post-install redirect flag (first visit after install/upgrade)
 *      b. Add "SmartMind Token Manager" to the site administration navigation node
 *      c. Add a top-level navigation node for easier access in the sidebar
 *   3. If user is not authorized → do nothing
 *
 * Post-install redirect:
 *   After plugin installation or upgrade, a redirect flag is set in plugin config.
 *   On the next page load, the admin is redirected to the plugin dashboard.
 *   Safety: the flag expires after 5 minutes to prevent redirect loops during upgrades.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\hooks;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles navigation tree extension for the SmartMind Token Manager.
 *
 * Called by the thin delegator functions in lib.php:
 *   local_sm_estratoos_plugin_extend_navigation()          → navigation_hooks::extend_navigation()
 *   local_sm_estratoos_plugin_extend_settings_navigation() → navigation_hooks::extend_settings_navigation()
 */
class navigation_hooks {

    /**
     * Extend Moodle's global navigation to include the SmartMind Token Manager.
     *
     * This adds the plugin to two locations in the navigation:
     *   1. Under "Site administration" (for admins who use the admin tree)
     *   2. As a top-level node with showinflatnavigation=true (appears in the sidebar drawer)
     *
     * Additionally handles post-install/upgrade redirect:
     *   - After install, db/install.php sets a 'redirect_to_dashboard' config flag
     *   - This method checks the flag and redirects the admin to the plugin dashboard
     *   - The flag is only valid for 5 minutes (prevents loops if upgrade is slow)
     *   - The redirect is skipped on: plugin pages, web service calls, AJAX requests, upgrade pages
     *
     * @param \global_navigation $navigation The Moodle navigation tree to extend.
     */
    public static function extend_navigation(\global_navigation $navigation) {
        global $CFG, $PAGE;

        // ========================================
        // POST-INSTALL REDIRECT LOGIC
        // Redirects admin to plugin dashboard after fresh install/upgrade.
        // Only triggers once, with a 5-minute safety window.
        // ========================================
        if (is_siteadmin() && !defined('ABORT_AFTER_CONFIG') && !CLI_SCRIPT) {
            $redirectflag = get_config('local_sm_estratoos_plugin', 'redirect_to_dashboard');
            if ($redirectflag) {
                // Only redirect if the flag was set within the last 5 minutes.
                // This prevents stale flags from causing unexpected redirects.
                if ((time() - $redirectflag) < 300) {
                    // Determine the current page to avoid redirect loops.
                    $currenturl = $PAGE->url->get_path();
                    $ispluginpage = strpos($currenturl, '/local/sm_estratoos_plugin/') !== false;
                    $iswebservice = strpos($currenturl, '/webservice/') !== false;
                    $isajax = defined('AJAX_SCRIPT') && AJAX_SCRIPT;

                    // Don't redirect during upgrade process pages.
                    $isupgradepage = strpos($currenturl, '/admin/upgradesettings.php') !== false
                        || strpos($currenturl, '/admin/environment.php') !== false
                        || (strpos($currenturl, '/admin/index.php') !== false && optional_param('cache', 0, PARAM_INT));

                    // Redirect from any non-excluded page.
                    if (!$ispluginpage && !$iswebservice && !$isajax && !$isupgradepage) {
                        // Clear the flag FIRST to prevent infinite redirect loops.
                        unset_config('redirect_to_dashboard', 'local_sm_estratoos_plugin');
                        redirect(new \moodle_url('/local/sm_estratoos_plugin/index.php'));
                    }
                } else {
                    // Flag is older than 5 minutes — clear it as stale.
                    unset_config('redirect_to_dashboard', 'local_sm_estratoos_plugin');
                }
            }
        }

        // ========================================
        // ACCESS CHECK
        // Only show navigation items to authorized users.
        // ========================================
        // Show plugin to: site admins always, token admins (active company),
        // and potential managers (managertype > 0, even if plugin/company not yet activated).
        if (!is_siteadmin()
            && !\local_sm_estratoos_plugin\util::is_token_admin()
            && !\local_sm_estratoos_plugin\util::is_potential_token_admin()) {
            return;
        }

        // ========================================
        // ADD NAVIGATION NODES
        // ========================================
        $url = new \moodle_url('/local/sm_estratoos_plugin/index.php');
        $nodename = get_string('pluginname', 'local_sm_estratoos_plugin');

        // Add to the site administration node (appears in admin tree).
        $siteadmin = $navigation->find('siteadministration', \navigation_node::TYPE_SITE_ADMIN);
        if ($siteadmin) {
            $siteadmin->add(
                $nodename,
                $url,
                \navigation_node::TYPE_CUSTOM,
                null,
                'sm_estratoos_plugin',
                new \pix_icon('i/settings', '')
            );
        }

        // Also add as a top-level node for easier sidebar access.
        $node = $navigation->add(
            $nodename,
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'sm_estratoos_plugin_main',
            new \pix_icon('i/settings', '')
        );
        // showinflatnavigation = true makes it appear in the left sidebar/drawer.
        $node->showinflatnavigation = true;
    }

    /**
     * Extend settings navigation.
     *
     * Currently a placeholder for future settings page integration.
     *
     * @param \settings_navigation $settingsnav The settings navigation tree.
     * @param \context $context The current context.
     */
    public static function extend_settings_navigation(\settings_navigation $settingsnav, \context $context) {
        // Settings navigation extension if needed in the future.
    }
}
