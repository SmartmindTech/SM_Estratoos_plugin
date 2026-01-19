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

// Quick select buttons - SAME structure as batch_token_form.php.
echo html_writer::start_div('company-quick-select mb-2');
echo html_writer::tag('label', get_string('quickselect', 'local_sm_estratoos_plugin') . ':', ['class' => 'font-weight-bold']);
echo html_writer::start_div('btn-group ml-2', ['role' => 'group']);
echo html_writer::tag('button', get_string('selectall'), [
    'type' => 'button',
    'class' => 'btn btn-sm btn-outline-secondary',
    'id' => 'select-all-companies'
]);
echo html_writer::tag('button', get_string('selectnone', 'local_sm_estratoos_plugin'), [
    'type' => 'button',
    'class' => 'btn btn-sm btn-outline-secondary',
    'id' => 'deselect-all-companies'
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Counter and Search - SAME layout as batch_token_form.php.
echo html_writer::start_div('company-list-header d-flex justify-content-between align-items-center mb-2');
echo html_writer::tag('span', $enabledcount . ' ' . get_string('companiesselected', 'local_sm_estratoos_plugin'), [
    'id' => 'selected-count'
]);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'company-search',
    'class' => 'form-control form-control-sm',
    'style' => 'width: 250px;',
    'placeholder' => get_string('searchcompanies', 'local_sm_estratoos_plugin')
]);
echo html_writer::end_div();

// Company list wrapper - SAME style as user-list-wrapper in batch_token_form.php.
echo html_writer::start_div('', [
    'id' => 'company-list-wrapper',
    'class' => 'border rounded',
    'style' => 'max-height: 400px; overflow-y: auto; background: #fafafa;'
]);

if (empty($companies)) {
    echo html_writer::tag('div', get_string('nocompanies', 'local_sm_estratoos_plugin'), [
        'class' => 'alert alert-info m-2'
    ]);
} else {
    echo html_writer::start_div('company-items', ['style' => 'padding: 0.5rem;']);
    foreach ($companies as $company) {
        $id = 'company-' . $company->id;

        // Company item - SAME structure as user-item in userselection.js.
        echo html_writer::start_div('company-item d-flex align-items-center py-2 px-2 border-bottom', [
            'data-name' => strtolower($company->name . ' ' . $company->shortname),
            'style' => 'background: #fff; margin-bottom: 1px;'
        ]);

        // Custom checkbox - SAME structure as batch_token_form.php.
        echo html_writer::start_div('custom-control custom-checkbox');
        echo html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'class' => 'custom-control-input company-checkbox',
            'id' => $id,
            'name' => 'companies[]',
            'value' => $company->id,
            'checked' => $company->enabled ? 'checked' : null
        ]);
        echo html_writer::start_tag('label', [
            'class' => 'custom-control-label',
            'for' => $id,
            'style' => 'cursor: pointer;'
        ]);
        echo html_writer::tag('strong', format_string($company->name));
        echo html_writer::tag('small', ' (' . format_string($company->shortname) . ')', ['class' => 'text-muted ml-2']);
        // Status badge - show Enabled or Disabled.
        if ($company->enabled) {
            echo html_writer::tag('span', get_string('enabled', 'local_sm_estratoos_plugin'), [
                'class' => 'badge badge-success ml-2 company-status-badge'
            ]);
        } else {
            echo html_writer::tag('span', get_string('disabled', 'local_sm_estratoos_plugin'), [
                'class' => 'badge badge-secondary ml-2 company-status-badge'
            ]);
        }
        echo html_writer::end_tag('label');
        echo html_writer::end_div(); // custom-control

        echo html_writer::end_div(); // company-item
    }
    echo html_writer::end_div(); // company-items
}

echo html_writer::end_div(); // company-list-wrapper

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

// Inline JavaScript for search functionality (more reliable than AMD modules).
// AMD modules can have caching issues in production mode.
$enabledtext = get_string('enabled', 'local_sm_estratoos_plugin');
$disabledtext = get_string('disabled', 'local_sm_estratoos_plugin');
?>
<script>
(function() {
    "use strict";

    document.addEventListener("DOMContentLoaded", function() {
        var searchInput = document.getElementById("company-search");
        var companyItems = document.querySelectorAll(".company-item");
        var checkboxes = document.querySelectorAll(".company-checkbox");
        var selectAllBtn = document.getElementById("select-all-companies");
        var deselectAllBtn = document.getElementById("deselect-all-companies");
        var countDisplay = document.getElementById("selected-count");

        // Update enabled count.
        function updateCount() {
            var count = document.querySelectorAll(".company-checkbox:checked").length;
            if (countDisplay) {
                countDisplay.textContent = count + " companies selected";
            }
        }

        // Update badge for a single checkbox.
        function updateBadge(checkbox) {
            var item = checkbox.closest(".company-item");
            if (!item) return;

            var badge = item.querySelector(".company-status-badge");
            if (!badge) return;

            if (checkbox.checked) {
                badge.className = "badge badge-success ml-2 company-status-badge";
                badge.textContent = "<?php echo $enabledtext; ?>";
            } else {
                badge.className = "badge badge-secondary ml-2 company-status-badge";
                badge.textContent = "<?php echo $disabledtext; ?>";
            }
        }

        // Search filter - filter companies as user types.
        if (searchInput) {
            searchInput.addEventListener("input", function() {
                var filter = this.value.toLowerCase().trim();

                companyItems.forEach(function(item) {
                    var name = item.getAttribute("data-name") || "";
                    var matches = (filter === "" || name.indexOf(filter) !== -1);

                    if (matches) {
                        item.classList.remove("d-none");
                    } else {
                        item.classList.add("d-none");
                    }
                });
            });
        }

        // Select all visible companies.
        if (selectAllBtn) {
            selectAllBtn.addEventListener("click", function() {
                companyItems.forEach(function(item) {
                    if (!item.classList.contains("d-none")) {
                        var checkbox = item.querySelector(".company-checkbox");
                        if (checkbox) {
                            checkbox.checked = true;
                            updateBadge(checkbox);
                        }
                    }
                });
                updateCount();
            });
        }

        // Deselect all visible companies.
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener("click", function() {
                companyItems.forEach(function(item) {
                    if (!item.classList.contains("d-none")) {
                        var checkbox = item.querySelector(".company-checkbox");
                        if (checkbox) {
                            checkbox.checked = false;
                            updateBadge(checkbox);
                        }
                    }
                });
                updateCount();
            });
        }

        // Update count and badge when any checkbox changes.
        checkboxes.forEach(function(checkbox) {
            checkbox.addEventListener("change", function() {
                updateCount();
                updateBadge(this);
            });
        });
    });
})();
</script>
<?php

echo $OUTPUT->footer();
