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
 * Upgrade script for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Executed on plugin upgrade.
 *
 * @param int $oldversion The old version of the plugin.
 * @return bool
 */
function xmldb_local_sm_estratoos_plugin_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Fix token names for existing tokens (v1.2.20).
    if ($oldversion < 2025011224) {
        // Only run if IOMAD (company table) exists.
        if ($dbman->table_exists('company')) {
            // Get all tokens from our plugin table that have a company.
            $sql = "SELECT smp.id, smp.tokenid, smp.companyid, et.userid
                    FROM {local_sm_estratoos_plugin} smp
                    JOIN {external_tokens} et ON et.id = smp.tokenid
                    WHERE smp.companyid IS NOT NULL AND smp.companyid > 0";

            $tokens = $DB->get_records_sql($sql);

            foreach ($tokens as $token) {
                // Get user info.
                $user = $DB->get_record('user', ['id' => $token->userid], 'id, firstname, lastname');
                if (!$user) {
                    continue;
                }

                // Get company info.
                $company = $DB->get_record('company', ['id' => $token->companyid], 'id, shortname');
                if (!$company) {
                    continue;
                }

                // Generate token name: FIRSTNAME_LASTNAME_COMPANY.
                $firstname = strtoupper(str_replace(' ', '_', trim($user->firstname)));
                $lastname = strtoupper(str_replace(' ', '_', trim($user->lastname)));
                $companyname = strtoupper(str_replace(' ', '_', trim($company->shortname)));
                $tokenname = $firstname . '_' . $lastname . '_' . $companyname;

                // Update the external_tokens table.
                $DB->set_field('external_tokens', 'name', $tokenname, ['id' => $token->tokenid]);
            }
        }

        upgrade_plugin_savepoint(true, 2025011224, 'local', 'sm_estratoos_plugin');
    }

    // Create deletion history table (v1.2.22).
    if ($oldversion < 2025011226) {
        // Define table local_sm_estratoos_plugin_del.
        $table = new xmldb_table('local_sm_estratoos_plugin_del');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('batchid', XMLDB_TYPE_CHAR, '40', null, null, null, null);
        $table->add_field('tokenname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userfullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('companyname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('deletedby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timedeleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('deletedby_fk', XMLDB_KEY_FOREIGN, ['deletedby'], 'user', ['id']);

        // Adding indexes.
        $table->add_index('batchid_idx', XMLDB_INDEX_NOTUNIQUE, ['batchid']);
        $table->add_index('companyid_idx', XMLDB_INDEX_NOTUNIQUE, ['companyid']);
        $table->add_index('timedeleted_idx', XMLDB_INDEX_NOTUNIQUE, ['timedeleted']);

        // Create the table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2025011226, 'local', 'sm_estratoos_plugin');
    }

    // Auto-configure web services (v1.2.34).
    if ($oldversion < 2025011238) {
        // Include install.php to use the configure function.
        require_once(__DIR__ . '/install.php');
        xmldb_local_sm_estratoos_plugin_configure_webservices();

        upgrade_plugin_savepoint(true, 2025011238, 'local', 'sm_estratoos_plugin');
    }

    // Add category-context functions and mobile service integration (v1.4.0).
    if ($oldversion < 2025011400) {
        // Include install.php to use the mobile service function.
        require_once(__DIR__ . '/install.php');

        // Add all plugin functions to Moodle mobile web service.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011400, 'local', 'sm_estratoos_plugin');
    }

    // Create dedicated SmartMind service and clean up mobile service (v1.4.1).
    if ($oldversion < 2025011401) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Remove plugin functions from Moodle mobile web service (cleanup from v1.4.0).
        xmldb_local_sm_estratoos_plugin_remove_from_mobile_service();

        // Create/update the dedicated SmartMind - Estratoos Plugin service.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011401, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Copy ALL mobile service functions to SmartMind service (v1.4.2).
    if ($oldversion < 2025011402) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run the function to copy all mobile functions (fixes v1.4.1 issue).
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011402, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Improved function copy from mobile service using external_functions table (v1.4.3).
    if ($oldversion < 2025011403) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run with improved logic that also checks external_functions table.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011403, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Rewritten mobile function copy using SQL subqueries (v1.4.4).
    if ($oldversion < 2025011404) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run with SQL subquery approach.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011404, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Clear and re-copy with try/catch for error handling (v1.4.5).
    if ($oldversion < 2025011405) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run with clear-and-copy approach.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011405, 'local', 'sm_estratoos_plugin');
    }

    // Debug: Added logging to diagnose function copy issue (v1.4.6).
    if ($oldversion < 2025011406) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run with logging enabled.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011406, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Removed service from services.php, re-populate functions (v1.4.7).
    if ($oldversion < 2025011407) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // Re-run to populate functions (now that services.php won't overwrite).
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011407, 'local', 'sm_estratoos_plugin');
    }

    // Fix: Re-create service without component (v1.4.8).
    if ($oldversion < 2025011408) {
        // Include install.php to use the service functions.
        require_once(__DIR__ . '/install.php');

        // This will re-create the service (since v1.4.7 caused Moodle to delete it).
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011408, 'local', 'sm_estratoos_plugin');
    }

    // Remove duplicate forum functions with shorter names (v1.4.9).
    if ($oldversion < 2025011409) {
        global $DB;

        // Get the SmartMind service.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service) {
            // Remove duplicate/old forum functions.
            $duplicatefunctions = [
                'local_forum_create',
                'local_forum_edit',
                'local_forum_delete',
                'local_discussion_edit',
                'local_discussion_delete',
            ];
            foreach ($duplicatefunctions as $funcname) {
                $DB->delete_records('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $funcname,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2025011409, 'local', 'sm_estratoos_plugin');
    }

    // Ensure SmartMind service exists for fresh installs or missed upgrades (v1.4.10).
    // This runs regardless of $oldversion to fix installations where service wasn't created.
    if ($oldversion < 2025011410) {
        // Include install.php to use the ensure function.
        require_once(__DIR__ . '/install.php');

        // Ensure the service exists and populate functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011410, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.12: Re-run webservice configuration to fix access exceptions for non-admin tokens.
    // This adds webservice/rest:use capability to the 'user' role (authenticated users),
    // which fixes the issue where student/teacher tokens didn't have web service access.
    if ($oldversion < 2025011412) {
        // Include install.php to use the configure function.
        require_once(__DIR__ . '/install.php');

        // Re-run webservice configuration with updated logic.
        xmldb_local_sm_estratoos_plugin_configure_webservices();

        upgrade_plugin_savepoint(true, 2025011412, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.13: Assign webservice role to existing token users.
    // This fixes tokens created before v1.4.13 that don't have the capability.
    if ($oldversion < 2025011413) {
        global $DB;

        // Get all users who have tokens from our plugin.
        $sql = "SELECT DISTINCT et.userid
                FROM {external_tokens} et
                JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id";
        $tokenusers = $DB->get_records_sql($sql);

        if (!empty($tokenusers)) {
            $systemcontext = context_system::instance();

            // Find a role with webservice/rest:use capability.
            $rolesql = "SELECT DISTINCT r.id, r.shortname
                        FROM {role} r
                        JOIN {role_capabilities} rc ON rc.roleid = r.id
                        JOIN {context} c ON c.id = rc.contextid
                        WHERE rc.capability = 'webservice/rest:use'
                          AND rc.permission = " . CAP_ALLOW . "
                          AND c.contextlevel = " . CONTEXT_SYSTEM;
            $role = $DB->get_record_sql($rolesql);

            if (!$role) {
                // No role with capability. Try to add it to 'user' or 'student' role.
                $role = $DB->get_record('role', ['shortname' => 'user']);
                if (!$role) {
                    $role = $DB->get_record('role', ['shortname' => 'student']);
                }

                if ($role) {
                    // Add the capability to this role.
                    if (!$DB->record_exists('role_capabilities', [
                        'roleid' => $role->id,
                        'capability' => 'webservice/rest:use',
                        'contextid' => $systemcontext->id,
                    ])) {
                        $DB->insert_record('role_capabilities', [
                            'roleid' => $role->id,
                            'capability' => 'webservice/rest:use',
                            'contextid' => $systemcontext->id,
                            'permission' => CAP_ALLOW,
                            'timemodified' => time(),
                            'modifierid' => get_admin()->id,
                        ]);
                    }
                }
            }

            if ($role) {
                // Assign the role to all token users at system level.
                foreach ($tokenusers as $tokenuser) {
                    // Skip site admins - they already have all capabilities.
                    if (is_siteadmin($tokenuser->userid)) {
                        continue;
                    }

                    // Check if user already has this role at system level.
                    if (!$DB->record_exists('role_assignments', [
                        'roleid' => $role->id,
                        'contextid' => $systemcontext->id,
                        'userid' => $tokenuser->userid,
                    ])) {
                        role_assign($role->id, $tokenuser->userid, $systemcontext->id);
                    }
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025011413, 'local', 'sm_estratoos_plugin');
    }

    return true;
}
