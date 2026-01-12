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
 * Form for creating admin (system-wide) tokens.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_token_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        global $DB;

        $mform = $this->_form;

        // Section: Web Service Selection.
        $mform->addElement('header', 'serviceheader', get_string('serviceselection', 'local_sm_estratoos_plugin'));

        // Get available external services.
        $services = $DB->get_records_menu('external_services', ['enabled' => 1], 'name', 'id, name');

        if (empty($services)) {
            $mform->addElement('static', 'noservices', '',
                get_string('noservicesenabled', 'local_sm_estratoos_plugin'));
        } else {
            $mform->addElement('select', 'serviceid',
                get_string('service', 'local_sm_estratoos_plugin'), $services);
            $mform->addRule('serviceid', get_string('required'), 'required', null, 'client');
        }

        // Section: Token Settings.
        $mform->addElement('header', 'settingsheader', get_string('tokenrestrictions', 'local_sm_estratoos_plugin'));

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

        // Buttons.
        $this->add_action_buttons(true, get_string('createadmintokenbutton', 'local_sm_estratoos_plugin'));
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

        // Validate IP restriction format.
        if (!empty($data['iprestriction'])) {
            if (!\local_sm_estratoos_plugin\util::validate_ip_format($data['iprestriction'])) {
                $errors['iprestriction'] = get_string('invalidiprestriction', 'local_sm_estratoos_plugin');
            }
        }

        return $errors;
    }
}
