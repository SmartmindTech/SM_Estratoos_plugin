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

    // Get manager tokens status for a company (v1.7.25).
    'local_sm_estratoos_plugin_get_company_manager_tokens_status' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_company_manager_tokens_status',
        'methodname' => 'execute',
        'description' => 'Check if a company has any manager tokens created. Returns boolean flag and details about ' .
                        'managers with tokens. Useful for company access management in IOMAD environments. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Get comprehensive token details (v1.7.29).
    'local_sm_estratoos_plugin_get_token_details' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_token_details',
        'methodname' => 'execute',
        'description' => 'Get comprehensive details about a token including user roles, restrictions, creation info, ' .
                        'service, company data, and more. Can lookup by plugin token ID or token hash. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Get company access status and expiration info (v1.7.29).
    'local_sm_estratoos_plugin_get_companies_access_status' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_companies_access_status',
        'methodname' => 'execute',
        'description' => 'Get company access status and expiration dates. Returns enabled status, expiry date, days remaining, ' .
                        'plugin version, and token counts for all or specific companies. Useful for monitoring company access. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Get plugin version status and check for updates (v1.7.45).
    'local_sm_estratoos_plugin_get_plugin_status' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_plugin_status',
        'methodname' => 'execute',
        'description' => 'Get plugin version status and check for updates. Returns current installed version, whether an update ' .
                        'is available, and the URL to perform the update. Use checkforupdates=1 to force fetch latest version info. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Check for plugin updates and get update information for external systems (v1.7.48).
    'local_sm_estratoos_plugin_update_plugin_version' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_plugin_version',
        'methodname' => 'execute',
        'description' => 'Check for plugin updates and get detailed update information. Returns current/latest versions, ' .
                        'download URL, release notes, and admin update URL. Use action="check" (default) or action="info". ' .
                        'Useful for external systems to monitor and trigger plugin updates. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
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
                        'Works with category-scoped tokens. Returns only users in the token\'s company. ' .
                        'No capability required - security enforced by company filtering. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Search users (category context version).
    'local_sm_estratoos_plugin_get_users' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_users',
        'methodname' => 'execute',
        'description' => 'Search company users by criteria (firstname, lastname, email, username, idnumber). ' .
                        'Works with category-scoped tokens. Returns only users in the token\'s company. ' .
                        'No capability required - security enforced by company filtering. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => '',
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
    // PRESENCE / SESSION TRACKING FUNCTIONS (v1.7.23)
    // These functions allow SmartLearning to register users as "online" in Moodle
    // by creating/updating/deleting records in mdl_sessions.
    // =========================================================================

    // Start a SmartLearning presence session.
    'local_sm_estratoos_plugin_start_session' => [
        'classname' => 'local_sm_estratoos_plugin\external\start_session',
        'methodname' => 'execute',
        'description' => 'Start a SmartLearning presence session. Creates a record in mdl_sessions to make the user ' .
                        'appear "online" in Moodle reports and user online blocks. Returns session ID (sid) to use ' .
                        'with heartbeat and end_session. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Send heartbeat to keep session alive.
    'local_sm_estratoos_plugin_session_heartbeat' => [
        'classname' => 'local_sm_estratoos_plugin\external\session_heartbeat',
        'methodname' => 'execute',
        'description' => 'Send heartbeat to keep a SmartLearning presence session alive. Updates the session\'s ' .
                        'timemodified field in mdl_sessions. Should be called every 5 minutes. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // End a SmartLearning presence session.
    'local_sm_estratoos_plugin_end_session' => [
        'classname' => 'local_sm_estratoos_plugin\external\end_session',
        'methodname' => 'execute',
        'description' => 'End a SmartLearning presence session. Deletes the session record from mdl_sessions, making ' .
                        'the user appear "offline" in Moodle. Returns total session duration. [SM Estratoos API Function]',
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

    // Get activity progress metadata (lightweight, fast).
    'local_sm_estratoos_plugin_get_activity_progress' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_activity_progress',
        'methodname' => 'execute',
        'description' => 'Lightweight activity progress retrieval. Returns only progress metadata (slide count, ' .
                        'current position, score, attempts) without fetching full content. Supports SCORM, Quiz, ' .
                        'Book, Lesson, Assignment, Page, Resource, URL. Use modtype parameter to filter by activity type. ' .
                        'Performance: < 100ms for 50 activities. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:view',
        'loginrequired' => true,
    ],

    // Get students enrolled in a course.
    'local_sm_estratoos_plugin_get_course_students' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_students',
        'methodname' => 'execute',
        'description' => 'Retrieve students enrolled in a course. Returns user details, optional profile fields, ' .
                        'and group memberships. Supports IOMAD company filtering. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:viewparticipants',
        'loginrequired' => true,
    ],

    // Get teachers enrolled in a course.
    'local_sm_estratoos_plugin_get_course_teachers' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_teachers',
        'methodname' => 'execute',
        'description' => 'Retrieve teachers enrolled in a course. Returns user details with their teaching role, ' .
                        'optional profile fields, and group memberships. Supports IOMAD company filtering. ' .
                        '[SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:viewparticipants',
        'loginrequired' => true,
    ],

    // Get managers for a course.
    'local_sm_estratoos_plugin_get_course_managers' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_course_managers',
        'methodname' => 'execute',
        'description' => 'Retrieve managers for a course including course-level, category-level, and IOMAD company ' .
                        'managers. Returns user details with role and scope information. Supports IOMAD company ' .
                        'filtering. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/course:viewparticipants',
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

    // =========================================================================
    // USER SELF-SERVICE FUNCTIONS (v2.1.20)
    // =========================================================================

    // Update the authenticated user's own username.
    'local_sm_estratoos_plugin_update_username' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_username',
        'methodname' => 'execute',
        'description' => 'Update the authenticated user\'s own username. Self-service only — no admin capability required. ' .
                        '[SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Update the authenticated user's own password.
    'local_sm_estratoos_plugin_update_password' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_password',
        'methodname' => 'execute',
        'description' => 'Update the authenticated user\'s own password. Requires current password for verification. ' .
                        'Self-service only — no admin capability required. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Update the authenticated user's own profile fields.
    'local_sm_estratoos_plugin_update_user' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_user',
        'methodname' => 'execute',
        'description' => 'Update the authenticated user\'s own profile fields (name, email, phone, address, custom fields, ' .
                        'preferences, etc.). Self-service only — no admin capability required. ' .
                        'Mirrors core_user_update_users but restricted to the token owner. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],

    // Update a calendar event (title, date/time, course, description, location).
    'local_sm_estratoos_plugin_update_calendar_event' => [
        'classname' => 'local_sm_estratoos_plugin\external\update_calendar_event',
        'methodname' => 'execute',
        'description' => 'Update a calendar event (title, date/time, course, description, location). ' .
                        'Event type is always Course, duration is always 0. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => '',
        'loginrequired' => true,
    ],
    // =========================================================================
    // USER CREATION & WATCHER FUNCTIONS (v2.1.30)
    // =========================================================================

    // Create a single Moodle user with auto-generated username and token.
    'local_sm_estratoos_plugin_create_user' => [
        'classname' => 'local_sm_estratoos_plugin\external\create_user',
        'methodname' => 'execute',
        'description' => 'Create a single Moodle user with auto-generated username and token. ' .
                        'Stores RSA-encrypted password for SmartLearning watcher. Supports IOMAD company assignment. ' .
                        'Returns error codes for validation failures. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:createusers',
        'loginrequired' => true,
    ],

    // Create multiple Moodle users in batch.
    'local_sm_estratoos_plugin_create_users_batch' => [
        'classname' => 'local_sm_estratoos_plugin\external\create_users_batch',
        'methodname' => 'execute',
        'description' => 'Create multiple Moodle users with auto token generation. Supports array of users or CSV data string. ' .
                        'Returns per-user success/fail results with error codes. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:createusers',
        'loginrequired' => true,
    ],

    // Delete one or more Moodle users.
    'local_sm_estratoos_plugin_delete_users' => [
        'classname' => 'local_sm_estratoos_plugin\external\delete_users',
        'methodname' => 'execute',
        'description' => 'Delete one or more Moodle users. Revokes tokens, removes plugin metadata, soft-deletes user. ' .
                        'Company-scoped for IOMAD. [SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:deleteusers',
        'loginrequired' => true,
    ],

    // Watcher API: Get newly created users for SmartLearning sync.
    'local_sm_estratoos_plugin_get_new_users' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_new_users',
        'methodname' => 'execute',
        'description' => 'Get newly created users for SmartLearning sync. Returns encrypted passwords, tokens, and user data. ' .
                        'Supports timestamp filtering and notification tracking. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:createusers',
        'loginrequired' => true,
    ],

    // v2.1.31: Token watcher API for SmartLearning sync.
    'local_sm_estratoos_plugin_get_new_tokens' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_new_tokens',
        'methodname' => 'execute',
        'description' => 'Get newly created tokens for SmartLearning sync. Returns tokens with user profile data (name, email, ' .
                        'city, country, timezone, phone) so SmartLearning can create user accounts without extra API calls. ' .
                        'Supports timestamp filtering and notification tracking. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:createusers',
        'loginrequired' => true,
    ],

    // v2.1.30: Encryption key retrieval for SmartLearning.
    'local_sm_estratoos_plugin_get_encryption_key' => [
        'classname' => 'local_sm_estratoos_plugin\external\get_encryption_key',
        'methodname' => 'execute',
        'description' => 'Retrieve the RSA private key for password decryption. SmartLearning uses this to decrypt ' .
                        'passwords encrypted by the plugin. Admin-only. [SM Estratoos API Function]',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'moodle/site:config',
        'loginrequired' => true,
    ],

    // =========================================================================
    // ACCESS CONTROL FUNCTIONS (v2.1.35)
    // Called by SmartLearning when a superadmin enables/disables a Moodle instance.
    // =========================================================================

    // Toggle company access (IOMAD only).
    'local_sm_estratoos_plugin_toggle_company_access' => [
        'classname' => 'local_sm_estratoos_plugin\external\toggle_company_access',
        'methodname' => 'execute',
        'description' => 'Toggle plugin access for an IOMAD company. Called by SmartLearning when a superadmin ' .
                        'enables or disables a Moodle instance. Enables/disables company access and suspends/reactivates tokens. ' .
                        '[SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:manageaccess',
        'loginrequired' => true,
    ],

    // Toggle plugin access globally (Standard Moodle).
    'local_sm_estratoos_plugin_toggle_access' => [
        'classname' => 'local_sm_estratoos_plugin\external\toggle_access',
        'methodname' => 'execute',
        'description' => 'Toggle plugin access globally for standard (non-IOMAD) Moodle instances. Called by SmartLearning ' .
                        'when a superadmin enables or disables a Moodle instance. Toggles the plugin activated state. ' .
                        '[SM Estratoos API Function]',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/sm_estratoos_plugin:manageaccess',
        'loginrequired' => true,
    ],
];

// NOTE: The SmartMind - Estratoos Plugin service is created and managed in install.php,
// NOT here in services.php. This allows us to include ALL mobile functions plus our
// plugin functions in the service. Defining the service here would cause Moodle to
// overwrite the service with only the functions listed, removing the mobile functions.
