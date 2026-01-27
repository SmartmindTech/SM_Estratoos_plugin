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
 * Lightweight activity progress metadata retrieval.
 *
 * Returns only progress/count metadata for activities without fetching full content.
 * Designed for fast progress tracking and UI updates.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_activity_progress extends external_api {

    /**
     * Define parameters for the function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course module ID'),
                'Course module IDs to get progress for'
            ),
            'modtype' => new external_value(
                PARAM_ALPHA,
                'Activity type filter: scorm, quiz, book, lesson, page, resource, url, assign, all',
                VALUE_DEFAULT,
                'all'
            ),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param array $cmids Course module IDs.
     * @param string $modtype Activity type filter.
     * @return array Activity progress data.
     */
    public static function execute(array $cmids, string $modtype = 'all'): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmids' => $cmids,
            'modtype' => $modtype,
        ]);

        $cmids = $params['cmids'];
        $modtype = strtolower($params['modtype']);
        $userid = $USER->id;

        $results = [];

        if (empty($cmids)) {
            return ['activities' => [], 'warnings' => []];
        }

        // Get course modules with their types.
        list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $sql = "SELECT cm.id, cm.instance, cm.module, cm.course, cm.completion, m.name as modname
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.id $insql";

        $cms = $DB->get_records_sql($sql, $inparams);

        foreach ($cms as $cm) {
            // Filter by modtype if specified.
            if ($modtype !== 'all' && $cm->modname !== $modtype) {
                continue;
            }

            // Validate context access.
            try {
                $context = \context_module::instance($cm->id);
                self::validate_context($context);
            } catch (\Exception $e) {
                continue;
            }

            $progress = [
                'cmid' => (int)$cm->id,
                'modtype' => $cm->modname,
                'instance' => (int)$cm->instance,
                'courseid' => (int)$cm->course,
            ];

            // Get completion status.
            $progress['completion'] = self::get_completion_status($cm, $userid);

            // Get type-specific progress data.
            switch ($cm->modname) {
                case 'scorm':
                    $progress = array_merge($progress, self::get_scorm_progress($cm->instance, $userid, $context));
                    break;

                case 'quiz':
                    $progress = array_merge($progress, self::get_quiz_progress($cm->instance, $userid));
                    break;

                case 'book':
                    $progress = array_merge($progress, self::get_book_progress($cm->instance, $userid));
                    break;

                case 'lesson':
                    $progress = array_merge($progress, self::get_lesson_progress($cm->instance, $userid));
                    break;

                case 'assign':
                    $progress = array_merge($progress, self::get_assignment_progress($cm->instance, $userid));
                    break;

                case 'page':
                case 'resource':
                case 'url':
                case 'folder':
                    $progress = array_merge($progress, self::get_simple_progress($cm, $userid));
                    break;

                default:
                    // Generic completion-only progress.
                    $progress['totalitems'] = 1;
                    $progress['completeditems'] = $progress['completion']['completed'] ? 1 : 0;
                    break;
            }

            // Calculate progress percentage.
            if (isset($progress['totalitems']) && $progress['totalitems'] > 0) {
                $progress['progresspercent'] = round(
                    ($progress['completeditems'] / $progress['totalitems']) * 100,
                    1
                );
            } else {
                $progress['progresspercent'] = 0;
            }

            $results[] = $progress;
        }

        return [
            'activities' => $results,
            'warnings' => [],
        ];
    }

    /**
     * Get completion status for a course module.
     */
    private static function get_completion_status($cm, int $userid): array {
        global $DB;

        $completion = [
            'state' => 0,
            'completed' => false,
            'timemodified' => null,
        ];

        try {
            $record = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $cm->id,
                'userid' => $userid,
            ]);

            if ($record) {
                $completion['state'] = (int)$record->completionstate;
                $completion['completed'] = $record->completionstate > 0;
                $completion['timemodified'] = (int)$record->timemodified;
            }
        } catch (\Exception $e) {
            // Table may not exist.
        }

        return $completion;
    }

    /**
     * Get SCORM progress data.
     */
    private static function get_scorm_progress(int $scormid, int $userid, \context_module $context): array {
        global $DB, $CFG;

        $progress = [
            'slidescount' => 0,
            'currentslide' => null,
            'score' => null,
            'maxscore' => null,
            'attempts' => 0,
            'totalitems' => 0,
            'completeditems' => 0,
            'lessonlocation' => null,
        ];

        // Get SCORM record.
        $scorm = $DB->get_record('scorm', ['id' => $scormid]);
        if (!$scorm) {
            return $progress;
        }

        $progress['maxscore'] = (float)$scorm->maxgrade;

        // Get SCO count.
        $scocount = $DB->count_records('scorm_scoes', ['scorm' => $scormid, 'scormtype' => 'sco']);
        $progress['totalitems'] = $scocount ?: 1;

        // Detect slide count from content files.
        $fs = get_file_storage();
        $contentfiles = $fs->get_area_files($context->id, 'mod_scorm', 'content', 0, 'sortorder', false);
        $contentfilesmap = [];
        foreach ($contentfiles as $file) {
            $path = $file->get_filepath() . $file->get_filename();
            $contentfilesmap[$path] = $file;
        }

        // Try to detect slides using the same logic as get_course_content.
        $slidecount = self::detect_slides_quick($contentfilesmap);
        if ($slidecount > 0) {
            $progress['slidescount'] = $slidecount;
            $progress['totalitems'] = $slidecount;
        } else {
            $progress['slidescount'] = $scocount;
        }

        // Get user tracking data.
        // Moodle 4.x uses normalized tables: scorm_attempt + scorm_scoes_value + scorm_element
        // Older Moodle uses: scorm_scoes_track (element + value in one row)
        try {
            // First, find the primary launchable SCO.
            $primarysco = $DB->get_record_select(
                'scorm_scoes',
                "scorm = :scormid AND scormtype = 'sco' AND launch <> ''",
                ['scormid' => $scormid],
                'id',
                IGNORE_MULTIPLE
            );

            // Check which table structure exists (Moodle 4.x vs older)
            $dbman = $DB->get_manager();
            $usenormalized = $dbman->table_exists('scorm_scoes_value');

            $tracks = [];
            $attempt = 0;

            if ($usenormalized) {
                // Moodle 4.x+ normalized structure: scorm_attempt + scorm_scoes_value + scorm_element
                $attemptrecord = $DB->get_record_sql(
                    "SELECT id, attempt FROM {scorm_attempt}
                     WHERE scormid = :scormid AND userid = :userid
                     ORDER BY attempt DESC LIMIT 1",
                    ['scormid' => $scormid, 'userid' => $userid]
                );

                if ($attemptrecord) {
                    $attempt = (int)$attemptrecord->attempt;
                    $progress['attempts'] = $attempt;

                    // Build SCO filter clause
                    $scoidclause = '';
                    $params = ['attemptid' => $attemptrecord->id];
                    if ($primarysco) {
                        $scoidclause = ' AND v.scoid = :scoid';
                        $params['scoid'] = $primarysco->id;
                    }

                    // Get tracking data from normalized tables
                    $tracksql = "SELECT e.element, v.value, v.timemodified
                                 FROM {scorm_scoes_value} v
                                 JOIN {scorm_element} e ON e.id = v.elementid
                                 WHERE v.attemptid = :attemptid
                                 $scoidclause
                                 ORDER BY v.timemodified DESC";
                    $trackrecords = $DB->get_records_sql($tracksql, $params);

                    // Convert to element => value map
                    foreach ($trackrecords as $track) {
                        if (!isset($tracks[$track->element])) {
                            $tracks[$track->element] = $track->value;
                        }
                    }
                }
            } else {
                // Legacy structure: scorm_scoes_track (Moodle < 4.x)
                $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
                    'scormid' => $scormid,
                    'userid' => $userid,
                ]);

                if ($attempt) {
                    $progress['attempts'] = (int)$attempt;

                    $trackparams = [
                        'scormid' => $scormid,
                        'userid' => $userid,
                        'attempt' => $attempt,
                    ];
                    $scoidclause = '';
                    if ($primarysco) {
                        $trackparams['scoid'] = $primarysco->id;
                        $scoidclause = ' AND scoid = :scoid';
                    }

                    $tracksql = "SELECT id, element, value
                                 FROM {scorm_scoes_track}
                                 WHERE scormid = :scormid
                                   AND userid = :userid
                                   AND attempt = :attempt
                                   $scoidclause
                                 ORDER BY timemodified DESC";
                    $trackrecords = $DB->get_records_sql($tracksql, $trackparams);

                    foreach ($trackrecords as $track) {
                        if (!isset($tracks[$track->element])) {
                            $tracks[$track->element] = $track->value;
                        }
                    }
                }
            }

            if ($attempt) {

                // Extract score.
                foreach (['cmi.core.score.raw', 'cmi.score.raw'] as $key) {
                    if (isset($tracks[$key]) && is_numeric($tracks[$key])) {
                        $progress['score'] = (float)$tracks[$key];
                        break;
                    }
                }

                // Extract lesson location (current slide).
                // SCORM 1.2: cmi.core.lesson_location
                // SCORM 2004: cmi.location
                foreach (['cmi.core.lesson_location', 'cmi.location'] as $key) {
                    if (isset($tracks[$key]) && $tracks[$key] !== '') {
                        $location = $tracks[$key];
                        $progress['lessonlocation'] = $location;

                        // Parse slide number from various formats.
                        // Format 1: Pure number "5"
                        if (is_numeric($location)) {
                            $progress['currentslide'] = (int)$location;
                        }
                        // Format 2: Trailing number "slide_5" or "scene1_slide5"
                        else if (preg_match('/(\d+)$/', $location, $m)) {
                            $progress['currentslide'] = (int)$m[1];
                        }
                        // Format 3: "5/10" format (current/total)
                        else if (preg_match('/^(\d+)\//', $location, $m)) {
                            $progress['currentslide'] = (int)$m[1];
                        }
                        // Format 4: Articulate format "#/slides/xxx" - extract slide number
                        else if (preg_match('/slide(\d+)/i', $location, $m)) {
                            $progress['currentslide'] = (int)$m[1];
                        }
                        break;
                    }
                }

                // Also check suspend_data for some SCORM packages that store position there.
                if ($progress['currentslide'] === null) {
                    foreach (['cmi.suspend_data', 'cmi.core.suspend_data'] as $key) {
                        if (isset($tracks[$key]) && $tracks[$key] !== '') {
                            // Try to parse JSON suspend_data (Articulate/iSpring format).
                            $suspenddata = @json_decode($tracks[$key], true);
                            if ($suspenddata && isset($suspenddata['currentSlide'])) {
                                $progress['currentslide'] = (int)$suspenddata['currentSlide'];
                                break;
                            }
                            // Try numeric extraction from suspend_data.
                            if (preg_match('/(?:slide|page|position)["\s:=]+(\d+)/i', $tracks[$key], $m)) {
                                $progress['currentslide'] = (int)$m[1];
                                break;
                            }
                        }
                    }
                }

                // Count completed SCOs based on lesson_status.
                // Check the $tracks array for completion status (already loaded above).
                $completedcount = 0;
                $lessonstatuskeys = ['cmi.core.lesson_status', 'cmi.completion_status'];
                foreach ($lessonstatuskeys as $statuskey) {
                    if (isset($tracks[$statuskey])) {
                        $statusvalue = strtolower($tracks[$statuskey]);
                        if (in_array($statusvalue, ['completed', 'passed'])) {
                            $completedcount = 1;
                            break;
                        }
                    }
                }

                // Calculate progress from available data.
                // Priority: currentslide > score-based calculation > completed SCOs
                if ($progress['currentslide'] !== null && $progress['slidescount'] > 0) {
                    // Have explicit slide position.
                    $progress['completeditems'] = $progress['currentslide'];
                } else if ($progress['score'] !== null && $progress['slidescount'] > 0) {
                    // Use score as progress percentage (common for Articulate Storyline).
                    // cmi.core.score.raw often represents progress % (0-100).
                    $scorepercent = min($progress['score'], 100);
                    $progress['currentslide'] = (int)round(($scorepercent / 100) * $progress['slidescount']);
                    $progress['completeditems'] = $progress['currentslide'];
                    // Override progresspercent directly from score for accuracy.
                    $progress['progresspercent'] = round($scorepercent, 1);
                } else {
                    $progress['completeditems'] = $completedcount ?: 0;
                }
            }
        } catch (\Exception $e) {
            // Tracking table may not exist - add error details for debugging.
            debugging('SCORM tracking error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return $progress;
    }

    /**
     * Quick slide detection (simplified version).
     */
    private static function detect_slides_quick(array $contentfilesmap): int {
        $slidenumbers = [];

        foreach ($contentfilesmap as $path => $file) {
            // Articulate Storyline: story_content/slideXXX.xml
            if (preg_match('#/story_content/slide(\d+)\.xml$#i', $path, $m)) {
                $slidenumbers[$m[1]] = true;
            }
            // Generic slide files: slide1.js, slide2.html, etc.
            if (preg_match('#/(?:res/data|slides|content|data)/slide(\d+)\.(js|html|css)$#i', $path, $m)) {
                $slidenumbers[$m[1]] = true;
            }
        }

        // If we found numbered files, return count.
        if (!empty($slidenumbers)) {
            return count($slidenumbers);
        }

        // Try reading slides.xml for Storyline.
        if (isset($contentfilesmap['/story_content/slides.xml'])) {
            $content = $contentfilesmap['/story_content/slides.xml']->get_content();
            $count = preg_match_all('/<sld\s/i', $content);
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }

    /**
     * Get Quiz progress data.
     */
    private static function get_quiz_progress(int $quizid, int $userid): array {
        global $DB;

        $progress = [
            'questioncount' => 0,
            'answeredcount' => 0,
            'score' => null,
            'maxscore' => null,
            'attempts' => 0,
            'attemptsallowed' => 0,
            'state' => null,
            'totalitems' => 0,
            'completeditems' => 0,
        ];

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return $progress;
        }

        $progress['maxscore'] = (float)$quiz->grade;
        $progress['attemptsallowed'] = (int)$quiz->attempts;

        // Count questions.
        $questioncount = $DB->count_records('quiz_slots', ['quizid' => $quizid]);
        $progress['questioncount'] = $questioncount;
        $progress['totalitems'] = $questioncount ?: 1;

        // Get user attempts.
        $attempts = $DB->get_records('quiz_attempts', [
            'quiz' => $quizid,
            'userid' => $userid,
        ], 'attempt DESC');

        $progress['attempts'] = count($attempts);

        if (!empty($attempts)) {
            $latest = reset($attempts);
            $progress['state'] = $latest->state;

            if ($latest->state === 'finished') {
                $progress['score'] = (float)$latest->sumgrades;
                $progress['completeditems'] = $questioncount;
            } else if ($latest->state === 'inprogress') {
                // Count answered questions in current attempt.
                $answered = $DB->count_records_select('question_attempts',
                    "questionusageid = :qubaid AND responsesummary IS NOT NULL AND responsesummary != ''",
                    ['qubaid' => $latest->uniqueid]
                );
                $progress['answeredcount'] = $answered;
                $progress['completeditems'] = $answered;
            }
        }

        return $progress;
    }

    /**
     * Get Book progress data.
     */
    private static function get_book_progress(int $bookid, int $userid): array {
        global $DB;

        $progress = [
            'chaptercount' => 0,
            'viewedchapters' => 0,
            'currentchapter' => null,
            'totalitems' => 0,
            'completeditems' => 0,
        ];

        // Count chapters.
        $chaptercount = $DB->count_records('book_chapters', ['bookid' => $bookid, 'hidden' => 0]);
        $progress['chaptercount'] = $chaptercount;
        $progress['totalitems'] = $chaptercount ?: 1;

        // Get viewed chapters from log (if available).
        try {
            $viewed = $DB->get_records_sql(
                "SELECT DISTINCT other
                 FROM {logstore_standard_log}
                 WHERE userid = :userid
                   AND component = 'mod_book'
                   AND action = 'viewed'
                   AND target = 'chapter'
                   AND contextinstanceid IN (
                       SELECT cm.id FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module AND m.name = 'book'
                       WHERE cm.instance = :bookid
                   )",
                ['userid' => $userid, 'bookid' => $bookid]
            );
            $progress['viewedchapters'] = count($viewed);
            $progress['completeditems'] = count($viewed);
        } catch (\Exception $e) {
            // Log table may not exist.
        }

        return $progress;
    }

    /**
     * Get Lesson progress data.
     */
    private static function get_lesson_progress(int $lessonid, int $userid): array {
        global $DB;

        $progress = [
            'pagecount' => 0,
            'viewedpages' => 0,
            'currentpage' => null,
            'score' => null,
            'maxscore' => null,
            'attempts' => 0,
            'totalitems' => 0,
            'completeditems' => 0,
        ];

        $lesson = $DB->get_record('lesson', ['id' => $lessonid]);
        if (!$lesson) {
            return $progress;
        }

        $progress['maxscore'] = (float)$lesson->grade;

        // Count content pages (qtype = 20) and question pages.
        $pagecount = $DB->count_records('lesson_pages', ['lessonid' => $lessonid]);
        $progress['pagecount'] = $pagecount;
        $progress['totalitems'] = $pagecount ?: 1;

        // Get viewed pages.
        try {
            $viewedpages = $DB->get_records('lesson_branch', [
                'lessonid' => $lessonid,
                'userid' => $userid,
            ]);
            $progress['viewedpages'] = count(array_unique(array_column($viewedpages, 'pageid')));

            // Get last viewed page.
            if (!empty($viewedpages)) {
                $last = end($viewedpages);
                $progress['currentpage'] = (int)$last->pageid;
            }
        } catch (\Exception $e) {
            // Table may not exist.
        }

        // Get attempts and grades.
        try {
            $grades = $DB->get_records('lesson_grades', [
                'lessonid' => $lessonid,
                'userid' => $userid,
            ], 'completed DESC');

            $progress['attempts'] = count($grades);

            if (!empty($grades)) {
                $latest = reset($grades);
                $progress['score'] = (float)$latest->grade;
                $progress['completeditems'] = $progress['pagecount'];
            } else {
                $progress['completeditems'] = $progress['viewedpages'];
            }
        } catch (\Exception $e) {
            // Table may not exist.
        }

        return $progress;
    }

    /**
     * Get Assignment progress data.
     */
    private static function get_assignment_progress(int $assignid, int $userid): array {
        global $DB;

        $progress = [
            'submitted' => false,
            'graded' => false,
            'grade' => null,
            'maxgrade' => null,
            'duedate' => null,
            'status' => 'new',
            'totalitems' => 1,
            'completeditems' => 0,
        ];

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return $progress;
        }

        $progress['maxgrade'] = (float)$assign->grade;
        $progress['duedate'] = $assign->duedate ? (int)$assign->duedate : null;

        // Check submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignid,
            'userid' => $userid,
            'latest' => 1,
        ]);

        if ($submission) {
            $progress['submitted'] = $submission->status === 'submitted';
            $progress['status'] = $submission->status;

            if ($progress['submitted']) {
                $progress['completeditems'] = 1;
            }
        }

        // Check grade.
        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assignid,
            'userid' => $userid,
        ], '*', IGNORE_MULTIPLE);

        if ($grade && $grade->grade >= 0) {
            $progress['graded'] = true;
            $progress['grade'] = (float)$grade->grade;
        }

        return $progress;
    }

    /**
     * Get simple activity progress (page, resource, url).
     */
    private static function get_simple_progress($cm, int $userid): array {
        $progress = [
            'viewed' => false,
            'totalitems' => 1,
            'completeditems' => 0,
        ];

        // Check completion - if viewed, it's complete.
        $completion = self::get_completion_status($cm, $userid);
        $progress['viewed'] = $completion['completed'];
        $progress['completeditems'] = $completion['completed'] ? 1 : 0;

        return $progress;
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                    'modtype' => new external_value(PARAM_ALPHA, 'Activity type'),
                    'instance' => new external_value(PARAM_INT, 'Activity instance ID'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'completion' => new external_single_structure([
                        'state' => new external_value(PARAM_INT, 'Completion state (0=incomplete, 1=complete, 2=complete_pass, 3=complete_fail)'),
                        'completed' => new external_value(PARAM_BOOL, 'Is completed'),
                        'timemodified' => new external_value(PARAM_INT, 'Time of completion', VALUE_OPTIONAL),
                    ]),
                    'progresspercent' => new external_value(PARAM_FLOAT, 'Progress percentage (0-100)'),
                    'totalitems' => new external_value(PARAM_INT, 'Total items (slides, pages, questions, etc.)'),
                    'completeditems' => new external_value(PARAM_INT, 'Completed items'),
                    // SCORM-specific
                    'slidescount' => new external_value(PARAM_INT, 'Total slides (SCORM)', VALUE_OPTIONAL),
                    'currentslide' => new external_value(PARAM_INT, 'Current slide position (SCORM)', VALUE_OPTIONAL),
                    'lessonlocation' => new external_value(PARAM_RAW, 'Raw lesson_location value (SCORM)', VALUE_OPTIONAL),
                    // Quiz-specific
                    'questioncount' => new external_value(PARAM_INT, 'Total questions (Quiz)', VALUE_OPTIONAL),
                    'answeredcount' => new external_value(PARAM_INT, 'Answered questions (Quiz)', VALUE_OPTIONAL),
                    'state' => new external_value(PARAM_ALPHA, 'Attempt state (Quiz)', VALUE_OPTIONAL),
                    'attemptsallowed' => new external_value(PARAM_INT, 'Allowed attempts (Quiz)', VALUE_OPTIONAL),
                    // Book-specific
                    'chaptercount' => new external_value(PARAM_INT, 'Total chapters (Book)', VALUE_OPTIONAL),
                    'viewedchapters' => new external_value(PARAM_INT, 'Viewed chapters (Book)', VALUE_OPTIONAL),
                    'currentchapter' => new external_value(PARAM_INT, 'Current chapter (Book)', VALUE_OPTIONAL),
                    // Lesson-specific
                    'pagecount' => new external_value(PARAM_INT, 'Total pages (Lesson)', VALUE_OPTIONAL),
                    'viewedpages' => new external_value(PARAM_INT, 'Viewed pages (Lesson)', VALUE_OPTIONAL),
                    'currentpage' => new external_value(PARAM_INT, 'Current page (Lesson)', VALUE_OPTIONAL),
                    // Assignment-specific
                    'submitted' => new external_value(PARAM_BOOL, 'Is submitted (Assignment)', VALUE_OPTIONAL),
                    'graded' => new external_value(PARAM_BOOL, 'Is graded (Assignment)', VALUE_OPTIONAL),
                    'duedate' => new external_value(PARAM_INT, 'Due date timestamp (Assignment)', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_ALPHA, 'Submission status (Assignment)', VALUE_OPTIONAL),
                    // Simple activities
                    'viewed' => new external_value(PARAM_BOOL, 'Is viewed (Page/Resource/URL)', VALUE_OPTIONAL),
                    // Common
                    'score' => new external_value(PARAM_FLOAT, 'Current score', VALUE_OPTIONAL),
                    'maxscore' => new external_value(PARAM_FLOAT, 'Maximum score', VALUE_OPTIONAL),
                    'grade' => new external_value(PARAM_FLOAT, 'Grade (Assignment)', VALUE_OPTIONAL),
                    'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade (Assignment)', VALUE_OPTIONAL),
                    'attempts' => new external_value(PARAM_INT, 'Number of attempts', VALUE_OPTIONAL),
                ])
            ),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_TEXT, 'Warning item'),
                    'message' => new external_value(PARAM_TEXT, 'Warning message'),
                ]),
                'Warnings',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
