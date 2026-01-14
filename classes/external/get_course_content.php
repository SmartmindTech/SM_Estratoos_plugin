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
 * External function to retrieve comprehensive course content.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use moodle_url;

/**
 * External function to retrieve comprehensive course content including SCORM, files, pages, and all educational materials.
 */
class get_course_content extends external_api {

    /**
     * Define the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course ID'),
                'List of course IDs to retrieve content for',
                VALUE_DEFAULT,
                []
            ),
            'options' => new external_single_structure([
                'includescormdetails' => new external_value(PARAM_BOOL, 'Include SCORM SCO details', VALUE_DEFAULT, true),
                'includefilecontents' => new external_value(PARAM_BOOL, 'Include file content URLs', VALUE_DEFAULT, true),
                'includepagecontent' => new external_value(PARAM_BOOL, 'Include page HTML content', VALUE_DEFAULT, true),
            ], 'Options', VALUE_DEFAULT, [])
        ]);
    }

    /**
     * Execute the function to retrieve course content.
     *
     * @param array $courseids List of course IDs.
     * @param array $options Options for content retrieval.
     * @return array Course content data.
     */
    public static function execute(array $courseids = [], array $options = []): array {
        global $DB, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseids' => $courseids,
            'options' => $options,
        ]);

        $courseids = $params['courseids'];
        $options = array_merge([
            'includescormdetails' => true,
            'includefilecontents' => true,
            'includepagecontent' => true,
        ], $params['options']);

        $warnings = [];
        $courses = [];

        // Apply company filtering if IOMAD token.
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $token = \local_sm_estratoos_plugin\util::get_current_request_token();
            if ($token) {
                $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                if ($restrictions && !empty($restrictions->companyid)) {
                    $filter = new \local_sm_estratoos_plugin\webservice_filter();
                    $filter->set_company_id($restrictions->companyid);
                    $allowedcourses = $filter->get_allowed_course_ids();
                    if (!empty($allowedcourses)) {
                        $courseids = array_intersect($courseids, $allowedcourses);
                    }
                }
            }
        }

        // Process each course.
        foreach ($courseids as $courseid) {
            try {
                // Get course record.
                $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
                if (!$course) {
                    $warnings[] = [
                        'item' => 'course',
                        'itemid' => $courseid,
                        'warningcode' => 'coursenotfound',
                        'message' => "Course with ID $courseid not found",
                    ];
                    continue;
                }

                // Validate context and capability.
                $context = context_course::instance($courseid, IGNORE_MISSING);
                if (!$context) {
                    $warnings[] = [
                        'item' => 'course',
                        'itemid' => $courseid,
                        'warningcode' => 'contextnotfound',
                        'message' => "Context for course $courseid not found",
                    ];
                    continue;
                }

                self::validate_context($context);

                // Check if user can view the course.
                if (!is_enrolled($context) && !has_capability('moodle/course:view', $context)) {
                    $warnings[] = [
                        'item' => 'course',
                        'itemid' => $courseid,
                        'warningcode' => 'nopermission',
                        'message' => "No permission to view course $courseid",
                    ];
                    continue;
                }

                // Get course structure.
                $modinfo = get_fast_modinfo($course);
                $sectionsdata = [];

                foreach ($modinfo->get_section_info_all() as $section) {
                    $sectiondata = [
                        'id' => $section->id,
                        'name' => get_section_name($course, $section),
                        'summary' => format_text($section->summary, $section->summaryformat, ['context' => $context]),
                        'summaryformat' => $section->summaryformat,
                        'visible' => $section->visible ? true : false,
                        'sectionnum' => $section->section,
                        'modules' => [],
                    ];

                    // Get modules in this section.
                    if (!empty($modinfo->sections[$section->section])) {
                        foreach ($modinfo->sections[$section->section] as $cmid) {
                            $cm = $modinfo->cms[$cmid];

                            // Skip if not visible to user.
                            if (!$cm->uservisible) {
                                continue;
                            }

                            $moduledata = self::get_module_data($cm, $context, $options);
                            if ($moduledata) {
                                $sectiondata['modules'][] = $moduledata;
                            }
                        }
                    }

                    $sectionsdata[] = $sectiondata;
                }

                $courses[] = [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'summary' => format_text($course->summary, $course->summaryformat, ['context' => $context]),
                    'summaryformat' => $course->summaryformat,
                    'startdate' => $course->startdate,
                    'enddate' => $course->enddate,
                    'visible' => $course->visible ? true : false,
                    'sections' => $sectionsdata,
                ];

            } catch (\Exception $e) {
                $warnings[] = [
                    'item' => 'course',
                    'itemid' => $courseid,
                    'warningcode' => 'exception',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'courses' => $courses,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get detailed data for a course module.
     *
     * @param \cm_info $cm Course module info.
     * @param \context_course $context Course context.
     * @param array $options Retrieval options.
     * @return array|null Module data or null if error.
     */
    private static function get_module_data(\cm_info $cm, \context_course $context, array $options): ?array {
        global $DB, $CFG;

        $modulecontext = \context_module::instance($cm->id, IGNORE_MISSING);
        if (!$modulecontext) {
            return null;
        }

        $moduledata = [
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname,
            'instance' => $cm->instance,
            'visible' => $cm->visible ? true : false,
            'uservisible' => $cm->uservisible ? true : false,
            'description' => format_module_intro($cm->modname, $cm->get_module_instance(), $cm->id, false),
            'completion' => $cm->completion,
            'completionstate' => 0,
            'url' => $cm->url ? $cm->url->out(false) : '',
            'contents' => [],
        ];

        // Get completion state if available.
        if ($cm->completion != COMPLETION_TRACKING_NONE) {
            $completion = new \completion_info($cm->get_course());
            $completiondata = $completion->get_data($cm);
            $moduledata['completionstate'] = $completiondata->completionstate ?? 0;
        }

        // Get module-specific content based on type.
        switch ($cm->modname) {
            case 'scorm':
                if ($options['includescormdetails']) {
                    $moduledata['scorm'] = self::get_scorm_data($cm->instance, $modulecontext);
                }
                break;

            case 'resource':
                if ($options['includefilecontents']) {
                    $moduledata['contents'] = self::get_resource_files($cm->instance, $modulecontext);
                }
                break;

            case 'folder':
                if ($options['includefilecontents']) {
                    $moduledata['contents'] = self::get_folder_files($cm->instance, $modulecontext);
                }
                break;

            case 'page':
                if ($options['includepagecontent']) {
                    $pagedata = self::get_page_content($cm->instance, $modulecontext);
                    $moduledata['pagecontent'] = $pagedata['content'];
                    $moduledata['pagecontentformat'] = $pagedata['contentformat'];
                }
                if ($options['includefilecontents']) {
                    $moduledata['contents'] = self::get_page_files($cm->instance, $modulecontext);
                }
                break;

            case 'url':
                $urldata = self::get_url_data($cm->instance);
                $moduledata['externalurl'] = $urldata['externalurl'];
                $moduledata['contents'] = [[
                    'type' => 'url',
                    'filename' => $urldata['name'],
                    'filepath' => '',
                    'filesize' => 0,
                    'fileurl' => $urldata['externalurl'],
                    'mimetype' => '',
                    'timecreated' => 0,
                    'timemodified' => $urldata['timemodified'],
                    'author' => '',
                ]];
                break;

            case 'label':
                // Labels have their content in the description field (intro).
                break;

            case 'assign':
                $moduledata['assignment'] = self::get_assignment_data($cm->instance);
                break;

            case 'quiz':
                $moduledata['quiz'] = self::get_quiz_data($cm->instance);
                break;

            case 'forum':
                $moduledata['forum'] = self::get_forum_data($cm->instance);
                break;

            case 'book':
                if ($options['includepagecontent']) {
                    $moduledata['book'] = self::get_book_data($cm->instance, $modulecontext);
                }
                break;

            case 'lesson':
                $moduledata['lesson'] = self::get_lesson_data($cm->instance);
                break;

            default:
                // For other module types, try to get any associated files.
                if ($options['includefilecontents']) {
                    $moduledata['contents'] = self::get_generic_module_files($cm, $modulecontext);
                }
                break;
        }

        return $moduledata;
    }

    /**
     * Get SCORM package data including SCOs.
     *
     * @param int $scormid SCORM instance ID.
     * @param \context_module $context Module context.
     * @return array SCORM data.
     */
    private static function get_scorm_data(int $scormid, \context_module $context): array {
        global $DB, $CFG;

        $scorm = $DB->get_record('scorm', ['id' => $scormid]);
        if (!$scorm) {
            return [];
        }

        $data = [
            'id' => $scorm->id,
            'name' => $scorm->name,
            'version' => $scorm->version ?? '',
            'maxgrade' => (float) $scorm->maxgrade,
            'grademethod' => (int) $scorm->grademethod,
            'maxattempt' => (int) $scorm->maxattempt,
            'whatgrade' => (int) ($scorm->whatgrade ?? 0),
            'scormtype' => $scorm->scormtype ?? 'local',
            'reference' => $scorm->reference ?? '',
            'launch' => '',
            'scos' => [],
            'packageurl' => '',
        ];

        // Get launch URL.
        if (!empty($scorm->launch)) {
            $data['launch'] = $CFG->wwwroot . '/mod/scorm/player.php?scoid=' . $scorm->launch . '&cm=' . $context->instanceid;
        }

        // Get package file URL.
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_scorm', 'package', 0, 'sortorder', false);
        foreach ($files as $file) {
            $data['packageurl'] = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out(false);
            break;
        }

        // Get SCOs (Sharable Content Objects).
        $scos = $DB->get_records('scorm_scoes', ['scorm' => $scormid], 'sortorder, id');
        foreach ($scos as $sco) {
            $scodata = [
                'id' => $sco->id,
                'identifier' => $sco->identifier ?? '',
                'title' => $sco->title ?? '',
                'organization' => $sco->organization ?? '',
                'parent' => $sco->parent ?? '',
                'launch' => $sco->launch ?? '',
                'scormtype' => $sco->scormtype ?? '',
                'sortorder' => $sco->sortorder ?? 0,
            ];

            // Get additional SCO data.
            $scoextradata = $DB->get_records('scorm_scoes_data', ['scoid' => $sco->id]);
            foreach ($scoextradata as $extra) {
                $scodata[$extra->name] = $extra->value;
            }

            $data['scos'][] = $scodata;
        }

        return $data;
    }

    /**
     * Get resource files.
     *
     * @param int $resourceid Resource instance ID.
     * @param \context_module $context Module context.
     * @return array Files data.
     */
    private static function get_resource_files(int $resourceid, \context_module $context): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $resourceid]);
        if (!$resource) {
            return [];
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);

        return self::format_files_array($files);
    }

    /**
     * Get folder files.
     *
     * @param int $folderid Folder instance ID.
     * @param \context_module $context Module context.
     * @return array Files data.
     */
    private static function get_folder_files(int $folderid, \context_module $context): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'sortorder, id', false);

        return self::format_files_array($files);
    }

    /**
     * Get page content.
     *
     * @param int $pageid Page instance ID.
     * @param \context_module $context Module context.
     * @return array Page content data.
     */
    private static function get_page_content(int $pageid, \context_module $context): array {
        global $DB;

        $page = $DB->get_record('page', ['id' => $pageid]);
        if (!$page) {
            return ['content' => '', 'contentformat' => FORMAT_HTML];
        }

        // Rewrite pluginfile URLs.
        $content = file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $context->id,
            'mod_page',
            'content',
            $page->revision
        );

        return [
            'content' => format_text($content, $page->contentformat, ['context' => $context]),
            'contentformat' => $page->contentformat,
        ];
    }

    /**
     * Get page files.
     *
     * @param int $pageid Page instance ID.
     * @param \context_module $context Module context.
     * @return array Files data.
     */
    private static function get_page_files(int $pageid, \context_module $context): array {
        global $DB;

        $page = $DB->get_record('page', ['id' => $pageid]);
        if (!$page) {
            return [];
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_page', 'content', $page->revision, 'sortorder', false);

        return self::format_files_array($files);
    }

    /**
     * Get URL module data.
     *
     * @param int $urlid URL instance ID.
     * @return array URL data.
     */
    private static function get_url_data(int $urlid): array {
        global $DB;

        $url = $DB->get_record('url', ['id' => $urlid]);
        if (!$url) {
            return ['name' => '', 'externalurl' => '', 'timemodified' => 0];
        }

        return [
            'name' => $url->name,
            'externalurl' => $url->externalurl,
            'timemodified' => $url->timemodified,
        ];
    }

    /**
     * Get assignment data.
     *
     * @param int $assignid Assignment instance ID.
     * @return array Assignment data.
     */
    private static function get_assignment_data(int $assignid): array {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return [];
        }

        return [
            'id' => $assign->id,
            'name' => $assign->name,
            'duedate' => $assign->duedate,
            'allowsubmissionsfromdate' => $assign->allowsubmissionsfromdate,
            'grade' => (float) $assign->grade,
            'timemodified' => $assign->timemodified,
            'cutoffdate' => $assign->cutoffdate ?? 0,
            'gradingduedate' => $assign->gradingduedate ?? 0,
        ];
    }

    /**
     * Get quiz data.
     *
     * @param int $quizid Quiz instance ID.
     * @return array Quiz data.
     */
    private static function get_quiz_data(int $quizid): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return [];
        }

        return [
            'id' => $quiz->id,
            'name' => $quiz->name,
            'timeopen' => $quiz->timeopen,
            'timeclose' => $quiz->timeclose,
            'timelimit' => $quiz->timelimit,
            'grade' => (float) $quiz->grade,
            'attempts' => $quiz->attempts,
            'grademethod' => $quiz->grademethod,
            'sumgrades' => (float) ($quiz->sumgrades ?? 0),
            'questionsperpage' => $quiz->questionsperpage ?? 0,
        ];
    }

    /**
     * Get forum data.
     *
     * @param int $forumid Forum instance ID.
     * @return array Forum data.
     */
    private static function get_forum_data(int $forumid): array {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $forumid]);
        if (!$forum) {
            return [];
        }

        // Count discussions.
        $discussioncount = $DB->count_records('forum_discussions', ['forum' => $forumid]);

        return [
            'id' => $forum->id,
            'name' => $forum->name,
            'type' => $forum->type,
            'discussioncount' => $discussioncount,
            'timemodified' => $forum->timemodified,
        ];
    }

    /**
     * Get book data including chapters.
     *
     * @param int $bookid Book instance ID.
     * @param \context_module $context Module context.
     * @return array Book data.
     */
    private static function get_book_data(int $bookid, \context_module $context): array {
        global $DB;

        $book = $DB->get_record('book', ['id' => $bookid]);
        if (!$book) {
            return [];
        }

        $chapters = $DB->get_records('book_chapters', ['bookid' => $bookid], 'pagenum');
        $chaptersdata = [];

        foreach ($chapters as $chapter) {
            // Rewrite pluginfile URLs.
            $content = file_rewrite_pluginfile_urls(
                $chapter->content,
                'pluginfile.php',
                $context->id,
                'mod_book',
                'chapter',
                $chapter->id
            );

            $chaptersdata[] = [
                'id' => $chapter->id,
                'title' => $chapter->title,
                'content' => format_text($content, $chapter->contentformat, ['context' => $context]),
                'contentformat' => $chapter->contentformat,
                'pagenum' => $chapter->pagenum,
                'subchapter' => $chapter->subchapter ? true : false,
                'hidden' => $chapter->hidden ? true : false,
            ];
        }

        return [
            'id' => $book->id,
            'name' => $book->name,
            'chapters' => $chaptersdata,
        ];
    }

    /**
     * Get lesson data.
     *
     * @param int $lessonid Lesson instance ID.
     * @return array Lesson data.
     */
    private static function get_lesson_data(int $lessonid): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $lessonid]);
        if (!$lesson) {
            return [];
        }

        // Count pages.
        $pagecount = $DB->count_records('lesson_pages', ['lessonid' => $lessonid]);

        return [
            'id' => $lesson->id,
            'name' => $lesson->name,
            'grade' => (float) $lesson->grade,
            'timelimit' => $lesson->timelimit ?? 0,
            'pagecount' => $pagecount,
            'maxattempts' => $lesson->maxattempts ?? 0,
            'timemodified' => $lesson->timemodified,
        ];
    }

    /**
     * Get files for generic module types.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @return array Files data.
     */
    private static function get_generic_module_files(\cm_info $cm, \context_module $context): array {
        $fs = get_file_storage();

        // Try common file areas.
        $fileareas = ['content', 'intro', 'mediafile', 'package'];
        $allfiles = [];

        foreach ($fileareas as $filearea) {
            $files = $fs->get_area_files($context->id, 'mod_' . $cm->modname, $filearea, false, 'sortorder', false);
            $allfiles = array_merge($allfiles, self::format_files_array($files));
        }

        return $allfiles;
    }

    /**
     * Format an array of stored_file objects into return structure.
     *
     * @param array $files Array of stored_file objects.
     * @return array Formatted files data.
     */
    private static function format_files_array(array $files): array {
        $result = [];

        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }

            $result[] = [
                'type' => 'file',
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => $file->get_filesize(),
                'fileurl' => moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false),
                'mimetype' => $file->get_mimetype(),
                'timecreated' => $file->get_timecreated(),
                'timemodified' => $file->get_timemodified(),
                'author' => $file->get_author() ?? '',
                'license' => $file->get_license() ?? '',
            ];
        }

        return $result;
    }

    /**
     * Define the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                    'summary' => new external_value(PARAM_RAW, 'Course summary'),
                    'summaryformat' => new external_value(PARAM_INT, 'Summary format'),
                    'startdate' => new external_value(PARAM_INT, 'Course start date'),
                    'enddate' => new external_value(PARAM_INT, 'Course end date'),
                    'visible' => new external_value(PARAM_BOOL, 'Course visibility'),
                    'sections' => new external_multiple_structure(
                        new external_single_structure([
                            'id' => new external_value(PARAM_INT, 'Section ID'),
                            'name' => new external_value(PARAM_TEXT, 'Section name'),
                            'summary' => new external_value(PARAM_RAW, 'Section summary'),
                            'summaryformat' => new external_value(PARAM_INT, 'Summary format'),
                            'visible' => new external_value(PARAM_BOOL, 'Section visibility'),
                            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                            'modules' => new external_multiple_structure(
                                new external_single_structure([
                                    'id' => new external_value(PARAM_INT, 'Course module ID'),
                                    'name' => new external_value(PARAM_TEXT, 'Module name'),
                                    'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module type (resource, scorm, page, etc.)'),
                                    'instance' => new external_value(PARAM_INT, 'Module instance ID'),
                                    'visible' => new external_value(PARAM_BOOL, 'Module visibility'),
                                    'uservisible' => new external_value(PARAM_BOOL, 'User can see module'),
                                    'description' => new external_value(PARAM_RAW, 'Module description/intro'),
                                    'completion' => new external_value(PARAM_INT, 'Completion tracking type'),
                                    'completionstate' => new external_value(PARAM_INT, 'User completion state'),
                                    'url' => new external_value(PARAM_URL, 'Module URL', VALUE_OPTIONAL),
                                    'contents' => new external_multiple_structure(
                                        new external_single_structure([
                                            'type' => new external_value(PARAM_ALPHA, 'Content type (file, url)'),
                                            'filename' => new external_value(PARAM_FILE, 'File name'),
                                            'filepath' => new external_value(PARAM_PATH, 'File path'),
                                            'filesize' => new external_value(PARAM_INT, 'File size in bytes'),
                                            'fileurl' => new external_value(PARAM_URL, 'File download URL'),
                                            'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
                                            'timecreated' => new external_value(PARAM_INT, 'Time created'),
                                            'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                                            'author' => new external_value(PARAM_RAW, 'Author'),
                                            'license' => new external_value(PARAM_RAW, 'License', VALUE_OPTIONAL),
                                        ]),
                                        'Module contents/files',
                                        VALUE_OPTIONAL
                                    ),
                                    'scorm' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'SCORM ID'),
                                        'name' => new external_value(PARAM_TEXT, 'SCORM name'),
                                        'version' => new external_value(PARAM_RAW, 'SCORM version'),
                                        'maxgrade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                                        'grademethod' => new external_value(PARAM_INT, 'Grade method'),
                                        'maxattempt' => new external_value(PARAM_INT, 'Maximum attempts'),
                                        'whatgrade' => new external_value(PARAM_INT, 'What grade to use'),
                                        'scormtype' => new external_value(PARAM_ALPHA, 'SCORM type (local, external)'),
                                        'reference' => new external_value(PARAM_RAW, 'Reference/URL'),
                                        'launch' => new external_value(PARAM_URL, 'Launch URL', VALUE_OPTIONAL),
                                        'packageurl' => new external_value(PARAM_URL, 'Package file URL', VALUE_OPTIONAL),
                                        'scos' => new external_multiple_structure(
                                            new external_single_structure([
                                                'id' => new external_value(PARAM_INT, 'SCO ID'),
                                                'identifier' => new external_value(PARAM_RAW, 'SCO identifier'),
                                                'title' => new external_value(PARAM_TEXT, 'SCO title'),
                                                'organization' => new external_value(PARAM_RAW, 'Organization'),
                                                'parent' => new external_value(PARAM_RAW, 'Parent SCO'),
                                                'launch' => new external_value(PARAM_RAW, 'Launch file'),
                                                'scormtype' => new external_value(PARAM_RAW, 'SCO type'),
                                                'sortorder' => new external_value(PARAM_INT, 'Sort order'),
                                            ]),
                                            'SCORM SCOs',
                                            VALUE_OPTIONAL
                                        ),
                                    ], 'SCORM data', VALUE_OPTIONAL),
                                    'pagecontent' => new external_value(PARAM_RAW, 'Page HTML content', VALUE_OPTIONAL),
                                    'pagecontentformat' => new external_value(PARAM_INT, 'Page content format', VALUE_OPTIONAL),
                                    'externalurl' => new external_value(PARAM_URL, 'External URL', VALUE_OPTIONAL),
                                    'assignment' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Assignment ID'),
                                        'name' => new external_value(PARAM_TEXT, 'Assignment name'),
                                        'duedate' => new external_value(PARAM_INT, 'Due date'),
                                        'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Submissions open date'),
                                        'grade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                                        'cutoffdate' => new external_value(PARAM_INT, 'Cut-off date'),
                                        'gradingduedate' => new external_value(PARAM_INT, 'Grading due date'),
                                    ], 'Assignment data', VALUE_OPTIONAL),
                                    'quiz' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Quiz ID'),
                                        'name' => new external_value(PARAM_TEXT, 'Quiz name'),
                                        'timeopen' => new external_value(PARAM_INT, 'Time open'),
                                        'timeclose' => new external_value(PARAM_INT, 'Time close'),
                                        'timelimit' => new external_value(PARAM_INT, 'Time limit'),
                                        'grade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                                        'attempts' => new external_value(PARAM_INT, 'Allowed attempts'),
                                        'grademethod' => new external_value(PARAM_INT, 'Grade method'),
                                        'sumgrades' => new external_value(PARAM_FLOAT, 'Sum of grades'),
                                        'questionsperpage' => new external_value(PARAM_INT, 'Questions per page'),
                                    ], 'Quiz data', VALUE_OPTIONAL),
                                    'forum' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Forum ID'),
                                        'name' => new external_value(PARAM_TEXT, 'Forum name'),
                                        'type' => new external_value(PARAM_ALPHA, 'Forum type'),
                                        'discussioncount' => new external_value(PARAM_INT, 'Discussion count'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                                    ], 'Forum data', VALUE_OPTIONAL),
                                    'book' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Book ID'),
                                        'name' => new external_value(PARAM_TEXT, 'Book name'),
                                        'chapters' => new external_multiple_structure(
                                            new external_single_structure([
                                                'id' => new external_value(PARAM_INT, 'Chapter ID'),
                                                'title' => new external_value(PARAM_TEXT, 'Chapter title'),
                                                'content' => new external_value(PARAM_RAW, 'Chapter content'),
                                                'contentformat' => new external_value(PARAM_INT, 'Content format'),
                                                'pagenum' => new external_value(PARAM_INT, 'Page number'),
                                                'subchapter' => new external_value(PARAM_BOOL, 'Is subchapter'),
                                                'hidden' => new external_value(PARAM_BOOL, 'Is hidden'),
                                            ]),
                                            'Book chapters',
                                            VALUE_OPTIONAL
                                        ),
                                    ], 'Book data', VALUE_OPTIONAL),
                                    'lesson' => new external_single_structure([
                                        'id' => new external_value(PARAM_INT, 'Lesson ID'),
                                        'name' => new external_value(PARAM_TEXT, 'Lesson name'),
                                        'grade' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                                        'timelimit' => new external_value(PARAM_INT, 'Time limit'),
                                        'pagecount' => new external_value(PARAM_INT, 'Page count'),
                                        'maxattempts' => new external_value(PARAM_INT, 'Maximum attempts'),
                                        'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                                    ], 'Lesson data', VALUE_OPTIONAL),
                                ])
                            ),
                        ])
                    ),
                ])
            ),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_TEXT, 'Item type'),
                    'itemid' => new external_value(PARAM_INT, 'Item ID'),
                    'warningcode' => new external_value(PARAM_ALPHANUM, 'Warning code'),
                    'message' => new external_value(PARAM_RAW, 'Warning message'),
                ]),
                'Warnings',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
