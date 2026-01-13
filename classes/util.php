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
     * Check if user is a company admin (IOMAD manager) or site admin.
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
        if (!self::is_iomad_installed()) {
            return false;
        }

        // Check if user is a company manager (managertype > 0).
        try {
            $sql = "SELECT cu.id, cu.managertype
                    FROM {company_users} cu
                    WHERE cu.userid = :userid AND cu.managertype > 0";
            $result = $DB->get_record_sql($sql, ['userid' => $userid]);
            return !empty($result);
        } catch (\Exception $e) {
            return false;
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
}
