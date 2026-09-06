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
 * Behat steps for mod_onelife.
 *
 * @package    mod_onelife
 * @category   test
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Builds a topic bank for a course, wherever this Moodle version keeps question banks.
 *
 * One step, and only because core has no version-agnostic way to express it. A topic
 * bank is a category with child categories, and core's "question categories" generator
 * cannot create that pair across the supported range:
 *
 * On 5.0 and later a request for a course context is relocated into a question bank
 * module, but the child's parent is still validated against the course context it was
 * asked for, so the child is always rejected. On 4.5 the alternative, naming an
 * "Activity module" context, cannot be used at all because mod_qbank does not exist.
 *
 * Courses, users, enrolments and the questions themselves still come from core's own
 * generators. Only the category hierarchy is built here, using the same branch the
 * plugin itself uses to decide where banks live.
 *
 * @package    mod_onelife
 * @category   test
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_onelife extends behat_base {
    /**
     * Create a topic bank with child topics in a course.
     *
     * @Given a One Life topic bank :bank with topics :topics exists in course :course
     *
     * @param string $bank the bank category name
     * @param string $topics comma separated topic names
     * @param string $course the course shortname
     */
    public function a_topic_bank_with_topics_exists_in_course(string $bank, string $topics, string $course): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/questionlib.php');

        $courserecord = $DB->get_record('course', ['shortname' => $course], '*', MUST_EXIST);
        $contextid = $this->bank_contextid($courserecord);

        $topid = $DB->get_field('question_categories', 'id', ['contextid' => $contextid, 'parent' => 0]);
        if (!$topid) {
            $topid = $this->create_category($contextid, 0, 'top')->id;
        }

        $bankcategory = $this->create_category($contextid, (int) $topid, $bank);

        foreach (array_filter(array_map('trim', explode(',', $topics))) as $topicname) {
            $this->create_category($contextid, (int) $bankcategory->id, $topicname);
        }
    }

    /**
     * The context a question bank lives in for this course, on this version.
     *
     * @param stdClass $course the course
     * @return int the context id
     */
    protected function bank_contextid(stdClass $course): int {
        if (\core_component::get_component_directory('mod_qbank') !== null) {
            $bank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
                $course,
                true
            );

            return context_module::instance($bank->id)->id;
        }

        return context_course::instance($course->id)->id;
    }

    /**
     * Insert one question category.
     *
     * @param int $contextid the context
     * @param int $parent the parent category id, 0 for the hidden top category
     * @param string $name the category name
     * @return stdClass the created record
     */
    protected function create_category(int $contextid, int $parent, string $name): stdClass {
        global $DB;

        $sortorder = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {question_categories} WHERE parent = ?',
            [$parent]
        );

        $record = (object) [
            'name' => $name,
            'contextid' => $contextid,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'stamp' => make_unique_id_code(),
            'parent' => $parent,
            'sortorder' => $sortorder,
        ];
        $record->id = $DB->insert_record('question_categories', $record);

        return $record;
    }
}
