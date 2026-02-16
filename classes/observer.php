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

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for cache invalidation and real-time progress tracking.
 *
 * Captures Moodle events for:
 * 1. Cache invalidation (local)
 * 2. Real-time progress updates to SmartLearning backend (WebSocket)
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    // =========================================================================
    // SmartLearning WebSocket Progress Tracking
    // =========================================================================

    /**
     * SmartLearning backend URL (reuses OAuth2 issuer URL from settings)
     */
    private static function get_backend_url(): string {
        return util::get_env_config('oauth2_issuer_url', 'https://api-inbox.smartlxp.com');
    }

    /**
     * Send progress event to SmartLearning backend for WebSocket broadcast.
     * Uses the same OAuth2 issuer URL configured for embed authentication.
     *
     * @param array $data Progress data to send.
     */
    private static function send_progress_event(array $data): void {
        global $CFG;

        $backendurl = self::get_backend_url();
        if (empty($backendurl)) {
            // Backend URL not configured, skip sending progress events.
            return;
        }

        $url = $backendurl . '/api/moodle/progress-event';

        // Add Moodle URL to identify the source.
        $data['moodle_url'] = $CFG->wwwroot;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Moodle-Source: ' . $CFG->wwwroot,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5, // 5 second timeout.
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200 && $httpcode !== 202) {
            debugging("[SM_Estratoos] Progress event failed: HTTP $httpcode - $response", DEBUG_DEVELOPER);
        }
    }

    /**
     * Get activity progress data for a course module.
     *
     * @param int $cmid Course module ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_progress_data(int $cmid, int $userid): array {
        global $DB;

        // Get course module info.
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.instance, cm.course, cm.completion, m.name as modname
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module
             WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );

        if (!$cm) {
            return [];
        }

        // Get completion status.
        $completion = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
        ]);

        $data = [
            'cmid' => (int)$cmid,
            'courseid' => (int)$cm->course,
            'modtype' => $cm->modname,
            'userid' => $userid,
            'completionstate' => $completion ? (int)$completion->completionstate : 0,
            'timemodified' => time(),
        ];

        // Get module-specific progress.
        switch ($cm->modname) {
            case 'scorm':
                $data = array_merge($data, self::get_scorm_progress($cm->instance, $userid));
                break;
            case 'quiz':
                $data = array_merge($data, self::get_quiz_progress($cm->instance, $userid));
                break;
            case 'book':
                $data = array_merge($data, self::get_book_progress($cm->instance, $userid));
                break;
            case 'lesson':
                $data = array_merge($data, self::get_lesson_progress($cm->instance, $userid));
                break;
            case 'assign':
                $data = array_merge($data, self::get_assign_progress($cm->instance, $userid));
                break;
            default:
                // Simple activities (page, resource, url).
                $data['progresspercent'] = $completion && $completion->completionstate > 0 ? 100 : 0;
                $data['totalitems'] = 1;
                $data['completeditems'] = $completion && $completion->completionstate > 0 ? 1 : 0;
        }

        return $data;
    }

    /**
     * Get SCORM progress data.
     *
     * @param int $scormid SCORM instance ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_scorm_progress(int $scormid, int $userid): array {
        global $DB;

        $progress = [
            'totalitems' => 0,
            'completeditems' => 0,
            'currentitem' => null,
            'score' => null,
            'maxscore' => 100,
            'progresspercent' => 0,
        ];

        $scorm = $DB->get_record('scorm', ['id' => $scormid]);
        if (!$scorm) {
            return $progress;
        }

        $progress['maxscore'] = (float)$scorm->maxgrade;

        // Count SCOs.
        $scocount = $DB->count_records('scorm_scoes', ['scorm' => $scormid, 'scormtype' => 'sco']);
        $progress['totalitems'] = $scocount ?: 1;

        // Get latest attempt.
        try {
            $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
                'scormid' => $scormid,
                'userid' => $userid,
            ]);

            if ($attempt) {
                // Get score.
                $score = $DB->get_field('scorm_scoes_track', 'value', [
                    'scormid' => $scormid,
                    'userid' => $userid,
                    'attempt' => $attempt,
                    'element' => 'cmi.core.score.raw',
                ]);
                if ($score !== false) {
                    $progress['score'] = (float)$score;
                    // Calculate current slide from score percentage.
                    if ($progress['totalitems'] > 0 && $progress['maxscore'] > 0) {
                        $percent = ($progress['score'] / $progress['maxscore']) * 100;
                        $progress['currentitem'] = (int)round(($percent / 100) * $progress['totalitems']);
                        $progress['completeditems'] = $progress['currentitem'];
                        $progress['progresspercent'] = round($percent, 1);
                    }
                }

                // Count completed SCOs.
                $completedcount = $DB->count_records_select('scorm_scoes_track',
                    "scormid = :scormid AND userid = :userid AND attempt = :attempt
                     AND element IN ('cmi.core.lesson_status', 'cmi.completion_status')
                     AND value IN ('completed', 'passed')",
                    ['scormid' => $scormid, 'userid' => $userid, 'attempt' => $attempt]
                );
                if ($completedcount > 0 && $progress['completeditems'] == 0) {
                    $progress['completeditems'] = $completedcount;
                    $progress['progresspercent'] = round(($completedcount / $progress['totalitems']) * 100, 1);
                }
            }
        } catch (\Exception $e) {
            // scorm_scoes_track table may not exist.
        }

        return $progress;
    }

    /**
     * Get Quiz progress data.
     *
     * @param int $quizid Quiz instance ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_quiz_progress(int $quizid, int $userid): array {
        global $DB;

        $progress = [
            'totalitems' => 0,
            'completeditems' => 0,
            'score' => null,
            'maxscore' => null,
            'progresspercent' => 0,
        ];

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return $progress;
        }

        $progress['maxscore'] = (float)$quiz->grade;

        // Count questions.
        $questioncount = $DB->count_records('quiz_slots', ['quizid' => $quizid]);
        $progress['totalitems'] = $questioncount ?: 1;

        // Get best attempt.
        $attempts = $DB->get_records('quiz_attempts', [
            'quiz' => $quizid,
            'userid' => $userid,
            'state' => 'finished',
        ], 'sumgrades DESC', 'id, sumgrades', 0, 1);

        if (!empty($attempts)) {
            $best = reset($attempts);
            $progress['score'] = (float)$best->sumgrades;
            $progress['completeditems'] = $questioncount;
            $progress['progresspercent'] = 100; // Quiz completed.
        }

        return $progress;
    }

    /**
     * Get Book progress data.
     *
     * @param int $bookid Book instance ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_book_progress(int $bookid, int $userid): array {
        global $DB;

        $progress = [
            'totalitems' => 0,
            'completeditems' => 0,
            'currentitem' => null,
            'progresspercent' => 0,
        ];

        // Count chapters.
        $chaptercount = $DB->count_records('book_chapters', ['bookid' => $bookid, 'hidden' => 0]);
        $progress['totalitems'] = $chaptercount ?: 1;

        // Get viewed chapters from log.
        try {
            $viewed = $DB->get_records_sql(
                "SELECT DISTINCT objectid
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
            $progress['completeditems'] = count($viewed);
            if ($chaptercount > 0) {
                $progress['progresspercent'] = round(($progress['completeditems'] / $chaptercount) * 100, 1);
            }
        } catch (\Exception $e) {
            // Log table may not exist.
        }

        return $progress;
    }

    /**
     * Get Lesson progress data.
     *
     * @param int $lessonid Lesson instance ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_lesson_progress(int $lessonid, int $userid): array {
        global $DB;

        $progress = [
            'totalitems' => 0,
            'completeditems' => 0,
            'currentitem' => null,
            'progresspercent' => 0,
        ];

        // Count pages.
        $pagecount = $DB->count_records('lesson_pages', ['lessonid' => $lessonid]);
        $progress['totalitems'] = $pagecount ?: 1;

        // Get viewed pages.
        try {
            $viewedpages = $DB->get_records('lesson_branch', [
                'lessonid' => $lessonid,
                'userid' => $userid,
            ]);
            $uniquepages = [];
            foreach ($viewedpages as $page) {
                $uniquepages[$page->pageid] = true;
            }
            $progress['completeditems'] = count($uniquepages);

            if (!empty($viewedpages)) {
                $last = end($viewedpages);
                $progress['currentitem'] = (int)$last->pageid;
            }

            if ($pagecount > 0) {
                $progress['progresspercent'] = round((count($uniquepages) / $pagecount) * 100, 1);
            }
        } catch (\Exception $e) {
            // Table may not exist.
        }

        return $progress;
    }

    /**
     * Get Assignment progress data.
     *
     * @param int $assignid Assignment instance ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_assign_progress(int $assignid, int $userid): array {
        global $DB;

        $progress = [
            'totalitems' => 1,
            'completeditems' => 0,
            'submitted' => false,
            'graded' => false,
            'score' => null,
            'maxscore' => null,
            'progresspercent' => 0,
        ];

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if ($assign) {
            $progress['maxscore'] = (float)$assign->grade;
        }

        // Check submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assignid,
            'userid' => $userid,
            'latest' => 1,
        ]);

        if ($submission && $submission->status === 'submitted') {
            $progress['submitted'] = true;
            $progress['completeditems'] = 1;
            $progress['progresspercent'] = 100;
        }

        // Check grade.
        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assignid,
            'userid' => $userid,
        ], '*', IGNORE_MULTIPLE);

        if ($grade && $grade->grade >= 0) {
            $progress['graded'] = true;
            $progress['score'] = (float)$grade->grade;
        }

        return $progress;
    }

    // =========================================================================
    // Cache Invalidation Handlers
    // =========================================================================

    /**
     * User enrolled in course - invalidate caches.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event): void {
        $userid = $event->relateduserid;
        cache_helper::invalidate_user_dashboard($userid);
        cache_helper::invalidate_course_progress($event->courseid);
        cache_helper::invalidate_health_summary($userid);
    }

    /**
     * User unenrolled from course - invalidate caches.
     *
     * @param \core\event\user_enrolment_deleted $event
     */
    public static function user_enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        $userid = $event->relateduserid;
        cache_helper::invalidate_user_dashboard($userid);
        cache_helper::invalidate_course_progress($event->courseid);
        cache_helper::invalidate_health_summary($userid);
    }

    /**
     * Course module completion updated.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function course_module_completion_updated(\core\event\course_module_completion_updated $event): void {
        $data = $event->get_data();
        $userid = $event->relateduserid;
        $courseid = $event->courseid;
        $cmid = $data['contextinstanceid'];

        // Cache invalidation.
        cache_helper::invalidate_user_dashboard($userid);
        cache_helper::invalidate_course_progress($courseid);

        // Send progress event to SmartLearning backend.
        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'completion_updated';
            self::send_progress_event($progress);
        }
    }

    /**
     * Course updated - invalidate caches.
     *
     * @param \core\event\course_updated $event
     */
    public static function course_updated(\core\event\course_updated $event): void {
        cache_helper::invalidate_course_progress($event->courseid);
    }

    /**
     * User created - invalidate company users cache.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        cache_helper::invalidate_company_users();
        cache_helper::invalidate_health_summary();
    }

    /**
     * User updated - invalidate caches.
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event): void {
        cache_helper::invalidate_company_users();
        cache_helper::invalidate_user_dashboard($event->relateduserid);
    }

    /**
     * User deleted - invalidate caches.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        cache_helper::invalidate_company_users();
        cache_helper::invalidate_health_summary();
    }

    /**
     * Message sent - invalidate user dashboard.
     *
     * @param \core\event\message_sent $event
     */
    public static function message_sent(\core\event\message_sent $event): void {
        // Invalidate dashboard for the recipient.
        $userid = $event->relateduserid;
        if ($userid) {
            cache_helper::invalidate_user_dashboard($userid);
        }
    }

    /**
     * Role assigned - invalidate caches when role changes.
     *
     * NOTE: This observer NO LONGER assigns system-level roles because doing so
     * breaks IOMAD's company context handling. Instead, the plugin now checks
     * for manager-like roles at course/category level directly in the
     * has_admin_or_manager_role() function.
     *
     * @param \core\event\role_assigned $event
     */
    public static function role_assigned(\core\event\role_assigned $event): void {
        // Just invalidate caches when role assignments change.
        // This ensures the plugin picks up new manager role assignments immediately.
        cache_helper::invalidate_company_users();
    }

    // =========================================================================
    // WebSocket Progress Event Handlers
    // =========================================================================

    /**
     * Quiz attempt submitted - send progress event.
     *
     * @param \mod_quiz\event\attempt_submitted $event
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'quiz_submitted';
            self::send_progress_event($progress);
        }
    }

    /**
     * Book chapter viewed - send progress event.
     *
     * @param \mod_book\event\chapter_viewed $event
     */
    public static function book_chapter_viewed(\mod_book\event\chapter_viewed $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'chapter_viewed';
            $progress['currentitem'] = $data['objectid']; // Chapter ID.
            self::send_progress_event($progress);
        }
    }

    /**
     * Lesson page viewed - send progress event.
     *
     * @param \mod_lesson\event\page_viewed $event
     */
    public static function lesson_page_viewed(\mod_lesson\event\page_viewed $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'page_viewed';
            self::send_progress_event($progress);
        }
    }

    /**
     * SCORM SCO launched - send progress event.
     *
     * @param \mod_scorm\event\sco_launched $event
     */
    public static function scorm_sco_launched(\mod_scorm\event\sco_launched $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'sco_launched';
            self::send_progress_event($progress);
        }
    }

    /**
     * SCORM score submitted - send progress event.
     *
     * @param \mod_scorm\event\scoreraw_submitted $event
     */
    public static function scorm_scoreraw_submitted(\mod_scorm\event\scoreraw_submitted $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'score_submitted';
            self::send_progress_event($progress);
        }
    }

    /**
     * Assignment submission created - send progress event.
     *
     * @param \mod_assign\event\submission_created $event
     */
    public static function assign_submission_created(\mod_assign\event\submission_created $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['userid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'submission_created';
            self::send_progress_event($progress);
        }
    }

    /**
     * Assignment graded - send progress event.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function assign_submission_graded(\mod_assign\event\submission_graded $event): void {
        $data = $event->get_data();
        $cmid = $data['contextinstanceid'];
        $userid = $data['relateduserid'];

        $progress = self::get_progress_data($cmid, $userid);
        if (!empty($progress)) {
            $progress['event'] = 'submission_graded';
            self::send_progress_event($progress);
        }
    }
}
