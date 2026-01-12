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

// Only site administrators can access this page.
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

// Trigger Moodle's update check for this plugin.
$updateavailable = null;
if (class_exists('\core\update\checker')) {
    $checker = \core\update\checker::instance();
    // Fetch updates if not checked recently (within last hour).
    $lastfetch = $checker->get_last_timefetched();
    if (time() - $lastfetch > 3600) {
        $checker->fetch();
    }
    // Check if update is available for this plugin.
    $updateinfo = $checker->get_update_info('local_sm_estratoos_plugin');
    if (!empty($updateinfo) && is_array($updateinfo)) {
        $updateavailable = reset($updateinfo);
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
echo html_writer::start_div('alert ' . $modeclass . ' d-flex align-items-center');
echo $OUTPUT->pix_icon($modeicon, '', 'moodle', ['class' => 'mr-2']);
echo html_writer::tag('span', get_string('moodlemode', 'local_sm_estratoos_plugin') . ': ', ['class' => 'font-weight-bold mr-1']);
echo html_writer::tag('span', $iomadstatus['message']);
echo html_writer::end_div();

// Dashboard cards.
$cards = [];

// Card 1: Create Admin Token.
$cards[] = [
    'title' => get_string('createadmintoken', 'local_sm_estratoos_plugin'),
    'description' => get_string('createadmintokendesc', 'local_sm_estratoos_plugin'),
    'url' => new moodle_url('/local/sm_estratoos_plugin/admin_token.php'),
    'icon' => 'i/lock',
    'class' => 'bg-primary text-white',
];

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

// Render cards.
echo html_writer::start_div('row mt-4');
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

// Count tokens.
$totalcompanytokens = $DB->count_records('local_sm_estratoos_plugin');
$totalbatches = $DB->count_records('local_sm_estratoos_plugin_batch');

// Get recent batches.
$recentbatches = \local_sm_estratoos_plugin\company_token_manager::get_batch_history(null, 5);

echo html_writer::start_div('row mt-3');

// Stat: Total Tokens (Company-scoped for IOMAD, User tokens for standard Moodle).
echo html_writer::start_div('col-md-3');
echo html_writer::start_div('card text-center');
echo html_writer::start_div('card-body');
echo html_writer::tag('h2', $totalcompanytokens, ['class' => 'display-4']);
$tokenlabel = $isiomad ? get_string('companyonly', 'local_sm_estratoos_plugin') . ' ' . get_string('tokens', 'local_sm_estratoos_plugin')
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
            get_string('success'),
            get_string('failed'),
            get_string('createdby', 'local_sm_estratoos_plugin')
        ];
    } else {
        $table->head = [
            get_string('date'),
            get_string('service', 'local_sm_estratoos_plugin'),
            get_string('success'),
            get_string('failed'),
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

echo $OUTPUT->footer();
