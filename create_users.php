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
 * User creation page.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/local/sm_estratoos_plugin/classes/form/create_users_form.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

// Check plugin activation (v2.1.32). Superadmins skip this in IOMAD.
\local_sm_estratoos_plugin\util::check_activation_gate();

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/create_users.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('createusers', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('createusers', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('createusers', 'local_sm_estratoos_plugin'));

$returnurl = new moodle_url('/local/sm_estratoos_plugin/index.php');

// Create form.
$mform = new \local_sm_estratoos_plugin\form\create_users_form();

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    // Validate company access for non-site-admins.
    if (!is_siteadmin() && !empty($data->companyid)) {
        if (!\local_sm_estratoos_plugin\util::can_manage_company($data->companyid)) {
            throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }
    }

    try {
        $results = [];

        if ($data->creationmethod === 'single') {
            // Single user creation.
            $userdata = [
                'firstname' => $data->firstname,
                'lastname' => $data->lastname,
                'email' => $data->email,
                'username' => $data->username ?? '',
                'password' => $data->password ?? '',
                'generate_password' => !empty($data->generate_password),
                'phone_intl_code' => $data->phone_intl_code ?? '',
                'phone' => $data->phone ?? '',
                'city' => $data->city ?? '',
                'state_province' => $data->state_province ?? '',
                'country' => $data->country ?? '',
                'timezone' => $data->timezone ?? '99',
                'document_type' => $data->document_type ?? '',
                'document_id' => $data->document_id ?? '',
                'companyid' => (int)$data->companyid,
                'serviceid' => (int)$data->serviceid,
            ];

            // Handle birthdate from date_selector (returns timestamp or 0).
            if (!empty($data->birthdate)) {
                $userdata['birthdate'] = date('Y-m-d', $data->birthdate);
            } else {
                $userdata['birthdate'] = '';
            }

            $result = \local_sm_estratoos_plugin\user_manager::create_user($userdata, 'dashboard');
            $results = [$result];

            // Enrol in course if specified and user was created successfully.
            if ($result->success && !empty($data->courseid) && (int)$data->courseid > 0) {
                $enrolplugin = enrol_get_plugin('manual');
                if ($enrolplugin) {
                    $enrolinstance = $DB->get_record('enrol', [
                        'courseid' => (int)$data->courseid,
                        'enrol' => 'manual',
                        'status' => ENROL_INSTANCE_ENABLED,
                    ]);
                    if (!$enrolinstance) {
                        $course = $DB->get_record('course', ['id' => (int)$data->courseid]);
                        if ($course) {
                            $instanceid = $enrolplugin->add_default_instance($course);
                            if ($instanceid) {
                                $enrolinstance = $DB->get_record('enrol', ['id' => $instanceid]);
                            }
                        }
                    }
                    if ($enrolinstance) {
                        $enrolplugin->enrol_user($enrolinstance, $result->userid, 5);
                    }
                }
            }

        } else if ($data->creationmethod === 'csv') {
            // CSV/Excel batch creation.
            $filename = $mform->get_new_filename('csvfile');
            $filecontent = $mform->get_file_content('csvfile');

            if (empty($filecontent)) {
                throw new moodle_exception('emptycsv', 'local_sm_estratoos_plugin');
            }

            // Check if it's an Excel file.
            $isexcel = preg_match('/\.(xlsx|xls)$/i', $filename);

            if ($isexcel) {
                // Parse Excel file to CSV-like data.
                require_once($CFG->libdir . '/excellib.class.php');

                // Save temp file and read with PHPSpreadsheet.
                $tempfile = tempnam(sys_get_temp_dir(), 'sm_users_');
                file_put_contents($tempfile, $filecontent);

                try {
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($tempfile);
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($tempfile);
                    $worksheet = $spreadsheet->getActiveSheet();

                    // Convert to CSV string.
                    $csvlines = [];
                    foreach ($worksheet->getRowIterator() as $row) {
                        $celldata = [];
                        $celliter = $row->getCellIterator();
                        $celliter->setIterateOnlyExistingCells(false);
                        foreach ($celliter as $cell) {
                            $celldata[] = $cell->getValue() ?? '';
                        }
                        $csvlines[] = implode(',', $celldata);
                    }
                    $filecontent = implode("\n", $csvlines);
                } finally {
                    @unlink($tempfile);
                }
            }

            // Parse CSV content.
            $csvresult = \local_sm_estratoos_plugin\user_manager::parse_csv_users($filecontent);

            if (empty($csvresult['users'])) {
                $errormsg = get_string('nousersfound', 'local_sm_estratoos_plugin');
                if (!empty($csvresult['errors'])) {
                    $errormsg .= ":\n";
                    foreach ($csvresult['errors'] as $error) {
                        $errormsg .= get_string('line', 'local_sm_estratoos_plugin') .
                            " {$error->line}: {$error->error}\n";
                    }
                }
                throw new moodle_exception('csverror', 'local_sm_estratoos_plugin', '', $errormsg);
            }

            // Apply global generate_password and company/service to all users.
            $generatepw = !empty($data->csv_generate_password);
            foreach ($csvresult['users'] as &$csvuser) {
                if (empty($csvuser['password']) && $generatepw) {
                    $csvuser['generate_password'] = true;
                }
            }
            unset($csvuser);

            $batchresult = \local_sm_estratoos_plugin\user_manager::create_users_batch(
                $csvresult['users'],
                (int)$data->companyid,
                (int)$data->serviceid,
                'dashboard'
            );

            $results = $batchresult->results;
        }

        // Display results.
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('usercreationresults', 'local_sm_estratoos_plugin'));

        // Count successes and failures.
        $successcount = 0;
        $failcount = 0;
        foreach ($results as $r) {
            if ($r->success) {
                $successcount++;
            } else {
                $failcount++;
            }
        }

        // Summary alert.
        $summaryclass = $failcount > 0 ? 'alert-warning' : 'alert-success';
        echo html_writer::start_div('alert ' . $summaryclass);
        echo html_writer::tag('strong', get_string('userscreated', 'local_sm_estratoos_plugin', $successcount));
        if ($failcount > 0) {
            echo html_writer::tag('br', '');
            echo html_writer::tag('span', get_string('usersfailed', 'local_sm_estratoos_plugin', $failcount),
                ['class' => 'text-danger']);
        }
        echo html_writer::end_div();

        // Show failures.
        $failures = array_filter($results, function($r) { return !$r->success; });
        if (!empty($failures)) {
            echo html_writer::start_div('card mb-4');
            echo html_writer::start_div('card-header bg-warning');
            echo html_writer::tag('h5', get_string('errors', 'local_sm_estratoos_plugin'), ['class' => 'mb-0']);
            echo html_writer::end_div();
            echo html_writer::start_div('card-body');
            echo html_writer::start_tag('ul');
            foreach ($failures as $f) {
                $label = $f->email ?: ($f->username ?: '?');
                echo html_writer::tag('li',
                    html_writer::tag('strong', $label) . ': ' .
                    html_writer::tag('code', $f->error_code) . ' - ' . $f->message
                );
            }
            echo html_writer::end_tag('ul');
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Show successes.
        $successes = array_filter($results, function($r) { return $r->success; });
        if (!empty($successes)) {
            echo html_writer::start_div('card');
            echo html_writer::start_div('card-header');
            echo html_writer::tag('h5', get_string('usercreated', 'local_sm_estratoos_plugin'), ['class' => 'mb-0']);
            echo html_writer::end_div();
            echo html_writer::start_div('card-body');

            // Password warning.
            echo $OUTPUT->notification(
                get_string('passwordshownonce', 'local_sm_estratoos_plugin'),
                'info'
            );

            // Results table.
            $table = new html_table();
            $table->head = [
                get_string('username'),
                get_string('email'),
                get_string('password'),
                get_string('token', 'local_sm_estratoos_plugin'),
            ];
            $table->attributes['class'] = 'table table-striped table-sm';

            foreach ($successes as $s) {
                $table->data[] = [
                    $s->username,
                    $s->email,
                    html_writer::tag('code', $s->password, ['class' => 'user-select-all']),
                    html_writer::tag('code', $s->token, ['class' => 'user-select-all']),
                ];
            }

            echo html_writer::table($table);
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Action buttons.
        echo html_writer::start_div('mt-4');
        echo html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-secondary mr-2']);
        echo html_writer::link(new moodle_url('/local/sm_estratoos_plugin/create_users.php'),
            get_string('createnewusers', 'local_sm_estratoos_plugin'), ['class' => 'btn btn-primary']);
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
echo html_writer::tag('p', get_string('createusersdesc', 'local_sm_estratoos_plugin'), ['class' => 'lead']);
$mform->display();

// Load AMD module for AJAX course loading.
$PAGE->requires->js_call_amd('local_sm_estratoos_plugin/courseloader', 'init');

echo $OUTPUT->footer();
