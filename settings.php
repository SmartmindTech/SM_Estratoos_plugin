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
 * Admin settings for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create a new settings page.
    $settings = new admin_settingpage('local_sm_estratoos_plugin', get_string('pluginname', 'local_sm_estratoos_plugin'));

    // Add settings page to local plugins.
    $ADMIN->add('localplugins', $settings);

    $isactivated = (bool) get_config('local_sm_estratoos_plugin', 'is_activated');

    // === ACTIVATION STATUS (always visible) ===
    $statusbadge = $isactivated
        ? '<span class="badge badge-success bg-success p-2">' . get_string('statusactive', 'local_sm_estratoos_plugin') . '</span>'
        : '<span class="badge badge-danger bg-danger p-2">' . get_string('statusnotactivated', 'local_sm_estratoos_plugin') . '</span>';

    $activationdesc = $statusbadge;
    if (!$isactivated) {
        $activateurl = new moodle_url('/local/sm_estratoos_plugin/activate.php');
        $activationdesc .= ' &mdash; <a href="' . $activateurl->out() . '" class="btn btn-sm btn-primary ml-2">'
            . get_string('activateplugin', 'local_sm_estratoos_plugin') . '</a>';
    }

    $settings->add(new admin_setting_heading(
        'local_sm_estratoos_plugin/activation_heading',
        get_string('activationstatus', 'local_sm_estratoos_plugin'),
        $activationdesc
    ));

    // Only show full settings when activated.
    if ($isactivated) {
        // === TOKEN SETTINGS ===
        $settings->add(new admin_setting_heading(
            'local_sm_estratoos_plugin/token_heading',
            get_string('tokensettings', 'local_sm_estratoos_plugin'),
            ''
        ));

        // Default validity period in days.
        $settings->add(new admin_setting_configtext(
            'local_sm_estratoos_plugin/default_validity_days',
            get_string('defaultvaliditydays', 'local_sm_estratoos_plugin'),
            get_string('defaultvaliditydays_desc', 'local_sm_estratoos_plugin'),
            365,
            PARAM_INT
        ));

        // Default: Restrict to company.
        $settings->add(new admin_setting_configcheckbox(
            'local_sm_estratoos_plugin/default_restricttocompany',
            get_string('defaultrestricttocompany', 'local_sm_estratoos_plugin'),
            get_string('defaultrestricttocompany_desc', 'local_sm_estratoos_plugin'),
            1
        ));

        // Default: Restrict to enrollment.
        $settings->add(new admin_setting_configcheckbox(
            'local_sm_estratoos_plugin/default_restricttoenrolment',
            get_string('defaultrestricttoenrolment', 'local_sm_estratoos_plugin'),
            get_string('defaultrestricttoenrolment_desc', 'local_sm_estratoos_plugin'),
            1
        ));

        // Allow individual overrides.
        $settings->add(new admin_setting_configcheckbox(
            'local_sm_estratoos_plugin/allow_individual_overrides',
            get_string('allowindividualoverrides', 'local_sm_estratoos_plugin'),
            get_string('allowindividualoverrides_desc', 'local_sm_estratoos_plugin'),
            1
        ));

        // Auto-cleanup expired tokens.
        $settings->add(new admin_setting_configcheckbox(
            'local_sm_estratoos_plugin/cleanup_expired_tokens',
            get_string('cleanupexpiredtokens', 'local_sm_estratoos_plugin'),
            get_string('cleanupexpiredtokens_desc', 'local_sm_estratoos_plugin'),
            1
        ));

        // NOTE: Webhook settings (webhook_enabled, webhook_url, instance_id) and
        // SmartLearning integration settings (oauth2_issuer_url, oauth2_allowed_origins,
        // oauth2_jwks_cache_ttl) are managed internally by the plugin and SmartLearning.
        // They are NOT exposed in the admin UI. Default values are set in db/install.php
        // and can be overridden programmatically via set_config().
    }

    // Add external pages for token management (only visible to site admins).
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_sm_estratoos_plugin_dashboard',
        get_string('dashboard', 'local_sm_estratoos_plugin'),
        new moodle_url('/local/sm_estratoos_plugin/index.php'),
        'moodle/site:config'
    ));
}
