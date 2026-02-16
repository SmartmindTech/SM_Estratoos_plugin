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
 * Token management page.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();

// Site administrators and company managers can access this page.
\local_sm_estratoos_plugin\util::require_token_admin();

// Check plugin activation (v2.1.32). Superadmins skip this in IOMAD.
\local_sm_estratoos_plugin\util::check_activation_gate();

// Get parameters.
$companyid = optional_param('companyid', 0, PARAM_INT);
$serviceid = optional_param('serviceid', 0, PARAM_INT);
$rolefilter = optional_param('rolefilter', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);
$tokenids = optional_param_array('tokenids', [], PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/sm_estratoos_plugin/manage.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('managetokens', 'local_sm_estratoos_plugin'));
$PAGE->set_heading(get_string('managetokens', 'local_sm_estratoos_plugin'));
$PAGE->set_pagelayout('admin');

// Add navigation.
$PAGE->navbar->add(get_string('pluginname', 'local_sm_estratoos_plugin'),
    new moodle_url('/local/sm_estratoos_plugin/index.php'));
$PAGE->navbar->add(get_string('managetokens', 'local_sm_estratoos_plugin'));

$returnurl = new moodle_url('/local/sm_estratoos_plugin/manage.php', [
    'companyid' => $companyid,
    'serviceid' => $serviceid,
    'rolefilter' => $rolefilter,
]);

// Handle actions.
if ($action === 'revoke' && $tokenid > 0) {
    if ($confirm) {
        require_sesskey();
        if (\local_sm_estratoos_plugin\company_token_manager::revoke_token($tokenid)) {
            redirect($returnurl, get_string('tokenrevoked', 'local_sm_estratoos_plugin'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        } else {
            redirect($returnurl, get_string('tokennotfound', 'local_sm_estratoos_plugin'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
    } else {
        // Show confirmation.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmrevoke', 'local_sm_estratoos_plugin'),
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'revoke',
                'tokenid' => $tokenid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $returnurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

if ($action === 'bulkrevoke' && !empty($tokenids)) {
    if ($confirm) {
        require_sesskey();
        $count = \local_sm_estratoos_plugin\company_token_manager::revoke_tokens($tokenids);
        redirect($returnurl, get_string('tokensrevoked', 'local_sm_estratoos_plugin', $count), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // Show confirmation.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('confirmrevokeselected', 'local_sm_estratoos_plugin'),
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'bulkrevoke',
                'tokenids' => $tokenids,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $returnurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

// Display page.
echo $OUTPUT->header();

// Filters.
global $DB;

// Check if IOMAD is installed.
$isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

// Get companies based on user access - site admins see all, company managers see only their companies.
$issiteadmin = is_siteadmin();
$companies = [];

if ($isiomad) {
    // IOMAD MODE: Show company filter.
    if ($issiteadmin) {
        $companies = ['' => get_string('all')] + $DB->get_records_menu('company', [], 'name', 'id, name');
    } else {
        // Company manager - only show managed companies.
        $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
        foreach ($managedcompanies as $company) {
            $companies[$company->id] = $company->name;
        }
        if (count($companies) > 1) {
            $companies = ['' => get_string('all')] + $companies;
        }
    }
}
$services = ['' => get_string('all')] + $DB->get_records_menu('external_services', ['enabled' => 1], 'name', 'id, name');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Only show company filter in IOMAD mode.
if ($isiomad && !empty($companies)) {
    echo html_writer::start_div('form-group mr-2');
    echo html_writer::label(get_string('company', 'local_sm_estratoos_plugin') . ': ', 'filtercompany', true, ['class' => 'mr-2']);
    echo html_writer::select($companies, 'companyid', $companyid, false, ['id' => 'filtercompany', 'class' => 'form-control']);
    echo html_writer::end_div();
}

echo html_writer::start_div('form-group mr-2');
echo html_writer::label(get_string('service', 'local_sm_estratoos_plugin') . ': ', 'filterservice', true, ['class' => 'mr-2']);
echo html_writer::select($services, 'serviceid', $serviceid, false, ['id' => 'filterservice', 'class' => 'form-control']);
echo html_writer::end_div();

// Role filter.
$roles = [
    '' => get_string('all'),
    'manager' => get_string('role_manager', 'local_sm_estratoos_plugin'),
    'teacher' => get_string('role_teacher', 'local_sm_estratoos_plugin'),
    'student' => get_string('role_student', 'local_sm_estratoos_plugin'),
    'other' => get_string('role_other', 'local_sm_estratoos_plugin'),
];
echo html_writer::start_div('form-group mr-2');
echo html_writer::label(get_string('role', 'local_sm_estratoos_plugin') . ': ', 'filterrole', true, ['class' => 'mr-2']);
echo html_writer::select($roles, 'rolefilter', $rolefilter, false, ['id' => 'filterrole', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('filter', 'local_sm_estratoos_plugin'),
    'class' => 'btn btn-secondary',
]);

echo html_writer::end_tag('form');

// Get tokens.
$filters = [];
if ($companyid > 0) {
    // Validate that user can access this company.
    if (!$issiteadmin && !isset($companies[$companyid])) {
        throw new moodle_exception('accessdenied', 'local_sm_estratoos_plugin');
    }
    $filters['companyid'] = $companyid;
}
if ($serviceid > 0) {
    $filters['serviceid'] = $serviceid;
}

// For company managers, restrict to their managed companies.
$companyfilter = $companyid > 0 ? $companyid : null;
if (!$issiteadmin && !$companyfilter) {
    // Get all managed company IDs.
    $managedids = array_keys(\local_sm_estratoos_plugin\util::get_user_managed_companies());
    $filters['companyids'] = $managedids;
}

$tokens = \local_sm_estratoos_plugin\company_token_manager::get_company_tokens(
    $companyfilter,
    $filters
);

// Enrich suspended tokens with user info from backup.
foreach ($tokens as $token) {
    if (empty($token->active) && !empty($token->token_backup)) {
        $backup = json_decode($token->token_backup);
        if ($backup && isset($backup->userid)) {
            // Get user from backup's userid.
            $backupuser = $DB->get_record('user', ['id' => $backup->userid], 'id, username, email, firstname, lastname');
            if ($backupuser) {
                $token->userid = $backupuser->id;
                $token->username = $backupuser->username;
                $token->email = $backupuser->email;
                $token->firstname = $backupuser->firstname;
                $token->lastname = $backupuser->lastname;
            }
            // Get service name from backup.
            if (isset($backup->externalserviceid)) {
                $service = $DB->get_record('external_services', ['id' => $backup->externalserviceid], 'name');
                if ($service) {
                    $token->servicename = $service->name . ' (' . get_string('tokenstatussuspended', 'local_sm_estratoos_plugin') . ')';
                }
            }
        }
    }
}

// Role filter helper function (defined early for use in filtering).
function _get_user_role_type_for_filter($userid, $companyid = 0) {
    global $DB;

    // Check IOMAD manager status first.
    if (\local_sm_estratoos_plugin\util::is_iomad_installed() && $companyid > 0) {
        $managertype = $DB->get_field('company_users', 'managertype', [
            'userid' => $userid,
            'companyid' => $companyid,
        ]);
        if ($managertype > 0) {
            return 'manager';
        }
    }

    // Check ALL role assignments.
    $sql = "SELECT DISTINCT r.id, r.shortname
            FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid
            WHERE ra.userid = :userid";
    $userroles = $DB->get_records_sql($sql, ['userid' => $userid]);

    $teacherpatterns = ['teacher', 'editingteacher', 'coursecreator', 'profesor', 'professor'];
    $studentpatterns = ['student', 'alumno', 'aluno', 'estudante', 'estudiante'];
    $managerpatterns = ['manager', 'admin', 'gerente', 'administrador'];

    $hasteacher = false;
    $hasstudent = false;

    foreach ($userroles as $role) {
        $shortname = strtolower($role->shortname);

        foreach ($managerpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                return 'manager';
            }
        }
        foreach ($teacherpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasteacher = true;
            }
        }
        foreach ($studentpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasstudent = true;
            }
        }
    }

    if ($hasteacher) {
        return 'teacher';
    }
    if ($hasstudent) {
        return 'student';
    }
    return 'other';
}

// Apply role filter if specified.
if (!empty($rolefilter)) {
    $filteredtokens = [];
    foreach ($tokens as $key => $token) {
        $userrole = _get_user_role_type_for_filter($token->userid, $token->companyid ?? 0);
        if ($userrole === $rolefilter) {
            $filteredtokens[$key] = $token;
        }
    }
    $tokens = $filteredtokens;
}

/**
 * Get role badge HTML for a user.
 *
 * @param int $userid The user ID.
 * @param int $companyid The company ID (for IOMAD manager detection).
 * @return string HTML badge element.
 */
function get_user_role_badge($userid, $companyid = 0) {
    global $DB;

    // 1. Check IOMAD manager status first (if IOMAD is installed).
    if (\local_sm_estratoos_plugin\util::is_iomad_installed() && $companyid > 0) {
        $managertype = $DB->get_field('company_users', 'managertype', [
            'userid' => $userid,
            'companyid' => $companyid,
        ]);
        if ($managertype > 0) {
            return html_writer::tag('span', get_string('role_manager', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-warning']);
        }
    }

    // 2. Check ALL role assignments (system, category, and course level).
    // Query all distinct roles for this user across all contexts.
    $sql = "SELECT DISTINCT r.id, r.shortname
            FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid
            WHERE ra.userid = :userid";
    $roles = $DB->get_records_sql($sql, ['userid' => $userid]);

    // Define role patterns (supports multilingual).
    $teacherpatterns = ['teacher', 'editingteacher', 'coursecreator', 'profesor', 'professor'];
    $studentpatterns = ['student', 'alumno', 'aluno', 'estudante', 'estudiante'];
    $managerpatterns = ['manager', 'admin', 'gerente', 'administrador'];

    // Track what roles we find (priority: manager > teacher > student > other).
    $hasmanager = false;
    $hasteacher = false;
    $hasstudent = false;

    foreach ($roles as $role) {
        $shortname = strtolower($role->shortname);

        // Check for manager roles.
        foreach ($managerpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasmanager = true;
                break 2; // Manager is highest priority, return immediately.
            }
        }

        // Check for teacher roles.
        foreach ($teacherpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasteacher = true;
                break;
            }
        }

        // Check for student roles.
        foreach ($studentpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasstudent = true;
                break;
            }
        }
    }

    // Return badge based on priority.
    if ($hasmanager) {
        return html_writer::tag('span', get_string('role_manager', 'local_sm_estratoos_plugin'),
            ['class' => 'badge badge-warning']);
    }
    if ($hasteacher) {
        return html_writer::tag('span', get_string('role_teacher', 'local_sm_estratoos_plugin'),
            ['class' => 'badge badge-success']);
    }
    if ($hasstudent) {
        return html_writer::tag('span', get_string('role_student', 'local_sm_estratoos_plugin'),
            ['class' => 'badge badge-info']);
    }

    // 3. Default to "Other".
    return html_writer::tag('span', get_string('role_other', 'local_sm_estratoos_plugin'),
        ['class' => 'badge badge-secondary']);
}

/**
 * Get role type for a user (for filtering).
 *
 * @param int $userid The user ID.
 * @param int $companyid The company ID (for IOMAD manager detection).
 * @return string Role type: 'manager', 'teacher', 'student', or 'other'.
 */
function get_user_role_type($userid, $companyid = 0) {
    global $DB;

    // 1. Check IOMAD manager status first.
    if (\local_sm_estratoos_plugin\util::is_iomad_installed() && $companyid > 0) {
        $managertype = $DB->get_field('company_users', 'managertype', [
            'userid' => $userid,
            'companyid' => $companyid,
        ]);
        if ($managertype > 0) {
            return 'manager';
        }
    }

    // 2. Check ALL role assignments.
    $sql = "SELECT DISTINCT r.id, r.shortname
            FROM {role_assignments} ra
            JOIN {role} r ON r.id = ra.roleid
            WHERE ra.userid = :userid";
    $roles = $DB->get_records_sql($sql, ['userid' => $userid]);

    $teacherpatterns = ['teacher', 'editingteacher', 'coursecreator', 'profesor', 'professor'];
    $studentpatterns = ['student', 'alumno', 'aluno', 'estudante', 'estudiante'];
    $managerpatterns = ['manager', 'admin', 'gerente', 'administrador'];

    $hasteacher = false;
    $hasstudent = false;

    foreach ($roles as $role) {
        $shortname = strtolower($role->shortname);

        foreach ($managerpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                return 'manager';
            }
        }
        foreach ($teacherpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasteacher = true;
            }
        }
        foreach ($studentpatterns as $pattern) {
            if (strpos($shortname, $pattern) !== false) {
                $hasstudent = true;
            }
        }
    }

    if ($hasteacher) {
        return 'teacher';
    }
    if ($hasstudent) {
        return 'student';
    }
    return 'other';
}

if (empty($tokens)) {
    echo $OUTPUT->notification(get_string('notokens', 'local_sm_estratoos_plugin'), 'info');
} else {
    // Token table.
    echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'tokensform']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'bulkrevoke']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'serviceid', 'value' => $serviceid]);

    $table = new html_table();
    $table->head = [
        html_writer::checkbox('selectall', 1, false, '', ['id' => 'selectall']),
        get_string('user'),
        get_string('role', 'local_sm_estratoos_plugin'),
        get_string('company', 'local_sm_estratoos_plugin'),
        get_string('service', 'local_sm_estratoos_plugin'),
        get_string('status'),
        get_string('restrictions', 'local_sm_estratoos_plugin'),
        get_string('validuntil', 'local_sm_estratoos_plugin'),
        get_string('lastaccess'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'table table-striped table-hover';

    foreach ($tokens as $token) {
        // Status badge - check if token is active or suspended.
        $isactive = isset($token->active) ? (bool)$token->active : true;
        if ($isactive) {
            $statusbadge = html_writer::tag('span', get_string('tokenstatusactive', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-success']);
        } else {
            $statusbadge = html_writer::tag('span', get_string('tokenstatussuspended', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-danger']);
        }

        // Restrictions badges.
        $restrictions = '';
        if ($token->restricttocompany) {
            $restrictions .= html_writer::tag('span', get_string('companyonly', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-info mr-1']);
        }
        if ($token->restricttoenrolment) {
            $restrictions .= html_writer::tag('span', get_string('enrolledonly', 'local_sm_estratoos_plugin'),
                ['class' => 'badge badge-warning mr-1']);
        }
        if (!empty($token->iprestriction)) {
            $restrictions .= html_writer::tag('span', $token->iprestriction,
                ['class' => 'badge badge-secondary']);
        }

        // Valid until.
        $validuntil = $token->validuntil ? userdate($token->validuntil) : get_string('neverexpires', 'local_sm_estratoos_plugin');

        // Last access.
        $lastaccess = $token->lastaccess ? userdate($token->lastaccess) : get_string('never');

        // Actions - use FA trash icon with proper centering.
        $trashicon = html_writer::tag('i', '', [
            'class' => 'fa fa-trash',
            'aria-hidden' => 'true'
        ]);
        $actions = html_writer::link(
            new moodle_url('/local/sm_estratoos_plugin/manage.php', [
                'action' => 'revoke',
                'tokenid' => $token->id,
                'companyid' => $companyid,
                'serviceid' => $serviceid,
            ]),
            $trashicon,
            [
                'class' => 'btn btn-sm btn-outline-danger d-inline-flex align-items-center justify-content-center',
                'title' => get_string('revoke', 'local_sm_estratoos_plugin'),
                'aria-label' => get_string('revoke', 'local_sm_estratoos_plugin'),
                'style' => 'width: 32px; height: 32px;'
            ]
        );

        // Role badge.
        $rolebadge = get_user_role_badge($token->userid, $token->companyid ?? 0);

        $table->data[] = [
            html_writer::checkbox('tokenids[]', $token->id, false, '', ['class' => 'tokencheck']),
            html_writer::tag('strong', fullname($token)) .
                html_writer::tag('br', '') .
                html_writer::tag('small', $token->email, ['class' => 'text-muted']),
            $rolebadge,
            $token->companyname,
            $token->servicename,
            $statusbadge,
            $restrictions ?: '-',
            $validuntil,
            $lastaccess,
            $actions,
        ];
    }

    echo html_writer::table($table);

    // Bulk actions.
    echo html_writer::start_div('d-flex justify-content-between align-items-center');
    echo html_writer::start_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('revokeselected', 'local_sm_estratoos_plugin'),
        'class' => 'btn btn-danger',
        'onclick' => 'return confirm("' . get_string('confirmrevokeselected', 'local_sm_estratoos_plugin') . '");',
    ]);
    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/export.php', [
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'format' => 'csv',
        ]),
        get_string('exportcsv', 'local_sm_estratoos_plugin'),
        ['class' => 'btn btn-outline-secondary ml-2']
    );
    echo html_writer::link(
        new moodle_url('/local/sm_estratoos_plugin/export.php', [
            'companyid' => $companyid,
            'serviceid' => $serviceid,
            'format' => 'xlsx',
        ]),
        get_string('exportexcel', 'local_sm_estratoos_plugin'),
        ['class' => 'btn btn-outline-success ml-2']
    );
    echo html_writer::end_div();
    echo html_writer::tag('span', count($tokens) . ' tokens', ['class' => 'text-muted']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');

    // JavaScript for select all.
    $PAGE->requires->js_amd_inline("
        require(['jquery'], function($) {
            $('#selectall').on('change', function() {
                $('.tokencheck').prop('checked', this.checked);
            });
        });
    ");
}

// Back button.
echo html_writer::start_div('mt-4');
echo html_writer::link(
    new moodle_url('/local/sm_estratoos_plugin/index.php'),
    get_string('back'),
    ['class' => 'btn btn-secondary']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
