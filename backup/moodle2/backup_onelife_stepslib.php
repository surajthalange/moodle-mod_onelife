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
 * Backup steps for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Defines the structure written to onelife.xml.
 *
 * Runs and answers are learner data, so they are only included when the userinfo
 * setting is on. The instance settings always go.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_onelife_activity_structure_step extends backup_activity_structure_step {
    /**
     * Build the backup structure.
     *
     * @return backup_nested_element the activity structure
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $onelife = new backup_nested_element('onelife', ['id'], [
            'name',
            'intro',
            'introformat',
            'topicbankcategoryid',
            'targetstreak',
            'allowedmodes',
            'completionstreak',
            'timecreated',
            'timemodified',
        ]);

        $runs = new backup_nested_element('runs');

        $run = new backup_nested_element('run', ['id'], [
            'userid',
            'scopetype',
            'topicids',
            'streak',
            'targetstreak',
            'currentquestionid',
            'timecreated',
            'timefinish',
        ]);

        $answers = new backup_nested_element('answers');

        $answer = new backup_nested_element('answer', ['id'], [
            'topicid',
            'questionid',
            'correct',
            'timecreated',
        ]);

        $onelife->add_child($runs);
        $runs->add_child($run);

        $run->add_child($answers);
        $answers->add_child($answer);

        $onelife->set_source_table('onelife', ['id' => backup::VAR_ACTIVITYID]);

        if ($userinfo) {
            $run->set_source_table('onelife_run', ['onelifeid' => backup::VAR_PARENTID], 'id ASC');
            $answer->set_source_table('onelife_answer', ['runid' => backup::VAR_PARENTID], 'id ASC');
        }

        // The configured topic bank is an instance setting, so it is annotated on the
        // activity itself rather than on the answers. Annotating it only via answers
        // would lose it from a settings-only backup, where no answers are written.
        $onelife->annotate_ids('question_category', 'topicbankcategoryid');

        $run->annotate_ids('user', 'userid');

        // Annotating the categories makes an activity-level backup carry them, so a
        // restored run still names the topics it was played over. topicids holds a
        // list rather than a single id and cannot be annotated, so it is remapped by
        // hand on restore.
        $answer->annotate_ids('question_category', 'topicid');

        // Questions are deliberately not annotated. annotate_ids('question', ...) is
        // legacy: since question versioning arrived, core pulls questions in through
        // the question bank steps and the reference helpers, not through that
        // annotation, and mod_quiz no longer uses it either. Adding it here would
        // imply a guarantee that does not hold.
        //
        // The consequence, confirmed by restoring both ways: a course backup carries
        // the question bank, so answers remap onto the right questions. An
        // activity-only backup does not carry it, so there is nothing to remap onto
        // and the restore records the question as unknown rather than pointing at
        // whatever id happens to be free on the target site.

        $onelife->annotate_files('mod_onelife', 'intro', null);

        return $this->prepare_activity_structure($onelife);
    }
}
