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
 * External function to update a calendar event.
 *
 * Updates calendar event fields: name (title), timestart (date/time),
 * courseid (course), description, and location.
 *
 * Constraints enforced by SmartLearning:
 * - eventtype is ALWAYS 'course' (not editable)
 * - timeduration is ALWAYS 0 / no duration (not editable)
 *   SmartLearning events have only a start date/time — a course has
 *   separate start and end events.
 *
 * Error codes:
 * - event_not_found: Event ID does not exist
 * - empty_name: Event name is empty
 * - invalid_timestart: Timestamp is not a positive integer
 * - invalid_course: Course ID does not exist
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
use external_value;
use external_warnings;

/**
 * API to update a calendar event.
 */
class update_calendar_event extends external_api {

    /** Sentinel value meaning "field was not provided by the caller". */
    const NOT_PROVIDED = '___SM_NOT_PROVIDED___';

    /**
     * Define input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $np = self::NOT_PROVIDED;

        return new external_function_parameters([
            'eventid' => new external_value(
                PARAM_INT, 'Calendar event ID to update'
            ),
            'name' => new external_value(
                PARAM_TEXT, 'Event title', VALUE_DEFAULT, $np
            ),
            'timestart' => new external_value(
                PARAM_INT, 'Event start date/time as Unix timestamp', VALUE_DEFAULT, -1
            ),
            'courseid' => new external_value(
                PARAM_INT, 'Course ID for the event', VALUE_DEFAULT, -1
            ),
            'description' => new external_value(
                PARAM_RAW, 'Event description', VALUE_DEFAULT, $np
            ),
            'location' => new external_value(
                PARAM_RAW, 'Event location', VALUE_DEFAULT, $np
            ),
        ]);
    }

    /**
     * Update a calendar event.
     *
     * @param int    $eventid     Calendar event ID.
     * @param string $name        Event title.
     * @param int    $timestart   Unix timestamp.
     * @param int    $courseid    Course ID.
     * @param string $description Event description.
     * @param string $location    Event location.
     * @return array Result with success flag and error details.
     */
    public static function execute(
        $eventid,
        $name = null,
        $timestart = -1,
        $courseid = -1,
        $description = null,
        $location = null
    ): array {
        global $DB, $USER, $CFG;

        require_once($CFG->dirroot . '/calendar/lib.php');

        $np = self::NOT_PROVIDED;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'eventid' => $eventid,
            'name' => $name,
            'timestart' => $timestart,
            'courseid' => $courseid,
            'description' => $description,
            'location' => $location,
        ]);

        // Basic validation.
        if (empty($USER->id) || isguestuser($USER)) {
            throw new \moodle_exception('invaliduser', 'local_sm_estratoos_plugin');
        }

        // Load existing event.
        $eventrecord = $DB->get_record('event', ['id' => $params['eventid']]);
        if (!$eventrecord) {
            return self::error_response('event_not_found',
                'Calendar event with ID ' . $params['eventid'] . ' not found.');
        }

        // Build update data — only fields that were actually provided.
        $data = new \stdClass();
        $haschanges = false;

        // Name (title).
        if ($params['name'] !== $np) {
            if (trim($params['name']) === '') {
                return self::error_response('empty_name', 'Event name cannot be empty.');
            }
            $data->name = $params['name'];
            $haschanges = true;
        }

        // Time start.
        if ($params['timestart'] >= 0) {
            if ($params['timestart'] === 0) {
                return self::error_response('invalid_timestart', 'Timestamp must be a positive integer.');
            }
            $data->timestart = $params['timestart'];
            $haschanges = true;
        }

        // Course ID.
        if ($params['courseid'] >= 0) {
            if ($params['courseid'] === 0) {
                return self::error_response('invalid_course', 'Course ID must be a positive integer.');
            }
            $course = $DB->get_record('course', ['id' => $params['courseid']]);
            if (!$course) {
                return self::error_response('invalid_course',
                    'Course with ID ' . $params['courseid'] . ' not found.');
            }
            $data->courseid = $params['courseid'];
            $haschanges = true;
        }

        // Description.
        if ($params['description'] !== $np) {
            $data->description = $params['description'];
            $data->format = FORMAT_HTML;
            $haschanges = true;
        }

        // Location.
        if ($params['location'] !== $np) {
            $data->location = $params['location'];
            $haschanges = true;
        }

        if (!$haschanges) {
            return [
                'success' => true,
                'error_code' => '',
                'eventid' => (int) $eventrecord->id,
                'message' => 'No fields to update.',
                'warnings' => [],
            ];
        }

        // Enforce SmartLearning constraints: always course event, no duration.
        $data->eventtype = 'course';
        $data->timeduration = 0;

        // Update using Moodle's calendar_event API.
        $event = \calendar_event::load($eventrecord->id);
        $event->update($data, false);

        return [
            'success' => true,
            'error_code' => '',
            'eventid' => (int) $eventrecord->id,
            'message' => 'Calendar event updated successfully.',
            'warnings' => [],
        ];
    }

    /**
     * Build a standardized error response.
     *
     * @param string $code    Error code.
     * @param string $message Human-readable message.
     * @return array
     */
    private static function error_response(string $code, string $message): array {
        return [
            'success' => false,
            'error_code' => $code,
            'eventid' => 0,
            'message' => $message,
            'warnings' => [],
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the update was successful'),
            'error_code' => new external_value(
                PARAM_ALPHANUMEXT,
                'Error code when success=false: event_not_found, empty_name, invalid_timestart, invalid_course. ' .
                'Empty on success.',
                VALUE_DEFAULT,
                ''
            ),
            'eventid' => new external_value(PARAM_INT, 'The updated event ID'),
            'message' => new external_value(PARAM_TEXT, 'Result message or error details'),
            'warnings' => new external_warnings(),
        ]);
    }
}
