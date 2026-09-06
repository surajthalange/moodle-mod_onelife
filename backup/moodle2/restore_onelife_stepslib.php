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
 * Restore steps for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Reads onelife.xml back into the database.
 *
 * Three kinds of foreign id have to be translated, not merely copied: the learner,
 * the questions that were asked, and the topic categories that were in scope.
 *
 * Where a question or category has no mapping, the backup did not carry the question
 * bank with it. Those references are cleared rather than kept, because an id that
 * survives untranslated points at whatever happens to occupy it on the target site,
 * and silently misreporting which question a learner answered is worse than
 * recording that it is no longer known.
 *
 * Do not "fix" the empty question mappings by adding annotate_ids('question', ...) to
 * the backup step. That annotation has been a no-op since question versioning landed:
 * questions now travel through the question bank steps and add_question_references(),
 * and core itself, mod_quiz included, no longer relies on the question mapping. Adding
 * it back makes the code look like it guarantees something it does not, and the
 * mappings here stay just as empty.
 *
 * The real division is by backup level, and it is documented in the README so it reads
 * as a known constraint rather than a bug. A course backup includes the bank, so
 * get_mappingid('question', ...) resolves and answers keep their questions. An
 * activity-only backup does not include the bank, so nothing resolves and the question
 * references are cleared. Everything else about a run survives either way: streak,
 * scope, timings, and therefore personal records and statistics.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_onelife_activity_structure_step extends restore_activity_structure_step {
    /**
     * The paths this step handles.
     *
     * @return array the restore path elements
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('onelife', '/activity/onelife');

        if ($userinfo) {
            $paths[] = new restore_path_element('onelife_run', '/activity/onelife/runs/run');
            $paths[] = new restore_path_element(
                'onelife_answer',
                '/activity/onelife/runs/run/answers/answer'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity instance.
     *
     * @param array $data the instance data
     */
    protected function process_onelife($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // The configured topic bank is a question category, so it needs translating
        // like any other. Without a mapping the activity falls back to auto-detection,
        // which is the documented behaviour for an unusable configured id.
        if (!empty($data->topicbankcategoryid)) {
            $data->topicbankcategoryid = $this->get_mappingid(
                'question_category',
                $data->topicbankcategoryid
            ) ?: null;
        }

        $newitemid = $DB->insert_record('onelife', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore one run.
     *
     * @param array $data the run data
     */
    protected function process_onelife_run($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->onelifeid = $this->get_new_parentid('onelife');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        if (!empty($data->timefinish)) {
            $data->timefinish = $this->apply_date_offset($data->timefinish);
        }

        $data->topicids = $this->remap_topic_ids((string) $data->topicids);

        if (!empty($data->currentquestionid)) {
            $mapped = $this->get_mappingid('question', $data->currentquestionid);
            // An in-progress run whose question did not come across cannot be resumed,
            // so it is closed rather than left pointing at nothing.
            if ($mapped) {
                $data->currentquestionid = $mapped;
            } else {
                $data->currentquestionid = null;
                if (empty($data->timefinish)) {
                    $data->timefinish = time();
                }
            }
        }

        $newitemid = $DB->insert_record('onelife_run', $data);
        $this->set_mapping('onelife_run', $oldid, $newitemid);
    }

    /**
     * Restore one answer.
     *
     * @param array $data the answer data
     */
    protected function process_onelife_answer($data) {
        global $DB;

        $data = (object) $data;

        $data->runid = $this->get_new_parentid('onelife_run');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->questionid = (int) $this->get_mappingid('question', $data->questionid);
        $data->topicid = (int) $this->get_mappingid('question_category', $data->topicid);

        $DB->insert_record('onelife_answer', $data);
    }

    /**
     * Translate a comma-separated list of question category ids.
     *
     * Ids with no mapping are dropped rather than carried over, so a restored scope
     * never claims to include a category that means something else here.
     *
     * @param string $stored the stored topicids value
     * @return string the remapped list
     */
    protected function remap_topic_ids(string $stored): string {
        $mapped = [];

        foreach (array_filter(array_map('intval', explode(',', $stored))) as $oldid) {
            $newid = $this->get_mappingid('question_category', $oldid);
            if ($newid) {
                $mapped[] = (int) $newid;
            }
        }

        sort($mapped, SORT_NUMERIC);

        return implode(',', $mapped);
    }

    /**
     * Restore files attached to the activity.
     */
    protected function after_execute() {
        $this->add_related_files('mod_onelife', 'intro', null);
    }
}
