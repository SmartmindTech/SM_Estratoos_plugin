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
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use context_module;
use moodle_url;

/**
 * External function to retrieve comprehensive course content including SCORM, files, pages, and all educational materials.
 */
class get_course_content extends external_api {

    /**
     * Lesson page type names mapping.
     */
    private const LESSON_PAGE_TYPES = [
        0 => 'unknown',
        1 => 'shortanswer',
        2 => 'truefalse',
        3 => 'multichoice',
        5 => 'matching',
        8 => 'numerical',
        10 => 'essay',
        20 => 'content',
        21 => 'endofbranch',
        30 => 'cluster',
        31 => 'endofcluster',
    ];

    /**
     * Calculate progress percentage (0-100) for a module based on its type and user data.
     *
     * @param string $modname Module type name.
     * @param array $moduledata Module data array.
     * @param int $completionstate Completion state from course_modules_completion.
     * @return int Progress percentage (0-100).
     */
    private static function calculate_module_progress(string $modname, array $moduledata, int $completionstate): int {
        switch ($modname) {
            case 'scorm':
                // Use score percentage if available.
                if (!empty($moduledata['score']) && !empty($moduledata['maxgrade']) && $moduledata['maxgrade'] > 0) {
                    return min(100, (int) round(($moduledata['score'] / $moduledata['maxgrade']) * 100));
                }
                // Fall back to completion state.
                return $completionstate > 0 ? 100 : 0;

            case 'quiz':
                // Use best grade percentage.
                if (!empty($moduledata['bestgrade']) && !empty($moduledata['grade']) && $moduledata['grade'] > 0) {
                    return min(100, (int) round(($moduledata['bestgrade'] / $moduledata['grade']) * 100));
                }
                return $completionstate > 0 ? 100 : 0;

            case 'assign':
                // 100 if submitted, 0 if not.
                return !empty($moduledata['submitted']) ? 100 : 0;

            case 'lesson':
                // Use pages viewed percentage.
                if (!empty($moduledata['pagecount']) && $moduledata['pagecount'] > 0) {
                    $pagesviewed = !empty($moduledata['pagesviewed']) ? count($moduledata['pagesviewed']) : 0;
                    return min(100, (int) round(($pagesviewed / $moduledata['pagecount']) * 100));
                }
                return $completionstate > 0 ? 100 : 0;

            case 'book':
                // Use chapters viewed percentage.
                $totalchapters = !empty($moduledata['chapters']) ? count($moduledata['chapters']) : 0;
                if ($totalchapters > 0 && !empty($moduledata['chaptersviewed'])) {
                    return min(100, (int) round((count($moduledata['chaptersviewed']) / $totalchapters) * 100));
                }
                return $completionstate > 0 ? 100 : 0;

            default:
                // For page, resource, URL, folder, etc. - use completion state.
                return $completionstate > 0 ? 100 : 0;
        }
    }

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
                'includescormdetails' => new external_value(PARAM_BOOL, 'Include SCORM SCO details and content files', VALUE_DEFAULT, true),
                'includefilecontents' => new external_value(PARAM_BOOL, 'Include file content URLs', VALUE_DEFAULT, true),
                'includepagecontent' => new external_value(PARAM_BOOL, 'Include page HTML content', VALUE_DEFAULT, true),
                'includequizquestions' => new external_value(PARAM_BOOL, 'Include quiz questions and answers', VALUE_DEFAULT, true),
                'includeassignmentdetails' => new external_value(PARAM_BOOL, 'Include assignment submission details', VALUE_DEFAULT, true),
                'includelessonpages' => new external_value(PARAM_BOOL, 'Include lesson pages with content', VALUE_DEFAULT, true),
                'includeuserdata' => new external_value(PARAM_BOOL, 'Include user progress/attempts data', VALUE_DEFAULT, true),
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
        global $DB, $CFG, $USER;

        // DEBUG: Return immediately to test if error is in our code at all.
        return ['courses' => [], 'warnings' => [['item' => 'debug', 'itemid' => 0, 'warningcode' => 'debug', 'message' => 'DEBUG v1.7.65 - minimal test']]];

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
            'includequizquestions' => true,
            'includeassignmentdetails' => true,
            'includelessonpages' => true,
            'includeuserdata' => true,
        ], $params['options']);

        $warnings = [];
        $courses = [];

        // TEMPORARILY DISABLED FOR DEBUGGING - checking if filtering causes the error.
        // Apply company filtering if IOMAD token.
        // NOTE: We use a union of:
        //   1. Courses in company_course table (IOMAD course assignment)
        //   2. Courses in company's category hierarchy (fallback for courses not created via IOMAD)
        // This allows access to courses in the company's category even if not explicitly
        // assigned in IOMAD (e.g., course created in SmartMind category but not via IOMAD course creator).
        /*
        if (\local_sm_estratoos_plugin\util::is_iomad_installed()) {
            $token = \local_sm_estratoos_plugin\util::get_current_request_token();
            if ($token) {
                $restrictions = \local_sm_estratoos_plugin\company_token_manager::get_token_restrictions($token);
                if ($restrictions && !empty($restrictions->companyid) && $restrictions->restricttocompany) {
                    $filter = new \local_sm_estratoos_plugin\webservice_filter($restrictions);

                    // Get company courses from company_course table.
                    $companycourses = $filter->get_company_course_ids();

                    // Get courses in company's category hierarchy (fallback).
                    $companycategoryids = $filter->get_company_category_ids();
                    $categorycourses = [];
                    if (!empty($companycategoryids)) {
                        list($insql, $params) = $DB->get_in_or_equal($companycategoryids, SQL_PARAMS_NAMED);
                        $categorycourses = $DB->get_fieldset_select('course', 'id', "category $insql", $params);
                    }

                    // Union: company_course table OR courses in company category.
                    $allowedcourses = array_unique(array_merge($companycourses, $categorycourses));

                    if (!empty($allowedcourses)) {
                        $courseids = array_intersect($courseids, $allowedcourses);
                    }
                }
            }
        }
        */

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

                            $moduledata = self::get_module_data($cm, $context, $options, $USER->id);
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
     * @param int $userid Current user ID.
     * @return array|null Module data or null if error.
     */
    private static function get_module_data(\cm_info $cm, \context_course $context, array $options, int $userid): ?array {
        global $DB, $CFG;

        $modulecontext = context_module::instance($cm->id, IGNORE_MISSING);
        if (!$modulecontext) {
            return null;
        }

        // Get the module instance from DB for format_module_intro.
        $moduleinstance = $DB->get_record($cm->modname, ['id' => $cm->instance]);

        // Get description/intro.
        $description = '';
        if ($moduleinstance && isset($moduleinstance->intro)) {
            $description = format_module_intro($cm->modname, $moduleinstance, $cm->id, false);
        }

        $moduledata = [
            'id' => $cm->id,
            'name' => $cm->name,
            'modname' => $cm->modname,
            'instance' => $cm->instance,
            'visible' => $cm->visible ? true : false,
            'uservisible' => $cm->uservisible ? true : false,
            'description' => $description,
            'completion' => $cm->completion,
            'completionstate' => 0,
            'url' => $cm->url ? $cm->url->out(false) : '',
            'contents' => [],
        ];

        // Get completion state if available.
        if ($cm->completion != COMPLETION_TRACKING_NONE) {
            $course = $DB->get_record('course', ['id' => $cm->course]);
            if ($course) {
                $completion = new \completion_info($course);
                $completiondata = $completion->get_data($cm);
                $moduledata['completionstate'] = $completiondata->completionstate ?? 0;
            }
        }

        // Get module-specific content based on type.
        switch ($cm->modname) {
            case 'scorm':
                if ($options['includescormdetails']) {
                    $moduledata['scorm'] = self::get_scorm_data($cm->instance, $modulecontext, $userid, $options['includeuserdata']);
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
                if ($options['includeassignmentdetails']) {
                    $moduledata['assignment'] = self::get_assignment_data($cm->instance, $modulecontext, $userid, $options['includeuserdata']);
                } else {
                    $moduledata['assignment'] = self::get_assignment_basic($cm->instance);
                }
                break;

            case 'quiz':
                if ($options['includequizquestions']) {
                    $moduledata['quiz'] = self::get_quiz_data($cm->instance, $modulecontext, $userid, $options['includeuserdata']);
                } else {
                    $moduledata['quiz'] = self::get_quiz_basic($cm->instance);
                }
                break;

            case 'forum':
                $moduledata['forum'] = self::get_forum_data($cm->instance);
                break;

            case 'book':
                if ($options['includepagecontent']) {
                    $moduledata['book'] = self::get_book_data($cm->instance, $modulecontext, $userid, $options['includeuserdata']);
                }
                break;

            case 'lesson':
                if ($options['includelessonpages']) {
                    $moduledata['lesson'] = self::get_lesson_data($cm->instance, $modulecontext, $userid, $options['includeuserdata']);
                } else {
                    $moduledata['lesson'] = self::get_lesson_basic($cm->instance);
                }
                break;

            default:
                // For other module types, try to get any associated files.
                if ($options['includefilecontents']) {
                    $moduledata['contents'] = self::get_generic_module_files($cm, $modulecontext);
                }
                break;
        }

        // Calculate progress based on module type and data (before JSON encoding).
        $progressdata = [];
        $jsonfields = ['scorm', 'quiz', 'assignment', 'forum', 'book', 'lesson'];
        foreach ($jsonfields as $field) {
            if (isset($moduledata[$field]) && is_array($moduledata[$field])) {
                $progressdata = $moduledata[$field];
                break;
            }
        }
        $moduledata['progress'] = self::calculate_module_progress($cm->modname, $progressdata, $moduledata['completionstate']);

        // JSON encode complex module data fields (return structure expects PARAM_RAW strings).
        foreach ($jsonfields as $field) {
            if (isset($moduledata[$field]) && is_array($moduledata[$field])) {
                $moduledata[$field] = json_encode($moduledata[$field]);
            }
        }

        return $moduledata;
    }

    // ========================================
    // SCORM DATA - Enhanced for external player
    // ========================================

    /**
     * Get SCORM package data including content files for external player.
     *
     * @param int $scormid SCORM instance ID.
     * @param \context_module $context Module context.
     * @param int $userid User ID for tracking data.
     * @param bool $includeuserdata Include user tracking data.
     * @return array SCORM data.
     */
    private static function get_scorm_data(int $scormid, \context_module $context, int $userid, bool $includeuserdata): array {
        global $DB, $CFG;

        $scorm = $DB->get_record('scorm', ['id' => $scormid]);
        if (!$scorm) {
            return [];
        }

        $data = [
            'id' => $scorm->id,
            'name' => $scorm->name,
            'intro' => format_text($scorm->intro ?? '', $scorm->introformat ?? FORMAT_HTML, ['context' => $context]),
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
            'contentfiles' => [],
            'userdata' => [],
            // Enhanced fields for SmartLearning.
            'score' => null,
            'attempts' => 0,
            'slidescount' => 0,
            'currentslide' => null,
        ];

        // Get launch URL (Moodle player).
        if (!empty($scorm->launch)) {
            $data['launch'] = $CFG->wwwroot . '/mod/scorm/player.php?scoid=' . $scorm->launch . '&cm=' . $context->instanceid;
        }

        // Get package file URL.
        $fs = get_file_storage();
        $packagefiles = $fs->get_area_files($context->id, 'mod_scorm', 'package', 0, 'sortorder', false);
        foreach ($packagefiles as $file) {
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

        // Get all SCORM content files (extracted package contents).
        $contentfiles = $fs->get_area_files($context->id, 'mod_scorm', 'content', 0, 'sortorder', false);
        foreach ($contentfiles as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $data['contentfiles'][] = [
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
            ];
        }

        // Get SCOs (Sharable Content Objects).
        $scos = $DB->get_records('scorm_scoes', ['scorm' => $scormid], 'sortorder, id');
        $currentslide = null;
        $bestscore = null;
        $slidescount = 0;

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

            // Count launchable SCOs.
            if (!empty($sco->scormtype) && $sco->scormtype === 'sco' && !empty($sco->launch)) {
                $slidescount++;
            }

            // Get additional SCO data.
            $scoextradata = $DB->get_records('scorm_scoes_data', ['scoid' => $sco->id]);
            foreach ($scoextradata as $extra) {
                $scodata[$extra->name] = $extra->value;
            }

            // Get user's tracking data for this SCO.
            if ($includeuserdata && !empty($sco->scormtype) && $sco->scormtype === 'sco') {
                $scodata['usertrack'] = self::get_scorm_user_track($sco->id, $userid, $scormid);

                // Extract score and current location from user track.
                if (!empty($scodata['usertrack']['tracks'])) {
                    $tracks = $scodata['usertrack']['tracks'];

                    // Get score.
                    foreach (['cmi.core.score.raw', 'cmi.score.raw'] as $scorekey) {
                        if (isset($tracks[$scorekey]) && $tracks[$scorekey] !== '') {
                            $scorevalue = (float) $tracks[$scorekey];
                            if ($bestscore === null || $scorevalue > $bestscore) {
                                $bestscore = $scorevalue;
                            }
                        }
                    }

                    // Get current slide/location.
                    foreach (['cmi.core.lesson_location', 'cmi.location'] as $lockey) {
                        if (isset($tracks[$lockey]) && $tracks[$lockey] !== '') {
                            $currentslide = $sco->id;
                            break;
                        }
                    }
                }
            }

            $data['scos'][] = $scodata;
        }

        // Set enhanced fields.
        $data['slidescount'] = $slidescount;

        // Get overall user data.
        if ($includeuserdata) {
            $data['userdata'] = self::get_scorm_user_data($scormid, $userid);
            $data['score'] = $bestscore ?? ($data['userdata']['grade'] ?? null);
            $data['attempts'] = $data['userdata']['attemptcount'] ?? 0;
            $data['currentslide'] = $currentslide;
        }

        return $data;
    }

    /**
     * Get SCORM user tracking data for a SCO.
     *
     * @param int $scoid SCO ID.
     * @param int $userid User ID.
     * @param int $scormid SCORM ID.
     * @return array Tracking data.
     */
    private static function get_scorm_user_track(int $scoid, int $userid, int $scormid): array {
        global $DB;

        $tracks = [];
        $attempt = 0;

        try {
            // Get the latest attempt.
            $attempt = $DB->get_field('scorm_scoes_track', 'MAX(attempt)', [
                'scormid' => $scormid,
                'userid' => $userid,
                'scoid' => $scoid,
            ]);

            if ($attempt) {
                $trackrecords = $DB->get_records('scorm_scoes_track', [
                    'scormid' => $scormid,
                    'userid' => $userid,
                    'scoid' => $scoid,
                    'attempt' => $attempt,
                ]);

                foreach ($trackrecords as $track) {
                    $tracks[$track->element] = $track->value;
                }
            }
        } catch (\Exception $e) {
            // Table may not exist if SCORM tracking not enabled.
            $attempt = 0;
        }

        return [
            'attempt' => $attempt ?? 0,
            'tracks' => $tracks,
        ];
    }

    /**
     * Get overall SCORM user data.
     *
     * @param int $scormid SCORM ID.
     * @param int $userid User ID.
     * @return array User data.
     */
    private static function get_scorm_user_data(int $scormid, int $userid): array {
        global $DB;

        $attemptcount = 0;
        $grade = null;

        try {
            // Get number of attempts.
            $attemptcount = $DB->get_field_sql(
                "SELECT MAX(attempt) FROM {scorm_scoes_track} WHERE scormid = ? AND userid = ?",
                [$scormid, $userid]
            );

            // Get grades.
            $grades = $DB->get_records('scorm_scoes_track', [
                'scormid' => $scormid,
                'userid' => $userid,
                'element' => 'cmi.core.score.raw',
            ], 'attempt DESC', '*', 0, 1);

            $grade = !empty($grades) ? reset($grades)->value : null;
        } catch (\Exception $e) {
            // Table may not exist if SCORM tracking not enabled.
        }

        return [
            'attemptcount' => (int) ($attemptcount ?? 0),
            'grade' => $grade,
        ];
    }

    // ========================================
    // QUIZ DATA - Enhanced with questions
    // ========================================

    /**
     * Get basic quiz data (without questions).
     *
     * @param int $quizid Quiz instance ID.
     * @return array Quiz data.
     */
    private static function get_quiz_basic(int $quizid): array {
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
     * Get comprehensive quiz data including questions and user attempts.
     *
     * @param int $quizid Quiz instance ID.
     * @param \context_module $context Module context.
     * @param int $userid User ID.
     * @param bool $includeuserdata Include user attempts.
     * @return array Quiz data.
     */
    private static function get_quiz_data(int $quizid, \context_module $context, int $userid, bool $includeuserdata): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return [];
        }

        $data = [
            'id' => $quiz->id,
            'name' => $quiz->name,
            'intro' => format_text($quiz->intro ?? '', $quiz->introformat ?? FORMAT_HTML, ['context' => $context]),
            'timeopen' => $quiz->timeopen,
            'timeclose' => $quiz->timeclose,
            'timelimit' => $quiz->timelimit,
            'grade' => (float) $quiz->grade,
            'attempts' => $quiz->attempts,
            'grademethod' => $quiz->grademethod,
            'sumgrades' => (float) ($quiz->sumgrades ?? 0),
            'questionsperpage' => $quiz->questionsperpage ?? 0,
            'shuffleanswers' => $quiz->shuffleanswers ?? 0,
            'preferredbehaviour' => $quiz->preferredbehaviour ?? 'deferredfeedback',
            'questions' => [],
            'userattempts' => [],
            // Enhanced fields for SmartLearning.
            'bestgrade' => null,
            'lastgrade' => null,
            'gradepercent' => null,
            'canreattempt' => true,
            'attemptcount' => 0,
        ];

        // Get quiz questions.
        try {
            $data['questions'] = self::get_quiz_questions($quiz, $context);
        } catch (\Exception $e) {
            // If we can't get questions, return basic data.
            $data['questionserror'] = $e->getMessage();
        }

        // Get user attempts and calculate enhanced fields.
        if ($includeuserdata) {
            $data['userattempts'] = self::get_quiz_user_attempts($quiz, $userid);

            // Calculate best grade, last grade, and attempt count.
            $attemptcount = count($data['userattempts']);
            $data['attemptcount'] = $attemptcount;

            if (!empty($data['userattempts'])) {
                $bestgrade = null;
                $lastgrade = null;

                foreach ($data['userattempts'] as $attempt) {
                    if ($attempt['state'] === 'finished' && $attempt['grade'] !== null) {
                        // Best grade.
                        if ($bestgrade === null || $attempt['grade'] > $bestgrade) {
                            $bestgrade = $attempt['grade'];
                        }
                    }
                }

                // Last grade is from first attempt in list (already sorted by attempt DESC).
                foreach ($data['userattempts'] as $attempt) {
                    if ($attempt['state'] === 'finished' && $attempt['grade'] !== null) {
                        $lastgrade = $attempt['grade'];
                        break;
                    }
                }

                $data['bestgrade'] = $bestgrade;
                $data['lastgrade'] = $lastgrade;

                // Calculate grade percent (using best grade).
                if ($bestgrade !== null && $quiz->grade > 0) {
                    $data['gradepercent'] = round(($bestgrade / $quiz->grade) * 100, 2);
                }
            }

            // Can reattempt: unlimited (0) or less than max attempts.
            $data['canreattempt'] = ($quiz->attempts == 0) || ($attemptcount < $quiz->attempts);
        }

        return $data;
    }

    /**
     * Get quiz questions with answers.
     *
     * @param object $quiz Quiz record.
     * @param \context_module $context Module context.
     * @return array Questions data.
     */
    private static function get_quiz_questions(object $quiz, \context_module $context): array {
        global $DB, $CFG;

        $questions = [];

        // Get quiz slots (question positions).
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');

        foreach ($slots as $slot) {
            $question = null;

            // Try old method first (Moodle < 4.0) - direct questionid in slot.
            if (!empty($slot->questionid)) {
                $question = $DB->get_record('question', ['id' => $slot->questionid]);
            }

            // New method (Moodle 4.0+) - use question_references table.
            if (!$question) {
                // Get question reference for this slot.
                $qref = $DB->get_record('question_references', [
                    'component' => 'mod_quiz',
                    'questionarea' => 'slot',
                    'itemid' => $slot->id,
                ]);

                if ($qref) {
                    // Get the question bank entry.
                    $qbe = $DB->get_record('question_bank_entries', ['id' => $qref->questionbankentryid]);
                    if ($qbe) {
                        // Get the latest version of the question.
                        $qversion = $DB->get_record_sql(
                            "SELECT qv.* FROM {question_versions} qv
                             WHERE qv.questionbankentryid = ?
                             ORDER BY qv.version DESC LIMIT 1",
                            [$qbe->id]
                        );
                        if ($qversion) {
                            $question = $DB->get_record('question', ['id' => $qversion->questionid]);
                        }
                    }
                }
            }

            if (!$question) {
                continue;
            }

            $qdata = [
                'id' => $question->id,
                'slot' => $slot->slot,
                'page' => $slot->page,
                'name' => $question->name,
                'questiontext' => format_text($question->questiontext, $question->questiontextformat, ['context' => $context]),
                'questiontextformat' => $question->questiontextformat,
                'qtype' => $question->qtype,
                'defaultmark' => (float) $slot->maxmark,
                'answers' => [],
            ];

            // Get answers based on question type.
            switch ($question->qtype) {
                case 'multichoice':
                case 'truefalse':
                    $qdata['answers'] = self::get_question_answers($question->id, $context);
                    // Get multichoice options.
                    $mcoptions = $DB->get_record('qtype_multichoice_options', ['questionid' => $question->id]);
                    if ($mcoptions) {
                        $qdata['single'] = $mcoptions->single ? true : false;
                        $qdata['shuffleanswers'] = $mcoptions->shuffleanswers ? true : false;
                    }
                    break;

                case 'match':
                    $qdata['subquestions'] = self::get_match_subquestions($question->id, $context);
                    break;

                case 'shortanswer':
                case 'numerical':
                    $qdata['answers'] = self::get_question_answers($question->id, $context);
                    break;

                case 'essay':
                    $essayoptions = $DB->get_record('qtype_essay_options', ['questionid' => $question->id]);
                    if ($essayoptions) {
                        $qdata['responseformat'] = $essayoptions->responseformat;
                        $qdata['responserequired'] = $essayoptions->responserequired;
                        $qdata['responsefieldlines'] = $essayoptions->responsefieldlines;
                        $qdata['attachments'] = $essayoptions->attachments;
                        $qdata['attachmentsrequired'] = $essayoptions->attachmentsrequired;
                    }
                    break;
            }

            $questions[] = $qdata;
        }

        return $questions;
    }

    /**
     * Get question answers.
     *
     * @param int $questionid Question ID.
     * @param \context_module $context Context.
     * @return array Answers.
     */
    private static function get_question_answers(int $questionid, \context_module $context): array {
        global $DB;

        $answers = [];
        $answerrecords = $DB->get_records('question_answers', ['question' => $questionid], 'id');

        foreach ($answerrecords as $answer) {
            $answers[] = [
                'id' => $answer->id,
                'answer' => format_text($answer->answer, $answer->answerformat, ['context' => $context]),
                'answerformat' => $answer->answerformat,
                'fraction' => (float) $answer->fraction,
                'feedback' => format_text($answer->feedback, $answer->feedbackformat, ['context' => $context]),
            ];
        }

        return $answers;
    }

    /**
     * Get match question subquestions.
     *
     * @param int $questionid Question ID.
     * @param \context_module $context Context.
     * @return array Subquestions.
     */
    private static function get_match_subquestions(int $questionid, \context_module $context): array {
        global $DB;

        $subquestions = [];
        $records = $DB->get_records('qtype_match_subquestions', ['questionid' => $questionid], 'id');

        foreach ($records as $sub) {
            $subquestions[] = [
                'id' => $sub->id,
                'questiontext' => format_text($sub->questiontext, $sub->questiontextformat, ['context' => $context]),
                'answertext' => $sub->answertext,
            ];
        }

        return $subquestions;
    }

    /**
     * Get user's quiz attempts.
     *
     * @param object $quiz Quiz record.
     * @param int $userid User ID.
     * @return array Attempts data.
     */
    private static function get_quiz_user_attempts(object $quiz, int $userid): array {
        global $DB;

        $attempts = [];
        $attemptrecords = $DB->get_records('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $userid,
        ], 'attempt DESC');

        foreach ($attemptrecords as $attempt) {
            // Calculate scaled grade.
            $grade = null;
            if ($attempt->sumgrades !== null && $quiz->sumgrades > 0) {
                $grade = round(($attempt->sumgrades / $quiz->sumgrades) * $quiz->grade, 2);
            }

            $attempts[] = [
                'id' => $attempt->id,
                'attempt' => $attempt->attempt,
                'state' => $attempt->state,
                'timestart' => $attempt->timestart,
                'timefinish' => $attempt->timefinish,
                'timemodified' => $attempt->timemodified,
                'sumgrades' => $attempt->sumgrades !== null ? (float) $attempt->sumgrades : null,
                'grade' => $grade,  // Scaled to quiz grade.
            ];
        }

        return $attempts;
    }

    // ========================================
    // ASSIGNMENT DATA - Enhanced with submission details
    // ========================================

    /**
     * Get basic assignment data.
     *
     * @param int $assignid Assignment instance ID.
     * @return array Assignment data.
     */
    private static function get_assignment_basic(int $assignid): array {
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
     * Get comprehensive assignment data with submission details.
     *
     * @param int $assignid Assignment instance ID.
     * @param \context_module $context Module context.
     * @param int $userid User ID.
     * @param bool $includeuserdata Include user submission.
     * @return array Assignment data.
     */
    private static function get_assignment_data(int $assignid, \context_module $context, int $userid, bool $includeuserdata): array {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return [];
        }

        $data = [
            'id' => $assign->id,
            'name' => $assign->name,
            'intro' => format_text($assign->intro ?? '', $assign->introformat ?? FORMAT_HTML, ['context' => $context]),
            'duedate' => $assign->duedate,
            'allowsubmissionsfromdate' => $assign->allowsubmissionsfromdate,
            'grade' => (float) $assign->grade,
            'timemodified' => $assign->timemodified,
            'cutoffdate' => $assign->cutoffdate ?? 0,
            'gradingduedate' => $assign->gradingduedate ?? 0,
            'submissiondrafts' => $assign->submissiondrafts ? true : false,
            'requiresubmissionstatement' => $assign->requiresubmissionstatement ? true : false,
            'teamsubmission' => $assign->teamsubmission ? true : false,
            'maxattempts' => $assign->maxattempts,
            'submissiontypes' => [],
            'usersubmission' => null,
            'usergrade' => null,
            // Enhanced fields for SmartLearning.
            'maxfilesubmissions' => null,
            'maxsubmissionsizebytes' => null,
            'filetypeslist' => null,
            'submitted' => false,
            'submissionstatus' => 'new',
            'graded' => false,
            'gradepercent' => null,
        ];

        // Get enabled submission types.
        $plugins = $DB->get_records('assign_plugin_config', [
            'assignment' => $assignid,
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
            'value' => '1',
        ]);

        foreach ($plugins as $plugin) {
            $typeconfig = [];
            $allconfigs = $DB->get_records('assign_plugin_config', [
                'assignment' => $assignid,
                'plugin' => $plugin->plugin,
                'subtype' => 'assignsubmission',
            ]);
            foreach ($allconfigs as $config) {
                $typeconfig[$config->name] = $config->value;
            }

            $data['submissiontypes'][] = [
                'type' => $plugin->plugin,
                'config' => $typeconfig,
            ];

            // Extract file submission settings.
            if ($plugin->plugin === 'file') {
                $data['maxfilesubmissions'] = isset($typeconfig['maxfilesubmissions']) ? (int) $typeconfig['maxfilesubmissions'] : null;
                $data['maxsubmissionsizebytes'] = isset($typeconfig['maxsubmissionsizebytes']) ? (int) $typeconfig['maxsubmissionsizebytes'] : null;
                $data['filetypeslist'] = $typeconfig['filetypeslist'] ?? null;
            }
        }

        // Get user's submission.
        if ($includeuserdata) {
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assignid,
                'userid' => $userid,
                'latest' => 1,
            ]);

            if ($submission) {
                $data['usersubmission'] = [
                    'id' => $submission->id,
                    'status' => $submission->status,
                    'attemptnumber' => $submission->attemptnumber,
                    'timecreated' => $submission->timecreated,
                    'timemodified' => $submission->timemodified,
                    'plugins' => [],
                ];

                // Get submission plugin data.
                // Online text.
                $onlinetext = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
                if ($onlinetext) {
                    $data['usersubmission']['plugins']['onlinetext'] = [
                        'text' => $onlinetext->onlinetext,
                        'format' => $onlinetext->onlineformat,
                    ];
                }

                // File submissions.
                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $submission->id, 'sortorder', false);
                if (!empty($files)) {
                    $data['usersubmission']['plugins']['file'] = [
                        'files' => self::format_files_array($files),
                    ];
                }
            }

            // Get user's grade.
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $assignid,
                'userid' => $userid,
            ], '*', IGNORE_MULTIPLE);

            if ($grade) {
                $data['usergrade'] = [
                    'id' => $grade->id,
                    'grade' => $grade->grade,
                    'attemptnumber' => $grade->attemptnumber,
                    'timecreated' => $grade->timecreated,
                    'timemodified' => $grade->timemodified,
                ];

                // Get feedback.
                $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
                if ($feedback) {
                    $data['usergrade']['feedbackcomment'] = $feedback->commenttext;
                    $data['usergrade']['feedbackformat'] = $feedback->commentformat;
                }

                // Enhanced fields: graded and gradepercent.
                $data['graded'] = ($grade->grade !== null && $grade->grade >= 0);
                if ($data['graded'] && $assign->grade > 0) {
                    $data['gradepercent'] = round(($grade->grade / $assign->grade) * 100, 2);
                }
            }

            // Enhanced fields: submitted and submissionstatus.
            if ($submission) {
                $data['submissionstatus'] = $submission->status;
                $data['submitted'] = ($submission->status === 'submitted');
            }
        }

        return $data;
    }

    // ========================================
    // LESSON DATA - Enhanced with pages and answers
    // ========================================

    /**
     * Get basic lesson data.
     *
     * @param int $lessonid Lesson instance ID.
     * @return array Lesson data.
     */
    private static function get_lesson_basic(int $lessonid): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $lessonid]);
        if (!$lesson) {
            return [];
        }

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
     * Get comprehensive lesson data including all pages.
     *
     * @param int $lessonid Lesson instance ID.
     * @param \context_module $context Module context.
     * @param int $userid User ID.
     * @param bool $includeuserdata Include user progress.
     * @return array Lesson data.
     */
    private static function get_lesson_data(int $lessonid, \context_module $context, int $userid, bool $includeuserdata): array {
        global $DB;

        $lesson = $DB->get_record('lesson', ['id' => $lessonid]);
        if (!$lesson) {
            return [];
        }

        // Count content pages (excluding cluster/endofcluster/endofbranch).
        $pagecount = $DB->count_records_select('lesson_pages',
            "lessonid = ? AND qtype NOT IN (21, 30, 31)",
            [$lessonid]
        );

        $data = [
            'id' => $lesson->id,
            'name' => $lesson->name,
            'intro' => format_text($lesson->intro ?? '', $lesson->introformat ?? FORMAT_HTML, ['context' => $context]),
            'grade' => (float) $lesson->grade,
            'timelimit' => $lesson->timelimit ?? 0,
            'maxattempts' => $lesson->maxattempts ?? 0,
            'retake' => $lesson->retake ?? 0,
            'progressbar' => $lesson->progressbar ?? 0,
            'ongoing' => $lesson->ongoing ?? 0,
            'review' => $lesson->review ?? 0,
            'timemodified' => $lesson->timemodified,
            'pagecount' => $pagecount,
            'pages' => [],
            'userprogress' => null,
            // Enhanced user progress fields (populated later if includeuserdata).
            'completed' => false,
            'currentpage' => null,
            'pagesviewed' => [],
            'bestgrade' => null,
            'lastgrade' => null,
            'timetaken' => null,
        ];

        // Get all lesson pages.
        $pages = $DB->get_records('lesson_pages', ['lessonid' => $lessonid], 'prevpageid');

        // Sort pages in order.
        $orderedpages = [];
        $currentid = 0;
        foreach ($pages as $page) {
            if ($page->prevpageid == 0) {
                $currentid = $page->id;
                break;
            }
        }

        while ($currentid != 0 && isset($pages[$currentid])) {
            $orderedpages[] = $pages[$currentid];
            $currentid = $pages[$currentid]->nextpageid;
        }

        foreach ($orderedpages as $page) {
            $pagedata = [
                'id' => $page->id,
                'title' => $page->title,
                'contents' => format_text($page->contents, $page->contentsformat, ['context' => $context]),
                'contentsformat' => $page->contentsformat,
                'qtype' => $page->qtype,
                'qtypename' => self::LESSON_PAGE_TYPES[$page->qtype] ?? 'unknown',
                'qoption' => $page->qoption,
                'layout' => $page->layout,
                'display' => $page->display,
                'prevpageid' => $page->prevpageid,
                'nextpageid' => $page->nextpageid,
                'answers' => [],
            ];

            // Get answers for question pages.
            $answers = $DB->get_records('lesson_answers', ['pageid' => $page->id], 'id');
            foreach ($answers as $answer) {
                $pagedata['answers'][] = [
                    'id' => $answer->id,
                    'answer' => format_text($answer->answer, $answer->answerformat, ['context' => $context]),
                    'answerformat' => $answer->answerformat,
                    'response' => format_text($answer->response, $answer->responseformat, ['context' => $context]),
                    'responseformat' => $answer->responseformat,
                    'jumpto' => $answer->jumpto,
                    'score' => $answer->score,
                ];
            }

            $data['pages'][] = $pagedata;
        }

        // Get user progress.
        if ($includeuserdata) {
            $userprogress = self::get_lesson_user_progress($lessonid, $userid);
            $data['userprogress'] = $userprogress;

            // Populate enhanced user progress fields.
            $data['completed'] = $userprogress['completed'] ?? false;
            $data['currentpage'] = $userprogress['currentpage'] ?? null;
            $data['pagesviewed'] = $userprogress['pagesviewed'] ?? [];
            $data['bestgrade'] = $userprogress['bestgrade'] ?? null;
            $data['lastgrade'] = $userprogress['lastgrade'] ?? null;
            $data['timetaken'] = $userprogress['timetaken'] ?? null;
        }

        return $data;
    }

    /**
     * Get user's lesson progress.
     *
     * @param int $lessonid Lesson ID.
     * @param int $userid User ID.
     * @return array Progress data.
     */
    private static function get_lesson_user_progress(int $lessonid, int $userid): array {
        global $DB;

        // Get attempts.
        $attempts = $DB->get_records('lesson_attempts', [
            'lessonid' => $lessonid,
            'userid' => $userid,
        ], 'timeseen DESC');

        $attemptdata = [];
        $pagesviewed = [];
        $currentpage = null;

        foreach ($attempts as $attempt) {
            $attemptdata[] = [
                'id' => $attempt->id,
                'pageid' => $attempt->pageid,
                'answerid' => $attempt->answerid,
                'retry' => $attempt->retry,
                'correct' => $attempt->correct,
                'timeseen' => $attempt->timeseen,
            ];

            // Track unique pages viewed.
            if (!in_array($attempt->pageid, $pagesviewed)) {
                $pagesviewed[] = $attempt->pageid;
            }

            // Track current page (most recent attempt).
            if ($currentpage === null) {
                $currentpage = $attempt->pageid;
            }
        }

        // Get grades.
        $grades = $DB->get_records('lesson_grades', [
            'lessonid' => $lessonid,
            'userid' => $userid,
        ], 'completed DESC');

        $gradedata = [];
        $bestgrade = null;
        $lastgrade = null;

        foreach ($grades as $grade) {
            $gradedata[] = [
                'id' => $grade->id,
                'grade' => (float) $grade->grade,
                'completed' => $grade->completed,
            ];

            // Track best grade.
            if ($bestgrade === null || $grade->grade > $bestgrade) {
                $bestgrade = (float) $grade->grade;
            }

            // Track last grade (first in list since sorted by completed DESC).
            if ($lastgrade === null) {
                $lastgrade = (float) $grade->grade;
            }
        }

        // Get timer (current attempt).
        $timer = $DB->get_record('lesson_timer', [
            'lessonid' => $lessonid,
            'userid' => $userid,
        ], '*', IGNORE_MULTIPLE);

        // Calculate time taken from timer.
        $timetaken = null;
        if ($timer && $timer->lessontime > 0 && $timer->starttime > 0) {
            $timetaken = $timer->lessontime - $timer->starttime;
        }

        // Determine if completed (has at least one grade or timer marked complete).
        $completed = !empty($grades) || ($timer && $timer->completed);

        return [
            'attempts' => $attemptdata,
            'grades' => $gradedata,
            'timer' => $timer ? [
                'starttime' => $timer->starttime,
                'lessontime' => $timer->lessontime,
                'completed' => $timer->completed,
            ] : null,
            // Enhanced fields.
            'completed' => $completed,
            'currentpage' => $currentpage,
            'pagesviewed' => $pagesviewed,
            'bestgrade' => $bestgrade,
            'lastgrade' => $lastgrade,
            'timetaken' => $timetaken,
        ];
    }

    // ========================================
    // FILE/RESOURCE HELPERS
    // ========================================

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
     * @param int $userid User ID.
     * @param bool $includeuserdata Include user viewing data.
     * @return array Book data.
     */
    private static function get_book_data(int $bookid, \context_module $context, int $userid, bool $includeuserdata): array {
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

        $data = [
            'id' => $book->id,
            'name' => $book->name,
            'intro' => format_text($book->intro ?? '', $book->introformat ?? FORMAT_HTML, ['context' => $context]),
            'numbering' => (int) ($book->numbering ?? 0),
            'chapters' => $chaptersdata,
            // Enhanced user tracking fields.
            'chaptersviewed' => [],
            'lastchapter' => null,
        ];

        // Get user viewing data from logs.
        if ($includeuserdata) {
            try {
                // Query the log table for chapter_viewed events.
                $logmanager = get_log_manager();
                $readers = $logmanager->get_readers('\\core\\log\\sql_reader');

                if (!empty($readers)) {
                    $reader = reset($readers);
                    $tablename = $reader->get_internal_log_table_name();

                    // Get all chapter viewed events for this user and book.
                    $sql = "SELECT DISTINCT objectid as chapterid, MAX(timecreated) as lastviewed
                            FROM {{$tablename}}
                            WHERE userid = ?
                              AND component = 'mod_book'
                              AND eventname = ?
                              AND contextid = ?
                            GROUP BY objectid
                            ORDER BY lastviewed DESC";

                    $viewedchapters = $DB->get_records_sql($sql, [
                        $userid,
                        '\\mod_book\\event\\chapter_viewed',
                        $context->id,
                    ]);

                    if (!empty($viewedchapters)) {
                        $data['chaptersviewed'] = array_keys($viewedchapters);
                        // Last chapter is the most recently viewed.
                        $data['lastchapter'] = (int) reset($viewedchapters)->chapterid;
                    }
                }
            } catch (\Exception $e) {
                // Log table might not be available, ignore errors.
            }
        }

        return $data;
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
                                self::get_module_return_structure()
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

    /**
     * Get the return structure for a module.
     *
     * @return external_single_structure
     */
    private static function get_module_return_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Course module ID'),
            'name' => new external_value(PARAM_TEXT, 'Module name'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module type'),
            'instance' => new external_value(PARAM_INT, 'Module instance ID'),
            'visible' => new external_value(PARAM_BOOL, 'Module visibility'),
            'uservisible' => new external_value(PARAM_BOOL, 'User can see module'),
            'description' => new external_value(PARAM_RAW, 'Module description'),
            'completion' => new external_value(PARAM_INT, 'Completion tracking type'),
            'completionstate' => new external_value(PARAM_INT, 'User completion state'),
            'progress' => new external_value(PARAM_INT, 'User progress percentage (0-100)'),
            'url' => new external_value(PARAM_URL, 'Module URL', VALUE_OPTIONAL),
            'contents' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHA, 'Content type'),
                    'filename' => new external_value(PARAM_FILE, 'File name'),
                    'filepath' => new external_value(PARAM_PATH, 'File path'),
                    'filesize' => new external_value(PARAM_INT, 'File size'),
                    'fileurl' => new external_value(PARAM_URL, 'File URL'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
                    'timecreated' => new external_value(PARAM_INT, 'Time created'),
                    'timemodified' => new external_value(PARAM_INT, 'Time modified'),
                    'author' => new external_value(PARAM_RAW, 'Author'),
                    'license' => new external_value(PARAM_RAW, 'License', VALUE_OPTIONAL),
                ]),
                'Contents',
                VALUE_OPTIONAL
            ),
            'scorm' => new external_value(PARAM_RAW, 'SCORM data as JSON', VALUE_OPTIONAL),
            'pagecontent' => new external_value(PARAM_RAW, 'Page HTML content', VALUE_OPTIONAL),
            'pagecontentformat' => new external_value(PARAM_INT, 'Page content format', VALUE_OPTIONAL),
            'externalurl' => new external_value(PARAM_URL, 'External URL', VALUE_OPTIONAL),
            'assignment' => new external_value(PARAM_RAW, 'Assignment data as JSON', VALUE_OPTIONAL),
            'quiz' => new external_value(PARAM_RAW, 'Quiz data as JSON', VALUE_OPTIONAL),
            'forum' => new external_value(PARAM_RAW, 'Forum data as JSON', VALUE_OPTIONAL),
            'book' => new external_value(PARAM_RAW, 'Book data as JSON', VALUE_OPTIONAL),
            'lesson' => new external_value(PARAM_RAW, 'Lesson data as JSON', VALUE_OPTIONAL),
        ]);
    }
}
