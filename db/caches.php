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
 * Cache definitions for local_sm_estratoos_plugin.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Application-level caches (shared across all users).
    'company_users' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes.
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ],
    'course_progress' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 120, // 2 minutes.
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],
    'health_summary' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60, // 1 minute.
    ],

    // Session-level caches (per-user).
    'user_dashboard' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 60, // 1 minute.
    ],
    'user_preferences' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 300, // 5 minutes.
    ],

    // Phase 2: Login & Dashboard Optimization caches.
    'login_essentials' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes.
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],
    'dashboard_complete' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 120, // 2 minutes.
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],
    'course_completion' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 60, // 1 minute.
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ],
    'course_stats' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 120, // 2 minutes.
        'staticacceleration' => true,
        'staticaccelerationsize' => 50,
    ],

    // v1.6.5: Dashboard stats cache for get_dashboard_stats function.
    'dashboard_stats' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60, // 1 minute.
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],
];
