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

        // Validate user belongs to company.
        self::validate_user_company_membership($userid, $companyid);

        // Get company to find its category.
        $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);

        // Get the company's category context.
        $context = \context_coursecat::instance($company->category);

        // Determine validity period.
        $validuntil = 0;
        if (!empty($options['validuntil'])) {
            $validuntil = $options['validuntil'];
        } else {
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
        $iomadtoken->notes = $options['notes'] ?? null;

        $iomadtoken->id = $DB->insert_record('local_sm_estratoos_plugin', $iomadtoken);
        $iomadtoken->token = $token;
        $iomadtoken->userid = $userid;

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
        global $CFG;

        // Get system context for admin token.
        $context = \context_system::instance();

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

        $sql = "SELECT lit.*, et.token, et.lastaccess, et.creatorid,
                       u.id as userid, u.username, u.email, u.firstname, u.lastname,
                       c.name as companyname, c.shortname as companyshortname,
                       es.name as servicename
                FROM {local_sm_estratoos_plugin} lit
                JOIN {external_tokens} et ON et.id = lit.tokenid
                JOIN {user} u ON u.id = et.userid
                JOIN {company} c ON c.id = lit.companyid
                JOIN {external_services} es ON es.id = et.externalserviceid
                WHERE 1=1";

        $params = [];

        if ($companyid !== null) {
            $sql .= " AND lit.companyid = :companyid";
            $params['companyid'] = $companyid;
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

        $sql = "SELECT lit.*, c.name as companyname, c.shortname as companyshortname,
                       c.category as companycategory, et.userid
                FROM {local_sm_estratoos_plugin} lit
                JOIN {external_tokens} et ON et.id = lit.tokenid
                JOIN {company} c ON c.id = lit.companyid
                WHERE et.token = :token";

        $record = $DB->get_record_sql($sql, ['token' => $token]);
        return $record ?: null;
    }

    /**
     * Revoke a token by ID.
     *
     * @param int $tokenid The local_sm_estratoos_plugin ID.
     * @return bool True if successful.
     */
    public static function revoke_token(int $tokenid): bool {
        global $DB;

        // Get the token record.
        $iomadtoken = $DB->get_record('local_sm_estratoos_plugin', ['id' => $tokenid]);
        if (!$iomadtoken) {
            return false;
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
     * @param int $companyid Company ID.
     * @param int|null $departmentid Optional department filter.
     * @return array Array of user records.
     */
    public static function get_company_users(int $companyid, ?int $departmentid = null): array {
        global $DB;

        // First try: Get users from company_users table.
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

        $users = $DB->get_records_sql($sql, $params);

        // If no users found, try alternative IOMAD table structure.
        // Some IOMAD versions use different relationships.
        if (empty($users)) {
            // Check if company has a department with users.
            $depsql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname
                       FROM {user} u
                       JOIN {company_users} cu ON cu.userid = u.id
                       JOIN {department} d ON d.id = cu.departmentid
                       WHERE d.company = :companyid2
                         AND u.deleted = 0
                         AND u.suspended = 0
                       ORDER BY u.lastname, u.firstname";

            $users = $DB->get_records_sql($depsql, ['companyid2' => $companyid]);
        }

        // Still no users? Get all non-guest, non-admin active users as fallback.
        // This helps when IOMAD tables are not fully populated.
        if (empty($users)) {
            $fallbacksql = "SELECT u.id, u.username, u.email, u.firstname, u.lastname
                            FROM {user} u
                            WHERE u.deleted = 0
                              AND u.suspended = 0
                              AND u.id > 1
                              AND u.username != 'guest'
                            ORDER BY u.lastname, u.firstname";

            $users = $DB->get_records_sql($fallbacksql);
        }

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

            // Check user belongs to company.
            if (!$DB->record_exists('company_users', ['userid' => $user->id, 'companyid' => $companyid])) {
                $result['errors'][] = [
                    'line' => $linenum + 1,
                    'value' => $value,
                    'error' => 'User is not a member of the selected company',
                ];
                continue;
            }

            $result['users'][] = $user->id;
        }

        // Remove duplicates.
        $result['users'] = array_unique($result['users']);

        return $result;
    }

    /**
     * Get batch history.
     *
     * @param int|null $companyid Filter by company.
     * @param int $limit Number of records to return.
     * @return array Array of batch records.
     */
    public static function get_batch_history(?int $companyid = null, int $limit = 50): array {
        global $DB;

        $sql = "SELECT b.*, c.name as companyname, es.name as servicename,
                       u.firstname, u.lastname
                FROM {local_sm_estratoos_plugin_batch} b
                JOIN {company} c ON c.id = b.companyid
                JOIN {external_services} es ON es.id = b.serviceid
                JOIN {user} u ON u.id = b.createdby
                WHERE 1=1";

        $params = [];

        if ($companyid !== null) {
            $sql .= " AND b.companyid = :companyid";
            $params['companyid'] = $companyid;
        }

        $sql .= " ORDER BY b.timecreated DESC";

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
}
