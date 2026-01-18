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
 * Utility class for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {

    /** @var bool|null Cached IOMAD detection result. */
    private static $isiomad = null;

    /**
     * Check if IOMAD is installed and active.
     *
     * This method checks for the presence of IOMAD by looking for:
     * 1. The local_iomad plugin directory
     * 2. The company table in the database
     *
     * @return bool True if IOMAD is installed and active.
     */
    public static function is_iomad_installed(): bool {
        global $CFG, $DB;

        // Use cached result if available.
        if (self::$isiomad !== null) {
            return self::$isiomad;
        }

        // Check 1: Does the local/iomad plugin exist?
        $iomadpath = $CFG->dirroot . '/local/iomad';
        if (!is_dir($iomadpath)) {
            self::$isiomad = false;
            return false;
        }

        // Check 2: Does the company table exist?
        try {
            $dbman = $DB->get_manager();
            if (!$dbman->table_exists('company')) {
                self::$isiomad = false;
                return false;
            }
        } catch (\Exception $e) {
            self::$isiomad = false;
            return false;
        }

        // Check 3: Is IOMAD enabled? (check if there's at least one company)
        try {
            $companycount = $DB->count_records('company');
            self::$isiomad = ($companycount > 0);
        } catch (\Exception $e) {
            self::$isiomad = false;
        }

        return self::$isiomad;
    }

    /**
     * Get the IOMAD status message for display.
     *
     * @return array Array with 'iomad' (bool) and 'message' (string).
     */
    public static function get_iomad_status(): array {
        $isiomad = self::is_iomad_installed();

        if ($isiomad) {
            return [
                'iomad' => true,
                'message' => get_string('iomaddetected', 'local_sm_estratoos_plugin'),
            ];
        }

        return [
            'iomad' => false,
            'message' => get_string('standardmoodle', 'local_sm_estratoos_plugin'),
        ];
    }

    /**
     * Get the token string from the current web service request.
     *
     * @return string|null The token or null if not available.
     */
    public static function get_current_request_token(): ?string {
        // Token can come from different sources:
        // 1. REST: wstoken parameter
        // 2. POST data
        // 3. Header

        // Check GET/POST parameters.
        $token = optional_param('wstoken', '', PARAM_ALPHANUM);

        if (empty($token)) {
            // Check for token in POST data.
            if (isset($_POST['wstoken'])) {
                $token = clean_param($_POST['wstoken'], PARAM_ALPHANUM);
            }
        }

        if (empty($token)) {
            // Check for token in Authorization header.
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                // Format: Bearer <token>
                if (preg_match('/Bearer\s+(\w+)/', $headers['Authorization'], $matches)) {
                    $token = $matches[1];
                }
            }
        }

        return $token ?: null;
    }

    /**
     * Get all available companies.
     *
     * @return array Array of company records.
     */
    public static function get_companies(): array {
        global $DB;
        return $DB->get_records('company', [], 'name ASC', 'id, name, shortname');
    }

    /**
     * Get company departments.
     *
     * @param int $companyid Company ID.
     * @return array Array of department records.
     */
    public static function get_company_departments(int $companyid): array {
        global $DB;
        return $DB->get_records('department', ['company' => $companyid], 'name ASC', 'id, name, shortname');
    }

    /**
     * Get enabled external services.
     *
     * @return array Array of service records.
     */
    public static function get_enabled_services(): array {
        global $DB;
        return $DB->get_records('external_services', ['enabled' => 1], 'name ASC', 'id, name, shortname');
    }

    /**
     * Validate IP restriction format.
     *
     * @param string $iprestriction IP restriction string.
     * @return bool True if valid.
     */
    public static function validate_ip_format(string $iprestriction): bool {
        if (empty($iprestriction)) {
            return true;
        }

        $ips = explode(',', $iprestriction);
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (empty($ip)) {
                continue;
            }

            // Check for valid IP, CIDR notation, or partial IP.
            // Valid formats: 192.168.1.1, 192.168.1.0/24, 10.0.0.*, 192.168.1.
            if (!preg_match('/^[\d\.\/*]+$/', $ip)) {
                return false;
            }

            // More specific validation.
            if (strpos($ip, '/') !== false) {
                // CIDR notation.
                list($addr, $mask) = explode('/', $ip);
                if (!filter_var($addr, FILTER_VALIDATE_IP) || $mask < 0 || $mask > 32) {
                    return false;
                }
            } else if (strpos($ip, '*') !== false) {
                // Wildcard notation.
                $parts = explode('.', str_replace('*', '0', $ip));
                if (count($parts) !== 4) {
                    return false;
                }
            } else if (substr($ip, -1) === '.') {
                // Partial IP like 192.168.1.
                $parts = explode('.', rtrim($ip, '.'));
                if (count($parts) < 1 || count($parts) > 3) {
                    return false;
                }
            } else {
                // Full IP address.
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if current user is site admin.
     *
     * @return bool True if site admin.
     */
    public static function require_site_admin(): void {
        if (!is_siteadmin()) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }
    }

    /**
     * Check if user has a role containing admin/manager keywords in the shortname.
     *
     * This is used to grant plugin access to users with administrative roles
     * even if they are not IOMAD company managers.
     *
     * Supported keywords (multilingual):
     * - English: admin, administrator, manager
     * - Spanish: administrador, gerente, gestor
     * - Portuguese: administrador, gerente, gestor
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user has such a role.
     */
    public static function has_admin_or_manager_role(int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        try {
            // Check for roles at system level or any category context.
            // Include multilingual keywords for admin/manager roles.
            $sql = "SELECT DISTINCT r.id, r.shortname
                    FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.userid = :userid
                      AND ctx.contextlevel IN (:systemlevel, :categorylevel)
                      AND (
                          -- English keywords
                          LOWER(r.shortname) LIKE '%admin%'
                          OR LOWER(r.shortname) LIKE '%manager%'
                          -- Spanish/Portuguese keywords
                          OR LOWER(r.shortname) LIKE '%administrador%'
                          OR LOWER(r.shortname) LIKE '%gerente%'
                          OR LOWER(r.shortname) LIKE '%gestor%'
                      )";

            $roles = $DB->get_records_sql($sql, [
                'userid' => $userid,
                'systemlevel' => CONTEXT_SYSTEM,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);

            return !empty($roles);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if user is a company admin (IOMAD manager) or site admin.
     * For company managers, also checks if their company has plugin access enabled.
     * Also grants access to users with roles containing 'admin' or 'manager' in the shortname.
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user can administer tokens.
     */
    public static function is_token_admin(int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Site admins always have access.
        if (is_siteadmin($userid)) {
            return true;
        }

        // Check if IOMAD is installed.
        if (self::is_iomad_installed()) {
            // IOMAD MODE: Check (managertype > 0 OR admin/manager role) AND company enabled.
            try {
                // Get user's companies with their manager status.
                $sql = "SELECT cu.id, cu.companyid, cu.managertype
                        FROM {company_users} cu
                        WHERE cu.userid = :userid";
                $usercompanies = $DB->get_records_sql($sql, ['userid' => $userid]);

                if (empty($usercompanies)) {
                    return false;
                }

                // Check if user has admin/manager role (cache this to avoid multiple queries).
                $hasadminrole = self::has_admin_or_manager_role($userid);

                // Check each company the user belongs to.
                foreach ($usercompanies as $uc) {
                    // User qualifies if: (managertype > 0 OR has admin/manager role).
                    if ($uc->managertype > 0 || $hasadminrole) {
                        // AND company must be enabled.
                        if (self::is_company_enabled($uc->companyid)) {
                            return true;
                        }
                    }
                }

                // User is in companies but none are enabled or user has no qualifying role.
                return false;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            // NON-IOMAD MODE: Check if user has admin/manager role.
            return self::has_admin_or_manager_role($userid);
        }
    }

    /**
     * Get companies that the user can manage.
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return array Array of company records [id => {id, name, shortname, category}].
     */
    public static function get_user_managed_companies(int $userid = null): array {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Site admins can manage all companies.
        if (is_siteadmin($userid)) {
            return self::get_companies();
        }

        // Check if IOMAD is installed.
        if (!self::is_iomad_installed()) {
            return [];
        }

        // Get companies where user is a manager.
        try {
            $sql = "SELECT c.id, c.name, c.shortname, c.category
                    FROM {company} c
                    JOIN {company_users} cu ON cu.companyid = c.id
                    WHERE cu.userid = :userid AND cu.managertype > 0
                    ORDER BY c.name";
            return $DB->get_records_sql($sql, ['userid' => $userid]);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if user can manage a specific company.
     *
     * @param int $companyid Company ID.
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user can manage the company.
     */
    public static function can_manage_company(int $companyid, int $userid = null): bool {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Site admins can manage all companies.
        if (is_siteadmin($userid)) {
            return true;
        }

        $managedcompanies = self::get_user_managed_companies($userid);
        return isset($managedcompanies[$companyid]);
    }

    /**
     * Require token admin access (site admin or company manager).
     * Throws exception if access denied.
     */
    public static function require_token_admin(): void {
        global $USER;

        require_login();

        if (!self::is_token_admin($USER->id)) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }
    }

    /**
     * Format a token for display (partial masking).
     *
     * @param string $token The full token.
     * @param bool $fulltoken Whether to show full token.
     * @return string Formatted token.
     */
    public static function format_token_display(string $token, bool $fulltoken = false): string {
        if ($fulltoken) {
            return $token;
        }
        // Show first 8 and last 4 characters.
        if (strlen($token) > 12) {
            return substr($token, 0, 8) . '...' . substr($token, -4);
        }
        return $token;
    }

    /**
     * Export tokens to CSV format.
     *
     * @param array $tokens Array of token records.
     * @param bool $includetoken Whether to include actual token strings.
     * @return string CSV content.
     */
    public static function export_tokens_csv(array $tokens, bool $includetoken = true): string {
        // Use semicolon delimiter for Excel compatibility (works in all locales).
        $delimiter = ';';

        // Start with UTF-8 BOM for Excel to recognize encoding.
        $csv = "\xEF\xBB\xBF";

        // Header row.
        $headers = ['User ID', 'Username', 'Email', 'Full Name', 'Company', 'Service',
            'Restrict to Company', 'Restrict to Enrollment', 'IP Restriction',
            'Valid Until', 'Created', 'Last Access'];

        if ($includetoken) {
            array_unshift($headers, 'Token');
        }

        $csv .= implode($delimiter, $headers) . "\n";

        // Data rows.
        foreach ($tokens as $token) {
            $row = [];

            if ($includetoken) {
                $row[] = $token->token;
            }

            $row[] = $token->userid;
            $row[] = $token->username;
            $row[] = $token->email;
            // Escape quotes in full name and wrap in quotes if contains delimiter.
            $fullname = fullname($token);
            $row[] = (strpos($fullname, $delimiter) !== false || strpos($fullname, '"') !== false)
                ? '"' . str_replace('"', '""', $fullname) . '"'
                : $fullname;
            $row[] = $token->companyname;
            $row[] = $token->servicename;
            $row[] = $token->restricttocompany ? 'Yes' : 'No';
            $row[] = $token->restricttoenrolment ? 'Yes' : 'No';
            $row[] = $token->iprestriction ?: '';
            $row[] = $token->validuntil ? userdate($token->validuntil) : 'Never';
            $row[] = userdate($token->timecreated);
            $row[] = $token->lastaccess ? userdate($token->lastaccess) : 'Never';

            $csv .= implode($delimiter, $row) . "\n";
        }

        return $csv;
    }

    /**
     * Export tokens to Excel format.
     *
     * @param array $tokens Array of token records.
     * @param bool $includetoken Whether to include actual token strings.
     */
    public static function export_tokens_excel(array $tokens, bool $includetoken = true): void {
        global $CFG;
        require_once($CFG->libdir . '/excellib.class.php');

        $filename = 'sm_tokens_' . date('Y-m-d_His') . '.xlsx';

        // Create workbook.
        $workbook = new \MoodleExcelWorkbook($filename);
        $worksheet = $workbook->add_worksheet(get_string('tokenlist', 'local_sm_estratoos_plugin'));

        // Formats.
        $formatheader = $workbook->add_format(['bold' => 1, 'bg_color' => '#4472C4', 'color' => 'white']);
        $formatdate = $workbook->add_format(['num_format' => 'DD/MM/YYYY HH:MM']);

        // Header row.
        $headers = ['User ID', 'Username', 'Email', 'Full Name', 'Company', 'Service',
            'Restrict to Company', 'Restrict to Enrollment', 'IP Restriction',
            'Valid Until', 'Created', 'Last Access'];

        if ($includetoken) {
            array_unshift($headers, 'Token');
        }

        $col = 0;
        foreach ($headers as $header) {
            $worksheet->write_string(0, $col, $header, $formatheader);
            $col++;
        }

        // Data rows.
        $row = 1;
        foreach ($tokens as $token) {
            $col = 0;

            if ($includetoken) {
                $worksheet->write_string($row, $col++, $token->token);
            }

            $worksheet->write_number($row, $col++, $token->userid);
            $worksheet->write_string($row, $col++, $token->username);
            $worksheet->write_string($row, $col++, $token->email);
            $worksheet->write_string($row, $col++, fullname($token));
            $worksheet->write_string($row, $col++, $token->companyname ?? '');
            $worksheet->write_string($row, $col++, $token->servicename);
            $worksheet->write_string($row, $col++, $token->restricttocompany ? 'Yes' : 'No');
            $worksheet->write_string($row, $col++, $token->restricttoenrolment ? 'Yes' : 'No');
            $worksheet->write_string($row, $col++, $token->iprestriction ?? '');
            $worksheet->write_string($row, $col++, $token->validuntil ? userdate($token->validuntil) : 'Never');
            $worksheet->write_string($row, $col++, userdate($token->timecreated));
            $worksheet->write_string($row, $col++, $token->lastaccess ? userdate($token->lastaccess) : 'Never');

            $row++;
        }

        // Set column widths.
        $colwidths = $includetoken
            ? [40, 10, 15, 25, 25, 15, 20, 15, 15, 20, 18, 18, 18]
            : [10, 15, 25, 25, 15, 20, 15, 15, 20, 18, 18, 18];

        foreach ($colwidths as $idx => $width) {
            $worksheet->set_column($idx, $idx, $width);
        }

        $workbook->close();
    }

    // =========================================================================
    // COMPANY ACCESS CONTROL METHODS
    // =========================================================================

    /**
     * Get all enabled company IDs for the plugin.
     *
     * @return array Array of company IDs that are enabled.
     */
    public static function get_enabled_companies(): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        try {
            return $DB->get_fieldset_select(
                'local_sm_estratoos_plugin_access',
                'companyid',
                'enabled = ?',
                [1]
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if a company is enabled to access the plugin.
     *
     * @param int $companyid Company ID.
     * @return bool True if company is enabled.
     */
    public static function is_company_enabled(int $companyid): bool {
        global $DB;

        if (!self::is_iomad_installed()) {
            return false;
        }

        try {
            $record = $DB->get_record('local_sm_estratoos_plugin_access', [
                'companyid' => $companyid,
                'enabled' => 1,
            ]);
            return !empty($record);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Enable access for a company.
     * Also reactivates all suspended tokens for this company.
     *
     * @param int $companyid Company ID.
     * @param int|null $userid User ID who enabled the access.
     * @return bool Success.
     */
    public static function enable_company_access(int $companyid, int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $time = time();

        try {
            $existing = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);

            if ($existing) {
                $existing->enabled = 1;
                $existing->enabledby = $userid;
                $existing->timemodified = $time;
                $DB->update_record('local_sm_estratoos_plugin_access', $existing);
            } else {
                $DB->insert_record('local_sm_estratoos_plugin_access', [
                    'companyid' => $companyid,
                    'enabled' => 1,
                    'enabledby' => $userid,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]);
            }

            // Reactivate all tokens for this company.
            self::set_company_tokens_active($companyid, true, $time);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Disable access for a company.
     * Also suspends all tokens for this company (they will be reactivated when company is re-enabled).
     *
     * @param int $companyid Company ID.
     * @param int|null $userid User ID who disabled the access.
     * @return bool Success.
     */
    public static function disable_company_access(int $companyid, int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $time = time();

        try {
            $existing = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);

            if ($existing) {
                $existing->enabled = 0;
                $existing->enabledby = $userid;
                $existing->timemodified = $time;
                $DB->update_record('local_sm_estratoos_plugin_access', $existing);
            } else {
                // Insert a disabled record (company was never in our table).
                $DB->insert_record('local_sm_estratoos_plugin_access', [
                    'companyid' => $companyid,
                    'enabled' => 0,
                    'enabledby' => $userid,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]);
            }

            // Suspend all tokens for this company.
            self::set_company_tokens_active($companyid, false, $time);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Set active status for all tokens of a company.
     *
     * @param int $companyid Company ID.
     * @param bool $active True to activate, false to suspend.
     * @param int|null $time Timestamp for timemodified.
     */
    private static function set_company_tokens_active(int $companyid, bool $active, int $time = null): void {
        global $DB;

        if ($time === null) {
            $time = time();
        }

        // Get all plugin token records for this company.
        $plugintokens = $DB->get_records('local_sm_estratoos_plugin', ['companyid' => $companyid]);

        foreach ($plugintokens as $plugintoken) {
            if ($active) {
                // RE-ENABLING: Restore the token to external_tokens from backup.
                if (!empty($plugintoken->token_backup)) {
                    $backupdata = json_decode($plugintoken->token_backup, true);
                    if ($backupdata && !empty($backupdata['token'])) {
                        // Check if token already exists (shouldn't, but be safe).
                        $existing = $DB->get_record('external_tokens', ['token' => $backupdata['token']]);
                        if (!$existing) {
                            // Restore the token record.
                            unset($backupdata['id']); // Remove old ID, let DB assign new one.
                            $newtokenid = $DB->insert_record('external_tokens', (object)$backupdata);

                            // Update our reference to the new token ID.
                            $plugintoken->tokenid = $newtokenid;
                        } else {
                            // Token already exists - link to it.
                            $plugintoken->tokenid = $existing->id;
                        }
                        // Clear the backup.
                        $plugintoken->token_backup = null;
                    }
                }
                // Note: If token_backup is empty and tokenid is NULL, we can't restore.
                // This happens for tokens created before v1.7.5 that were suspended without backup.
                // The token is lost and admin needs to create a new one.
                $plugintoken->active = 1;
            } else {
                // SUSPENDING: Backup the token data, then delete from external_tokens.
                if ($plugintoken->tokenid) {
                    $externaltoken = $DB->get_record('external_tokens', ['id' => $plugintoken->tokenid]);
                    if ($externaltoken) {
                        // Store full token record as JSON backup.
                        $plugintoken->token_backup = json_encode((array)$externaltoken);

                        // Set tokenid to NULL first (to avoid foreign key constraint).
                        $plugintoken->tokenid = null;

                        // Delete from external_tokens - this blocks ALL API calls immediately.
                        $DB->delete_records('external_tokens', ['id' => $externaltoken->id]);
                    }
                }
                $plugintoken->active = 0;
            }

            $plugintoken->timemodified = $time;
            $DB->update_record('local_sm_estratoos_plugin', $plugintoken);
        }
    }

    /**
     * Bulk update company access.
     * Enables the provided company IDs and disables all others.
     * Also activates/suspends tokens accordingly.
     *
     * @param array $companyids Company IDs to enable.
     * @param int|null $userid User ID who made the change.
     */
    public static function set_enabled_companies(array $companyids, int $userid = null): void {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        if (!self::is_iomad_installed()) {
            return;
        }

        $time = time();
        $enabledids = array_map('intval', $companyids);

        // Get all companies.
        $allcompanies = self::get_companies();

        foreach ($allcompanies as $company) {
            $shouldenable = in_array($company->id, $enabledids);
            $existing = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $company->id]);
            $wasEnabled = $existing && ($existing->enabled == 1);

            if ($existing) {
                // Update if state changed.
                if ($wasEnabled !== $shouldenable) {
                    $existing->enabled = $shouldenable ? 1 : 0;
                    $existing->enabledby = $userid;
                    $existing->timemodified = $time;
                    $DB->update_record('local_sm_estratoos_plugin_access', $existing);

                    // Update token status.
                    self::set_company_tokens_active($company->id, $shouldenable, $time);
                }
            } else {
                // Insert new record.
                $DB->insert_record('local_sm_estratoos_plugin_access', [
                    'companyid' => $company->id,
                    'enabled' => $shouldenable ? 1 : 0,
                    'enabledby' => $userid,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]);

                // If disabling a company that was never in the access table,
                // suspend its tokens (they were active by default before this feature).
                if (!$shouldenable) {
                    self::set_company_tokens_active($company->id, false, $time);
                }
            }
        }
    }

    /**
     * Check if current user has access to the plugin.
     * - Site admins always have access
     * - Company managers only if their company is enabled
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user can access the plugin.
     */
    public static function has_plugin_access(int $userid = null): bool {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Site admins always have access.
        if (is_siteadmin($userid)) {
            return true;
        }

        // Check if IOMAD is installed.
        if (!self::is_iomad_installed()) {
            return false;
        }

        // Get companies the user manages (without filtering by enabled status yet).
        $managedcompanies = self::get_user_managed_companies_raw($userid);

        if (empty($managedcompanies)) {
            return false;
        }

        // Check if at least one managed company is enabled.
        foreach ($managedcompanies as $company) {
            if (self::is_company_enabled($company->id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get companies that the user manages (raw - without enabled filter).
     * This is used internally by has_plugin_access().
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return array Array of company records.
     */
    private static function get_user_managed_companies_raw(int $userid = null): array {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Site admins can manage all companies.
        if (is_siteadmin($userid)) {
            return self::get_companies();
        }

        // Check if IOMAD is installed.
        if (!self::is_iomad_installed()) {
            return [];
        }

        // Get companies where user is a manager (no enabled filter).
        try {
            $sql = "SELECT c.id, c.name, c.shortname, c.category
                    FROM {company} c
                    JOIN {company_users} cu ON cu.companyid = c.id
                    WHERE cu.userid = :userid AND cu.managertype > 0
                    ORDER BY c.name";
            return $DB->get_records_sql($sql, ['userid' => $userid]);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all companies with their enabled status (for the admin page).
     *
     * @return array Array of companies with 'enabled' property.
     */
    public static function get_companies_with_access_status(): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        $companies = self::get_companies();
        $enabledids = self::get_enabled_companies();

        foreach ($companies as $company) {
            $company->enabled = in_array($company->id, $enabledids);
        }

        return $companies;
    }
}
