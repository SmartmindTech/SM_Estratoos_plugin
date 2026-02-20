/**
 * AMD module for loading courses via AJAX based on company selection.
 *
 * Populates the course dropdown in the create users form when a company
 * is selected (IOMAD) or on page load (non-IOMAD).
 *
 * @module     local_sm_estratoos_plugin/courseloader
 * @copyright  2026 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax'], function($, Ajax) {
    return {
        /**
         * Initialize the course loader.
         */
        init: function() {
            var companySelect = document.getElementById('id_companyid');
            var courseSelect = document.getElementById('id_courseid');

            if (!courseSelect) {
                return;
            }

            /**
             * Load courses for a given company ID via AJAX.
             *
             * @param {number} companyId Company ID (0 for all courses).
             */
            function loadCourses(companyId) {
                // Clear existing options.
                courseSelect.innerHTML = '';
                var defaultOption = document.createElement('option');
                defaultOption.value = '0';
                defaultOption.textContent = courseSelect.dataset.defaultText || 'Select a course...';
                courseSelect.appendChild(defaultOption);

                var cid = parseInt(companyId) || 0;

                Ajax.call([{
                    methodname: 'local_sm_estratoos_plugin_get_company_courses',
                    args: {companyid: cid}
                }])[0].done(function(response) {
                    var courses = response.courses || [];
                    courses.forEach(function(course) {
                        var option = document.createElement('option');
                        option.value = course.id;
                        option.textContent = course.fullname;
                        courseSelect.appendChild(option);
                    });
                }).fail(function() {
                    // Silently fail — dropdown stays with just the default option.
                });
            }

            // Store default text from the first option.
            if (courseSelect.options.length > 0) {
                courseSelect.dataset.defaultText = courseSelect.options[0].textContent;
            }

            if (companySelect) {
                // IOMAD mode: reload courses when company changes.
                $(companySelect).on('change', function() {
                    loadCourses(this.value);
                });
                // If a company is already pre-selected, load immediately.
                if (companySelect.value && companySelect.value !== '') {
                    loadCourses(companySelect.value);
                }
            } else {
                // Non-IOMAD mode: load all courses immediately (companyid=0).
                loadCourses(0);
            }
        }
    };
});
