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

define(['jquery'], function($) {
    'use strict';

    /**
     * Update the enabled count display.
     */
    var updateCount = function() {
        var count = $('.company-checkbox:checked').length;
        $('#enabled-count').next('strong').text(count);
    };

    /**
     * Initialize the module.
     */
    var init = function() {
        // Search filter - filter companies as user types.
        $('#company-search').on('keyup', function() {
            var filter = $(this).val().toLowerCase();

            $('.company-item').each(function() {
                var name = $(this).data('name');
                if (name && name.indexOf(filter) !== -1) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        });

        // Select all visible companies.
        $('#select-all-companies').on('click', function() {
            $('.company-checkbox:visible').prop('checked', true);
            updateCount();
        });

        // Deselect all visible companies.
        $('#deselect-all-companies').on('click', function() {
            $('.company-checkbox:visible').prop('checked', false);
            updateCount();
        });

        // Update count when any checkbox changes.
        $(document).on('change', '.company-checkbox', function() {
            updateCount();
        });

        // Initial count (already set from PHP, but refresh just in case).
        updateCount();
    };

    return {
        init: init
    };
});
