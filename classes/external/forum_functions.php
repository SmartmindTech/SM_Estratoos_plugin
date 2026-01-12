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

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * Forum-related external functions.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_functions extends external_api {

    // =========================================================================
    // CREATE FORUM
    // =========================================================================

    /**
     * Describes the parameters for create_forum.
     *
     * @return external_function_parameters
     */
    public static function create_forum_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'name'     => new external_value(PARAM_TEXT, 'Forum name'),
            'intro'    => new external_value(PARAM_RAW, 'Introduction text', VALUE_OPTIONAL, ''),
            'type'     => new external_value(PARAM_ALPHANUMEXT, 'Forum type', VALUE_OPTIONAL, 'general'),
        ]);
    }

    /**
     * Create a new forum in a course.
     *
     * @param int $courseid Course ID.
     * @param string $name Forum name.
     * @param string $intro Introduction text.
     * @param string $type Forum type.
     * @return array Result with forum ID and course module ID.
     */
    public static function create_forum(int $courseid, string $name, string $intro = '', string $type = 'general'): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::create_forum_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
            'intro' => $intro,
            'type' => $type,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('mod/forum:addinstance', $context);

        $forumdata = (object)[
            'course'         => $params['courseid'],
            'modulename'     => 'forum',
            'section'        => 0,
            'visible'        => 1,
            'cmidnumber'     => '',
            'groupmode'      => 0,
            'groupingid'     => 0,
            'name'           => $params['name'],
            'showdescription' => 0,
            'introeditor'    => [
                'text'   => $params['intro'],
                'format' => FORMAT_HTML,
                'itemid' => 0
            ],
            'type'           => $params['type'],
            'forcesubscribe' => FORUM_INITIALSUBSCRIBE,
            'trackingtype'   => FORUM_TRACKING_OPTIONAL,
            'maxbytes'       => 0,
            'maxattachments' => 1,
            'assessed'       => 0,
        ];

        $created = create_module($forumdata);

        return [
            'status'  => 'success',
            'forumid' => $created->instance,
            'cmid'    => $created->coursemodule,
        ];
    }

    /**
     * Describes the return value for create_forum.
     *
     * @return external_single_structure
     */
    public static function create_forum_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Status'),
            'forumid' => new external_value(PARAM_INT, 'Forum ID'),
            'cmid'    => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    // =========================================================================
    // EDIT FORUM
    // =========================================================================

    /**
     * Describes the parameters for edit_forum.
     *
     * @return external_function_parameters
     */
    public static function edit_forum_parameters(): external_function_parameters {
        return new external_function_parameters([
            'forumid' => new external_value(PARAM_INT, 'Forum ID'),
            'name'    => new external_value(PARAM_TEXT, 'New name', VALUE_OPTIONAL, null),
            'intro'   => new external_value(PARAM_RAW, 'New introduction', VALUE_OPTIONAL, null),
            'type'    => new external_value(PARAM_ALPHANUMEXT, 'Forum type', VALUE_OPTIONAL, null),
        ]);
    }

    /**
     * Edit an existing forum.
     *
     * @param int $forumid Forum ID.
     * @param string|null $name New name.
     * @param string|null $intro New introduction.
     * @param string|null $type Forum type.
     * @return array Result with status.
     */
    public static function edit_forum(int $forumid, ?string $name = null, ?string $intro = null, ?string $type = null): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::edit_forum_parameters(), [
            'forumid' => $forumid,
            'name' => $name,
            'intro' => $intro,
            'type' => $type,
        ]);

        $forum = $DB->get_record('forum', ['id' => $params['forumid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        // Update forum fields.
        if ($params['name'] !== null) {
            $forum->name = $params['name'];
        }
        if ($params['intro'] !== null) {
            $forum->intro = $params['intro'];
            $forum->introformat = FORMAT_HTML;
        }
        if ($params['type'] !== null) {
            $forum->type = $params['type'];
        }

        $forum->timemodified = time();
        $DB->update_record('forum', $forum);

        // Rebuild course cache.
        rebuild_course_cache($forum->course, true);
        \cache_helper::purge_by_event('changesincourse');

        return ['status' => 'updated', 'forumid' => $params['forumid']];
    }

    /**
     * Describes the return value for edit_forum.
     *
     * @return external_single_structure
     */
    public static function edit_forum_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Status'),
            'forumid' => new external_value(PARAM_INT, 'Forum ID'),
        ]);
    }

    // =========================================================================
    // DELETE FORUM
    // =========================================================================

    /**
     * Describes the parameters for delete_forum.
     *
     * @return external_function_parameters
     */
    public static function delete_forum_parameters(): external_function_parameters {
        return new external_function_parameters([
            'forumid' => new external_value(PARAM_INT, 'Forum ID'),
        ]);
    }

    /**
     * Delete a forum.
     *
     * @param int $forumid Forum ID.
     * @return array Result with status.
     */
    public static function delete_forum(int $forumid): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::delete_forum_parameters(), [
            'forumid' => $forumid,
        ]);

        $forum = $DB->get_record('forum', ['id' => $params['forumid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('moodle/course:manageactivities', $context);

        course_delete_module($cm->id, true);

        return ['status' => 'deleted', 'cmid' => $cm->id];
    }

    /**
     * Describes the return value for delete_forum.
     *
     * @return external_single_structure
     */
    public static function delete_forum_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'cmid'   => new external_value(PARAM_INT, 'Deleted course module ID'),
        ]);
    }

    // =========================================================================
    // EDIT DISCUSSION
    // =========================================================================

    /**
     * Describes the parameters for edit_discussion.
     *
     * @return external_function_parameters
     */
    public static function edit_discussion_parameters(): external_function_parameters {
        return new external_function_parameters([
            'discussionid' => new external_value(PARAM_INT, 'Discussion ID'),
            'subject'      => new external_value(PARAM_TEXT, 'New subject', VALUE_OPTIONAL, null),
            'message'      => new external_value(PARAM_RAW, 'New message', VALUE_OPTIONAL, null),
        ]);
    }

    /**
     * Edit an existing discussion.
     *
     * @param int $discussionid Discussion ID.
     * @param string|null $subject New subject.
     * @param string|null $message New message.
     * @return array Result with status.
     */
    public static function edit_discussion(int $discussionid, ?string $subject = null, ?string $message = null): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::edit_discussion_parameters(), [
            'discussionid' => $discussionid,
            'subject' => $subject,
            'message' => $message,
        ]);

        // Get discussion and related records.
        $discussion = $DB->get_record('forum_discussions', ['id' => $params['discussionid']], '*', MUST_EXIST);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);

        // Check permissions.
        $caneditsown = ($post->userid == $USER->id) && has_capability('mod/forum:replypost', $context);
        $caneditany = has_capability('mod/forum:editanypost', $context);

        if (!$caneditsown && !$caneditany) {
            throw new \moodle_exception('cannotupdatepost', 'forum');
        }

        // Update discussion subject if provided.
        if ($params['subject'] !== null) {
            $discussion->name = $params['subject'];
            $discussion->timemodified = time();
            $discussion->usermodified = $USER->id;
            $DB->update_record('forum_discussions', $discussion);
        }

        // Update post message if provided.
        if ($params['message'] !== null) {
            $post->subject = $params['subject'] !== null ? $params['subject'] : $post->subject;
            $post->message = $params['message'];
            $post->messageformat = FORMAT_HTML;
            $post->modified = time();
            $DB->update_record('forum_posts', $post);
        }

        \cache_helper::purge_by_event('changesincourse');

        return [
            'status' => 'updated',
            'discussionid' => $params['discussionid'],
        ];
    }

    /**
     * Describes the return value for edit_discussion.
     *
     * @return external_single_structure
     */
    public static function edit_discussion_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'discussionid' => new external_value(PARAM_INT, 'Discussion ID'),
        ]);
    }

    // =========================================================================
    // DELETE DISCUSSION
    // =========================================================================

    /**
     * Describes the parameters for delete_discussion.
     *
     * @return external_function_parameters
     */
    public static function delete_discussion_parameters(): external_function_parameters {
        return new external_function_parameters([
            'discussionid' => new external_value(PARAM_INT, 'Discussion ID'),
        ]);
    }

    /**
     * Delete a discussion.
     *
     * @param int $discussionid Discussion ID.
     * @return array Result with status.
     */
    public static function delete_discussion(int $discussionid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::delete_discussion_parameters(), [
            'discussionid' => $discussionid,
        ]);

        // Get discussion and related records.
        $discussion = $DB->get_record('forum_discussions', ['id' => $params['discussionid']], '*', MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => $discussion->forum], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $forum->id, $forum->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        self::validate_context($context);

        // Check permissions.
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], 'userid', MUST_EXIST);
        $candeleteown = ($post->userid == $USER->id) && has_capability('mod/forum:deleteownpost', $context);
        $candeleteany = has_capability('mod/forum:deleteanypost', $context);

        if (!$candeleteown && !$candeleteany) {
            throw new \moodle_exception('cannotdeletediscussion', 'forum');
        }

        // Delete the discussion.
        forum_delete_discussion($discussion, false, $forum->course, $cm, $forum);

        \cache_helper::purge_by_event('changesincourse');

        return [
            'status' => 'deleted',
            'discussionid' => $params['discussionid'],
        ];
    }

    /**
     * Describes the return value for delete_discussion.
     *
     * @return external_single_structure
     */
    public static function delete_discussion_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'discussionid' => new external_value(PARAM_INT, 'Deleted discussion ID'),
        ]);
    }
}
