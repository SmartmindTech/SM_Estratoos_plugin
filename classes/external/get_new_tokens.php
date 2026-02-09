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

/**
 * External function to retrieve newly created tokens.
 *
 * Returns tokens tracked in the plugin's main metadata table
 * (local_sm_estratoos_plugin) since a given timestamp, with optional
 * company filtering and notification marking.
 *
 * Requires site admin or IOMAD company manager privileges.
 *
 * Supports pagination via the limit parameter and returns has_more
 * to indicate whether additional results exist.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use external_warnings;

/**
 * API to retrieve newly created tokens.
 */
class get_new_tokens extends external_api {

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(
                PARAM_INT,
                'Unix timestamp — return tokens created after this time (0 for all)',
                VALUE_DEFAULT,
                0
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'IOMAD company ID to filter by (0 for all companies)',
                VALUE_DEFAULT,
                0
            ),
            'markasnotified' => new external_value(
                PARAM_BOOL,
                'Mark returned tokens as notified after retrieval',
                VALUE_DEFAULT,
                false
            ),
            'limit' => new external_value(
                PARAM_INT,
                'Maximum number of tokens to return (1-1000)',
                VALUE_DEFAULT,
                100
            ),
            'onlyunnotified' => new external_value(
                PARAM_BOOL,
                'Only return tokens that have not been marked as notified',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * Retrieve newly created tokens.
     *
     * @param int $since Unix timestamp filter.
     * @param int $companyid IOMAD company ID filter.
     * @param bool $markasnotified Mark results as notified.
     * @param int $limit Maximum results to return.
     * @param bool $onlyunnotified Only return un-notified tokens.
     * @return array List of new tokens with metadata.
     */
    public static function execute(
        int $since = 0,
        int $companyid = 0,
        bool $markasnotified = false,
        int $limit = 100,
        bool $onlyunnotified = true
    ): array {
        global $DB, $CFG, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'since' => $since,
            'companyid' => $companyid,
            'markasnotified' => $markasnotified,
            'limit' => $limit,
            'onlyunnotified' => $onlyunnotified,
        ]);

        $warnings = [];

        // Check user is logged in and not guest.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // Permission check: site admin OR IOMAD company manager.
        $haspermission = false;
        if (is_siteadmin()) {
            $haspermission = true;
        } else if (\local_sm_estratoos_plugin\util::is_iomad_installed() && $params['companyid'] > 0) {
            $haspermission = \local_sm_estratoos_plugin\util::can_manage_company($params['companyid']);
        }

        if (!$haspermission) {
            throw new \moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
        }

        // Enforce limit bounds (1-1000).
        $limit = max(1, min($params['limit'], 1000));

        // Build the SQL query.
        $conditions = ['lsp.active = 1', 'u.deleted = 0'];
        $sqlparams = [];

        if (!empty($params['since'])) {
            $conditions[] = 'lsp.timecreated > :since';
            $sqlparams['since'] = $params['since'];
        }

        if (!empty($params['companyid'])) {
            $conditions[] = 'lsp.companyid = :companyid';
            $sqlparams['companyid'] = $params['companyid'];
        }

        if ($params['onlyunnotified']) {
            $conditions[] = 'lsp.notified = 0';
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT lsp.id as recordid, lsp.tokenid, lsp.companyid, lsp.active,
                       lsp.notified, lsp.timecreated,
                       et.token as token_string, et.userid,
                       u.firstname, u.lastname, u.email, u.username,
                       u.city, u.country, u.timezone, u.phone1
                  FROM {local_sm_estratoos_plugin} lsp
                  JOIN {external_tokens} et ON et.id = lsp.tokenid
                  JOIN {user} u ON u.id = et.userid
                 WHERE {$where}
              ORDER BY lsp.timecreated ASC";

        // Fetch limit + 1 to detect has_more.
        $records = $DB->get_records_sql($sql, $sqlparams, 0, $limit + 1);

        $hasmore = false;
        if (count($records) > $limit) {
            $hasmore = true;
            // Remove the extra record.
            array_pop($records);
        }

        // Format token records for return.
        $tokens = [];
        $recordids = [];
        foreach ($records as $r) {
            $tokens[] = [
                'userid' => (int)($r->userid ?? 0),
                'firstname' => $r->firstname ?? '',
                'lastname' => $r->lastname ?? '',
                'email' => $r->email ?? '',
                'username' => $r->username ?? '',
                'token' => $r->token_string ?? '',
                'companyid' => (int)($r->companyid ?? 0),
                'city' => $r->city ?? '',
                'country' => $r->country ?? '',
                'timezone' => $r->timezone ?? '',
                'phone1' => $r->phone1 ?? '',
                'moodle_url' => $CFG->wwwroot,
                'timecreated' => (int)($r->timecreated ?? 0),
                'notified' => (bool)($r->notified ?? false),
            ];
            $recordids[] = (int)$r->recordid;
        }

        // Mark as notified if requested.
        if ($params['markasnotified'] && !empty($recordids)) {
            $transaction = $DB->start_delegated_transaction();
            try {
                $now = time();
                foreach ($recordids as $rid) {
                    $DB->set_field('local_sm_estratoos_plugin', 'notified', 1, ['id' => $rid]);
                    $DB->set_field('local_sm_estratoos_plugin', 'notified_at', $now, ['id' => $rid]);
                }
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                $warnings[] = [
                    'warningcode' => 'marknotifiedfailed',
                    'message' => 'Failed to mark tokens as notified: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'tokens' => $tokens,
            'count' => count($tokens),
            'has_more' => $hasmore,
            'warnings' => $warnings,
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tokens' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'Moodle user ID'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'email' => new external_value(PARAM_TEXT, 'Email address'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'token' => new external_value(PARAM_RAW, 'Web service token string'),
                    'companyid' => new external_value(PARAM_INT, 'IOMAD company ID (0 if no company)'),
                    'city' => new external_value(PARAM_TEXT, 'City'),
                    'country' => new external_value(PARAM_TEXT, 'Country ISO code'),
                    'timezone' => new external_value(PARAM_TEXT, 'Timezone'),
                    'phone1' => new external_value(PARAM_TEXT, 'Phone number'),
                    'moodle_url' => new external_value(PARAM_URL, 'Moodle site URL'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix timestamp of token creation'),
                    'notified' => new external_value(PARAM_BOOL, 'Whether external system was notified about this token'),
                ]),
                'List of newly created tokens'
            ),
            'count' => new external_value(PARAM_INT, 'Number of tokens returned in this response'),
            'has_more' => new external_value(PARAM_BOOL, 'Whether more tokens exist beyond the limit'),
            'warnings' => new external_warnings(),
        ]);
    }
}
