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
 * Main dashboard for SmartMind tokens management.
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

require_once(__DIR__ . '/classes/update_checker.php');

// Check if site admin (for admin-only features).
$issiteadmin = is_siteadmin();

// Handle manual update check (only for site admins).
$checkupdates = optional_param('checkupdates', 0, PARAM_BOOL);
$updatechecked = false;
$updateavailable = false;

if ($issiteadmin) {
    if ($checkupdates && confirm_sesskey()) {
        // Force fetch updates.
        $updateavailable = \local_sm_estratoos_plugin\update_checker::check(true);
        $updatechecked = true;
    } else {
        // Check for available updates (uses cache if recent).
        $updateavailable = \local_sm_estratoos_plugin\update_checker::check();
    }
}

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('dashboard', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('dashboard', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'));

echo $OUTPUT->header();

// Debug info for troubleshooting (visible to admins).
$showdebug = optional_param('debug', 0, PARAM_BOOL);
if ($showdebug) {
    $plugin = core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
    $cachedinfo = get_config('local_sm_estratoos_plugin', 'cached_update_info');
    $lastcheck = get_config('local_sm_estratoos_plugin', 'last_update_check');

    echo html_writer::start_div('alert alert-secondary');
    echo html_writer::tag('h5', 'Debug Info');
    echo html_writer::tag('p', '<strong>Installed version:</strong> ' . $plugin->versiondisk . ' (' . ($plugin->release ?? 'N/A') . ')');
    echo html_writer::tag('p', '<strong>Last check:</strong> ' . ($lastcheck ? userdate($lastcheck) : 'Never'));
    echo html_writer::tag('p', '<strong>Cached info:</strong> ' . ($cachedinfo ? htmlspecialchars($cachedinfo) : 'None'));
    echo html_writer::tag('p', '<strong>Update available object:</strong> ' . ($updateavailable ? json_encode($updateavailable) : 'null'));
    echo html_writer::end_div();
}

// Show success message if update check was performed.
if ($updatechecked) {
    if ($updateavailable) {
        echo $OUTPUT->notification(get_string('updateavailable', 'local_sm_estratoos_plugin'), 'info');
    } else {
        echo $OUTPUT->notification(get_string('alreadyuptodate', 'local_sm_estratoos_plugin'), 'success');
    }
}

// Show update notification if available.
if ($updateavailable) {
    $plugin = core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
    $currentversion = $plugin->release ?? $plugin->versiondisk;
    $newversion = $updateavailable->version;
    $newrelease = $updateavailable->release ?? $newversion;

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

echo $OUTPUT->heading(get_string('dashboard', 'local_sm_estratoos_plugin'));
echo html_writer::tag('p', get_string('dashboarddesc', 'local_sm_estratoos_plugin'), ['class' => 'lead']);

// Check if IOMAD is installed.
$isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
$iomadstatus = \local_sm_estratoos_plugin\util::get_iomad_status();

// Show mode indicator.
$modeclass = $isiomad ? 'alert-info' : 'alert-warning';
$modeicon = $isiomad ? 'i/siteevent' : 'i/moodle_host';
echo html_writer::start_div('alert ' . $modeclass . ' d-flex align-items-center justify-content-between');
echo html_writer::start_div('d-flex align-items-center');
echo $OUTPUT->pix_icon($modeicon, '', 'moodle', ['class' => 'mr-2']);
echo html_writer::tag('span', get_string('moodlemode', 'local_sm_estratoos_plugin') . ': ', ['class' => 'font-weight-bold mr-1']);
echo html_writer::tag('span', $iomadstatus['message']);
echo html_writer::end_div();
// Check for updates button (only for site admins).
if ($issiteadmin) {
    $checkurl = new moodle_url('/local/sm_estratoos_plugin/index.php', ['checkupdates' => 1, 'sesskey' => sesskey()]);
    echo html_writer::link($checkurl, get_string('checkforupdates', 'local_sm_estratoos_plugin'), ['class' => 'btn btn-outline-secondary btn-sm']);
}
echo html_writer::end_div();

// Dashboard cards.
$cards = [];

// Card 1: Create Admin Token (only for site admins).
if ($issiteadmin) {
    $cards[] = [
        'title' => get_string('createadmintoken', 'local_sm_estratoos_plugin'),
        'description' => get_string('createadmintokendesc', 'local_sm_estratoos_plugin'),
        'url' => new moodle_url('/local/sm_estratoos_plugin/admin_token.php'),
        'icon' => 'i/lock',
        'class' => 'bg-primary text-white',
    ];
}

// Card 2: Create Company/User Tokens (depending on IOMAD mode).
if ($isiomad) {
    // IOMAD mode: Create Company Tokens.
    $cards[] = [
        'title' => get_string('createcompanytokens', 'local_sm_estratoos_plugin'),
        'description' => get_string('createcompanytokensdesc', 'local_sm_estratoos_plugin'),
        'url' => new moodle_url('/local/sm_estratoos_plugin/batch.php'),
        'icon' => 'i/users',
        'class' => 'bg-success text-white',
    ];
} else {
    // Standard Moodle: Create User Tokens (no company context).
    $cards[] = [
        'title' => get_string('createusertokens', 'local_sm_estratoos_plugin'),
        'description' => get_string('createusertokensdesc', 'local_sm_estratoos_plugin'),
        'url' => new moodle_url('/local/sm_estratoos_plugin/batch.php'),
        'icon' => 'i/users',
        'class' => 'bg-success text-white',
    ];
}

// Card 3: Manage Tokens.
$cards[] = [
    'title' => get_string('managetokens', 'local_sm_estratoos_plugin'),
    'description' => get_string('managetokensdesc', 'local_sm_estratoos_plugin'),
    'url' => new moodle_url('/local/sm_estratoos_plugin/manage.php'),
    'icon' => 'i/settings',
    'class' => 'bg-info text-white',
];

// Card 4: Manage Web Services (only for site admins).
if ($issiteadmin) {
    $cards[] = [
        'title' => get_string('manageservices', 'local_sm_estratoos_plugin'),
        'description' => get_string('manageservicesdesc', 'local_sm_estratoos_plugin'),
        'url' => new moodle_url('/local/sm_estratoos_plugin/services.php'),
        'icon' => 'i/edit',
        'class' => 'bg-secondary text-white',
    ];
}

// Card 5: Manage Company Access (only for site admins in IOMAD mode).
if ($issiteadmin && $isiomad) {
    $cards[] = [
        'title' => get_string('managecompanyaccess', 'local_sm_estratoos_plugin'),
        'description' => get_string('managecompanyaccessdesc', 'local_sm_estratoos_plugin'),
        'url' => new moodle_url('/local/sm_estratoos_plugin/company_access.php'),
        'icon' => 'i/permissions',
        'class' => 'bg-dark text-white',
    ];
}

// Render cards.
echo html_writer::start_div('row mt-4 justify-content-center');
foreach ($cards as $card) {
    echo html_writer::start_div('col-md-4 mb-4');
    echo html_writer::start_div('card h-100 ' . $card['class']);
    echo html_writer::start_div('card-body text-center');

    // Icon.
    echo html_writer::tag('div',
        $OUTPUT->pix_icon($card['icon'], '', 'moodle', ['class' => 'icon-large']),
        ['class' => 'mb-3', 'style' => 'font-size: 3rem;']
    );

    // Title.
    echo html_writer::tag('h4', $card['title'], ['class' => 'card-title']);

    // Description.
    echo html_writer::tag('p', $card['description'], ['class' => 'card-text']);

    echo html_writer::end_div(); // card-body

    // Card footer with button.
    echo html_writer::start_div('card-footer bg-transparent border-0 text-center');
    echo html_writer::link($card['url'], get_string('go'), [
        'class' => 'btn btn-light btn-lg',
    ]);
    echo html_writer::end_div(); // card-footer

    echo html_writer::end_div(); // card
    echo html_writer::end_div(); // col
}
echo html_writer::end_div(); // row

// Statistics section.
echo html_writer::tag('h3', get_string('statistics'), ['class' => 'mt-5']);

global $DB;

// Count tokens (filtered for company managers).
if ($issiteadmin) {
    $totalcompanytokens = $DB->count_records('local_sm_estratoos_plugin');
    $totalbatches = $DB->count_records('local_sm_estratoos_plugin_batch');
    $recentbatches = \local_sm_estratoos_plugin\company_token_manager::get_batch_history(null, 5);
} else {
    // Company manager - filter by managed companies.
    $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
    $managedids = array_keys($managedcompanies);

    if (!empty($managedids)) {
        list($insql, $params) = $DB->get_in_or_equal($managedids);
        $totalcompanytokens = $DB->count_records_select('local_sm_estratoos_plugin', "companyid $insql", $params);
        $totalbatches = $DB->count_records_select('local_sm_estratoos_plugin_batch', "companyid $insql", $params);
        $recentbatches = \local_sm_estratoos_plugin\company_token_manager::get_batch_history($managedids, 5);
    } else {
        $totalcompanytokens = 0;
        $totalbatches = 0;
        $recentbatches = [];
    }
}

echo html_writer::start_div('row mt-3');

// Stat: Total Tokens (Company-scoped for IOMAD, User tokens for standard Moodle).
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card text-center');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', $totalcompanytokens, ['class' => 'display-4']);
$tokenlabel = $isiomad ? get_string('companytokens_stat', 'local_sm_estratoos_plugin')
                       : get_string('tokens', 'local_sm_estratoos_plugin');
echo html_writer::tag('p', $tokenlabel, ['class' => 'text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Stat: Total Batches.
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card text-center');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', $totalbatches, ['class' => 'display-4']);
echo html_writer::tag('p', get_string('batchtokens', 'local_sm_estratoos_plugin'), ['class' => 'text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // row

// Recent batches table.
if (!empty($recentbatches)) {
    echo html_writer::tag('h4', get_string('recentbatches', 'local_sm_estratoos_plugin'), ['class' => 'mt-4']);

    $table = new html_table();
    // Adjust headers based on IOMAD mode.
    if ($isiomad) {
        $table->head = [
            get_string('date'),
            get_string('company', 'local_sm_estratoos_plugin'),
            get_string('service', 'local_sm_estratoos_plugin'),
            get_string('stat_success', 'local_sm_estratoos_plugin'),
            get_string('stat_failed', 'local_sm_estratoos_plugin'),
            get_string('createdby', 'local_sm_estratoos_plugin')
        ];
    } else {
        $table->head = [
            get_string('date'),
            get_string('service', 'local_sm_estratoos_plugin'),
            get_string('stat_success', 'local_sm_estratoos_plugin'),
            get_string('stat_failed', 'local_sm_estratoos_plugin'),
            get_string('createdby', 'local_sm_estratoos_plugin')
        ];
    }
    $table->attributes['class'] = 'table table-striped';

    foreach ($recentbatches as $batch) {
        if ($isiomad) {
            $table->data[] = [
                userdate($batch->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                $batch->companyname ?? '-',
                $batch->servicename,
                html_writer::tag('span', $batch->successcount, ['class' => 'badge badge-success']),
                html_writer::tag('span', $batch->failcount, ['class' => 'badge badge-danger']),
                fullname($batch),
            ];
        } else {
            $table->data[] = [
                userdate($batch->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                $batch->servicename,
                html_writer::tag('span', $batch->successcount, ['class' => 'badge badge-success']),
                html_writer::tag('span', $batch->failcount, ['class' => 'badge badge-danger']),
                fullname($batch),
            ];
        }
    }

    echo html_writer::table($table);
}

// Deletion history section.
if ($issiteadmin) {
    $deletions = \local_sm_estratoos_plugin\company_token_manager::get_recent_deletions(null, 50);
} else if (!empty($managedids)) {
    $deletions = \local_sm_estratoos_plugin\company_token_manager::get_recent_deletions($managedids, 50);
} else {
    $deletions = [];
}

if (!empty($deletions)) {
    echo html_writer::tag('h4', get_string('deletionhistory', 'local_sm_estratoos_plugin'), ['class' => 'mt-4']);

    $deletioncount = count($deletions);
    $collapseclass = $deletioncount > 5 ? '' : ' show';

    echo html_writer::start_div('card');

    // Collapsible header.
    echo html_writer::start_div('card-header', ['id' => 'deletionHistoryHeader']);
    echo html_writer::start_tag('button', [
        'class' => 'btn btn-link text-left text-start w-100',
        'type' => 'button',
        'data-toggle' => 'collapse',
        'data-target' => '#deletionHistoryContent',
        'data-bs-toggle' => 'collapse',
        'data-bs-target' => '#deletionHistoryContent',
        'aria-expanded' => ($deletioncount <= 5 ? 'true' : 'false'),
        'aria-controls' => 'deletionHistoryContent'
    ]);
    echo $OUTPUT->pix_icon('i/trash', '', 'moodle', ['class' => 'mr-2']);
    echo html_writer::tag('strong', $deletioncount . ' ' . get_string('tokensdeleted', 'local_sm_estratoos_plugin'));
    echo ' (' . get_string('clicktoexpand', 'local_sm_estratoos_plugin') . ')';
    echo html_writer::end_tag('button');
    echo html_writer::end_div(); // card-header

    // Collapsible content.
    echo html_writer::start_div('collapse' . $collapseclass, ['id' => 'deletionHistoryContent', 'aria-labelledby' => 'deletionHistoryHeader']);
    echo html_writer::start_div('card-body');

    // Scrollable table container.
    echo html_writer::start_div('table-responsive', ['style' => 'max-height: 300px; overflow-y: auto;']);

    $deltable = new html_table();
    $deltable->head = [
        get_string('date'),
        get_string('user', 'local_sm_estratoos_plugin'),
        get_string('token', 'local_sm_estratoos_plugin'),
        get_string('company', 'local_sm_estratoos_plugin'),
        get_string('deletedby', 'local_sm_estratoos_plugin')
    ];
    $deltable->attributes['class'] = 'table table-sm table-striped';

    foreach ($deletions as $deletion) {
        // Build deleter name from deleterfirstname/deleterlastname.
        $deletername = trim($deletion->deleterfirstname . ' ' . $deletion->deleterlastname);
        $deltable->data[] = [
            html_writer::tag('small', userdate($deletion->timedeleted, get_string('strftimedatetimeshort', 'langconfig'))),
            $deletion->userfullname,
            html_writer::tag('code', $deletion->tokenname),
            $deletion->companyname ?: '-',
            $deletername
        ];
    }

    echo html_writer::table($deltable);
    echo html_writer::end_div(); // table-responsive

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // collapse
    echo html_writer::end_div(); // card
}

echo $OUTPUT->footer();
