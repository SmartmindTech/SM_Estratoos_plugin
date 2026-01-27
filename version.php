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
 * Plugin version and other meta-data.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_sm_estratoos_plugin';
$plugin->version = 2025012712;  // YYYYMMDDXX format.
$plugin->requires = 2022112800; // Moodle 4.1+
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '2.0.12';  // Rise 360 navigation, fix notification URLs redirect issue.

// GitHub update server - allows automatic update notifications.
// Point to the raw update.xml file in the GitHub repository.
$plugin->updateserver = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';

// No dependencies - plugin works with both IOMAD and standard Moodle.
// IOMAD features are automatically detected and enabled when available.
