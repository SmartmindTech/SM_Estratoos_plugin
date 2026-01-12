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

// Only site administrators can access this page.
if (!is_siteadmin()) {
    throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
}

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

// Fetch update info.
$updateurl = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';
$updateinfo = fetch_update_info($updateurl);

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
        echo $OUTPUT->notification(get_string('updatesuccessful', 'local_sm_estratoos_plugin'), 'success');
        echo html_writer::tag('p', get_string('updatesuccessful_desc', 'local_sm_estratoos_plugin'));

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
 * Fetch update information from the update server.
 *
 * @param string $url Update XML URL.
 * @return array|null Update info or null on failure.
 */
function fetch_update_info(string $url): ?array {
    $curl = new curl(['cache' => false]);
    $curl->setopt([
        'CURLOPT_TIMEOUT' => 30,
        'CURLOPT_CONNECTTIMEOUT' => 10,
        'CURLOPT_FOLLOWLOCATION' => true,
    ]);

    $content = $curl->get($url);

    if ($curl->get_errno() || empty($content)) {
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($content);
    if ($xml === false || !isset($xml->update)) {
        return null;
    }

    $update = $xml->update;

    return [
        'version' => (int) $update->version,
        'release' => (string) $update->release,
        'download' => (string) $update->download,
        'maturity' => (int) $update->maturity,
    ];
}

/**
 * Perform the plugin update.
 *
 * @param array $updateinfo Update information.
 * @return bool True on success.
 */
function perform_plugin_update(array $updateinfo): bool {
    global $CFG;

    // Download the ZIP file.
    $downloadurl = 'https://github.com/SmartmindTech/SM_Estratoos_plugin/archive/refs/heads/main.zip';

    $tempdir = make_temp_directory('local_sm_estratoos_plugin_update');
    $zipfile = $tempdir . '/plugin_update.zip';

    mtrace(get_string('downloadingupdate', 'local_sm_estratoos_plugin'));
    echo html_writer::tag('p', get_string('downloadingupdate', 'local_sm_estratoos_plugin'));

    $curl = new curl(['cache' => false]);
    $curl->setopt([
        'CURLOPT_TIMEOUT' => 120,
        'CURLOPT_FOLLOWLOCATION' => true,
    ]);

    $result = $curl->download_one($downloadurl, null, ['filepath' => $zipfile]);

    if (!file_exists($zipfile) || filesize($zipfile) < 1000) {
        mtrace('Download failed');
        echo html_writer::tag('p', get_string('downloadfailed', 'local_sm_estratoos_plugin'), ['class' => 'text-danger']);
        return false;
    }

    mtrace(get_string('extractingupdate', 'local_sm_estratoos_plugin'));
    echo html_writer::tag('p', get_string('extractingupdate', 'local_sm_estratoos_plugin'));

    // Extract ZIP.
    $zip = new ZipArchive();
    if ($zip->open($zipfile) !== true) {
        mtrace('Failed to open ZIP');
        echo html_writer::tag('p', get_string('extractfailed', 'local_sm_estratoos_plugin'), ['class' => 'text-danger']);
        return false;
    }

    $extractdir = $tempdir . '/extracted';
    mkdir($extractdir, 0777, true);
    $zip->extractTo($extractdir);
    $zip->close();

    // Find the extracted folder (GitHub adds branch name to folder).
    $folders = glob($extractdir . '/*', GLOB_ONLYDIR);
    if (empty($folders)) {
        mtrace('No folder found in ZIP');
        return false;
    }
    $sourcedir = $folders[0];

    // Target directory.
    $targetdir = $CFG->dirroot . '/local/sm_estratoos_plugin';

    mtrace(get_string('installingupdate', 'local_sm_estratoos_plugin'));
    echo html_writer::tag('p', get_string('installingupdate', 'local_sm_estratoos_plugin'));

    // Backup current version (optional).
    $backupdir = $tempdir . '/backup_' . time();
    if (is_dir($targetdir)) {
        rename($targetdir, $backupdir);
    }

    // Copy new version.
    if (!rename($sourcedir, $targetdir)) {
        // Try recursive copy instead.
        if (!recursive_copy($sourcedir, $targetdir)) {
            // Restore backup.
            if (is_dir($backupdir)) {
                rename($backupdir, $targetdir);
            }
            mtrace('Failed to install update');
            echo html_writer::tag('p', get_string('installfailed', 'local_sm_estratoos_plugin'), ['class' => 'text-danger']);
            return false;
        }
    }

    // Clean up.
    @unlink($zipfile);
    if (is_dir($backupdir)) {
        recursive_delete($backupdir);
    }
    if (is_dir($extractdir)) {
        recursive_delete($extractdir);
    }

    // Clear caches.
    purge_all_caches();

    return true;
}

/**
 * Recursively copy a directory.
 *
 * @param string $src Source directory.
 * @param string $dst Destination directory.
 * @return bool True on success.
 */
function recursive_copy(string $src, string $dst): bool {
    $dir = opendir($src);
    if (!$dir) {
        return false;
    }

    @mkdir($dst, 0777, true);

    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcpath = $src . '/' . $file;
        $dstpath = $dst . '/' . $file;

        if (is_dir($srcpath)) {
            if (!recursive_copy($srcpath, $dstpath)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!copy($srcpath, $dstpath)) {
                closedir($dir);
                return false;
            }
        }
    }

    closedir($dir);
    return true;
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
