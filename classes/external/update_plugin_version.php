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
 * External function to trigger plugin update from external systems.
 *
 * This function allows external systems (like SmartLearning) to:
 * 1. Check if a plugin update is available
 * 2. Get download URL and update instructions
 * 3. Trigger the update process remotely
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
 *
 * Use cases:
 * - SmartLearning checking if Moodle plugin needs update
 * - Automated deployment systems
 * - Remote administration tools
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
                'Action to perform: "check" (default) = check for updates, "info" = get detailed update info',
                VALUE_DEFAULT,
                'check'
            ),
        ]);
    }

    /**
     * Check for plugin updates and return update information.
     *
     * @param string $action The action to perform (check or info).
     * @return array Update information.
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

        // Get current installed version.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $currentversion = $plugin->versiondb ?? 0;
        $currentrelease = $plugin->release ?? 'unknown';

        // Fetch latest version from update server.
        $updateurl = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';
        $latestversion = $currentversion;
        $latestrelease = $currentrelease;
        $downloadurl = '';
        $releasenotes = '';
        $releaseurl = '';

        try {
            $xmlcontent = @file_get_contents($updateurl);
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

        // Build the manual update URL (admin page).
        $mabortupdateurl = $CFG->wwwroot . '/admin/index.php';

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
            'downloadurl' => $downloadurl,
            'releaseurl' => $releaseurl,
            'releasenotes' => $releasenotes,
            'adminupdateurl' => $mabortupdateurl,
            'isiomad' => $isiomad,
            'companycount' => $companycount,
            'tokencount' => $tokencount,
            'mabortupdateurl' => $CFG->wwwroot,
            'warnings' => $warnings,
        ];
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
            'downloadurl' => new external_value(PARAM_URL, 'URL to download the latest plugin ZIP', VALUE_OPTIONAL),
            'releaseurl' => new external_value(PARAM_URL, 'URL to the release page on GitHub', VALUE_OPTIONAL),
            'releasenotes' => new external_value(PARAM_RAW, 'Release notes HTML', VALUE_OPTIONAL),
            'adminupdateurl' => new external_value(PARAM_URL, 'URL to Moodle admin page to trigger update'),
            'isiomad' => new external_value(PARAM_BOOL, 'Whether IOMAD is installed'),
            'companycount' => new external_value(PARAM_INT, 'Number of IOMAD companies'),
            'tokencount' => new external_value(PARAM_INT, 'Number of tokens using this service'),
            'mabortupdateurl' => new external_value(PARAM_URL, 'Moodle base URL'),
            'warnings' => new external_warnings(),
        ]);
    }
}
