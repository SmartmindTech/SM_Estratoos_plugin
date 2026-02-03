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
 * Activity position tracking JavaScript generator.
 *
 * Generates tracking JS for quiz, book, and lesson activities that:
 *   1. Detects current position from URL parameters
 *   2. Tracks furthest position in sessionStorage
 *   3. Reports position via postMessage using 'activity-progress' type
 *   4. Listens for navigation requests (activity-navigate-to-position)
 *   5. Handles pending navigation from tag clicks
 *
 * Uses 'activity-progress' message type (not 'scorm-progress') to clearly
 * distinguish non-SCORM activities. The SmartLearning frontend handles both
 * message types for position bar, tagging, go-back button, and progress tracking.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\activity;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates position tracking JavaScript for non-SCORM activities.
 *
 * Each activity type has a static entry point that queries the DB for item
 * data and delegates to the generic script generator.
 */
class tracking_js {

    /**
     * Generate tracking JS for quiz attempt/review pages.
     *
     * Quiz position is page-based. Each page can contain multiple questions.
     * URL parameter: ?page=N (0-based).
     *
     * @param int $cmid Course module ID.
     * @param int $quizid Quiz instance ID.
     * @return string HTML <script> block, or empty string if quiz has only 1 page.
     */
    public static function get_quiz_script(int $cmid, int $quizid): string {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id, questionsperpage');
        if (!$quiz) {
            return '';
        }

        $questioncount = $DB->count_records('quiz_slots', ['quizid' => $quizid]);
        if ($questioncount <= 0) {
            return '';
        }

        $perpage = (int)$quiz->questionsperpage;
        if ($perpage <= 0) {
            // All questions on one page — no position tracking.
            return '';
        }

        $totalpages = (int)ceil($questioncount / $perpage);
        if ($totalpages <= 1) {
            return '';
        }

        // Build maps: position (1-based) ↔ page number (0-based).
        $itemmap = [];
        $reversemap = [];
        for ($i = 1; $i <= $totalpages; $i++) {
            $page = $i - 1; // 0-based page number.
            $itemmap[$i] = $page;
            $reversemap[$page] = $i;
        }

        return self::get_generic_script($cmid, 'quiz', $totalpages, $itemmap, $reversemap, 'page');
    }

    /**
     * Generate tracking JS for book chapter pages.
     *
     * Book position is chapter-based. URL parameter: ?chapterid=N (DB ID).
     *
     * @param int $cmid Course module ID.
     * @param int $bookid Book instance ID.
     * @return string HTML <script> block, or empty string if book has ≤1 chapter.
     */
    public static function get_book_script(int $cmid, int $bookid): string {
        global $DB;

        $chapters = $DB->get_records('book_chapters',
            ['bookid' => $bookid, 'hidden' => 0],
            'pagenum ASC',
            'id, pagenum'
        );

        if (count($chapters) <= 1) {
            return '';
        }

        // Build maps: position (1-based) ↔ chapter ID.
        $itemmap = [];
        $reversemap = [];
        $pos = 1;
        foreach ($chapters as $chapter) {
            $itemmap[$pos] = (int)$chapter->id;
            $reversemap[(int)$chapter->id] = $pos;
            $pos++;
        }

        return self::get_generic_script($cmid, 'book', count($chapters), $itemmap, $reversemap, 'chapterid');
    }

    /**
     * Generate tracking JS for whole-activity tagging.
     *
     * For activities without position tracking (page, resource, url, assign, forum,
     * folder, label, etc.), this sends an activity-progress message with position 1/1.
     * This enables tagging comments to the whole activity.
     *
     * @param int $cmid Course module ID.
     * @param string $modtype Activity type name (for logging).
     * @return string HTML <script> block.
     */
    public static function get_whole_activity_script(int $cmid, string $modtype): string {
        return <<<HTML
<script>
// ============================================================
// Whole-Activity Tagging ({$modtype}) — cmid {$cmid}
// Reports position 1/1 for activities without position tracking.
// Enables tagging comments to the whole activity.
// ============================================================
(function() {
    var cmid = {$cmid};
    var modtype = '{$modtype}';

    function sendProgress() {
        var message = {
            type: 'activity-progress',
            activityType: modtype,
            cmid: cmid,
            currentPosition: 1,
            totalPositions: 1,
            furthestPosition: 1,
            status: 'completed',
            source: 'whole-activity',
            timestamp: Date.now(),
            progressPercent: 100,
            currentPercent: 100
        };
        try { window.parent.postMessage(message, '*'); } catch (e) {}
        try {
            if (window.top !== window.parent) {
                window.top.postMessage(message, '*');
            }
        } catch (e) {}
    }

    // Send progress on load and after delays.
    sendProgress();
    setTimeout(sendProgress, 500);
    setTimeout(sendProgress, 2000);
})();
</script>
HTML;
    }

    /**
     * Generate tracking JS for lesson pages.
     *
     * Lesson position is page-based. URL parameter: ?pageid=N (DB ID).
     * Excludes non-content pages: endofbranch (21), cluster (30), endofcluster (31).
     *
     * @param int $cmid Course module ID.
     * @param int $lessonid Lesson instance ID.
     * @return string HTML <script> block, or empty string if lesson has ≤1 page.
     */
    public static function get_lesson_script(int $cmid, int $lessonid): string {
        global $DB;

        $pages = $DB->get_records_sql(
            "SELECT id FROM {lesson_pages}
             WHERE lessonid = :lessonid AND qtype NOT IN (21, 30, 31)
             ORDER BY ordering ASC",
            ['lessonid' => $lessonid]
        );

        if (count($pages) <= 1) {
            return '';
        }

        // Build maps: position (1-based) ↔ page ID.
        $itemmap = [];
        $reversemap = [];
        $pos = 1;
        foreach ($pages as $page) {
            $itemmap[$pos] = (int)$page->id;
            $reversemap[(int)$page->id] = $pos;
            $pos++;
        }

        return self::get_generic_script($cmid, 'lesson', count($pages), $itemmap, $reversemap, 'pageid');
    }

    /**
     * Generate the generic tracking JS IIFE.
     *
     * This JS runs inside the Moodle activity page (which is loaded in an iframe
     * from SmartLearning). It detects position, reports progress, and handles
     * navigation using 'activity-progress' and 'activity-navigate-to-position'
     * message types.
     *
     * @param int $cmid Course module ID.
     * @param string $modtype Activity type ('quiz', 'book', 'lesson').
     * @param int $totalitems Total number of position items.
     * @param array $itemmap Position (1-based) → URL param value.
     * @param array $reversemap URL param value → position (1-based).
     * @param string $urlparam URL parameter name ('page', 'chapterid', 'pageid').
     * @return string HTML <script> block.
     */
    private static function get_generic_script(
        int $cmid,
        string $modtype,
        int $totalitems,
        array $itemmap,
        array $reversemap,
        string $urlparam
    ): string {
        $itemmapjson = json_encode((object)$itemmap);
        $reversemapjson = json_encode((object)$reversemap);

        return <<<HTML
<script>
// ============================================================
// Activity Position Tracking ({$modtype}) — cmid {$cmid}
// Detects position from URL, reports via postMessage, handles navigation.
// Uses activity-progress message type for non-SCORM activities.
// ============================================================
(function() {
    var cmid = {$cmid};
    var modtype = '{$modtype}';
    var totalItems = {$totalitems};
    var itemMap = {$itemmapjson};
    var reverseMap = {$reversemapjson};
    var urlParam = '{$urlparam}';

    // 1. Detect current position from URL parameter.
    var params = new URLSearchParams(window.location.search);
    var paramValue = params.get(urlParam);
    var currentPosition = 1;
    if (paramValue !== null) {
        var key = isNaN(Number(paramValue)) ? paramValue : Number(paramValue);
        if (reverseMap[key] !== undefined) {
            currentPosition = reverseMap[key];
        }
    }

    // 2. Track furthest position in sessionStorage + localStorage.
    var storageKey = 'activity_furthest_position_' + cmid;
    var furthestPosition = 0;
    try {
        furthestPosition = parseInt(sessionStorage.getItem(storageKey)) || 0;
    } catch (e) {}
    if (furthestPosition <= 0) {
        try {
            furthestPosition = parseInt(localStorage.getItem(storageKey)) || 0;
        } catch (e) {}
    }
    if (currentPosition > furthestPosition) {
        furthestPosition = currentPosition;
    }
    try {
        sessionStorage.setItem(storageKey, String(furthestPosition));
        localStorage.setItem(storageKey, String(furthestPosition));
    } catch (e) {}

    // 3. Build and send progress message (activity-progress for non-SCORM activities).
    var progressPercent = totalItems > 0
        ? Math.min(100, Math.round(furthestPosition / totalItems * 100)) : 0;
    var currentPercent = totalItems > 0
        ? Math.min(100, Math.round(currentPosition / totalItems * 100)) : 0;

    function sendProgress() {
        var message = {
            type: 'activity-progress',
            activityType: modtype,
            cmid: cmid,
            currentPosition: currentPosition,
            totalPositions: totalItems,
            furthestPosition: furthestPosition,
            status: furthestPosition >= totalItems ? 'completed' : 'incomplete',
            source: 'navigation',
            timestamp: Date.now(),
            progressPercent: progressPercent,
            currentPercent: currentPercent
        };
        try { window.parent.postMessage(message, '*'); } catch (e) {}
        try {
            if (window.top !== window.parent) {
                window.top.postMessage(message, '*');
            }
        } catch (e) {}
    }

    // Send progress on load and after delays (iframe may not be ready immediately).
    sendProgress();
    setTimeout(sendProgress, 500);
    setTimeout(sendProgress, 2000);

    // 4. Listen for navigation requests from SmartLearning.
    // Accepts both 'activity-navigate-to-position' and legacy 'scorm-navigate-to-slide'.
    window.addEventListener('message', function(event) {
        if (!event.data) return;
        var isActivityNav = event.data.type === 'activity-navigate-to-position' && event.data.cmid === cmid;
        var isLegacyNav = event.data.type === 'scorm-navigate-to-slide' && event.data.cmid === cmid;
        if (isActivityNav || isLegacyNav) {
            var targetPosition = event.data.position || event.data.slide;
            if (targetPosition && itemMap[targetPosition] !== undefined) {
                var targetValue = itemMap[targetPosition];
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set(urlParam, String(targetValue));
                    window.location.href = url.toString();
                } catch (e) {}
            }
        }
    }, false);

    // 5. Check sessionStorage for pending navigation (from tag click before iframe loaded).
    // Supports both new and legacy storage keys.
    try {
        var pendingKey = 'activity_pending_navigation_' + cmid;
        var legacyPendingKey = 'scorm_pending_navigation_' + cmid;
        var pending = sessionStorage.getItem(pendingKey) || sessionStorage.getItem(legacyPendingKey);
        if (pending) {
            sessionStorage.removeItem(pendingKey);
            sessionStorage.removeItem(legacyPendingKey);
            var navData = JSON.parse(pending);
            var targetPos = navData.position || navData.slide;
            if (targetPos && targetPos !== currentPosition && itemMap[targetPos] !== undefined) {
                var url = new URL(window.location.href);
                url.searchParams.set(urlParam, String(itemMap[targetPos]));
                window.location.href = url.toString();
            }
        }
    } catch (e) {}
})();
</script>
HTML;
    }
}
