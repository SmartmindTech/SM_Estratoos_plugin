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
 * This module works with STATIC HTML (companies rendered by PHP)
 * unlike userselection.js which works with AJAX-loaded users.
 *
 * @module     local_sm_estratoos_plugin/companyaccess
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {
    'use strict';

    /**
     * Update the selected count display.
     */
    var updateCount = function() {
        var count = $('.company-checkbox:checked').length;
        $('#selected-count').text(count + ' companies selected');
    };

    /**
     * Update status badges based on checkbox states.
     */
    var updateBadges = function() {
        $('.company-checkbox').each(function() {
            var $cb = $(this);
            var $item = $cb.closest('.company-item');
            var $badge = $item.find('.company-status-badge');

            if ($cb.is(':checked')) {
                // Show Enabled badge (green).
                if ($badge.length === 0) {
                    $item.find('.custom-control-label').append(
                        '<span class="badge badge-success ml-2 company-status-badge">Enabled</span>'
                    );
                } else {
                    $badge.removeClass('badge-secondary').addClass('badge-success').text('Enabled');
                }
            } else {
                // Show Disabled badge (gray).
                if ($badge.length === 0) {
                    $item.find('.custom-control-label').append(
                        '<span class="badge badge-secondary ml-2 company-status-badge">Disabled</span>'
                    );
                } else {
                    $badge.removeClass('badge-success').addClass('badge-secondary').text('Disabled');
                }
            }
        });
    };

    /**
     * Initialize the module.
     */
    var init = function() {
        // Log initialization immediately.
        if (window.console && window.console.log) {
            window.console.log('SM_ESTRATOOS: companyaccess init() called');
        }

        // Wait for DOM ready using jQuery.
        $(function() {
            if (window.console && window.console.log) {
                window.console.log('SM_ESTRATOOS: DOM ready, binding events');
                window.console.log('SM_ESTRATOOS: Found ' + $('.company-item').length + ' company items');
                window.console.log('SM_ESTRATOOS: Search input exists: ' + ($('#company-search').length > 0));
            }

            // SEARCH - Filter by showing/hiding elements (no re-render needed).
            // Using document delegation for maximum reliability.
            $(document).on('input keyup', '#company-search', function() {
                var filter = $(this).val().toLowerCase().trim();

                if (window.console && window.console.log) {
                    window.console.log('SM_ESTRATOOS: Search filter: "' + filter + '"');
                }

                $('.company-item').each(function() {
                    var name = $(this).attr('data-name') || '';
                    if (filter === '' || name.indexOf(filter) !== -1) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // SELECT ALL - Using document delegation.
            $(document).on('click', '#select-all-companies', function(e) {
                e.preventDefault();
                if (window.console && window.console.log) {
                    window.console.log('SM_ESTRATOOS: Select All clicked');
                }
                $('.company-checkbox:visible').prop('checked', true);
                updateCount();
                updateBadges();
            });

            // DESELECT ALL - Using document delegation.
            $(document).on('click', '#deselect-all-companies', function(e) {
                e.preventDefault();
                if (window.console && window.console.log) {
                    window.console.log('SM_ESTRATOOS: Deselect All clicked');
                }
                $('.company-checkbox:visible').prop('checked', false);
                updateCount();
                updateBadges();
            });

            // CHECKBOX CHANGE - Using document delegation.
            $(document).on('change', '.company-checkbox', function() {
                if (window.console && window.console.log) {
                    window.console.log('SM_ESTRATOOS: Checkbox changed');
                }
                updateCount();
                updateBadges();
            });

            // Initial count update.
            updateCount();

            if (window.console && window.console.log) {
                window.console.log('SM_ESTRATOOS: All event handlers bound successfully');
            }
        });
    };

    return {
        init: init
    };
});
