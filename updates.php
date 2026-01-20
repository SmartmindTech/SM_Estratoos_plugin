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
 * Plugin updates management page for SmartMind - Estratoos Plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Only site admins can access this page.
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

// Check if IOMAD is installed.
$isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
if (!$isiomad) {
    redirect(
        new moodle_url('/local/sm_estratoos_plugin/index.php'),
        get_string('noiomad', 'local_sm_estratoos_plugin'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

require_once(__DIR__ . '/classes/update_checker.php');

// Handle version sync actions.
$syncversions = optional_param('syncversions', 0, PARAM_BOOL);
$synccompany = optional_param('synccompany', 0, PARAM_INT);

// Get plugin info.
$plugin = core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
$currentversion = $plugin->release ?? $plugin->versiondisk;

if (($syncversions || $synccompany > 0) && confirm_sesskey()) {
    if ($syncversions) {
        // Sync all companies to the installed version.
        $result = \local_sm_estratoos_plugin\util::update_plugin_version_after_upgrade($currentversion);
        redirect(
            new moodle_url('/local/sm_estratoos_plugin/updates.php'),
            $result['message'],
            null,
            $result['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    } elseif ($synccompany > 0) {
        // Sync a specific company to the installed version.
        global $DB;
        $result = \local_sm_estratoos_plugin\util::set_company_plugin_version($synccompany, $currentversion);
        $company = $DB->get_record('company', ['id' => $synccompany], 'name, shortname');
        $companyname = $company ? $company->name . ' (' . $company->shortname . ')' : $synccompany;
        $message = $result
            ? get_string('versionsynced', 'local_sm_estratoos_plugin', $companyname)
            : get_string('versionsyncfailed', 'local_sm_estratoos_plugin', $companyname);
        redirect(
            new moodle_url('/local/sm_estratoos_plugin/updates.php'),
            $message,
            null,
            $result ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }
}

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/updates.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('managepluginupdates', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('managepluginupdates', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('managepluginupdates', 'local_sm_estratoos_plugin'));

echo $OUTPUT->header();

// Check for available updates.
$updateavailable = \local_sm_estratoos_plugin\update_checker::check();

// Get companies with versions.
$companiesWithVersions = \local_sm_estratoos_plugin\util::get_companies_with_versions();
$companiesNeedingUpdate = \local_sm_estratoos_plugin\util::get_companies_needing_update($currentversion);
$allUpToDate = empty($companiesNeedingUpdate);

// Show update notification if a new version is available from GitHub.
if ($updateavailable) {
    $newrelease = $updateavailable->release ?? $updateavailable->version;

    echo html_writer::start_div('alert alert-warning d-flex align-items-center justify-content-between');
    echo html_writer::start_div();
    echo $OUTPUT->pix_icon('i/warning', '', 'moodle', ['class' => 'mr-2']);
    echo html_writer::tag('strong', get_string('updateavailable', 'local_sm_estratoos_plugin') . ': ');
    echo get_string('currentversion', 'local_sm_estratoos_plugin') . ': ' . $currentversion . ' → ';
    echo get_string('newversion', 'local_sm_estratoos_plugin') . ': ' . $newrelease;
    echo html_writer::end_div();

    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/update.php'),
        get_string('updateplugin', 'local_sm_estratoos_plugin'),
        ['class' => 'btn btn-warning']
    );
    echo html_writer::end_div();
}

// Installed version info.
echo html_writer::start_div('alert alert-info');
echo html_writer::tag('strong', get_string('installedversion', 'local_sm_estratoos_plugin') . ': ');
echo $currentversion;
echo html_writer::end_div();

// Show "Sync All" button if there are companies needing updates.
if (!$allUpToDate) {
    echo html_writer::start_div('mb-4');
    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/updates.php', ['syncversions' => 1, 'sesskey' => sesskey()]),
        $OUTPUT->pix_icon('i/reload', '', 'moodle') . ' ' . get_string('updateallcompanies', 'local_sm_estratoos_plugin') . ' (' . count($companiesNeedingUpdate) . ')',
        ['class' => 'btn btn-primary btn-lg']
    );
    echo html_writer::end_div();
}

// Table of companies with versions.
if (!empty($companiesWithVersions)) {
    $table = new html_table();
    $table->attributes['class'] = 'table table-striped table-hover';
    $table->head = [
        get_string('company', 'local_sm_estratoos_plugin'),
        get_string('pluginversion', 'local_sm_estratoos_plugin'),
        get_string('enabled', 'local_sm_estratoos_plugin'),
        get_string('actions', 'local_sm_estratoos_plugin'),
    ];

    foreach ($companiesWithVersions as $company) {
        $versioncell = $company->plugin_version ?: '-';
        $needsupdate = isset($companiesNeedingUpdate[$company->id]);

        // Add badge if needs update.
        if ($needsupdate) {
            $versioncell .= ' ' . html_writer::tag('span', get_string('needsupdate', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-danger ml-2']);
        } elseif ($company->plugin_version === $currentversion) {
            $versioncell .= ' ' . html_writer::tag('span', '✓', ['class' => 'badge badge-success ml-2']);
        }

        // Enabled/disabled badge.
        $enabledbadge = $company->enabled
            ? html_writer::tag('span', get_string('enabled', 'local_sm_estratoos_plugin'), ['class' => 'badge badge-success'])
            : html_writer::tag('span', get_string('disabled', 'local_sm_estratoos_plugin'), ['class' => 'badge badge-danger']);

        // Actions - individual sync button.
        $actions = '';
        if ($needsupdate) {
            $syncurl = new moodle_url('/local/sm_estratoos_plugin/updates.php', [
                'synccompany' => $company->id,
                'sesskey' => sesskey(),
            ]);
            $actions = html_writer::link($syncurl, get_string('syncversion', 'local_sm_estratoos_plugin'),
                ['class' => 'btn btn-sm btn-primary']);
        } else {
            $actions = html_writer::tag('span', '✓ ' . get_string('uptodate', 'local_sm_estratoos_plugin'),
                ['class' => 'text-success']);
        }

        $table->data[] = [
            html_writer::tag('strong', $company->name) . ' (' . $company->shortname . ')',
            $versioncell,
            $enabledbadge,
            $actions,
        ];
    }

    echo html_writer::table($table);
} else {
    echo html_writer::div(get_string('nocompanies', 'local_sm_estratoos_plugin'), 'alert alert-warning');
}

// Show message if all up to date.
if ($allUpToDate && !empty($companiesWithVersions)) {
    echo html_writer::div(
        $OUTPUT->pix_icon('i/valid', '', 'moodle') . ' ' . get_string('allcompaniesuptodate', 'local_sm_estratoos_plugin'),
        'alert alert-success'
    );
}

// Back to dashboard button.
echo html_writer::start_div('mt-4');
echo html_writer::link(
    new moodle_url('/local/sm_estratoos_plugin/index.php'),
    get_string('backtodashboard', 'local_sm_estratoos_plugin'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
