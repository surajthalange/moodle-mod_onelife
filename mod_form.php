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
 * Settings form for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_suddendeath\local\modes;
use mod_suddendeath\local\settings_validator;
use mod_suddendeath\topic_repository;

/**
 * Module settings form.
 *
 * Four traps from PRD section 7 are designed around here rather than worked around
 * later:
 *
 * - Every dynamic element is prefixed via modes::element_name(). A bare integer name
 *   is read by HTML_QuickForm_group::getElementName() as a positional index, which
 *   silently breaks hideIf() and disabledIf() for the whole group.
 * - Unchecked advcheckbox elements post a zero through a hidden fallback input, so
 *   selections are read by value in modes::from_form_data(), never by key presence.
 * - Nothing is frozen. A frozen element submits nothing at all, so freezing without
 *   a matching setConstant() silently drops the value.
 * - No required rule is attached to anything conditionally hidden. The topic bank
 *   select is always visible, and the completion streak is guarded by
 *   completion_rule_enabled() rather than by a rule that cannot be satisfied while
 *   the field is hidden.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_suddendeath_mod_form extends moodleform_mod {
    /**
     * Define the form elements.
     */
    public function definition() {
        global $CFG, $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('suddendeathname', 'mod_suddendeath'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'suddendeathname', 'mod_suddendeath');

        $this->standard_intro_elements();

        // Topic bank: a select built from categories this course can actually reach.
        // Never a raw id field, so a teacher cannot type an unreachable id at all.
        $mform->addElement(
            'select',
            'topicbankcategoryid',
            get_string('topicbank', 'mod_suddendeath'),
            $this->topic_bank_options((int) $COURSE->id)
        );
        $mform->setType('topicbankcategoryid', PARAM_INT);
        $mform->setDefault('topicbankcategoryid', 0);
        $mform->addHelpButton('topicbankcategoryid', 'topicbank', 'mod_suddendeath');

        // Where authors learn that a horizontal rule splits their general feedback.
        $mform->addElement(
            'static',
            'explanationconvention',
            get_string('explanationconvention', 'mod_suddendeath'),
            ''
        );
        $mform->addHelpButton('explanationconvention', 'explanationconvention', 'mod_suddendeath');

        $mform->addElement('text', 'targetstreak', get_string('targetstreak', 'mod_suddendeath'), ['size' => 6]);
        $mform->setType('targetstreak', PARAM_RAW_TRIMMED);
        $mform->setDefault('targetstreak', 15);
        $mform->addHelpButton('targetstreak', 'targetstreak', 'mod_suddendeath');

        $modeelements = [];
        foreach (modes::codes() as $code) {
            $modeelements[] = $mform->createElement(
                'advcheckbox',
                modes::element_name($code),
                '',
                modes::label($code)
            );
        }
        $mform->addGroup(
            $modeelements,
            modes::GROUP_NAME,
            get_string('allowedmodes', 'mod_suddendeath'),
            ['<br />'],
            false
        );
        $mform->addHelpButton(modes::GROUP_NAME, 'allowedmodes', 'mod_suddendeath');
        foreach (modes::codes() as $code) {
            $mform->setType(modes::element_name($code), PARAM_INT);
            $mform->setDefault(modes::element_name($code), 1);
        }

        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Options for the topic bank select: auto-detect, then the reachable banks.
     *
     * @param int $courseid the course being edited
     * @return array option value to label, keyed by category id with 0 for auto-detect
     */
    private function topic_bank_options(int $courseid): array {
        global $DB;

        $options = [0 => get_string('topicbankautodetect', 'mod_suddendeath')];

        $repository = new topic_repository();

        foreach ($repository->get_context_ids($courseid) as $contextid) {
            $topid = $DB->get_field('question_categories', 'id', ['contextid' => $contextid, 'parent' => 0]);
            if (!$topid) {
                continue;
            }

            $candidates = $DB->get_records_select(
                'question_categories',
                'contextid = ? AND parent = ?',
                [$contextid, $topid],
                'sortorder, id',
                'id, name, contextid'
            );

            foreach ($candidates as $candidate) {
                if (!$repository->is_usable_bank($courseid, (int) $candidate->id)) {
                    continue;
                }
                $context = \core\context::instance_by_id((int) $candidate->contextid, IGNORE_MISSING);
                $label = format_string($candidate->name);
                if ($context) {
                    $label .= ' (' . $context->get_context_name(false, true) . ')';
                }
                $options[(int) $candidate->id] = $label;
            }
        }

        return $options;
    }

    /**
     * Add the custom completion rule.
     *
     * Using the completion rule API rather than a plain element is what lets core
     * show and hide the field with completion tracking, so no required rule is ever
     * attached to something the teacher cannot see.
     *
     * @return array names of the elements added
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $group = [
            $mform->createElement(
                'checkbox',
                'completionstreakenabled',
                '',
                get_string('completionstreak', 'mod_suddendeath')
            ),
            $mform->createElement('text', 'completionstreak', '', ['size' => 4]),
        ];
        $mform->setType('completionstreak', PARAM_RAW_TRIMMED);

        $mform->addGroup(
            $group,
            'completionstreakgroup',
            get_string('completionstreakgroup', 'mod_suddendeath'),
            [' '],
            false
        );
        $mform->addHelpButton('completionstreakgroup', 'completionstreak', 'mod_suddendeath');

        // Named elements, not integers, so this actually takes effect.
        $mform->disabledIf('completionstreak', 'completionstreakenabled', 'notchecked');

        return ['completionstreakgroup'];
    }

    /**
     * Whether the custom completion rule is switched on.
     *
     * @param array $data the submitted form data
     * @return bool true when the rule is in use
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completionstreakenabled']) && (int) $data['completionstreak'] > 0;
    }

    /**
     * Expand stored values into form elements before the form is displayed.
     *
     * @param array $defaultvalues the instance values, modified in place
     */
    public function data_preprocessing(&$defaultvalues) {
        $selected = modes::from_storage($defaultvalues['allowedmodes'] ?? null);

        // A new instance has no stored value, so offer every mode by default.
        $isnew = !isset($defaultvalues['allowedmodes']);
        foreach (modes::codes() as $code) {
            $defaultvalues[modes::element_name($code)] = $isnew || in_array($code, $selected, true) ? 1 : 0;
        }

        $streak = (int) ($defaultvalues['completionstreak'] ?? 0);
        $defaultvalues['completionstreakenabled'] = $streak > 0 ? 1 : 0;
        if ($streak <= 0) {
            $defaultvalues['completionstreak'] = '';
        }
    }

    /**
     * Collapse form elements back into the stored representation.
     *
     * @param stdClass $data the submitted data, modified in place
     */
    public function data_postprocessing($data) {
        $data->allowedmodes = modes::to_storage(modes::from_form_data((array) $data));

        if (empty($data->completionstreakenabled) || (int) $data->completionstreak < 1) {
            $data->completionstreak = 0;
        } else {
            $data->completionstreak = (int) $data->completionstreak;
        }

        $data->targetstreak = (int) $data->targetstreak;
    }

    /**
     * Server-side validation.
     *
     * Delegates in full to settings_validator, so the rules that are tested and the
     * rules that are enforced cannot drift apart.
     *
     * @param array $data the submitted data
     * @param array $files the submitted files
     * @return array element name to error message
     */
    public function validation($data, $files) {
        global $COURSE;

        $errors = parent::validation($data, $files);

        $validator = new settings_validator(new topic_repository());

        return array_merge($errors, $validator->validate((int) $COURSE->id, (array) $data));
    }
}
