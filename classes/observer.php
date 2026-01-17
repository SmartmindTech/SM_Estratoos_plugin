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
 * Event observer for cache invalidation.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

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
        $userid = $event->relateduserid;
        $courseid = $event->courseid;
        cache_helper::invalidate_user_dashboard($userid);
        cache_helper::invalidate_course_progress($courseid);
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
}
