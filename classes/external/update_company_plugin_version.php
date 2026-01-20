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
 * External function to get plugin version and check for updates.
 *
 * This allows external systems to monitor the plugin status and
 * check if updates are available.
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
use external_warnings;
use local_sm_estratoos_plugin\util;

/**
 * Get plugin version status and check for updates.
 */
class update_company_plugin_version extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'checkforupdates' => new external_value(PARAM_BOOL, 'Force check for updates (default: false)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Get the plugin version status and optionally check for updates.
     *
     * @param bool $checkforupdates Whether to force check for updates.
     * @return array Plugin status information.
     */
    public static function execute(bool $checkforupdates = false): array {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'checkforupdates' => $checkforupdates,
        ]);

        // Validate context.
        $context = \context_system::instance();
        self::validate_context($context);

        // Get current plugin info.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $currentversion = $plugin->versiondisk;
        $currentrelease = $plugin->release ?? '';

        // Check for updates.
        $updateavailable = false;
        $latestversion = 0;
        $latestrelease = '';
        $downloadurl = '';

        if ($params['checkforupdates']) {
            require_once(__DIR__ . '/../update_checker.php');
            $updateinfo = \local_sm_estratoos_plugin\update_checker::fetch_update_info();

            if ($updateinfo) {
                $latestversion = $updateinfo['version'] ?? 0;
                $latestrelease = $updateinfo['release'] ?? '';
                $downloadurl = $updateinfo['download'] ?? '';
                $updateavailable = ($latestversion > $currentversion);
            }
        }

        // Get IOMAD status.
        $isiomad = util::is_iomad_installed();
        $companycount = 0;
        if ($isiomad) {
            $companycount = $DB->count_records('company');
        }

        return [
            'success' => true,
            'currentversion' => $currentversion,
            'currentrelease' => $currentrelease,
            'updateavailable' => $updateavailable,
            'latestversion' => $latestversion,
            'latestrelease' => $latestrelease,
            'downloadurl' => $downloadurl,
            'isiomad' => $isiomad,
            'companycount' => $companycount,
            'updateurl' => $CFG->wwwroot . '/local/sm_estratoos_plugin/update.php',
            'warnings' => [],
        ];
    }

    /**
     * Define output structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the request was successful'),
            'currentversion' => new external_value(PARAM_INT, 'Current installed plugin version (YYYYMMDDXX format)'),
            'currentrelease' => new external_value(PARAM_TEXT, 'Current installed plugin release (e.g., 1.7.44)'),
            'updateavailable' => new external_value(PARAM_BOOL, 'Whether an update is available'),
            'latestversion' => new external_value(PARAM_INT, 'Latest available version (if checked)'),
            'latestrelease' => new external_value(PARAM_TEXT, 'Latest available release (if checked)'),
            'downloadurl' => new external_value(PARAM_URL, 'Download URL for the latest version'),
            'isiomad' => new external_value(PARAM_BOOL, 'Whether IOMAD is installed'),
            'companycount' => new external_value(PARAM_INT, 'Number of IOMAD companies'),
            'updateurl' => new external_value(PARAM_URL, 'URL to perform the update'),
            'warnings' => new external_warnings(),
        ]);
    }
}
