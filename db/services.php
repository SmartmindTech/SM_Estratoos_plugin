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
 * External services and functions definitions.
 *
 * These functions are available to be added to any external service in Moodle.
 * They can also be used via AJAX calls from the admin interface.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // =========================================================================
    // TOKEN MANAGEMENT FUNCTIONS
    // =========================================================================

    // Create batch tokens for multiple users.
    'local_sm_estratoos_plugin_create_batch' => [
        'classname' => 'local_sm_estratoos_plugin\external\create_batch_tokens',
        'methodname' => 'execute',
        'description' => 'Create company-scoped tokens for multiple users in batch. ' .
                        'Returns the generated token strings and batch ID.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:createtokensapi',
        'loginrequired' => true,
    ],

    // Get company tokens list.
    'local_sm_estratoos_plugin_get_tokens' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_company_tokens',
        'methodname' => 'execute',
        'description' => 'Get list of company-scoped tokens. Can filter by company, service, or batch ID.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:viewreports',
        'loginrequired' => true,
    ],

    // Revoke one or more tokens.
    'local_sm_estratoos_plugin_revoke' => [
        'classname' => 'local_sm_estratoos_plugin\external\revoke_company_tokens',
        'methodname' => 'execute',
        'description' => 'Revoke one or more company-scoped tokens by their IDs.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:managetokens',
        'loginrequired' => true,
    ],

    // Get users for a company.
    'local_sm_estratoos_plugin_get_company_users' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_company_users',
        'methodname' => 'execute',
        'description' => 'Get list of users belonging to a company. Useful for selecting users before batch token creation.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:managecompanytokens',
        'loginrequired' => true,
    ],

    // Get list of companies.
    'local_sm_estratoos_plugin_get_companies' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_companies',
        'methodname' => 'execute',
        'description' => 'Get list of all SmartMind companies. Useful for building company selection dropdowns.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:viewreports',
        'loginrequired' => true,
    ],

    // Get available external services.
    'local_sm_estratoos_plugin_get_services' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_services',
        'methodname' => 'execute',
        'description' => 'Get list of enabled external services. Useful for building service selection dropdowns.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:viewreports',
        'loginrequired' => true,
    ],

    // Create admin (system-wide) token.
    'local_sm_estratoos_plugin_create_admin_token' => [
        'classname' => 'local_sm_estratoos_plugin\external\create_admin_token',
        'methodname' => 'execute',
        'description' => 'Create a system-wide token for the site administrator with full access.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:managetokens',
        'loginrequired' => true,
    ],

    // Get batch history.
    'local_sm_estratoos_plugin_get_batch_history' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_batch_history',
        'methodname' => 'execute',
        'description' => 'Get history of batch token creation operations.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:viewreports',
        'loginrequired' => true,
    ],

    // =========================================================================
    // FORUM FUNCTIONS
    // =========================================================================

    // Create a new forum in a course.
    'local_sm_estratoos_plugin_forum_create' => [
        'classname' => 'local_sm_estratoos_plugin\external\forum_functions',
        'methodname' => 'create_forum',
        'description' => 'Create a new forum in a given course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/forum:addinstance',
        'loginrequired' => true,
    ],

    // Edit forum settings.
    'local_sm_estratoos_plugin_forum_edit' => [
        'classname' => 'local_sm_estratoos_plugin\external\forum_functions',
        'methodname' => 'edit_forum',
        'description' => 'Edit forum settings (name, introduction, type).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
        'loginrequired' => true,
    ],

    // Delete a forum.
    'local_sm_estratoos_plugin_forum_delete' => [
        'classname' => 'local_sm_estratoos_plugin\external\forum_functions',
        'methodname' => 'delete_forum',
        'description' => 'Delete a forum activity from the course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:manageactivities',
        'loginrequired' => true,
    ],

    // Edit a discussion.
    'local_sm_estratoos_plugin_discussion_edit' => [
        'classname' => 'local_sm_estratoos_plugin\external\forum_functions',
        'methodname' => 'edit_discussion',
        'description' => 'Edit an existing forum discussion (subject and/or message).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/forum:editanypost',
        'loginrequired' => true,
    ],

    // Delete a discussion.
    'local_sm_estratoos_plugin_discussion_delete' => [
        'classname' => 'local_sm_estratoos_plugin\external\forum_functions',
        'methodname' => 'delete_discussion',
        'description' => 'Delete a forum discussion and all its posts.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/forum:deleteanypost',
        'loginrequired' => true,
    ],

    // =========================================================================
    // CATEGORY-CONTEXT USER FUNCTIONS
    // These functions work with category-scoped tokens (company tokens).
    // They mirror core Moodle functions but validate against category context
    // instead of system context.
    // =========================================================================

    // Get users by field (category context version).
    'local_sm_estratoos_plugin_get_users_by_field' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_users_by_field',
        'methodname' => 'execute',
        'description' => 'Get company users by field (id, username, email, idnumber). ' .
                        'Works with category-scoped tokens. Returns only users in the token\'s company.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/user:viewdetails',
        'loginrequired' => true,
    ],

    // Search users (category context version).
    'local_sm_estratoos_plugin_get_users' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_users',
        'methodname' => 'execute',
        'description' => 'Search company users by criteria (firstname, lastname, email, username, idnumber). ' .
                        'Works with category-scoped tokens. Returns only users in the token\'s company. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/user:viewdetails',
        'loginrequired' => true,
    ],

    // Get categories (category context version).
    'local_sm_estratoos_plugin_get_categories' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_categories',
        'methodname' => 'execute',
        'description' => 'Get course categories for the company. Works with category-scoped tokens. ' .
                        'Returns only categories in the company\'s category tree. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/category:viewcourselist',
        'loginrequired' => true,
    ],

    // Get conversations (category context version).
    'local_sm_estratoos_plugin_get_conversations' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_conversations',
        'methodname' => 'execute',
        'description' => 'Get user conversations filtered to company users. Works with category-scoped tokens. ' .
                        'Returns only conversations involving company members. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
];

// Define a pre-built service that includes all our functions.
// Administrators can also add individual functions to other services.
$services = [
    'SmartMind - Estratoos Plugin' => [
        'functions' => [
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
            // Category-context functions (work with company tokens).
            'local_sm_estratoos_plugin_get_users_by_field',
            'local_sm_estratoos_plugin_get_users',
            'local_sm_estratoos_plugin_get_categories',
            'local_sm_estratoos_plugin_get_conversations',
        ],
        'restrictedusers' => 1,
        'enabled' => 1,
        'shortname' => 'sm_estratoos_plugin',
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ],
];
