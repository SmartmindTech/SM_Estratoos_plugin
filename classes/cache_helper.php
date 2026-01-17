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

use cache;

/**
 * Helper class for cache operations.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cache_helper {

    /**
     * Invalidate company users cache.
     *
     * @param int $companyid Company ID (0 to purge all)
     */
    public static function invalidate_company_users(int $companyid = 0): void {
        try {
            $cache = cache::make('local_sm_estratoos_plugin', 'company_users');
            // Purge all company users cache since we can't target specific keys.
            $cache->purge();
        } catch (\Exception $e) {
            // Cache may not be configured yet during install.
            debugging('cache_helper: Unable to invalidate company_users cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Invalidate user dashboard cache.
     *
     * @param int $userid User ID (0 to purge all)
     */
    public static function invalidate_user_dashboard(int $userid = 0): void {
        try {
            $cache = cache::make('local_sm_estratoos_plugin', 'user_dashboard');
            // Purge session cache.
            $cache->purge();
        } catch (\Exception $e) {
            debugging('cache_helper: Unable to invalidate user_dashboard cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Invalidate course progress cache.
     *
     * @param int $courseid Course ID (0 to purge all)
     */
    public static function invalidate_course_progress(int $courseid = 0): void {
        try {
            $cache = cache::make('local_sm_estratoos_plugin', 'course_progress');
            $cache->purge();
        } catch (\Exception $e) {
            debugging('cache_helper: Unable to invalidate course_progress cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Invalidate health summary cache.
     *
     * @param int $userid User ID (0 to purge all)
     */
    public static function invalidate_health_summary(int $userid = 0): void {
        try {
            $cache = cache::make('local_sm_estratoos_plugin', 'health_summary');
            $cache->purge();
        } catch (\Exception $e) {
            debugging('cache_helper: Unable to invalidate health_summary cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Purge all plugin caches.
     */
    public static function purge_all(): void {
        self::invalidate_company_users();
        self::invalidate_user_dashboard();
        self::invalidate_course_progress();
        self::invalidate_health_summary();
    }

    /**
     * Warm up cache for a user (call during health check).
     *
     * @param int $userid User ID
     */
    public static function warmup_user_cache(int $userid): void {
        if ($userid <= 0) {
            return;
        }

        try {
            // Pre-fetch dashboard data to warm cache.
            \local_sm_estratoos_plugin\external\get_dashboard_summary::execute(
                $userid,
                true,  // include_courses
                true,  // include_assignments
                true,  // include_quizzes
                true,  // include_events
                true,  // include_grades
                true,  // include_messages
                7,     // events_days_ahead
                100    // max_courses
            );
        } catch (\Exception $e) {
            debugging('cache_helper: Unable to warm up user cache: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
