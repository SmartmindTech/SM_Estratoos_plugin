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
 * Capability definitions for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Manage all tokens (site admin capability).
    'local/sm_estratoos_plugin:managetokens' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
    ],

    // Manage tokens for a specific company (company manager capability).
    'local/sm_estratoos_plugin:managecompanytokens' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
    ],

    // Create tokens in batch.
    'local/sm_estratoos_plugin:createbatch' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
    ],

    // View token reports.
    'local/sm_estratoos_plugin:viewreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    // Export tokens.
    'local/sm_estratoos_plugin:export' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_PERSONAL,
    ],

    // Use the API to create tokens programmatically.
    'local/sm_estratoos_plugin:createtokensapi' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
    ],

    // Create users via API or dashboard.
    'local/sm_estratoos_plugin:createusers' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_SPAM,
    ],

    // Delete users via API.
    'local/sm_estratoos_plugin:deleteusers' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS,
    ],

    // Manage plugin access (enable/disable companies, toggle global access).
    // Used by the SmartLearning service user for API callbacks.
    'local/sm_estratoos_plugin:manageaccess' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG,
    ],
];
