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
 * Manage web service functions - allows adding/removing functions from any service.
 *
 * This page bypasses the core Moodle restriction that prevents modifying
 * built-in services like moodle_mobile_app.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

$serviceid  = required_param('id', PARAM_INT);
$functionid = optional_param('fid', 0, PARAM_INT);
$action     = optional_param('action', '', PARAM_ALPHANUMEXT);
$confirm    = optional_param('confirm', 0, PARAM_BOOL);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/service_functions.php', ['id' => $serviceid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');

// Get service.
$service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);

$PAGE->set_title(get_string('servicefunctions', 'local_sm_estratoos_plugin') . ': ' . $service->name);
$PAGE->set_heading(get_string('servicefunctions', 'local_sm_estratoos_plugin'));

// Navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('manageservices', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/services.php'));
$PAGE->navbar->add($service->name);

$webservicemanager = new webservice();
$functionlisturl = new moodle_url('/local/sm_estratoos_plugin/service_functions.php', ['id' => $serviceid]);
$returnurl = new moodle_url('/local/sm_estratoos_plugin/services.php');

// Handle actions.
switch ($action) {
    case 'add':
        // Add function to service.
        if (confirm_sesskey()) {
            require_once(__DIR__ . '/classes/form/service_functions_form.php');

            $mform = new \local_sm_estratoos_plugin\form\service_functions_form(null, [
                'action' => 'add',
                'id' => $service->id
            ]);

            if ($mform->is_cancelled()) {
                redirect($functionlisturl);
            }

            if ($data = $mform->get_data()) {
                ignore_user_abort(true);

                foreach ($data->fids as $fid) {
                    $function = $webservicemanager->get_external_function_by_id($fid, MUST_EXIST);

                    // Prevent duplicates.
                    if (!$webservicemanager->service_function_exists($function->name, $service->id)) {
                        $webservicemanager->add_external_function_to_service($function->name, $service->id);
                    }
                }

                redirect($functionlisturl, get_string('functionsadded', 'local_sm_estratoos_plugin'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }

            // Display add form.
            echo $OUTPUT->header();
            echo $OUTPUT->heading(get_string('addfunctions', 'webservice') . ': ' . $service->name);
            $mform->display();
            echo $OUTPUT->footer();
            exit;
        }
        break;

    case 'delete':
        // Remove function from service.
        if (confirm_sesskey() && $functionid > 0) {
            $function = $webservicemanager->get_external_function_by_id($functionid, MUST_EXIST);

            if (!$confirm) {
                // Confirmation page.
                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('removefunction', 'webservice'));

                $confirmurl = new moodle_url('/local/sm_estratoos_plugin/service_functions.php', [
                    'id' => $serviceid,
                    'fid' => $functionid,
                    'action' => 'delete',
                    'confirm' => 1,
                    'sesskey' => sesskey()
                ]);

                echo $OUTPUT->confirm(
                    get_string('removefunctionconfirm', 'local_sm_estratoos_plugin', [
                        'function' => $function->name,
                        'service' => $service->name
                    ]),
                    $confirmurl,
                    $functionlisturl
                );
                echo $OUTPUT->footer();
                exit;
            }

            // Remove the function.
            $webservicemanager->remove_external_function_from_service($function->name, $service->id);

            redirect($functionlisturl, get_string('functionremoved', 'local_sm_estratoos_plugin'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        break;
}

// Display function list.
echo $OUTPUT->header();
echo $OUTPUT->heading($service->name);

// Service info.
echo html_writer::start_div('alert alert-info');
echo html_writer::tag('strong', get_string('shortname') . ': ') . $service->shortname;
if ($service->component) {
    echo html_writer::tag('br', '');
    echo html_writer::tag('strong', get_string('component', 'webservice') . ': ') . $service->component;
}
echo html_writer::end_div();

// Get functions for this service.
$functions = $webservicemanager->get_external_functions([$service->id]);

if (empty($functions)) {
    echo $OUTPUT->notification(get_string('nofunctions', 'webservice'), 'info');
} else {
    // Functions table.
    $table = new html_table();
    $table->head = [
        get_string('function', 'webservice'),
        get_string('description'),
        get_string('requiredcaps', 'webservice'),
        get_string('actions')
    ];
    $table->attributes['class'] = 'table table-striped';

    foreach ($functions as $function) {
        $functioninfo = external_api::external_function_info($function);

        $description = html_writer::tag('div', $functioninfo->description, ['class' => 'small']);

        $capabilities = '';
        if (!empty($functioninfo->capabilities)) {
            $capabilities = html_writer::tag('code', $functioninfo->capabilities, ['class' => 'small']);
        }

        // Remove action.
        $removeurl = new moodle_url('/local/sm_estratoos_plugin/service_functions.php', [
            'id' => $serviceid,
            'fid' => $function->id,
            'action' => 'delete',
            'sesskey' => sesskey()
        ]);
        $removeicon = html_writer::tag('i', '', ['class' => 'fa fa-trash', 'aria-hidden' => 'true']);
        $actions = html_writer::link($removeurl, $removeicon, [
            'class' => 'btn btn-sm btn-outline-danger',
            'title' => get_string('removefunction', 'webservice')
        ]);

        // Deprecated warning.
        $functionname = $function->name;
        if (!empty($functioninfo->deprecated)) {
            $functionname .= ' ' . html_writer::tag('span', get_string('deprecated', 'core'),
                ['class' => 'badge badge-warning']);
        }

        $table->data[] = [
            html_writer::tag('code', $functionname),
            $description,
            $capabilities,
            $actions
        ];
    }

    echo html_writer::table($table);
    echo html_writer::tag('p', count($functions) . ' ' . get_string('functions', 'webservice'),
        ['class' => 'text-muted']);
}

// Action buttons.
echo html_writer::start_div('mt-4');

// Add functions button.
$addurl = new moodle_url('/local/sm_estratoos_plugin/service_functions.php', [
    'id' => $serviceid,
    'action' => 'add',
    'sesskey' => sesskey()
]);
echo html_writer::link($addurl, get_string('addfunctions', 'webservice'), [
    'class' => 'btn btn-primary mr-2'
]);

// Back button.
echo html_writer::link($returnurl, get_string('back'), [
    'class' => 'btn btn-secondary'
]);

echo html_writer::end_div();

echo $OUTPUT->footer();
