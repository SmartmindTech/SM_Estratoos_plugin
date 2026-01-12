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

namespace local_sm_estratoos_plugin\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to check for plugin updates and notify site administrators.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_for_updates extends \core\task\scheduled_task {

    /** @var string Config key for last notified version */
    const CONFIG_LAST_NOTIFIED = 'last_notified_version';

    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:checkforupdates', 'local_sm_estratoos_plugin');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG;

        mtrace('Checking for SmartMind - Estratoos Plugin updates...');

        // Get the update server URL from plugin config.
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        if (!$plugin) {
            mtrace('Could not get plugin info.');
            return;
        }

        $currentversion = $plugin->versiondisk;
        $currentrelease = $plugin->release ?? 'unknown';

        mtrace("Current installed version: {$currentversion} ({$currentrelease})");

        // Fetch update.xml from GitHub.
        $updateurl = 'https://raw.githubusercontent.com/SmartmindTech/SM_Estratoos_plugin/main/update.xml';

        $updateinfo = $this->fetch_update_info($updateurl);
        if (!$updateinfo) {
            mtrace('Could not fetch or parse update information.');
            return;
        }

        mtrace("Latest available version: {$updateinfo['version']} ({$updateinfo['release']})");

        // Compare versions.
        if ($updateinfo['version'] <= $currentversion) {
            mtrace('Plugin is up to date. No notification needed.');
            return;
        }

        // Check if we already notified about this version.
        $lastnotified = get_config('local_sm_estratoos_plugin', self::CONFIG_LAST_NOTIFIED);
        if ($lastnotified == $updateinfo['version']) {
            mtrace('Already notified about this version. Skipping.');
            return;
        }

        // New version available - notify all site administrators.
        $this->notify_admins($updateinfo, $currentrelease);

        // Store the version we notified about.
        set_config(self::CONFIG_LAST_NOTIFIED, $updateinfo['version'], 'local_sm_estratoos_plugin');

        mtrace('Notifications sent to all site administrators.');
    }

    /**
     * Fetch and parse update information from the update server.
     *
     * @param string $url The URL to fetch.
     * @return array|null Update info array or null on failure.
     */
    private function fetch_update_info(string $url): ?array {
        // Use Moodle's curl wrapper.
        $curl = new \curl(['cache' => false]);
        $curl->setopt([
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_SSL_VERIFYPEER' => true,
        ]);

        $content = $curl->get($url);

        if ($curl->get_errno() || empty($content)) {
            mtrace('Failed to fetch update.xml: ' . $curl->error);
            return null;
        }

        // Parse XML.
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            mtrace('Failed to parse update.xml');
            return null;
        }

        // Extract update information.
        if (!isset($xml->update)) {
            mtrace('No update element found in XML');
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
     * Send notifications to all site administrators.
     *
     * @param array $updateinfo Update information.
     * @param string $currentrelease Current installed release string.
     */
    private function notify_admins(array $updateinfo, string $currentrelease): void {
        global $CFG;

        // Get all site administrators.
        $admins = get_admins();

        if (empty($admins)) {
            mtrace('No administrators found to notify.');
            return;
        }

        // Prepare the message.
        $subject = get_string('updateavailable_subject', 'local_sm_estratoos_plugin', $updateinfo['release']);

        $messagedata = new \stdClass();
        $messagedata->currentversion = $currentrelease;
        $messagedata->newversion = $updateinfo['release'];
        $messagedata->updateurl = $CFG->wwwroot . '/local/sm_estratoos_plugin/update.php';

        $fullmessage = get_string('updateavailable_message', 'local_sm_estratoos_plugin', $messagedata);
        $htmlmessage = get_string('updateavailable_message_html', 'local_sm_estratoos_plugin', $messagedata);

        // Get the noreply user for sending.
        $noreplyuser = \core_user::get_noreply_user();

        $notifiedcount = 0;
        foreach ($admins as $admin) {
            // Create message object.
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
            $message->contexturl = new \moodle_url('/local/sm_estratoos_plugin/update.php');
            $message->contexturlname = get_string('updateplugin', 'local_sm_estratoos_plugin');

            try {
                message_send($message);
                $notifiedcount++;
                mtrace("Notified admin: {$admin->username}");
            } catch (\Exception $e) {
                mtrace("Failed to notify {$admin->username}: " . $e->getMessage());
            }
        }

        mtrace("Notified {$notifiedcount} administrator(s).");
    }
}
