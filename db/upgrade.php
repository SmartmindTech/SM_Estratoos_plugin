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
                        WHERE rc.capability = :capability
                          AND rc.permission = :permission
                          AND c.contextlevel = :contextlevel";
            $role = $DB->get_record_sql($rolesql, [
                'capability' => 'webservice/rest:use',
                'permission' => CAP_ALLOW,
                'contextlevel' => CONTEXT_SYSTEM,
            ]);

            if (!$role) {
                // No role with capability. Try to add it to 'student' role.
                $role = $DB->get_record('role', ['shortname' => 'student']);

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

    // v1.4.16: Re-run capability repair (v1.4.13 failed due to SQL error).
    // This ensures all token users have the webservice/rest:use capability.
    if ($oldversion < 2025011416) {
        global $DB;

        // Get all users who have tokens from our plugin.
        $sql = "SELECT DISTINCT et.userid
                FROM {external_tokens} et
                JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id";
        $tokenusers = $DB->get_records_sql($sql);

        if (!empty($tokenusers)) {
            $systemcontext = context_system::instance();

            // First, ensure a role has the webservice/rest:use capability.
            // Use 'student' role (not 'user' - authenticated users).
            $role = $DB->get_record('role', ['shortname' => 'student']);

            if ($role) {
                // Add the capability to this role if it doesn't have it.
                $existingcap = $DB->get_record('role_capabilities', [
                    'roleid' => $role->id,
                    'capability' => 'webservice/rest:use',
                    'contextid' => $systemcontext->id,
                ]);

                if (!$existingcap) {
                    $DB->insert_record('role_capabilities', [
                        'roleid' => $role->id,
                        'capability' => 'webservice/rest:use',
                        'contextid' => $systemcontext->id,
                        'permission' => CAP_ALLOW,
                        'timemodified' => time(),
                        'modifierid' => get_admin()->id,
                    ]);
                }

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

        // Also run webservice configuration to ensure REST is enabled.
        require_once(__DIR__ . '/install.php');
        xmldb_local_sm_estratoos_plugin_configure_webservices();

        upgrade_plugin_savepoint(true, 2025011416, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.17: Fix service restrictedusers setting.
    // The service must have restrictedusers=0 to allow any authenticated user with capability.
    if ($oldversion < 2025011417) {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service && $service->restrictedusers != 0) {
            $DB->set_field('external_services', 'restrictedusers', 0, ['id' => $service->id]);
            $DB->set_field('external_services', 'timemodified', time(), ['id' => $service->id]);
        }

        upgrade_plugin_savepoint(true, 2025011417, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.19: Add course content retrieval function to SmartMind service.
    if ($oldversion < 2025011419) {
        global $DB;

        // Get the SmartMind service.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service) {
            // Add the new function directly to the service.
            $functionname = 'local_sm_estratoos_plugin_get_course_content';

            // Check if function is already in the service.
            $existing = $DB->get_record('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $functionname,
            ]);

            if (!$existing) {
                // Add function to the service.
                $DB->insert_record('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $functionname,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2025011419, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.20: Fix - Ensure course content function is added to service.
    if ($oldversion < 2025011420) {
        global $DB;

        // Get the SmartMind service.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service) {
            // Add the new function directly to the service.
            $functionname = 'local_sm_estratoos_plugin_get_course_content';

            // Check if function is already in the service.
            $existing = $DB->get_record('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname' => $functionname,
            ]);

            if (!$existing) {
                // Add function to the service.
                $DB->insert_record('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $functionname,
                ]);
            }
        }

        upgrade_plugin_savepoint(true, 2025011420, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.27: Add completion tracking function and ensure lesson/completion functions are available.
    if ($oldversion < 2025011427) {
        global $DB;

        // Get the SmartMind service.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service) {
            // Functions to ensure are in the service.
            $functions = [
                // New custom completion function.
                'local_sm_estratoos_plugin_mark_module_viewed',
                // Moodle core completion functions.
                'core_completion_update_activity_completion_status_manually',
                'core_completion_get_activities_completion_status',
                'core_completion_get_course_completion_status',
                // Lesson view function.
                'mod_lesson_view_lesson',
                'mod_lesson_launch_attempt',
                'mod_lesson_get_page_data',
                'mod_lesson_process_page',
                'mod_lesson_finish_attempt',
            ];

            foreach ($functions as $functionname) {
                // Check if function exists in external_functions table.
                $functionexists = $DB->record_exists('external_functions', ['name' => $functionname]);

                if ($functionexists) {
                    // Check if function is already in the service.
                    $existing = $DB->get_record('external_services_functions', [
                        'externalserviceid' => $service->id,
                        'functionname' => $functionname,
                    ]);

                    if (!$existing) {
                        // Add function to the service.
                        try {
                            $DB->insert_record('external_services_functions', [
                                'externalserviceid' => $service->id,
                                'functionname' => $functionname,
                            ]);
                        } catch (\Exception $e) {
                            // Ignore if already exists.
                        }
                    }
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025011427, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.28: Add generic grade update function.
    if ($oldversion < 2025011428) {
        global $DB;

        // Get the SmartMind service.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        if ($service) {
            $functionname = 'local_sm_estratoos_plugin_update_activity_grade';

            // Check if function exists in external_functions table.
            $functionexists = $DB->record_exists('external_functions', ['name' => $functionname]);

            if ($functionexists) {
                // Check if function is already in the service.
                $existing = $DB->get_record('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname' => $functionname,
                ]);

                if (!$existing) {
                    try {
                        $DB->insert_record('external_services_functions', [
                            'externalserviceid' => $service->id,
                            'functionname' => $functionname,
                        ]);
                    } catch (\Exception $e) {
                        // Ignore if already exists.
                    }
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025011428, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.29: Fix function not being added to service.
    // The v1.4.28 upgrade step failed because external_functions is populated AFTER upgrade.php runs.
    // This fix calls the full rebuild function which properly adds all plugin functions.
    if ($oldversion < 2025011429) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add any missing functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011429, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.37: Add health_check function and core_user_update_users to service.
    // This upgrade adds the new lightweight health check API for SmartLearning connectivity monitoring.
    if ($oldversion < 2025011637) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add health_check and core_user_update_users functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011637, 'local', 'sm_estratoos_plugin');
    }

    // v1.4.39: Fix - Run service rebuild for users who had v1.4.37/v1.4.38 without proper upgrade step.
    // The v1.4.37 release was missing the upgrade step, so this catches those installations.
    if ($oldversion < 2025011639) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add health_check and core_user_update_users functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011639, 'local', 'sm_estratoos_plugin');
    }

    // v1.5.0: Add bulk data optimization functions for SmartLearning performance improvements.
    // New functions: get_all_users_bulk, get_dashboard_summary, get_courses_with_progress_bulk,
    // get_changes_since, health_check_extended.
    // Also adds cache definitions and event observers for cache invalidation.
    if ($oldversion < 2025011700) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add new bulk optimization functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        // Purge caches to ensure new cache definitions are loaded.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011700, 'local', 'sm_estratoos_plugin');
    }

    // v1.5.1: Add get_conversation_messages function for messaging sync.
    // This function wraps core_message_get_conversation_messages but works with category-scoped
    // IOMAD tokens (context_coursecat) instead of requiring system context.
    // Supports bulk retrieval with pagination for conversations with hundreds/thousands of messages.
    if ($oldversion < 2025011701) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add the new conversation messages function.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011701, 'local', 'sm_estratoos_plugin');
    }

    // v1.6.0: Phase 2 - Login & Dashboard Optimization functions.
    // New functions: get_login_essentials, get_dashboard_complete, get_course_completion_bulk,
    // get_course_stats_bulk. These reduce login time from ~6s to <500ms and dashboard loading
    // from ~20s to <2s by eliminating N+1 query patterns.
    // Also adds new cache definitions for these functions.
    if ($oldversion < 2025011800) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add Phase 2 optimization functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        // Purge caches to ensure new cache definitions are loaded.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011800, 'local', 'sm_estratoos_plugin');
    }

    // v1.6.5: Add get_dashboard_stats function for optimized dashboard statistics.
    // This function returns course count, deadlines, urgent count, and to-grade count
    // in a single API call. Reduces dashboard stats loading from ~3.7s to <300ms.
    if ($oldversion < 2025011805) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add get_dashboard_stats function.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        // Purge caches to ensure new cache definitions are loaded.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011805, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.0: Add company access control feature.
    // Super administrators can now control which IOMAD companies have access to the plugin.
    // Company managers will only see the plugin if their company is enabled.
    // Also adds 'active' field to tokens table for suspending tokens when company is disabled.
    if ($oldversion < 2025011900) {
        // 1. Create the company access control table.
        $table = new xmldb_table('local_sm_estratoos_plugin_access');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('enabledby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Use FOREIGN_UNIQUE to combine foreign key and unique constraint (avoids collision).
        $table->add_key('companyid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['companyid'], 'company', ['id']);
        $table->add_key('enabledby_fk', XMLDB_KEY_FOREIGN, ['enabledby'], 'user', ['id']);

        // Adding indexes.
        $table->add_index('enabled_idx', XMLDB_INDEX_NOTUNIQUE, ['enabled']);

        // Create the table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // 2. Add 'active' field to the main tokens table.
        // This allows suspending tokens when a company is disabled without deleting them.
        $tokentable = new xmldb_table('local_sm_estratoos_plugin');
        $activefield = new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'notes');

        if (!$dbman->field_exists($tokentable, $activefield)) {
            $dbman->add_field($tokentable, $activefield);
        }

        // Add index on active field for performance.
        $activeindex = new xmldb_index('active_idx', XMLDB_INDEX_NOTUNIQUE, ['active']);
        if (!$dbman->index_exists($tokentable, $activeindex)) {
            $dbman->add_index($tokentable, $activeindex);
        }

        // 3. BACKWARD COMPATIBILITY: Enable all existing companies by default.
        // This ensures existing installations continue to work as before.
        // New companies (created after this upgrade) will NOT be auto-enabled.
        // All existing tokens remain active (default value = 1).
        if ($dbman->table_exists('company')) {
            $companies = $DB->get_records('company', [], '', 'id');
            $adminid = get_admin()->id;
            $time = time();

            foreach ($companies as $company) {
                if (!$DB->record_exists('local_sm_estratoos_plugin_access', ['companyid' => $company->id])) {
                    $DB->insert_record('local_sm_estratoos_plugin_access', [
                        'companyid' => $company->id,
                        'enabled' => 1,
                        'enabledby' => $adminid,
                        'timecreated' => $time,
                        'timemodified' => $time,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2025011900, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.1: Add system-level role assignment and admin/manager role capabilities.
    // This grants capabilities to admin/manager roles and assigns users to system-level
    // roles based on their course/category role names.
    if ($oldversion < 2025011901) {
        // Include install.php to use the configuration functions.
        require_once(__DIR__ . '/install.php');

        // Re-run webservice configuration to grant capabilities to admin/manager roles.
        xmldb_local_sm_estratoos_plugin_configure_webservices();

        // NOTE: This function call was removed in v1.7.15 because the function never existed
        // and was causing upgrade failures. System-level role assignment is no longer done.
        // xmldb_local_sm_estratoos_plugin_assign_system_roles();

        upgrade_plugin_savepoint(true, 2025011901, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.2: Fix XMLDB key/index collision for company access table.
    // The companyid field had both a foreign key and unique index which collide.
    // Changed to foreign-unique key type. No action needed for existing tables.
    if ($oldversion < 2025011902) {
        upgrade_plugin_savepoint(true, 2025011902, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.3: Add event observer for automatic system-level role assignment.
    // When users are assigned roles at course/category level, they now automatically
    // receive the corresponding system-level role (editingteacher, student, or manager).
    if ($oldversion < 2025011903) {
        // Purge caches to load the new event observer.
        purge_all_caches();

        // NOTE: This function call was removed in v1.7.15 because the function never existed
        // and was causing upgrade failures. System-level role assignment is no longer done.
        // require_once(__DIR__ . '/install.php');
        // xmldb_local_sm_estratoos_plugin_assign_system_roles();

        upgrade_plugin_savepoint(true, 2025011903, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.5: Add token_backup field and make tokenid nullable for proper token suspension.
    // When a company is disabled, tokens are deleted from external_tokens (blocking API calls)
    // and backed up to token_backup. When re-enabled, tokens are restored with same hash.
    if ($oldversion < 2025011905) {
        $table = new xmldb_table('local_sm_estratoos_plugin');

        // Add token_backup field for storing suspended token data.
        $field = new xmldb_field('token_backup', XMLDB_TYPE_TEXT, null, null, null, null, null, 'active');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Make tokenid nullable (needed for suspended tokens where external_tokens record is deleted).
        // First, drop the unique key/index on tokenid.
        $key = new xmldb_key('tokenid_fk', XMLDB_KEY_FOREIGN_UNIQUE, ['tokenid'], 'external_tokens', ['id']);
        $dbman->drop_key($table, $key);

        // Now change the field to be nullable.
        $field = new xmldb_field('tokenid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'id');
        $dbman->change_field_notnull($table, $field);

        // Re-add the key as a regular foreign key (not unique, since nulls are allowed).
        $key = new xmldb_key('tokenid_fk', XMLDB_KEY_FOREIGN, ['tokenid'], 'external_tokens', ['id']);
        $dbman->add_key($table, $key);

        upgrade_plugin_savepoint(true, 2025011905, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.7: Bug fixes - LEFT JOIN for suspended tokens, functions box size, cache purge.
    if ($oldversion < 2025011907) {
        // Purge caches to ensure AMD modules and CSS are reloaded.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011907, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.8: Use companymanager role, enable at system level, add debugging.
    if ($oldversion < 2025011908) {
        // Enable companymanager role for system context assignment.
        // This allows IOMAD company managers to be assigned the role at system level.
        $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
        if ($companymanagerrole) {
            // Check if already assignable at system level.
            $existing = $DB->get_record('role_context_levels', [
                'roleid' => $companymanagerrole->id,
                'contextlevel' => CONTEXT_SYSTEM,
            ]);
            if (!$existing) {
                $DB->insert_record('role_context_levels', [
                    'roleid' => $companymanagerrole->id,
                    'contextlevel' => CONTEXT_SYSTEM,
                ]);
            }
        }

        // Purge caches to ensure AMD modules and permissions are refreshed.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011908, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.9: Fix company search bar, add lazy role assignment for IOMAD managers.
    if ($oldversion < 2025011909) {
        // Purge all caches to ensure the updated AMD module is loaded.
        // The main fix is in the AMD module (companyaccess.js) and util.php.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011909, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.10: Fixed company search (inline JS), assign companymanager to existing IOMAD managers.
    if ($oldversion < 2025011910) {
        // Assign companymanager role at system level to all existing IOMAD managers
        // who don't already have a system-level admin/manager role.
        $dbman = $DB->get_manager();

        // Check if IOMAD tables exist.
        if ($dbman->table_exists('company_users') && $dbman->table_exists('company')) {
            // Get companymanager role (or fallback to manager).
            $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
            if (!$companymanagerrole) {
                $companymanagerrole = $DB->get_record('role', ['shortname' => 'manager']);
            }

            if ($companymanagerrole) {
                $systemcontext = context_system::instance();

                // Find all IOMAD company managers (managertype > 0).
                $sql = "SELECT DISTINCT cu.userid
                        FROM {company_users} cu
                        WHERE cu.managertype > 0";
                $iomadmanagers = $DB->get_records_sql($sql);

                foreach ($iomadmanagers as $manager) {
                    // Check if user already has this role at system level.
                    $hasrole = $DB->record_exists('role_assignments', [
                        'roleid' => $companymanagerrole->id,
                        'contextid' => $systemcontext->id,
                        'userid' => $manager->userid,
                    ]);

                    if (!$hasrole) {
                        // Assign the role.
                        role_assign($companymanagerrole->id, $manager->userid, $systemcontext->id);
                    }
                }
            }
        }

        // Purge caches.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011910, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.11: Fix company search timing, assign companymanager to course/category-level managers.
    // NOTE: This was a MISTAKE - assigning companymanager at system level breaks IOMAD.
    // v1.7.12 will undo this.
    if ($oldversion < 2025011911) {
        // Skip - the role assignment was removed in v1.7.12.
        // Just purge caches for any users who ran this version.
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011911, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.12: CRITICAL FIX - Remove system-level role assignments that broke IOMAD.
    // Previous versions incorrectly assigned companymanager, editingteacher, and student
    // roles at system level, which breaks IOMAD's company context handling.
    // This upgrade removes those incorrect assignments.
    if ($oldversion < 2025011912) {
        $systemcontext = context_system::instance();

        // 1. Remove editingteacher at system level for users who have teacher-like roles
        // at course/category level (these were assigned by our plugin).
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        if ($teacherrole) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :roleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid IN (
                          SELECT DISTINCT ra2.userid
                          FROM {role_assignments} ra2
                          JOIN {role} r2 ON r2.id = ra2.roleid
                          JOIN {context} ctx2 ON ctx2.id = ra2.contextid
                          WHERE ctx2.contextlevel IN (:courselevel, :categorylevel)
                            AND (
                                LOWER(r2.shortname) LIKE '%teacher%'
                                OR LOWER(r2.shortname) LIKE '%professor%'
                                OR LOWER(r2.shortname) LIKE '%tutor%'
                                OR LOWER(r2.shortname) LIKE '%profesor%'
                                OR LOWER(r2.shortname) LIKE '%maestro%'
                                OR LOWER(r2.shortname) LIKE '%docente%'
                                OR LOWER(r2.shortname) LIKE '%formador%'
                            )
                      )";

            $users = $DB->get_records_sql($sql, [
                'roleid' => $teacherrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
                'courselevel' => CONTEXT_COURSE,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);

            foreach ($users as $user) {
                role_unassign($teacherrole->id, $user->userid, $systemcontext->id);
            }
            $teachercount = count($users);
        } else {
            $teachercount = 0;
        }

        // 2. Remove student at system level for users who have student-like roles
        // at course/category level (these were assigned by our plugin).
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        if ($studentrole) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :roleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid IN (
                          SELECT DISTINCT ra2.userid
                          FROM {role_assignments} ra2
                          JOIN {role} r2 ON r2.id = ra2.roleid
                          JOIN {context} ctx2 ON ctx2.id = ra2.contextid
                          WHERE ctx2.contextlevel IN (:courselevel, :categorylevel)
                            AND (
                                LOWER(r2.shortname) LIKE '%student%'
                                OR LOWER(r2.shortname) LIKE '%alumno%'
                                OR LOWER(r2.shortname) LIKE '%estudiante%'
                                OR LOWER(r2.shortname) LIKE '%aluno%'
                                OR LOWER(r2.shortname) LIKE '%aprendiz%'
                            )
                      )";

            $users = $DB->get_records_sql($sql, [
                'roleid' => $studentrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
                'courselevel' => CONTEXT_COURSE,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);

            foreach ($users as $user) {
                role_unassign($studentrole->id, $user->userid, $systemcontext->id);
            }
            $studentcount = count($users);
        } else {
            $studentcount = 0;
        }

        // 3. Remove companymanager at system level for users who are NOT actual IOMAD managers.
        // Actual IOMAD managers have managertype > 0 in company_users table.
        // Users who were wrongly assigned by our plugin don't have this.
        $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
        $managercount = 0;
        if ($companymanagerrole && $dbman->table_exists('company_users')) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :cmroleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid NOT IN (
                          SELECT DISTINCT cu.userid
                          FROM {company_users} cu
                          WHERE cu.managertype > 0
                      )";

            $users = $DB->get_records_sql($sql, [
                'cmroleid' => $companymanagerrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
            ]);

            foreach ($users as $user) {
                role_unassign($companymanagerrole->id, $user->userid, $systemcontext->id);
            }
            $managercount = count($users);
        }

        // Log what we did.
        if ($teachercount > 0 || $studentcount > 0 || $managercount > 0) {
            error_log("SM_ESTRATOOS_PLUGIN v1.7.12: Removed system-level role assignments - " .
                      "editingteacher: $teachercount, student: $studentcount, companymanager: $managercount");
        }

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011912, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.13: Re-run system-level role cleanup for older installations.
    // Also fixes company search bar (AMD module loading) and teacher role detection.
    if ($oldversion < 2025011913) {
        $systemcontext = context_system::instance();
        $cleanupcount = 0;

        // 1. Remove editingteacher at system level for users who have teacher roles at course/category.
        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
        if ($teacherrole) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :roleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid IN (
                          SELECT DISTINCT ra2.userid
                          FROM {role_assignments} ra2
                          JOIN {role} r2 ON r2.id = ra2.roleid
                          JOIN {context} ctx2 ON ctx2.id = ra2.contextid
                          WHERE ctx2.contextlevel IN (:courselevel, :categorylevel)
                            AND (
                                LOWER(r2.shortname) LIKE '%teacher%'
                                OR LOWER(r2.shortname) LIKE '%professor%'
                                OR LOWER(r2.shortname) LIKE '%tutor%'
                                OR LOWER(r2.shortname) LIKE '%profesor%'
                                OR LOWER(r2.shortname) LIKE '%maestro%'
                                OR LOWER(r2.shortname) LIKE '%docente%'
                                OR LOWER(r2.shortname) LIKE '%formador%'
                            )
                      )";

            $users = $DB->get_records_sql($sql, [
                'roleid' => $teacherrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
                'courselevel' => CONTEXT_COURSE,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);

            foreach ($users as $user) {
                role_unassign($teacherrole->id, $user->userid, $systemcontext->id);
                $cleanupcount++;
            }
        }

        // 2. Remove student at system level for users who have student roles at course/category.
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        if ($studentrole) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :roleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid IN (
                          SELECT DISTINCT ra2.userid
                          FROM {role_assignments} ra2
                          JOIN {role} r2 ON r2.id = ra2.roleid
                          JOIN {context} ctx2 ON ctx2.id = ra2.contextid
                          WHERE ctx2.contextlevel IN (:courselevel, :categorylevel)
                            AND (
                                LOWER(r2.shortname) LIKE '%student%'
                                OR LOWER(r2.shortname) LIKE '%alumno%'
                                OR LOWER(r2.shortname) LIKE '%estudiante%'
                                OR LOWER(r2.shortname) LIKE '%aluno%'
                                OR LOWER(r2.shortname) LIKE '%aprendiz%'
                            )
                      )";

            $users = $DB->get_records_sql($sql, [
                'roleid' => $studentrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
                'courselevel' => CONTEXT_COURSE,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);

            foreach ($users as $user) {
                role_unassign($studentrole->id, $user->userid, $systemcontext->id);
                $cleanupcount++;
            }
        }

        // 3. Remove companymanager at system level for users who are NOT actual IOMAD managers.
        $companymanagerrole = $DB->get_record('role', ['shortname' => 'companymanager']);
        if ($companymanagerrole && $dbman->table_exists('company_users')) {
            $sql = "SELECT DISTINCT ra.userid
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = :cmroleid
                      AND ctx.contextlevel = :systemlevel
                      AND ra.userid NOT IN (
                          SELECT DISTINCT cu.userid
                          FROM {company_users} cu
                          WHERE cu.managertype > 0
                      )";

            $users = $DB->get_records_sql($sql, [
                'cmroleid' => $companymanagerrole->id,
                'systemlevel' => CONTEXT_SYSTEM,
            ]);

            foreach ($users as $user) {
                role_unassign($companymanagerrole->id, $user->userid, $systemcontext->id);
                $cleanupcount++;
            }
        }

        if ($cleanupcount > 0) {
            error_log("SM_ESTRATOOS_PLUGIN v1.7.13: Cleaned up $cleanupcount system-level role assignments");
        }

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011913, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.14: UI improvements for company access page (no DB changes).
    if ($oldversion < 2025011914) {
        upgrade_plugin_savepoint(true, 2025011914, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.15: Aggressive system-level role cleanup.
    // Previous upgrade steps v1.7.1 and v1.7.3 called a non-existent function that caused
    // upgrades to fail for some users. This step removes ALL system-level teacher/student roles
    // to ensure clean state, regardless of how the user ended up with them.
    if ($oldversion < 2025011915) {
        $systemcontext = context_system::instance();
        $cleanupcount = 0;

        // Get role IDs.
        $editingteacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $companymanagerroleid = $DB->get_field('role', 'id', ['shortname' => 'companymanager']);

        // 1. Remove ALL editingteacher at system level.
        // System-level teacher roles should NEVER exist - teachers are assigned at course level.
        if ($editingteacherroleid) {
            $assignments = $DB->get_records('role_assignments', [
                'roleid' => $editingteacherroleid,
                'contextid' => $systemcontext->id
            ]);
            foreach ($assignments as $assignment) {
                role_unassign($editingteacherroleid, $assignment->userid, $systemcontext->id);
                $cleanupcount++;
            }
        }

        // 2. Remove ALL student at system level.
        // System-level student roles should NEVER exist - students are assigned at course level.
        if ($studentroleid) {
            $assignments = $DB->get_records('role_assignments', [
                'roleid' => $studentroleid,
                'contextid' => $systemcontext->id
            ]);
            foreach ($assignments as $assignment) {
                role_unassign($studentroleid, $assignment->userid, $systemcontext->id);
                $cleanupcount++;
            }
        }

        // 3. Remove companymanager at system level for non-IOMAD managers.
        // Only actual IOMAD company managers (managertype > 0) should have this role.
        if ($companymanagerroleid && $DB->get_manager()->table_exists('company_users')) {
            $assignments = $DB->get_records('role_assignments', [
                'roleid' => $companymanagerroleid,
                'contextid' => $systemcontext->id
            ]);
            foreach ($assignments as $assignment) {
                // Check if user is actually an IOMAD company manager.
                $isrealmanager = $DB->record_exists_select(
                    'company_users',
                    'userid = ? AND managertype > 0',
                    [$assignment->userid]
                );
                if (!$isrealmanager) {
                    role_unassign($companymanagerroleid, $assignment->userid, $systemcontext->id);
                    $cleanupcount++;
                }
            }
        }

        if ($cleanupcount > 0) {
            purge_all_caches();
            error_log("SM_ESTRATOOS_PLUGIN v1.7.15: Cleaned up $cleanupcount system-level role assignments");
        }

        upgrade_plugin_savepoint(true, 2025011915, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.16: Rebuild company access page from scratch (UI only, no DB changes).
    if ($oldversion < 2025011916) {
        // Purge caches to ensure new AMD module is loaded.
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011916, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.17: Switch to inline JavaScript for company search (fix AMD caching issues).
    // AMD modules were being cached in production mode, preventing updates from taking effect.
    // Inline JS runs fresh on every page load, bypassing cache issues entirely.
    if ($oldversion < 2025011917) {
        // Purge caches to clear any remaining AMD module cache.
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011917, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.18: Debug version with console logging for company search.
    if ($oldversion < 2025011918) {
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011918, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.19: More debug - log data-name values and match results.
    if ($oldversion < 2025011919) {
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011919, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.20: FIX - Use d-none class instead of style.display (Bootstrap d-flex override).
    if ($oldversion < 2025011920) {
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011920, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.21: Cleanup - removed console logs, added comprehensive documentation.
    if ($oldversion < 2025011921) {
        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011921, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.22: Add send_instant_messages function for company-scoped messaging.
    // This function mirrors core_message_send_instant_messages but validates
    // recipients against the token's company scope instead of requiring
    // system-level moodle/site:sendmessage capability.
    if ($oldversion < 2025011922) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add the new messaging function.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011922, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.23: Add presence/session tracking functions for SmartLearning.
    // These functions allow SmartLearning to register users as "online" in Moodle
    // by creating/updating/deleting records in mdl_sessions.
    // - start_session: Creates a session record (user goes "online")
    // - session_heartbeat: Updates session timemodified (keeps user "online")
    // - end_session: Deletes session record (user goes "offline")
    if ($oldversion < 2025011923) {
        require_once(__DIR__ . '/install.php');

        // Re-run the full service rebuild to add the new session tracking functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        upgrade_plugin_savepoint(true, 2025011923, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.24: Fix capability issue for get_users_by_field and get_users functions.
    // These functions now work without requiring moodle/user:viewdetails capability.
    // Security is enforced by company filtering (IOMAD tokens only see company users).
    if ($oldversion < 2025011924) {
        // No database changes needed - just code changes.
        // Purge caches to ensure the updated services.php is loaded.
        purge_all_caches();

        upgrade_plugin_savepoint(true, 2025011924, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.25: Default service selection, role badges, auto-enable for managers, manager tokens API.
    // - Default service: Pre-select "SmartMind Estratoos Plugin" when creating tokens
    // - Role badges: Show user roles (Manager/Teacher/Student/Other) in tokens list
    // - Auto-enable: When creating tokens for managers, automatically enable the company
    // - New API: get_company_manager_tokens_status returns if a company has manager tokens
    // Also enables companies that already have existing manager tokens (migration).
    if ($oldversion < 2025011925) {
        require_once(__DIR__ . '/install.php');

        // Add the new API function to the service.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        // Migration: Enable all companies that already have manager tokens.
        // This ensures backward compatibility - if managers have tokens, company should be enabled.
        if ($dbman->table_exists('company_users') && $dbman->table_exists('company')) {
            // Find companies with existing manager tokens.
            $sql = "SELECT DISTINCT c.id as companyid
                    FROM {company} c
                    JOIN {company_users} cu ON cu.companyid = c.id
                    JOIN {local_sm_estratoos_plugin} smp ON smp.companyid = c.id
                    JOIN {external_tokens} et ON et.id = smp.tokenid
                    WHERE cu.userid = et.userid AND cu.managertype > 0";

            $companiestonable = $DB->get_records_sql($sql);

            foreach ($companiestonable as $company) {
                $accessrecord = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $company->companyid]);

                if ($accessrecord) {
                    // Update existing record to enabled.
                    if (!$accessrecord->enabled) {
                        $accessrecord->enabled = 1;
                        $accessrecord->timemodified = time();
                        $DB->update_record('local_sm_estratoos_plugin_access', $accessrecord);
                    }
                } else {
                    // Create new access record with enabled = 1.
                    $DB->insert_record('local_sm_estratoos_plugin_access', [
                        'companyid' => $company->companyid,
                        'enabled' => 1,
                        'enabledby' => get_admin()->id,
                        'timecreated' => time(),
                        'timemodified' => time(),
                    ]);
                }
            }

            if (!empty($companiestonable)) {
                error_log("SM_ESTRATOOS_PLUGIN v1.7.25: Enabled " . count($companiestonable) .
                          " companies with existing manager tokens");
            }
        }

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011925, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.26: Non-IOMAD support and category-scoped tokens security improvement.
    // - get_company_manager_tokens_status now works for non-IOMAD Moodles
    // - Non-admin users in non-IOMAD mode now get category context instead of system context
    // Migration: Update existing non-IOMAD tokens to use category context (except super admins).
    if ($oldversion < 2025011926) {
        // Get the top-level category for the new context.
        $topcategory = $DB->get_record('course_categories', ['parent' => 0], 'id', IGNORE_MULTIPLE);

        if ($topcategory) {
            // Get the category context.
            $catcontext = context_coursecat::instance($topcategory->id);
            $syscontext = context_system::instance();

            // Find all non-IOMAD tokens (companyid = 0 or NULL) with system context.
            // Skip super admin tokens - they should keep system context.
            $sql = "SELECT et.id as tokenid, et.userid
                    FROM {external_tokens} et
                    JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id
                    WHERE (smp.companyid = 0 OR smp.companyid IS NULL)
                      AND et.contextid = :syscontextid";

            $tokens = $DB->get_records_sql($sql, ['syscontextid' => $syscontext->id]);

            $migratedcount = 0;
            foreach ($tokens as $token) {
                // Skip super admins - they keep system context.
                if (is_siteadmin($token->userid)) {
                    continue;
                }

                // Update token to use category context.
                $DB->set_field('external_tokens', 'contextid', $catcontext->id, ['id' => $token->tokenid]);
                $migratedcount++;
            }

            if ($migratedcount > 0) {
                error_log("SM_ESTRATOOS_PLUGIN v1.7.26: Migrated $migratedcount non-IOMAD tokens " .
                          "from system context to category context (security improvement)");
            }
        }

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011926, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.27: Fix role detection for course-level roles, add role filter (code-only change).
    if ($oldversion < 2025011927) {
        upgrade_plugin_savepoint(true, 2025011927, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.28: Fix auto-enable company for managers token reactivation bug (code-only change).
    if ($oldversion < 2025011928) {
        upgrade_plugin_savepoint(true, 2025011928, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.29: Add company access expiration dates and get_token_details/get_companies_access_status API functions.
    if ($oldversion < 2025011929) {
        // 1. Add expirydate field to company access table.
        $table = new xmldb_table('local_sm_estratoos_plugin_access');
        $field = new xmldb_field('expirydate', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'enabled');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Add new API functions to service.
        require_once(__DIR__ . '/install.php');
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011929, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.30: Fix new API functions not being added on upgrade, improved expiry date UI.
    // The v1.7.29 upgrade step called xmldb_local_sm_estratoos_plugin_add_to_mobile_service()
    // but the new functions weren't in the $pluginfunctions array. This fixes that.
    if ($oldversion < 2025011930) {
        require_once(__DIR__ . '/install.php');

        // Re-run to add the get_token_details and get_companies_access_status functions.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011930, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.37: Add plugin_version field and update_company_plugin_version API function.
    // This allows each company to track their own plugin version independently,
    // enabling independent updates per company from external systems.
    if ($oldversion < 2025011937) {
        // Add plugin_version field to company access table.
        $table = new xmldb_table('local_sm_estratoos_plugin_access');
        $field = new xmldb_field('plugin_version', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'expirydate');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add new API function to service.
        require_once(__DIR__ . '/install.php');
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011937, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.38: Fix - Add update_company_plugin_version to service functions list.
    // The v1.7.37 upgrade step ran but the function wasn't in $pluginfunctions array.
    if ($oldversion < 2025011938) {
        require_once(__DIR__ . '/install.php');
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011938, 'local', 'sm_estratoos_plugin');
    }

    // v1.7.45: Add get_plugin_status function (system-wide plugin status check).
    // This function replaces the per-company version tracking with a simpler system-wide check.
    // Also adds auto-redirect to dashboard after install/upgrade and "Plugin Version:" label.
    if ($oldversion < 2025011945) {
        require_once(__DIR__ . '/install.php');

        // Add the new get_plugin_status function to the service.
        xmldb_local_sm_estratoos_plugin_add_to_mobile_service();

        purge_all_caches();
        upgrade_plugin_savepoint(true, 2025011945, 'local', 'sm_estratoos_plugin');
    }

    // Set flag to redirect to plugin dashboard after upgrade completes.
    set_config('redirect_to_dashboard', time(), 'local_sm_estratoos_plugin');

    return true;
}
