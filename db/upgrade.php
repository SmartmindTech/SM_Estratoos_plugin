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

    return true;
}
