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
 * Form for user creation (single user or CSV/Excel batch).
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_users_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        global $DB;

        $mform = $this->_form;

        // Check if IOMAD is installed.
        $isiomad = \local_sm_estratoos_plugin\util::is_iomad_installed();

        // Store IOMAD status in hidden field.
        $mform->addElement('hidden', 'isiomad', $isiomad ? '1' : '0');
        $mform->setType('isiomad', PARAM_INT);

        // --- Section 1: Creation Method ---
        $mform->addElement('header', 'methodheader',
            get_string('creationmethod', 'local_sm_estratoos_plugin'));

        $mform->addElement('select', 'creationmethod',
            get_string('creationmethod', 'local_sm_estratoos_plugin'), [
                'single' => get_string('singleuser', 'local_sm_estratoos_plugin'),
                'csv' => get_string('bycsv', 'local_sm_estratoos_plugin'),
            ]);

        // --- Section 2: Company Selection (IOMAD only) ---
        if ($isiomad) {
            if (is_siteadmin()) {
                $companies = $DB->get_records_menu('company', [], 'name', 'id, name');
            } else {
                $managedcompanies = \local_sm_estratoos_plugin\util::get_user_managed_companies();
                $companies = [];
                foreach ($managedcompanies as $company) {
                    $companies[$company->id] = $company->name;
                }
            }

            if (empty($companies)) {
                $mform->addElement('static', 'nocompanies', '',
                    get_string('invalidcompany', 'local_sm_estratoos_plugin'));
            } else if (count($companies) === 1) {
                $mform->addElement('select', 'companyid',
                    get_string('company', 'local_sm_estratoos_plugin'),
                    $companies);
                $mform->setDefault('companyid', key($companies));
            } else {
                $mform->addElement('select', 'companyid',
                    get_string('company', 'local_sm_estratoos_plugin'),
                    ['' => get_string('selectcompany', 'local_sm_estratoos_plugin')] + $companies);
                $mform->addRule('companyid', get_string('required'), 'required', null, 'client');
            }
        } else {
            $mform->addElement('hidden', 'companyid', 0);
            $mform->setType('companyid', PARAM_INT);
        }

        // --- Section 3: Web Service Selection ---
        $mform->addElement('header', 'serviceheader',
            get_string('serviceselection', 'local_sm_estratoos_plugin'));

        $services = $DB->get_records_menu('external_services', ['enabled' => 1], 'name', 'id, name');

        if (empty($services)) {
            $mform->addElement('static', 'noservices', '',
                get_string('noservicesenabled', 'local_sm_estratoos_plugin'));
        } else {
            $mform->addElement('select', 'serviceid',
                get_string('service', 'local_sm_estratoos_plugin'),
                ['' => get_string('selectservice', 'local_sm_estratoos_plugin')] + $services);
            $mform->addRule('serviceid', get_string('required'), 'required', null, 'client');

            // Pre-select SmartMind service.
            $pluginservice = $DB->get_record('external_services', ['shortname' => 'sm_estratoos_plugin'], 'id');
            if ($pluginservice && isset($services[$pluginservice->id])) {
                $mform->setDefault('serviceid', $pluginservice->id);
            }
        }

        // --- Section 4: Single User Details (shown when creationmethod = single) ---
        $mform->addElement('header', 'singleuserheader',
            get_string('singleuserdetails', 'local_sm_estratoos_plugin'));

        $mform->addElement('text', 'firstname', get_string('firstname'), ['size' => 40]);
        $mform->setType('firstname', PARAM_TEXT);
        $mform->hideIf('firstname', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'lastname', get_string('lastname'), ['size' => 40]);
        $mform->setType('lastname', PARAM_TEXT);
        $mform->hideIf('lastname', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'email', get_string('email'), ['size' => 40]);
        $mform->setType('email', PARAM_TEXT);
        $mform->hideIf('email', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'username',
            get_string('username') . ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['size' => 40]);
        $mform->setType('username', PARAM_TEXT);
        $mform->hideIf('username', 'creationmethod', 'eq', 'csv');

        // Document ID (mandatory for Spain compliance).
        $mform->addElement('select', 'document_type',
            get_string('documenttype', 'local_sm_estratoos_plugin'), [
                '' => get_string('selectdocumenttype', 'local_sm_estratoos_plugin'),
                'dni' => get_string('dni', 'local_sm_estratoos_plugin'),
                'nie' => get_string('nie', 'local_sm_estratoos_plugin'),
                'passport' => get_string('passport', 'local_sm_estratoos_plugin'),
            ]);
        $mform->addRule('document_type', get_string('required'), 'required', null, 'client');
        $mform->hideIf('document_type', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'document_id',
            get_string('documentid', 'local_sm_estratoos_plugin'),
            ['size' => 40]);
        $mform->setType('document_id', PARAM_TEXT);
        $mform->addRule('document_id', get_string('required'), 'required', null, 'client');
        $mform->hideIf('document_id', 'creationmethod', 'eq', 'csv');

        // Password.
        $mform->addElement('advcheckbox', 'generate_password',
            get_string('generatepassword', 'local_sm_estratoos_plugin'),
            get_string('generatepassword_desc', 'local_sm_estratoos_plugin'));
        $mform->setDefault('generate_password', 1);
        $mform->hideIf('generate_password', 'creationmethod', 'eq', 'csv');

        $mform->addElement('passwordunmask', 'password', get_string('password'), ['size' => 40]);
        $mform->setType('password', PARAM_RAW);
        $mform->hideIf('password', 'creationmethod', 'eq', 'csv');
        $mform->hideIf('password', 'generate_password', 'checked');

        // Phone.
        $mform->addElement('text', 'phone_intl_code',
            get_string('phoneintlcode', 'local_sm_estratoos_plugin') .
            ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['size' => 10, 'placeholder' => '+55']);
        $mform->setType('phone_intl_code', PARAM_TEXT);
        $mform->hideIf('phone_intl_code', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'phone',
            get_string('phone') . ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['size' => 20]);
        $mform->setType('phone', PARAM_TEXT);
        $mform->hideIf('phone', 'creationmethod', 'eq', 'csv');

        // Birthdate.
        $mform->addElement('date_selector', 'birthdate',
            get_string('birthdate', 'local_sm_estratoos_plugin') .
            ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['optional' => true]);
        $mform->hideIf('birthdate', 'creationmethod', 'eq', 'csv');

        // Location.
        $mform->addElement('text', 'city',
            get_string('city') . ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['size' => 40]);
        $mform->setType('city', PARAM_TEXT);
        $mform->hideIf('city', 'creationmethod', 'eq', 'csv');

        $mform->addElement('text', 'state_province',
            get_string('stateprovince', 'local_sm_estratoos_plugin') .
            ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['size' => 40]);
        $mform->setType('state_province', PARAM_TEXT);
        $mform->hideIf('state_province', 'creationmethod', 'eq', 'csv');

        // Country dropdown.
        $countries = get_string_manager()->get_list_of_countries();
        $mform->addElement('select', 'country',
            get_string('country') . ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['' => get_string('selectacountry')] + $countries);
        $mform->hideIf('country', 'creationmethod', 'eq', 'csv');

        // Timezone.
        $timezones = \core_date::get_list_of_timezones(null, true);
        $mform->addElement('select', 'timezone',
            get_string('timezone') . ' (' . get_string('optional', 'local_sm_estratoos_plugin') . ')',
            ['99' => get_string('serverlocaltime')] + $timezones);
        $mform->setDefault('timezone', '99');
        $mform->hideIf('timezone', 'creationmethod', 'eq', 'csv');

        // --- Section 5: CSV/Excel Upload (shown when creationmethod = csv) ---
        $mform->addElement('filepicker', 'csvfile',
            get_string('uploadfile', 'local_sm_estratoos_plugin'), null, [
                'accepted_types' => ['.csv', '.txt', '.xlsx', '.xls'],
                'maxfiles' => 1,
            ]);
        $mform->hideIf('csvfile', 'creationmethod', 'eq', 'single');

        // Global generate password for CSV batch.
        $mform->addElement('advcheckbox', 'csv_generate_password',
            get_string('generatepassword', 'local_sm_estratoos_plugin'),
            get_string('generatepassword_desc', 'local_sm_estratoos_plugin'));
        $mform->setDefault('csv_generate_password', 1);
        $mform->hideIf('csv_generate_password', 'creationmethod', 'eq', 'single');

        // Template download links.
        $csvtemplateurl = new \moodle_url('/local/sm_estratoos_plugin/create_users_csvtemplate.php', ['format' => 'csv']);
        $exceltemplateurl = new \moodle_url('/local/sm_estratoos_plugin/create_users_csvtemplate.php', ['format' => 'xlsx']);
        $templatehtml = \html_writer::link($csvtemplateurl,
            get_string('downloaduserscsvtemplate', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-outline-info mr-2', 'target' => '_blank']);
        $templatehtml .= \html_writer::link($exceltemplateurl,
            get_string('downloadusersexceltemplate', 'local_sm_estratoos_plugin'),
            ['class' => 'btn btn-sm btn-outline-success', 'target' => '_blank']);
        $mform->addElement('static', 'csvtemplate', '', $templatehtml);
        $mform->hideIf('csvtemplate', 'creationmethod', 'eq', 'single');

        // Buttons.
        $this->add_action_buttons(true, get_string('createusers', 'local_sm_estratoos_plugin'));
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

        if ($data['creationmethod'] === 'single') {
            // Validate single user fields.
            if (empty(trim($data['firstname'] ?? ''))) {
                $errors['firstname'] = get_string('error_empty_firstname', 'local_sm_estratoos_plugin');
            }
            if (empty(trim($data['lastname'] ?? ''))) {
                $errors['lastname'] = get_string('error_empty_lastname', 'local_sm_estratoos_plugin');
            }
            if (empty(trim($data['email'] ?? ''))) {
                $errors['email'] = get_string('error_empty_email', 'local_sm_estratoos_plugin');
            } else if (!validate_email($data['email'])) {
                $errors['email'] = get_string('error_invalid_email', 'local_sm_estratoos_plugin');
            }
            if (empty($data['generate_password']) && empty($data['password'])) {
                $errors['password'] = get_string('error_empty_password', 'local_sm_estratoos_plugin');
            }
        } else if ($data['creationmethod'] === 'csv') {
            // Validate CSV file is uploaded.
            $csvfile = $this->get_file_content('csvfile');
            if (empty($csvfile)) {
                $errors['csvfile'] = get_string('required');
            }
        }

        // Validate service selection.
        if (empty($data['serviceid'])) {
            $errors['serviceid'] = get_string('required');
        }

        // Validate company in IOMAD mode.
        $isiomad = !empty($data['isiomad']);
        if ($isiomad && empty($data['companyid'])) {
            $errors['companyid'] = get_string('required');
        }

        return $errors;
    }
}
