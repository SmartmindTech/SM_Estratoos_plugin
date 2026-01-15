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
require_once($CFG->libdir . '/completionlib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

/**
 * Mark a course module as viewed and trigger completion if configured.
 *
 * This function provides functionality similar to the non-existent
 * core_completion_mark_course_module_viewed, which is needed for
 * external LMS players to track progress.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_module_viewed extends external_api {

    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Mark a course module as viewed.
     *
     * @param int $cmid Course module ID.
     * @return array Result with status and warnings.
     */
    public static function execute(int $cmid): array {
        global $DB, $USER, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cmid = $params['cmid'];

        $warnings = [];

        // Get course module.
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

        // Validate context.
        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Check if user can view the module.
        require_capability('moodle/course:view', $context);

        // Get course module info.
        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cmid);

        if (!$cminfo->uservisible) {
            return [
                'status' => false,
                'message' => 'Module is not visible to user',
                'warnings' => $warnings,
            ];
        }

        // Trigger the course module viewed event.
        // This is the standard way Moodle tracks views.
        $eventdata = [
            'context' => $context,
            'objectid' => $cm->instance,
        ];

        // Try to trigger the module-specific view event.
        $eventclass = "\\mod_{$cm->modname}\\event\\course_module_viewed";
        if (class_exists($eventclass)) {
            try {
                $event = $eventclass::create($eventdata);
                $event->add_record_snapshot('course', $course);
                $event->add_record_snapshot('course_modules', $cm);
                $moduleinstance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                if ($moduleinstance) {
                    $event->add_record_snapshot($cm->modname, $moduleinstance);
                }
                $event->trigger();
            } catch (\Exception $e) {
                $warnings[] = [
                    'item' => 'event',
                    'itemid' => $cmid,
                    'warningcode' => 'eventfailed',
                    'message' => 'Could not trigger view event: ' . $e->getMessage(),
                ];
            }
        }

        // Mark as viewed for completion tracking.
        $completion = new \completion_info($course);

        if ($completion->is_enabled($cminfo)) {
            // Check if completion is based on view.
            if ($cminfo->completion == COMPLETION_TRACKING_AUTOMATIC) {
                // Update completion state - this triggers the completion check.
                $completion->set_module_viewed($cminfo);
            }
        }

        // Also try to update the course_modules_viewed table if it exists (Moodle 4.0+).
        try {
            $viewrecord = $DB->get_record('course_modules_viewed', [
                'coursemoduleid' => $cmid,
                'userid' => $USER->id,
            ]);

            if (!$viewrecord) {
                $DB->insert_record('course_modules_viewed', [
                    'coursemoduleid' => $cmid,
                    'userid' => $USER->id,
                    'timecreated' => time(),
                ]);
            }
        } catch (\Exception $e) {
            // Table might not exist in older Moodle versions.
        }

        // Get current completion state.
        $completionstate = 0;
        if ($completion->is_enabled($cminfo)) {
            $completiondata = $completion->get_data($cminfo, true, $USER->id);
            $completionstate = $completiondata->completionstate ?? 0;
        }

        return [
            'status' => true,
            'message' => 'Module marked as viewed',
            'completionstate' => $completionstate,
            'warnings' => $warnings,
        ];
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if successful'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'completionstate' => new external_value(PARAM_INT, 'Completion state after view (0=incomplete, 1=complete, 2=complete_pass, 3=complete_fail)', VALUE_DEFAULT, 0),
            'warnings' => new \external_warnings(),
        ]);
    }
}
