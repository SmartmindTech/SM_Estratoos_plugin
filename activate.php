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
 * Plugin activation page.
 *
 * Allows site administrators to enter an activation code to connect
 * this Moodle instance to SmartLearning.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// IOMAD: activation is per-company, not system-level. Redirect to company_access.php.
if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
    redirect(new moodle_url('/local/sm_estratoos_plugin/company_access.php'));
}

// Standard Moodle: site admin or manager can activate.
if (!is_siteadmin() && !\local_sm_estratoos_plugin\util::is_potential_token_admin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'activate plugin');
}

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/activate.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('activateplugin', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('activateplugin', 'local_sm_estratoos_plugin'));

// If already activated, redirect to settings.
if (\local_sm_estratoos_plugin\webhook::is_activated()) {
    redirect(
        new moodle_url('/admin/settings.php', ['section' => 'local_sm_estratoos_plugin']),
        get_string('activationsuccess', 'local_sm_estratoos_plugin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$error = '';
$success = false;

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $code = required_param('activation_code', PARAM_ALPHANUMEXT);

    if (empty($code)) {
        $error = get_string('activationcodeinvalid', 'local_sm_estratoos_plugin');
    } else {
        $result = \local_sm_estratoos_plugin\webhook::activate($code);

        if ($result->success) {
            redirect(
                new moodle_url('/admin/settings.php', ['section' => 'local_sm_estratoos_plugin']),
                get_string('activationsuccess', 'local_sm_estratoos_plugin'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            // Map error codes to language strings.
            switch ($result->error) {
                case 'code_expired':
                    $error = get_string('activationcodeexpired', 'local_sm_estratoos_plugin');
                    break;
                case 'code_already_used':
                    $error = get_string('activationcodeused', 'local_sm_estratoos_plugin');
                    break;
                case 'invalid_code':
                    $error = get_string('activationcodeinvalid', 'local_sm_estratoos_plugin');
                    break;
                default:
                    $error = get_string('activationfailed', 'local_sm_estratoos_plugin', $result->message);
                    break;
            }
        }
    }
}

echo $OUTPUT->header();

// Error notification.
if (!empty($error)) {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

// Activation form.
echo '<div class="card mx-auto" style="max-width: 500px;">';
echo '<div class="card-body">';
echo '<h4 class="card-title mb-3">' . get_string('activationrequired', 'local_sm_estratoos_plugin') . '</h4>';
echo '<p class="text-muted">' . get_string('activationcodeonly', 'local_sm_estratoos_plugin') . '</p>';
echo '<p class="text-muted small">' . get_string('activationcodehelp', 'local_sm_estratoos_plugin') . '</p>';

echo '<form method="post" action="">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<div class="form-group mb-3">';
echo '<label for="activation_code">' . get_string('activationcode', 'local_sm_estratoos_plugin') . '</label>';
echo '<input type="text" id="activation_code" name="activation_code" class="form-control"
       placeholder="ACT-XXXX-XXXX-XXXX" pattern="ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
       required autofocus>';
echo '</div>';
echo '<button type="submit" class="btn btn-primary btn-block">';
echo get_string('activateplugin', 'local_sm_estratoos_plugin');
echo '</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo $OUTPUT->footer();
