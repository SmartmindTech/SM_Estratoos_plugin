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
 * Form for editing individual token settings.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class individual_token_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        $mform = $this->_form;
        $token = $this->_customdata['token'] ?? null;

        // Hidden token ID.
        $mform->addElement('hidden', 'tokenid');
        $mform->setType('tokenid', PARAM_INT);

        // Token info (read-only).
        $mform->addElement('header', 'tokeninfo', get_string('token', 'local_sm_estratoos_plugin'));

        if ($token) {
            $mform->addElement('static', 'userinfo', get_string('user'),
                fullname($token) . ' (' . $token->email . ')');
            $mform->addElement('static', 'companyinfo', get_string('company', 'local_sm_estratoos_plugin'),
                $token->companyname);
            $mform->addElement('static', 'serviceinfo', get_string('service', 'local_sm_estratoos_plugin'),
                $token->servicename);
            $mform->addElement('static', 'createdinfo', get_string('created', 'local_sm_estratoos_plugin'),
                userdate($token->timecreated));
        }

        // Editable settings.
        $mform->addElement('header', 'editsettings', get_string('tokenrestrictions', 'local_sm_estratoos_plugin'));

        // Restrict to company.
        $mform->addElement('advcheckbox', 'restricttocompany',
            get_string('restricttocompany', 'local_sm_estratoos_plugin'),
            get_string('restricttocompany_desc', 'local_sm_estratoos_plugin'));

        // Restrict to enrollment.
        $mform->addElement('advcheckbox', 'restricttoenrolment',
            get_string('restricttoenrolment', 'local_sm_estratoos_plugin'),
            get_string('restricttoenrolment_desc', 'local_sm_estratoos_plugin'));

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

        // Notes.
        $mform->addElement('textarea', 'notes',
            get_string('notes', 'local_sm_estratoos_plugin'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('notes', PARAM_TEXT);

        // Buttons.
        $this->add_action_buttons(true, get_string('savechanges'));
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
