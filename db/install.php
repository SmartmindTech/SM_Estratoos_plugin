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
 * Install script for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Executed on plugin installation.
 *
 * @return bool
 */
function xmldb_local_sm_estratoos_plugin_install() {
    global $DB, $CFG;

    // Set default configuration values.
    set_config('default_validity_days', 365, 'local_sm_estratoos_plugin');
    set_config('default_restricttocompany', 1, 'local_sm_estratoos_plugin');
    set_config('default_restricttoenrolment', 1, 'local_sm_estratoos_plugin');
    set_config('allow_individual_overrides', 1, 'local_sm_estratoos_plugin');
    set_config('cleanup_expired_tokens', 1, 'local_sm_estratoos_plugin');

    // Auto-configure web services for the plugin to work properly.
    xmldb_local_sm_estratoos_plugin_configure_webservices();

    // Add plugin functions to Moodle mobile web service.
    xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

    return true;
}

/**
 * Configure web services automatically.
 * This enables the necessary settings for the plugin to work.
 */
function xmldb_local_sm_estratoos_plugin_configure_webservices() {
    global $DB, $CFG;

    // 1. Enable web services globally.
    set_config('enablewebservices', 1);

    // 2. Enable REST protocol.
    $protocols = !empty($CFG->webserviceprotocols) ? explode(',', $CFG->webserviceprotocols) : [];
    if (!in_array('rest', $protocols)) {
        $protocols[] = 'rest';
        set_config('webserviceprotocols', implode(',', $protocols));
    }

    // 3. Enable "Moodle mobile web service".
    $mobileservice = $DB->get_record('external_services', ['shortname' => 'moodle_mobile_app']);
    if ($mobileservice && !$mobileservice->enabled) {
        $DB->set_field('external_services', 'enabled', 1, ['id' => $mobileservice->id]);
    }

    // 4 & 5. Configure Teacher and Student roles.
    $rolestoconfig = ['editingteacher', 'student'];

    foreach ($rolestoconfig as $roleshortname) {
        $role = $DB->get_record('role', ['shortname' => $roleshortname]);
        if (!$role) {
            continue;
        }

        // 4. Enable "System" context for the role.
        // Context level 10 = CONTEXT_SYSTEM.
        $existingcontext = $DB->get_record('role_context_levels', [
            'roleid' => $role->id,
            'contextlevel' => CONTEXT_SYSTEM
        ]);
        if (!$existingcontext) {
            $DB->insert_record('role_context_levels', [
                'roleid' => $role->id,
                'contextlevel' => CONTEXT_SYSTEM
            ]);
        }

        // 5. Add capabilities to the role in system context.
        $systemcontext = context_system::instance();
        $capabilities = ['moodle/site:sendmessage', 'webservice/rest:use'];

        foreach ($capabilities as $capability) {
            // Check if capability exists in the system.
            if (!$DB->record_exists('capabilities', ['name' => $capability])) {
                continue;
            }

            // Check if role already has this capability.
            $existingperm = $DB->get_record('role_capabilities', [
                'roleid' => $role->id,
                'capability' => $capability,
                'contextid' => $systemcontext->id
            ]);

            if (!$existingperm) {
                // Add the capability with CAP_ALLOW permission.
                $DB->insert_record('role_capabilities', [
                    'roleid' => $role->id,
                    'capability' => $capability,
                    'contextid' => $systemcontext->id,
                    'permission' => CAP_ALLOW,
                    'timemodified' => time(),
                    'modifierid' => get_admin()->id
                ]);
            } else if ($existingperm->permission != CAP_ALLOW) {
                // Update to allow if it was previously denied/prohibited.
                $DB->set_field('role_capabilities', 'permission', CAP_ALLOW, ['id' => $existingperm->id]);
                $DB->set_field('role_capabilities', 'timemodified', time(), ['id' => $existingperm->id]);
            }
        }
    }

    // Purge caches to ensure changes take effect.
    purge_all_caches();
}

/**
 * Create dedicated SmartMind - Estratoos Plugin web service.
 * This creates a separate service with all plugin functions instead of modifying the Moodle mobile service.
 */
function xmldb_local_sm_estratoos_plugin_add_to_mobile_service() {
    global $DB;

    $serviceshortname = 'sm_estratoos_plugin';
    $servicename = 'SmartMind - Estratoos Plugin';

    // Check if service already exists.
    $service = $DB->get_record('external_services', ['shortname' => $serviceshortname]);

    if (!$service) {
        // Create the service.
        $serviceid = $DB->insert_record('external_services', [
            'name' => $servicename,
            'shortname' => $serviceshortname,
            'enabled' => 1,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => 'local_sm_estratoos_plugin',
            'timecreated' => time(),
            'timemodified' => time(),
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);
    } else {
        $serviceid = $service->id;
        // Ensure service is enabled.
        if (!$service->enabled) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $serviceid]);
        }
    }

    // List of all plugin functions to add to the service.
    $functions = [
        // Token management functions.
        'local_sm_estratoos_plugin_create_batch',
        'local_sm_estratoos_plugin_get_tokens',
        'local_sm_estratoos_plugin_revoke',
        'local_sm_estratoos_plugin_get_company_users',
        'local_sm_estratoos_plugin_get_companies',
        'local_sm_estratoos_plugin_get_services',
        'local_sm_estratoos_plugin_create_admin_token',
        'local_sm_estratoos_plugin_get_batch_history',
        // Forum functions.
        'local_sm_estratoos_plugin_forum_create',
        'local_sm_estratoos_plugin_forum_edit',
        'local_sm_estratoos_plugin_forum_delete',
        'local_sm_estratoos_plugin_discussion_edit',
        'local_sm_estratoos_plugin_discussion_delete',
        // Category-context functions.
        'local_sm_estratoos_plugin_get_users_by_field',
        'local_sm_estratoos_plugin_get_users',
        'local_sm_estratoos_plugin_get_categories',
        'local_sm_estratoos_plugin_get_conversations',
    ];

    foreach ($functions as $functionname) {
        // Check if function exists in external_functions.
        $function = $DB->get_record('external_functions', ['name' => $functionname]);
        if (!$function) {
            // Function not registered yet, skip.
            continue;
        }

        // Check if function is already in the service.
        $existing = $DB->get_record('external_services_functions', [
            'externalserviceid' => $serviceid,
            'functionname' => $functionname,
        ]);

        if (!$existing) {
            // Add function to the service.
            $DB->insert_record('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname' => $functionname,
            ]);
        }
    }
}

/**
 * Remove plugin functions from Moodle mobile web service.
 * Called during upgrade to clean up functions added in previous versions.
 */
function xmldb_local_sm_estratoos_plugin_remove_from_mobile_service() {
    global $DB;

    // Get mobile service.
    $mobileservice = $DB->get_record('external_services', ['shortname' => 'moodle_mobile_app']);
    if (!$mobileservice) {
        return;
    }

    // List of plugin functions to remove from mobile service.
    $functions = [
        'local_sm_estratoos_plugin_create_batch',
        'local_sm_estratoos_plugin_get_tokens',
        'local_sm_estratoos_plugin_revoke',
        'local_sm_estratoos_plugin_get_company_users',
        'local_sm_estratoos_plugin_get_companies',
        'local_sm_estratoos_plugin_get_services',
        'local_sm_estratoos_plugin_create_admin_token',
        'local_sm_estratoos_plugin_get_batch_history',
        'local_sm_estratoos_plugin_forum_create',
        'local_sm_estratoos_plugin_forum_edit',
        'local_sm_estratoos_plugin_forum_delete',
        'local_sm_estratoos_plugin_discussion_edit',
        'local_sm_estratoos_plugin_discussion_delete',
        'local_sm_estratoos_plugin_get_users_by_field',
        'local_sm_estratoos_plugin_get_users',
        'local_sm_estratoos_plugin_get_categories',
        'local_sm_estratoos_plugin_get_conversations',
    ];

    foreach ($functions as $functionname) {
        $DB->delete_records('external_services_functions', [
            'externalserviceid' => $mobileservice->id,
            'functionname' => $functionname,
        ]);
    }
}
