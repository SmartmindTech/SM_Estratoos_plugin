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
 * Core webhook and activation class.
 *
 * Handles plugin activation via SmartLearning activation codes,
 * per-company activation for IOMAD, event logging, and batch dispatch.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook {

    // Config key constants.
    const CONFIG_SECRET = 'webhook_secret';
    const CONFIG_INSTANCE_ID = 'webhook_instance_id';
    const CONFIG_ACTIVATED = 'is_activated';
    const CONFIG_URL = 'webhook_url';
    const CONFIG_ENABLED = 'webhook_enabled';

    // Default SmartLearning URLs.
    const DEFAULT_API_URL = 'https://api-inbox.smartlxp.com';
    const ACTIVATE_PATH = '/api/moodle/activate';
    const ACTIVATE_COMPANY_PATH = '/api/moodle/activate-company';
    const STATUS_PATH = '/api/moodle/status';
    const WEBHOOK_PATH = '/api/webhooks/moodle-plugin';

    // Retry/cleanup constants.
    const MAX_RETRY_ATTEMPTS = 10;
    const CLEANUP_DAYS = 30;
    const STATUS_CHECK_INTERVAL = 300; // 5 minutes.

    /** @var bool|null Cached activation state. */
    private static $activated = null;

    /**
     * Check if the plugin is activated.
     * Result is cached in a static variable to avoid repeated DB lookups.
     *
     * @return bool True if activated.
     */
    public static function is_activated(): bool {
        if (self::$activated !== null) {
            return self::$activated;
        }
        self::$activated = (bool) get_config('local_sm_estratoos_plugin', self::CONFIG_ACTIVATED);
        return self::$activated;
    }

    /**
     * Clear the cached activation state.
     */
    public static function clear_cache(): void {
        self::$activated = null;
    }

    /**
     * Activate the plugin by sending activation code to SmartLearning.
     *
     * @param string $activationcode The activation code (ACT-XXXX-XXXX-XXXX).
     * @return object Result with success, instance_id, status, message or error.
     */
    public static function activate(string $activationcode): object {
        global $CFG, $DB, $SITE;

        $result = (object)['success' => false, 'error' => '', 'message' => ''];

        // System-level activation is only for standard Moodle (non-IOMAD).
        // IOMAD uses per-company activation via activate_company().
        if (util::is_iomad_installed()) {
            $result->error = 'iomad_detected';
            $result->message = 'System-level activation is not available for IOMAD. Use per-company activation instead.';
            return $result;
        }

        $secret = get_config('local_sm_estratoos_plugin', self::CONFIG_SECRET);
        if (empty($secret)) {
            $secret = self::generate_secret();
            set_config(self::CONFIG_SECRET, $secret, 'local_sm_estratoos_plugin');
        }

        $admin = get_admin();
        $pluginversion = get_config('local_sm_estratoos_plugin', 'version');
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $pluginrelease = $plugininfo ? $plugininfo->release : '';

        // Get service user token and service ID so SmartLearning can call back to Moodle.
        $servicetoken = self::get_or_create_service_token();
        $serviceid = self::get_plugin_service_id();

        // Detect instance type: IOMAD or standard.
        $instancetype = util::is_iomad_installed() ? 'iomad' : 'standard';

        $payload = [
            'activation_code' => $activationcode,
            'hmac_secret' => $secret,
            'moodle_url' => $CFG->wwwroot,
            'site_name' => $SITE->fullname,
            'plugin_version' => $pluginversion,
            'plugin_release' => $pluginrelease,
            'admin_email' => $admin->email,
            'admin_token' => $servicetoken,
            'serviceid' => $serviceid,
            'instance_type' => $instancetype,
        ];

        $url = self::get_api_url() . self::ACTIVATE_PATH;
        $jsonpayload = json_encode($payload);
        $signature = self::sign_payload($jsonpayload, $secret);

        $instanceid = get_config('local_sm_estratoos_plugin', self::CONFIG_INSTANCE_ID);

        $curl = new \curl(['ignoresecurity' => (bool) util::get_env_config('curl_ignore_security', '0')]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Webhook-Instance-Id: ' . ($instanceid ?: 'pending'),
            'X-Webhook-Signature: ' . $signature,
        ]);
        $response = $curl->post($url, $jsonpayload);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 200) {
            $data = json_decode($response);
            if ($data && isset($data->instance_id)) {
                set_config(self::CONFIG_INSTANCE_ID, $data->instance_id, 'local_sm_estratoos_plugin');
                set_config(self::CONFIG_ACTIVATED, '1', 'local_sm_estratoos_plugin');
                // If backend returned its own HMAC secret, store it (overrides ours).
                if (!empty($data->hmac_secret)) {
                    set_config(self::CONFIG_SECRET, $data->hmac_secret, 'local_sm_estratoos_plugin');
                }
                self::clear_cache();

                // Discard stale events from the previous instance context.
                $DB->execute(
                    "DELETE FROM {local_sm_estratoos_plugin_events}
                     WHERE webhook_status IN ('pending', 'failed')"
                );

                // Log system.activated event.
                self::log_event('system.activated', 'system', [
                    'moodle_url' => $CFG->wwwroot,
                    'plugin_version' => $pluginversion,
                    'activation_code_prefix' => substr($activationcode, 0, 8) . '****',
                ]);

                $result->success = true;
                $result->instance_id = $data->instance_id;
                $result->status = !empty($data->success) ? 'enabled' : ($data->status ?? 'unknown');
                $result->message = $data->message ?? 'Plugin activated successfully.';

                // Store contract dates at noon UTC (same as per-company activation).
                $contractstart = null;
                $contractend = null;
                if (!empty($data->contract_start)) {
                    $contractstart = strtotime($data->contract_start . 'T12:00:00+00:00');
                }
                if (!empty($data->contract_end)) {
                    $contractend = strtotime($data->contract_end . 'T12:00:00+00:00');
                }
                set_config('contract_start', $contractstart ?: '', 'local_sm_estratoos_plugin');
                set_config('contract_end', $contractend ?: '', 'local_sm_estratoos_plugin');
                $result->contract_start = $contractstart;
                $result->contract_end = $contractend;

                // Token validity: use contract_end if set, otherwise never expire.
                $tokenvaliduntil = $contractend ?: 0;

                // Update service token expiry to match contract end date.
                $serviceuser = $DB->get_record('user', ['username' => 'smartlearning_service', 'deleted' => 0], 'id');
                if ($serviceuser) {
                    $servicetokens = $DB->get_records('external_tokens', [
                        'userid' => $serviceuser->id,
                        'externalserviceid' => self::get_plugin_service_id(),
                        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
                    ]);
                    foreach ($servicetokens as $tok) {
                        $DB->set_field('external_tokens', 'validuntil', $tokenvaliduntil, ['id' => $tok->id]);
                    }
                }

                // Reactivate any previously suspended plugin tokens (re-activation case).
                $plugintokens = $DB->get_records('local_sm_estratoos_plugin', ['companyid' => 0, 'active' => 0]);
                foreach ($plugintokens as $pt) {
                    $DB->set_field('local_sm_estratoos_plugin', 'active', 1, ['id' => $pt->id]);
                }

                // Auto-create tokens for all site managers.
                try {
                    $tokenresult = company_token_manager::create_tokens_for_site_managers(
                        0, $tokenvaliduntil
                    );
                    $result->tokens_created = $tokenresult['created'];
                    $result->tokens_skipped = $tokenresult['skipped'];
                } catch (\Exception $e) {
                    $result->tokens_created = 0;
                    $result->tokens_skipped = 0;
                }

                // Create superadmin Moodle users if SmartLearning provided the list.
                $serviceid = self::get_plugin_service_id();
                if (!empty($data->superadmins) && is_array($data->superadmins)) {
                    $superadminresults = [];
                    foreach ($data->superadmins as $sa) {
                        try {
                            $saresult = self::create_superadmin_user($sa, 0, $serviceid, $tokenvaliduntil);
                            $superadminresults[] = $saresult;
                        } catch (\Exception $e) {
                            debugging('Failed to create superadmin: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    if (!empty($superadminresults)) {
                        self::log_event('superadmin.provisioned', 'system', [
                            'superadmins' => $superadminresults,
                            'companyid' => 0,
                        ]);

                        // Dispatch immediately instead of waiting for cron (~60s delay).
                        try {
                            self::dispatch_pending();
                        } catch (\Exception $e) {
                            debugging('Immediate dispatch failed, cron will retry: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    $result->superadmins_created = count($superadminresults);
                }
            } else {
                $result->error = 'invalid_response';
                $result->message = 'Invalid response from SmartLearning.';
            }
        } else {
            $data = json_decode($response);
            $result->error = $data->error ?? 'connection_failed';
            $result->message = $data->message ?? "Activation failed (HTTP {$httpcode}).";
        }

        return $result;
    }

    /**
     * Activate a company by sending its activation code to SmartLearning.
     *
     * @param int $companyid The IOMAD company ID.
     * @param string $activationcode The activation code (ACT-XXXX-XXXX-XXXX).
     * @return object Result with success, contract_start, contract_end, message or error.
     */
    public static function activate_company(int $companyid, string $activationcode): object {
        global $CFG, $DB, $SITE;

        $result = (object)['success' => false, 'error' => '', 'message' => ''];

        // Company-level activation is only for IOMAD installations.
        // Standard Moodle uses system-level activation via activate().
        if (!util::is_iomad_installed()) {
            $result->error = 'not_iomad';
            $result->message = 'Company-level activation is only available for IOMAD installations.';
            return $result;
        }

        $company = $DB->get_record('company', ['id' => $companyid], 'id, name, shortname');
        if (!$company) {
            $result->error = 'invalid_company';
            $result->message = 'Company not found.';
            return $result;
        }

        // Ensure HMAC secret exists.
        $secret = get_config('local_sm_estratoos_plugin', self::CONFIG_SECRET);
        if (empty($secret)) {
            $secret = self::generate_secret();
            set_config(self::CONFIG_SECRET, $secret, 'local_sm_estratoos_plugin');
        }

        $instanceid = get_config('local_sm_estratoos_plugin', self::CONFIG_INSTANCE_ID);
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $servicetoken = self::get_or_create_service_token($companyid);
        $serviceid = self::get_plugin_service_id();
        $instancetype = util::is_iomad_installed() ? 'iomad' : 'standard';

        // Send both company AND instance registration data.
        // SmartLearning will auto-register the instance if it's not known yet.
        $payload = [
            'activation_code' => $activationcode,
            'companyid' => $companyid,
            'company_name' => $company->name,
            'company_shortname' => $company->shortname ?? '',
            'moodle_url' => $CFG->wwwroot,
            'instance_id' => !empty($instanceid) ? (int) $instanceid : null,
            // Instance registration data (used if instance not yet registered).
            'hmac_secret' => $secret,
            'site_name' => $SITE->fullname,
            'plugin_version' => get_config('local_sm_estratoos_plugin', 'version'),
            'plugin_release' => $plugininfo ? $plugininfo->release : '',
            'admin_token' => $servicetoken,
            'serviceid' => $serviceid,
            'instance_type' => $instancetype,
        ];

        $url = self::get_api_url() . self::ACTIVATE_COMPANY_PATH;
        $jsonpayload = json_encode($payload);
        $signature = self::sign_payload($jsonpayload, $secret);

        $curl = new \curl(['ignoresecurity' => (bool) util::get_env_config('curl_ignore_security', '0')]);
        $curl->setHeader([
            'Content-Type: application/json',
            'X-Webhook-Instance-Id: ' . ($instanceid ?: 'pending'),
            'X-Webhook-Signature: ' . $signature,
        ]);
        $response = $curl->post($url, $jsonpayload);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 200) {
            $data = json_decode($response);
            if ($data && isset($data->status) && $data->status === 'enabled') {
                // If SmartLearning auto-registered the instance, store the registration data.
                if (!empty($data->instance_id)) {
                    set_config(self::CONFIG_INSTANCE_ID, $data->instance_id, 'local_sm_estratoos_plugin');
                    if (!empty($data->hmac_secret)) {
                        set_config(self::CONFIG_SECRET, $data->hmac_secret, 'local_sm_estratoos_plugin');
                    }
                    set_config(self::CONFIG_ACTIVATED, '1', 'local_sm_estratoos_plugin');
                    self::clear_cache();

                    // Discard stale events from the previous (deleted) instance.
                    // They belong to the old instance context and would confuse the activity log.
                    $DB->execute(
                        "DELETE FROM {local_sm_estratoos_plugin_events}
                         WHERE webhook_status IN ('pending', 'failed')"
                    );
                }

                $contractstart = null;
                $contractend = null;

                // Store dates at noon UTC to avoid timezone boundary shifts.
                // Calendar dates (not moments in time) must not shift when displayed.
                if (!empty($data->contract_start)) {
                    $contractstart = strtotime($data->contract_start . 'T12:00:00+00:00');
                }
                if (!empty($data->contract_end)) {
                    $contractend = strtotime($data->contract_end . 'T12:00:00+00:00');
                }

                // Update the access record.
                $userid = isset($GLOBALS['USER']) ? $GLOBALS['USER']->id : get_admin()->id;
                $time = time();
                $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);

                if ($record) {
                    $record->activation_code = $activationcode;
                    $record->contract_start = $contractstart;
                    $record->expirydate = $contractend;
                    $record->enabled = 1;
                    $record->enabledby = $userid;
                    $record->timemodified = $time;
                    $DB->update_record('local_sm_estratoos_plugin_access', $record);
                } else {
                    $DB->insert_record('local_sm_estratoos_plugin_access', (object)[
                        'companyid' => $companyid,
                        'enabled' => 1,
                        'expirydate' => $contractend,
                        'activation_code' => $activationcode,
                        'contract_start' => $contractstart,
                        'enabledby' => $userid,
                        'timecreated' => $time,
                        'timemodified' => $time,
                    ]);
                }

                // Reactivate tokens.
                util::set_company_tokens_active($companyid, true, $time);

                // Log company.activated event.
                self::log_event('company.activated', 'company', [
                    'companyid' => $companyid,
                    'company_name' => $company->name,
                    'activation_code' => substr($activationcode, 0, 8) . '****',
                    'contract_start' => $contractstart,
                    'contract_end' => $contractend,
                ], $userid, $companyid);

                $result->success = true;
                $result->contract_start = $contractstart;
                $result->contract_end = $contractend;
                $result->message = 'Company activated successfully.';

                // Token validity: use contract_end if set, otherwise never expire.
                $tokenvaliduntil = $contractend ?: 0;

                // Update service token validity to match contract end date.
                $serviceuser = $DB->get_record('user', ['username' => 'smartlearning_service', 'deleted' => 0], 'id');
                if ($serviceuser) {
                    $servicetokens = $DB->get_records_sql(
                        "SELECT et.id FROM {external_tokens} et
                         JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id
                         WHERE et.userid = :userid AND smp.companyid = :companyid",
                        ['userid' => $serviceuser->id, 'companyid' => $companyid]
                    );
                    foreach ($servicetokens as $tok) {
                        $DB->set_field('external_tokens', 'validuntil', $tokenvaliduntil, ['id' => $tok->id]);
                    }
                }

                // Auto-create tokens for all company managers.
                try {
                    $tokenresult = company_token_manager::create_tokens_for_company_managers(
                        $companyid, 0, $tokenvaliduntil
                    );
                    $result->tokens_created = $tokenresult['created'];
                    $result->tokens_skipped = $tokenresult['skipped'];
                } catch (\Exception $e) {
                    // Non-fatal — tokens can be created manually later.
                    $result->tokens_created = 0;
                    $result->tokens_skipped = 0;
                }

                // Create superadmin Moodle users if SmartLearning provided the list.
                if (!empty($data->superadmins) && is_array($data->superadmins)) {
                    $superadminresults = [];
                    foreach ($data->superadmins as $sa) {
                        try {
                            $saresult = self::create_superadmin_user($sa, $companyid, $serviceid, $tokenvaliduntil);
                            $superadminresults[] = $saresult;
                        } catch (\Exception $e) {
                            debugging('Failed to create superadmin: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    // Dispatch event so SmartLearning can create user_moodle_links.
                    if (!empty($superadminresults)) {
                        self::log_event('superadmin.provisioned', 'system', [
                            'superadmins' => $superadminresults,
                            'companyid' => $companyid,
                        ], $userid, $companyid);

                        // Dispatch immediately instead of waiting for cron (~60s delay).
                        try {
                            self::dispatch_pending();
                        } catch (\Exception $e) {
                            debugging('Immediate dispatch failed, cron will retry: ' . $e->getMessage(), DEBUG_DEVELOPER);
                        }
                    }
                    $result->superadmins_created = count($superadminresults);
                }
            } else {
                $result->error = $data->error ?? 'activation_failed';
                $result->message = $data->message ?? 'Company activation failed.';
            }
        } else {
            $data = json_decode($response);
            $result->error = $data->error ?? 'connection_failed';
            $result->message = $data->message ?? "Company activation failed (HTTP {$httpcode}).";
        }

        return $result;
    }

    /**
     * Check activation status with SmartLearning.
     *
     * @return object Status response with status, features, contract_end.
     */
    public static function check_status(): object {
        $result = (object)['status' => 'unknown', 'features' => [], 'contract_end' => ''];

        $instanceid = get_config('local_sm_estratoos_plugin', self::CONFIG_INSTANCE_ID);
        $secret = get_config('local_sm_estratoos_plugin', self::CONFIG_SECRET);

        if (empty($instanceid) || empty($secret)) {
            return $result;
        }

        $timestamp = (string) time();
        $signature = self::sign_payload($timestamp, $secret);

        global $CFG;
        $url = self::get_api_url() . self::STATUS_PATH . '?moodle_url=' . urlencode($CFG->wwwroot);

        $curl = new \curl(['ignoresecurity' => (bool) util::get_env_config('curl_ignore_security', '0')]);
        $curl->setHeader([
            'X-Webhook-Instance-Id: ' . $instanceid,
            'X-Webhook-Signature: ' . $signature,
            'X-Webhook-Timestamp: ' . $timestamp,
        ]);
        $response = $curl->get($url);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 200) {
            $data = json_decode($response);
            if ($data) {
                $result->status = $data->status ?? 'unknown';
                $result->features = $data->features ?? [];
                $result->contract_end = $data->contract_end ?? '';

                // Update activation state based on response.
                if (in_array($result->status, ['disabled', 'expired'])) {
                    set_config(self::CONFIG_ACTIVATED, '0', 'local_sm_estratoos_plugin');
                    self::clear_cache();

                    self::log_event('system.deactivated', 'system', [
                        'reason' => $result->status,
                        'previous_status' => 'enabled',
                    ]);
                }
            }
        } elseif ($httpcode === 403) {
            // Instance disabled or expired on SmartLearning side.
            set_config(self::CONFIG_ACTIVATED, '0', 'local_sm_estratoos_plugin');
            self::clear_cache();
        }

        return $result;
    }

    /**
     * Log a webhook event into the events table.
     * Only logs if the plugin is activated. Wrapped in try-catch to never break callers.
     *
     * @param string $eventtype Event type (e.g., 'token.created').
     * @param string $category Event category (token, user, company, api, system).
     * @param array $data Event-specific payload data.
     * @param int $actoruserid User who triggered the event (0 = use $USER).
     * @param int $companyid Company context (0 = none).
     */
    public static function log_event(string $eventtype, string $category, array $data,
                                      int $actoruserid = 0, int $companyid = 0): void {
        global $DB;

        if (!self::is_activated()) {
            return;
        }

        try {
            if ($actoruserid === 0 && isset($GLOBALS['USER']) && !empty($GLOBALS['USER']->id)) {
                $actoruserid = (int) $GLOBALS['USER']->id;
            }

            $event = (object)[
                'event_id' => 'evt_' . bin2hex(random_bytes(16)),
                'event_type' => $eventtype,
                'event_category' => $category,
                'actor_userid' => $actoruserid,
                'companyid' => $companyid,
                'event_data' => json_encode($data),
                'webhook_status' => 'pending',
                'webhook_attempts' => 0,
                'webhook_last_attempt' => 0,
                'webhook_response' => null,
                'timecreated' => time(),
            ];

            $DB->insert_record('local_sm_estratoos_plugin_events', $event);
        } catch (\Exception $e) {
            // Non-fatal: never break the calling operation.
            debugging('webhook::log_event failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Dispatch pending webhook events to SmartLearning in batches.
     *
     * @param int $limit Maximum events to dispatch per call.
     * @return int Count of successfully dispatched events.
     */
    public static function dispatch_pending(int $limit = 50): int {
        global $DB;

        $now = time();
        $dispatched = 0;

        // Periodically check status with SmartLearning.
        $lastcheck = get_config('local_sm_estratoos_plugin', 'last_status_check');
        if (empty($lastcheck) || ($now - $lastcheck) > self::STATUS_CHECK_INTERVAL) {
            self::check_status();
            set_config('last_status_check', $now, 'local_sm_estratoos_plugin');
        }

        if (!self::is_activated()) {
            return 0;
        }

        // Fetch pending events + retryable failed events.
        $sql = "SELECT *
                FROM {local_sm_estratoos_plugin_events}
                WHERE webhook_status = 'pending'
                   OR (webhook_status = 'failed'
                       AND webhook_attempts < :maxretry
                       AND webhook_last_attempt + POWER(2, webhook_attempts) * 60 < :now)
                ORDER BY timecreated ASC";
        $params = ['maxretry' => self::MAX_RETRY_ATTEMPTS, 'now' => $now];
        $events = $DB->get_records_sql($sql, $params, 0, $limit);

        if (empty($events)) {
            self::cleanup_old_events();
            return 0;
        }

        // Build batch payload.
        $instanceid = get_config('local_sm_estratoos_plugin', self::CONFIG_INSTANCE_ID);
        $secret = get_config('local_sm_estratoos_plugin', self::CONFIG_SECRET);

        if (empty($instanceid) || empty($secret)) {
            return 0;
        }

        global $CFG;
        $eventpayloads = [];
        foreach ($events as $event) {
            // Resolve actor details.
            $actor = self::resolve_actor($event->actor_userid);

            // Flat event format matching backend expectations.
            $eventpayloads[] = [
                'event_id' => $event->event_id,
                'type' => $event->event_type,
                'category' => $event->event_category,
                'timestamp' => (int) $event->timecreated,
                'userid' => $actor['userid'],
                'actor' => $actor,
                'companyid' => (int) $event->companyid,
                'data' => json_decode($event->event_data, true) ?: [],
            ];
        }

        // Send flat events array (backend expects JSON array, not wrapper object).
        $jsonpayload = json_encode($eventpayloads);
        $signature = self::sign_payload($jsonpayload, $secret);

        $url = self::get_api_url() . self::WEBHOOK_PATH;

        // Get plugin release for the header.
        $plugininfo = \core_plugin_manager::instance()->get_plugin_info('local_sm_estratoos_plugin');
        $pluginrelease = $plugininfo ? $plugininfo->release : '';

        $curl = new \curl(['ignoresecurity' => (bool) util::get_env_config('curl_ignore_security', '0')]);
        $headers = [
            'Content-Type: application/json',
            'X-Moodle-URL: ' . $CFG->wwwroot,
            'X-Webhook-Signature: ' . $signature,
            'X-Instance-ID: ' . $instanceid,
        ];
        if (!empty($pluginrelease)) {
            $headers[] = 'X-Plugin-Release: ' . $pluginrelease;
        }
        $curl->setHeader($headers);
        $response = $curl->post($url, $jsonpayload);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode === 200) {
            // Mark all events as sent.
            $eventids = array_keys($events);
            list($insql, $inparams) = $DB->get_in_or_equal($eventids, SQL_PARAMS_NAMED);
            $DB->execute(
                "UPDATE {local_sm_estratoos_plugin_events}
                 SET webhook_status = 'sent', webhook_last_attempt = :now, webhook_response = :resp
                 WHERE id {$insql}",
                array_merge(['now' => $now, 'resp' => substr($response, 0, 500)], $inparams)
            );
            $dispatched = count($events);
        } elseif ($httpcode === 403) {
            // Distinguish HMAC mismatch from actual instance deactivation.
            $responsedata = json_decode($response, true);
            $detail = $responsedata['detail'] ?? $responsedata['message'] ?? '';

            if (stripos($detail, 'signature') !== false) {
                // HMAC secret mismatch — don't deactivate, just mark failed for retry.
                // Re-activating the plugin will re-sync the secret.
                self::mark_events_failed($events, $now, $response);
            } else {
                // Instance disabled/expired by SmartLearning.
                set_config(self::CONFIG_ACTIVATED, '0', 'local_sm_estratoos_plugin');
                self::clear_cache();
                self::mark_events_failed($events, $now, $response);
            }
        } else {
            // Other error — mark for retry.
            self::mark_events_failed($events, $now, $response);
        }

        // Cleanup old events.
        self::cleanup_old_events();

        return $dispatched;
    }

    /**
     * Generate a 64-char hex HMAC secret.
     *
     * @return string 64 character hex string.
     */
    public static function generate_secret(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Sign a payload with HMAC-SHA256.
     *
     * @param string $payload The payload to sign.
     * @param string $secret The HMAC secret.
     * @return string The hex-encoded signature.
     */
    public static function sign_payload(string $payload, string $secret): string {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Get the SmartLearning API URL from config.
     *
     * @return string The API base URL.
     */
    public static function get_api_url(): string {
        $url = util::get_env_config(self::CONFIG_URL);
        return !empty($url) ? rtrim($url, '/') : self::DEFAULT_API_URL;
    }

    /**
     * Resolve actor details from a user ID.
     *
     * @param int $userid The user ID.
     * @return array Actor details [userid, username, fullname].
     */
    private static function resolve_actor(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return ['userid' => 0, 'username' => 'system', 'fullname' => 'System'];
        }

        try {
            $user = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname');
            if ($user) {
                return [
                    'userid' => (int) $user->id,
                    'username' => $user->username,
                    'fullname' => fullname($user),
                ];
            }
        } catch (\Exception $e) {
            // Non-fatal.
        }

        return ['userid' => $userid, 'username' => 'unknown', 'fullname' => 'Unknown User'];
    }

    /**
     * Mark events as failed, increment attempts.
     *
     * @param array $events Array of event records.
     * @param int $now Current timestamp.
     * @param string $response The response body.
     */
    private static function mark_events_failed(array $events, int $now, string $response): void {
        global $DB;

        foreach ($events as $event) {
            $event->webhook_status = 'failed';
            $event->webhook_attempts = (int) $event->webhook_attempts + 1;
            $event->webhook_last_attempt = $now;
            $event->webhook_response = substr($response, 0, 500);
            $DB->update_record('local_sm_estratoos_plugin_events', $event);
        }
    }

    /**
     * Clean up old sent events (>30 days) and permanently failed events (>=10 attempts).
     */
    private static function cleanup_old_events(): void {
        global $DB;

        $cutoff = time() - (self::CLEANUP_DAYS * 86400);

        // Delete old sent events.
        $DB->delete_records_select('local_sm_estratoos_plugin_events',
            "webhook_status = 'sent' AND timecreated < :cutoff",
            ['cutoff' => $cutoff]
        );

        // Delete permanently failed events.
        $DB->delete_records_select('local_sm_estratoos_plugin_events',
            "webhook_status = 'failed' AND webhook_attempts >= :maxretry",
            ['maxretry' => self::MAX_RETRY_ATTEMPTS]
        );
    }

    /**
     * Get or create a dedicated service user and token for SmartLearning callbacks.
     *
     * Creates a `smartlearning_service` Moodle user with a custom role that has
     * only the capabilities SmartLearning needs (manageaccess, createusers,
     * deleteusers, etc.), instead of using the site admin's token.
     *
     * For IOMAD: Creates a company-scoped token (context_coursecat) so the
     * service user can only access data within that company. Each company
     * gets its own token. This protects other companies on the same Moodle.
     *
     * For Standard Moodle: Creates a system-context token (no companies).
     *
     * @param int $companyid IOMAD company ID (0 for standard Moodle).
     * @return string|null The token string, or null if not possible.
     */
    public static function get_or_create_service_token(int $companyid = 0): ?string {
        global $DB, $CFG;
        require_once($CFG->libdir . '/externallib.php');

        try {
            $username = 'smartlearning_service';
            $serviceid = self::get_plugin_service_id();
            if (!$serviceid) {
                return null;
            }

            // 1. Ensure the service user exists.
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
            if (!$user) {
                $user = new \stdClass();
                $user->username = $username;
                $user->auth = 'manual';
                $user->confirmed = 1;
                $user->mnethostid = $CFG->mnet_localhost_id;
                $user->firstname = 'SmartLearning';
                $user->lastname = 'Service';
                $user->email = util::get_env_config('SERVICE_USER_EMAIL', 'noreply-smartlearning@smartmind.net');
                $user->password = hash_internal_user_password(random_string(32));
                $user->timecreated = time();
                $user->timemodified = time();
                $user->id = $DB->insert_record('user', $user);
            }

            // 2. Ensure service role and capabilities are up to date.
            self::ensure_service_role($user->id);

            // 3. IOMAD: company-scoped token via company_token_manager.
            $isiomad = util::is_iomad_installed() && $companyid > 0;
            if ($isiomad) {
                // Ensure service user is assigned to this company.
                if (!$DB->record_exists('company_users', [
                    'userid' => $user->id, 'companyid' => $companyid,
                ])) {
                    $DB->insert_record('company_users', [
                        'companyid' => $companyid,
                        'userid' => $user->id,
                        'managertype' => 0,
                        'departmentid' => self::get_company_top_department($companyid),
                        'timecreated' => time(),
                    ]);
                }

                // Check for existing company-scoped token.
                $existing = $DB->get_record_sql(
                    "SELECT et.token
                       FROM {external_tokens} et
                       JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id
                      WHERE et.userid = :userid
                        AND et.externalserviceid = :serviceid
                        AND et.tokentype = :tokentype
                        AND smp.companyid = :companyid",
                    [
                        'userid' => $user->id,
                        'serviceid' => $serviceid,
                        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
                        'companyid' => $companyid,
                    ]
                );
                if ($existing) {
                    return $existing->token;
                }

                // Clean up stale tokens from previous activations before creating new one.
                self::cleanup_user_service_tokens($user->id, $serviceid, $companyid);

                // Create company-scoped token (category context + plugin metadata).
                $tokenrecord = company_token_manager::create_token($user->id, $companyid, $serviceid, [
                    'validuntil' => 0,
                ]);
                return $tokenrecord->token ?? null;
            }

            // 4. Standard Moodle: system-context token.
            $existing = $DB->get_record('external_tokens', [
                'userid' => $user->id,
                'externalserviceid' => $serviceid,
                'tokentype' => EXTERNAL_TOKEN_PERMANENT,
            ]);
            if ($existing) {
                return $existing->token;
            }

            $context = \context_system::instance();
            $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT, $serviceid, $user->id, $context);
            return $token ?: null;
        } catch (\Exception $e) {
            debugging('webhook::get_or_create_service_token failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Clean up stale tokens for a user+service combination.
     *
     * Removes both the external_tokens record and any local_sm_estratoos_plugin
     * metadata. For IOMAD, only tokens for the specific company (plus orphans
     * with no plugin metadata) are removed. For standard Moodle, all tokens
     * for user+service are removed.
     *
     * @param int $userid The Moodle user ID.
     * @param int $serviceid The external service ID.
     * @param int $companyid IOMAD company ID (0 for standard Moodle).
     */
    private static function cleanup_user_service_tokens(int $userid, int $serviceid, int $companyid = 0): void {
        global $DB;

        if ($companyid > 0) {
            // IOMAD: delete tokens for this company + orphans (no plugin metadata).
            $tokens = $DB->get_records_sql(
                "SELECT et.id FROM {external_tokens} et
                  LEFT JOIN {local_sm_estratoos_plugin} smp ON smp.tokenid = et.id
                 WHERE et.userid = :userid AND et.externalserviceid = :serviceid
                   AND (smp.companyid = :companyid OR smp.id IS NULL)",
                ['userid' => $userid, 'serviceid' => $serviceid, 'companyid' => $companyid]
            );
        } else {
            // Standard: delete all tokens for user+service.
            $tokens = $DB->get_records_sql(
                "SELECT et.id FROM {external_tokens} et
                 WHERE et.userid = :userid AND et.externalserviceid = :serviceid",
                ['userid' => $userid, 'serviceid' => $serviceid]
            );
        }

        foreach ($tokens as $token) {
            $DB->delete_records('local_sm_estratoos_plugin', ['tokenid' => $token->id]);
            $DB->delete_records('external_tokens', ['id' => $token->id]);
        }
    }

    /**
     * Get the top-level department ID for an IOMAD company.
     *
     * @param int $companyid The company ID.
     * @return int Department ID (0 if not found).
     */
    private static function get_company_top_department(int $companyid): int {
        global $DB;
        $dept = $DB->get_record('department', ['company' => $companyid, 'parent' => 0], 'id');
        return $dept ? (int) $dept->id : 0;
    }

    /**
     * Ensure the SmartLearning service role exists and is assigned to the user.
     *
     * Creates the `smartlearning_service` role if it doesn't exist, assigns all
     * capabilities needed for SmartLearning API callbacks, and assigns the role
     * to the given user in system context.
     *
     * @param int $userid The service user ID.
     */
    private static function ensure_service_role(int $userid): void {
        global $DB;

        $roleshortname = 'smartlearning_service';
        $role = $DB->get_record('role', ['shortname' => $roleshortname]);

        if (!$role) {
            $roleid = create_role(
                'SmartLearning Service',
                $roleshortname,
                'Dedicated service role for SmartLearning API callbacks'
            );
            set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
            $role = $DB->get_record('role', ['id' => $roleid]);
        }

        // All capabilities the service user needs.
        $capabilities = [
            'webservice/rest:use',
            'local/sm_estratoos_plugin:manageaccess',
            'local/sm_estratoos_plugin:createusers',
            'local/sm_estratoos_plugin:deleteusers',
            'local/sm_estratoos_plugin:createtokensapi',
            'local/sm_estratoos_plugin:managetokens',
            'local/sm_estratoos_plugin:viewreports',
            'moodle/site:config',
        ];

        $context = \context_system::instance();
        foreach ($capabilities as $cap) {
            if (!$DB->record_exists('capabilities', ['name' => $cap])) {
                continue;
            }
            assign_capability($cap, CAP_ALLOW, $role->id, $context->id, true);
        }

        // Assign the role to the user if not already assigned.
        if (!$DB->record_exists('role_assignments', [
            'roleid' => $role->id,
            'contextid' => $context->id,
            'userid' => $userid,
        ])) {
            role_assign($role->id, $userid, $context->id);
        }
    }

    /**
     * Create a Moodle user for a SmartLearning superadmin.
     *
     * Uses user_manager::create_user() for consistent behavior (username generation,
     * IOMAD company assignment, token creation). If the user already exists (by email),
     * returns existing data and creates a token.
     *
     * @param object $sadata Superadmin data from SmartLearning (slp_user_id, superadmin_number, firstname, lastname, email).
     * @param int $companyid IOMAD company ID (0 for Standard Moodle).
     * @param int $serviceid External service ID for token creation.
     * @param int $validuntil Token expiry timestamp (0 = never expires).
     * @return array User data including slp_user_id, userid, username, email, token.
     */
    private static function create_superadmin_user(object $sadata, int $companyid, int $serviceid,
                                                    int $validuntil = 0): array {
        global $DB;

        $email = $sadata->email ?? '';
        $firstname = $sadata->firstname ?? 'SM Estratoos';
        $lastname = $sadata->lastname ?? 'Admin';

        // Use the SmartLearning service user as actor so log entries show the
        // service user instead of whichever admin triggered the activation.
        $serviceuser = $DB->get_record('user', ['username' => 'smartlearning_service', 'deleted' => 0], 'id');

        // Clean up stale tokens from previous activations for this superadmin.
        $existinguser = $DB->get_record('user', ['email' => $email, 'deleted' => 0], 'id');
        if ($existinguser) {
            self::cleanup_user_service_tokens($existinguser->id, $serviceid, $companyid);
        }

        $userdata = [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'generate_password' => 1,
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'managertype' => 1, // Company manager — needed for get_company_users and other management APIs.
        ];
        if ($serviceuser) {
            $userdata['actor_userid'] = (int) $serviceuser->id;
        }
        if ($validuntil > 0) {
            $userdata['validuntil'] = $validuntil;
        }

        $createresult = user_manager::create_user($userdata);

        return [
            'slp_user_id' => $sadata->slp_user_id ?? '',
            'superadmin_number' => $sadata->superadmin_number ?? 0,
            'userid' => $createresult->userid ?? 0,
            'username' => $createresult->username ?? '',
            'email' => $email,
            'token' => $createresult->token ?? '',
        ];
    }

    /**
     * Get the plugin's external service ID.
     *
     * @return int Service ID, or 0 if not found.
     */
    public static function get_plugin_service_id(): int {
        global $DB;

        try {
            $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin']);
            return $service ? (int) $service->id : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}
