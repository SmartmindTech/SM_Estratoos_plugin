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
 * Batch token creation page.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/sm_estratoos_plugin/classes/form/batch_token_form.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/batch.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('batchtokens', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('batchtokens', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('batchtokens', 'local_sm_estratoos_plugin'));

// Load AMD module for user selection.
$PAGE->requires->js_call_amd('local_sm_estratoos_plugin/userselection', 'init');

$returnurl = new moodle_url('/local/sm_estratoos_plugin/index.php');

// Create form.
$mform = new \local_sm_estratoos_plugin\form\batch_token_form();

if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    // Validate company access for non-site-admins.
    if (!is_siteadmin() && !empty($data->companyid)) {
        if (!\local_sm_estratoos_plugin\util::can_manage_company($data->companyid)) {
            throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }
    }

    // Process form submission.
    try {
        // Get user IDs based on selection method.
        $userids = [];
        $source = 'company';

        if ($data->selectionmethod === 'company' || $data->selectionmethod === 'users') {
            // Get users from selected user IDs (both IOMAD company mode and standard user selection).
            if (!empty($data->selecteduserids)) {
                $userids = array_map('intval', explode(',', $data->selecteduserids));
                $userids = array_filter($userids, function($id) {
                    return $id > 0;
                });
            }

            if (empty($userids)) {
                throw new moodle_exception('nousersselected', 'local_sm_estratoos_plugin');
            }

            $source = $data->selectionmethod === 'company' ? 'company' : 'users';
        } else if ($data->selectionmethod === 'csv') {
            // Get users from uploaded file (CSV or Excel).
            $filename = $mform->get_new_filename('csvfile');
            $filecontent = $mform->get_file_content('csvfile');

            if (empty($filecontent)) {
                throw new moodle_exception('emptycsv', 'local_sm_estratoos_plugin');
            }

            // Check if it's an Excel file.
            $isexcel = preg_match('/\.(xlsx|xls)$/i', $filename);

            if ($isexcel) {
                // Process Excel file.
                $fileresult = \local_sm_estratoos_plugin\company_token_manager::get_users_from_excel(
                    $filecontent,
                    $data->csvfield,
                    $data->companyid
                );
            } else {
                // Process CSV file.
                $fileresult = \local_sm_estratoos_plugin\company_token_manager::get_users_from_csv(
                    $filecontent,
                    $data->csvfield,
                    $data->companyid
                );
            }

            if (empty($fileresult['users'])) {
                if (!empty($fileresult['errors'])) {
                    $errormsg = get_string('fileprocessingerrors', 'local_sm_estratoos_plugin') . ":\n";
                    foreach ($fileresult['errors'] as $error) {
                        $errormsg .= get_string('line', 'local_sm_estratoos_plugin') . " {$error['line']}: {$error['value']} - {$error['error']}\n";
                    }
                    throw new moodle_exception('csverror', 'local_sm_estratoos_plugin', '', $errormsg);
                }
                throw new moodle_exception('nousersfound', 'local_sm_estratoos_plugin');
            }

            $userids = $fileresult['users'];
            $source = $isexcel ? 'excel' : 'csv';
        } else {
            // Unknown selection method.
            throw new moodle_exception('nousersselected', 'local_sm_estratoos_plugin');
        }

        if (empty($userids)) {
            throw new moodle_exception('nousersfound', 'local_sm_estratoos_plugin');
        }

        // Prepare options.
        $options = [
            'source' => $source,
            'restricttocompany' => !empty($data->restricttocompany) ? 1 : 0,
            'restricttoenrolment' => !empty($data->restricttoenrolment) ? 1 : 0,
        ];

        if (!empty($data->iprestriction)) {
            $options['iprestriction'] = $data->iprestriction;
        }

        // Always set validuntil explicitly.
        // When checkbox is unchecked, $data->validuntil is 0 (never expires).
        // When checkbox is checked, $data->validuntil is the timestamp.
        $options['validuntil'] = !empty($data->validuntil) ? $data->validuntil : 0;

        if (!empty($data->notes)) {
            $options['notes'] = $data->notes;
        }

        // Create batch tokens.
        $results = \local_sm_estratoos_plugin\company_token_manager::create_batch_tokens(
            $userids,
            $data->companyid,
            $data->serviceid,
            $options
        );

        // Show results page.
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('batchcomplete', 'local_sm_estratoos_plugin'));

        // Summary.
        $summaryclass = $results->failcount > 0 ? 'alert-warning' : 'alert-success';
        echo html_writer::start_div('alert ' . $summaryclass);
        echo html_writer::tag('strong', get_string('tokenscreated', 'local_sm_estratoos_plugin', $results->successcount));
        if ($results->failcount > 0) {
            echo html_writer::tag('br', '');
            echo html_writer::tag('span', get_string('tokensfailed', 'local_sm_estratoos_plugin', $results->failcount));
        }
        echo html_writer::tag('br', '');
        echo html_writer::tag('small', get_string('batchid', 'local_sm_estratoos_plugin') . ': ' . $results->batchid,
            ['class' => 'text-muted']);
        echo html_writer::end_div();

        // Errors (if any).
        if (!empty($results->errors)) {
            echo html_writer::start_div('card mb-4');
            echo html_writer::start_div('card-header bg-warning');
            echo html_writer::tag('h5', get_string('errors', 'local_sm_estratoos_plugin'), ['class' => 'mb-0']);
            echo html_writer::end_div();
            echo html_writer::start_div('card-body');
            echo html_writer::start_tag('ul');
            foreach ($results->errors as $error) {
                echo html_writer::tag('li',
                    html_writer::tag('strong', $error['fullname']) . ' (' . $error['email'] . '): ' . $error['error']
                );
            }
            echo html_writer::end_tag('ul');
            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Created tokens.
        if (!empty($results->tokens)) {
            echo html_writer::start_div('card');
            echo html_writer::start_div('card-header');
            echo html_writer::tag('h5', get_string('createdtokens', 'local_sm_estratoos_plugin'), ['class' => 'mb-0']);
            echo html_writer::end_div();
            echo html_writer::start_div('card-body');

            // Warning.
            echo $OUTPUT->notification(
                get_string('tokensshownonce', 'local_sm_estratoos_plugin'),
                'info'
            );

            // Tokens table.
            $table = new html_table();
            $table->head = [
                get_string('user'),
                get_string('email'),
                get_string('token', 'local_sm_estratoos_plugin')
            ];
            $table->attributes['class'] = 'table table-striped table-sm';

            foreach ($results->tokens as $tokenobj) {
                global $DB;
                $user = $DB->get_record('user', ['id' => $tokenobj->userid]);
                $table->data[] = [
                    fullname($user),
                    $user->email,
                    html_writer::tag('code', $tokenobj->token, ['class' => 'user-select-all']),
                ];
            }

            echo html_writer::table($table);

            // Export button.
            $exporturl = new moodle_url('/local/sm_estratoos_plugin/export.php', [
                'batchid' => $results->batchid,
                'includetoken' => 1,
            ]);
            echo html_writer::link($exporturl, get_string('exportcsv', 'local_sm_estratoos_plugin'), [
                'class' => 'btn btn-primary',
            ]);

            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        // Action buttons.
        echo html_writer::start_div('mt-4');
        echo html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-secondary mr-2']);
        echo html_writer::link(new moodle_url('/local/sm_estratoos_plugin/batch.php'),
            get_string('createnewbatch', 'local_sm_estratoos_plugin'), ['class' => 'btn btn-primary']);
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

echo $OUTPUT->heading(get_string('batchtokens', 'local_sm_estratoos_plugin'));

// Show appropriate description based on IOMAD status.
$isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
if ($isiomad) {
    echo html_writer::tag('p', get_string('batchtokensdesc', 'local_sm_estratoos_plugin'), ['class' => 'lead']);
} else {
    echo html_writer::tag('p', get_string('batchtokensdesc_standard', 'local_sm_estratoos_plugin'), ['class' => 'lead']);
}

$mform->display();

echo $OUTPUT->footer();
