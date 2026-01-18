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
 * Company access management JavaScript module.
 *
 * Provides search filtering, select all/deselect all functionality,
 * and live counter updates for the company access management page.
 *
 * @module     local_sm_estratoos_plugin/companyaccess
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/str'], function($, Str) {
    'use strict';

    var strings = {};

    /**
     * Load language strings.
     * @returns {Promise}
     */
    var loadStrings = function() {
        return Str.get_strings([
            {key: 'companiesselected', component: 'local_sm_estratoos_plugin'},
            {key: 'enabled', component: 'local_sm_estratoos_plugin'}
        ]).then(function(strs) {
            strings.companiesselected = strs[0];
            strings.enabled = strs[1];
            return true;
        });
    };

    /**
     * Update the selected count display.
     */
    var updateCount = function() {
        var count = $('.company-checkbox:checked').length;
        $('#selected-count').text(count + ' ' + strings.companiesselected);
    };

    /**
     * Update status badges based on checkbox states.
     */
    var updateBadges = function() {
        $('.company-checkbox').each(function() {
            var $checkbox = $(this);
            var $item = $checkbox.closest('.company-item');
            var $badge = $item.find('.company-status-badge');

            if ($checkbox.is(':checked')) {
                // Add badge if not exists.
                if ($badge.length === 0) {
                    var $label = $item.find('.custom-control-label');
                    $label.append('<span class="badge badge-success ml-2 company-status-badge">' +
                        strings.enabled + '</span>');
                }
            } else {
                // Remove badge.
                $badge.remove();
            }
        });
    };

    /**
     * Filter companies based on search term.
     * @param {string} filter - The search term (lowercase).
     */
    var filterCompanies = function(filter) {
        $('.company-item').each(function() {
            var name = $(this).attr('data-name');
            if (!name) {
                name = '';
            }
            if (filter === '' || name.indexOf(filter) !== -1) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    };

    /**
     * Initialize the module.
     */
    var init = function() {
        loadStrings().then(function() {
            // Debug: Log initialization.
            if (window.console && window.console.log) {
                window.console.log('SM_ESTRATOOS: companyaccess module initialized');
                window.console.log('SM_ESTRATOOS: Found ' + $('.company-item').length + ' company items');
            }

            // Search filter - use document delegation for robust event binding.
            // This is the SAME PATTERN used by userselection.js (line 382).
            $(document).on('input keyup', '#company-search', function() {
                var filter = $(this).val().toLowerCase().trim();
                filterCompanies(filter);
            });

            // Select all visible companies - document delegation.
            $(document).on('click', '#select-all-companies', function(e) {
                e.preventDefault();
                $('.company-checkbox:visible').prop('checked', true);
                updateCount();
                updateBadges();
            });

            // Deselect all visible companies - document delegation.
            $(document).on('click', '#deselect-all-companies', function(e) {
                e.preventDefault();
                $('.company-checkbox:visible').prop('checked', false);
                updateCount();
                updateBadges();
            });

            // Update count and badges when any checkbox changes - document delegation.
            $(document).on('change', '.company-checkbox', function() {
                updateCount();
                updateBadges();
            });

            // Initial count update.
            updateCount();

            return true;
        }).catch(function(err) {
            if (window.console && window.console.error) {
                window.console.error('SM_ESTRATOOS: Error loading strings', err);
            }
        });
    };

    return {
        init: init
    };
});
