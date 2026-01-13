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
 * Form for adding functions to a web service.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_functions_form extends \moodleform {

    /**
     * Define the form elements.
     */
    protected function definition() {
        global $DB;

        $mform = $this->_form;
        $serviceid = $this->_customdata['id'];

        // Get all available external functions.
        $allfunctions = $DB->get_records('external_functions', [], 'name ASC');

        // Get functions already in this service.
        $webservicemanager = new \webservice();
        $servicefunctions = $webservicemanager->get_external_functions([$serviceid]);
        $existingfunctions = [];
        foreach ($servicefunctions as $sf) {
            $existingfunctions[$sf->name] = true;
        }

        // Build list of available functions (not already in service).
        $availablefunctions = [];
        foreach ($allfunctions as $function) {
            if (!isset($existingfunctions[$function->name])) {
                $availablefunctions[$function->id] = $function->name;
            }
        }

        if (empty($availablefunctions)) {
            $mform->addElement('static', 'nofunctions', '',
                get_string('allfunctionsadded', 'local_sm_estratoos_plugin'));
        } else {
            // Instructions.
            $mform->addElement('static', 'instructions', '',
                get_string('selectfunctionstoadd', 'local_sm_estratoos_plugin'));

            // Search filter (JavaScript will handle this).
            $mform->addElement('text', 'search', get_string('search'),
                ['id' => 'function-search', 'placeholder' => get_string('searchfunctions', 'local_sm_estratoos_plugin')]);
            $mform->setType('search', PARAM_TEXT);

            // Function selection.
            $select = $mform->addElement('select', 'fids',
                get_string('functions', 'webservice'), $availablefunctions,
                ['id' => 'function-select', 'size' => 15]);
            $select->setMultiple(true);
            $mform->addRule('fids', get_string('required'), 'required', null, 'client');

            // Help text.
            $mform->addElement('static', 'help', '',
                html_writer::tag('small',
                    get_string('functionselecthelp', 'local_sm_estratoos_plugin'),
                    ['class' => 'text-muted']
                )
            );
        }

        // Hidden service ID.
        $mform->addElement('hidden', 'id', $serviceid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'action', 'add');
        $mform->setType('action', PARAM_ALPHA);

        // Buttons.
        if (!empty($availablefunctions)) {
            $this->add_action_buttons(true, get_string('addfunctions', 'webservice'));
        } else {
            $mform->addElement('cancel');
        }
    }
}
