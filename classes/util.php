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

    /** @var array|null Cached .env file values. */
    private static $envvalues = null;

    /** @var array Map of .env keys to Moodle config keys. */
    private static $envmap = [
        'WEBHOOK_URL' => 'webhook_url',
        'WEBHOOK_ENABLED' => 'webhook_enabled',
        'CURL_IGNORE_SECURITY' => 'curl_ignore_security',
        'OAUTH2_ISSUER_URL' => 'oauth2_issuer_url',
        'OAUTH2_ALLOWED_ORIGINS' => 'oauth2_allowed_origins',
        'OAUTH2_JWKS_CACHE_TTL' => 'oauth2_jwks_cache_ttl',
    ];

    /**
     * Get a plugin config value with .env override support.
     *
     * Checks for a .env file in the plugin directory first. If the key has
     * an override there, it takes precedence over the database config.
     * This allows local development without modifying the database.
     *
     * @param string $key The Moodle config key (e.g., 'oauth2_issuer_url').
     * @param mixed $default Default value if not found anywhere.
     * @return mixed The config value.
     */
    public static function get_env_config(string $key, $default = null) {
        // Load .env file once.
        if (self::$envvalues === null) {
            self::$envvalues = [];
            $envfile = __DIR__ . '/../.env';
            if (file_exists($envfile)) {
                $lines = file($envfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        self::$envvalues[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }

        // Find the .env key for this Moodle config key.
        $envkey = array_search($key, self::$envmap);
        if ($envkey !== false && isset(self::$envvalues[$envkey])) {
            return self::$envvalues[$envkey];
        }

        // Fall back to database config.
        $value = get_config('local_sm_estratoos_plugin', $key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

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
     * Get the company ID from the current web service request token.
     *
     * Uses the token to look up company restrictions in the plugin's token table.
     *
     * @return int The company ID or 0 if not available/not company-scoped.
     */
    public static function get_company_id_from_token(): int {
        $token = self::get_current_request_token();
        if (!$token) {
            return 0;
        }

        $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
        if ($restrictions && !empty($restrictions->companyid)) {
            return (int)$restrictions->companyid;
        }

        return 0;
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
     * NOTE: Only checks SYSTEM and CATEGORY contexts. Course-level managers
     * should NOT have access to the plugin.
     *
     * Also checks IOMAD's company_users.managertype for IOMAD installations.
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
            // Check 1: IOMAD company_users.managertype > 0.
            if (self::is_iomad_installed()) {
                $ismanager = $DB->record_exists_select(
                    'company_users',
                    'userid = :userid AND managertype > 0',
                    ['userid' => $userid]
                );
                if ($ismanager) {
                    return true;
                }
            }

            // Check 2: Roles at SYSTEM or CATEGORY context level.
            // Course-level managers should NOT have access to the plugin.
            // Include multilingual keywords for admin/manager roles.
            // Also explicitly include IOMAD's companymanager role.
            $sql = "SELECT DISTINCT r.id, r.shortname
                    FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.userid = :userid
                      AND ctx.contextlevel IN (:systemlevel, :categorylevel)
                      AND (
                          -- IOMAD specific role
                          r.shortname = 'companymanager'
                          -- English keywords
                          OR LOWER(r.shortname) LIKE '%admin%'
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
     * Also grants access to users with roles containing 'admin' or 'manager' in the shortname
     * at system, category, or course level.
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user can administer tokens.
     */
    public static function is_token_admin(int $userid = null): bool {
        global $DB, $USER, $CFG;

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

                // DEBUG: Log what we found.
                if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
                    debugging("SM_ESTRATOOS DEBUG: User $userid companies: " . json_encode($usercompanies), DEBUG_DEVELOPER);
                }

                if (empty($usercompanies)) {
                    if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
                        debugging("SM_ESTRATOOS DEBUG: User $userid not found in any company", DEBUG_DEVELOPER);
                    }
                    return false;
                }

                // Check if user has admin/manager role at system, category, or course level.
                $hasadminrole = self::has_admin_or_manager_role($userid);

                // Check each company the user belongs to.
                foreach ($usercompanies as $uc) {
                    // User qualifies if: (managertype > 0 OR has admin/manager role).
                    $qualifies = ($uc->managertype > 0 || $hasadminrole);
                    $enabled = self::is_company_enabled($uc->companyid);

                    if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
                        debugging("SM_ESTRATOOS DEBUG: Company {$uc->companyid}: managertype={$uc->managertype}, " .
                                  "hasadminrole=" . ($hasadminrole ? '1' : '0') . ", qualifies=" . ($qualifies ? '1' : '0') .
                                  ", enabled=" . ($enabled ? '1' : '0'), DEBUG_DEVELOPER);
                    }

                    if ($qualifies && $enabled) {
                        return true;
                    }
                }

                // User is in companies but none are enabled or user has no qualifying role.
                return false;
            } catch (\Exception $e) {
                if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
                    debugging("SM_ESTRATOOS DEBUG: Exception in is_token_admin: " . $e->getMessage(), DEBUG_DEVELOPER);
                }
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
     * Get all managers of a specific IOMAD company.
     *
     * Returns users with managertype > 0 in the company_users table.
     *
     * @param int $companyid The IOMAD company ID.
     * @return array Array of user records (id, username, email, firstname, lastname).
     */
    public static function get_company_managers(int $companyid): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        try {
            $sql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname
                    FROM {company_users} cu
                    JOIN {user} u ON u.id = cu.userid AND u.deleted = 0 AND u.suspended = 0
                    WHERE cu.companyid = :companyid
                      AND cu.managertype > 0";
            return $DB->get_records_sql($sql, ['companyid' => $companyid]);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get all site-level managers for standard Moodle (non-IOMAD).
     *
     * Returns users with admin/manager roles at system or category context level,
     * plus site admins. Used during system-level activation to auto-create tokens.
     *
     * @return array Array of user records (id, username, email, firstname, lastname).
     */
    public static function get_site_managers(): array {
        global $DB, $CFG;

        try {
            $managers = [];

            // 1. Get site admins.
            $adminids = explode(',', $CFG->siteadmins);
            foreach ($adminids as $adminid) {
                $adminid = (int) trim($adminid);
                if ($adminid > 0) {
                    $user = $DB->get_record('user', [
                        'id' => $adminid, 'deleted' => 0, 'suspended' => 0,
                    ], 'id, username, email, firstname, lastname');
                    if ($user) {
                        $managers[$user->id] = $user;
                    }
                }
            }

            // 2. Get users with admin/manager roles at system or category context.
            $sql = "SELECT DISTINCT u.id, u.username, u.email, u.firstname, u.lastname
                    FROM {role_assignments} ra
                    JOIN {role} r ON r.id = ra.roleid
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                    WHERE ctx.contextlevel IN (:systemlevel, :categorylevel)
                      AND (
                          LOWER(r.shortname) LIKE '%admin%'
                          OR LOWER(r.shortname) LIKE '%manager%'
                          OR LOWER(r.shortname) LIKE '%administrador%'
                          OR LOWER(r.shortname) LIKE '%gerente%'
                          OR LOWER(r.shortname) LIKE '%gestor%'
                      )";
            $rolemanagers = $DB->get_records_sql($sql, [
                'systemlevel' => CONTEXT_SYSTEM,
                'categorylevel' => CONTEXT_COURSECAT,
            ]);
            foreach ($rolemanagers as $rm) {
                $managers[$rm->id] = $rm;
            }

            return $managers;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if user is a potential token admin (IOMAD manager for any company,
     * regardless of company enabled status).
     *
     * Used for navigation visibility so managers can access the activation page
     * even before their company is activated.
     *
     * @param int|null $userid User ID (defaults to current user).
     * @return bool True if user is a manager for any IOMAD company.
     */
    public static function is_potential_token_admin(int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        if (!self::is_iomad_installed()) {
            return self::has_admin_or_manager_role($userid);
        }

        try {
            $sql = "SELECT COUNT(*) FROM {company_users}
                    WHERE userid = :userid AND managertype > 0";
            return $DB->count_records_sql($sql, ['userid' => $userid]) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Require token admin access (site admin or company manager).
     * Throws exception if access denied.
     */
    public static function require_token_admin(): void {
        global $USER;

        require_login();

        // Allow active token admins and potential managers (whose company
        // may not yet be activated — they need access to activate it).
        if (!self::is_token_admin($USER->id) && !self::is_potential_token_admin($USER->id)) {
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
     * Also checks if access has expired (v1.7.29).
     *
     * @param int $companyid Company ID.
     * @return bool True if company is enabled and not expired.
     */
    public static function is_company_enabled(int $companyid): bool {
        global $DB, $CFG;

        if (!self::is_iomad_installed()) {
            return false;
        }

        try {
            $record = $DB->get_record('local_sm_estratoos_plugin_access', [
                'companyid' => $companyid,
                'enabled' => 1,
            ]);

            if (empty($record)) {
                return false;
            }

            // Check if expired (v1.7.29, v2.1.34 noon-UTC fix: +12h buffer).
            // Just return false; actual cleanup is handled by the expire_company_access scheduled task.
            if (!empty($record->expirydate) && ($record->expirydate + 43200) < time()) {
                return false;
            }

            // DEBUG: Log the check result.
            if (!empty($CFG->debug) && $CFG->debug >= DEBUG_DEVELOPER) {
                debugging("SM_ESTRATOOS DEBUG: is_company_enabled($companyid) = 1" .
                          ", record: " . json_encode($record), DEBUG_DEVELOPER);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get company access expiry date.
     *
     * @param int $companyid Company ID.
     * @return int|null Expiry timestamp or null if never expires.
     */
    public static function get_company_expiry_date(int $companyid): ?int {
        global $DB;

        $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);
        if ($record && !empty($record->expirydate)) {
            return (int)$record->expirydate;
        }
        return null;
    }

    /**
     * Set company access expiry date.
     * NOTE: This function also enables/disables the company based on the expiry date.
     * Use set_company_expiry_date_only() if you want to update just the date.
     *
     * @param int $companyid Company ID.
     * @param int|null $expirydate Expiry timestamp or null for never.
     * @param int|null $userid User who made the change.
     * @return bool Success.
     */
    public static function set_company_expiry_date(int $companyid, ?int $expirydate, int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);
        if (!$record) {
            return false;
        }

        $wasEnabled = (bool)$record->enabled;
        $now = time();

        // Update the expiry date in the record.
        $record->expirydate = $expirydate;
        $record->enabledby = $userid;
        $record->timemodified = $now;
        $DB->update_record('local_sm_estratoos_plugin_access', $record);

        if (!empty($expirydate) && $expirydate < $now) {
            // Expiry date is in the past - disable company and suspend tokens.
            self::disable_company_access($companyid, $userid);
        } else {
            // Expiry date is null (never) or today/future - enable company and unsuspend tokens.
            // This ensures the company is enabled when a valid date is set.
            self::enable_company_access($companyid, $userid);
        }

        return true;
    }

    /**
     * Set company access expiry date WITHOUT triggering enable/disable logic.
     * Use this when you've already handled the enable/disable state separately.
     *
     * @param int $companyid Company ID.
     * @param int|null $expirydate Expiry timestamp or null for never.
     * @param int|null $userid User who made the change.
     * @return bool Success.
     */
    public static function set_company_expiry_date_only(int $companyid, ?int $expirydate, int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);
        if (!$record) {
            // Create record if it doesn't exist.
            $now = time();
            $DB->insert_record('local_sm_estratoos_plugin_access', [
                'companyid' => $companyid,
                'enabled' => 0,
                'expirydate' => $expirydate,
                'enabledby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return true;
        }

        // Only update the expiry date field.
        $oldexpiry = $record->expirydate;
        $record->expirydate = $expirydate;
        $record->enabledby = $userid;
        $record->timemodified = time();
        $DB->update_record('local_sm_estratoos_plugin_access', $record);

        // Log company.expiry_updated event (v2.1.32).
        try {
            \local_sm_estratoos_plugin\webhook::log_event('company.expiry_updated', 'company', [
                'companyid' => $companyid,
                'new_expiry' => $expirydate,
                'old_expiry' => $oldexpiry,
                'set_by' => $userid,
            ], $userid, $companyid);
        } catch (\Exception $e) {
            // Non-fatal.
        }

        return true;
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

            // Log company.access_enabled event (v2.1.32).
            try {
                \local_sm_estratoos_plugin\webhook::log_event('company.access_enabled', 'company', [
                    'companyid' => $companyid,
                    'enabled_by' => $userid,
                ], $userid, $companyid);
            } catch (\Exception $e) {
                // Non-fatal.
            }

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

            // Log company.access_disabled event (v2.1.32).
            try {
                \local_sm_estratoos_plugin\webhook::log_event('company.access_disabled', 'company', [
                    'companyid' => $companyid,
                    'disabled_by' => $userid,
                ], $userid, $companyid);
            } catch (\Exception $e) {
                // Non-fatal.
            }

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
    public static function set_company_tokens_active(int $companyid, bool $active, int $time = null): void {
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
     * Get the activation code for a company.
     *
     * @param int $companyid Company ID.
     * @return string|null The activation code or null if not set.
     */
    public static function get_company_activation_code(int $companyid): ?string {
        global $DB;
        $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid], 'activation_code');
        if ($record && !empty($record->activation_code)) {
            return $record->activation_code;
        }
        return null;
    }

    /**
     * Check if a company has been activated with an activation code.
     *
     * @param int $companyid Company ID.
     * @return bool True if the company has an activation code and contract has not expired.
     */
    public static function is_company_activated(int $companyid): bool {
        global $DB;
        $record = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid], 'activation_code, expirydate');
        if (!$record || empty($record->activation_code)) {
            return false;
        }
        // If there's an expiry date and it's in the past, the contract has expired.
        // Dates stored at noon UTC; add 12h so the full day counts as active.
        if (!empty($record->expirydate) && ($record->expirydate + 43200) < time()) {
            return false;
        }
        return true;
    }

    /**
     * Check activation gate for UI pages (index.php, batch.php, etc.).
     *
     * For IOMAD environments:
     * - Site admins are never redirected (they manage activation via settings/company_access).
     * - Company managers are redirected only if NONE of their companies have been activated.
     *
     * For non-IOMAD: redirects if plugin-level activation is not done.
     */
    public static function check_activation_gate(): void {
        global $USER;

        // Non-IOMAD: simple plugin-level activation check.
        if (!self::is_iomad_installed()) {
            if (!\local_sm_estratoos_plugin\webhook::is_activated()) {
                redirect(new \moodle_url('/local/sm_estratoos_plugin/activate.php'),
                    get_string('activationrequired', 'local_sm_estratoos_plugin'), null,
                    \core\output\notification::NOTIFY_WARNING);
            }
            return;
        }

        // IOMAD: Site admins skip the activation redirect entirely.
        if (is_siteadmin()) {
            return;
        }

        // IOMAD company manager: check if any of their companies have been activated.
        $companies = self::get_user_managed_companies($USER->id);
        foreach ($companies as $company) {
            if (self::is_company_activated($company->id)) {
                return; // At least one company is activated — allow access.
            }
        }

        // No activated company found — redirect to company access page where
        // managers can self-activate by entering their activation code.
        redirect(new \moodle_url('/local/sm_estratoos_plugin/company_access.php'),
            get_string('activationrequired', 'local_sm_estratoos_plugin'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * Get all companies with their enabled status and expiry info (for the admin page).
     *
     * @return array Array of companies with 'enabled', 'expirydate', 'expired' properties.
     */
    public static function get_companies_with_access_status(): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        $companies = self::get_companies();
        $accessrecords = $DB->get_records('local_sm_estratoos_plugin_access', [], '', 'companyid, enabled, expirydate, activation_code, contract_start');

        $now = time();
        foreach ($companies as $company) {
            $access = isset($accessrecords[$company->id]) ? $accessrecords[$company->id] : null;
            $company->enabled = $access && $access->enabled;
            $company->expirydate = $access ? $access->expirydate : null;
            $company->expired = !empty($company->expirydate) && $company->expirydate < $now;
            $company->activation_code = $access ? ($access->activation_code ?? null) : null;
            $company->contract_start = $access ? ($access->contract_start ?? null) : null;
        }

        return $companies;
    }

    /**
     * Update plugin version after a UI upgrade.
     *
     * Logic:
     * - IOMAD + Site Admin: Update ALL companies' plugin_version
     * - IOMAD + Manager: Update ONLY the manager's company plugin_version
     * - Non-IOMAD: Store version in plugin config (system-wide)
     *
     * @param string $version The new plugin version (e.g., "1.7.39").
     * @param int|null $userid User ID performing the update (defaults to current user).
     * @return array Result with 'success', 'message', and 'updated_companies'.
     */
    public static function update_plugin_version_after_upgrade(string $version, int $userid = null): array {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $result = [
            'success' => true,
            'message' => '',
            'updated_companies' => [],
        ];

        $time = time();

        if (self::is_iomad_installed()) {
            // IOMAD MODE.
            if (is_siteadmin($userid)) {
                // Site admin: Update ALL companies.
                $companies = self::get_companies();
                foreach ($companies as $company) {
                    self::set_company_plugin_version($company->id, $version, $userid);
                    $result['updated_companies'][] = $company->shortname;
                }
                $result['message'] = 'Updated plugin version to ' . $version . ' for all ' . count($companies) . ' companies';
            } else {
                // Manager: Update only their managed companies.
                $managedcompanies = self::get_user_managed_companies($userid);
                if (empty($managedcompanies)) {
                    $result['success'] = false;
                    $result['message'] = 'No companies found for this user';
                    return $result;
                }
                foreach ($managedcompanies as $company) {
                    self::set_company_plugin_version($company->id, $version, $userid);
                    $result['updated_companies'][] = $company->shortname;
                }
                $result['message'] = 'Updated plugin version to ' . $version . ' for ' . count($managedcompanies) . ' company(ies): ' . implode(', ', $result['updated_companies']);
            }
        } else {
            // NON-IOMAD MODE: Store in plugin config (system-wide).
            set_config('system_plugin_version', $version, 'local_sm_estratoos_plugin');
            set_config('system_plugin_version_updated', $time, 'local_sm_estratoos_plugin');
            set_config('system_plugin_version_updatedby', $userid, 'local_sm_estratoos_plugin');
            $result['message'] = 'Updated system plugin version to ' . $version;
        }

        return $result;
    }

    /**
     * Set the plugin version for a specific company.
     *
     * @param int $companyid Company ID.
     * @param string $version Plugin version string.
     * @param int|null $userid User who performed the update.
     * @return bool Success.
     */
    public static function set_company_plugin_version(int $companyid, string $version, int $userid = null): bool {
        global $DB, $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $time = time();

        try {
            $existing = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);

            if ($existing) {
                $existing->plugin_version = $version;
                $existing->timemodified = $time;
                $DB->update_record('local_sm_estratoos_plugin_access', $existing);
            } else {
                $DB->insert_record('local_sm_estratoos_plugin_access', [
                    'companyid' => $companyid,
                    'enabled' => 1,
                    'plugin_version' => $version,
                    'enabledby' => $userid,
                    'timecreated' => $time,
                    'timemodified' => $time,
                ]);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the system plugin version (for non-IOMAD installations).
     *
     * @return string|null The version string or null if not set.
     */
    public static function get_system_plugin_version(): ?string {
        $version = get_config('local_sm_estratoos_plugin', 'system_plugin_version');
        return $version ? $version : null;
    }

    /**
     * Get companies that need plugin version updates.
     *
     * @param string $latestversion The latest available version.
     * @param int|null $userid User ID (for filtering to managed companies). Null = all companies.
     * @return array Array of companies that need updates.
     */
    public static function get_companies_needing_update(string $latestversion, int $userid = null): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        // Get companies based on user permissions.
        if ($userid === null || is_siteadmin($userid)) {
            $companies = self::get_companies();
        } else {
            $companies = self::get_user_managed_companies($userid);
        }

        if (empty($companies)) {
            return [];
        }

        // Get access records with versions.
        $companyids = array_keys($companies);
        list($insql, $inparams) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED);
        $accessrecords = $DB->get_records_select(
            'local_sm_estratoos_plugin_access',
            "companyid $insql",
            $inparams,
            '',
            'companyid, plugin_version'
        );

        $needsupdate = [];
        foreach ($companies as $company) {
            $currentversion = '';
            if (isset($accessrecords[$company->id])) {
                $currentversion = $accessrecords[$company->id]->plugin_version ?? '';
            }

            // Company needs update if version is empty or different from latest.
            if (empty($currentversion) || version_compare($currentversion, $latestversion, '<')) {
                $company->current_version = $currentversion;
                $needsupdate[$company->id] = $company;
            }
        }

        return $needsupdate;
    }

    /**
     * Get all companies with their plugin versions for the dashboard.
     *
     * @param int|null $userid User ID (for filtering to managed companies). Null = all companies.
     * @return array Array of companies with version info.
     */
    public static function get_companies_with_versions(int $userid = null): array {
        global $DB;

        if (!self::is_iomad_installed()) {
            return [];
        }

        // Get companies based on user permissions.
        if ($userid === null || is_siteadmin($userid)) {
            $companies = self::get_companies();
        } else {
            $companies = self::get_user_managed_companies($userid);
        }

        if (empty($companies)) {
            return [];
        }

        // Get access records with versions.
        $companyids = array_keys($companies);
        list($insql, $inparams) = $DB->get_in_or_equal($companyids, SQL_PARAMS_NAMED);
        $accessrecords = $DB->get_records_select(
            'local_sm_estratoos_plugin_access',
            "companyid $insql",
            $inparams,
            '',
            'companyid, plugin_version, enabled'
        );

        foreach ($companies as $company) {
            $company->plugin_version = '';
            $company->enabled = false;
            if (isset($accessrecords[$company->id])) {
                $company->plugin_version = $accessrecords[$company->id]->plugin_version ?? '';
                $company->enabled = (bool)$accessrecords[$company->id]->enabled;
            }
        }

        return $companies;
    }

    /**
     * Check if all companies are up to date.
     *
     * @param string $latestversion The latest available version.
     * @param int|null $userid User ID (for filtering to managed companies). Null = all companies.
     * @return bool True if all companies have the latest version.
     */
    public static function all_companies_up_to_date(string $latestversion, int $userid = null): bool {
        $needsupdate = self::get_companies_needing_update($latestversion, $userid);
        return empty($needsupdate);
    }
}
