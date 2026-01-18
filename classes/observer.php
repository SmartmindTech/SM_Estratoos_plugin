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

    /**
     * Role assigned - automatically assign system-level role if appropriate.
     *
     * When a user is assigned a teacher-like, student-like, or manager-like role
     * at course or category level, this observer automatically assigns the
     * corresponding system-level role (editingteacher, student, or manager).
     *
     * For IOMAD installations, this only happens if the user's company is enabled.
     *
     * @param \core\event\role_assigned $event
     */
    public static function role_assigned(\core\event\role_assigned $event): void {
        global $DB;

        try {
            $data = $event->get_data();
            $userid = $data['relateduserid'];
            $roleid = $data['objectid'];
            $contextid = $data['contextid'];

            // Get the context to check the level.
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return;
            }

            // Only process course and category level assignments.
            if ($context->contextlevel != CONTEXT_COURSE && $context->contextlevel != CONTEXT_COURSECAT) {
                return;
            }

            // Get the role that was assigned.
            $role = $DB->get_record('role', ['id' => $roleid], 'id, shortname');
            if (!$role) {
                return;
            }

            $shortname = strtolower($role->shortname);

            // Determine which system role to assign based on the role pattern.
            $systemrole = null;

            // Teacher-like patterns.
            $teacherpatterns = ['teacher', 'professor', 'tutor', 'profesor', 'maestro', 'docente', 'formador'];
            foreach ($teacherpatterns as $pattern) {
                if (strpos($shortname, $pattern) !== false) {
                    $systemrole = $DB->get_record('role', ['shortname' => 'editingteacher']);
                    break;
                }
            }

            // Student-like patterns (only if not already matched as teacher).
            if (!$systemrole) {
                $studentpatterns = ['student', 'alumno', 'estudiante', 'aluno', 'aprendiz'];
                foreach ($studentpatterns as $pattern) {
                    if (strpos($shortname, $pattern) !== false) {
                        $systemrole = $DB->get_record('role', ['shortname' => 'student']);
                        break;
                    }
                }
            }

            // Manager-like patterns (only if not already matched).
            if (!$systemrole) {
                $managerpatterns = ['admin', 'manager', 'administrador', 'gerente', 'gestor'];
                foreach ($managerpatterns as $pattern) {
                    if (strpos($shortname, $pattern) !== false) {
                        $systemrole = $DB->get_record('role', ['shortname' => 'manager']);
                        break;
                    }
                }
            }

            // No matching pattern found.
            if (!$systemrole) {
                return;
            }

            // Check if user already has this system role.
            $systemcontext = \context_system::instance();
            $hasrole = $DB->record_exists('role_assignments', [
                'roleid' => $systemrole->id,
                'contextid' => $systemcontext->id,
                'userid' => $userid,
            ]);

            if ($hasrole) {
                return; // User already has the system role.
            }

            // For IOMAD: Check if user's company is enabled.
            if (util::is_iomad_installed()) {
                $companyuser = $DB->get_record('company_users', ['userid' => $userid], 'companyid');
                if ($companyuser) {
                    // Check if company has access to the plugin.
                    $access = $DB->get_record('local_sm_estratoos_plugin_access', [
                        'companyid' => $companyuser->companyid
                    ]);
                    // If no record exists or company is disabled, don't assign role.
                    if (!$access || !$access->enabled) {
                        return;
                    }
                }
            }

            // Assign the system role.
            role_assign($systemrole->id, $userid, $systemcontext->id);

            // Log the assignment.
            debugging("SM_ESTRATOOS_PLUGIN: Auto-assigned user $userid to system role {$systemrole->shortname} " .
                      "based on course/category role {$role->shortname}", DEBUG_DEVELOPER);

        } catch (\Exception $e) {
            // Log error but don't break the role assignment process.
            debugging("SM_ESTRATOOS_PLUGIN: Error in role_assigned observer - " . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
