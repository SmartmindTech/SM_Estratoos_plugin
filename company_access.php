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
 * Company access management page for SmartMind Estratoos Plugin.
 *
 * Super administrators can control which IOMAD companies have access to the plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Only site administrators can access this page.
\local_sm_estratoos_plugin\util::require_site_admin();

// Check if IOMAD is installed.
if (!\local_sm_estratoos_plugin\util::is_iomad_installed()) {
    throw new moodle_exception('noiomad', 'local_sm_estratoos_plugin');
}

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/company_access.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('managecompanyaccess', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('managecompanyaccess', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('managecompanyaccess', 'local_sm_estratoos_plugin'));

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $enabledcompanies = optional_param_array('companies', [], PARAM_INT);
    \local_sm_estratoos_plugin\util::set_enabled_companies($enabledcompanies);

    redirect(
        $PAGE->url,
        get_string('companiesaccessupdated', 'local_sm_estratoos_plugin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Get all companies with their access status.
$companies = \local_sm_estratoos_plugin\util::get_companies_with_access_status();
$enabledcount = count(array_filter($companies, function($c) { return $c->enabled; }));
$totalcount = count($companies);

// Load AMD module for JavaScript functionality.
$PAGE->requires->js_call_amd('local_sm_estratoos_plugin/companyaccess', 'init');

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('managecompanyaccess', 'local_sm_estratoos_plugin'));
echo html_writer::tag('p', get_string('managecompanyaccessdesc', 'local_sm_estratoos_plugin'), ['class' => 'lead']);

// Back to dashboard link.
echo html_writer::start_div('mb-3');
echo html_writer::link(
    new moodle_url('/local/sm_estratoos_plugin/index.php'),
    $OUTPUT->pix_icon('i/return', '') . ' ' . get_string('backtodashboard', 'local_sm_estratoos_plugin'),
    ['class' => 'btn btn-outline-secondary']
);
echo html_writer::end_div();

// Start form.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out_omit_querystring(),
    'id' => 'company-access-form'
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Status badge (admonition) - at the top.
echo html_writer::start_div('alert alert-info mb-3');
echo html_writer::tag('span', '', ['id' => 'enabled-count', 'data-initial' => $enabledcount]);
echo html_writer::tag('strong', $enabledcount);
echo ' ' . get_string('companiesenabled', 'local_sm_estratoos_plugin');
echo ' / ' . $totalcount . ' ' . get_string('total');
echo html_writer::end_div();

// Row with buttons on left and search bar on right.
echo html_writer::start_div('d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2');

// Quick select buttons on the left.
echo html_writer::start_div('btn-group');
echo html_writer::tag('button', get_string('selectall'), [
    'type' => 'button',
    'class' => 'btn btn-sm btn-outline-secondary',
    'id' => 'select-all-companies'
]);
echo html_writer::tag('button', get_string('deselectall', 'local_sm_estratoos_plugin'), [
    'type' => 'button',
    'class' => 'btn btn-sm btn-outline-secondary',
    'id' => 'deselect-all-companies'
]);
echo html_writer::end_div();

// Search bar on the right.
echo html_writer::start_div('', ['style' => 'min-width: 250px;']);
echo html_writer::tag('label', get_string('searchcompanies', 'local_sm_estratoos_plugin'), [
    'for' => 'company-search',
    'class' => 'sr-only'
]);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'company-search',
    'class' => 'form-control',
    'placeholder' => get_string('searchcompanies', 'local_sm_estratoos_plugin')
]);
echo html_writer::end_div();

echo html_writer::end_div(); // d-flex row

// Company list with scrollbar.
echo html_writer::start_div('company-list border rounded', ['style' => 'max-height: 400px; overflow-y: auto;']);

if (empty($companies)) {
    echo html_writer::tag('p', get_string('nocompanies', 'local_sm_estratoos_plugin'), ['class' => 'text-muted']);
} else {
    foreach ($companies as $company) {
        $checked = $company->enabled ? 'checked' : '';
        $id = 'company-' . $company->id;

        echo html_writer::start_div('company-item custom-control custom-checkbox py-3 px-4 border-bottom', [
            'data-name' => strtolower($company->name . ' ' . $company->shortname)
        ]);

        echo html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'custom-control-input company-checkbox',
            'id' => $id,
            'name' => 'companies[]',
            'value' => $company->id,
            'checked' => $company->enabled ? 'checked' : null
        ]);

        echo html_writer::start_tag('label', ['class' => 'custom-control-label', 'for' => $id]);
        echo html_writer::tag('strong', $company->name);
        echo html_writer::tag('small', ' (' . $company->shortname . ')', ['class' => 'text-muted ml-2']);
        echo html_writer::end_tag('label');

        echo html_writer::end_div();
    }
}

echo html_writer::end_div(); // company-list

// Submit button.
echo html_writer::start_div('mt-4');
echo html_writer::tag('button', get_string('savechanges'), [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-lg'
]);
echo ' ';
echo html_writer::link(
    new moodle_url('/local/sm_estratoos_plugin/index.php'),
    get_string('cancel'),
    ['class' => 'btn btn-secondary btn-lg']
);
echo html_writer::end_div();

echo html_writer::end_tag('form');

echo $OUTPUT->footer();
