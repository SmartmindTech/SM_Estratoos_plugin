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
 * Supports both old and new SCORM table schemas:
 * - Old Schema (Moodle 3.x): mdl_scorm_scoes_track
 * - New Schema (Moodle 4.x+, IOMAD): mdl_scorm_scoes_value + mdl_scorm_element + mdl_scorm_attempt
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
     * Reads score from activity-specific tables and updates the central gradebook.
     * Handles both old and new SCORM table schemas automatically.
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
        $courseid = $cm->course;

        // Validate context.
        self::validate_context($context);

        // Check capability - user needs to be able to view grades.
        require_capability('moodle/grade:view', $context);

        // =====================================================
        // STEP 1: Get the raw score from activity-specific tables
        // =====================================================
        $rawscore = null;

        switch ($modname) {
            case 'scorm':
                $rawscore = self::get_scorm_score($instanceid, $userid);
                break;
            case 'quiz':
                $rawscore = self::get_quiz_score($instanceid, $userid);
                break;
            case 'assign':
                $rawscore = self::get_assign_score($instanceid, $userid);
                break;
            case 'lesson':
                $rawscore = self::get_lesson_score($instanceid, $userid);
                break;
            case 'h5pactivity':
                $rawscore = self::get_h5p_score($instanceid, $userid);
                break;
            case 'lti':
                $rawscore = self::get_lti_score($instanceid, $userid);
                break;
            default:
                // For other activities, we'll try the standard grade update function.
                $rawscore = null;
        }

        // =====================================================
        // STEP 2: Update the gradebook using grade_update()
        // =====================================================
        $gradeupdated = false;

        if ($rawscore !== null) {
            $grades = [];
            $grades[$userid] = new \stdClass();
            $grades[$userid]->userid = $userid;
            $grades[$userid]->rawgrade = $rawscore;

            $updateresult = grade_update(
                'mod/' . $modname,
                $courseid,
                'mod',
                $modname,
                $instanceid,
                0,
                $grades
            );

            if ($updateresult === GRADE_UPDATE_OK) {
                $gradeupdated = true;
            } else {
                $warnings[] = [
                    'item' => 'gradeupdate',
                    'itemid' => $instanceid,
                    'warningcode' => 'gradeupdatefailed',
                    'message' => 'grade_update() returned: ' . $updateresult,
                ];
            }
        } else {
            // No raw score found, try the standard module grade update function.
            $libfile = $CFG->dirroot . '/mod/' . $modname . '/lib.php';
            if (file_exists($libfile)) {
                require_once($libfile);
                $functionname = $modname . '_update_grades';
                if (function_exists($functionname)) {
                    try {
                        $functionname($activity, $userid);
                        $gradeupdated = true;
                    } catch (\Exception $e) {
                        $warnings[] = [
                            'item' => 'gradeupdate',
                            'itemid' => $instanceid,
                            'warningcode' => 'standardgradeupdatefailed',
                            'message' => 'Standard grade update threw exception: ' . $e->getMessage(),
                        ];
                    }
                }
            }
        }

        // =====================================================
        // STEP 3: Update activity-specific grade storage
        // This ensures internal activity views show correct grade
        // (e.g., /mod/scorm/report.php, /mod/scorm/view.php)
        // =====================================================
        switch ($modname) {
            case 'scorm':
                self::update_scorm_internal_grade($activity, $userid, $warnings);
                break;
            case 'lesson':
                self::update_lesson_internal_grade($activity, $userid, $warnings);
                break;
            case 'h5pactivity':
                self::update_h5p_internal_grade($activity, $userid, $warnings);
                break;
            // Quiz and Assignment grades are auto-calculated from attempts, no extra step needed.
        }

        // =====================================================
        // STEP 4: Read back from gradebook to confirm
        // =====================================================
        $grade = null;
        $grademax = null;
        $grademin = null;

        try {
            $gradeitem = $DB->get_record('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $instanceid,
                'courseid' => $courseid,
            ]);

            if ($gradeitem) {
                $grademax = floatval($gradeitem->grademax);
                $grademin = floatval($gradeitem->grademin);

                $gradegrade = $DB->get_record('grade_grades', [
                    'itemid' => $gradeitem->id,
                    'userid' => $userid,
                ]);

                if ($gradegrade && $gradegrade->finalgrade !== null) {
                    $grade = floatval($gradegrade->finalgrade);
                    $gradeupdated = true;
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
            'status' => $gradeupdated,
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
     * Get SCORM score - handles BOTH old and new table schemas.
     *
     * New Schema (Moodle 4.x+, IOMAD):
     *   - mdl_scorm_scoes_value
     *   - mdl_scorm_element
     *   - mdl_scorm_attempt
     *
     * Old Schema (Moodle 3.x and some 4.x):
     *   - mdl_scorm_scoes_track
     *
     * @param int $scormid SCORM instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_scorm_score(int $scormid, int $userid): ?float {
        global $DB;

        $dbman = $DB->get_manager();

        // =====================================================
        // TRY NEW SCHEMA FIRST (Moodle 4.x+, IOMAD)
        // Tables: mdl_scorm_scoes_value + mdl_scorm_element + mdl_scorm_attempt
        // =====================================================
        if ($dbman->table_exists('scorm_scoes_value') &&
            $dbman->table_exists('scorm_element') &&
            $dbman->table_exists('scorm_attempt')) {

            // Try cmi.core.score.raw (SCORM 1.2).
            $sql = "SELECT sv.value
                    FROM {scorm_scoes_value} sv
                    JOIN {scorm_element} e ON sv.elementid = e.id
                    JOIN {scorm_attempt} a ON sv.attemptid = a.id
                    WHERE a.scormid = :scormid
                      AND a.userid = :userid
                      AND e.element = :element
                    ORDER BY sv.timemodified DESC";

            $score = $DB->get_field_sql($sql, [
                'scormid' => $scormid,
                'userid' => $userid,
                'element' => 'cmi.core.score.raw',
            ], IGNORE_MULTIPLE);

            if ($score !== false && $score !== null && $score !== '') {
                return floatval($score);
            }

            // Try cmi.score.raw (SCORM 2004).
            $score = $DB->get_field_sql($sql, [
                'scormid' => $scormid,
                'userid' => $userid,
                'element' => 'cmi.score.raw',
            ], IGNORE_MULTIPLE);

            if ($score !== false && $score !== null && $score !== '') {
                return floatval($score);
            }
        }

        // =====================================================
        // TRY OLD SCHEMA (Moodle 3.x and some 4.x)
        // Table: mdl_scorm_scoes_track
        // =====================================================
        if ($dbman->table_exists('scorm_scoes_track')) {
            // Get latest attempt number.
            $attempt = $DB->get_field_sql(
                "SELECT MAX(attempt) FROM {scorm_scoes_track} WHERE scormid = :scormid AND userid = :userid",
                ['scormid' => $scormid, 'userid' => $userid]
            );

            if ($attempt) {
                // Try cmi.core.score.raw (SCORM 1.2).
                $score = $DB->get_field('scorm_scoes_track', 'value', [
                    'scormid' => $scormid,
                    'userid' => $userid,
                    'attempt' => $attempt,
                    'element' => 'cmi.core.score.raw',
                ]);

                if ($score !== false && $score !== null && $score !== '') {
                    return floatval($score);
                }

                // Try cmi.score.raw (SCORM 2004).
                $score = $DB->get_field('scorm_scoes_track', 'value', [
                    'scormid' => $scormid,
                    'userid' => $userid,
                    'attempt' => $attempt,
                    'element' => 'cmi.score.raw',
                ]);

                if ($score !== false && $score !== null && $score !== '') {
                    return floatval($score);
                }
            }
        }

        return null;
    }

    /**
     * Get Quiz score from mdl_quiz_attempts.
     *
     * @param int $quizid Quiz instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_quiz_score(int $quizid, int $userid): ?float {
        global $DB;

        // Get the best/latest finished attempt.
        $sql = "SELECT sumgrades
                FROM {quiz_attempts}
                WHERE quiz = :quizid
                  AND userid = :userid
                  AND state = 'finished'
                ORDER BY sumgrades DESC, timemodified DESC";

        $sumgrades = $DB->get_field_sql($sql, [
            'quizid' => $quizid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        if ($sumgrades !== false && $sumgrades !== null) {
            // Get quiz settings to scale the grade.
            $quiz = $DB->get_record('quiz', ['id' => $quizid], 'grade, sumgrades');
            if ($quiz && $quiz->sumgrades > 0) {
                // Scale to quiz grade.
                return (floatval($sumgrades) / floatval($quiz->sumgrades)) * floatval($quiz->grade);
            }
            return floatval($sumgrades);
        }

        return null;
    }

    /**
     * Get Assignment score from mdl_assign_grades.
     *
     * @param int $assignid Assignment instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_assign_score(int $assignid, int $userid): ?float {
        global $DB;

        // Get the latest grade (there might be multiple for re-grading).
        $sql = "SELECT grade
                FROM {assign_grades}
                WHERE assignment = :assignid
                  AND userid = :userid
                  AND grade >= 0
                ORDER BY timemodified DESC";

        $grade = $DB->get_field_sql($sql, [
            'assignid' => $assignid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        if ($grade !== false && $grade !== null) {
            return floatval($grade);
        }

        return null;
    }

    /**
     * Get Lesson score from mdl_lesson_grades.
     *
     * @param int $lessonid Lesson instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_lesson_score(int $lessonid, int $userid): ?float {
        global $DB;

        // Get the latest/best grade.
        $sql = "SELECT grade
                FROM {lesson_grades}
                WHERE lessonid = :lessonid
                  AND userid = :userid
                ORDER BY completed DESC";

        $grade = $DB->get_field_sql($sql, [
            'lessonid' => $lessonid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        if ($grade !== false && $grade !== null) {
            return floatval($grade);
        }

        return null;
    }

    /**
     * Get H5P score from mdl_h5pactivity_attempts.
     *
     * @param int $h5pid H5P activity instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_h5p_score(int $h5pid, int $userid): ?float {
        global $DB;

        // Get the best attempt (H5P stores scaled score 0-1).
        $sql = "SELECT scaled
                FROM {h5pactivity_attempts}
                WHERE h5pactivityid = :h5pid
                  AND userid = :userid
                ORDER BY scaled DESC, timemodified DESC";

        $scaled = $DB->get_field_sql($sql, [
            'h5pid' => $h5pid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        if ($scaled !== false && $scaled !== null) {
            // H5P stores scaled score (0-1), convert to percentage.
            return floatval($scaled) * 100;
        }

        return null;
    }

    /**
     * Get LTI score from mdl_lti_submission.
     *
     * @param int $ltiid LTI instance ID
     * @param int $userid User ID
     * @return float|null Score or null if not found
     */
    private static function get_lti_score(int $ltiid, int $userid): ?float {
        global $DB;

        // Get the latest submission grade.
        $sql = "SELECT originalgrade
                FROM {lti_submission}
                WHERE ltiid = :ltiid
                  AND userid = :userid
                ORDER BY datesubmitted DESC";

        $grade = $DB->get_field_sql($sql, [
            'ltiid' => $ltiid,
            'userid' => $userid,
        ], IGNORE_MULTIPLE);

        if ($grade !== false && $grade !== null) {
            // LTI stores grade as 0-1, convert to percentage.
            return floatval($grade) * 100;
        }

        return null;
    }

    /**
     * Update SCORM's internal grade storage.
     *
     * This ensures /mod/scorm/report.php and /mod/scorm/view.php show correct grade.
     *
     * CRITICAL: Before calling scorm_update_grades(), we must update the simplified
     * 'score_raw' element to match the full 'cmi.core.score.raw' value. Otherwise,
     * scorm_update_grades() will read the old score_raw value and overwrite our
     * correct grade.
     *
     * @param object $scorm The SCORM activity record
     * @param int $userid User ID
     * @param array &$warnings Warnings array (passed by reference)
     */
    private static function update_scorm_internal_grade($scorm, int $userid, array &$warnings): void {
        global $CFG, $DB;

        try {
            require_once($CFG->dirroot . '/mod/scorm/lib.php');

            // Get the raw score we calculated earlier.
            $rawscore = self::get_scorm_score($scorm->id, $userid);

            // =====================================================
            // CRITICAL: Update the simplified 'score_raw' element
            // to match the full 'cmi.core.score.raw' value.
            // This ensures scorm_update_grades() uses the correct score.
            // =====================================================
            if ($rawscore !== null) {
                self::sync_scorm_score_raw($scorm->id, $userid, $rawscore, $warnings);
            }

            // Now call SCORM's native grade update - it will read the updated score_raw.
            scorm_update_grades($scorm, $userid);
        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'scorminternalgrade',
                'itemid' => $scorm->id,
                'warningcode' => 'scorminternalgradeerror',
                'message' => 'Failed to update SCORM internal grade: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Sync the simplified 'score_raw' element to match 'cmi.core.score.raw'.
     *
     * scorm_update_grades() reads from the simplified 'score_raw' element,
     * not from 'cmi.core.score.raw'. If these are out of sync, the grade
     * will be incorrect. This function updates 'score_raw' to match.
     *
     * Handles both old schema (scorm_scoes_track) and new schema
     * (scorm_scoes_value + scorm_element + scorm_attempt).
     *
     * @param int $scormid SCORM instance ID
     * @param int $userid User ID
     * @param float $rawscore The correct raw score
     * @param array &$warnings Warnings array (passed by reference)
     */
    private static function sync_scorm_score_raw(int $scormid, int $userid, float $rawscore, array &$warnings): void {
        global $DB;

        $dbman = $DB->get_manager();

        // =====================================================
        // TRY NEW SCHEMA (Moodle 4.x+, IOMAD)
        // =====================================================
        if ($dbman->table_exists('scorm_scoes_value') &&
            $dbman->table_exists('scorm_element') &&
            $dbman->table_exists('scorm_attempt')) {

            try {
                // Get the latest attempt for this user.
                $attempt = $DB->get_record_sql(
                    "SELECT a.id
                     FROM {scorm_attempt} a
                     WHERE a.scormid = :scormid AND a.userid = :userid
                     ORDER BY a.attempt DESC",
                    ['scormid' => $scormid, 'userid' => $userid],
                    IGNORE_MULTIPLE
                );

                if ($attempt) {
                    // Get or check if 'score_raw' element exists.
                    $element = $DB->get_record('scorm_element', ['element' => 'score_raw']);

                    if ($element) {
                        // Check if value exists for this attempt.
                        $existing = $DB->get_record('scorm_scoes_value', [
                            'attemptid' => $attempt->id,
                            'elementid' => $element->id,
                        ]);

                        if ($existing) {
                            // Update existing value.
                            $DB->set_field('scorm_scoes_value', 'value', strval($rawscore), ['id' => $existing->id]);
                            $DB->set_field('scorm_scoes_value', 'timemodified', time(), ['id' => $existing->id]);
                        } else {
                            // Insert new value - need to get scoid from another value in same attempt.
                            $scoid = $DB->get_field_sql(
                                "SELECT scoid FROM {scorm_scoes_value} WHERE attemptid = :attemptid",
                                ['attemptid' => $attempt->id],
                                IGNORE_MULTIPLE
                            );

                            if ($scoid) {
                                $record = new \stdClass();
                                $record->scoid = $scoid;
                                $record->attemptid = $attempt->id;
                                $record->elementid = $element->id;
                                $record->value = strval($rawscore);
                                $record->timemodified = time();
                                $DB->insert_record('scorm_scoes_value', $record);
                            }
                        }
                        return; // Success with new schema.
                    }
                }
            } catch (\Exception $e) {
                $warnings[] = [
                    'item' => 'scormscorerawsync',
                    'itemid' => $scormid,
                    'warningcode' => 'newschemaerror',
                    'message' => 'Error syncing score_raw (new schema): ' . $e->getMessage(),
                ];
            }
        }

        // =====================================================
        // TRY OLD SCHEMA (Moodle 3.x and some 4.x)
        // =====================================================
        if ($dbman->table_exists('scorm_scoes_track')) {
            try {
                // Get latest attempt number.
                $attempt = $DB->get_field_sql(
                    "SELECT MAX(attempt) FROM {scorm_scoes_track} WHERE scormid = :scormid AND userid = :userid",
                    ['scormid' => $scormid, 'userid' => $userid]
                );

                if ($attempt) {
                    // Get scoid from an existing track.
                    $scoid = $DB->get_field_sql(
                        "SELECT scoid FROM {scorm_scoes_track}
                         WHERE scormid = :scormid AND userid = :userid AND attempt = :attempt",
                        ['scormid' => $scormid, 'userid' => $userid, 'attempt' => $attempt],
                        IGNORE_MULTIPLE
                    );

                    if ($scoid) {
                        // Check if score_raw exists for this attempt.
                        $existing = $DB->get_record('scorm_scoes_track', [
                            'scormid' => $scormid,
                            'userid' => $userid,
                            'attempt' => $attempt,
                            'element' => 'score_raw',
                        ]);

                        if ($existing) {
                            // Update existing value.
                            $DB->set_field('scorm_scoes_track', 'value', strval($rawscore), ['id' => $existing->id]);
                            $DB->set_field('scorm_scoes_track', 'timemodified', time(), ['id' => $existing->id]);
                        } else {
                            // Insert new track record.
                            $record = new \stdClass();
                            $record->scormid = $scormid;
                            $record->scoid = $scoid;
                            $record->userid = $userid;
                            $record->attempt = $attempt;
                            $record->element = 'score_raw';
                            $record->value = strval($rawscore);
                            $record->timemodified = time();
                            $DB->insert_record('scorm_scoes_track', $record);
                        }
                    }
                }
            } catch (\Exception $e) {
                $warnings[] = [
                    'item' => 'scormscorerawsync',
                    'itemid' => $scormid,
                    'warningcode' => 'oldschemaerror',
                    'message' => 'Error syncing score_raw (old schema): ' . $e->getMessage(),
                ];
            }
        }
    }

    /**
     * Update Lesson's internal grade storage.
     *
     * This ensures /mod/lesson/report.php and /mod/lesson/view.php show correct grade.
     * Calls lesson_update_grades() which recalculates grade from attempts.
     *
     * @param object $lesson The Lesson activity record
     * @param int $userid User ID
     * @param array &$warnings Warnings array (passed by reference)
     */
    private static function update_lesson_internal_grade($lesson, int $userid, array &$warnings): void {
        global $CFG;

        try {
            require_once($CFG->dirroot . '/mod/lesson/lib.php');

            // Call Lesson's native grade update function.
            lesson_update_grades($lesson, $userid);
        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'lessoninternalgrade',
                'itemid' => $lesson->id,
                'warningcode' => 'lessoninternalgradeerror',
                'message' => 'Failed to update Lesson internal grade: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update H5P's internal grade storage.
     *
     * This ensures /mod/h5pactivity/report.php and /mod/h5pactivity/view.php show correct grade.
     * Calls h5pactivity_update_grades() which recalculates grade from attempts.
     *
     * @param object $h5pactivity The H5P activity record
     * @param int $userid User ID
     * @param array &$warnings Warnings array (passed by reference)
     */
    private static function update_h5p_internal_grade($h5pactivity, int $userid, array &$warnings): void {
        global $CFG;

        try {
            require_once($CFG->dirroot . '/mod/h5pactivity/lib.php');

            // Call H5P's native grade update function.
            if (function_exists('h5pactivity_update_grades')) {
                h5pactivity_update_grades($h5pactivity, $userid);
            }
        } catch (\Exception $e) {
            $warnings[] = [
                'item' => 'h5pinternalgrade',
                'itemid' => $h5pactivity->id,
                'warningcode' => 'h5pinternalgradeerror',
                'message' => 'Failed to update H5P internal grade: ' . $e->getMessage(),
            ];
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
