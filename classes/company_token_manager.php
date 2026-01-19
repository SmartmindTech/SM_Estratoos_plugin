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

require_once($CFG->dirroot . '/lib/externallib.php');

/**
 * Company token manager class.
 *
 * Handles creation, management, and validation of company-scoped tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class company_token_manager {

    /**
     * Create a company-scoped token for a user.
     *
     * @param int $userid The user ID.
     * @param int $companyid The company ID to scope the token.
     * @param int $serviceid The external service ID.
     * @param array $options Optional settings (iprestriction, validuntil, notes, etc.).
     * @return object Token record with token string.
     * @throws \moodle_exception If user doesn't belong to company.
     */
    public static function create_token(int $userid, int $companyid, int $serviceid, array $options = []): object {
        global $DB, $USER;

        // Check if IOMAD mode (companyid > 0) or standard Moodle mode (companyid = 0).
        $isiomad = util::is_iomad_installed() && $companyid > 0;

        if ($isiomad) {
            // IOMAD MODE: Validate user belongs to company.
            self::validate_user_company_membership($userid, $companyid);

            // Get company to find its category.
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);

            // Get user for token naming.
            $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname', MUST_EXIST);

            // Generate token name: FIRSTNAME_LASTNAME_COMPANY (all caps, spaces replaced with underscores).
            $tokenname = self::generate_token_name($user->firstname, $user->lastname, $company->shortname);

            // Get the company's category context.
            $context = \context_coursecat::instance($company->category);
        } else {
            // STANDARD MOODLE MODE: No company validation needed.
            // Get user for token naming.
            $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname', MUST_EXIST);

            // Get user's primary role for token naming.
            $userrole = self::get_user_primary_role($userid);

            // Generate token name: FIRSTNAME_LASTNAME_ROLE (all caps, spaces replaced with underscores).
            $tokenname = self::generate_token_name($user->firstname, $user->lastname, $userrole);

            // Use system context for standard Moodle.
            $context = \context_system::instance();
        }

        // Determine validity period.
        // Use isset() instead of !empty() to respect explicitly set 0 (never expires).
        $validuntil = 0;
        if (isset($options['validuntil'])) {
            // Explicitly set - use the value (0 means never expires).
            $validuntil = (int)$options['validuntil'];
        } else {
            // Not specified - use default if configured.
            $defaultdays = get_config('local_sm_estratoos_plugin', 'default_validity_days');
            if ($defaultdays > 0) {
                $validuntil = time() + ($defaultdays * DAYSECS);
            }
        }

        // Get IP restriction.
        $iprestriction = $options['iprestriction'] ?? '';

        // Create the standard Moodle token.
        $token = external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $serviceid,
            $userid,
            $context,
            $validuntil,
            $iprestriction
        );

        // Get the token record to link it.
        $tokenrecord = $DB->get_record('external_tokens', ['token' => $token], '*', MUST_EXIST);

        // Update the token name in Moodle's external_tokens table.
        $DB->set_field('external_tokens', 'name', $tokenname, ['id' => $tokenrecord->id]);

        // Create our extension record.
        $iomadtoken = new \stdClass();
        $iomadtoken->tokenid = $tokenrecord->id;
        $iomadtoken->companyid = $companyid;
        $iomadtoken->batchid = $options['batchid'] ?? null;
        $iomadtoken->restricttocompany = $options['restricttocompany'] ??
            get_config('local_sm_estratoos_plugin', 'default_restricttocompany');
        $iomadtoken->restricttoenrolment = $options['restricttoenrolment'] ??
            get_config('local_sm_estratoos_plugin', 'default_restricttoenrolment');
        $iomadtoken->iprestriction = $iprestriction ?: null;
        $iomadtoken->validuntil = $validuntil ?: null;
        $iomadtoken->createdby = $USER->id;
        $iomadtoken->timecreated = time();
        $iomadtoken->timemodified = time();
        // Store token name as notes (prepend to any existing notes).
        $usernotes = $options['notes'] ?? '';
        $iomadtoken->notes = $usernotes ? $tokenname . "\n" . $usernotes : $tokenname;

        $iomadtoken->id = $DB->insert_record('local_sm_estratoos_plugin', $iomadtoken);
        $iomadtoken->token = $token;
        $iomadtoken->userid = $userid;
        $iomadtoken->tokenname = $tokenname;

        return $iomadtoken;
    }

    /**
     * Create a system-wide admin token (no company restrictions).
     *
     * @param int $userid The user ID (typically the admin).
     * @param int $serviceid The external service ID.
     * @param array $options Optional settings (iprestriction, validuntil).
     * @return string The token string.
     */
    public static function create_admin_token(int $userid, int $serviceid, array $options = []): string {
        global $CFG, $DB;

        // Get system context for admin token.
        $context = \context_system::instance();

        // Get user for token naming.
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname', MUST_EXIST);

        // Generate token name: FIRSTNAME_LASTNAME_ADMIN.
        $tokenname = self::generate_token_name($user->firstname, $user->lastname, 'ADMIN');

        // Determine validity period.
        $validuntil = 0;
        if (!empty($options['validuntil'])) {
            $validuntil = $options['validuntil'];
        }

        // Get IP restriction.
        $iprestriction = $options['iprestriction'] ?? '';

        // Create the standard Moodle system token.
        $token = external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $serviceid,
            $userid,
            $context,
            $validuntil,
            $iprestriction
        );

        // Get the token record and update the name.
        $tokenrecord = $DB->get_record('external_tokens', ['token' => $token], 'id', MUST_EXIST);
        $DB->set_field('external_tokens', 'name', $tokenname, ['id' => $tokenrecord->id]);

        return $token;
    }

    /**
     * Create tokens for multiple users in batch.
     *
     * @param array $userids Array of user IDs.
     * @param int $companyid Company ID.
     * @param int $serviceid Service ID.
     * @param array $options Batch options.
     * @return object Batch result with tokens and statistics.
     */
    public static function create_batch_tokens(array $userids, int $companyid, int $serviceid, array $options = []): object {
        global $DB, $USER;

        $batchid = self::generate_uuid();
        $results = new \stdClass();
        $results->batchid = $batchid;
        $results->tokens = [];
        $results->errors = [];
        $results->successcount = 0;
        $results->failcount = 0;

        // Create batch record.
        $batch = new \stdClass();
        $batch->batchid = $batchid;
        $batch->companyid = $companyid;
        $batch->serviceid = $serviceid;
        $batch->totalusers = count($userids);
        $batch->source = $options['source'] ?? 'company';
        $batch->iprestriction = $options['iprestriction'] ?? null;
        $batch->validuntil = $options['validuntil'] ?? null;
        $batch->createdby = $USER->id;
        $batch->timecreated = time();
        $batch->status = 'processing';
        $batch->successcount = 0;
        $batch->failcount = 0;
        $batch->id = $DB->insert_record('local_sm_estratoos_plugin_batch', $batch);

        $tokenoptions = array_merge($options, ['batchid' => $batchid]);

        foreach ($userids as $userid) {
            try {
                $token = self::create_token($userid, $companyid, $serviceid, $tokenoptions);
                $results->tokens[] = $token;
                $results->successcount++;
            } catch (\Exception $e) {
                // Get user info for error reporting.
                $user = $DB->get_record('user', ['id' => $userid], 'id, username, email, firstname, lastname');
                $results->errors[] = [
                    'userid' => $userid,
                    'username' => $user ? $user->username : 'unknown',
                    'email' => $user ? $user->email : 'unknown',
                    'fullname' => $user ? fullname($user) : 'Unknown User',
                    'error' => $e->getMessage(),
                ];
                $results->failcount++;
            }
        }

        // Update batch record.
        $batch->successcount = $results->successcount;
        $batch->failcount = $results->failcount;
        $batch->status = 'completed';
        $DB->update_record('local_sm_estratoos_plugin_batch', $batch);

        // Auto-enable company if any manager tokens were created (IOMAD only).
        if (util::is_iomad_installed() && $companyid > 0 && $results->successcount > 0) {
            self::auto_enable_company_for_managers($companyid, $userids);
        }

        return $results;
    }

    /**
     * Get company tokens with filtering.
     *
     * @param int|null $companyid Filter by company (null for all).
     * @param array $filters Additional filters.
     * @return array Array of token records.
     */
    public static function get_company_tokens(?int $companyid = null, array $filters = []): array {
        global $DB;

        // Check if IOMAD is installed (company table exists).
        $isiomad = util::is_iomad_installed();

        if ($isiomad) {
            // Use LEFT JOINs to include suspended tokens (tokenid = NULL).
            $sql = "SELECT lit.*,
                           COALESCE(et.token, '') as token,
                           et.lastaccess, et.creatorid,
                           COALESCE(et.userid, 0) as userid,
                           COALESCE(u.username, '') as username,
                           COALESCE(u.email, '') as email,
                           COALESCE(u.firstname, '') as firstname,
                           COALESCE(u.lastname, '') as lastname,
                           c.name as companyname, c.shortname as companyshortname,
                           COALESCE(es.name, '') as servicename,
                           et.externalserviceid
                    FROM {local_sm_estratoos_plugin} lit
                    LEFT JOIN {external_tokens} et ON et.id = lit.tokenid
                    LEFT JOIN {user} u ON u.id = et.userid
                    LEFT JOIN {company} c ON c.id = lit.companyid
                    LEFT JOIN {external_services} es ON es.id = et.externalserviceid
                    WHERE 1=1";
        } else {
            // Standard Moodle - no company table. Use LEFT JOINs for suspended tokens.
            $sql = "SELECT lit.*,
                           COALESCE(et.token, '') as token,
                           et.lastaccess, et.creatorid,
                           COALESCE(et.userid, 0) as userid,
                           COALESCE(u.username, '') as username,
                           COALESCE(u.email, '') as email,
                           COALESCE(u.firstname, '') as firstname,
                           COALESCE(u.lastname, '') as lastname,
                           '' as companyname, '' as companyshortname,
                           COALESCE(es.name, '') as servicename,
                           et.externalserviceid
                    FROM {local_sm_estratoos_plugin} lit
                    LEFT JOIN {external_tokens} et ON et.id = lit.tokenid
                    LEFT JOIN {user} u ON u.id = et.userid
                    LEFT JOIN {external_services} es ON es.id = et.externalserviceid
                    WHERE 1=1";
        }

        $params = [];

        if ($isiomad) {
            if ($companyid !== null) {
                $sql .= " AND lit.companyid = :companyid";
                $params['companyid'] = $companyid;
            } else if (!empty($filters['companyids'])) {
                // Filter by multiple company IDs (for company managers).
                list($insql, $inparams) = $DB->get_in_or_equal($filters['companyids'], SQL_PARAMS_NAMED, 'cid');
                $sql .= " AND lit.companyid $insql";
                $params = array_merge($params, $inparams);
            }
        }

        if (!empty($filters['serviceid'])) {
            $sql .= " AND et.externalserviceid = :serviceid";
            $params['serviceid'] = $filters['serviceid'];
        }

        if (!empty($filters['userid'])) {
            $sql .= " AND et.userid = :userid";
            $params['userid'] = $filters['userid'];
        }

        if (!empty($filters['batchid'])) {
            $sql .= " AND lit.batchid = :batchid";
            $params['batchid'] = $filters['batchid'];
        }

        $sql .= " ORDER BY lit.timecreated DESC";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Get company ID for a given token string.
     *
     * @param string $token The token string.
     * @return int|null Company ID or null if not a company token.
     */
    public static function get_token_company(string $token): ?int {
        global $DB;

        $sql = "SELECT lit.companyid
                FROM {local_sm_estratoos_plugin} lit
                JOIN {external_tokens} et ON et.id = lit.tokenid
                WHERE et.token = :token";

        $companyid = $DB->get_field_sql($sql, ['token' => $token]);
        return $companyid ?: null;
    }

    /**
     * Get token restrictions for a given token.
     *
     * @param string $token The token string.
     * @return object|null Token restrictions or null if not found.
     */
    public static function get_token_restrictions(string $token): ?object {
        global $DB;

        try {
            // Check if IOMAD is installed (company table exists).
            $isiomad = util::is_iomad_installed();

            if ($isiomad) {
                $sql = "SELECT lit.*, c.name as companyname, c.shortname as companyshortname,
                               c.category as companycategory, et.userid
                        FROM {local_sm_estratoos_plugin} lit
                        JOIN {external_tokens} et ON et.id = lit.tokenid
                        LEFT JOIN {company} c ON c.id = lit.companyid
                        WHERE et.token = :token";
            } else {
                // Standard Moodle - no company table.
                $sql = "SELECT lit.*, '' as companyname, '' as companyshortname,
                               0 as companycategory, et.userid
                        FROM {local_sm_estratoos_plugin} lit
                        JOIN {external_tokens} et ON et.id = lit.tokenid
                        WHERE et.token = :token";
            }

            $record = $DB->get_record_sql($sql, ['token' => $token]);
            return $record ?: null;
        } catch (\dml_exception $e) {
            // Database error (e.g., missing table, schema mismatch) - return null gracefully.
            debugging('get_token_restrictions: Database error - ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Check if a token is active (not suspended).
     *
     * This method checks the 'active' field in the local_sm_estratoos_plugin table.
     * Tokens are suspended when their company access is disabled.
     *
     * @param string $token The token string.
     * @return bool True if token is active, false if suspended or not found.
     */
    public static function is_token_active(string $token): bool {
        global $DB;

        try {
            $sql = "SELECT lit.active
                    FROM {local_sm_estratoos_plugin} lit
                    JOIN {external_tokens} et ON et.id = lit.tokenid
                    WHERE et.token = :token";

            $active = $DB->get_field_sql($sql, ['token' => $token]);

            // If token not found in our table, it's not a plugin token - consider it active.
            if ($active === false) {
                return true;
            }

            return (bool)$active;
        } catch (\dml_exception $e) {
            // Database error - log and consider active (fail open for non-plugin tokens).
            debugging('is_token_active: Database error - ' . $e->getMessage(), DEBUG_DEVELOPER);
            return true;
        }
    }

    /**
     * Revoke a token by ID.
     *
     * @param int $tokenid The local_sm_estratoos_plugin ID.
     * @return bool True if successful.
     */
    public static function revoke_token(int $tokenid): bool {
        global $DB, $USER;

        // Get the token record with all info for logging.
        $iomadtoken = $DB->get_record('local_sm_estratoos_plugin', ['id' => $tokenid]);
        if (!$iomadtoken) {
            return false;
        }

        // Get the external token info.
        $externaltoken = $DB->get_record('external_tokens', ['id' => $iomadtoken->tokenid]);
        if ($externaltoken) {
            // Get user info.
            $user = $DB->get_record('user', ['id' => $externaltoken->userid], 'id, username, firstname, lastname');

            // Get company info if available.
            $companyname = null;
            if ($iomadtoken->companyid) {
                $company = $DB->get_record('company', ['id' => $iomadtoken->companyid], 'shortname');
                $companyname = $company ? $company->shortname : null;
            }

            // Log the deletion.
            $deletion = new \stdClass();
            $deletion->batchid = $iomadtoken->batchid;
            $deletion->tokenname = $externaltoken->name ?? 'Unknown';
            $deletion->username = $user ? $user->username : 'unknown';
            $deletion->userfullname = $user ? fullname($user) : 'Unknown User';
            $deletion->companyid = $iomadtoken->companyid;
            $deletion->companyname = $companyname;
            $deletion->deletedby = $USER->id;
            $deletion->timedeleted = time();

            $DB->insert_record('local_sm_estratoos_plugin_del', $deletion);
        }

        // Delete from external_tokens.
        $DB->delete_records('external_tokens', ['id' => $iomadtoken->tokenid]);

        // Delete our record.
        $DB->delete_records('local_sm_estratoos_plugin', ['id' => $tokenid]);

        return true;
    }

    /**
     * Revoke multiple tokens.
     *
     * @param array $tokenids Array of local_sm_estratoos_plugin IDs.
     * @return int Number of tokens revoked.
     */
    public static function revoke_tokens(array $tokenids): int {
        $count = 0;
        foreach ($tokenids as $tokenid) {
            if (self::revoke_token($tokenid)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Update token settings.
     *
     * @param int $tokenid The local_sm_estratoos_plugin ID.
     * @param array $settings Settings to update.
     * @return bool True if successful.
     */
    public static function update_token(int $tokenid, array $settings): bool {
        global $DB;

        $iomadtoken = $DB->get_record('local_sm_estratoos_plugin', ['id' => $tokenid]);
        if (!$iomadtoken) {
            return false;
        }

        // Update allowed fields.
        $allowedfields = ['restricttocompany', 'restricttoenrolment', 'iprestriction', 'validuntil', 'notes'];
        foreach ($allowedfields as $field) {
            if (array_key_exists($field, $settings)) {
                $iomadtoken->$field = $settings[$field];
            }
        }

        $iomadtoken->timemodified = time();

        // Update the external_tokens record if needed.
        if (isset($settings['iprestriction']) || isset($settings['validuntil'])) {
            $externaltoken = $DB->get_record('external_tokens', ['id' => $iomadtoken->tokenid]);
            if ($externaltoken) {
                if (isset($settings['iprestriction'])) {
                    $externaltoken->iprestriction = $settings['iprestriction'];
                }
                if (isset($settings['validuntil'])) {
                    $externaltoken->validuntil = $settings['validuntil'];
                }
                $DB->update_record('external_tokens', $externaltoken);
            }
        }

        return $DB->update_record('local_sm_estratoos_plugin', $iomadtoken);
    }

    /**
     * Get users for a company.
     *
     * This method retrieves users associated with a company through multiple sources:
     * 1. Direct association in company_users table
     * 2. Enrollment in courses under the company's category
     *
     * @param int $companyid Company ID.
     * @param int|null $departmentid Optional department filter.
     * @return array Array of user records.
     */
    public static function get_company_users(int $companyid, ?int $departmentid = null): array {
        global $DB;

        $users = [];

        // Method 1: Get users from company_users table.
        $sql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname
                FROM {user} u
                JOIN {company_users} cu ON cu.userid = u.id
                WHERE cu.companyid = :companyid
                  AND u.deleted = 0
                  AND u.suspended = 0";

        $params = ['companyid' => $companyid];

        if ($departmentid !== null && $departmentid > 0) {
            $sql .= " AND cu.departmentid = :departmentid";
            $params['departmentid'] = $departmentid;
        }

        $sql .= " ORDER BY u.lastname, u.firstname";

        $companyusers = $DB->get_records_sql($sql, $params);
        if (!empty($companyusers)) {
            $users = $companyusers;
        }

        // Method 2: Get users enrolled in courses under the company's category.
        // This is important because many users are only enrolled in courses,
        // not directly associated with the company.
        $company = $DB->get_record('company', ['id' => $companyid], 'id, category');
        if ($company && !empty($company->category)) {
            // Get all category IDs (including subcategories).
            $categoryids = self::get_category_and_children($company->category);

            if (!empty($categoryids)) {
                list($insql, $inparams) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');

                $enrolledsql = "SELECT DISTINCT u.id, u.username, u.email, u.firstname, u.lastname
                                FROM {user} u
                                JOIN {user_enrolments} ue ON ue.userid = u.id
                                JOIN {enrol} e ON e.id = ue.enrolid
                                JOIN {course} c ON c.id = e.courseid
                                WHERE c.category {$insql}
                                  AND u.deleted = 0
                                  AND u.suspended = 0
                                  AND ue.status = 0
                                ORDER BY u.lastname, u.firstname";

                $enrolledusers = $DB->get_records_sql($enrolledsql, $inparams);

                // Merge with existing users (avoiding duplicates).
                foreach ($enrolledusers as $user) {
                    if (!isset($users[$user->id])) {
                        $users[$user->id] = $user;
                    }
                }
            }
        }

        // Method 3: Alternative IOMAD table structure (department relationship).
        if (empty($users)) {
            $depsql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id
                       JOIN {department} d ON d.id = cu.departmentid
                       WHERE d.company = :companyid2
                         AND u.deleted = 0
                         AND u.suspended = 0
                       ORDER BY u.lastname, u.firstname";

            $depusers = $DB->get_records_sql($depsql, ['companyid2' => $companyid]);
            if (!empty($depusers)) {
                $users = $depusers;
            }
        }

        // Fallback: If still no users found, this might be a misconfigured company.
        // Don't return all users as that would be incorrect for IOMAD.
        // Instead, return empty and let the UI show a message.

        return $users;
    }

    /**
     * Get users from CSV data.
     *
     * @param string $csvdata CSV content.
     * @param string $field Field to match (id, username, email).
     * @param int $companyid Company ID to validate against.
     * @return array Array with 'users' and 'errors'.
     */
    public static function get_users_from_csv(string $csvdata, string $field, int $companyid): array {
        global $DB;

        $result = [
            'users' => [],
            'errors' => [],
        ];

        // Parse CSV.
        $lines = preg_split('/\r\n|\r|\n/', trim($csvdata));
        if (empty($lines)) {
            return $result;
        }

        // Skip header if it looks like one.
        $firstline = strtolower(trim($lines[0]));
        if (in_array($firstline, ['id', 'userid', 'username', 'email', 'user id', 'user_id'])) {
            array_shift($lines);
        }

        foreach ($lines as $linenum => $line) {
            $value = trim($line);
            if (empty($value)) {
                continue;
            }

            // Handle CSV with multiple columns - take first column.
            if (strpos($value, ',') !== false) {
                $parts = str_getcsv($value);
                $value = trim($parts[0]);
            }

            // Find user.
            $user = null;
            switch ($field) {
                case 'id':
                    $user = $DB->get_record('user', ['id' => $value, 'deleted' => 0]);
                    break;
                case 'username':
                    $user = $DB->get_record('user', ['username' => $value, 'deleted' => 0]);
                    break;
                case 'email':
                    $user = $DB->get_record('user', ['email' => $value, 'deleted' => 0]);
                    break;
            }

            if (!$user) {
                $result['errors'][] = [
                    'line' => $linenum + 1,
                    'value' => $value,
                    'error' => 'User not found',
                ];
                continue;
            }

            // Check user belongs to company (only for IOMAD mode with valid company).
            $isiomad = util::is_iomad_installed();
            if ($isiomad && $companyid > 0) {
                if (!$DB->record_exists('company_users', ['userid' => $user->id, 'companyid' => $companyid])) {
                    $result['errors'][] = [
                        'line' => $linenum + 1,
                        'value' => $value,
                        'error' => 'User is not a member of the selected company',
                    ];
                    continue;
                }
            }

            $result['users'][] = $user->id;
        }

        // Remove duplicates.
        $result['users'] = array_unique($result['users']);

        return $result;
    }

    /**
     * Parse an Excel file and extract user identifiers.
     *
     * @param string $filedata Binary file content.
     * @param string $field Field to match (id, username, email).
     * @param int $companyid Company ID to validate against (0 for standard Moodle).
     * @return array Array with 'users' and 'errors'.
     */
    public static function get_users_from_excel(string $filedata, string $field, int $companyid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/phpspreadsheet/vendor/autoload.php');

        $result = [
            'users' => [],
            'errors' => [],
        ];

        // Save content to temp file.
        $tempfile = tempnam($CFG->tempdir, 'excel_');
        file_put_contents($tempfile, $filedata);

        try {
            // Load spreadsheet.
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempfile);
            $worksheet = $spreadsheet->getActiveSheet();

            // Get highest row number.
            $highestrow = $worksheet->getHighestRow();

            // Determine which column to use based on field.
            $colindex = ['id' => 0, 'username' => 1, 'email' => 2];
            $col = $colindex[$field] ?? 0;

            // Check for IOMAD.
            $isiomad = util::is_iomad_installed();

            // Read rows (skip header row if present).
            $startrow = 1;
            $firstcell = strtolower(trim($worksheet->getCellByColumnAndRow(1, 1)->getValue() ?? ''));
            if (in_array($firstcell, ['id', 'userid', 'username', 'email', 'user id', 'user_id', '#'])) {
                $startrow = 2;
            }
            // Skip instruction rows (those starting with #).
            while ($startrow <= $highestrow) {
                $cellval = trim($worksheet->getCellByColumnAndRow(1, $startrow)->getValue() ?? '');
                if (empty($cellval) || substr($cellval, 0, 1) !== '#') {
                    break;
                }
                $startrow++;
            }

            for ($row = $startrow; $row <= $highestrow; $row++) {
                // Try to get value from the selected field column.
                $value = trim($worksheet->getCellByColumnAndRow($col + 1, $row)->getValue() ?? '');

                // If empty, try other columns.
                if (empty($value)) {
                    for ($c = 1; $c <= 3; $c++) {
                        $val = trim($worksheet->getCellByColumnAndRow($c, $row)->getValue() ?? '');
                        if (!empty($val)) {
                            $value = $val;
                            // Update field based on which column had data.
                            $field = array_search($c - 1, $colindex);
                            break;
                        }
                    }
                }

                if (empty($value)) {
                    continue;
                }

                // Find user.
                $user = null;
                switch ($field) {
                    case 'id':
                        $user = $DB->get_record('user', ['id' => $value, 'deleted' => 0]);
                        break;
                    case 'username':
                        $user = $DB->get_record('user', ['username' => $value, 'deleted' => 0]);
                        break;
                    case 'email':
                        $user = $DB->get_record('user', ['email' => $value, 'deleted' => 0]);
                        break;
                }

                if (!$user) {
                    $result['errors'][] = [
                        'line' => $row,
                        'value' => $value,
                        'error' => 'User not found',
                    ];
                    continue;
                }

                // Check user belongs to company (only for IOMAD mode with valid company).
                if ($isiomad && $companyid > 0) {
                    if (!$DB->record_exists('company_users', ['userid' => $user->id, 'companyid' => $companyid])) {
                        $result['errors'][] = [
                            'line' => $row,
                            'value' => $value,
                            'error' => 'User is not a member of the selected company',
                        ];
                        continue;
                    }
                }

                $result['users'][] = $user->id;
            }

        } catch (\Exception $e) {
            $result['errors'][] = [
                'line' => 0,
                'value' => '',
                'error' => 'Error reading Excel file: ' . $e->getMessage(),
            ];
        } finally {
            // Clean up temp file.
            if (file_exists($tempfile)) {
                unlink($tempfile);
            }
        }

        // Remove duplicates.
        $result['users'] = array_unique($result['users']);

        return $result;
    }

    /**
     * Get batch history, optionally filtered by company.
     *
     * @param int|array|null $companyid Single company ID, array of company IDs, or null for all.
     * @param int $limit Maximum number of batches to return.
     * @return array Array of batch records.
     */
    public static function get_batch_history($companyid = null, int $limit = 50): array {
        global $DB;

        // Check if IOMAD is installed (company table exists).
        $isiomad = util::is_iomad_installed();

        if ($isiomad) {
            $sql = "SELECT b.*, c.name as companyname, es.name as servicename,
                           u.firstname, u.lastname
                    FROM {local_sm_estratoos_plugin_batch} b
                    LEFT JOIN {company} c ON c.id = b.companyid
                    JOIN {external_services} es ON es.id = b.serviceid
                    JOIN {user} u ON u.id = b.createdby
                    WHERE 1=1";
        } else {
            // Standard Moodle - no company table.
            $sql = "SELECT b.*, '' as companyname, es.name as servicename,
                           u.firstname, u.lastname
                    FROM {local_sm_estratoos_plugin_batch} b
                    JOIN {external_services} es ON es.id = b.serviceid
                    JOIN {user} u ON u.id = b.createdby
                    WHERE 1=1";
        }

        $params = [];

        if ($isiomad) {
            if (is_array($companyid) && !empty($companyid)) {
                // Multiple company IDs.
                list($insql, $inparams) = $DB->get_in_or_equal($companyid, SQL_PARAMS_NAMED, 'cid');
                $sql .= " AND b.companyid $insql";
                $params = array_merge($params, $inparams);
            } else if ($companyid !== null && !is_array($companyid)) {
                // Single company ID.
                $sql .= " AND b.companyid = :companyid";
                $params['companyid'] = $companyid;
            }
        }

        $sql .= " ORDER BY b.timecreated DESC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Get deletion history for a specific batch.
     *
     * @param string $batchid The batch UUID.
     * @return array Array of deletion records.
     */
    public static function get_batch_deletions(string $batchid): array {
        global $DB;

        return $DB->get_records('local_sm_estratoos_plugin_del', ['batchid' => $batchid], 'timedeleted DESC');
    }

    /**
     * Get recent deletions (not tied to a batch, or all).
     *
     * @param int|null $companyid Filter by company ID (null for all).
     * @param int $limit Maximum number of records.
     * @return array Array of deletion records grouped by date.
     */
    public static function get_recent_deletions($companyid = null, int $limit = 100): array {
        global $DB;

        $sql = "SELECT d.*, u.firstname as deleterfirstname, u.lastname as deleterlastname
                FROM {local_sm_estratoos_plugin_del} d
                JOIN {user} u ON u.id = d.deletedby
                WHERE 1=1";

        $params = [];

        if ($companyid !== null) {
            if (is_array($companyid) && !empty($companyid)) {
                list($insql, $inparams) = $DB->get_in_or_equal($companyid, SQL_PARAMS_NAMED, 'cid');
                $sql .= " AND d.companyid $insql";
                $params = array_merge($params, $inparams);
            } else if (!is_array($companyid)) {
                $sql .= " AND d.companyid = :companyid";
                $params['companyid'] = $companyid;
            }
        }

        $sql .= " ORDER BY d.timedeleted DESC";

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Validate user belongs to company.
     *
     * Checks if the user is enrolled in any course within the company's category,
     * or if the user is directly associated with the company in company_users table.
     *
     * @param int $userid User ID.
     * @param int $companyid Company ID.
     * @throws \moodle_exception If user doesn't belong to company.
     */
    private static function validate_user_company_membership(int $userid, int $companyid): void {
        global $DB;

        // First check: user is in company_users table.
        if ($DB->record_exists('company_users', ['userid' => $userid, 'companyid' => $companyid])) {
            return;
        }

        // Second check: user is enrolled in any course within the company's category.
        $company = $DB->get_record('company', ['id' => $companyid], 'id, category');
        if (!$company || empty($company->category)) {
            throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin', '',
                ['userid' => $userid, 'companyid' => $companyid]);
        }

        // Get all courses in the company's category (including subcategories).
        $categoryids = self::get_category_and_children($company->category);

        if (!empty($categoryids)) {
            list($insql, $params) = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
            $params['userid'] = $userid;

            $sql = "SELECT DISTINCT ue.id
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {course} c ON c.id = e.courseid
                    WHERE c.category {$insql}
                      AND ue.userid = :userid
                      AND ue.status = 0";

            if ($DB->record_exists_sql($sql, $params)) {
                return;
            }
        }

        throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin', '',
            ['userid' => $userid, 'companyid' => $companyid]);
    }

    /**
     * Get category ID and all its child categories.
     *
     * @param int $categoryid Parent category ID.
     * @return array Array of category IDs.
     */
    private static function get_category_and_children(int $categoryid): array {
        global $DB;

        $categories = [$categoryid];

        // Get all child categories recursively.
        $children = $DB->get_records('course_categories', ['parent' => $categoryid], '', 'id');
        foreach ($children as $child) {
            $categories = array_merge($categories, self::get_category_and_children($child->id));
        }

        return $categories;
    }

    /**
     * Get user's primary role for token naming (non-IOMAD mode).
     *
     * Priority: MANAGER > TEACHER > OTHER > STUDENT
     * Returns translated role name based on current language.
     *
     * @param int $userid User ID.
     * @return string Role name in uppercase (translated).
     */
    private static function get_user_primary_role(int $userid): string {
        global $DB;

        // Get all role assignments for this user.
        $roles = $DB->get_records_sql(
            "SELECT DISTINCT r.shortname
             FROM {role_assignments} ra
             JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = ?",
            [$userid]
        );

        $hasmanager = false;
        $hasteacher = false;
        $hasother = false;
        $hasstudent = false;

        foreach ($roles as $role) {
            $rolename = strtolower($role->shortname);

            // Check for manager: role contains "manager" or "admin" (but user is not site admin).
            if ((strpos($rolename, 'manager') !== false || strpos($rolename, 'admin') !== false)
                && !is_siteadmin($userid)) {
                $hasmanager = true;
            } else if (strpos($rolename, 'teacher') !== false) {
                $hasteacher = true;
            } else if ($rolename === 'student' || $rolename === 'alumno' || $rolename === 'estudante') {
                $hasstudent = true;
            } else if ($rolename !== 'user' && $rolename !== 'authenticated' && $rolename !== 'guest') {
                // Other roles like "chatbot", custom roles, etc.
                $hasother = true;
            }
        }

        // Return translated role name based on priority.
        if ($hasmanager) {
            return get_string('tokenrole_manager', 'local_sm_estratoos_plugin');
        } else if ($hasteacher) {
            return get_string('tokenrole_teacher', 'local_sm_estratoos_plugin');
        } else if ($hasother) {
            return get_string('tokenrole_other', 'local_sm_estratoos_plugin');
        } else {
            return get_string('tokenrole_student', 'local_sm_estratoos_plugin');
        }
    }

    /**
     * Generate a UUID v4.
     *
     * @return string UUID string.
     */
    private static function generate_uuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate a formatted token name.
     *
     * Format: FIRSTNAME_LASTNAME_COMPANY (all caps, spaces replaced with underscores).
     * For admin tokens, use FIRSTNAME_LASTNAME_ADMIN_TOKEN.
     *
     * @param string $firstname User's first name.
     * @param string $lastname User's last name.
     * @param string $suffix Company shortname or 'ADMIN_TOKEN' for admin tokens.
     * @return string Formatted token name in uppercase.
     */
    public static function generate_token_name(string $firstname, string $lastname, string $suffix): string {
        // Replace spaces with underscores and convert to uppercase.
        $firstname = strtoupper(str_replace(' ', '_', trim($firstname)));
        $lastname = strtoupper(str_replace(' ', '_', trim($lastname)));
        $suffix = strtoupper(str_replace(' ', '_', trim($suffix)));

        return $firstname . '_' . $lastname . '_' . $suffix;
    }

    /**
     * Ensure user has the webservice/rest:use capability at system level.
     *
     * This method assigns a web service role to the user at system level if they don't
     * already have the required capability. This is necessary because students/teachers
     * typically only have their roles at course level, not system level.
     *
     * @param int $userid User ID.
     */
    private static function ensure_webservice_capability(int $userid): void {
        global $DB;

        $systemcontext = \context_system::instance();

        // Check if user already has the webservice/rest:use capability at system level.
        if (has_capability('webservice/rest:use', $systemcontext, $userid)) {
            return; // User already has the capability, nothing to do.
        }

        // User doesn't have the capability. We need to assign a role that grants it.
        // First, try to find a role that already has this capability at system level.
        // Prioritize student/teacher roles (not 'user' - authenticated users).
        $sql = "SELECT DISTINCT r.id, r.shortname
                FROM {role} r
                JOIN {role_capabilities} rc ON rc.roleid = r.id
                JOIN {context} c ON c.id = rc.contextid
                WHERE rc.capability = :capability
                  AND rc.permission = :permission
                  AND c.contextlevel = :contextlevel
                  AND r.shortname IN ('student', 'teacher', 'editingteacher')
                ORDER BY CASE r.shortname
                    WHEN 'student' THEN 1
                    WHEN 'teacher' THEN 2
                    WHEN 'editingteacher' THEN 3
                    ELSE 4
                END
                LIMIT 1";

        $role = $DB->get_record_sql($sql, [
            'capability' => 'webservice/rest:use',
            'permission' => CAP_ALLOW,
            'contextlevel' => CONTEXT_SYSTEM,
        ]);

        if ($role) {
            // Found a role with the capability. Check if user already has this role at system level.
            $hasrole = $DB->record_exists('role_assignments', [
                'roleid' => $role->id,
                'contextid' => $systemcontext->id,
                'userid' => $userid,
            ]);

            if (!$hasrole) {
                // Assign the role to the user at system level.
                role_assign($role->id, $userid, $systemcontext->id);
            }
        } else {
            // No role found with the capability. Configure the 'student' role as fallback.
            $studentrole = $DB->get_record('role', ['shortname' => 'student']);

            if ($studentrole) {
                // Add the capability to the role if it doesn't have it.
                $existingcap = $DB->get_record('role_capabilities', [
                    'roleid' => $studentrole->id,
                    'capability' => 'webservice/rest:use',
                    'contextid' => $systemcontext->id,
                ]);

                if (!$existingcap) {
                    $DB->insert_record('role_capabilities', [
                        'roleid' => $studentrole->id,
                        'capability' => 'webservice/rest:use',
                        'contextid' => $systemcontext->id,
                        'permission' => CAP_ALLOW,
                        'timemodified' => time(),
                        'modifierid' => get_admin()->id,
                    ]);
                }

                // Assign the role to the user at system level.
                $hasrole = $DB->record_exists('role_assignments', [
                    'roleid' => $studentrole->id,
                    'contextid' => $systemcontext->id,
                    'userid' => $userid,
                ]);

                if (!$hasrole) {
                    role_assign($studentrole->id, $userid, $systemcontext->id);
                }
            }
        }

        // Clear access cache for this user to ensure new capability takes effect immediately.
        // Use mark_user_dirty to invalidate the user's capability cache.
        mark_user_dirty($userid);
    }

    /**
     * Auto-enable a company if any of the given users is a manager.
     *
     * When creating tokens for managers (company_users.managertype > 0),
     * automatically enable the company in the plugin access table.
     * This ensures that companies with manager tokens are always enabled.
     *
     * @param int $companyid The company ID.
     * @param array $userids Array of user IDs that received tokens.
     */
    private static function auto_enable_company_for_managers(int $companyid, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        // Check if any of these users is a manager for this company.
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params['companyid'] = $companyid;

        $hasmanager = $DB->record_exists_select(
            'company_users',
            "companyid = :companyid AND userid $insql AND managertype > 0",
            $params
        );

        if (!$hasmanager) {
            return; // No managers among the users, nothing to do.
        }

        // A manager received a token - enable the company.
        $accessrecord = $DB->get_record('local_sm_estratoos_plugin_access', ['companyid' => $companyid]);

        if ($accessrecord) {
            // Update existing record to enabled.
            if (!$accessrecord->enabled) {
                $accessrecord->enabled = 1;
                $accessrecord->timemodified = time();
                $DB->update_record('local_sm_estratoos_plugin_access', $accessrecord);
            }
        } else {
            // Create new access record with enabled = 1.
            $newaccess = new \stdClass();
            $newaccess->companyid = $companyid;
            $newaccess->enabled = 1;
            $newaccess->timecreated = time();
            $newaccess->timemodified = time();
            $DB->insert_record('local_sm_estratoos_plugin_access', $newaccess);
        }
    }
}
