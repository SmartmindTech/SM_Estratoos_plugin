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
 * Event observers for cache invalidation and real-time progress tracking.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    // =========================================================================
    // Cache Invalidation Events
    // =========================================================================

    // User enrollment events.
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback' => '\local_sm_estratoos_plugin\observer::user_enrolment_created',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_sm_estratoos_plugin\observer::user_enrolment_deleted',
    ],

    // Course module completion (also sends progress event to SmartLearning).
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_sm_estratoos_plugin\observer::course_module_completion_updated',
    ],

    // Course events.
    [
        'eventname' => '\core\event\course_updated',
        'callback' => '\local_sm_estratoos_plugin\observer::course_updated',
    ],

    // User events.
    [
        'eventname' => '\core\event\user_created',
        'callback' => '\local_sm_estratoos_plugin\observer::user_created',
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => '\local_sm_estratoos_plugin\observer::user_updated',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\local_sm_estratoos_plugin\observer::user_deleted',
    ],

    // Message events.
    [
        'eventname' => '\core\event\message_sent',
        'callback' => '\local_sm_estratoos_plugin\observer::message_sent',
    ],

    // Role assignment events - for automatic system-level role assignment.
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => '\local_sm_estratoos_plugin\observer::role_assigned',
    ],

    // =========================================================================
    // WebSocket Progress Events (sent to SmartLearning backend)
    // =========================================================================

    // Quiz events.
    [
        'eventname' => '\mod_quiz\event\attempt_submitted',
        'callback' => '\local_sm_estratoos_plugin\observer::quiz_attempt_submitted',
    ],

    // Book events.
    [
        'eventname' => '\mod_book\event\chapter_viewed',
        'callback' => '\local_sm_estratoos_plugin\observer::book_chapter_viewed',
    ],

    // Lesson events.
    [
        'eventname' => '\mod_lesson\event\page_viewed',
        'callback' => '\local_sm_estratoos_plugin\observer::lesson_page_viewed',
    ],

    // SCORM events.
    [
        'eventname' => '\mod_scorm\event\sco_launched',
        'callback' => '\local_sm_estratoos_plugin\observer::scorm_sco_launched',
    ],
    [
        'eventname' => '\mod_scorm\event\scoreraw_submitted',
        'callback' => '\local_sm_estratoos_plugin\observer::scorm_scoreraw_submitted',
    ],

    // Assignment events.
    [
        'eventname' => '\mod_assign\event\submission_created',
        'callback' => '\local_sm_estratoos_plugin\observer::assign_submission_created',
    ],
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback' => '\local_sm_estratoos_plugin\observer::assign_submission_graded',
    ],

    // =========================================================================
    // Webhook Data Sync Events (push data changes to SmartLearning)
    // =========================================================================

    // Course lifecycle events.
    [
        'eventname' => '\core\event\course_created',
        'callback' => '\local_sm_estratoos_plugin\observer::course_created',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_sm_estratoos_plugin\observer::course_deleted',
    ],

    // Grade events.
    [
        'eventname' => '\core\event\user_graded',
        'callback' => '\local_sm_estratoos_plugin\observer::user_graded',
    ],

    // Calendar events.
    [
        'eventname' => '\core\event\calendar_event_created',
        'callback' => '\local_sm_estratoos_plugin\observer::calendar_event_created',
    ],
    [
        'eventname' => '\core\event\calendar_event_updated',
        'callback' => '\local_sm_estratoos_plugin\observer::calendar_event_updated',
    ],
    [
        'eventname' => '\core\event\calendar_event_deleted',
        'callback' => '\local_sm_estratoos_plugin\observer::calendar_event_deleted',
    ],
];
