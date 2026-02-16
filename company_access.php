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
 * Site administrators and IOMAD company managers can activate their companies
 * by entering an activation code provided by SmartLearning.
 *
 * - Site admins see all companies and can activate any of them.
 * - Company managers see only their managed companies.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Check if IOMAD is installed.
if (!\local_sm_estratoos_plugin\util::is_iomad_installed()) {
    throw new moodle_exception('noiomad', 'local_sm_estratoos_plugin');
}

// Access: site admins or IOMAD company managers (managertype > 0).
$issiteadmin = is_siteadmin();
if (!$issiteadmin && !\local_sm_estratoos_plugin\util::is_potential_token_admin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'manage company access');
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

// Get companies visible to this user.
if ($issiteadmin) {
    $companies = \local_sm_estratoos_plugin\util::get_companies_with_access_status();
} else {
    // Company managers: only their managed companies.
    $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies($USER->id);
    $allcompanies = \local_sm_estratoos_plugin\util::get_companies_with_access_status();
    $companies = [];
    foreach ($allcompanies as $c) {
        if (isset($managedcompanies[$c->id])) {
            $companies[$c->id] = $c;
        }
    }
}

// Handle form submission (activation code only).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $activationcodes = optional_param_array('activation_code', [], PARAM_ALPHANUMEXT);

    $activationmessages = [];
    foreach ($activationcodes as $cid => $code) {
        $code = trim($code);
        if (empty($code)) {
            continue;
        }
        $cid = (int) $cid;

        // Verify user can manage this company.
        if (!$issiteadmin && !isset($companies[$cid])) {
            continue;
        }

        // Skip if company already has this activation code.
        $existingcode = \local_sm_estratoos_plugin\util::get_company_activation_code($cid);
        if ($existingcode === $code) {
            continue;
        }

        // Validate format.
        if (!preg_match('/^ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            $activationmessages[] = (object)[
                'companyid' => $cid,
                'success' => false,
                'message' => get_string('activationcodeinvalid', 'local_sm_estratoos_plugin'),
            ];
            continue;
        }

        // Call SmartLearning to activate.
        $result = \local_sm_estratoos_plugin\webhook::activate_company($cid, $code);
        if ($result->success) {
            $start = !empty($result->contract_start) ? gmdate('Y-m-d', $result->contract_start) : '?';
            $end = !empty($result->contract_end) ? gmdate('Y-m-d', $result->contract_end) : '?';
            $tokenscreated = isset($result->tokens_created) ? (int) $result->tokens_created : 0;
            $msg = get_string('companyactivated', 'local_sm_estratoos_plugin',
                (object)['start' => $start, 'end' => $end]);
            if ($tokenscreated > 0) {
                $msg .= ' ' . get_string('tokenscreatedformanagers', 'local_sm_estratoos_plugin',
                    (object)['count' => $tokenscreated]);
            }
            $activationmessages[] = (object)[
                'companyid' => $cid,
                'success' => true,
                'message' => $msg,
            ];
        } else {
            $activationmessages[] = (object)[
                'companyid' => $cid,
                'success' => false,
                'message' => get_string('companyactivationfailed', 'local_sm_estratoos_plugin', $result->message),
            ];
        }
    }

    // Build redirect message.
    $redirectmsg = '';
    $redirecttype = \core\output\notification::NOTIFY_SUCCESS;
    if (!empty($activationmessages)) {
        $msgs = [];
        $hasfailure = false;
        foreach ($activationmessages as $am) {
            $msgs[] = $am->message;
            if (!$am->success) {
                $hasfailure = true;
            }
        }
        $redirectmsg = implode(' ', $msgs);
        if ($hasfailure) {
            $redirecttype = \core\output\notification::NOTIFY_WARNING;
        }
    }

    // Refresh company list after activation.
    redirect($PAGE->url, $redirectmsg ?: null, null, $redirectmsg ? $redirecttype : null);
}

// Re-fetch companies (in case of redirect from activation).
if ($issiteadmin) {
    $companies = \local_sm_estratoos_plugin\util::get_companies_with_access_status();
} else {
    $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies($USER->id);
    $allcompanies = \local_sm_estratoos_plugin\util::get_companies_with_access_status();
    $companies = [];
    foreach ($allcompanies as $c) {
        if (isset($managedcompanies[$c->id])) {
            $companies[$c->id] = $c;
        }
    }
}

$activatedcount = count(array_filter($companies, function($c) { return !empty($c->activation_code); }));
$totalcount = count($companies);

echo $OUTPUT->header();

echo html_writer::tag('p', get_string('manageractivationinstructions', 'local_sm_estratoos_plugin'), ['class' => 'lead']);

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

// Counter and Search.
echo html_writer::start_div('company-list-header d-flex justify-content-between align-items-center mb-2');
echo html_writer::tag('span',
    get_string('companiesactivatedcount', 'local_sm_estratoos_plugin',
        (object)['activated' => $activatedcount, 'total' => $totalcount]),
    ['id' => 'activated-count']
);
if ($totalcount > 5) {
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'company-search',
        'class' => 'form-control form-control-sm',
        'style' => 'width: 250px;',
        'placeholder' => get_string('searchcompanies', 'local_sm_estratoos_plugin')
    ]);
}
echo html_writer::end_div();

// Company list.
echo html_writer::start_div('', [
    'id' => 'company-list-wrapper',
    'class' => 'border rounded',
    'style' => 'max-height: 600px; overflow-y: auto; background: #fafafa;'
]);

if (empty($companies)) {
    echo html_writer::tag('div', get_string('nocompanies', 'local_sm_estratoos_plugin'), [
        'class' => 'alert alert-info m-2'
    ]);
} else {
    echo html_writer::start_div('company-items', ['style' => 'padding: 0.5rem;']);
    foreach ($companies as $company) {
        $isactivated = !empty($company->activation_code);
        $contractexpired = $isactivated && !empty($company->expirydate) && ($company->expirydate + 43200) < time();

        // Company item.
        echo html_writer::start_div('company-item d-flex flex-wrap align-items-center py-3 px-3 border-bottom', [
            'data-name' => strtolower($company->name . ' ' . $company->shortname),
            'style' => 'background: #fff; margin-bottom: 1px;'
        ]);

        // Company name and status.
        echo html_writer::start_div('flex-grow-1');
        echo html_writer::tag('strong', format_string($company->name));
        echo html_writer::tag('small', ' (' . format_string($company->shortname) . ')', ['class' => 'text-muted ml-1']);

        // Status badge.
        if ($isactivated && !$contractexpired) {
            echo html_writer::tag('span', get_string('statusactive', 'local_sm_estratoos_plugin'), [
                'class' => 'badge badge-success ml-2'
            ]);
        } else if ($contractexpired) {
            echo html_writer::tag('span', get_string('companycontractexpired', 'local_sm_estratoos_plugin'), [
                'class' => 'badge badge-danger ml-2'
            ]);
        } else {
            echo html_writer::tag('span', get_string('companynotactivated', 'local_sm_estratoos_plugin'), [
                'class' => 'badge badge-secondary ml-2'
            ]);
        }
        echo html_writer::end_div();

        // Activation code section.
        echo html_writer::start_div('ml-2 d-flex align-items-center');
        if ($isactivated && !$contractexpired) {
            // Show masked code and contract dates.
            $existingcode = $company->activation_code;
            $maskedcode = substr($existingcode, 0, 9) . '****-****';
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'class' => 'form-control form-control-sm',
                'style' => 'width: 190px; font-family: monospace; font-size: 0.8rem; background: #e9ecef;',
                'value' => $maskedcode,
                'disabled' => 'disabled',
            ]);
            if (!empty($company->contract_start)) {
                echo html_writer::tag('small',
                    get_string('contractstart', 'local_sm_estratoos_plugin') . ': ' .
                    gmdate('j M Y', $company->contract_start),
                    ['class' => 'text-muted ml-2 text-nowrap']
                );
            }
            if (!empty($company->expirydate)) {
                echo html_writer::tag('small',
                    get_string('contractend', 'local_sm_estratoos_plugin') . ': ' .
                    gmdate('j M Y', $company->expirydate),
                    ['class' => 'text-muted ml-2 text-nowrap']
                );
            }
        } else {
            // Activation code input field.
            echo html_writer::tag('label',
                get_string('companyactivationcode', 'local_sm_estratoos_plugin') . ':',
                ['class' => 'mb-0 mr-2 small text-muted text-nowrap']
            );
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'class' => 'form-control form-control-sm activation-code-input',
                'style' => 'width: 190px; font-family: monospace; font-size: 0.8rem;',
                'name' => 'activation_code[' . $company->id . ']',
                'placeholder' => 'ACT-XXXX-XXXX-XXXX',
                'value' => '',
                'pattern' => 'ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}',
                'title' => get_string('companyactivationcodehelp', 'local_sm_estratoos_plugin'),
            ]);
        }
        echo html_writer::end_div();

        echo html_writer::end_div(); // company-item
    }
    echo html_writer::end_div(); // company-items
}

echo html_writer::end_div(); // company-list-wrapper

// Submit button (only show if there are non-activated companies).
$hasunactivated = count(array_filter($companies, function($c) { return empty($c->activation_code); })) > 0;
if ($hasunactivated) {
    echo html_writer::start_div('mt-4');
    echo html_writer::tag('button', get_string('activatebutton', 'local_sm_estratoos_plugin'), [
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
}

echo html_writer::end_tag('form');

// Search JavaScript.
?>
<script>
(function() {
    "use strict";
    document.addEventListener("DOMContentLoaded", function() {
        var searchInput = document.getElementById("company-search");
        if (!searchInput) return;

        var companyItems = document.querySelectorAll(".company-item");
        searchInput.addEventListener("input", function() {
            var filter = this.value.toLowerCase().trim();
            companyItems.forEach(function(item) {
                var name = item.getAttribute("data-name") || "";
                if (filter === "" || name.indexOf(filter) !== -1) {
                    item.classList.remove("d-none");
                } else {
                    item.classList.add("d-none");
                }
            });
        });
    });
})();
</script>
<?php

echo $OUTPUT->footer();
