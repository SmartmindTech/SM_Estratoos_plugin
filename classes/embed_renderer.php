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
 * Chromeless activity renderer for SmartLearning iframe embedding.
 *
 * This class renders Moodle activities without navigation, header, or footer
 * for seamless embedding in SmartLearning's interface.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Chromeless activity renderer class.
 */
class embed_renderer {

    /** @var \cm_info Course module info */
    private $cm;

    /** @var \context_module Module context */
    private $context;

    /** @var string Activity type */
    private $activityType;

    /** @var int|null Target slide for SCORM navigation */
    private $targetSlide;

    /** @var int|null Furthest slide reached (for progress preservation across origins) */
    private $furthestSlide;

    /**
     * Constructor.
     *
     * @param \cm_info $cm Course module info
     * @param int|null $targetSlide Optional target slide for direct SCORM navigation
     * @param int|null $furthestSlide Optional furthest slide for progress preservation
     */
    public function __construct(\cm_info $cm, ?int $targetSlide = null, ?int $furthestSlide = null) {
        $this->cm = $cm;
        $this->context = \context_module::instance($cm->id);
        $this->activityType = $cm->modname;
        $this->targetSlide = $targetSlide;
        $this->furthestSlide = $furthestSlide;
    }

    /**
     * Render the activity content without Moodle chrome.
     *
     * For activities that require full Moodle functionality (SCORM, Quiz, etc.),
     * we redirect to the native Moodle page instead of using iframes. This avoids
     * cross-origin cookie issues that prevent session sharing in iframes.
     *
     * @return string HTML content or performs redirect
     */
    public function render(): string {
        global $PAGE, $OUTPUT, $CFG;

        // Set up minimal page.
        $PAGE->set_pagelayout('embedded');
        $PAGE->set_context($this->context);
        $PAGE->set_cm($this->cm);
        $PAGE->set_title($this->cm->name);

        // Trigger module viewed event (for completion tracking).
        $this->trigger_module_viewed();

        // For complex activities, redirect to native Moodle page.
        // This avoids cross-origin iframe cookie issues.
        switch ($this->activityType) {
            case 'scorm':
                return $this->redirect_to_scorm();
            case 'quiz':
                return $this->redirect_to_quiz();
            case 'assign':
                return $this->redirect_to_activity();
            case 'lesson':
                return $this->render_lesson_iframe();
            case 'book':
                return $this->redirect_to_activity();
            // Simple content types can be rendered inline.
            case 'page':
                return $this->render_page();
            case 'resource':
                return $this->render_resource();
            case 'url':
                return $this->render_url();
            // Interactive activities - always redirect to Moodle view.php in iframe.
            // These need full Moodle JS/session support.
            case 'feedback':  // Encuesta rapida
            case 'data':      // Base de datos
            case 'workshop':  // Taller
            case 'choice':    // Choice/Poll
            case 'survey':    // Survey
            case 'glossary':  // Glossary
            case 'wiki':      // Wiki
            case 'chat':      // Chat
            case 'folder':    // Folder
                return $this->redirect_to_activity();
            default:
                return $this->redirect_to_activity();
        }
    }

    /**
     * Redirect to the native Moodle activity page.
     *
     * This is used for activities that need full Moodle session/JS support.
     * Redirecting instead of using iframe avoids cross-origin cookie issues.
     *
     * For quiz, book, and lesson: if targetSlide is set, constructs a
     * position-aware URL that lands directly on the correct page/chapter/page.
     */
    private function redirect_to_activity(): string {
        global $CFG;

        $url = $CFG->wwwroot . '/mod/' . $this->activityType . '/view.php?id=' . $this->cm->id;

        // If target position specified, construct position-aware URL.
        if ($this->targetSlide !== null && $this->targetSlide > 0) {
            switch ($this->activityType) {
                case 'quiz':
                    $positionurl = $this->get_quiz_position_url($this->targetSlide);
                    if ($positionurl) {
                        $url = $positionurl;
                    }
                    break;
                case 'book':
                    $positionurl = $this->get_book_position_url($this->targetSlide);
                    if ($positionurl) {
                        $url = $positionurl;
                    }
                    break;
                case 'lesson':
                    $positionurl = $this->get_lesson_position_url($this->targetSlide);
                    if ($positionurl) {
                        $url = $positionurl;
                    }
                    break;
            }
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * Redirect to quiz - handles direct navigation to attempt page.
     *
     * Logic:
     * 1. If targetSlide is set and active attempt exists → attempt.php?page=targetSlide-1
     * 2. If furthestSlide is set and active attempt exists → attempt.php?page=furthestSlide-1
     * 3. If active attempt exists (no position) → attempt.php (Moodle resumes at last page)
     * 4. If no active attempt → view.php (user needs to start attempt)
     *
     * @return string Never returns - performs redirect and exits.
     */
    private function redirect_to_quiz(): string {
        global $DB, $CFG, $USER;

        // Find user's active (in-progress) attempt.
        $attempt = $DB->get_record_sql(
            "SELECT id FROM {quiz_attempts}
             WHERE quiz = :quizid AND userid = :userid AND state = 'inprogress'
             ORDER BY attempt DESC LIMIT 1",
            ['quizid' => (int)$this->cm->instance, 'userid' => $USER->id]
        );

        // If no active attempt but targetSlide is specified, try to start a new attempt.
        if (!$attempt && $this->targetSlide !== null && $this->targetSlide > 0) {
            $attempt = $this->start_quiz_attempt();
        }

        if ($attempt) {
            // Active attempt exists - redirect to attempt.php.
            $url = $CFG->wwwroot . '/mod/quiz/attempt.php?attempt=' . $attempt->id
                . '&cmid=' . $this->cm->id;

            // Determine which page to navigate to.
            if ($this->targetSlide !== null && $this->targetSlide > 0) {
                // Direct navigation to specific position (tag click).
                $page = $this->targetSlide - 1; // 1-based → 0-based.
                $url .= '&page=' . $page;
            } elseif ($this->furthestSlide !== null && $this->furthestSlide > 0) {
                // Resume to furthest position (go-back button).
                $page = $this->furthestSlide - 1;
                $url .= '&page=' . $page;
            }
            // If no position specified, Moodle will resume at the last viewed page.
        } else {
            // No active attempt - redirect to view.php (user needs to start attempt).
            $url = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $this->cm->id;
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * Start a new quiz attempt for the current user.
     *
     * Uses Moodle's quiz API to properly start an attempt, respecting all
     * quiz settings (max attempts, time limits, access rules, etc.).
     *
     * @return object|null The new attempt record, or null if unable to start.
     */
    private function start_quiz_attempt(): ?object {
        global $CFG, $DB, $USER;

        try {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
            require_once($CFG->dirroot . '/mod/quiz/lib.php');

            // Load quiz record.
            $quiz = $DB->get_record('quiz', ['id' => $this->cm->instance], '*', MUST_EXIST);

            // Create quiz object using Moodle's quiz API.
            // Moodle 4.2+ uses mod_quiz\quiz_settings, older uses global quiz class.
            if (class_exists('\\mod_quiz\\quiz_settings')) {
                // Moodle 4.2+ (quiz_settings replaced quiz class)
                $quizobj = \mod_quiz\quiz_settings::create($this->cm->instance, $USER->id);
            } else if (class_exists('\\mod_quiz\\quiz')) {
                // Moodle 4.0-4.1 (mod_quiz\quiz namespace)
                $quizobj = \mod_quiz\quiz::create($this->cm->instance, $USER->id);
            } else {
                // Moodle 3.x (global quiz class)
                $quizobj = \quiz::create($this->cm->instance, $USER->id);
            }

            // Get user's previous attempts using the library function.
            $attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
            $numprevattempts = count($attempts);
            $lastattempt = end($attempts);
            if ($lastattempt === false) {
                $lastattempt = null;
            }

            // Check if user can start a new attempt.
            $accessmanager = $quizobj->get_access_manager(time());
            $messages = $accessmanager->prevent_new_attempt($numprevattempts, $lastattempt);

            if (!empty($messages)) {
                // User cannot start a new attempt (max attempts reached, etc.)
                return null;
            }

            // Start the attempt.
            $attemptnumber = $numprevattempts + 1;

            $attempt = quiz_prepare_and_start_new_attempt($quizobj, $attemptnumber, $lastattempt);

            if ($attempt) {
                return (object)['id' => $attempt->id];
            }
        } catch (\Exception $e) {
            // Log error but don't break - will fall back to view.php.
            debugging('SmartMind: Failed to start quiz attempt: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Get a position-aware URL for a quiz attempt page.
     *
     * Finds the user's active (in-progress) attempt and builds a URL
     * to the specific page number. Position 1 = page 0, position 2 = page 1, etc.
     *
     * @param int $position Target position (1-based).
     * @return string|null URL string, or null if no active attempt.
     */
    private function get_quiz_position_url(int $position): ?string {
        global $DB, $CFG, $USER;

        // Find user's active attempt.
        $attempt = $DB->get_record_sql(
            "SELECT id FROM {quiz_attempts}
             WHERE quiz = :quizid AND userid = :userid AND state = 'inprogress'
             ORDER BY attempt DESC LIMIT 1",
            ['quizid' => (int)$this->cm->instance, 'userid' => $USER->id]
        );

        if (!$attempt) {
            return null;
        }

        // Position is 1-based, quiz pages are 0-based.
        $page = $position - 1;
        return $CFG->wwwroot . '/mod/quiz/attempt.php?attempt=' . $attempt->id
            . '&cmid=' . $this->cm->id . '&page=' . $page;
    }

    /**
     * Get a position-aware URL for a book chapter.
     *
     * Queries visible chapters ordered by pagenum and indexes into the list
     * to find the chapter ID at the given position.
     *
     * @param int $position Target position (1-based).
     * @return string|null URL string, or null if position is out of range.
     */
    private function get_book_position_url(int $position): ?string {
        global $DB, $CFG;

        $chapters = $DB->get_records('book_chapters',
            ['bookid' => (int)$this->cm->instance, 'hidden' => 0],
            'pagenum ASC',
            'id'
        );

        $chapters = array_values($chapters);
        $index = $position - 1;

        if (!isset($chapters[$index])) {
            return null;
        }

        return $CFG->wwwroot . '/mod/book/view.php?id=' . $this->cm->id
            . '&chapterid=' . $chapters[$index]->id;
    }

    /**
     * Get a position-aware URL for a lesson page.
     *
     * Queries content pages (excluding endofbranch, cluster, endofcluster)
     * and indexes into the list to find the page ID at the given position.
     *
     * @param int $position Target position (1-based).
     * @return string|null URL string, or null if position is out of range.
     */
    private function get_lesson_position_url(int $position): ?string {
        global $DB, $CFG;

        // Note: lesson_pages uses prevpageid/nextpageid for ordering, not an ordering column
        $pages = $DB->get_records_sql(
            "SELECT id FROM {lesson_pages}
             WHERE lessonid = :lessonid AND qtype NOT IN (21, 30, 31)
             ORDER BY id ASC",
            ['lessonid' => (int)$this->cm->instance]
        );

        $pages = array_values($pages);
        $index = $position - 1;

        if (!isset($pages[$index])) {
            return null;
        }

        return $CFG->wwwroot . '/mod/lesson/view.php?id=' . $this->cm->id
            . '&pageid=' . $pages[$index]->id;
    }

    /**
     * Render SCORM player in an iframe with navigation hidden.
     *
     * Instead of redirecting to the SCORM player, we render it in an iframe
     * within our own page. This allows us to inject CSS via JavaScript to
     * hide the navigation elements (since same-origin allows iframe manipulation).
     */
    private function redirect_to_scorm(): string {
        global $DB, $CFG, $USER;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $scorm = $DB->get_record('scorm', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get first SCO.
        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        if (empty($scoes)) {
            return $this->render_error('No SCOs found in this SCORM package.');
        }

        $sco = reset($scoes);

        // Force TOC and navigation to be hidden in SCORM settings.
        $updates = [];
        if ($scorm->hidetoc != 2) {
            $updates['hidetoc'] = 2;
        }
        if (isset($scorm->nav) && $scorm->nav != 0) {
            $updates['nav'] = 0;
        }
        if (!empty($updates)) {
            $updates['id'] = $scorm->id;
            $DB->update_record('scorm', (object)$updates);
        }

        // =====================================================================
        // PHP-LEVEL SUSPEND_DATA AND LESSON_LOCATION SYNC
        // =====================================================================
        // Storyline uses lesson_location (from database) to determine initial
        // slide position. We must keep lesson_location in sync with suspend_data
        // to prevent loading at stale positions from previous tag navigations.
        // =====================================================================
        if ($this->targetSlide !== null && $this->targetSlide > 0) {
            // Tag navigation: modify suspend_data AND lesson_location
            $this->modify_suspend_data_for_slide($scorm->id, $sco->id, $USER->id, $this->targetSlide);
            $this->update_lesson_location($scorm->id, $sco->id, $USER->id, $this->targetSlide);
        } else {
            // Normal load: sync lesson_location with suspend_data to prevent stale values
            $this->sync_lesson_location_with_suspend_data($scorm->id, $sco->id, $USER->id);
        }

        // Build SCORM player URL and render iframe wrapper.
        $playerUrl = $CFG->wwwroot . '/mod/scorm/player.php?a=' . $scorm->id . '&scoid=' . $sco->id . '&display=popup';
        return $this->render_scorm_iframe($playerUrl, $scorm->name);
    }

    /**
     * Modify suspend_data in the database to set the target slide position.
     *
     * This function handles both Moodle 4.x (normalized tables) and older Moodle
     * (scorm_scoes_track table) structures.
     *
     * @param int $scormid SCORM instance ID
     * @param int $scoid SCO ID
     * @param int $userid User ID
     * @param int $targetSlide Target slide number (1-indexed)
     */
    private function modify_suspend_data_for_slide(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        debugging("[Embed Renderer] Modifying suspend_data for slide {$targetSlide}", DEBUG_DEVELOPER);

        // Check which table structure exists (Moodle 4.x vs older).
        $dbman = $DB->get_manager();
        $useNormalized = $dbman->table_exists('scorm_scoes_value');

        if ($useNormalized) {
            // Moodle 4.x+ normalized structure: scorm_attempt + scorm_scoes_value + scorm_element
            $this->modify_suspend_data_normalized($scormid, $scoid, $userid, $targetSlide);
        } else {
            // Legacy structure: scorm_scoes_track
            $this->modify_suspend_data_legacy($scormid, $scoid, $userid, $targetSlide);
        }
    }

    /**
     * Modify suspend_data in Moodle 4.x+ normalized tables.
     *
     * Tables: scorm_attempt, scorm_scoes_value, scorm_element
     */
    private function modify_suspend_data_normalized(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        // Get the latest attempt for this user.
        $attemptRecord = $DB->get_record_sql(
            "SELECT id, attempt FROM {scorm_attempt}
             WHERE scormid = :scormid AND userid = :userid
             ORDER BY attempt DESC LIMIT 1",
            ['scormid' => $scormid, 'userid' => $userid]
        );

        if (!$attemptRecord) {
            debugging("[Embed Renderer] No SCORM attempt found for user {$userid}", DEBUG_DEVELOPER);
            return;
        }

        // Get the element ID for cmi.suspend_data.
        $elementRecord = $DB->get_record('scorm_element', ['element' => 'cmi.suspend_data']);
        if (!$elementRecord) {
            debugging("[Embed Renderer] cmi.suspend_data element not found in scorm_element table", DEBUG_DEVELOPER);
            return;
        }

        // Get the current suspend_data value.
        $valueRecord = $DB->get_record('scorm_scoes_value', [
            'attemptid' => $attemptRecord->id,
            'scoid' => $scoid,
            'elementid' => $elementRecord->id,
        ]);

        if (!$valueRecord || empty($valueRecord->value)) {
            debugging("[Embed Renderer] No suspend_data found for attempt {$attemptRecord->id}", DEBUG_DEVELOPER);
            return;
        }

        // Modify the suspend_data using LZ-String helper.
        $modifiedData = lzstring_helper::modifySuspendDataSlide($valueRecord->value, $targetSlide);

        if ($modifiedData !== null && $modifiedData !== $valueRecord->value) {
            // Update the database.
            $valueRecord->value = $modifiedData;
            $valueRecord->timemodified = time();
            $DB->update_record('scorm_scoes_value', $valueRecord);
            debugging("[Embed Renderer] suspend_data modified successfully (normalized tables)", DEBUG_DEVELOPER);
        } else {
            debugging("[Embed Renderer] suspend_data modification failed or no change needed", DEBUG_DEVELOPER);
        }
    }

    /**
     * Modify suspend_data in legacy scorm_scoes_track table.
     *
     * Used in Moodle versions prior to 4.x.
     */
    private function modify_suspend_data_legacy(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        // Get the latest attempt number.
        $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
            'scormid' => $scormid,
            'userid' => $userid,
        ]);

        if (!$attempt) {
            debugging("[Embed Renderer] No SCORM attempt found for user {$userid} (legacy)", DEBUG_DEVELOPER);
            return;
        }

        // Get the current suspend_data record.
        $trackRecord = $DB->get_record('scorm_scoes_track', [
            'scormid' => $scormid,
            'scoid' => $scoid,
            'userid' => $userid,
            'attempt' => $attempt,
            'element' => 'cmi.suspend_data',
        ]);

        if (!$trackRecord || empty($trackRecord->value)) {
            debugging("[Embed Renderer] No suspend_data found for attempt {$attempt} (legacy)", DEBUG_DEVELOPER);
            return;
        }

        // Modify the suspend_data using LZ-String helper.
        $modifiedData = lzstring_helper::modifySuspendDataSlide($trackRecord->value, $targetSlide);

        if ($modifiedData !== null && $modifiedData !== $trackRecord->value) {
            // Update the database.
            $trackRecord->value = $modifiedData;
            $trackRecord->timemodified = time();
            $DB->update_record('scorm_scoes_track', $trackRecord);
            debugging("[Embed Renderer] suspend_data modified successfully (legacy table)", DEBUG_DEVELOPER);
        } else {
            debugging("[Embed Renderer] suspend_data modification failed or no change needed (legacy)", DEBUG_DEVELOPER);
        }
    }

    /**
     * Update lesson_location in the database to match the target slide.
     *
     * Storyline uses lesson_location (not suspend_data) to determine initial position.
     * We must update this value to ensure Storyline loads at the correct slide.
     *
     * @param int $scormid SCORM instance ID
     * @param int $scoid SCO ID
     * @param int $userid User ID
     * @param int $targetSlide Target slide number (1-indexed)
     */
    private function update_lesson_location(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        debugging("[Embed Renderer] Updating lesson_location to slide {$targetSlide}", DEBUG_DEVELOPER);

        // Check which table structure exists (Moodle 4.x vs older).
        $dbman = $DB->get_manager();
        $useNormalized = $dbman->table_exists('scorm_scoes_value');

        if ($useNormalized) {
            $this->update_lesson_location_normalized($scormid, $scoid, $userid, $targetSlide);
        } else {
            $this->update_lesson_location_legacy($scormid, $scoid, $userid, $targetSlide);
        }
    }

    /**
     * Update lesson_location in Moodle 4.x+ normalized tables.
     */
    private function update_lesson_location_normalized(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        // Get the latest attempt.
        $attemptRecord = $DB->get_record_sql(
            "SELECT id, attempt FROM {scorm_attempt}
             WHERE scormid = :scormid AND userid = :userid
             ORDER BY attempt DESC LIMIT 1",
            ['scormid' => $scormid, 'userid' => $userid]
        );

        if (!$attemptRecord) {
            debugging("[Embed Renderer] No SCORM attempt found for lesson_location update", DEBUG_DEVELOPER);
            return;
        }

        // Get the element ID for cmi.core.lesson_location.
        $elementRecord = $DB->get_record('scorm_element', ['element' => 'cmi.core.lesson_location']);
        if (!$elementRecord) {
            debugging("[Embed Renderer] cmi.core.lesson_location element not found", DEBUG_DEVELOPER);
            return;
        }

        // Get current value record.
        $valueRecord = $DB->get_record('scorm_scoes_value', [
            'attemptid' => $attemptRecord->id,
            'scoid' => $scoid,
            'elementid' => $elementRecord->id,
        ]);

        $newValue = (string)$targetSlide;

        if ($valueRecord) {
            // Update existing record.
            $valueRecord->value = $newValue;
            $valueRecord->timemodified = time();
            $DB->update_record('scorm_scoes_value', $valueRecord);
            debugging("[Embed Renderer] lesson_location updated to {$newValue} (normalized)", DEBUG_DEVELOPER);
        } else {
            // Insert new record.
            $newRecord = new \stdClass();
            $newRecord->attemptid = $attemptRecord->id;
            $newRecord->scoid = $scoid;
            $newRecord->elementid = $elementRecord->id;
            $newRecord->value = $newValue;
            $newRecord->timemodified = time();
            $DB->insert_record('scorm_scoes_value', $newRecord);
            debugging("[Embed Renderer] lesson_location created with {$newValue} (normalized)", DEBUG_DEVELOPER);
        }
    }

    /**
     * Update lesson_location in legacy scorm_scoes_track table.
     */
    private function update_lesson_location_legacy(int $scormid, int $scoid, int $userid, int $targetSlide): void {
        global $DB;

        // Get the latest attempt number.
        $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
            'scormid' => $scormid,
            'userid' => $userid,
        ]);

        if (!$attempt) {
            debugging("[Embed Renderer] No SCORM attempt found for lesson_location update (legacy)", DEBUG_DEVELOPER);
            return;
        }

        // Get current lesson_location record.
        $trackRecord = $DB->get_record('scorm_scoes_track', [
            'scormid' => $scormid,
            'scoid' => $scoid,
            'userid' => $userid,
            'attempt' => $attempt,
            'element' => 'cmi.core.lesson_location',
        ]);

        $newValue = (string)$targetSlide;

        if ($trackRecord) {
            // Update existing record.
            $trackRecord->value = $newValue;
            $trackRecord->timemodified = time();
            $DB->update_record('scorm_scoes_track', $trackRecord);
            debugging("[Embed Renderer] lesson_location updated to {$newValue} (legacy)", DEBUG_DEVELOPER);
        } else {
            // Insert new record.
            $newRecord = new \stdClass();
            $newRecord->scormid = $scormid;
            $newRecord->scoid = $scoid;
            $newRecord->userid = $userid;
            $newRecord->attempt = $attempt;
            $newRecord->element = 'cmi.core.lesson_location';
            $newRecord->value = $newValue;
            $newRecord->timemodified = time();
            $DB->insert_record('scorm_scoes_track', $newRecord);
            debugging("[Embed Renderer] lesson_location created with {$newValue} (legacy)", DEBUG_DEVELOPER);
        }
    }

    /**
     * Sync lesson_location with suspend_data on normal loads.
     *
     * This prevents stale lesson_location values from causing Storyline to load
     * at incorrect positions. The suspend_data contains the true resume position.
     *
     * @param int $scormid SCORM instance ID
     * @param int $scoid SCO ID
     * @param int $userid User ID
     */
    private function sync_lesson_location_with_suspend_data(int $scormid, int $scoid, int $userid): void {
        global $DB;

        debugging("[Embed Renderer] Syncing lesson_location with suspend_data", DEBUG_DEVELOPER);

        // Check which table structure exists.
        $dbman = $DB->get_manager();
        $useNormalized = $dbman->table_exists('scorm_scoes_value');

        if ($useNormalized) {
            $this->sync_lesson_location_normalized($scormid, $scoid, $userid);
        } else {
            $this->sync_lesson_location_legacy($scormid, $scoid, $userid);
        }
    }

    /**
     * Sync lesson_location with suspend_data in Moodle 4.x+ normalized tables.
     */
    private function sync_lesson_location_normalized(int $scormid, int $scoid, int $userid): void {
        global $DB;

        // Get the latest attempt.
        $attemptRecord = $DB->get_record_sql(
            "SELECT id, attempt FROM {scorm_attempt}
             WHERE scormid = :scormid AND userid = :userid
             ORDER BY attempt DESC LIMIT 1",
            ['scormid' => $scormid, 'userid' => $userid]
        );

        if (!$attemptRecord) {
            return;
        }

        // Get suspend_data element and value.
        $suspendElement = $DB->get_record('scorm_element', ['element' => 'cmi.suspend_data']);
        if (!$suspendElement) {
            return;
        }

        $suspendValue = $DB->get_record('scorm_scoes_value', [
            'attemptid' => $attemptRecord->id,
            'scoid' => $scoid,
            'elementid' => $suspendElement->id,
        ]);

        if (!$suspendValue || empty($suspendValue->value)) {
            return;
        }

        // Parse slide from suspend_data.
        $slideFromSuspendData = lzstring_helper::getSlideFromSuspendData($suspendValue->value);
        if ($slideFromSuspendData === null || $slideFromSuspendData < 1) {
            return;
        }

        // Get lesson_location element and current value.
        $locationElement = $DB->get_record('scorm_element', ['element' => 'cmi.core.lesson_location']);
        if (!$locationElement) {
            return;
        }

        $locationValue = $DB->get_record('scorm_scoes_value', [
            'attemptid' => $attemptRecord->id,
            'scoid' => $scoid,
            'elementid' => $locationElement->id,
        ]);

        $currentLocation = $locationValue ? (int)$locationValue->value : 0;

        // Only sync if they differ.
        if ($currentLocation !== $slideFromSuspendData) {
            debugging("[Embed Renderer] Syncing lesson_location: {$currentLocation} -> {$slideFromSuspendData} (normalized)", DEBUG_DEVELOPER);
            $this->update_lesson_location_normalized($scormid, $scoid, $userid, $slideFromSuspendData);
        }
    }

    /**
     * Sync lesson_location with suspend_data in legacy tables.
     */
    private function sync_lesson_location_legacy(int $scormid, int $scoid, int $userid): void {
        global $DB;

        // Get the latest attempt number.
        $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
            'scormid' => $scormid,
            'userid' => $userid,
        ]);

        if (!$attempt) {
            return;
        }

        // Get suspend_data.
        $suspendRecord = $DB->get_record('scorm_scoes_track', [
            'scormid' => $scormid,
            'scoid' => $scoid,
            'userid' => $userid,
            'attempt' => $attempt,
            'element' => 'cmi.suspend_data',
        ]);

        if (!$suspendRecord || empty($suspendRecord->value)) {
            return;
        }

        // Parse slide from suspend_data.
        $slideFromSuspendData = lzstring_helper::getSlideFromSuspendData($suspendRecord->value);
        if ($slideFromSuspendData === null || $slideFromSuspendData < 1) {
            return;
        }

        // Get current lesson_location.
        $locationRecord = $DB->get_record('scorm_scoes_track', [
            'scormid' => $scormid,
            'scoid' => $scoid,
            'userid' => $userid,
            'attempt' => $attempt,
            'element' => 'cmi.core.lesson_location',
        ]);

        $currentLocation = $locationRecord ? (int)$locationRecord->value : 0;

        // Only sync if they differ.
        if ($currentLocation !== $slideFromSuspendData) {
            debugging("[Embed Renderer] Syncing lesson_location: {$currentLocation} -> {$slideFromSuspendData} (legacy)", DEBUG_DEVELOPER);
            $this->update_lesson_location_legacy($scormid, $scoid, $userid, $slideFromSuspendData);
        }
    }

    /**
     * Render SCORM player in iframe with CSS injection to hide navigation.
     *
     * @param string $playerUrl SCORM player URL
     * @param string $title Activity title
     * @return string HTML content
     */
    private function render_scorm_iframe(string $playerUrl, string $title): string {
        global $CFG;

        // Generate JavaScript to manage sessionStorage for target slide navigation BEFORE iframe loads.
        // IMPORTANT: Always clear any existing entry first to prevent stale navigation on page reload.
        $cmid = (int)$this->cm->id;
        $slideSetupScript = "
    // CRITICAL: Set 'navigation starting' signal IMMEDIATELY
    // This runs as soon as embed.php loads, BEFORE the iframe even starts loading
    // OLD iframes will check this and stop intercepting if they see a newer timestamp
    (function() {
        var startingTimestamp = Date.now();
        sessionStorage.setItem('scorm_navigation_starting_{$cmid}', JSON.stringify({
            timestamp: startingTimestamp,
            targetSlide: " . ($this->targetSlide !== null ? (int)$this->targetSlide : 'null') . "
        }));
        console.log('[Embed Renderer] Set navigation starting signal:', startingTimestamp);
    })();

    // Clear any stale navigation data first (prevents redirect on page reload)
    (function() {
        sessionStorage.removeItem('scorm_pending_navigation_{$cmid}');
    })();
";
        if ($this->targetSlide !== null) {
            $slide = (int)$this->targetSlide;
            // Include furthestSlide if provided (for progress preservation across origins)
            $furthestJs = $this->furthestSlide !== null ? (int)$this->furthestSlide : 'null';
            $slideSetupScript .= "
    // Set up sessionStorage for direct slide navigation BEFORE iframe loads
    // CRITICAL: Set BOTH pending and current navigation immediately!
    // This ensures OLD iframes see the new navId ASAP and stop intercepting.
    // Previously, only pending was set here, and current was set by player.php.
    // This caused a race condition where OLD iframe would write before NEW current was set.
    (function() {
        var timestamp = Date.now();
        var navId = timestamp + '_' + Math.random().toString(36).substr(2, 9);
        var navData = {
            slide: {$slide},
            cmid: {$cmid},
            furthest: {$furthestJs},
            timestamp: timestamp,
            navId: navId,
            version: 5  // v5: navId generated in embed_renderer to prevent race condition
        };
        // Set pending navigation (for player.php to read)
        sessionStorage.setItem('scorm_pending_navigation_{$cmid}', JSON.stringify(navData));
        // IMMEDIATELY set current navigation (so OLD iframes see it and stop intercepting)
        sessionStorage.setItem('scorm_current_navigation_{$cmid}', JSON.stringify({
            slide: {$slide},
            navId: navId,
            timestamp: timestamp
        }));
        console.log('[Embed Renderer] Set pending navigation:', navData);
        console.log('[Embed Renderer] Set current navigation (superseding old iframes):', navId);
    })();
";
        }

        $html = '<!DOCTYPE html>
<html lang="' . current_language() . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . s($title) . '</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        #scorm-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
    </style>
    <script>' . $slideSetupScript . '</script>
</head>
<body>
    <iframe id="scorm-frame" src="' . s($playerUrl) . '" allowfullscreen></iframe>
    <script>
    (function() {
        var iframe = document.getElementById("scorm-frame");

        function hideNavigation() {
            try {
                var doc = iframe.contentDocument || iframe.contentWindow.document;
                if (!doc || !doc.body) return;

                // Inject CSS - only hide nav elements, NOT toctree or tocbox (they contain content)
                var styleId = "sm-embed-css";
                if (!doc.getElementById(styleId)) {
                    var style = doc.createElement("style");
                    style.id = styleId;
                    style.textContent = [
                        "/* Hide only navigation, not content containers */",
                        "#scormtop { display: none !important; }",
                        "#scormnav { display: none !important; }",
                        ".scorm-right { display: none !important; }",
                        "#scorm_toc { display: none !important; }",
                        "#scorm_toc_toggle { display: none !important; }",
                        "#scorm_toc_toggle_btn { display: none !important; }",
                        "#scorm_navpanel { display: none !important; }",
                        ".toast-wrapper { display: none !important; }",
                        "/* Make content area full width */",
                        "#scorm_layout { width: 100% !important; }",
                        "#scorm_layout > .yui3-u-1-5 { display: none !important; width: 0 !important; }",
                        "#scorm_content { width: 100% !important; left: 0 !important; margin: 0 !important; }",
                        "/* Full viewport */",
                        "body, #page, .embedded-main, #scormpage, #tocbox, #toctree { margin: 0 !important; padding: 0 !important; width: 100% !important; height: 100% !important; }",
                        "body { overflow: hidden !important; }"
                    ].join("\\n");
                    doc.head.appendChild(style);
                }

                // Direct hide
                ["scormtop", "scormnav", "scorm_toc", "scorm_toc_toggle", "scorm_toc_toggle_btn", "scorm_navpanel"].forEach(function(id) {
                    var el = doc.getElementById(id);
                    if (el) el.style.display = "none";
                });

            } catch (e) {
                // Cross-origin - ignore
            }
        }

        iframe.onload = function() {
            hideNavigation();
            setTimeout(hideNavigation, 300);
            setTimeout(hideNavigation, 1000);
            setTimeout(hideNavigation, 2000);
        };

        // Forward navigation messages from parent (ActivityEmbed) to inner iframe (player.php)
        window.addEventListener("message", function(event) {
            console.log("[Embed Renderer] Message received:", event.data?.type, event.origin);

            // Forward SCORM navigation requests to inner iframe
            if (event.data && event.data.type === "scorm-navigate-to-slide") {
                console.log("[Embed Renderer] Forwarding navigation to player.php iframe");
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.postMessage(event.data, "*");
                }
            }

            // Also forward any messages from player.php back to parent
            if (event.source === iframe.contentWindow && event.data) {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(event.data, "*");
                }
            }
        }, false);
    })();
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * Render Lesson in an iframe with position tracking.
     *
     * Wraps the lesson view in an iframe and injects JavaScript to:
     * 1. Track the current page position
     * 2. Send position updates via PostMessage to the parent (SmartLearning)
     *
     * @return string HTML content
     */
    private function render_lesson_iframe(): string {
        global $DB, $CFG;

        $lesson = $DB->get_record('lesson', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get lesson pages (content pages only, exclude navigation pages)
        // qtype: 20 = content page, 1-10 = question types, 21 = end of branch, 30 = cluster, 31 = end of cluster
        // Note: lesson_pages uses prevpageid/nextpageid for ordering, not an ordering column
        $pages = $DB->get_records_sql(
            "SELECT id, title, qtype FROM {lesson_pages}
             WHERE lessonid = :lessonid AND qtype NOT IN (21, 30, 31)
             ORDER BY id ASC",
            ['lessonid' => (int)$this->cm->instance]
        );
        $pages = array_values($pages);
        $totalPages = count($pages);

        // Build initial URL (with position if specified)
        $url = $CFG->wwwroot . '/mod/lesson/view.php?id=' . $this->cm->id;

        if ($this->targetSlide !== null && $this->targetSlide > 0) {
            $positionUrl = $this->get_lesson_position_url($this->targetSlide);
            if ($positionUrl) {
                $url = $positionUrl;
            }
        }

        $cmid = (int)$this->cm->id;

        $html = '<!DOCTYPE html>
<html lang="' . current_language() . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . s($lesson->name) . '</title>
    <style>
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        #lesson-frame {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
    </style>
</head>
<body>
    <iframe id="lesson-frame" src="' . s($url) . '" allowfullscreen></iframe>
    <script>
    (function() {
        var iframe = document.getElementById("lesson-frame");
        var cmid = ' . $cmid . ';
        var totalPages = ' . $totalPages . ';
        var pageIdToIndex = ' . json_encode(array_combine(array_column($pages, 'id'), array_keys($pages))) . ';
        var lastSentPosition = null;

        // Storage keys for position tracking
        var storageKey = "activity_furthest_position_" + cmid;
        var currentPosKey = "activity_current_position_" + cmid;

        // Get last known position from storage
        var lastKnownPosition = 1;
        var furthestPosition = 1;
        try {
            lastKnownPosition = parseInt(sessionStorage.getItem(currentPosKey)) || 1;
            furthestPosition = parseInt(sessionStorage.getItem(storageKey)) || 1;
        } catch (e) {}

        // Send position update to parent (SmartLearning)
        function sendPositionUpdate(currentPage, source) {
            if (currentPage === lastSentPosition) return;
            lastSentPosition = currentPage;

            // Update furthest if needed
            if (currentPage > furthestPosition) {
                furthestPosition = currentPage;
                try {
                    sessionStorage.setItem(storageKey, String(furthestPosition));
                    localStorage.setItem(storageKey, String(furthestPosition));
                } catch (e) {}
            }

            var progressPercent = totalPages > 0 ? Math.round((furthestPosition / totalPages) * 100) : 0;
            var currentPercent = totalPages > 0 ? Math.round((currentPage / totalPages) * 100) : 0;
            var message = {
                type: "activity-progress",
                activityType: "lesson",
                cmid: cmid,
                currentPosition: currentPage,
                totalPositions: totalPages,
                furthestPosition: furthestPosition,
                progressPercent: progressPercent,
                currentPercent: currentPercent,
                status: furthestPosition >= totalPages ? "completed" : "incomplete",
                source: source || "lesson-tracker",
                timestamp: Date.now()
            };

            // Send to parent window (SmartLearning iframe container)
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(message, "*");
                console.log("[Lesson Embed] Sent position:", currentPage, "/", totalPages, "furthest:", furthestPosition);
            }
        }

        // Extract page ID from URL
        function getPageIdFromUrl(url) {
            var match = url.match(/[?&]pageid=(\d+)/);
            return match ? parseInt(match[1], 10) : null;
        }

        // Track page changes by monitoring iframe URL
        function checkPosition() {
            try {
                var iframeUrl = iframe.contentWindow.location.href;
                var pageId = getPageIdFromUrl(iframeUrl);

                if (pageId !== null && pageIdToIndex[pageId] !== undefined) {
                    // Convert 0-indexed to 1-indexed - page is in our map
                    var currentPage = pageIdToIndex[pageId] + 1;
                    // Save as last known position
                    try {
                        sessionStorage.setItem(currentPosKey, String(currentPage));
                    } catch (e) {}
                    lastKnownPosition = currentPage;
                    sendPositionUpdate(currentPage, "url-tracker");
                }
                // If page not in map (answer/feedback page), do not update position
                // This keeps the counter at the last question position
            } catch (e) {
                // Cross-origin or other error - ignore
            }
        }

        // Monitor iframe load events
        iframe.onload = function() {
            checkPosition();

            // Inject a mutation observer into the iframe to catch AJAX navigation
            try {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                if (iframeDoc) {
                    // Check position after any page changes
                    var observer = new MutationObserver(function() {
                        setTimeout(checkPosition, 100);
                    });
                    observer.observe(iframeDoc.body, { childList: true, subtree: true });

                    // Also inject CSS to hide Moodle navigation
                    var style = iframeDoc.createElement("style");
                    style.id = "sm-lesson-embed-css";
                    style.textContent = [
                        "/* Hide Moodle navigation for clean embed */",
                        "#page-header { display: none !important; }",
                        ".navbar { display: none !important; }",
                        "#nav-drawer { display: none !important; }",
                        ".drawer-toggler { display: none !important; }",
                        "#page-footer { display: none !important; }",
                        "footer { display: none !important; }",
                        ".secondary-navigation { display: none !important; }",
                        "#page.drawers { padding-left: 0 !important; }",
                        "#page-content { padding: 1rem !important; }"
                    ].join("\\n");
                    iframeDoc.head.appendChild(style);
                }
            } catch (e) {
                // Cross-origin
            }

            // Poll for position changes (backup for AJAX navigation)
            setInterval(checkPosition, 1000);
        };

        // Initial position message (whole activity if no pages detected yet)
        if (totalPages > 0) {
            sendPositionUpdate(1, "initial");
        }
    })();
    </script>
</body>
</html>';

        return $html;
    }

    /**
     * Get JavaScript code to inject CSS into the SCORM player iframe.
     *
     * @return string JavaScript code
     */
    private function get_iframe_css_injection_script(): string {
        return <<<'JS'
(function() {
    var iframe = document.getElementById('scorm-frame');

    function injectCSS() {
        try {
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;

            // Check if we can access the iframe (same-origin)
            if (!iframeDoc) {
                console.log('SM Embed: Cannot access iframe document');
                return;
            }

            console.log('SM Embed: Injecting CSS into iframe');

            // Create style element
            var style = iframeDoc.createElement('style');
            style.id = 'sm-embed-styles';
            style.type = 'text/css';
            style.innerHTML = `
                /* Hide SCORM navigation elements */
                #scormtop { display: none !important; }
                #scormnav { display: none !important; }
                .scorm-right { display: none !important; }
                #scorm_toc { display: none !important; width: 0 !important; }
                #scorm_toc_toggle { display: none !important; }
                #scorm_toc_toggle_btn { display: none !important; }
                #scorm_navpanel { display: none !important; }
                .toast-wrapper { display: none !important; }

                /* Hide the left column in the layout */
                #scorm_layout > .yui3-u-1-5 { display: none !important; width: 0 !important; }

                /* Make the content area take full width */
                #scorm_content {
                    width: 100% !important;
                    left: 0 !important;
                    margin-left: 0 !important;
                }

                /* Ensure scormpage fills the viewport */
                #scormpage {
                    width: 100% !important;
                    height: 100% !important;
                }

                #page, .embedded-main {
                    padding: 0 !important;
                    margin: 0 !important;
                    width: 100% !important;
                    height: 100% !important;
                }

                body {
                    margin: 0 !important;
                    padding: 0 !important;
                    overflow: auto !important;
                }

                /* Hide tocbox but keep scormpage visible */
                #tocbox > *:not(#toctree) { display: none !important; }
                #toctree > *:not(#scorm_layout) { display: none !important; }
            `;

            // Remove old style if exists
            var oldStyle = iframeDoc.getElementById('sm-embed-styles');
            if (oldStyle) oldStyle.remove();

            iframeDoc.head.appendChild(style);

            // Hide specific elements directly
            var hideIds = ['scormtop', 'scormnav', 'scorm_toc', 'scorm_toc_toggle', 'scorm_toc_toggle_btn', 'scorm_navpanel'];
            hideIds.forEach(function(id) {
                var el = iframeDoc.getElementById(id);
                if (el) {
                    el.style.display = 'none';
                    console.log('SM Embed: Hidden element #' + id);
                }
            });

            // Hide .scorm-right elements
            var scormRights = iframeDoc.querySelectorAll('.scorm-right');
            scormRights.forEach(function(el) {
                el.style.display = 'none';
            });

            console.log('SM Embed: CSS injection complete');

        } catch (e) {
            // Cross-origin error - can't inject CSS
            console.log('SM Embed: Could not inject CSS into iframe', e);
        }
    }

    // Inject on load and also after delays (for dynamic content)
    iframe.onload = function() {
        console.log('SM Embed: iframe loaded');
        injectCSS();
        setTimeout(injectCSS, 500);
        setTimeout(injectCSS, 1000);
        setTimeout(injectCSS, 2000);
    };
})();
JS;
    }

    /**
     * Trigger module viewed event for completion tracking.
     */
    private function trigger_module_viewed(): void {
        global $USER, $DB;

        // Get course.
        $course = $DB->get_record('course', ['id' => $this->cm->course], '*', MUST_EXIST);

        // Create viewed event based on activity type.
        $eventclass = '\\mod_' . $this->activityType . '\\event\\course_module_viewed';
        if (class_exists($eventclass)) {
            $event = $eventclass::create([
                'objectid' => $this->cm->instance,
                'context' => $this->context,
            ]);
            $event->add_record_snapshot('course', $course);
            $event->trigger();
        }

        // Update completion state.
        $completion = new \completion_info($course);
        if ($completion->is_enabled($this->cm) && $this->cm->completion == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($this->cm, COMPLETION_COMPLETE);
        }
    }

    /**
     * Render Page content directly.
     *
     * @return string HTML content
     */
    private function render_page(): string {
        global $DB;

        $page = $DB->get_record('page', ['id' => $this->cm->instance], '*', MUST_EXIST);

        $content = file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $this->context->id,
            'mod_page',
            'content',
            0
        );
        $content = format_text($content, $page->contentformat, ['context' => $this->context]);

        $html = '<div class="embed-page-container" style="padding:20px;">';
        $html .= '<h1>' . format_string($page->name) . '</h1>';
        $html .= '<div class="page-content">' . $content . '</div>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Resource (file download/view).
     *
     * @return string HTML content
     */
    private function render_resource(): string {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/resource/locallib.php');

        $resource = $DB->get_record('resource', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get the file.
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);

        if (empty($files)) {
            return $this->render_error('No file found in this resource.');
        }

        $file = reset($files);
        $fileUrl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );

        $mimetype = $file->get_mimetype();

        // Handle based on file type.
        if (strpos($mimetype, 'pdf') !== false) {
            $html = '<div class="embed-resource-container" style="width:100%;height:100vh;">';
            $html .= '<iframe src="' . $fileUrl->out(false) . '" ';
            $html .= 'style="width:100%;height:100%;border:none;">';
            $html .= '</iframe>';
            $html .= '</div>';
        } elseif (strpos($mimetype, 'image') !== false) {
            $html = '<div class="embed-resource-container" style="text-align:center;padding:20px;">';
            $html .= '<img src="' . $fileUrl->out(false) . '" style="max-width:100%;max-height:90vh;" />';
            $html .= '</div>';
        } else {
            // Download link for other types.
            $html = '<div class="embed-resource-container" style="padding:20px;text-align:center;">';
            $html .= '<h2>' . format_string($resource->name) . '</h2>';
            $html .= '<p><a href="' . $fileUrl->out(false) . '" class="btn btn-primary" download>';
            $html .= get_string('download') . '</a></p>';
            $html .= '</div>';
        }

        return $this->wrap_content($html);
    }

    /**
     * Render URL module.
     *
     * @return string HTML content
     */
    private function render_url(): string {
        global $CFG, $DB;

        require_once($CFG->libdir . '/resourcelib.php');

        $url = $DB->get_record('url', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Check display mode.
        if ($url->display == \RESOURCELIB_DISPLAY_EMBED) {
            $html = '<div class="embed-url-container" style="width:100%;height:100vh;">';
            $html .= '<iframe src="' . $url->externalurl . '" ';
            $html .= 'style="width:100%;height:100%;border:none;" ';
            $html .= 'allowfullscreen>';
            $html .= '</iframe>';
            $html .= '</div>';
        } else {
            // Show link.
            $html = '<div class="embed-url-container" style="padding:20px;text-align:center;">';
            $html .= '<h2>' . format_string($url->name) . '</h2>';
            $html .= '<p><a href="' . $url->externalurl . '" target="_blank" class="btn btn-primary">';
            $html .= get_string('clicktoopen', 'url') . '</a></p>';
            $html .= '</div>';
        }

        return $this->wrap_content($html);
    }

    /**
     * Render error message.
     *
     * @param string $message Error message
     * @return string HTML content
     */
    private function render_error(string $message): string {
        $html = '<div class="embed-error-container" style="padding:40px;text-align:center;">';
        $html .= '<div class="alert alert-danger">' . s($message) . '</div>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Wrap content with minimal HTML structure.
     *
     * For embed mode, we intentionally skip Moodle's get_head_code() and get_end_code()
     * because they include JavaScript that expects the full Moodle JS framework to be
     * loaded. Instead, we only include the theme CSS and a minimal M.cfg object.
     *
     * @param string $content Inner content
     * @return string Complete HTML document
     */
    private function wrap_content(string $content): string {
        global $CFG, $PAGE;

        // Build the theme CSS URL directly - skip get_head_code() to avoid JS conflicts.
        $themename = $PAGE->theme->name ?? 'boost';
        $themerev = theme_get_revision();
        $cssurl = $CFG->wwwroot . '/theme/styles.php/' . $themename . '/' . $themerev . '/all';

        $html = '<!DOCTYPE html>';
        $html .= '<html lang="' . current_language() . '">';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . format_string($this->cm->name) . '</title>';

        // Include only theme CSS - no Moodle JavaScript framework.
        $html .= '<link rel="stylesheet" href="' . s($cssurl) . '" />';

        // Minimal M.cfg for any scripts that might check for it.
        $html .= '<script>';
        $html .= 'window.M = window.M || {};';
        $html .= 'M.cfg = {';
        $html .= 'wwwroot: ' . json_encode($CFG->wwwroot) . ',';
        $html .= 'sesskey: ' . json_encode(sesskey()) . ',';
        $html .= 'themerev: ' . json_encode($themerev) . ',';
        $html .= 'slasharguments: ' . json_encode($CFG->slasharguments ?? 1) . ',';
        $html .= 'theme: ' . json_encode($themename) . ',';
        $html .= 'jsrev: ' . json_encode($CFG->jsrev ?? -1) . ',';
        $html .= 'svgicons: true,';
        $html .= 'developerdebug: false,';
        $html .= 'loadingicon: ' . json_encode($CFG->wwwroot . '/pix/i/loading_small.gif') . ',';
        $html .= 'js_pending: []';
        $html .= '};';
        $html .= 'M.util = {';
        $html .= 'pending_js: [],';
        $html .= 'js_pending: function() { return 0; },';
        $html .= 'js_complete: function() {},';
        $html .= 'image_url: function(name, component) { return M.cfg.wwwroot + "/pix/" + name + ".svg"; }';
        $html .= '};';
        $html .= 'M.str = M.str || {};';
        $html .= 'M.yui = M.yui || {};';
        $html .= '</script>';

        $html .= '<style>';
        $html .= 'html, body { margin: 0; padding: 0; height: 100%; }';
        $html .= 'body { overflow-y: auto; overflow-x: hidden; }';
        $html .= '.embed-container { width: 100%; min-height: 100%; padding: 0; }';
        $html .= '.embed-page-container { max-width: 900px; margin: 0 auto; }';
        $html .= '.embed-page-container img { max-width: 100%; height: auto; }';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body class="embed-mode">';
        $html .= '<main class="embed-container">';
        $html .= $content;
        $html .= '</main>';
        $html .= '</body>';
        $html .= '</html>';

        return $html;
    }
}
