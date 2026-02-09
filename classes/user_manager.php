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
 * User manager class.
 *
 * Handles user creation, batch user creation, CSV parsing,
 * and new user notification queries.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_manager {

    /**
     * Generate a unique username from first and last name.
     *
     * Format: firstname_lastname_6digits (e.g., john_doe_384721)
     * Lowercase, accents removed, non-alphanumeric replaced with underscore.
     *
     * @param string $firstname User's first name.
     * @param string $lastname User's last name.
     * @return string Generated unique username.
     */
    public static function generate_username(string $firstname, string $lastname): string {
        global $DB;

        // Remove accents and convert to lowercase.
        $first = \core_text::specialtoascii(strtolower(trim($firstname)));
        $last = \core_text::specialtoascii(strtolower(trim($lastname)));

        // Replace non-alphanumeric chars with underscore.
        $first = preg_replace('/[^a-z0-9]/', '_', $first);
        $last = preg_replace('/[^a-z0-9]/', '_', $last);

        // Collapse multiple underscores.
        $first = preg_replace('/_+/', '_', trim($first, '_'));
        $last = preg_replace('/_+/', '_', trim($last, '_'));

        // Build base: firstname_lastname.
        $base = $first . '_' . $last;

        // Truncate base to 85 chars (Moodle max username = 100, leaves room for _6digits).
        if (\core_text::strlen($base) > 85) {
            $base = \core_text::substr($base, 0, 85);
            // Remove trailing underscore from truncation.
            $base = rtrim($base, '_');
        }

        // Try up to 10 times with random 6-digit suffix.
        for ($i = 0; $i < 10; $i++) {
            $suffix = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $username = $base . '_' . $suffix;

            if (!$DB->record_exists('user', ['username' => $username])) {
                return $username;
            }
        }

        // Fallback: use timestamp + random for guaranteed uniqueness.
        $suffix = time() . '_' . random_int(100, 999);
        $username = $base . '_' . $suffix;

        // Ensure it fits within 100 chars.
        if (\core_text::strlen($username) > 100) {
            $username = \core_text::substr($username, 0, 100);
        }

        return $username;
    }

    /**
     * Generate a secure password that meets Moodle's password policy.
     *
     * Generates a random password of 12+ characters with uppercase, lowercase,
     * digits, and symbols. Validates against Moodle's check_password_policy().
     *
     * @return string Generated password.
     */
    public static function generate_password(): string {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%&*+-=?';

        // Try up to 20 times to generate a valid password.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $password = '';

            // Ensure at least 2 of each character type.
            for ($i = 0; $i < 2; $i++) {
                $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
                $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
                $password .= $digits[random_int(0, strlen($digits) - 1)];
                $password .= $symbols[random_int(0, strlen($symbols) - 1)];
            }

            // Fill remaining chars to reach 12+ total.
            $allchars = $uppercase . $lowercase . $digits . $symbols;
            $remaining = 12 - strlen($password);
            for ($i = 0; $i < $remaining; $i++) {
                $password .= $allchars[random_int(0, strlen($allchars) - 1)];
            }

            // Shuffle the password to avoid predictable patterns.
            $chars = str_split($password);
            for ($i = count($chars) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                $tmp = $chars[$i];
                $chars[$i] = $chars[$j];
                $chars[$j] = $tmp;
            }
            $password = implode('', $chars);

            // Validate against Moodle's password policy.
            $errmsg = '';
            if (check_password_policy($password, $errmsg)) {
                return $password;
            }
        }

        // Last resort fallback: a known-good pattern.
        return 'Sm@rt' . random_int(1000, 9999) . '!Aa';
    }

    /**
     * Encrypt a password using SmartLearning's RSA public key.
     *
     * Reads the public key from plugin config, encrypts with OAEP padding,
     * and returns base64-encoded ciphertext.
     *
     * @param string $plaintext The password to encrypt.
     * @return string Base64-encoded encrypted password.
     * @throws \moodle_exception If public key is not configured or encryption fails.
     */
    public static function encrypt_password(string $plaintext): string {
        $publickey = get_config('local_sm_estratoos_plugin', 'rsa_public_key');

        if (empty($publickey)) {
            throw new \moodle_exception('no_public_key', 'local_sm_estratoos_plugin', '',
                null, 'RSA key pair not generated. Run plugin upgrade or reinstall.');
        }

        $encrypted = '';
        $result = openssl_public_encrypt($plaintext, $encrypted, $publickey, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            throw new \moodle_exception('encryption_error', 'local_sm_estratoos_plugin', '',
                null, 'Failed to encrypt password with RSA public key');
        }

        return base64_encode($encrypted);
    }

    /**
     * Detect timezone from a country code.
     *
     * Uses PHP's DateTimeZone to look up timezones for a given ISO 3166-1 alpha-2
     * country code. Returns the first timezone found, or Moodle's server default ('99').
     *
     * @param string $countrycode ISO 3166-1 alpha-2 country code (e.g., 'BR', 'US').
     * @return string Timezone identifier or '99' for server default.
     */
    public static function detect_timezone(string $countrycode): string {
        $countrycode = strtoupper(trim($countrycode));

        if (strlen($countrycode) !== 2) {
            return '99';
        }

        try {
            $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $countrycode);

            if (!empty($timezones)) {
                return $timezones[0];
            }
        } catch (\Exception $e) {
            // Invalid country code or other error.
        }

        return '99';
    }

    /**
     * Resolve a country input to an ISO code and name.
     *
     * If the input is 2 characters, treats it as an ISO code and looks up the name.
     * If longer, tries to match against Moodle's country lists in en, es, and pt_br.
     *
     * @param string $input Country code or name.
     * @return object|null Object with 'code' and 'name' properties, or null if not found.
     */
    public static function resolve_country(string $input): ?object {
        $input = trim($input);

        if (empty($input)) {
            return null;
        }

        $stringmanager = get_string_manager();
        $languages = ['en', 'es', 'pt_br'];

        if (\core_text::strlen($input) === 2) {
            // Treat as ISO code.
            $code = strtoupper($input);

            // Look up the name in available languages.
            foreach ($languages as $lang) {
                $countries = $stringmanager->get_list_of_countries(true, $lang);
                if (isset($countries[$code])) {
                    $result = new \stdClass();
                    $result->code = $code;
                    $result->name = $countries[$code];
                    return $result;
                }
            }

            return null;
        }

        // Longer input: try to match against country names from Moodle's language packs.
        $inputlower = \core_text::strtolower($input);

        foreach ($languages as $lang) {
            $countries = $stringmanager->get_list_of_countries(true, $lang);
            foreach ($countries as $code => $name) {
                if (\core_text::strtolower($name) === $inputlower) {
                    $result = new \stdClass();
                    $result->code = $code;
                    $result->name = $name;
                    return $result;
                }
            }
        }

        // Fallback: hardcoded common Spanish/Portuguese country names
        // (in case Moodle language packs are not installed).
        $fallbackmap = [
            // Spanish names.
            'alemania' => 'DE', 'argentina' => 'AR', 'bolivia' => 'BO',
            'brasil' => 'BR', 'canadá' => 'CA', 'chile' => 'CL',
            'colombia' => 'CO', 'corea del sur' => 'KR', 'costa rica' => 'CR',
            'cuba' => 'CU', 'ecuador' => 'EC', 'el salvador' => 'SV',
            'españa' => 'ES', 'estados unidos' => 'US',
            'estados unidos de américa' => 'US', 'francia' => 'FR',
            'guatemala' => 'GT', 'honduras' => 'HN', 'india' => 'IN',
            'inglaterra' => 'GB', 'italia' => 'IT', 'japón' => 'JP',
            'méxico' => 'MX', 'nicaragua' => 'NI', 'panamá' => 'PA',
            'paraguay' => 'PY', 'perú' => 'PE', 'portugal' => 'PT',
            'puerto rico' => 'PR', 'reino unido' => 'GB',
            'república dominicana' => 'DO', 'rusia' => 'RU',
            'uruguay' => 'UY', 'venezuela' => 'VE',
            // Portuguese names (different from Spanish/English).
            'alemanha' => 'DE', 'coreia do sul' => 'KR',
            'espanha' => 'ES', 'estados unidos da américa' => 'US',
            'grã-bretanha' => 'GB', 'índia' => 'IN', 'japão' => 'JP',
            'reino unido' => 'GB', 'rússia' => 'RU',
        ];

        if (isset($fallbackmap[$inputlower])) {
            $code = $fallbackmap[$inputlower];
            // Get the English name from Moodle.
            $encountries = $stringmanager->get_list_of_countries(true, 'en');
            $result = new \stdClass();
            $result->code = $code;
            $result->name = $encountries[$code] ?? $input;
            return $result;
        }

        return null;
    }

    /**
     * Create a single user in Moodle with token and company assignment.
     *
     * This is the main user creation method. It validates input, creates the Moodle user,
     * generates/encrypts the password, creates a web service token, and assigns
     * the user to a company (IOMAD).
     *
     * @param array $userdata User data array with keys: firstname, lastname, email,
     *   username, password, generate_password, phone_intl_code, phone, birthdate,
     *   city, state_province, country, timezone, companyid, serviceid.
     * @param string $source Source identifier (e.g., 'api', 'batch_api', 'csv').
     * @return object Result object with success, error_code, message, userid, username,
     *   token, password, encrypted_password, moodle_url.
     */
    public static function create_user(array $userdata, string $source = 'api'): object {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        // Initialize result object.
        $result = (object)[
            'success' => false,
            'error_code' => '',
            'message' => '',
            'userid' => 0,
            'username' => '',
            'email' => trim($userdata['email'] ?? ''),
            'token' => '',
            'password' => '',
            'encrypted_password' => '',
            'moodle_url' => $CFG->wwwroot,
        ];

        // Step 1: Validate fields.
        $validation = self::validate_user_data($userdata);
        if (!$validation->valid) {
            $result->error_code = $validation->error_code;
            $result->message = $validation->message;
            return $result;
        }

        // Step 2: Generate username if empty.
        $username = trim($userdata['username'] ?? '');
        if (empty($username)) {
            $username = self::generate_username($userdata['firstname'], $userdata['lastname']);
        }

        // Step 3: Handle password.
        $generatepassword = !empty($userdata['generate_password']);
        $plainpassword = '';

        if ($generatepassword) {
            $plainpassword = self::generate_password();
        } else {
            $plainpassword = $userdata['password'] ?? '';
            if (!empty($plainpassword)) {
                // Validate password against Moodle policy.
                $errmsg = '';
                if (!check_password_policy($plainpassword, $errmsg)) {
                    $result->error_code = 'password_policy';
                    $result->message = 'Password does not meet policy requirements: ' . $errmsg;
                    return $result;
                }
            }
        }

        // Step 4: Resolve country if provided.
        $countrycode = '';
        $countryname = '';
        $countryinput = trim($userdata['country'] ?? '');
        if (!empty($countryinput)) {
            $country = self::resolve_country($countryinput);
            if ($country) {
                $countrycode = $country->code;
                $countryname = $country->name;
            }
        }

        // Step 5: Detect timezone if not provided but country is available.
        $timezone = trim($userdata['timezone'] ?? '');
        if (empty($timezone) && !empty($countrycode)) {
            $timezone = self::detect_timezone($countrycode);
        }
        if (empty($timezone)) {
            $timezone = '99';
        }

        // Step 6: Build phone string.
        $phone = '';
        $phoneintlcode = trim($userdata['phone_intl_code'] ?? '');
        $phonenum = trim($userdata['phone'] ?? '');
        if (!empty($phoneintlcode) && !empty($phonenum)) {
            $phone = $phoneintlcode . ' ' . $phonenum;
        } else if (!empty($phonenum)) {
            $phone = $phonenum;
        }

        // Step 7: Build Moodle user object.
        $user = new \stdClass();
        $user->firstname = trim($userdata['firstname']);
        $user->lastname = trim($userdata['lastname']);
        $user->email = trim($userdata['email']);
        $user->username = $username;
        $user->password = $plainpassword;
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->auth = 'manual';
        $user->city = trim($userdata['city'] ?? '');
        $user->country = $countrycode;
        $user->timezone = $timezone;
        $user->phone1 = $phone;

        // Step 8: Create the Moodle user.
        try {
            $userid = user_create_user($user, true, false);
        } catch (\Exception $e) {
            $result->error_code = 'user_creation_failed';
            $result->message = 'Failed to create Moodle user: ' . $e->getMessage();
            return $result;
        }

        $result->userid = $userid;
        $result->username = $username;
        $result->password = $plainpassword;

        // Step 9: Encrypt password with RSA.
        try {
            $result->encrypted_password = self::encrypt_password($plainpassword);
        } catch (\moodle_exception $e) {
            // Non-fatal: password created but encryption failed.
            // User is already created, so continue but note the issue.
            $result->encrypted_password = '';
        }

        // Step 10: Get default service ID if not specified.
        $serviceid = (int)($userdata['serviceid'] ?? 0);
        if ($serviceid === 0) {
            $serviceid = self::get_default_service_id();
        }

        $companyid = (int)($userdata['companyid'] ?? 0);

        // Step 11: If IOMAD and companyid > 0, assign user to company BEFORE token creation
        // (create_token validates company membership).
        if (util::is_iomad_installed() && $companyid > 0) {
            try {
                if (!$DB->record_exists('company_users', ['userid' => $userid, 'companyid' => $companyid])) {
                    $department = $DB->get_record('department', [
                        'company' => $companyid,
                        'parent' => 0,
                    ], 'id', IGNORE_MULTIPLE);

                    $companyuser = new \stdClass();
                    $companyuser->companyid = $companyid;
                    $companyuser->userid = $userid;
                    $companyuser->managertype = 0;
                    $companyuser->departmentid = $department ? $department->id : 0;
                    $companyuser->timecreated = time();

                    $DB->insert_record('company_users', $companyuser);
                }
            } catch (\Exception $e) {
                debugging('user_manager::create_user: Failed to assign user to company - ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        // Step 12: Create web service token.
        try {
            $tokenrecord = company_token_manager::create_token($userid, $companyid, $serviceid, []);
            $result->token = $tokenrecord->token;
        } catch (\Exception $e) {
            $result->error_code = 'token_creation_failed';
            $result->message = 'User created (ID: ' . $userid . ') but token creation failed: ' . $e->getMessage();
            return $result;
        }

        // Step 14: Insert record into local_sm_estratoos_plugin_users table.
        try {
            $userrecord = new \stdClass();
            $userrecord->userid = $userid;
            $userrecord->companyid = $companyid;
            $userrecord->tokenid = $tokenrecord->tokenid ?? 0;
            $userrecord->token_string = $result->token;
            $userrecord->encrypted_password = $result->encrypted_password;
            $userrecord->phone_intl_code = $phoneintlcode;
            $userrecord->phone = $phonenum;
            $userrecord->birthdate = trim($userdata['birthdate'] ?? '');
            $userrecord->state_province = trim($userdata['state_province'] ?? '');
            $userrecord->country_name = $countryname;
            $userrecord->document_type = strtolower(trim($userdata['document_type'] ?? ''));
            $userrecord->document_id = strtoupper(trim($userdata['document_id'] ?? ''));
            $userrecord->password_generated = $generatepassword ? 1 : 0;
            $userrecord->source = $source;
            $userrecord->createdby = (int)($GLOBALS['USER']->id ?? 0);
            $userrecord->notified = 0;
            $userrecord->timecreated = time();

            $DB->insert_record('local_sm_estratoos_plugin_users', $userrecord);
        } catch (\Exception $e) {
            // Non-fatal: user and token exist, metadata record is supplementary.
            debugging('user_manager::create_user: Failed to insert user metadata - ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }

        // Step 15: Return success.
        $result->success = true;
        $result->message = 'User created successfully';

        return $result;
    }

    /**
     * Create multiple users in batch.
     *
     * Iterates through a list of user data arrays, creating each user individually.
     * Tracks success/fail counts and returns detailed results.
     *
     * @param array $users Array of user data arrays (each following create_user format).
     * @param int $companyid Company ID for all users.
     * @param int $serviceid Service ID for all users.
     * @param string $source Source identifier for tracking.
     * @return object Result with batchid, successcount, failcount, results[].
     */
    public static function create_users_batch(array $users, int $companyid, int $serviceid,
            string $source = 'batch_api'): object {

        // Generate batch UUID.
        $batchid = bin2hex(random_bytes(16));

        $result = (object)[
            'batchid' => $batchid,
            'successcount' => 0,
            'failcount' => 0,
            'results' => [],
        ];

        foreach ($users as $userdata) {
            // Set companyid and serviceid for each user.
            $userdata['companyid'] = $companyid;
            $userdata['serviceid'] = $serviceid;

            try {
                $userresult = self::create_user($userdata, $source);

                if ($userresult->success) {
                    $result->successcount++;
                } else {
                    $result->failcount++;
                }

                $result->results[] = $userresult;
            } catch (\Exception $e) {
                $result->failcount++;
                $result->results[] = (object)[
                    'success' => false,
                    'error_code' => 'unexpected_error',
                    'message' => $e->getMessage(),
                    'userid' => 0,
                    'username' => $userdata['username'] ?? '',
                    'token' => '',
                    'password' => '',
                    'encrypted_password' => '',
                    'moodle_url' => '',
                ];
            }
        }

        return $result;
    }

    /**
     * Parse CSV data into an array of user data.
     *
     * Expected CSV headers: firstname, lastname, email, username, password,
     * phone_intl_code, phone, birthdate, city, state_province, country, timezone.
     *
     * Handles BOM, detects comma or semicolon delimiter.
     *
     * @param string $csvdata Raw CSV content.
     * @return array Array with 'users' (array of user data) and 'errors' (array of error objects).
     */
    public static function parse_csv_users(string $csvdata): array {
        $result = [
            'users' => [],
            'errors' => [],
        ];

        if (empty(trim($csvdata))) {
            return $result;
        }

        // Remove BOM if present.
        $csvdata = ltrim($csvdata, "\xEF\xBB\xBF");

        // Split into lines.
        $lines = preg_split('/\r\n|\r|\n/', trim($csvdata));
        if (empty($lines)) {
            return $result;
        }

        // Detect delimiter: check first line for semicolons vs commas.
        $firstline = $lines[0];
        $semicoloncount = substr_count($firstline, ';');
        $commacount = substr_count($firstline, ',');
        $delimiter = ($semicoloncount > $commacount) ? ';' : ',';

        // Parse header row.
        $headerfields = str_getcsv($firstline, $delimiter);
        $headers = [];
        foreach ($headerfields as $idx => $field) {
            $headers[$idx] = strtolower(trim($field));
        }

        // Map expected column names.
        $expectedcolumns = [
            'firstname', 'lastname', 'email', 'username', 'password',
            'document_type', 'document_id',
            'phone_intl_code', 'phone', 'birthdate', 'city',
            'state_province', 'country', 'timezone',
        ];

        // Process data rows (skip header = line 0).
        for ($linenum = 1; $linenum < count($lines); $linenum++) {
            $line = trim($lines[$linenum]);
            if (empty($line)) {
                continue;
            }

            $fields = str_getcsv($line, $delimiter);
            $userdata = [];

            // Map fields to column names based on header.
            foreach ($headers as $idx => $colname) {
                if (in_array($colname, $expectedcolumns) && isset($fields[$idx])) {
                    $userdata[$colname] = trim($fields[$idx]);
                }
            }

            // Validate minimum required fields.
            $displayline = $linenum + 1; // Human-readable line number.

            if (empty($userdata['firstname'] ?? '')) {
                $result['errors'][] = (object)[
                    'line' => $displayline,
                    'error' => 'Missing required field: firstname',
                ];
                continue;
            }

            if (empty($userdata['lastname'] ?? '')) {
                $result['errors'][] = (object)[
                    'line' => $displayline,
                    'error' => 'Missing required field: lastname',
                ];
                continue;
            }

            if (empty($userdata['email'] ?? '')) {
                $result['errors'][] = (object)[
                    'line' => $displayline,
                    'error' => 'Missing required field: email',
                ];
                continue;
            }

            $result['users'][] = $userdata;
        }

        return $result;
    }

    /**
     * Get newly created users for notification to SmartLearning.
     *
     * Queries local_sm_estratoos_plugin_users joined with the user table
     * to retrieve users created since a given timestamp, filtered by company
     * and notification status.
     *
     * @param int $since Only return users created after this timestamp (0 = all).
     * @param int $companyid Filter by company ID (0 = all companies).
     * @param bool $markasnotified If true, marks returned records as notified in a transaction.
     * @param int $limit Maximum number of records to return.
     * @param bool $onlyunnotified If true, only return records where notified=0.
     * @return array Array of user data objects.
     */
    public static function get_new_users(int $since = 0, int $companyid = 0,
            bool $markasnotified = false, int $limit = 100, bool $onlyunnotified = true): array {
        global $DB, $CFG;

        $sql = "SELECT su.id as recordid, su.userid, su.companyid, su.token_string,
                       su.encrypted_password, su.phone_intl_code, su.phone, su.birthdate,
                       su.state_province, su.country_name, su.document_type, su.document_id,
                       su.source, su.notified,
                       su.timecreated,
                       u.firstname, u.lastname, u.email, u.username,
                       u.city, u.country as country_code, u.timezone
                FROM {local_sm_estratoos_plugin_users} su
                JOIN {user} u ON u.id = su.userid
                WHERE u.deleted = 0";

        $params = [];

        if ($since > 0) {
            $sql .= " AND su.timecreated > :since";
            $params['since'] = $since;
        }

        if ($companyid > 0) {
            $sql .= " AND su.companyid = :companyid";
            $params['companyid'] = $companyid;
        }

        if ($onlyunnotified) {
            $sql .= " AND su.notified = 0";
        }

        $sql .= " ORDER BY su.timecreated ASC";

        // Fetch limit + 1 to detect has_more.
        $records = $DB->get_records_sql($sql, $params, 0, $limit + 1);

        $hasmore = count($records) > $limit;
        if ($hasmore) {
            // Remove the extra record.
            array_pop($records);
        }

        // Build result array.
        $users = [];
        $recordids = [];

        foreach ($records as $record) {
            $recordids[] = $record->recordid;

            $user = new \stdClass();
            $user->userid = (int)$record->userid;
            $user->firstname = $record->firstname;
            $user->lastname = $record->lastname;
            $user->email = $record->email;
            $user->username = $record->username;
            $user->encrypted_password = $record->encrypted_password;
            $user->phone_intl_code = $record->phone_intl_code;
            $user->phone = $record->phone;
            $user->birthdate = $record->birthdate;
            $user->city = $record->city;
            $user->state_province = $record->state_province;
            $user->country_name = $record->country_name;
            $user->document_type = $record->document_type ?? '';
            $user->document_id = $record->document_id ?? '';
            $user->country_code = $record->country_code;
            $user->timezone = $record->timezone;
            $user->moodle_token = $record->token_string;
            $user->moodle_url = $CFG->wwwroot;
            $user->companyid = (int)$record->companyid;
            $user->timecreated = (int)$record->timecreated;
            $user->notified = (int)$record->notified;
            $users[] = $user;
        }

        // Mark as notified if requested.
        if ($markasnotified && !empty($recordids)) {
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($recordids as $rid) {
                    $DB->update_record('local_sm_estratoos_plugin_users', (object)[
                        'id' => $rid,
                        'notified' => 1,
                        'notified_at' => $now,
                    ]);
                }
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                debugging('user_manager::get_new_users: Failed to mark as notified - ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return [
            'users' => $users,
            'count' => count($users),
            'has_more' => $hasmore,
        ];
    }

    /**
     * Validate user data before creation.
     *
     * Checks all required fields, email uniqueness, username validity,
     * password policy, birthdate format, phone code format, country validity,
     * and company/service existence.
     *
     * @param array $userdata User data array to validate.
     * @return object Validation result with 'valid', 'error_code', and 'message'.
     */
    private static function validate_user_data(array $userdata): object {
        global $DB;

        $result = (object)[
            'valid' => true,
            'error_code' => '',
            'message' => '',
        ];

        // Firstname required.
        if (empty(trim($userdata['firstname'] ?? ''))) {
            $result->valid = false;
            $result->error_code = 'empty_firstname';
            $result->message = 'First name is required';
            return $result;
        }

        // Lastname required.
        if (empty(trim($userdata['lastname'] ?? ''))) {
            $result->valid = false;
            $result->error_code = 'empty_lastname';
            $result->message = 'Last name is required';
            return $result;
        }

        // Email required.
        $email = trim($userdata['email'] ?? '');
        if (empty($email)) {
            $result->valid = false;
            $result->error_code = 'empty_email';
            $result->message = 'Email is required';
            return $result;
        }

        // Email valid format.
        if (!validate_email($email)) {
            $result->valid = false;
            $result->error_code = 'invalid_email';
            $result->message = 'Invalid email format';
            return $result;
        }

        // Email not already taken.
        if ($DB->record_exists('user', ['email' => $email, 'deleted' => 0])) {
            $result->valid = false;
            $result->error_code = 'email_taken';
            $result->message = 'Email address is already in use';
            return $result;
        }

        // Username validation (if provided).
        $username = trim($userdata['username'] ?? '');
        if (!empty($username)) {
            // Check valid format: lowercase alphanumeric, dots, dashes, underscores, @.
            if (!preg_match('/^[a-z0-9._@\-]+$/', $username)) {
                $result->valid = false;
                $result->error_code = 'invalid_username';
                $result->message = 'Username contains invalid characters';
                return $result;
            }

            // Check username not taken.
            if ($DB->record_exists('user', ['username' => $username])) {
                $result->valid = false;
                $result->error_code = 'username_taken';
                $result->message = 'Username is already in use';
                return $result;
            }
        }

        // Password validation.
        $generatepassword = !empty($userdata['generate_password']);
        $password = $userdata['password'] ?? '';
        if (!$generatepassword && empty($password)) {
            $result->valid = false;
            $result->error_code = 'empty_password';
            $result->message = 'Password is required when generate_password is false';
            return $result;
        }

        if (!$generatepassword && !empty($password)) {
            $errmsg = '';
            if (!check_password_policy($password, $errmsg)) {
                $result->valid = false;
                $result->error_code = 'password_policy';
                $result->message = 'Password does not meet policy requirements: ' . $errmsg;
                return $result;
            }
        }

        // Birthdate validation (if provided).
        $birthdate = trim($userdata['birthdate'] ?? '');
        if (!empty($birthdate)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
                $result->valid = false;
                $result->error_code = 'invalid_birthdate';
                $result->message = 'Birthdate must be in YYYY-MM-DD format';
                return $result;
            }
            // Verify it is a real date.
            $parts = explode('-', $birthdate);
            if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
                $result->valid = false;
                $result->error_code = 'invalid_birthdate';
                $result->message = 'Birthdate is not a valid date';
                return $result;
            }
        }

        // Phone international code validation (if provided).
        $phoneintlcode = trim($userdata['phone_intl_code'] ?? '');
        if (!empty($phoneintlcode)) {
            if (!preg_match('/^\+\d{1,4}$/', $phoneintlcode)) {
                $result->valid = false;
                $result->error_code = 'invalid_phone_code';
                $result->message = 'Phone international code must be in +N format (e.g., +1, +55, +351)';
                return $result;
            }
        }

        // Country validation (if provided).
        $country = trim($userdata['country'] ?? '');
        if (!empty($country)) {
            $resolved = self::resolve_country($country);
            if ($resolved === null) {
                $result->valid = false;
                $result->error_code = 'invalid_country';
                $result->message = 'Country not recognized: ' . $country;
                return $result;
            }
        }

        // Document type validation (if provided).
        $documenttype = strtolower(trim($userdata['document_type'] ?? ''));
        if (!empty($documenttype)) {
            $validtypes = ['dni', 'nie', 'passport'];
            if (!in_array($documenttype, $validtypes)) {
                $result->valid = false;
                $result->error_code = 'invalid_document_type';
                $result->message = 'Document type must be one of: dni, nie, passport';
                return $result;
            }

            // Document ID required when document_type is provided.
            $documentid = strtoupper(trim($userdata['document_id'] ?? ''));
            if (empty($documentid)) {
                $result->valid = false;
                $result->error_code = 'empty_document_id';
                $result->message = 'Document ID is required when document type is specified';
                return $result;
            }

            // Format validation per document type.
            if ($documenttype === 'dni') {
                // DNI: 8 digits + 1 letter, validated via modulo-23 check.
                if (!preg_match('/^(\d{8})([A-Z])$/', $documentid, $matches)) {
                    $result->valid = false;
                    $result->error_code = 'invalid_document_id';
                    $result->message = 'DNI must be 8 digits followed by 1 letter (e.g., 12345678A)';
                    return $result;
                }
                $dnitable = 'TRWAGMYFPDXBNJZSQVHLCKE';
                $expectedletter = $dnitable[(int)$matches[1] % 23];
                if ($matches[2] !== $expectedletter) {
                    $result->valid = false;
                    $result->error_code = 'invalid_document_id';
                    $result->message = 'DNI check letter is incorrect. Expected: ' . $expectedletter;
                    return $result;
                }
            } else if ($documenttype === 'nie') {
                // NIE: X/Y/Z + 7 digits + 1 letter.
                if (!preg_match('/^([XYZ])(\d{7})([A-Z])$/', $documentid, $matches)) {
                    $result->valid = false;
                    $result->error_code = 'invalid_document_id';
                    $result->message = 'NIE must be X/Y/Z + 7 digits + 1 letter (e.g., X1234567A)';
                    return $result;
                }
                $prefixmap = ['X' => '0', 'Y' => '1', 'Z' => '2'];
                $numericpart = $prefixmap[$matches[1]] . $matches[2];
                $dnitable = 'TRWAGMYFPDXBNJZSQVHLCKE';
                $expectedletter = $dnitable[(int)$numericpart % 23];
                if ($matches[3] !== $expectedletter) {
                    $result->valid = false;
                    $result->error_code = 'invalid_document_id';
                    $result->message = 'NIE check letter is incorrect. Expected: ' . $expectedletter;
                    return $result;
                }
            } else if ($documenttype === 'passport') {
                // Passport: 3-20 alphanumeric characters.
                if (!preg_match('/^[A-Z0-9]{3,20}$/', $documentid)) {
                    $result->valid = false;
                    $result->error_code = 'invalid_document_id';
                    $result->message = 'Passport must be 3-20 alphanumeric characters';
                    return $result;
                }
            }
        }

        // Company ID validation (if provided and > 0).
        $companyid = (int)($userdata['companyid'] ?? 0);
        if ($companyid > 0) {
            if (util::is_iomad_installed()) {
                if (!$DB->record_exists('company', ['id' => $companyid])) {
                    $result->valid = false;
                    $result->error_code = 'company_not_found';
                    $result->message = 'Company not found with ID: ' . $companyid;
                    return $result;
                }
            }
        }

        // Service ID validation (if provided and > 0).
        $serviceid = (int)($userdata['serviceid'] ?? 0);
        if ($serviceid > 0) {
            if (!$DB->record_exists('external_services', ['id' => $serviceid])) {
                $result->valid = false;
                $result->error_code = 'service_not_found';
                $result->message = 'Web service not found with ID: ' . $serviceid;
                return $result;
            }
        }

        return $result;
    }

    /**
     * Get the default web service ID for this plugin.
     *
     * Looks up the service with shortname 'sm_estratoos_plugin'.
     *
     * @return int Service ID, or 0 if not found.
     */
    private static function get_default_service_id(): int {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin'], 'id');
        if ($service) {
            return (int)$service->id;
        }

        return 0;
    }

    /**
     * Get newly created tokens for SmartLearning watcher.
     *
     * Queries the main token metadata table (local_sm_estratoos_plugin) joined with
     * external_tokens and user tables. This covers tokens created via create_batch
     * for existing Moodle users (not tracked in local_sm_estratoos_plugin_users).
     *
     * @param int $since Only return tokens created after this timestamp (0 = all).
     * @param int $companyid Filter by company ID (0 = all companies).
     * @param bool $markasnotified If true, marks returned records as notified.
     * @param int $limit Maximum number of records to return.
     * @param bool $onlyunnotified If true, only return records where notified=0.
     * @return array Array with tokens[], count, has_more.
     */
    public static function get_new_tokens(int $since = 0, int $companyid = 0,
            bool $markasnotified = false, int $limit = 100, bool $onlyunnotified = true): array {
        global $DB, $CFG;

        $sql = "SELECT lsp.id as recordid, lsp.tokenid, lsp.companyid, lsp.active,
                       lsp.notified, lsp.timecreated,
                       et.token as token_string, et.userid,
                       u.firstname, u.lastname, u.email, u.username,
                       u.city, u.country, u.timezone, u.phone1
                FROM {local_sm_estratoos_plugin} lsp
                JOIN {external_tokens} et ON et.id = lsp.tokenid
                JOIN {user} u ON u.id = et.userid
                WHERE lsp.active = 1
                  AND u.deleted = 0";

        $params = [];

        if ($since > 0) {
            $sql .= " AND lsp.timecreated > :since";
            $params['since'] = $since;
        }

        if ($companyid > 0) {
            $sql .= " AND lsp.companyid = :companyid";
            $params['companyid'] = $companyid;
        }

        if ($onlyunnotified) {
            $sql .= " AND lsp.notified = 0";
        }

        $sql .= " ORDER BY lsp.timecreated ASC";

        // Fetch limit + 1 to detect has_more.
        $records = $DB->get_records_sql($sql, $params, 0, $limit + 1);

        $hasmore = count($records) > $limit;
        if ($hasmore) {
            array_pop($records);
        }

        $tokens = [];
        $recordids = [];

        foreach ($records as $record) {
            $recordids[] = $record->recordid;

            $token = new \stdClass();
            $token->userid = (int)$record->userid;
            $token->firstname = $record->firstname;
            $token->lastname = $record->lastname;
            $token->email = $record->email;
            $token->username = $record->username;
            $token->token = $record->token_string;
            $token->companyid = (int)$record->companyid;
            $token->city = $record->city ?? '';
            $token->country = $record->country ?? '';
            $token->timezone = $record->timezone ?? '';
            $token->phone1 = $record->phone1 ?? '';
            $token->moodle_url = $CFG->wwwroot;
            $token->timecreated = (int)$record->timecreated;
            $token->notified = (int)$record->notified;
            $tokens[] = $token;
        }

        // Mark as notified if requested.
        if ($markasnotified && !empty($recordids)) {
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($recordids as $rid) {
                    $DB->update_record('local_sm_estratoos_plugin', (object)[
                        'id' => $rid,
                        'notified' => 1,
                        'notified_at' => $now,
                    ]);
                }
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                debugging('user_manager::get_new_tokens: Failed to mark as notified - ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        return [
            'tokens' => $tokens,
            'count' => count($tokens),
            'has_more' => $hasmore,
        ];
    }
}
