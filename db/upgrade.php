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

    // Future upgrades will be added here.
    // Example:
    // if ($oldversion < 2025011201) {
    //     // Add new field or table.
    //     upgrade_plugin_savepoint(true, 2025011201, 'local', 'sm_estratoos_plugin');
    // }

    return true;
}
