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
 * External function to update the plugin from external systems.
 *
 * This function allows external systems (like SmartLearning) to:
 * 1. Check if a plugin update is available (action=check)
 * 2. Actually perform the plugin update (action=update)
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use external_warnings;

/**
 * External function to update the plugin from external systems.
 */
class update_plugin_version extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(
                PARAM_ALPHA,
                'Action: "check" = check for updates (default), "update" = download and install update',
                VALUE_DEFAULT,
                'check'
            ),
        ]);
    }

    /**
     * Check for updates or perform the update.
     *
     * @param string $action The action to perform.
     * @return array Result information.
     */
    public static function execute(string $action = 'check'): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'action' => $action,
        ]);

        // Basic validation.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        $warnings = [];
        $updateperformed = false;
        $updatemessage = '';

        // Get current installed version.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $currentversion = $plugin->versiondb ?? 0;
        $currentrelease = $plugin->release ?? 'unknown';

        // Fetch latest version from update server.
        $updateserverurl = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';
        $latestversion = $currentversion;
        $latestrelease = $currentrelease;
        $downloadurl = '';
        $releasenotes = '';
        $releaseurl = '';

        try {
            $xmlcontent = @file_get_contents($updateserverurl);
            if ($xmlcontent !== false) {
                $xml = @simplexml_load_string($xmlcontent);
                if ($xml && isset($xml->update)) {
                    $latestversion = (int)$xml->update->version;
                    $latestrelease = (string)$xml->update->release;
                    $downloadurl = (string)$xml->update->download;
                    $releaseurl = (string)$xml->update->url;
                    if (isset($xml->update->releasenotes)) {
                        $releasenotes = trim((string)$xml->update->releasenotes);
                    }
                }
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'warningcode' => 'updatecheckfailed',
                'message' => 'Could not check for updates: ' . $e->getMessage(),
            ];
        }

        $updateavailable = $latestversion > $currentversion;

        // If action is 'update' and update is available, perform the update.
        if ($params['action'] === 'update') {
            if (!$updateavailable) {
                $updatemessage = 'No update available. Current version is already the latest.';
            } else if (empty($downloadurl)) {
                $updatemessage = 'Download URL not available.';
                $warnings[] = [
                    'warningcode' => 'nodownloadurl',
                    'message' => $updatemessage,
                ];
            } else {
                // Only site admins can perform updates.
                if (!is_siteadmin($USER)) {
                    throw new \moodle_exception('nopermissions', 'error', '', 'update plugin');
                }

                // Perform the actual update.
                try {
                    $result = self::perform_update($downloadurl, $latestversion, $latestrelease);
                    $updateperformed = $result['success'];
                    $updatemessage = $result['message'];

                    if (!$updateperformed) {
                        $warnings[] = [
                            'warningcode' => 'updatefailed',
                            'message' => $updatemessage,
                        ];
                    }
                } catch (\Exception $e) {
                    $updatemessage = 'Update failed: ' . $e->getMessage();
                    $warnings[] = [
                        'warningcode' => 'updateexception',
                        'message' => $updatemessage,
                    ];
                }
            }
        }

        // Get IOMAD info.
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();
        $companycount = 0;
        if ($isiomad) {
            $companycount = $DB->count_records('company');
        }

        // Get token count.
        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
        $tokencount = 0;
        if ($service) {
            $tokencount = $DB->count_records('external_tokens', ['externalserviceid' => $service->id]);
        }

        return [
            'success' => true,
            'action' => $params['action'],
            'currentversion' => $currentversion,
            'currentrelease' => $currentrelease,
            'latestversion' => $latestversion,
            'latestrelease' => $latestrelease,
            'updateavailable' => $updateavailable,
            'updateperformed' => $updateperformed,
            'updatemessage' => $updatemessage,
            'downloadurl' => $downloadurl,
            'releaseurl' => $releaseurl,
            'releasenotes' => $releasenotes,
            'adminupdateurl' => $CFG->wwwroot . '/admin/index.php',
            'isiomad' => $isiomad,
            'companycount' => $companycount,
            'tokencount' => $tokencount,
            'siteurl' => $CFG->wwwroot,
            'warnings' => $warnings,
        ];
    }

    /**
     * Perform the actual plugin update.
     *
     * @param string $downloadurl URL to download the plugin ZIP.
     * @param int $newversion New version number.
     * @param string $newrelease New release string.
     * @return array Result with 'success' and 'message'.
     */
    private static function perform_update(string $downloadurl, int $newversion, string $newrelease): array {
        global $CFG;

        // Plugin directory.
        $plugindir = $CFG->dirroot . '/local/sm_estratoos_plugin';

        // Create temp directory for download.
        $tempdir = make_temp_directory('sm_estratoos_plugin_update');
        $zipfile = $tempdir . '/plugin.zip';
        $extractdir = $tempdir . '/extracted';

        // Step 1: Download the ZIP file.
        $zipcontents = @file_get_contents($downloadurl);
        if ($zipcontents === false) {
            return ['success' => false, 'message' => 'Failed to download update from: ' . $downloadurl];
        }

        if (!file_put_contents($zipfile, $zipcontents)) {
            return ['success' => false, 'message' => 'Failed to save downloaded file'];
        }

        // Step 2: Extract the ZIP file.
        $zip = new \ZipArchive();
        if ($zip->open($zipfile) !== true) {
            return ['success' => false, 'message' => 'Failed to open ZIP file'];
        }

        // Create extraction directory.
        if (!mkdir($extractdir, 0777, true)) {
            $zip->close();
            return ['success' => false, 'message' => 'Failed to create extraction directory'];
        }

        if (!$zip->extractTo($extractdir)) {
            $zip->close();
            return ['success' => false, 'message' => 'Failed to extract ZIP file'];
        }
        $zip->close();

        // Step 3: Find the plugin directory in extracted files.
        // GitHub releases usually have a root folder like "SM_Estratoos_plugin-main" or just the files.
        $extractedplugindir = $extractdir;
        $items = scandir($extractdir);
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..' && is_dir($extractdir . '/' . $item)) {
                // Check if this is the plugin directory (has version.php).
                if (file_exists($extractdir . '/' . $item . '/version.php')) {
                    $extractedplugindir = $extractdir . '/' . $item;
                    break;
                }
            }
        }

        // Verify version.php exists.
        if (!file_exists($extractedplugindir . '/version.php')) {
            return ['success' => false, 'message' => 'Invalid plugin package: version.php not found'];
        }

        // Step 4: Backup current plugin (optional but recommended).
        $backupdir = $tempdir . '/backup_' . date('YmdHis');
        if (!self::copy_directory($plugindir, $backupdir)) {
            // Continue anyway, backup is optional.
            error_log("SM_ESTRATOOS_PLUGIN UPDATE: Could not create backup at $backupdir");
        }

        // Step 5: Remove old plugin files (except .git if present).
        $filestoskip = ['.git', '.gitignore'];
        self::clear_directory($plugindir, $filestoskip);

        // Step 6: Copy new plugin files.
        if (!self::copy_directory($extractedplugindir, $plugindir)) {
            // Try to restore from backup.
            if (is_dir($backupdir)) {
                self::clear_directory($plugindir, []);
                self::copy_directory($backupdir, $plugindir);
            }
            return ['success' => false, 'message' => 'Failed to copy new plugin files'];
        }

        // Step 7: Clean up temp files.
        self::remove_directory($tempdir);

        // Step 8: Trigger Moodle upgrade check.
        // This will be done when admin visits admin/index.php or via CLI.
        // We can't run it directly here as it requires a full page reload.

        return [
            'success' => true,
            'message' => "Plugin files updated to v$newrelease. Please visit Site Administration to complete the database upgrade.",
        ];
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $src Source directory.
     * @param string $dst Destination directory.
     * @return bool Success.
     */
    private static function copy_directory(string $src, string $dst): bool {
        if (!is_dir($src)) {
            return false;
        }

        if (!is_dir($dst)) {
            if (!mkdir($dst, 0777, true)) {
                return false;
            }
        }

        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcpath = $src . '/' . $file;
            $dstpath = $dst . '/' . $file;

            if (is_dir($srcpath)) {
                if (!self::copy_directory($srcpath, $dstpath)) {
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
     * Clear a directory (remove all contents).
     *
     * @param string $dir Directory to clear.
     * @param array $skip Files/folders to skip.
     */
    private static function clear_directory(string $dir, array $skip): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (in_array($item, $skip)) {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::remove_directory($path);
            } else {
                unlink($path);
            }
        }
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $dir Directory to remove.
     */
    private static function remove_directory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::remove_directory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation was successful'),
            'action' => new external_value(PARAM_ALPHA, 'Action that was performed'),
            'currentversion' => new external_value(PARAM_INT, 'Currently installed version number'),
            'currentrelease' => new external_value(PARAM_TEXT, 'Currently installed release string'),
            'latestversion' => new external_value(PARAM_INT, 'Latest available version number'),
            'latestrelease' => new external_value(PARAM_TEXT, 'Latest available release string'),
            'updateavailable' => new external_value(PARAM_BOOL, 'Whether an update is available'),
            'updateperformed' => new external_value(PARAM_BOOL, 'Whether update was performed (action=update only)'),
            'updatemessage' => new external_value(PARAM_TEXT, 'Update result message', VALUE_OPTIONAL),
            'downloadurl' => new external_value(PARAM_URL, 'URL to download the latest plugin ZIP', VALUE_OPTIONAL),
            'releaseurl' => new external_value(PARAM_URL, 'URL to the release page on GitHub', VALUE_OPTIONAL),
            'releasenotes' => new external_value(PARAM_RAW, 'Release notes HTML', VALUE_OPTIONAL),
            'adminupdateurl' => new external_value(PARAM_URL, 'URL to Moodle admin page to complete upgrade'),
            'isiomad' => new external_value(PARAM_BOOL, 'Whether IOMAD is installed'),
            'companycount' => new external_value(PARAM_INT, 'Number of IOMAD companies'),
            'tokencount' => new external_value(PARAM_INT, 'Number of tokens using this service'),
            'siteurl' => new external_value(PARAM_URL, 'Moodle site URL'),
            'warnings' => new external_warnings(),
        ]);
    }
}
