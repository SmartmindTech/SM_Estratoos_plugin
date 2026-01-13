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

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom update checker for SmartMind plugin.
 *
 * Fetches update information directly from GitHub update.xml.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_checker {

    /** @var string URL to the update.xml file */
    const UPDATE_URL = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';

    /** @var string Config key for cached update info */
    const CONFIG_UPDATE_INFO = 'cached_update_info';

    /** @var string Config key for last check time */
    const CONFIG_LAST_CHECK = 'last_update_check';

    /**
     * Check for updates and return info if available.
     *
     * @param bool $force Force fetch even if recently checked.
     * @return object|null Update info object or null if up to date.
     */
    public static function check(bool $force = false): ?object {
        // Get current installed version.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        if (!$plugin) {
            debugging('SmartMind update check: Plugin info not found', DEBUG_DEVELOPER);
            return null;
        }
        $currentversion = $plugin->versiondisk;

        // Get update check interval (default 60 seconds).
        $interval = get_config('local_sm_estratoos_plugin', 'update_check_interval');
        if ($interval === false) {
            $interval = 60;
        }

        // Check if we need to fetch.
        $lastcheck = get_config('local_sm_estratoos_plugin', self::CONFIG_LAST_CHECK);
        $needsfetch = $force || ($lastcheck === false) || (time() - $lastcheck >= $interval);

        // Always try to get cached info first.
        $cached = get_config('local_sm_estratoos_plugin', self::CONFIG_UPDATE_INFO);
        $updateinfo = $cached ? json_decode($cached, true) : null;

        if ($needsfetch) {
            // Fetch from GitHub.
            $fetchedinfo = self::fetch_update_info();
            if ($fetchedinfo) {
                // Cache the result.
                set_config(self::CONFIG_UPDATE_INFO, json_encode($fetchedinfo), 'local_sm_estratoos_plugin');
                $updateinfo = $fetchedinfo;
                debugging('SmartMind update check: Fetched version ' . $fetchedinfo['version'] . ' from GitHub', DEBUG_DEVELOPER);
            } else {
                // Fetch failed, keep using cached data if available.
                debugging('SmartMind update check: Fetch failed, using cached data', DEBUG_DEVELOPER);
            }
            set_config(self::CONFIG_LAST_CHECK, time(), 'local_sm_estratoos_plugin');
        }

        if (!$updateinfo) {
            debugging('SmartMind update check: No update info available', DEBUG_DEVELOPER);
            return null;
        }

        debugging('SmartMind update check: Current=' . $currentversion . ', Remote=' . $updateinfo['version'], DEBUG_DEVELOPER);

        // Compare versions.
        if ($updateinfo['version'] > $currentversion) {
            $result = new \stdClass();
            $result->version = $updateinfo['version'];
            $result->release = $updateinfo['release'];
            $result->download = $updateinfo['download'] ?? '';
            $result->url = $updateinfo['url'] ?? '';
            $result->currentversion = $currentversion;
            $result->currentrelease = $plugin->release ?? $currentversion;

            // Send notification to current admin if not already notified.
            self::send_notification_if_needed($result);

            return $result;
        }

        return null;
    }

    /**
     * Fetch update information from GitHub.
     *
     * @return array|null Update info array or null on failure.
     */
    public static function fetch_update_info(): ?array {
        $curl = new \curl(['cache' => false]);
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => false,
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_HTTPHEADER' => [
                'Cache-Control: no-cache, no-store, must-revalidate',
                'Pragma: no-cache',
            ],
        ]);

        // Add cache-busting parameter to bypass GitHub CDN cache.
        $url = self::UPDATE_URL . '?t=' . time();
        $content = $curl->get($url);

        if ($curl->get_errno() || empty($content)) {
            debugging('SmartMind update check failed: ' . $curl->error, DEBUG_DEVELOPER);
            return null;
        }

        // Parse XML.
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false || !isset($xml->update)) {
            debugging('SmartMind update check: Failed to parse update.xml', DEBUG_DEVELOPER);
            return null;
        }

        $update = $xml->update;

        return [
            'version' => (int) $update->version,
            'release' => (string) $update->release,
            'download' => (string) $update->download,
            'url' => (string) $update->url,
            'maturity' => (int) $update->maturity,
        ];
    }

    /**
     * Get the current installed version info.
     *
     * @return object|null Plugin info or null.
     */
    public static function get_current_version(): ?object {
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        if (!$plugin) {
            return null;
        }

        $result = new \stdClass();
        $result->version = $plugin->versiondisk;
        $result->release = $plugin->release ?? $plugin->versiondisk;
        return $result;
    }

    /**
     * Send Moodle notifications to ALL site administrators if not already notified about this version.
     *
     * Uses the same plugin config key as the scheduled task to stay synchronized.
     *
     * @param object $updateinfo Update info object with version, release, etc.
     */
    public static function send_notification_if_needed(object $updateinfo): void {
        global $USER, $CFG;

        // Only trigger from site administrators.
        if (!is_siteadmin($USER)) {
            return;
        }

        // Use the SAME config key as the scheduled task to stay synchronized.
        $lastnotified = get_config('local_sm_estratoos_plugin', 'last_notified_version');
        if ($lastnotified == $updateinfo->version) {
            // Already notified all admins about this version.
            return;
        }

        // New version - notify ALL site administrators (same as scheduled task).
        $admins = get_admins();
        if (empty($admins)) {
            return;
        }

        // Prepare the message.
        $subject = get_string('updateavailable_subject', 'local_sm_estratoos_plugin', $updateinfo->release);

        $messagedata = new \stdClass();
        $messagedata->currentversion = $updateinfo->currentrelease;
        $messagedata->newversion = $updateinfo->release;
        // Point to Moodle's native plugin updater instead of custom update.php.
        $messagedata->updateurl = $CFG->wwwroot . '/admin/plugins.php';

        $fullmessage = get_string('updateavailable_message', 'local_sm_estratoos_plugin', $messagedata);
        $htmlmessage = get_string('updateavailable_message_html', 'local_sm_estratoos_plugin', $messagedata);

        // Get the noreply user for sending.
        $noreplyuser = \core_user::get_noreply_user();

        $notifiedcount = 0;
        foreach ($admins as $admin) {
            // Create and send message.
            $message = new \core\message\message();
            $message->component = 'local_sm_estratoos_plugin';
            $message->name = 'updatenotification';
            $message->userfrom = $noreplyuser;
            $message->userto = $admin;
            $message->subject = $subject;
            $message->fullmessage = $fullmessage;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $htmlmessage;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = new \moodle_url('/admin/plugins.php');
            $message->contexturlname = get_string('pluginsoverview', 'core_admin');

            try {
                message_send($message);
                $notifiedcount++;
            } catch (\Exception $e) {
                debugging('SmartMind update check: Failed to notify ' . $admin->username . ' - ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Mark this version as notified (same key as scheduled task).
        set_config('last_notified_version', $updateinfo->version, 'local_sm_estratoos_plugin');
        debugging('SmartMind update check: Notified ' . $notifiedcount . ' administrator(s)', DEBUG_DEVELOPER);
    }
}
