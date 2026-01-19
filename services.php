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
 * Web services management page - lists all services for function management.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/services.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('manageservices', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('manageservices', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('manageservices', 'local_sm_estratoos_plugin'));

$webservicemanager = new webservice();

echo $OUTPUT->header();

// Description.
echo html_writer::tag('p', get_string('manageservicesdesc', 'local_sm_estratoos_plugin'),
    ['class' => 'lead']);

// Get all external services.
$services = $DB->get_records('external_services', [], 'name ASC');

if (empty($services)) {
    echo $OUTPUT->notification(get_string('noservices', 'local_sm_estratoos_plugin'), 'info');
} else {
    // Services table.
    $table = new html_table();
    $table->head = [
        get_string('name'),
        get_string('shortname'),
        get_string('enabled', 'webservice'),
        get_string('functions', 'webservice'),
        get_string('actions')
    ];
    $table->attributes['class'] = 'table table-striped';

    foreach ($services as $service) {
        // Count functions.
        $functions = $webservicemanager->get_external_functions([$service->id]);
        $functioncount = count($functions);

        // Enabled badge.
        if ($service->enabled) {
            $enabledbadge = html_writer::tag('span', get_string('yes'),
                ['class' => 'badge badge-success']);
        } else {
            $enabledbadge = html_writer::tag('span', get_string('no'),
                ['class' => 'badge badge-secondary']);
        }

        // Built-in service indicator.
        $servicename = $service->name;
        if ($service->component) {
            $servicename .= ' ' . html_writer::tag('span', get_string('builtin', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-info']);
        }

        // Actions - manage functions.
        $manageurl = new moodle_url('/local/sm_estratoos_plugin/service_functions.php', [
            'id' => $service->id
        ]);
        $manageicon = html_writer::tag('i', '', ['class' => 'fa fa-cog', 'aria-hidden' => 'true']);
        $actions = html_writer::link($manageurl, $manageicon . ' ' . get_string('managefunctions', 'local_sm_estratoos_plugin'), [
            'class' => 'btn btn-sm btn-outline-primary'
        ]);

        $table->data[] = [
            $servicename,
            html_writer::tag('code', $service->shortname),
            $enabledbadge,
            $functioncount,
            $actions
        ];
    }

    echo html_writer::table($table);
}

// Back button.
echo html_writer::start_div('mt-4');
echo html_writer::link(
    new moodle_url('/local/sm_estratoos_plugin/index.php'),
    get_string('back'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
