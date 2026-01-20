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
 * Auto-update page for SmartMind - Estratoos Plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
require_once(__DIR__ . '/classes/util.php');

// Site administrators and admin/manager roles can access this page.
$canmanageupdates = is_siteadmin() || \local_sm_estratoos_plugin\util::has_admin_or_manager_role();
if (!$canmanageupdates) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

require_once(__DIR__ . '/classes/update_checker.php');

$confirm = optional_param('confirm', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/update.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('updateplugin', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('updateplugin', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Get current and available versions.
$plugin = core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
$currentversion = $plugin->versiondisk;
$currentrelease = $plugin->release ?? 'unknown';

// Fetch update info using shared update_checker (with cache-busting).
$updateinfo = \local_sm_estratoos_plugin\update_checker::fetch_update_info();

if (!$updateinfo) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('updatefetcherror', 'local_sm_estratoos_plugin'), 'error');
    echo $OUTPUT->continue_button(new moodle_url('/local/sm_estratoos_plugin/index.php'));
    echo $OUTPUT->footer();
    exit;
}

// Check if update is needed.
if ($updateinfo['version'] <= $currentversion) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('alreadyuptodate', 'local_sm_estratoos_plugin'), 'info');
    echo $OUTPUT->continue_button(new moodle_url('/local/sm_estratoos_plugin/index.php'));
    echo $OUTPUT->footer();
    exit;
}

if ($confirm && confirm_sesskey()) {
    // Perform the update.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('updatingplugin', 'local_sm_estratoos_plugin'));

    $success = perform_plugin_update($updateinfo);

    if ($success) {
        // Update the plugin version for the appropriate companies.
        $newrelease = $updateinfo['release'] ?? $updateinfo['version'];
        $versionresult = \local_sm_estratoos_plugin\util::update_plugin_version_after_upgrade($newrelease);

        echo $OUTPUT->notification(get_string('updatesuccessful', 'local_sm_estratoos_plugin'), 'success');
        echo html_writer::tag('p', get_string('updatesuccessful_desc', 'local_sm_estratoos_plugin'));

        // Show which companies were updated.
        if (!empty($versionresult['message'])) {
            echo $OUTPUT->notification($versionresult['message'], 'info');
        }

        // Redirect to upgrade page.
        $upgradeurl = new moodle_url('/admin/index.php');
        echo $OUTPUT->continue_button($upgradeurl);
    } else {
        echo $OUTPUT->notification(get_string('updatefailed', 'local_sm_estratoos_plugin'), 'error');
        echo $OUTPUT->continue_button(new moodle_url('/local/sm_estratoos_plugin/index.php'));
    }

    echo $OUTPUT->footer();
    exit;
}

// Show confirmation page.
echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('updateavailable', 'local_sm_estratoos_plugin'));

// Version info table.
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('currentversion', 'local_sm_estratoos_plugin'), $currentrelease . ' (' . $currentversion . ')'],
    [get_string('newversion', 'local_sm_estratoos_plugin'), $updateinfo['release'] . ' (' . $updateinfo['version'] . ')'],
];
echo html_writer::table($table);

// Confirmation buttons.
$confirmurl = new moodle_url('/local/sm_estratoos_plugin/update.php', ['confirm' => 1, 'sesskey' => sesskey()]);
$cancelurl = new moodle_url('/local/sm_estratoos_plugin/index.php');

echo $OUTPUT->confirm(
    get_string('updateconfirm', 'local_sm_estratoos_plugin'),
    $confirmurl,
    $cancelurl
);

echo $OUTPUT->footer();

/**
 * Show manual update instructions when auto-update fails.
 *
 * @param array $updateinfo Update information.
 */
function show_manual_update_instructions(array $updateinfo): void {
    global $CFG;

    echo html_writer::start_tag('div', ['class' => 'alert alert-info']);
    echo html_writer::tag('h5', get_string('manualupdate_title', 'local_sm_estratoos_plugin'));

    echo html_writer::tag('p', get_string('manualupdate_intro', 'local_sm_estratoos_plugin'));

    echo html_writer::start_tag('ol');

    // Step 1: Download ZIP.
    $downloadurl = $updateinfo['download'] ?? 'https://github.com/SmartmindTech/SM_Estratoos_plugin/releases/latest';
    echo html_writer::tag('li', get_string('manualupdate_step1', 'local_sm_estratoos_plugin') . ' ' .
        html_writer::link($downloadurl, get_string('manualupdate_download', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']));

    // Step 2: Go to plugin installer.
    $installerurl = new moodle_url('/admin/tool/installaddon/index.php');
    echo html_writer::tag('li', get_string('manualupdate_step2', 'local_sm_estratoos_plugin') . ' ' .
        html_writer::link($installerurl, get_string('manualupdate_installer', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-secondary', 'target' => '_blank']));

    // Step 3: Upload and install.
    echo html_writer::tag('li', get_string('manualupdate_step3', 'local_sm_estratoos_plugin'));

    echo html_writer::end_tag('ol');

    // Alternative: command line.
    echo html_writer::tag('p', html_writer::tag('strong', get_string('manualupdate_cli_title', 'local_sm_estratoos_plugin')));
    echo html_writer::tag('pre', "cd {$CFG->dirroot}/local\nrm -rf sm_estratoos_plugin\nunzip /path/to/sm_estratoos_plugin.zip\nphp {$CFG->dirroot}/admin/cli/upgrade.php",
        ['class' => 'bg-light p-2', 'style' => 'font-size: 0.85em;']);

    echo html_writer::end_tag('div');
}

/**
 * Perform the plugin update.
 *
 * @param array $updateinfo Update information.
 * @return bool True on success.
 */
function perform_plugin_update(array $updateinfo): bool {
    global $CFG;

    // Download the ZIP file from GitHub main branch.
    $downloadurl = 'https://github.com/SmartmindTech/SM_Estratoos_plugin/archive/refs/heads/main.zip';

    $tempdir = make_temp_directory('local_sm_estratoos_plugin_update');
    $zipfile = $tempdir . '/plugin_update.zip';

    echo html_writer::tag('p', get_string('downloadingupdate', 'local_sm_estratoos_plugin'));

    $curl = new curl(['cache' => false]);
    $curl->setopt([
        'CURLOPT_TIMEOUT' => 120,
        'CURLOPT_FOLLOWLOCATION' => true,
        'CURLOPT_SSL_VERIFYPEER' => false,
    ]);

    $result = $curl->download_one($downloadurl, null, ['filepath' => $zipfile]);

    if (!file_exists($zipfile) || filesize($zipfile) < 1000) {
        echo html_writer::tag('p', get_string('downloadfailed', 'local_sm_estratoos_plugin') .
            ' (curl error: ' . $curl->error . ')', ['class' => 'text-danger']);
        return false;
    }

    echo html_writer::tag('p', '✓ ' . get_string('downloadingupdate', 'local_sm_estratoos_plugin') .
        ' (' . round(filesize($zipfile) / 1024) . ' KB)', ['class' => 'text-success']);

    echo html_writer::tag('p', get_string('extractingupdate', 'local_sm_estratoos_plugin'));

    // Extract ZIP.
    $zip = new ZipArchive();
    if ($zip->open($zipfile) !== true) {
        echo html_writer::tag('p', get_string('extractfailed', 'local_sm_estratoos_plugin'), ['class' => 'text-danger']);
        return false;
    }

    $extractdir = $tempdir . '/extracted';
    @mkdir($extractdir, 0777, true);
    $zip->extractTo($extractdir);
    $zip->close();

    // Find the extracted folder (GitHub adds branch name to folder).
    $folders = glob($extractdir . '/*', GLOB_ONLYDIR);
    if (empty($folders)) {
        echo html_writer::tag('p', get_string('extractfailed', 'local_sm_estratoos_plugin'), ['class' => 'text-danger']);
        return false;
    }
    $sourcedir = $folders[0];

    echo html_writer::tag('p', '✓ ' . get_string('extractingupdate', 'local_sm_estratoos_plugin'), ['class' => 'text-success']);

    // Target directory.
    $targetdir = $CFG->dirroot . '/local/sm_estratoos_plugin';

    echo html_writer::tag('p', get_string('installingupdate', 'local_sm_estratoos_plugin'));

    // Check if target directory is writable.
    if (!is_writable($targetdir)) {
        echo html_writer::tag('p', get_string('installfailed', 'local_sm_estratoos_plugin') .
            ' (Directory not writable)', ['class' => 'text-danger']);
        echo html_writer::tag('p', '', ['class' => 'mb-3']);
        show_manual_update_instructions($updateinfo);
        return false;
    }

    // Copy files from source to target (overwriting existing files).
    $copyresult = recursive_copy_overwrite($sourcedir, $targetdir);
    if (!$copyresult['success']) {
        echo html_writer::tag('p', get_string('installfailed', 'local_sm_estratoos_plugin') .
            ' (File copy failed)', ['class' => 'text-danger']);
        echo html_writer::tag('p', '', ['class' => 'mb-3']);
        show_manual_update_instructions($updateinfo);
        return false;
    }

    echo html_writer::tag('p', '✓ ' . get_string('installingupdate', 'local_sm_estratoos_plugin') .
        ' (' . $copyresult['count'] . ' files)', ['class' => 'text-success']);

    // Clean up.
    @unlink($zipfile);
    if (is_dir($extractdir)) {
        recursive_delete($extractdir);
    }

    // Clear caches.
    purge_all_caches();

    return true;
}

/**
 * Recursively copy files from source to destination, overwriting existing files.
 *
 * @param string $src Source directory.
 * @param string $dst Destination directory.
 * @return array Result with 'success', 'count', and 'error' keys.
 */
function recursive_copy_overwrite(string $src, string $dst): array {
    $result = ['success' => true, 'count' => 0, 'error' => ''];

    $dir = @opendir($src);
    if (!$dir) {
        $result['success'] = false;
        $result['error'] = "Cannot open source directory: $src";
        return $result;
    }

    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) {
            $result['success'] = false;
            $result['error'] = "Cannot create directory: $dst";
            closedir($dir);
            return $result;
        }
    }

    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcpath = $src . '/' . $file;
        $dstpath = $dst . '/' . $file;

        if (is_dir($srcpath)) {
            $subresult = recursive_copy_overwrite($srcpath, $dstpath);
            if (!$subresult['success']) {
                closedir($dir);
                return $subresult;
            }
            $result['count'] += $subresult['count'];
        } else {
            if (!@copy($srcpath, $dstpath)) {
                $result['success'] = false;
                $result['error'] = "Cannot copy file: $srcpath to $dstpath";
                closedir($dir);
                return $result;
            }
            $result['count']++;
        }
    }

    closedir($dir);
    return $result;
}

/**
 * Recursively delete a directory.
 *
 * @param string $dir Directory to delete.
 */
function recursive_delete(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            recursive_delete($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}
