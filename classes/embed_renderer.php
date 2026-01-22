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

    /**
     * Constructor.
     *
     * @param \cm_info $cm Course module info
     */
    public function __construct(\cm_info $cm) {
        $this->cm = $cm;
        $this->context = \context_module::instance($cm->id);
        $this->activityType = $cm->modname;
    }

    /**
     * Render the activity content without Moodle chrome.
     *
     * @return string HTML content
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

        // Render based on activity type.
        switch ($this->activityType) {
            case 'scorm':
                return $this->render_scorm();
            case 'quiz':
                return $this->render_quiz();
            case 'assign':
                return $this->render_assignment();
            case 'lesson':
                return $this->render_lesson();
            case 'book':
                return $this->render_book();
            case 'page':
                return $this->render_page();
            case 'resource':
                return $this->render_resource();
            case 'url':
                return $this->render_url();
            default:
                return $this->render_generic();
        }
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
     * Render SCORM player.
     *
     * @return string HTML content
     */
    private function render_scorm(): string {
        global $DB, $CFG, $USER, $PAGE;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $scorm = $DB->get_record('scorm', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get current SCO and attempt.
        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id, 'scormtype' => 'sco']);
        if (empty($scoes)) {
            return $this->render_error('No SCOs found in this SCORM package.');
        }

        $sco = reset($scoes);

        // Get or create attempt.
        $attempt = scorm_get_last_attempt($scorm->id, $USER->id);
        if (empty($attempt)) {
            $attempt = 1;
        }

        // Build SCORM player URL (use Moodle's native player).
        $playerUrl = new \moodle_url('/mod/scorm/player.php', [
            'a' => $scorm->id,
            'currentorg' => '',
            'scoid' => $sco->id,
            'display' => 'popup',
        ]);

        // Load required JavaScript.
        $PAGE->requires->js_call_amd('mod_scorm/scorm_player', 'init');

        $html = '<div class="embed-scorm-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $playerUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;" ';
        $html .= 'allowfullscreen allow="autoplay; fullscreen">';
        $html .= '</iframe>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Quiz.
     *
     * @return string HTML content
     */
    private function render_quiz(): string {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quiz = $DB->get_record('quiz', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Redirect to quiz view page (within iframe).
        $quizUrl = new \moodle_url('/mod/quiz/view.php', ['id' => $this->cm->id]);

        $html = '<div class="embed-quiz-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $quizUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;">';
        $html .= '</iframe>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Assignment.
     *
     * @return string HTML content
     */
    private function render_assignment(): string {
        $assignUrl = new \moodle_url('/mod/assign/view.php', ['id' => $this->cm->id]);

        $html = '<div class="embed-assign-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $assignUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;">';
        $html .= '</iframe>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Lesson.
     *
     * @return string HTML content
     */
    private function render_lesson(): string {
        $lessonUrl = new \moodle_url('/mod/lesson/view.php', ['id' => $this->cm->id]);

        $html = '<div class="embed-lesson-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $lessonUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;">';
        $html .= '</iframe>';
        $html .= '</div>';

        return $this->wrap_content($html);
    }

    /**
     * Render Book.
     *
     * @return string HTML content
     */
    private function render_book(): string {
        global $DB;

        $book = $DB->get_record('book', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Get first chapter.
        $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id, 'hidden' => 0], 'pagenum ASC');
        $chapter = reset($chapters);

        $bookUrl = new \moodle_url('/mod/book/view.php', [
            'id' => $this->cm->id,
            'chapterid' => $chapter ? $chapter->id : 0,
        ]);

        $html = '<div class="embed-book-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $bookUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;">';
        $html .= '</iframe>';
        $html .= '</div>';

        return $this->wrap_content($html);
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
        global $DB;

        $url = $DB->get_record('url', ['id' => $this->cm->instance], '*', MUST_EXIST);

        // Check display mode.
        if ($url->display == RESOURCELIB_DISPLAY_EMBED) {
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
     * Render generic activity (fallback).
     *
     * @return string HTML content
     */
    private function render_generic(): string {
        $activityUrl = new \moodle_url('/mod/' . $this->activityType . '/view.php', ['id' => $this->cm->id]);

        $html = '<div class="embed-generic-container" style="width:100%;height:100vh;">';
        $html .= '<iframe src="' . $activityUrl->out(false) . '" ';
        $html .= 'style="width:100%;height:100%;border:none;">';
        $html .= '</iframe>';
        $html .= '</div>';

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
     * @param string $content Inner content
     * @return string Complete HTML document
     */
    private function wrap_content(string $content): string {
        global $CFG, $PAGE, $OUTPUT;

        // Ensure the page is properly initialized before getting head code.
        // This is needed because embed.php uses NO_MOODLE_COOKIES which results
        // in a bootstrap_renderer instead of core_renderer.
        try {
            // Force proper output initialization by getting the renderer.
            $output = $PAGE->get_renderer('core');
            $css = $PAGE->requires->get_head_code($PAGE, $output);
        } catch (\Throwable $e) {
            // Fallback: include basic Moodle CSS without full renderer.
            $css = '<link rel="stylesheet" href="' . $CFG->wwwroot . '/theme/styles.php/' . $PAGE->theme->name . '/' . theme_get_revision() . '/all" />';
        }

        $html = '<!DOCTYPE html>';
        $html .= '<html lang="' . current_language() . '">';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . format_string($this->cm->name) . '</title>';
        $html .= $css;
        $html .= '<style>';
        $html .= 'body { margin: 0; padding: 0; overflow: hidden; }';
        $html .= '.embed-container { width: 100%; height: 100vh; }';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body class="embed-mode">';
        $html .= '<main class="embed-container">';
        $html .= $content;
        $html .= '</main>';
        try {
            $html .= $PAGE->requires->get_end_code();
        } catch (\Throwable $e) {
            // Fallback: no additional scripts.
            $html .= '<!-- end code unavailable -->';
        }
        $html .= '</body>';
        $html .= '</html>';

        return $html;
    }
}
