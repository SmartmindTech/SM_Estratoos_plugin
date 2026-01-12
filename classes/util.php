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
        $output = fopen('php://temp', 'r+');

        // Header row.
        $headers = ['User ID', 'Username', 'Email', 'Full Name', 'Company', 'Service',
            'Restrict to Company', 'Restrict to Enrollment', 'IP Restriction',
            'Valid Until', 'Created', 'Last Access'];

        if ($includetoken) {
            array_unshift($headers, 'Token');
        }

        fputcsv($output, $headers);

        // Data rows.
        foreach ($tokens as $token) {
            $row = [];

            if ($includetoken) {
                $row[] = $token->token;
            }

            $row[] = $token->userid;
            $row[] = $token->username;
            $row[] = $token->email;
            $row[] = fullname($token);
            $row[] = $token->companyname;
            $row[] = $token->servicename;
            $row[] = $token->restricttocompany ? 'Yes' : 'No';
            $row[] = $token->restricttoenrolment ? 'Yes' : 'No';
            $row[] = $token->iprestriction ?: '';
            $row[] = $token->validuntil ? userdate($token->validuntil) : 'Never';
            $row[] = userdate($token->timecreated);
            $row[] = $token->lastaccess ? userdate($token->lastaccess) : 'Never';

            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
