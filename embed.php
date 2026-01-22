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
 * Chromeless activity embed endpoint for SmartLearning iframe integration.
 *
 * This endpoint validates OAuth2/OIDC JWT tokens from SmartLearning and renders
 * Moodle activities without navigation/header/footer for seamless embedding.
 *
 * URL: /local/sm_estratoos_plugin/embed.php?cmid=123&token=<jwt>
 * OR with Authorization header: Authorization: Bearer <jwt>
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Disable Moodle's standard output buffering for this page.
define('NO_OUTPUT_BUFFERING', true);

// IMPORTANT: We do NOT use NO_MOODLE_COOKIES here because we need to create
// a real Moodle session for the inner iframes (SCORM player, quiz, etc.) to work.
// The JWT token is validated first, then we create a session for that user.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

use local_sm_estratoos_plugin\oauth2_validator;
use local_sm_estratoos_plugin\embed_renderer;

// =============================================================================
// Configuration - Should be moved to plugin settings in production.
// =============================================================================

// SmartLearning OAuth2 issuer URL.
$issuerUrl = get_config('local_sm_estratoos_plugin', 'oauth2_issuer_url');
if (empty($issuerUrl)) {
    // Default to common SmartLearning URLs.
    $issuerUrl = 'https://smartlearning.smartmind.net';
}

// Allowed embed origins for CSP header.
$allowedOrigins = get_config('local_sm_estratoos_plugin', 'oauth2_allowed_origins');
if (empty($allowedOrigins)) {
    $allowedOrigins = 'https://smartlearning.smartmind.net https://*.smartmind.net http://localhost:3000';
}

// =============================================================================
// Security Headers.
// =============================================================================

// Allow embedding from SmartLearning.
header("Content-Security-Policy: frame-ancestors {$allowedOrigins}");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// =============================================================================
// Token Validation.
// =============================================================================

// Get JWT token from Authorization header or query parameter.
$token = oauth2_validator::get_bearer_token();

if (empty($token)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing_token', 'message' => 'No authentication token provided']);
    exit;
}

// Validate token.
$validator = new oauth2_validator($issuerUrl);
$payload = $validator->validate_jwt($token);

if (!$payload) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_token', 'message' => 'Invalid or expired token']);
    exit;
}

// =============================================================================
// User Validation.
// =============================================================================

// Get Moodle user from token.
$user = $validator->get_user_from_token($token);

if (!$user) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'user_not_found',
        'message' => 'User not found in Moodle',
        'moodle_user_id' => $payload->moodle_user_id ?? null,
    ]);
    exit;
}

// =============================================================================
// Activity Validation.
// =============================================================================

// Get course module ID from request.
$cmid = required_param('cmid', PARAM_INT);

// Get course module.
try {
    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
} catch (Exception $e) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'activity_not_found', 'message' => 'Activity not found']);
    exit;
}

// Validate activity matches token (if specified in token).
if (isset($payload->activity_id) && $payload->activity_id != $cmid) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'activity_mismatch',
        'message' => 'Token is not valid for this activity',
        'expected' => $payload->activity_id,
        'provided' => $cmid,
    ]);
    exit;
}

// =============================================================================
// Session Setup.
// =============================================================================

// Always force a clean session to avoid conflicts with existing/expired sessions.
// This is necessary because:
// 1. User might have an expired session cookie
// 2. User might be logged in as a different user
// 3. The existing session might have invalid state

// First, destroy any existing session completely.
if (isloggedin()) {
    require_logout();
}

// Now create a fresh session for the JWT-authenticated user.
// complete_user_login() creates a proper Moodle session with cookies.
complete_user_login($user);

// Ensure $USER is set correctly after login.
$USER = $user;

// Force session write to ensure cookie is sent before redirect.
\core\session\manager::write_close();

// Set up course context.
$PAGE->set_course($course);
$PAGE->set_cm($cm);

// Check user enrollment.
$context = context_module::instance($cm->id);
if (!is_enrolled(context_course::instance($course->id), $user->id, '', true)) {
    // Check if user has course view capability (managers, admins).
    if (!has_capability('moodle/course:view', context_course::instance($course->id))) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'not_enrolled',
            'message' => 'User is not enrolled in this course',
            'course_id' => $course->id,
        ]);
        exit;
    }
}

// Check activity visibility.
if (!$cm->visible && !has_capability('moodle/course:viewhiddenactivities', $context)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'activity_hidden', 'message' => 'Activity is not visible']);
    exit;
}

// =============================================================================
// Render Activity.
// =============================================================================

// Get cm_info for better activity handling.
$modinfo = get_fast_modinfo($course);
$cminfo = $modinfo->get_cm($cm->id);

// Render the activity.
$renderer = new embed_renderer($cminfo);
$html = $renderer->render();

// Output HTML.
header('Content-Type: text/html; charset=utf-8');
echo $html;
