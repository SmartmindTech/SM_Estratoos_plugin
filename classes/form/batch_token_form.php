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

namespace local_sm_estratoos_plugin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for batch token creation.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class batch_token_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        global $DB, $PAGE;

        $mform = $this->_form;

        // Check if IOMAD is installed.
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

        // Store IOMAD status in hidden field for JavaScript.
        $mform->addElement('hidden', 'isiomad', $isiomad ? '1' : '0');
        $mform->setType('isiomad', PARAM_INT);

        // Section: User Selection Method.
        $mform->addElement('header', 'userselectionheader',
            get_string('userselection', 'local_sm_estratoos_plugin'));

        if ($isiomad) {
            // IOMAD MODE: Show company-based selection.

            // Selection method.
            $mform->addElement('select', 'selectionmethod',
                get_string('selectionmethod', 'local_sm_estratoos_plugin'), [
                    'company' => get_string('bycompany', 'local_sm_estratoos_plugin'),
                    'csv' => get_string('bycsv', 'local_sm_estratoos_plugin'),
                ]);

            // Company selection - filter by user access (site admin vs company manager).
            if (is_siteadmin()) {
                $companies = $DB->get_records_menu('company', [], 'name', 'id, name');
            } else {
                // Company manager - only show managed companies.
                $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
                $companies = [];
                foreach ($managedcompanies as $company) {
                    $companies[$company->id] = $company->name;
                }
            }

            if (empty($companies)) {
                $mform->addElement('static', 'nocompanies', '',
                    get_string('invalidcompany', 'local_sm_estratoos_plugin'));
            } else {
                $mform->addElement('select', 'companyid',
                    get_string('company', 'local_sm_estratoos_plugin'),
                    ['' => get_string('selectcompany', 'local_sm_estratoos_plugin')] + $companies);
                $mform->addRule('companyid', get_string('required'), 'required', null, 'client');
            }

            // Department filter (optional, shown when company method selected).
            $mform->addElement('select', 'departmentid',
                get_string('department', 'local_sm_estratoos_plugin'),
                [0 => get_string('alldepartments', 'local_sm_estratoos_plugin')]);
            $mform->hideIf('departmentid', 'selectionmethod', 'eq', 'csv');
        } else {
            // STANDARD MOODLE MODE: No companies, show direct user selection.

            // Selection method (users or csv).
            $mform->addElement('select', 'selectionmethod',
                get_string('selectionmethod', 'local_sm_estratoos_plugin'), [
                    'users' => get_string('selectusers', 'local_sm_estratoos_plugin'),
                    'csv' => get_string('bycsv', 'local_sm_estratoos_plugin'),
                ]);

            // Hidden company ID (set to 0 for non-IOMAD mode).
            $mform->addElement('hidden', 'companyid', 0);
            $mform->setType('companyid', PARAM_INT);
        }

        // User selection area (shown when company method selected).
        $mform->addElement('html', '<div id="user-selection-container" class="mb-3" style="display:none;">');

        // Quick select buttons.
        $mform->addElement('html', '
            <div class="user-quick-select mb-2">
                <label class="font-weight-bold">' . get_string('quickselect', 'local_sm_estratoos_plugin') . ':</label>
                <div class="btn-group ml-2" role="group">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-all-users">
                        ' . get_string('selectallusers', 'local_sm_estratoos_plugin') . '
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="select-none-users">
                        ' . get_string('selectnone', 'local_sm_estratoos_plugin') . '
                    </button>
                </div>
                <div class="btn-group ml-2" role="group">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="select-students">
                        ' . get_string('selectstudents', 'local_sm_estratoos_plugin') . '
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success" id="select-teachers">
                        ' . get_string('selectteachers', 'local_sm_estratoos_plugin') . '
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="select-managers">
                        ' . get_string('selectmanagers', 'local_sm_estratoos_plugin') . '
                    </button>
                </div>
            </div>
        ');

        // User list container (will be populated via AJAX).
        $mform->addElement('html', '
            <div class="user-list-header d-flex justify-content-between align-items-center mb-2">
                <span id="selected-count">' . get_string('selectedusers', 'local_sm_estratoos_plugin', 0) . '</span>
                <input type="text" id="user-search" class="form-control form-control-sm"
                       style="width: 250px;" placeholder="' . get_string('searchusers', 'local_sm_estratoos_plugin') . '">
            </div>
            <div id="user-list-wrapper" class="border rounded" style="max-height: 400px; overflow-y: auto; background: #fafafa;">
                <div id="user-list-loading" class="text-center py-3">
                    <i class="fa fa-spinner fa-spin"></i> ' . get_string('loadingusers', 'local_sm_estratoos_plugin') . '
                </div>
                <div id="user-list" style="display:none;"></div>
                <div id="user-list-empty" class="alert alert-info m-2" style="display:none;">
                    ' . get_string('nousersfound', 'local_sm_estratoos_plugin') . '
                </div>
            </div>
        ');

        $mform->addElement('html', '</div>');

        // Hidden field to store selected user IDs.
        $mform->addElement('hidden', 'selecteduserids', '');
        $mform->setType('selecteduserids', PARAM_TEXT);

        // File upload (shown when CSV method selected).
        $mform->addElement('filepicker', 'csvfile',
            get_string('uploadfile', 'local_sm_estratoos_plugin'), null, [
                'accepted_types' => ['.csv', '.txt', '.xlsx', '.xls'],
                'maxfiles' => 1
            ]);
        $mform->hideIf('csvfile', 'selectionmethod', 'eq', 'company');
        $mform->hideIf('csvfile', 'selectionmethod', 'eq', 'users');
        $mform->addHelpButton('csvfile', 'csvhelp', 'local_sm_estratoos_plugin');

        // CSV field mapping.
        $mform->addElement('select', 'csvfield',
            get_string('csvfield', 'local_sm_estratoos_plugin'), [
                'id' => get_string('userid', 'local_sm_estratoos_plugin'),
                'username' => get_string('username'),
                'email' => get_string('email'),
            ]);
        $mform->hideIf('csvfield', 'selectionmethod', 'eq', 'company');
        $mform->hideIf('csvfield', 'selectionmethod', 'eq', 'users');

        // Template download links.
        $csvtemplateurl = new \moodle_url('/local/sm_estratoos_plugin/csvtemplate.php', ['format' => 'csv']);
        $exceltemplateurl = new \moodle_url('/local/sm_estratoos_plugin/csvtemplate.php', ['format' => 'xlsx']);
        $templatehtml = \html_writer::link($csvtemplateurl, get_string('downloadcsvtemplate', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-outline-info mr-2', 'target' => '_blank']);
        $templatehtml .= \html_writer::link($exceltemplateurl, get_string('downloadexceltemplate', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-outline-success', 'target' => '_blank']);
        $mform->addElement('static', 'csvtemplate', '', $templatehtml);
        $mform->hideIf('csvtemplate', 'selectionmethod', 'eq', 'company');
        $mform->hideIf('csvtemplate', 'selectionmethod', 'eq', 'users');

        // Section: Web Service Selection.
        $mform->addElement('header', 'serviceheader',
            get_string('serviceselection', 'local_sm_estratoos_plugin'));

        // Get available external services.
        $services = $DB->get_records_menu('external_services', ['enabled' => 1], 'name', 'id, name');

        if (empty($services)) {
            $mform->addElement('static', 'noservices', '',
                get_string('noservicesenabled', 'local_sm_estratoos_plugin'));
        } else {
            $mform->addElement('select', 'serviceid',
                get_string('service', 'local_sm_estratoos_plugin'),
                ['' => get_string('selectservice', 'local_sm_estratoos_plugin')] + $services);
            $mform->addRule('serviceid', get_string('required'), 'required', null, 'client');
        }

        if ($isiomad) {
            // Section: Token Restrictions (IOMAD only).
            $mform->addElement('header', 'restrictionsheader',
                get_string('tokenrestrictions', 'local_sm_estratoos_plugin'));

            // IOMAD MODE: Show company restriction option.
            $mform->addElement('advcheckbox', 'restricttocompany',
                get_string('restricttocompany', 'local_sm_estratoos_plugin'),
                get_string('restricttocompany_desc', 'local_sm_estratoos_plugin'));
            $mform->setDefault('restricttocompany',
                get_config('local_sm_estratoos_plugin', 'default_restricttocompany'));

            // Restrict to enrollment.
            $mform->addElement('advcheckbox', 'restricttoenrolment',
                get_string('restricttoenrolment', 'local_sm_estratoos_plugin'),
                get_string('restricttoenrolment_desc', 'local_sm_estratoos_plugin'));
            $mform->setDefault('restricttoenrolment',
                get_config('local_sm_estratoos_plugin', 'default_restricttoenrolment'));
        } else {
            // STANDARD MOODLE: No restrictions needed (backend handles filtering).
            $mform->addElement('hidden', 'restricttocompany', 0);
            $mform->setType('restricttocompany', PARAM_INT);
            $mform->addElement('hidden', 'restricttoenrolment', 0);
            $mform->setType('restricttoenrolment', PARAM_INT);
        }

        // Section: Batch Settings.
        $mform->addElement('header', 'batchsettingsheader',
            get_string('batchsettings', 'local_sm_estratoos_plugin'));

        // IP Restriction.
        $mform->addElement('text', 'iprestriction',
            get_string('iprestriction', 'local_sm_estratoos_plugin'), ['size' => 60]);
        $mform->setType('iprestriction', PARAM_TEXT);
        $mform->addHelpButton('iprestriction', 'iprestriction', 'local_sm_estratoos_plugin');

        // Valid until.
        $mform->addElement('date_time_selector', 'validuntil',
            get_string('validuntil', 'local_sm_estratoos_plugin'), [
                'optional' => true
            ]);
        $mform->addHelpButton('validuntil', 'validuntil', 'local_sm_estratoos_plugin');

        // Set default validity if configured.
        $defaultdays = get_config('local_sm_estratoos_plugin', 'default_validity_days');
        if ($defaultdays > 0) {
            $mform->setDefault('validuntil', time() + ($defaultdays * DAYSECS));
        }

        // Notes.
        $mform->addElement('textarea', 'notes',
            get_string('notes', 'local_sm_estratoos_plugin'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);
        $mform->addHelpButton('notes', 'notes', 'local_sm_estratoos_plugin');

        // Buttons.
        $this->add_action_buttons(true, get_string('createtokens', 'local_sm_estratoos_plugin'));
    }

    /**
     * Validate form data.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $isiomad = !empty($data['isiomad']);

        // Validate company selection (only for IOMAD mode).
        if ($isiomad && empty($data['companyid'])) {
            $errors['companyid'] = get_string('required');
        }

        // Validate service selection.
        if (empty($data['serviceid'])) {
            $errors['serviceid'] = get_string('required');
        }

        // Validate user selection if company/users method.
        if ($data['selectionmethod'] === 'company' || $data['selectionmethod'] === 'users') {
            if (empty($data['selecteduserids'])) {
                if ($isiomad) {
                    $errors['companyid'] = get_string('nousersselected', 'local_sm_estratoos_plugin');
                } else {
                    $errors['selectionmethod'] = get_string('nousersselected', 'local_sm_estratoos_plugin');
                }
            }
        }

        // Validate CSV file if CSV method selected.
        if ($data['selectionmethod'] === 'csv') {
            $csvfile = $this->get_file_content('csvfile');
            if (empty($csvfile)) {
                $errors['csvfile'] = get_string('required');
            }
        }

        // Validate IP restriction format.
        if (!empty($data['iprestriction'])) {
            if (!\local_sm_estratoos_plugin\util::validate_ip_format($data['iprestriction'])) {
                $errors['iprestriction'] = get_string('invalidiprestriction', 'local_sm_estratoos_plugin');
            }
        }

        return $errors;
    }
}
