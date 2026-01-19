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
 * Get user conversations, scoped to token's company.
 *
 * This function works with category-scoped tokens (CONTEXT_COURSECAT) and returns
 * only conversations that involve users from the token's company. It mirrors
 * core_message_get_conversations but validates against category context instead of system context.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_conversations extends external_api {

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'The ID of the user whose conversations to retrieve'),
            'limitfrom' => new external_value(PARAM_INT, 'Offset to start from', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(PARAM_INT, 'Maximum number of conversations to return', VALUE_DEFAULT, 0),
            'type' => new external_value(PARAM_INT, 'Filter by type (1=individual, 2=group, 3=self)', VALUE_DEFAULT, null),
            'favourites' => new external_value(PARAM_BOOL,
                'Filter: null=all, true=favourites only, false=non-favourites only',
                VALUE_DEFAULT,
                null
            ),
            'mergeself' => new external_value(PARAM_BOOL,
                'Include self-conversations when requesting private conversations',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Get conversations, filtered to involve only company users.
     *
     * @param int $userid The user ID.
     * @param int $limitfrom Offset.
     * @param int $limitnum Limit.
     * @param int|null $type Conversation type filter.
     * @param bool|null $favourites Favourites filter.
     * @param bool $mergeself Include self conversations.
     * @return object Object with conversations array.
     */
    public static function execute(
        int $userid,
        int $limitfrom = 0,
        int $limitnum = 0,
        ?int $type = null,
        ?bool $favourites = null,
        bool $mergeself = false
    ): object {
        global $CFG, $DB, $USER;

        // Check if messaging is enabled.
        if (empty($CFG->messaging)) {
            throw new \moodle_exception('disabled', 'message');
        }

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
            'type' => $type,
            'favourites' => $favourites,
            'mergeself' => $mergeself,
        ]);

        // Determine if we need to apply company filtering.
        $companyuserids = null;
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

            // Check if user can access conversations for this userid.
            if ($USER->id != $params['userid']) {
                require_capability('moodle/site:readallmessages', $companycontext);
            }

            // Get company user IDs for filtering.
            $companyuserids = $DB->get_fieldset_select(
                'company_users',
                'userid',
                'companyid = ?',
                [$companyid]
            );

            // Verify the target user is in the company.
            if (!in_array($params['userid'], $companyuserids)) {
                throw new \moodle_exception('usernotincompany', 'local_sm_estratoos_plugin');
            }
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

            // Check if user can access conversations for this userid.
            if ($USER->id != $params['userid']) {
                require_capability('moodle/site:readallmessages', $context);
            }
        }

        // Get conversations using core API.
        $requestlimit = $params['limitnum'] > 0 ? ($companyuserids !== null ? $params['limitnum'] * 3 : $params['limitnum']) : 0;
        $conversations = \core_message\api::get_conversations(
            $params['userid'],
            $params['limitfrom'],
            $requestlimit,
            $params['type'],
            $params['favourites'],
            $params['mergeself']
        );

        // Filter conversations if company-scoped.
        $filteredconversations = [];
        foreach ($conversations as $conversation) {
            if ($companyuserids !== null) {
                // Company-scoped: filter to only conversations with company users.
                $hascompanymember = false;
                $filteredmembers = [];

                if (isset($conversation->members) && is_array($conversation->members)) {
                    foreach ($conversation->members as $member) {
                        $memberid = $member->id ?? null;
                        if ($memberid && in_array($memberid, $companyuserids)) {
                            $hascompanymember = true;
                            $filteredmembers[] = $member;
                        }
                    }
                }

                // For self-conversations (type 3), always include if user is in company.
                if (isset($conversation->type) && $conversation->type == 3) {
                    $hascompanymember = true;
                }

                // Include conversation if it has company members.
                if ($hascompanymember) {
                    if (!empty($filteredmembers)) {
                        $conversation->members = $filteredmembers;
                    }
                    $filteredconversations[] = $conversation;

                    // Respect the original limit.
                    if ($params['limitnum'] > 0 && count($filteredconversations) >= $params['limitnum']) {
                        break;
                    }
                }
            } else {
                // No company filtering - include all conversations.
                $filteredconversations[] = $conversation;

                if ($params['limitnum'] > 0 && count($filteredconversations) >= $params['limitnum']) {
                    break;
                }
            }
        }

        return (object) ['conversations' => $filteredconversations];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversations' => new external_multiple_structure(
                self::get_conversation_structure()
            ),
        ]);
    }

    /**
     * Returns the structure of a conversation.
     *
     * @return external_single_structure
     */
    private static function get_conversation_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The conversation ID'),
            'name' => new external_value(PARAM_RAW, 'The conversation name', VALUE_OPTIONAL),
            'subname' => new external_value(PARAM_RAW, 'Subtitle for the conversation name', VALUE_OPTIONAL),
            'imageurl' => new external_value(PARAM_URL, 'Link to conversation picture', VALUE_OPTIONAL),
            'type' => new external_value(PARAM_INT, 'Conversation type (1=individual, 2=group, 3=self)'),
            'membercount' => new external_value(PARAM_INT, 'Total number of members'),
            'ismuted' => new external_value(PARAM_BOOL, 'Is the conversation muted'),
            'isfavourite' => new external_value(PARAM_BOOL, 'Is the conversation a favourite'),
            'isread' => new external_value(PARAM_BOOL, 'Are all messages read'),
            'unreadcount' => new external_value(PARAM_INT, 'Number of unread messages', VALUE_OPTIONAL),
            'members' => new external_multiple_structure(
                self::get_member_structure()
            ),
            'messages' => new external_multiple_structure(
                self::get_message_structure()
            ),
            'candeletemessagesforallusers' => new external_value(PARAM_BOOL,
                'Can delete messages for all users',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Returns the structure of a conversation member.
     *
     * @return external_single_structure
     */
    private static function get_member_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User ID'),
            'fullname' => new external_value(PARAM_NOTAGS, 'User full name'),
            'profileurl' => new external_value(PARAM_URL, 'Profile page URL'),
            'profileimageurl' => new external_value(PARAM_URL, 'Profile image URL'),
            'profileimageurlsmall' => new external_value(PARAM_URL, 'Small profile image URL'),
            'isonline' => new external_value(PARAM_BOOL, 'Is user online'),
            'showonlinestatus' => new external_value(PARAM_BOOL, 'Show online status'),
            'isblocked' => new external_value(PARAM_BOOL, 'Is user blocked'),
            'iscontact' => new external_value(PARAM_BOOL, 'Is user a contact'),
            'isdeleted' => new external_value(PARAM_BOOL, 'Is user deleted'),
            'canmessageevenifblocked' => new external_value(PARAM_BOOL, 'Can message even if blocked', VALUE_OPTIONAL),
            'canmessage' => new external_value(PARAM_BOOL, 'Can be messaged', VALUE_OPTIONAL),
            'requirescontact' => new external_value(PARAM_BOOL, 'Requires contact to message', VALUE_OPTIONAL),
            'cancreatecontact' => new external_value(PARAM_BOOL, 'Can create contact', VALUE_OPTIONAL),
            'contactrequests' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Contact request ID'),
                    'userid' => new external_value(PARAM_INT, 'User who created request'),
                    'requesteduserid' => new external_value(PARAM_INT, 'User confirming request'),
                    'timecreated' => new external_value(PARAM_INT, 'Time created'),
                ]),
                'Contact requests',
                VALUE_OPTIONAL
            ),
            'conversations' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Conversation ID'),
                    'type' => new external_value(PARAM_INT, 'Conversation type'),
                    'name' => new external_value(PARAM_RAW, 'Conversation name', VALUE_OPTIONAL),
                    'timecreated' => new external_value(PARAM_INT, 'Time created'),
                ]),
                'User conversations',
                VALUE_OPTIONAL
            ),
        ]);
    }

    /**
     * Returns the structure of a message.
     *
     * @return external_single_structure
     */
    private static function get_message_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Message ID'),
            'useridfrom' => new external_value(PARAM_INT, 'Sender user ID'),
            'text' => new external_value(PARAM_RAW, 'Message text'),
            'timecreated' => new external_value(PARAM_INT, 'Time created'),
        ]);
    }
}
