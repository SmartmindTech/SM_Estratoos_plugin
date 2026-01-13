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

        upgrade_plugin_savepoint(true, 2025011224, 'local', 'sm_estratoos_plugin');
    }

    return true;
}
