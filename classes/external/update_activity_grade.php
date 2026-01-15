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
require_once($CFG->libdir . '/gradelib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;
use grade_item;
use grade_grade;
use invalid_parameter_exception;
use moodle_exception;

/**
 * Force grade recalculation for any gradable activity.
 *
 * This function solves the problem where Moodle web services for saving
 * activity data (mod_scorm_insert_scorm_tracks, mod_quiz_process_attempt, etc.)
 * do NOT trigger grade recalculation. Call this after saving tracking data
 * to force the grade to update.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_activity_grade extends external_api {

    /**
     * Valid module names that support grade updates.
     */
    private const VALID_MODULES = ['scorm', 'quiz', 'assign', 'lesson', 'h5pactivity', 'lti'];

    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'modname' => new external_value(PARAM_ALPHA, 'Module name: scorm, quiz, assign, lesson, h5pactivity, lti'),
            'instanceid' => new external_value(PARAM_INT, 'Activity instance ID (NOT the course module ID)'),
            'userid' => new external_value(PARAM_INT, 'User ID (optional, defaults to current user)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Force grade recalculation for a gradable activity.
     *
     * @param string $modname Module name (scorm, quiz, assign, lesson, etc.)
     * @param int $instanceid Activity instance ID
     * @param int $userid User ID (0 = current user)
     * @return array Result with status, grade info, and warnings.
     */
    public static function execute(string $modname, int $instanceid, int $userid = 0): array {
        global $CFG, $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'modname' => $modname,
            'instanceid' => $instanceid,
            'userid' => $userid,
        ]);

        $modname = $params['modname'];
        $instanceid = $params['instanceid'];
        $userid = $params['userid'];

        // Default to current user.
        if ($userid === 0) {
            $userid = $USER->id;
        }

        $warnings = [];

        // Validate module name.
        if (!in_array($modname, self::VALID_MODULES)) {
            throw new invalid_parameter_exception(
                'Invalid module name: ' . $modname . '. Valid modules are: ' . implode(', ', self::VALID_MODULES)
            );
        }

        // Get the activity record.
        $activity = $DB->get_record($modname, ['id' => $instanceid]);
        if (!$activity) {
            throw new invalid_parameter_exception(
                'Activity not found: ' . $modname . ' with ID ' . $instanceid
            );
        }

        // Get course module for context validation.
        $cm = get_coursemodule_from_instance($modname, $instanceid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Validate context.
        self::validate_context($context);

        // Check capability - user needs to be able to view grades.
        require_capability('moodle/grade:view', $context);

        // Include the module's lib file.
        $libfile = $CFG->dirroot . '/mod/' . $modname . '/lib.php';
        if (!file_exists($libfile)) {
            throw new moodle_exception('Module lib file not found: ' . $libfile);
        }
        require_once($libfile);

        // Special handling for SCORM - read score directly from tracks table.
        // Moodle's scorm_update_grades() may not read the score correctly from mdl_scorm_scoes_track.
        if ($modname === 'scorm') {
            $scormgrade = self::update_scorm_grade_directly($activity, $userid, $warnings);
            if ($scormgrade !== null) {
                return [
                    'status' => true,
                    'modname' => $modname,
                    'instanceid' => $instanceid,
                    'userid' => $userid,
                    'grade' => $scormgrade['grade'],
                    'grademax' => $scormgrade['grademax'],
                    'grademin' => $scormgrade['grademin'],
                    'warnings' => $warnings,
                ];
            }
            // If direct update failed, fall through to standard grade update.
        }

        // Build the grade update function name.
        // Pattern: {modname}_update_grades($activity, $userid)
        $functionname = $modname . '_update_grades';

        if (!function_exists($functionname)) {
            throw new moodle_exception(
                'gradefunction',
                'local_sm_estratoos_plugin',
                '',
                $functionname,
                'Grade update function not found: ' . $functionname
            );
        }

        // Call the grade update function.
        try {
            $functionname($activity, $userid);
        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'gradeupdate',
                'itemid' => $instanceid,
                'warningcode' => 'gradeupdatefailed',
                'message' => 'Grade update function threw an exception: ' . $e->getMessage(),
            ];
        }

        // Get the updated grade to return.
        $grade = null;
        $grademax = null;
        $grademin = null;

        try {
            $gradeitem = grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $instanceid,
            ]);

            if ($gradeitem) {
                $grademax = $gradeitem->grademax;
                $grademin = $gradeitem->grademin;

                $grades = grade_grade::fetch_all([
                    'itemid' => $gradeitem->id,
                    'userid' => $userid,
                ]);

                if ($grades) {
                    $gradeobj = reset($grades);
                    $grade = $gradeobj->finalgrade;
                }
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'gradefetch',
                'itemid' => $instanceid,
                'warningcode' => 'gradefetchfailed',
                'message' => 'Could not fetch updated grade: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => true,
            'modname' => $modname,
            'instanceid' => $instanceid,
            'userid' => $userid,
            'grade' => $grade,
            'grademax' => $grademax,
            'grademin' => $grademin,
            'warnings' => $warnings,
        ];
    }

    /**
     * Update SCORM grade directly by reading from tracks table.
     *
     * This bypasses scorm_update_grades() which may not correctly read the score
     * when data is saved via mod_scorm_insert_scorm_tracks. We read the score
     * directly from mdl_scorm_scoes_track and update the gradebook.
     *
     * @param object $scorm The SCORM activity record
     * @param int $userid User ID
     * @param array &$warnings Warnings array (passed by reference)
     * @return array|null Array with grade info, or null if no score found
     */
    private static function update_scorm_grade_directly($scorm, int $userid, array &$warnings): ?array {
        global $DB;

        try {
            // Get the latest score directly from tracks table.
            // Try multiple element name formats that different SCORM versions use.
            $sql = "SELECT t.value, t.attempt, t.timemodified
                    FROM {scorm_scoes_track} t
                    WHERE t.scormid = :scormid
                      AND t.userid = :userid
                      AND t.element IN ('cmi.core.score.raw', 'cmi.score.raw', 'score_raw')
                    ORDER BY t.timemodified DESC, t.attempt DESC
                    LIMIT 1";

            $scorerecord = $DB->get_record_sql($sql, [
                'scormid' => $scorm->id,
                'userid' => $userid,
            ]);

            if (!$scorerecord || $scorerecord->value === null || $scorerecord->value === '') {
                // No score found, let the caller fall back to standard grade update.
                return null;
            }

            $score = floatval($scorerecord->value);

            // Normalize the score based on SCORM max grade.
            // If the SCORM has a max grade different from 100, we need to scale.
            $maxgrade = floatval($scorm->maxgrade);
            if ($maxgrade <= 0) {
                $maxgrade = 100;
            }

            // Check if we need to scale the score.
            // SCORM scores are typically 0-100, but Moodle grade might be different.
            // If maxgrade is 100, use score as-is. Otherwise, scale proportionally.
            // Actually, SCORM packages can have their own max score, so we assume
            // the raw score is already in the correct scale for the SCORM package.
            // We should use the score as-is and let Moodle handle the scaling.

            // Update grade directly in gradebook.
            $grades = [];
            $grades[$userid] = new \stdClass();
            $grades[$userid]->userid = $userid;
            $grades[$userid]->rawgrade = $score;

            $result = grade_update(
                'mod/scorm',
                $scorm->course,
                'mod',
                'scorm',
                $scorm->id,
                0,
                $grades
            );

            if ($result !== GRADE_UPDATE_OK) {
                $warnings[] = [
                    'item' => 'gradeupdate',
                    'itemid' => $scorm->id,
                    'warningcode' => 'directgradeupdatefailed',
                    'message' => 'Direct grade update returned: ' . $result,
                ];
            }

            return [
                'grade' => $score,
                'grademax' => $maxgrade,
                'grademin' => 0.0,
            ];

        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'scormgrade',
                'itemid' => $scorm->id,
                'warningcode' => 'directgradeerror',
                'message' => 'Error reading SCORM score directly: ' . $e->getMessage(),
            ];
            return null;
        }
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if grade update was triggered successfully'),
            'modname' => new external_value(PARAM_ALPHA, 'Module name'),
            'instanceid' => new external_value(PARAM_INT, 'Activity instance ID'),
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'grade' => new external_value(PARAM_FLOAT, 'Updated grade value (null if no grade)', VALUE_OPTIONAL),
            'grademax' => new external_value(PARAM_FLOAT, 'Maximum possible grade', VALUE_OPTIONAL),
            'grademin' => new external_value(PARAM_FLOAT, 'Minimum possible grade', VALUE_OPTIONAL),
            'warnings' => new \external_warnings(),
        ]);
    }
}
