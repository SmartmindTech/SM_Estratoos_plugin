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
 * User selection module for batch token creation.
 *
 * @module     local_sm_estratoos_plugin/userselection
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {

    var users = [];
    var selectedIds = [];
    var strings = {};

    /**
     * Load language strings.
     */
    var loadStrings = function() {
        return Str.get_strings([
            {key: 'selectedusers', component: 'local_sm_estratoos_plugin'},
            {key: 'student', component: 'core'},
            {key: 'teacher', component: 'core'},
            {key: 'manager', component: 'core_role'}
        ]).then(function(strs) {
            strings.selectedusers = strs[0];
            strings.student = strs[1];
            strings.teacher = strs[2];
            strings.manager = strs[3];
            return true;
        });
    };

    /**
     * Load users for a company.
     *
     * @param {number} companyId Company ID.
     * @param {number} departmentId Department ID (0 for all).
     */
    var loadUsers = function(companyId, departmentId) {
        var container = $('#user-selection-container');
        var loading = $('#user-list-loading');
        var list = $('#user-list');
        var empty = $('#user-list-empty');

        // Show loading.
        loading.show();
        list.hide();
        empty.hide();
        container.show();

        // Reset selection.
        users = [];
        selectedIds = [];
        updateSelectedCount();

        // Call AJAX.
        Ajax.call([{
            methodname: 'local_sm_estratoos_plugin_get_company_users',
            args: {
                companyid: companyId,
                departmentid: departmentId || 0,
                includeroles: true
            }
        }])[0].done(function(response) {
            loading.hide();

            if (response.users && response.users.length > 0) {
                users = response.users;
                renderUserList();
                list.show();
            } else {
                empty.show();
            }
        }).fail(function(err) {
            loading.hide();
            empty.show();
            Notification.exception(err);
        });
    };

    /**
     * Render the user list.
     *
     * @param {string} filter Optional filter string.
     */
    var renderUserList = function(filter) {
        var list = $('#user-list');
        var html = '<div class="user-items">';

        filter = filter ? filter.toLowerCase() : '';

        users.forEach(function(user) {
            // Apply filter.
            if (filter) {
                var searchable = (user.fullname + ' ' + user.email).toLowerCase();
                if (searchable.indexOf(filter) === -1) {
                    return;
                }
            }

            var isChecked = selectedIds.indexOf(user.id) !== -1;
            var roleClasses = [];
            var roleBadges = '';

            // Determine role badges.
            if (user.roles) {
                user.roles.forEach(function(role) {
                    var rolename = role.shortname.toLowerCase();
                    if (rolename === 'student' || rolename === 'alumno' || rolename === 'estudante') {
                        roleClasses.push('role-student');
                        roleBadges += '<span class="badge badge-primary ml-1">' + strings.student + '</span>';
                    } else if (rolename === 'editingteacher' || rolename === 'teacher' ||
                               rolename === 'profesor' || rolename === 'professor') {
                        roleClasses.push('role-teacher');
                        roleBadges += '<span class="badge badge-success ml-1">' + strings.teacher + '</span>';
                    } else if (rolename === 'manager' || rolename === 'companymanager' ||
                               rolename === 'gerente' || rolename === 'gestor') {
                        roleClasses.push('role-manager');
                        roleBadges += '<span class="badge badge-warning ml-1">' + strings.manager + '</span>';
                    }
                });
            }

            html += '<div class="user-item d-flex align-items-center py-1 border-bottom ' + roleClasses.join(' ') + '">';
            html += '<div class="custom-control custom-checkbox">';
            html += '<input type="checkbox" class="custom-control-input user-checkbox" ';
            html += 'id="user-' + user.id + '" data-userid="' + user.id + '"';
            if (isChecked) {
                html += ' checked';
            }
            html += '>';
            html += '<label class="custom-control-label" for="user-' + user.id + '">';
            html += '<strong>' + user.fullname + '</strong>';
            html += '<small class="text-muted ml-2">' + user.email + '</small>';
            html += roleBadges;
            html += '</label>';
            html += '</div>';
            html += '</div>';
        });

        html += '</div>';
        list.html(html);

        // Bind checkbox events.
        $('.user-checkbox').on('change', function() {
            var userId = parseInt($(this).data('userid'));
            if (this.checked) {
                if (selectedIds.indexOf(userId) === -1) {
                    selectedIds.push(userId);
                }
            } else {
                var idx = selectedIds.indexOf(userId);
                if (idx !== -1) {
                    selectedIds.splice(idx, 1);
                }
            }
            updateSelectedCount();
            updateHiddenField();
        });
    };

    /**
     * Update the selected count display.
     */
    var updateSelectedCount = function() {
        var text = strings.selectedusers.replace('{$a}', selectedIds.length);
        $('#selected-count').text(selectedIds.length + ' ' + text);
    };

    /**
     * Update the hidden field with selected user IDs.
     */
    var updateHiddenField = function() {
        $('input[name="selecteduserids"]').val(selectedIds.join(','));
    };

    /**
     * Select users by role.
     *
     * @param {string} roleType Role type to select (student, teacher, manager).
     */
    var selectByRole = function(roleType) {
        selectedIds = [];

        users.forEach(function(user) {
            if (user.roles) {
                var hasRole = user.roles.some(function(role) {
                    var rolename = role.shortname.toLowerCase();
                    if (roleType === 'student') {
                        return rolename === 'student' || rolename === 'alumno' || rolename === 'estudante';
                    } else if (roleType === 'teacher') {
                        return rolename === 'editingteacher' || rolename === 'teacher' ||
                               rolename === 'profesor' || rolename === 'professor';
                    } else if (roleType === 'manager') {
                        return rolename === 'manager' || rolename === 'companymanager' ||
                               rolename === 'gerente' || rolename === 'gestor';
                    }
                    return false;
                });

                if (hasRole) {
                    selectedIds.push(user.id);
                }
            }
        });

        updateCheckboxes();
        updateSelectedCount();
        updateHiddenField();
    };

    /**
     * Select all users.
     */
    var selectAll = function() {
        selectedIds = users.map(function(u) { return u.id; });
        updateCheckboxes();
        updateSelectedCount();
        updateHiddenField();
    };

    /**
     * Deselect all users.
     */
    var selectNone = function() {
        selectedIds = [];
        updateCheckboxes();
        updateSelectedCount();
        updateHiddenField();
    };

    /**
     * Update checkbox states to match selectedIds.
     */
    var updateCheckboxes = function() {
        $('.user-checkbox').each(function() {
            var userId = parseInt($(this).data('userid'));
            $(this).prop('checked', selectedIds.indexOf(userId) !== -1);
        });
    };

    /**
     * Initialize the module.
     */
    var init = function() {
        loadStrings().then(function() {
            // Company selection change handler.
            $('#id_companyid').on('change', function() {
                var companyId = parseInt($(this).val());
                var departmentId = parseInt($('#id_departmentid').val()) || 0;

                if (companyId > 0) {
                    loadUsers(companyId, departmentId);
                } else {
                    $('#user-selection-container').hide();
                    users = [];
                    selectedIds = [];
                    updateHiddenField();
                }
            });

            // Department selection change handler.
            $('#id_departmentid').on('change', function() {
                var companyId = parseInt($('#id_companyid').val());
                var departmentId = parseInt($(this).val()) || 0;

                if (companyId > 0) {
                    loadUsers(companyId, departmentId);
                }
            });

            // Selection method change handler.
            $('#id_selectionmethod').on('change', function() {
                var method = $(this).val();
                if (method === 'company') {
                    var companyId = parseInt($('#id_companyid').val());
                    if (companyId > 0) {
                        $('#user-selection-container').show();
                    }
                } else {
                    $('#user-selection-container').hide();
                }
            });

            // Quick select buttons.
            $('#select-all-users').on('click', function(e) {
                e.preventDefault();
                selectAll();
            });

            $('#select-none-users').on('click', function(e) {
                e.preventDefault();
                selectNone();
            });

            $('#select-students').on('click', function(e) {
                e.preventDefault();
                selectByRole('student');
            });

            $('#select-teachers').on('click', function(e) {
                e.preventDefault();
                selectByRole('teacher');
            });

            $('#select-managers').on('click', function(e) {
                e.preventDefault();
                selectByRole('manager');
            });

            // User search.
            $('#user-search').on('keyup', function() {
                var filter = $(this).val();
                renderUserList(filter);
            });

            // Initial load if company already selected.
            var initialCompany = parseInt($('#id_companyid').val());
            if (initialCompany > 0 && $('#id_selectionmethod').val() === 'company') {
                loadUsers(initialCompany, 0);
            }

            return true;
        }).catch(Notification.exception);
    };

    return {
        init: init
    };
});
