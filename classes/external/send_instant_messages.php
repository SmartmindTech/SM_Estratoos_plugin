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
require_once($CFG->dirroot . '/message/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_format_value;
use external_single_structure;
use external_multiple_structure;

/**
 * Send instant messages with company-scoped validation.
 *
 * This function mirrors core_message_send_instant_messages but validates
 * against company category context instead of requiring system-level
 * moodle/site:sendmessage capability. Recipients must be in the same
 * company as the sender for IOMAD tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_instant_messages extends external_api {

    /**
     * Describes the parameters accepted by send_instant_messages.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'touserid' => new external_value(PARAM_INT, 'ID of the user to send the private message'),
                    'text' => new external_value(PARAM_RAW, 'The text of the message'),
                    'textformat' => new external_format_value('text', VALUE_DEFAULT, FORMAT_MOODLE),
                    'clientmsgid' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Your own client id for the message. If this is provided, the failed message returned ' .
                        'will contain this id',
                        VALUE_OPTIONAL
                    ),
                ])
            ),
        ]);
    }

    /**
     * Send instant messages to users within the same company scope.
     *
     * For IOMAD tokens: validates at company category context and ensures
     * recipients are members of the same company.
     *
     * For standard Moodle tokens: validates at system context and requires
     * the standard moodle/site:sendmessage capability.
     *
     * @param array $messages Array of message objects to send.
     * @return array Array of result objects for each message.
     */
    public static function execute(array $messages): array {
        global $CFG, $DB, $USER;

        // Check if messaging is enabled.
        if (empty($CFG->messaging)) {
            throw new \moodle_exception('disabled', 'message');
        }

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'messages' => $messages,
        ]);

        // Determine if we need to apply company filtering.
        $companyuserids = null;
        $companyid = 0;
        $context = \context_system::instance();

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
            $context = \context_coursecat::instance($company->category);
            self::validate_context($context);

            // Get company user IDs for filtering recipients.
            $companyuserids = $DB->get_fieldset_select(
                'company_users',
                'userid',
                'companyid = ?',
                [$companyid]
            );
        } else {
            // Standard Moodle token (non-IOMAD or no company).
            if (is_siteadmin()) {
                // Site admin: Use system context.
                $context = \context_system::instance();
            } else {
                // Non-IOMAD normal user: Use top-level category context.
                $topcategory = $DB->get_record('course_categories', ['parent' => 0], 'id', IGNORE_MULTIPLE);
                if ($topcategory) {
                    $context = \context_coursecat::instance($topcategory->id);
                } else {
                    $context = \context_system::instance();
                }
            }
            self::validate_context($context);

            // Require standard messaging capability for non-IOMAD tokens.
            require_capability('moodle/site:sendmessage', $context);
        }

        // Check if current user can delete messages for all users.
        $candeletemessagesforallusers = has_capability('moodle/site:deleteanymessage', $context);

        // Collect all recipient user IDs.
        $touseridlist = [];
        foreach ($params['messages'] as $message) {
            $touseridlist[] = $message['touserid'];
        }

        // Fetch all recipient users at once.
        list($sqluserids, $sqlparams) = $DB->get_in_or_equal($touseridlist);
        $tousers = $DB->get_records_select(
            'user',
            "id $sqluserids AND deleted = 0",
            $sqlparams
        );

        // Process each message.
        $resultmessages = [];
        $messageids = [];

        foreach ($params['messages'] as $message) {
            $resultmsg = [];

            // Include clientmsgid if provided.
            if (isset($message['clientmsgid'])) {
                $resultmsg['clientmsgid'] = $message['clientmsgid'];
            }

            // Check if recipient exists.
            if (empty($tousers[$message['touserid']])) {
                $resultmsg['msgid'] = -1;
                $resultmsg['errormessage'] = get_string('userdoesnotexist', 'error');
                $resultmessages[] = $resultmsg;
                continue;
            }

            $touser = $tousers[$message['touserid']];

            // For IOMAD tokens: check recipient is in the same company.
            if ($companyuserids !== null && !in_array($message['touserid'], $companyuserids)) {
                $resultmsg['msgid'] = -1;
                $resultmsg['errormessage'] = get_string('recipientnotincompany', 'local_sm_estratoos_plugin');
                $resultmessages[] = $resultmsg;
                continue;
            }

            // Check message length.
            if (strlen($message['text']) > \core_message\api::MESSAGE_MAX_LENGTH) {
                $resultmsg['msgid'] = -1;
                $resultmsg['errormessage'] = get_string('errormessagetoolong', 'message');
                $resultmessages[] = $resultmsg;
                continue;
            }

            // Check if the sender can message this recipient.
            if (!\core_message\api::can_send_message($touser->id, $USER->id)) {
                $resultmsg['msgid'] = -1;
                $resultmsg['errormessage'] = get_string('usercantbemessaged', 'message');
                $resultmessages[] = $resultmsg;
                continue;
            }

            // Send the message using Moodle's message API.
            $success = message_post_message(
                $USER,
                $touser,
                $message['text'],
                $message['textformat']
            );

            if ($success) {
                $resultmsg['msgid'] = $success;
                $resultmsg['timecreated'] = time();
                $resultmsg['useridfrom'] = $USER->id;
                $resultmsg['candeletemessagesforallusers'] = $candeletemessagesforallusers;

                // Get conversation ID if available.
                $conversation = \core_message\api::get_conversation_between_users([$USER->id, $touser->id]);
                if ($conversation) {
                    $resultmsg['conversationid'] = $conversation;
                }

                // Include the formatted text.
                $resultmsg['text'] = $message['text'];

                $messageids[] = $success;
            } else {
                $resultmsg['msgid'] = -1;
                $resultmsg['errormessage'] = get_string('messageundeliveredbynotificationsettings', 'error');
            }

            $resultmessages[] = $resultmsg;
        }

        return $resultmessages;
    }

    /**
     * Describes the return value structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'msgid' => new external_value(
                    PARAM_INT,
                    'ID of the created message when successful, -1 when failed'
                ),
                'clientmsgid' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Your own id for the message',
                    VALUE_OPTIONAL
                ),
                'errormessage' => new external_value(
                    PARAM_TEXT,
                    'Error message - if it failed',
                    VALUE_OPTIONAL
                ),
                'text' => new external_value(
                    PARAM_RAW,
                    'The text of the message',
                    VALUE_OPTIONAL
                ),
                'timecreated' => new external_value(
                    PARAM_INT,
                    'The timecreated timestamp for the message',
                    VALUE_OPTIONAL
                ),
                'conversationid' => new external_value(
                    PARAM_INT,
                    'The conversation id for this message',
                    VALUE_OPTIONAL
                ),
                'useridfrom' => new external_value(
                    PARAM_INT,
                    'The user id who sent the message',
                    VALUE_OPTIONAL
                ),
                'candeletemessagesforallusers' => new external_value(
                    PARAM_BOOL,
                    'If the user can delete messages in the conversation for all users',
                    VALUE_DEFAULT,
                    false
                ),
            ])
        );
    }
}
