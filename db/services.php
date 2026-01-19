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

    // Get messages from a conversation (category context version).
    'local_sm_estratoos_plugin_get_conversation_messages' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_conversation_messages',
        'methodname' => 'execute',
        'description' => 'Get messages from a conversation. Works with category-scoped tokens (IOMAD) and system tokens. ' .
                        'Supports pagination for bulk message retrieval (up to 1000 messages per request). ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Send instant messages with company-scoped validation (v1.7.22).
    'local_sm_estratoos_plugin_send_instant_messages' => [
        'classname' => 'local_sm_estratoos_plugin\external\send_instant_messages',
        'methodname' => 'execute',
        'description' => 'Send instant messages to users within the same company scope. ' .
                        'Works with category-scoped tokens (IOMAD) and system tokens. ' .
                        'Recipients must be in the same company as the sender for IOMAD tokens. ' .
                        '[SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // =========================================================================
    // COURSE CONTENT FUNCTIONS
    // =========================================================================

    // Get comprehensive course content including SCORM, files, pages, and all educational materials.
    'local_sm_estratoos_plugin_get_course_content' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_content',
        'methodname' => 'execute',
        'description' => 'Retrieve comprehensive course content including SCORM packages, files, pages, URLs, ' .
                        'assignments, quizzes, forums, books, lessons, and all educational materials. ' .
                        'Supports company filtering for IOMAD tokens. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:view',
        'loginrequired' => true,
    ],

    // =========================================================================
    // COMPLETION AND TRACKING FUNCTIONS
    // =========================================================================

    // Mark a course module as viewed and trigger completion.
    'local_sm_estratoos_plugin_mark_module_viewed' => [
        'classname' => 'local_sm_estratoos_plugin\external\mark_module_viewed',
        'methodname' => 'execute',
        'description' => 'Mark a course module as viewed and trigger completion tracking. ' .
                        'Use this to track progress when playing content externally (SCORM, lessons, etc.). ' .
                        'Replaces the non-existent core_completion_mark_course_module_viewed. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/course:view',
        'loginrequired' => true,
    ],

    // Force grade recalculation for any gradable activity.
    'local_sm_estratoos_plugin_update_activity_grade' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_activity_grade',
        'methodname' => 'execute',
        'description' => 'Force grade recalculation for any gradable activity (SCORM, Quiz, Assignment, Lesson, etc.). ' .
                        'Call this after saving tracking data via mod_scorm_insert_scorm_tracks or similar functions ' .
                        'to ensure the grade is updated in Moodle gradebook. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'moodle/grade:view',
        'loginrequired' => true,
    ],

    // Health check for SmartLearning platform - lightweight connectivity verification.
    'local_sm_estratoos_plugin_health_check' => [
        'classname' => 'local_sm_estratoos_plugin\external\health_check',
        'methodname' => 'execute',
        'description' => 'Lightweight health check for SmartLearning platform. Returns minimal response for fast ' .
                        'connectivity verification. Designed for high-frequency polling (every 10-30 seconds). ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // =========================================================================
    // BULK DATA OPTIMIZATION FUNCTIONS
    // These functions eliminate N+1 query patterns and provide single-call access
    // to commonly fetched data combinations.
    // =========================================================================

    // Bulk user fetch with embedded roles and pagination.
    'local_sm_estratoos_plugin_get_all_users_bulk' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_all_users_bulk',
        'methodname' => 'execute',
        'description' => 'Bulk fetch users with roles and pagination. Eliminates N+1 queries when loading user lists. ' .
                        'Supports IOMAD company filtering and non-IOMAD installations. Performance: 5000 users < 2s. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/user:viewdetails',
        'loginrequired' => true,
    ],

    // Single-call dashboard summary.
    'local_sm_estratoos_plugin_get_dashboard_summary' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_dashboard_summary',
        'methodname' => 'execute',
        'description' => 'Single-call dashboard data fetch. Replaces 5-10 separate API calls with one optimized request. ' .
                        'Returns courses with progress, assignments due, quizzes, events, grades, and unread messages. ' .
                        'Performance: < 500ms. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Bulk courses with completion and grades.
    'local_sm_estratoos_plugin_get_courses_with_progress_bulk' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_courses_with_progress_bulk',
        'methodname' => 'execute',
        'description' => 'Bulk fetch enrolled courses with completion progress and grades. Supports pagination and ' .
                        'optional teacher data. Performance: 500 courses < 3s. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Delta sync - changes since timestamp.
    'local_sm_estratoos_plugin_get_changes_since' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_changes_since',
        'methodname' => 'execute',
        'description' => 'Delta sync - check for changes since last sync timestamp. Returns counts and optionally ' .
                        'changed records for courses, grades, assignments, messages, events, and completions. ' .
                        'Reduces data transfer by 70-90%. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Extended health check with summary counts.
    'local_sm_estratoos_plugin_health_check_extended' => [
        'classname' => 'local_sm_estratoos_plugin\external\health_check_extended',
        'methodname' => 'execute',
        'description' => 'Extended health check with optional summary counts. Returns status, latency calculation, ' .
                        'and site/user statistics for dashboard pre-warming. Performance: < 200ms with caching. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // =========================================================================
    // PHASE 2: LOGIN & DASHBOARD OPTIMIZATION FUNCTIONS
    // These functions reduce login time from ~6s to <500ms and dashboard loading
    // from ~20s to <2s by eliminating N+1 query patterns.
    // =========================================================================

    // Get all essential data needed for login in a single call.
    'local_sm_estratoos_plugin_get_login_essentials' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_login_essentials',
        'methodname' => 'execute',
        'description' => 'Get all data needed for login in a single call: user info, site info, roles, and courses. ' .
                        'Replaces 6+ separate API calls. Performance: < 500ms. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Get complete dashboard data in a single call.
    'local_sm_estratoos_plugin_get_dashboard_complete' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_dashboard_complete',
        'methodname' => 'execute',
        'description' => 'Get complete dashboard data in a single call: courses with assignments/quizzes, events, ' .
                        'deadlines, grades summary, messages, and recent activity. Replaces 10+ API calls per Moodle. ' .
                        'Performance: < 2s. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Get completion status for all users in a course at once.
    'local_sm_estratoos_plugin_get_course_completion_bulk' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_completion_bulk',
        'methodname' => 'execute',
        'description' => 'Get completion status for all users in a course in one query. Eliminates N+1 pattern where ' .
                        'completion is fetched per-user. Performance: < 100ms for 500 users. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:viewparticipants',
        'loginrequired' => true,
    ],

    // Get statistics for multiple courses at once.
    'local_sm_estratoos_plugin_get_course_stats_bulk' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_stats_bulk',
        'methodname' => 'execute',
        'description' => 'Get statistics for multiple courses: enrollment, completion, grades, assignments, quizzes, ' .
                        'and recent activity. Useful for teacher dashboards. Performance: < 1s for 100 courses. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:viewparticipants',
        'loginrequired' => true,
    ],

    // Get dashboard stats in a single call (course count, deadlines, to-grade).
    'local_sm_estratoos_plugin_get_dashboard_stats' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_dashboard_stats',
        'methodname' => 'execute',
        'description' => 'Get dashboard statistics (course count, deadlines, to-grade count) in a single call. ' .
                        'Reduces dashboard loading from ~3.7s to <300ms. Supports per-course breakdown. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
];

// NOTE: The SmartMind - Estratoos Plugin service is created and managed in install.php,
// NOT here in services.php. This allows us to include ALL mobile functions plus our
// plugin functions in the service. Defining the service here would cause Moodle to
// overwrite the service with only the functions listed, removing the mobile functions.
