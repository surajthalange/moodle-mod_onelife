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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * The learner's scope picker form.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\form;

use mod_suddendeath\local\modes;
use mod_suddendeath\local\scope_validator;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Lets a learner choose what to practise before a run starts.
 *
 * Single-topic mode uses radio inputs rather than checkboxes policed by a script.
 * The browser then enforces "exactly one" natively, which is also correct on a
 * keyboard and to a screen reader, and the plugin ships no JavaScript for it at all.
 * The cap is re-checked server-side regardless, because a forged post can carry
 * anything.
 *
 * Only permitted modes are added to the form, and scope_validator rejects any other
 * mode on submission, so the restriction does not depend on the markup.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_picker_form extends moodleform {
    /**
     * Define the form elements.
     */
    public function definition() {
        $mform = $this->_form;

        $allowedmodes = $this->_customdata['modes'];
        $topics = $this->_customdata['topics'];

        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $moderadios = [];
        foreach ($allowedmodes as $code) {
            $moderadios[] = $mform->createElement(
                'radio',
                'scopetype',
                '',
                modes::label($code),
                $code
            );
        }
        $mform->addGroup(
            $moderadios,
            'scopetypegroup',
            get_string('choosescope', 'mod_suddendeath'),
            ['<br />'],
            false
        );
        $mform->setDefault('scopetype', reset($allowedmodes));

        if (in_array(modes::SINGLE, $allowedmodes, true)) {
            $topicradios = [];
            foreach ($topics as $topicid => $name) {
                $topicradios[] = $mform->createElement(
                    'radio',
                    scope_validator::SINGLE_ELEMENT,
                    '',
                    format_string($name),
                    (int) $topicid
                );
            }
            $mform->addGroup(
                $topicradios,
                'singletopicgroup',
                get_string('choosetopic', 'mod_suddendeath'),
                ['<br />'],
                false
            );
            // Named elements, never bare integers, so this dependency actually binds.
            $mform->hideIf('singletopicgroup', 'scopetype', 'neq', modes::SINGLE);
        }

        if (in_array(modes::MULTI, $allowedmodes, true)) {
            $topicboxes = [];
            foreach ($topics as $topicid => $name) {
                $topicboxes[] = $mform->createElement(
                    'advcheckbox',
                    scope_validator::topic_element_name((int) $topicid),
                    '',
                    format_string($name)
                );
            }
            $mform->addGroup(
                $topicboxes,
                scope_validator::TOPIC_GROUP,
                get_string('choosetopics', 'mod_suddendeath'),
                ['<br />'],
                false
            );
            $mform->hideIf(scope_validator::TOPIC_GROUP, 'scopetype', 'neq', modes::MULTI);
        }

        $this->add_action_buttons(false, get_string('startrun', 'mod_suddendeath'));
    }

    /**
     * Server-side validation.
     *
     * Delegates in full to scope_validator, so the tested rules and the enforced
     * rules cannot drift apart, then maps the result onto the names formslib can
     * actually display.
     *
     * @param array $data the submitted data
     * @param array $files the submitted files
     * @return array element name to error message
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $validtopicids = array_map('intval', array_keys($this->_customdata['topics']));

        $failures = scope_validator::validate(
            $this->_customdata['modes'],
            $validtopicids,
            (array) $data
        );

        return array_merge($errors, $this->map_to_group_names($failures));
    }

    /**
     * Re-key validation errors onto the groups that render them.
     *
     * scope_validator keys its errors on the element that is wrong, which is what
     * makes it readable and testable on its own. formslib, though, can only show an
     * error against a top-level element, and both the mode radios and the
     * single-topic radios live inside groups. Handing back the inner element name
     * silently drops the message: the submission is still rejected, but the learner
     * is returned an unchanged form with no explanation.
     *
     * @param array $failures errors keyed by the element at fault
     * @return array errors keyed by the element formslib will render
     */
    private function map_to_group_names(array $failures): array {
        $groupfor = [
            'scopetype' => 'scopetypegroup',
            scope_validator::SINGLE_ELEMENT => 'singletopicgroup',
        ];

        $mapped = [];
        foreach ($failures as $element => $message) {
            $target = $groupfor[$element] ?? $element;
            // Only re-key onto a group that this form actually rendered.
            if (!$this->_form->elementExists($target)) {
                $target = $element;
            }
            $mapped[$target] = $message;
        }

        return $mapped;
    }
}
