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
 * Token management page.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

// Get parameters.
$companyid = optional_param('companyid', 0, PARAM_INT);
$serviceid = optional_param('serviceid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);
$tokenids = optional_param_array('tokenids', [], PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/manage.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('managetokens', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('managetokens', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('managetokens', 'local_sm_estratoos_plugin'));

$returnurl = new moodle_url('/local/sm_estratoos_plugin/manage.php', [
    'companyid' => $companyid,
    'serviceid' => $serviceid,
]);

// Handle actions.
if ($action === 'revoke' && $tokenid > 0) {
    if ($confirm) {
        require_sesskey();
        if (\local_sm_estratoos_plugin\company_token_manager::revoke_token($tokenid)) {
            redirect($returnurl, get_string('tokenrevoked', 'local_sm_estratoos_plugin'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($returnurl, get_string('tokennotfound', 'local_sm_estratoos_plugin'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        // Show confirmation.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmrevoke', 'local_sm_estratoos_plugin'),
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'revoke',
                'tokenid' => $tokenid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $returnurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

if ($action === 'bulkrevoke' && !empty($tokenids)) {
    if ($confirm) {
        require_sesskey();
        $count = \local_sm_estratoos_plugin\company_token_manager::revoke_tokens($tokenids);
        redirect($returnurl, get_string('tokensrevoked', 'local_sm_estratoos_plugin', $count), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // Show confirmation.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmrevokeselected', 'local_sm_estratoos_plugin'),
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'bulkrevoke',
                'tokenids' => $tokenids,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $returnurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

// Display page.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managetokens', 'local_sm_estratoos_plugin'));

// Filters.
global $DB;

// Get companies based on user access - site admins see all, company managers see only their companies.
$issiteadmin = is_siteadmin();
if ($issiteadmin) {
    $companies = ['' => get_string('all')] + $DB->get_records_menu('company', [], 'name', 'id, name');
} else {
    // Company manager - only show managed companies.
    $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
    $companies = [];
    foreach ($managedcompanies as $company) {
        $companies[$company->id] = $company->name;
    }
    if (count($companies) > 1) {
        $companies = ['' => get_string('all')] + $companies;
    }
}
$services = ['' => get_string('all')] + $DB->get_records_menu('external_services', ['enabled' => 1], 'name', 'id, name');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group mr-2');
echo html_writer::label(get_string('company', 'local_sm_estratoos_plugin') . ': ', 'filtercompany', true, ['class' => 'mr-2']);
echo html_writer::select($companies, 'companyid', $companyid, false, ['id' => 'filtercompany', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('form-group mr-2');
echo html_writer::label(get_string('service', 'local_sm_estratoos_plugin') . ': ', 'filterservice', true, ['class' => 'mr-2']);
echo html_writer::select($services, 'serviceid', $serviceid, false, ['id' => 'filterservice', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter', 'local_sm_estratoos_plugin'),
    'class' => 'btn btn-secondary',
]);

echo html_writer::end_tag('form');

// Get tokens.
$filters = [];
if ($companyid > 0) {
    // Validate that user can access this company.
    if (!$issiteadmin && !isset($companies[$companyid])) {
        throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
    }
    $filters['companyid'] = $companyid;
}
if ($serviceid > 0) {
    $filters['serviceid'] = $serviceid;
}

// For company managers, restrict to their managed companies.
$companyfilter = $companyid > 0 ? $companyid : null;
if (!$issiteadmin && !$companyfilter) {
    // Get all managed company IDs.
    $managedids = array_keys(\local_sm_estratoos_plugin\util::get_user_managed_companies());
    $filters['companyids'] = $managedids;
}

$tokens = \local_sm_estratoos_plugin\company_token_manager::get_company_tokens(
    $companyfilter,
    $filters
);

if (empty($tokens)) {
    echo $OUTPUT->notification(get_string('notokens', 'local_sm_estratoos_plugin'), 'info');
} else {
    // Token table.
    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'tokensform']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulkrevoke']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'serviceid', 'value' => $serviceid]);

    $table = new html_table();
    $table->head = [
        html_writer::checkbox('selectall', 1, false, '', ['id' => 'selectall']),
        get_string('user'),
        get_string('company', 'local_sm_estratoos_plugin'),
        get_string('service', 'local_sm_estratoos_plugin'),
        get_string('restrictions', 'local_sm_estratoos_plugin'),
        get_string('validuntil', 'local_sm_estratoos_plugin'),
        get_string('lastaccess'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'table table-striped table-hover';

    foreach ($tokens as $token) {
        // Restrictions badges.
        $restrictions = '';
        if ($token->restricttocompany) {
            $restrictions .= html_writer::tag('span', get_string('companyonly', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-info mr-1']);
        }
        if ($token->restricttoenrolment) {
            $restrictions .= html_writer::tag('span', get_string('enrolledonly', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-warning mr-1']);
        }
        if (!empty($token->iprestriction)) {
            $restrictions .= html_writer::tag('span', $token->iprestriction,
                ['class' => 'badge badge-secondary']);
        }

        // Valid until.
        $validuntil = $token->validuntil ? userdate($token->validuntil) : get_string('neverexpires', 'local_sm_estratoos_plugin');

        // Last access.
        $lastaccess = $token->lastaccess ? userdate($token->lastaccess) : get_string('never');

        // Actions - use FA trash icon with proper centering.
        $trashicon = html_writer::tag('i', '', [
            'class' => 'fa fa-trash',
            'aria-hidden' => 'true'
        ]);
        $actions = html_writer::link(
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'revoke',
                'tokenid' => $token->id,
                'companyid' => $companyid,
                'serviceid' => $serviceid,
            ]),
            $trashicon,
            [
                'class' => 'btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center',
                'title' => get_string('revoke', 'local_sm_estratoos_plugin'),
                'aria-label' => get_string('revoke', 'local_sm_estratoos_plugin'),
                'style' => 'width: 32px; height: 32px;'
            ]
        );

        $table->data[] = [
            html_writer::checkbox('tokenids[]', $token->id, false, '', ['class' => 'tokencheck']),
            html_writer::tag('strong', fullname($token)) .
                html_writer::tag('br', '') .
                html_writer::tag('small', $token->email, ['class' => 'text-muted']),
            $token->companyname,
            $token->servicename,
            $restrictions ?: '-',
            $validuntil,
            $lastaccess,
            $actions,
        ];
    }

    echo html_writer::table($table);

    // Bulk actions.
    echo html_writer::start_div('d-flex justify-content-between align-items-center');
    echo html_writer::start_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('revokeselected', 'local_sm_estratoos_plugin'),
        'class' => 'btn btn-danger',
        'onclick' => 'return confirm("' . get_string('confirmrevokeselected', 'local_sm_estratoos_plugin') . '");',
    ]);
    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/export.php', [
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'format' => 'csv',
        ]),
        get_string('exportcsv', 'local_sm_estratoos_plugin'),
        ['class' => 'btn btn-outline-secondary ml-2']
    );
    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/export.php', [
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'format' => 'xlsx',
        ]),
        get_string('exportexcel', 'local_sm_estratoos_plugin'),
        ['class' => 'btn btn-outline-success ml-2']
    );
    echo html_writer::end_div();
    echo html_writer::tag('span', count($tokens) . ' tokens', ['class' => 'text-muted']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');

    // JavaScript for select all.
    $PAGE->requires->js_amd_inline("
        require(['jquery'], function($) {
            $('#selectall').on('change', function() {
                $('.tokencheck').prop('checked', this.checked);
            });
        });
    ");
}

echo $OUTPUT->footer();
