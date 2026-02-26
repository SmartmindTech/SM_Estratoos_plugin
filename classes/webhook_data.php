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
 * Data packaging helper for webhook sync events.
 *
 * Static methods that extract Moodle data in SmartLearning-ready format.
 * Supports both IOMAD (company_users/company_course) and standard Moodle.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook_data {

    // =========================================================================
    // Single-record packaging (for real-time events)
    // =========================================================================

    /**
     * Package user data for webhook.
     *
     * @param int $userid Moodle user ID.
     * @return array User data payload.
     */
    public static function package_user(int $userid): array {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
            'id, username, email, firstname, lastname, phone1, city, country, timezone, picture');

        if (!$user) {
            return ['userid' => $userid];
        }

        $profileimageurl = '';
        if ($user->picture) {
            $profileimageurl = $CFG->wwwroot . '/pluginfile.php/'
                . \context_user::instance($userid)->id . '/user/icon/boost/f1';
        }

        return [
            'userid' => (int) $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'phone1' => $user->phone1 ?? '',
            'city' => $user->city ?? '',
            'country' => $user->country ?? '',
            'timezone' => $user->timezone ?? '',
            'profileimageurl' => $profileimageurl,
        ];
    }

    /**
     * Package course data for webhook.
     *
     * @param int $courseid Moodle course ID.
     * @return array Course data payload.
     */
    public static function package_course(int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid],
            'id, fullname, shortname, summary, startdate, enddate, visible, category');

        if (!$course) {
            return ['courseid' => $courseid];
        }

        return [
            'courseid' => (int) $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'summary' => strip_tags($course->summary ?? ''),
            'startdate' => (int) $course->startdate,
            'enddate' => (int) $course->enddate,
            'visible' => (bool) $course->visible,
            'category' => (int) $course->category,
        ];
    }

    /**
     * Package enrollment data for webhook.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @return array Enrollment data payload.
     */
    public static function package_enrollment(int $userid, int $courseid): array {
        global $DB;

        $data = [
            'userid' => $userid,
            'courseid' => $courseid,
            'role_shortname' => '',
            'status' => 0,
            'timestart' => 0,
            'timeend' => 0,
        ];

        // Get enrollment info.
        $sql = "SELECT ue.status, ue.timestart, ue.timeend
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                WHERE ue.userid = :userid
                ORDER BY ue.timemodified DESC";
        $enrolment = $DB->get_record_sql($sql, ['userid' => $userid, 'courseid' => $courseid], IGNORE_MULTIPLE);

        if ($enrolment) {
            $data['status'] = (int) $enrolment->status;
            $data['timestart'] = (int) $enrolment->timestart;
            $data['timeend'] = (int) $enrolment->timeend;
        }

        // Get primary role.
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if ($context) {
            $roles = get_user_roles($context, $userid, false);
            if (!empty($roles)) {
                $role = reset($roles);
                $data['role_shortname'] = $role->shortname;
            }
        }

        // Add basic user info for convenience.
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
            'username, firstname, lastname, email');
        if ($user) {
            $data['username'] = $user->username;
            $data['fullname'] = trim($user->firstname . ' ' . $user->lastname);
            $data['email'] = $user->email;
        }

        return $data;
    }

    /**
     * Package grade data for webhook.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @return array Grade data payload.
     */
    public static function package_grade(int $userid, int $courseid, int $cmid): array {
        global $DB;

        $data = [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modname' => '',
            'grade' => null,
            'maxgrade' => null,
        ];

        // Get module info.
        $cm = $DB->get_record_sql(
            "SELECT cm.instance, m.name as modname
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module
             WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );

        if (!$cm) {
            return $data;
        }

        $data['modname'] = $cm->modname;

        // Get grade from grade_grades via grade_items.
        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $courseid,
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);

        if ($gradeitem) {
            $grade = $DB->get_record('grade_grades', [
                'itemid' => $gradeitem->id,
                'userid' => $userid,
            ]);
            if ($grade && $grade->finalgrade !== null) {
                $data['grade'] = round((float) $grade->finalgrade, 5);
                $data['maxgrade'] = round((float) $gradeitem->grademax, 5);
            }
        }

        return $data;
    }

    /**
     * Package completion data for webhook.
     *
     * @param int $userid User ID.
     * @param int $cmid Course module ID.
     * @param int $courseid Course ID.
     * @return array Completion data payload.
     */
    public static function package_completion(int $userid, int $cmid, int $courseid): array {
        global $DB;

        $data = [
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'modname' => '',
            'completionstate' => 0,
        ];

        // Get module name.
        $cm = $DB->get_record_sql(
            "SELECT m.name as modname
             FROM {course_modules} cm
             JOIN {modules} m ON m.id = cm.module
             WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );
        if ($cm) {
            $data['modname'] = $cm->modname;
        }

        // Get completion state.
        $completion = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
        ]);
        if ($completion) {
            $data['completionstate'] = (int) $completion->completionstate;
        }

        return $data;
    }

    /**
     * Package calendar event data for webhook.
     *
     * @param int $eventid Calendar event ID.
     * @return array Calendar event data payload.
     */
    public static function package_calendar_event(int $eventid): array {
        global $DB;

        $event = $DB->get_record('event', ['id' => $eventid],
            'id, name, description, courseid, userid, timestart, timeduration, eventtype');

        if (!$event) {
            return ['eventid' => $eventid];
        }

        return [
            'eventid' => (int) $event->id,
            'name' => $event->name,
            'description' => strip_tags($event->description ?? ''),
            'courseid' => (int) $event->courseid,
            'userid' => (int) $event->userid,
            'timestart' => (int) $event->timestart,
            'timeduration' => (int) $event->timeduration,
            'eventtype' => $event->eventtype,
        ];
    }

    // =========================================================================
    // Bulk packaging (for initial sync, paginated)
    // =========================================================================

    /**
     * Get all users for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_users(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            $userids = $DB->get_fieldset_select('company_users', 'userid', 'companyid = ?', [$companyid]);
        } else {
            $userids = $DB->get_fieldset_select('user', 'id', 'deleted = 0 AND id > 1');
        }

        $total = count($userids);
        $totalpages = (int) ceil($total / $perpage);
        $pageids = array_slice($userids, $page * $perpage, $perpage);

        $records = [];
        foreach ($pageids as $uid) {
            $records[] = self::package_user((int) $uid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    /**
     * Get all courses for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_courses(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            $courseids = $DB->get_fieldset_select('company_course', 'courseid', 'companyid = ?', [$companyid]);
        } else {
            // All visible courses except site course (id=1).
            $courseids = $DB->get_fieldset_select('course', 'id', 'id > 1');
        }

        $total = count($courseids);
        $totalpages = (int) ceil($total / $perpage);
        $pageids = array_slice($courseids, $page * $perpage, $perpage);

        $records = [];
        foreach ($pageids as $cid) {
            $records[] = self::package_course((int) $cid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    /**
     * Get all enrollments for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_enrollments(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            // Get enrollments for company users in company courses.
            $sql = "SELECT DISTINCT ue.userid, e.courseid
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {company_users} cu ON cu.userid = ue.userid AND cu.companyid = :companyid1
                    JOIN {company_course} cc ON cc.courseid = e.courseid AND cc.companyid = :companyid2
                    ORDER BY ue.userid, e.courseid";
            $enrollments = $DB->get_records_sql($sql, [
                'companyid1' => $companyid,
                'companyid2' => $companyid,
            ]);
        } else {
            $sql = "SELECT DISTINCT ue.userid, e.courseid
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE e.courseid > 1
                    ORDER BY ue.userid, e.courseid";
            $enrollments = $DB->get_records_sql($sql);
        }

        $enrollments = array_values($enrollments);
        $total = count($enrollments);
        $totalpages = (int) ceil($total / $perpage);
        $pageenrollments = array_slice($enrollments, $page * $perpage, $perpage);

        $records = [];
        foreach ($pageenrollments as $enr) {
            $records[] = self::package_enrollment((int) $enr->userid, (int) $enr->courseid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    /**
     * Get all grades for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_grades(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            $sql = "SELECT gg.id, gg.userid, gi.courseid, cm.id as cmid
                    FROM {grade_grades} gg
                    JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'mod'
                    JOIN {course_modules} cm ON cm.course = gi.courseid
                        AND cm.instance = gi.iteminstance
                    JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
                    JOIN {company_users} cu ON cu.userid = gg.userid AND cu.companyid = :companyid1
                    JOIN {company_course} cc ON cc.courseid = gi.courseid AND cc.companyid = :companyid2
                    WHERE gg.finalgrade IS NOT NULL
                    ORDER BY gg.id";
            $grades = $DB->get_records_sql($sql, [
                'companyid1' => $companyid,
                'companyid2' => $companyid,
            ]);
        } else {
            $sql = "SELECT gg.id, gg.userid, gi.courseid, cm.id as cmid
                    FROM {grade_grades} gg
                    JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'mod'
                    JOIN {course_modules} cm ON cm.course = gi.courseid
                        AND cm.instance = gi.iteminstance
                    JOIN {modules} m ON m.id = cm.module AND m.name = gi.itemmodule
                    WHERE gg.finalgrade IS NOT NULL AND gi.courseid > 1
                    ORDER BY gg.id";
            $grades = $DB->get_records_sql($sql);
        }

        $grades = array_values($grades);
        $total = count($grades);
        $totalpages = (int) ceil($total / $perpage);
        $pagegrades = array_slice($grades, $page * $perpage, $perpage);

        $records = [];
        foreach ($pagegrades as $g) {
            $records[] = self::package_grade((int) $g->userid, (int) $g->courseid, (int) $g->cmid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    /**
     * Get all completions for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_completions(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            $sql = "SELECT cmc.id, cmc.userid, cmc.coursemoduleid as cmid, cm.course as courseid
                    FROM {course_modules_completion} cmc
                    JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                    JOIN {company_users} cu ON cu.userid = cmc.userid AND cu.companyid = :companyid1
                    JOIN {company_course} cc ON cc.courseid = cm.course AND cc.companyid = :companyid2
                    ORDER BY cmc.id";
            $completions = $DB->get_records_sql($sql, [
                'companyid1' => $companyid,
                'companyid2' => $companyid,
            ]);
        } else {
            $sql = "SELECT cmc.id, cmc.userid, cmc.coursemoduleid as cmid, cm.course as courseid
                    FROM {course_modules_completion} cmc
                    JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                    WHERE cm.course > 1
                    ORDER BY cmc.id";
            $completions = $DB->get_records_sql($sql);
        }

        $completions = array_values($completions);
        $total = count($completions);
        $totalpages = (int) ceil($total / $perpage);
        $pagecompletions = array_slice($completions, $page * $perpage, $perpage);

        $records = [];
        foreach ($pagecompletions as $c) {
            $records[] = self::package_completion((int) $c->userid, (int) $c->cmid, (int) $c->courseid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    /**
     * Get all calendar events for a company, paginated.
     *
     * @param int $companyid Company ID (0 for standard Moodle).
     * @param int $page Page number (0-based).
     * @param int $perpage Records per page.
     * @return array {records: array, page: int, total_pages: int, total_records: int}
     */
    public static function get_all_calendar_events(int $companyid, int $page = 0, int $perpage = 100): array {
        global $DB;

        if ($companyid > 0 && util::is_iomad_installed()) {
            // Course events for company courses + user events for company users.
            $sql = "SELECT DISTINCT e.id
                    FROM {event} e
                    LEFT JOIN {company_course} cc ON cc.courseid = e.courseid AND cc.companyid = :companyid1
                    LEFT JOIN {company_users} cu ON cu.userid = e.userid AND cu.companyid = :companyid2
                    WHERE (e.courseid > 0 AND cc.id IS NOT NULL)
                       OR (e.userid > 0 AND cu.id IS NOT NULL AND e.eventtype = 'user')
                       OR e.eventtype = 'site'
                    ORDER BY e.id";
            $eventids = $DB->get_fieldset_sql($sql, [
                'companyid1' => $companyid,
                'companyid2' => $companyid,
            ]);
        } else {
            $eventids = $DB->get_fieldset_select('event', 'id', '1=1 ORDER BY id');
        }

        $total = count($eventids);
        $totalpages = (int) ceil($total / $perpage);
        $pageids = array_slice($eventids, $page * $perpage, $perpage);

        $records = [];
        foreach ($pageids as $eid) {
            $records[] = self::package_calendar_event((int) $eid);
        }

        return [
            'records' => $records,
            'page' => $page,
            'total_pages' => $totalpages,
            'total_records' => $total,
        ];
    }

    // =========================================================================
    // Company resolution helpers
    // =========================================================================

    /**
     * Resolve which company IDs a user belongs to.
     * For IOMAD: queries company_users table.
     * For standard Moodle: returns [0].
     *
     * @param int $userid User ID.
     * @return array Array of company IDs.
     */
    public static function resolve_company_ids_for_user(int $userid): array {
        global $DB;

        if (!util::is_iomad_installed()) {
            return [0];
        }

        try {
            $companyids = $DB->get_fieldset_select('company_users', 'companyid',
                'userid = ?', [$userid]);
            return !empty($companyids) ? $companyids : [0];
        } catch (\Exception $e) {
            return [0];
        }
    }

    /**
     * Resolve which company IDs a course belongs to.
     * For IOMAD: queries company_course table.
     * For standard Moodle: returns [0].
     *
     * @param int $courseid Course ID.
     * @return array Array of company IDs.
     */
    public static function resolve_company_ids_for_course(int $courseid): array {
        global $DB;

        if (!util::is_iomad_installed()) {
            return [0];
        }

        try {
            $companyids = $DB->get_fieldset_select('company_course', 'companyid',
                'courseid = ?', [$courseid]);
            return !empty($companyids) ? $companyids : [0];
        } catch (\Exception $e) {
            return [0];
        }
    }

    /**
     * Resolve company IDs for a calendar event (via course or user).
     *
     * @param int $eventid Calendar event ID.
     * @return array Array of company IDs.
     */
    public static function resolve_company_ids_for_event(int $eventid): array {
        global $DB;

        $event = $DB->get_record('event', ['id' => $eventid], 'courseid, userid, eventtype');
        if (!$event) {
            return [0];
        }

        // Course events → resolve via course.
        if ($event->courseid > 0) {
            return self::resolve_company_ids_for_course($event->courseid);
        }

        // User events → resolve via user.
        if ($event->userid > 0) {
            return self::resolve_company_ids_for_user($event->userid);
        }

        // Site events → all companies.
        return [0];
    }
}
