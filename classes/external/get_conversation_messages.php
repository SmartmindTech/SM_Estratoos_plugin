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

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Get messages from a conversation, scoped to token's company.
 *
 * This function works with category-scoped tokens (CONTEXT_COURSECAT) and returns
 * messages from a conversation. It mirrors core_message_get_conversation_messages
 * but validates against category context instead of system context for IOMAD tokens.
 *
 * Supports bulk retrieval with pagination for conversations with hundreds/thousands of messages.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_conversation_messages extends external_api {

    /**
     * Maximum messages per request for bulk operations.
     */
    const MAX_MESSAGES_PER_REQUEST = 1000;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'currentuserid' => new external_value(PARAM_INT, 'The current user ID'),
            'convid' => new external_value(PARAM_INT, 'The conversation ID'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset for pagination', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT,
                'Maximum number of messages to return (0 = no limit, max ' . self::MAX_MESSAGES_PER_REQUEST . ')',
                VALUE_DEFAULT, 0),
            'newest' => new external_value(PARAM_BOOL, 'Return newest messages first', VALUE_DEFAULT, false),
            'timefrom' => new external_value(PARAM_INT,
                'Only return messages from this timestamp onwards (0 = all messages)',
                VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get messages from a conversation.
     *
     * @param int $currentuserid The current user ID.
     * @param int $convid The conversation ID.
     * @param int $limitfrom Offset for pagination.
     * @param int $limitnum Maximum number of messages (0 = no limit).
     * @param bool $newest Return newest messages first.
     * @param int $timefrom Only return messages from this timestamp onwards.
     * @return array Array with messages and metadata.
     */
    public static function execute(
        int $currentuserid,
        int $convid,
        int $limitfrom = 0,
        int $limitnum = 0,
        bool $newest = false,
        int $timefrom = 0
    ): array {
        global $CFG, $DB, $USER;

        // Check if messaging is enabled.
        if (empty($CFG->messaging)) {
            throw new \moodle_exception('disabled', 'message');
        }

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'currentuserid' => $currentuserid,
            'convid' => $convid,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
            'newest' => $newest,
            'timefrom' => $timefrom,
        ]);

        // Enforce maximum limit for bulk operations.
        if ($params['limitnum'] <= 0 || $params['limitnum'] > self::MAX_MESSAGES_PER_REQUEST) {
            $params['limitnum'] = self::MAX_MESSAGES_PER_REQUEST;
        }

        // Determine if we need to apply company filtering.
        $companyid = 0;

        // Check if IOMAD is installed and token has company restrictions.
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $token = \local_sm_estratoos_plugin\util::get_current_request_token();
            if ($token) {
                $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                if ($restrictions && !empty($restrictions->companyid)) {
                    $companyid = $restrictions->companyid;
                }
            }
        }

        if ($companyid > 0) {
            // IOMAD company-scoped token: validate at category context.
            $company = $DB->get_record('company', ['id' => $companyid], '*', MUST_EXIST);
            $companycontext = \context_coursecat::instance($company->category);
            self::validate_context($companycontext);

            // Check if user can access messages for this userid.
            if ($USER->id != $params['currentuserid']) {
                require_capability('moodle/site:readallmessages', $companycontext);
            }

            // Get company user IDs for validation.
            $companyuserids = $DB->get_fieldset_select(
                'company_users',
                'userid',
                'companyid = ?',
                [$companyid]
            );

            // Verify the current user is in the company.
            if (!in_array($params['currentuserid'], $companyuserids)) {
                throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin');
            }

            // Verify the conversation involves at least one company member.
            $conversationmembers = $DB->get_fieldset_select(
                'message_conversation_members',
                'userid',
                'conversationid = ?',
                [$params['convid']]
            );

            $hascompanymember = false;
            foreach ($conversationmembers as $memberid) {
                if (in_array($memberid, $companyuserids)) {
                    $hascompanymember = true;
                    break;
                }
            }

            if (!$hascompanymember) {
                throw new \moodle_exception('conversationnotincompany', 'local_sm_estratoos_plugin');
            }
        } else {
            // Standard Moodle token (non-IOMAD or no company): validate at system context.
            $context = \context_system::instance();
            self::validate_context($context);

            // Check if user can access messages for this userid.
            if ($USER->id != $params['currentuserid']) {
                require_capability('moodle/site:readallmessages', $context);
            }
        }

        // Verify user is a member of the conversation.
        $ismember = $DB->record_exists('message_conversation_members', [
            'conversationid' => $params['convid'],
            'userid' => $params['currentuserid'],
        ]);

        if (!$ismember && $USER->id == $params['currentuserid']) {
            throw new \moodle_exception('notamemberofconversation', 'core_message');
        }

        // Get messages using core API.
        // The core API returns an array with 'id' and 'members' keys plus message objects.
        $sort = $params['newest'] ? 'timecreated DESC, id DESC' : 'timecreated ASC, id ASC';
        $messages = \core_message\api::get_conversation_messages(
            $params['currentuserid'],
            $params['convid'],
            $params['limitfrom'],
            $params['limitnum'],
            $sort,
            $params['timefrom']
        );

        // Process and format messages for response.
        $formattedmessages = [];
        if (!empty($messages['messages'])) {
            foreach ($messages['messages'] as $message) {
                $formattedmessage = [
                    'id' => (int)$message->id,
                    'useridfrom' => (int)$message->useridfrom,
                    'text' => $message->text ?? '',
                    'timecreated' => (int)$message->timecreated,
                ];

                // Include additional fields if available.
                if (isset($message->fullmessage)) {
                    $formattedmessage['fullmessage'] = $message->fullmessage;
                }
                if (isset($message->fullmessageformat)) {
                    $formattedmessage['fullmessageformat'] = (int)$message->fullmessageformat;
                }
                if (isset($message->fullmessagehtml)) {
                    $formattedmessage['fullmessagehtml'] = $message->fullmessagehtml;
                }
                if (isset($message->smallmessage)) {
                    $formattedmessage['smallmessage'] = $message->smallmessage;
                }

                $formattedmessages[] = $formattedmessage;
            }
        }

        // Get members info if available.
        $members = [];
        if (!empty($messages['members'])) {
            foreach ($messages['members'] as $memberid => $member) {
                $members[] = [
                    'id' => (int)$memberid,
                    'fullname' => $member->fullname ?? '',
                    'profileimageurl' => $member->profileimageurl ?? '',
                    'profileimageurlsmall' => $member->profileimageurlsmall ?? '',
                    'isonline' => !empty($member->isonline),
                    'isblocked' => !empty($member->isblocked),
                    'iscontact' => !empty($member->iscontact),
                    'isdeleted' => !empty($member->isdeleted),
                ];
            }
        }

        // Get total message count for pagination info.
        $totalmessages = self::get_conversation_message_count($params['convid'], $params['timefrom']);

        // Calculate pagination info.
        $hasprevious = $params['limitfrom'] > 0;
        $hasnext = ($params['limitfrom'] + count($formattedmessages)) < $totalmessages;

        return [
            'id' => $params['convid'],
            'messages' => $formattedmessages,
            'members' => $members,
            'total' => $totalmessages,
            'limitfrom' => $params['limitfrom'],
            'limitnum' => $params['limitnum'],
            'hasprevious' => $hasprevious,
            'hasnext' => $hasnext,
        ];
    }

    /**
     * Get total message count for a conversation.
     *
     * @param int $convid Conversation ID.
     * @param int $timefrom Only count messages from this timestamp onwards.
     * @return int Total message count.
     */
    private static function get_conversation_message_count(int $convid, int $timefrom = 0): int {
        global $DB;

        $params = ['conversationid' => $convid];
        $sql = 'conversationid = :conversationid';

        if ($timefrom > 0) {
            $sql .= ' AND timecreated >= :timefrom';
            $params['timefrom'] = $timefrom;
        }

        return $DB->count_records_select('messages', $sql, $params);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The conversation ID'),
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Message ID'),
                    'useridfrom' => new external_value(PARAM_INT, 'Sender user ID'),
                    'text' => new external_value(PARAM_RAW, 'Message text (formatted)'),
                    'timecreated' => new external_value(PARAM_INT, 'Time message was created'),
                    'fullmessage' => new external_value(PARAM_RAW, 'Full message text', VALUE_OPTIONAL),
                    'fullmessageformat' => new external_value(PARAM_INT, 'Full message format', VALUE_OPTIONAL),
                    'fullmessagehtml' => new external_value(PARAM_RAW, 'Full message HTML', VALUE_OPTIONAL),
                    'smallmessage' => new external_value(PARAM_RAW, 'Small message text', VALUE_OPTIONAL),
                ]),
                'List of messages'
            ),
            'members' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_NOTAGS, 'User full name'),
                    'profileimageurl' => new external_value(PARAM_URL, 'Profile image URL', VALUE_OPTIONAL),
                    'profileimageurlsmall' => new external_value(PARAM_URL, 'Small profile image URL', VALUE_OPTIONAL),
                    'isonline' => new external_value(PARAM_BOOL, 'Is user online'),
                    'isblocked' => new external_value(PARAM_BOOL, 'Is user blocked'),
                    'iscontact' => new external_value(PARAM_BOOL, 'Is user a contact'),
                    'isdeleted' => new external_value(PARAM_BOOL, 'Is user deleted'),
                ]),
                'Conversation members who sent messages'
            ),
            'total' => new external_value(PARAM_INT, 'Total number of messages in conversation'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset used'),
            'limitnum' => new external_value(PARAM_INT, 'Limit used'),
            'hasprevious' => new external_value(PARAM_BOOL, 'Are there previous messages (pagination)'),
            'hasnext' => new external_value(PARAM_BOOL, 'Are there more messages (pagination)'),
        ]);
    }
}
