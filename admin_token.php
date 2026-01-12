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
 * Create admin (system-wide) token page.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/sm_estratoos_plugin/classes/form/admin_token_form.php');

require_login();

// Only site administrators can access this page.
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/admin_token.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('admintoken', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('admintoken', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('admintoken', 'local_sm_estratoos_plugin'));

$returnurl = new moodle_url('/local/sm_estratoos_plugin/index.php');

// Create form.
$mform = new \local_sm_estratoos_plugin\form\admin_token_form();

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    // Process form submission.
    global $USER;

    try {
        // Prepare options.
        $options = [];

        if (!empty($data->iprestriction)) {
            $options['iprestriction'] = $data->iprestriction;
        }

        if (!empty($data->validuntil)) {
            $options['validuntil'] = $data->validuntil;
        }

        // Create the admin token.
        $token = \local_sm_estratoos_plugin\company_token_manager::create_admin_token(
            $USER->id,
            $data->serviceid,
            $options
        );

        // Show success page with token.
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('admintokencreated', 'local_sm_estratoos_plugin'));

        // Warning about token visibility.
        echo $OUTPUT->notification(
            get_string('tokensshownonce', 'local_sm_estratoos_plugin'),
            'info'
        );

        echo $OUTPUT->notification(
            get_string('admintokenwarning', 'local_sm_estratoos_plugin'),
            'warning'
        );

        // Display the token.
        echo html_writer::start_div('card mt-4');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('h5', 'Your Admin Token:', ['class' => 'card-title']);
        echo html_writer::tag('code', $token, [
            'class' => 'user-select-all d-block p-3 bg-light',
            'style' => 'font-size: 1.2rem; word-break: break-all;'
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Back button.
        echo html_writer::start_div('mt-4');
        echo html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-primary']);
        echo html_writer::end_div();

        echo $OUTPUT->footer();
        exit;

    } catch (Exception $e) {
        echo $OUTPUT->header();
        echo $OUTPUT->notification($e->getMessage(), 'error');
        $mform->display();
        echo $OUTPUT->footer();
        exit;
    }
}

// Display form.
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('admintoken', 'local_sm_estratoos_plugin'));
echo html_writer::tag('p', get_string('admintokendesc', 'local_sm_estratoos_plugin'), ['class' => 'lead']);

// Warning box.
echo $OUTPUT->notification(
    get_string('admintokenwarning', 'local_sm_estratoos_plugin'),
    'warning'
);

$mform->display();

echo $OUTPUT->footer();
