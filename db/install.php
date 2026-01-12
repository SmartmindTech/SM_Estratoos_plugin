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
    // Set default configuration values.
    set_config('default_validity_days', 365, 'local_sm_estratoos_plugin');
    set_config('default_restricttocompany', 1, 'local_sm_estratoos_plugin');
    set_config('default_restricttoenrolment', 1, 'local_sm_estratoos_plugin');
    set_config('allow_individual_overrides', 1, 'local_sm_estratoos_plugin');
    set_config('cleanup_expired_tokens', 1, 'local_sm_estratoos_plugin');

    return true;
}
