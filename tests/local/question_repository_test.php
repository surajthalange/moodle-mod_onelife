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
 * Tests for the question pool repository.
 *
 * These are the querying half of PRD section 6.1's select_question. The choosing
 * half stays pure in engine, so the rules that decide which questions may ever be
 * served are tested here against a real question bank.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

use context_course;
use context_module;

/**
 * Tests for the question_repository class.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\local\question_repository
 */
final class question_repository_test extends \advanced_testcase {
    /**
     * The nearest context a bank can live in on this version.
     *
     * @param int $courseid the course
     * @return int a context id
     */
    private function bank_context_id(int $courseid): int {
        if (\core_component::get_component_directory('mod_qbank') !== null) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $courseid]);
            return context_module::instance($qbank->cmid)->id;
        }

        return context_course::instance($courseid)->id;
    }

    /**
     * Create a category, returning the stored record.
     *
     * @param array $record the fields
     * @return \stdClass the stored category
     */
    private function make_category(array $record): \stdClass {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $created = $generator->create_question_category($record);

        return $DB->get_record('question_categories', ['id' => $created->id], '*', MUST_EXIST);
    }

    /**
     * Single-answer multichoice questions in a category and its descendants are pooled.
     */
    public function test_pool_includes_single_answer_multichoice(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Cells']);

        $question = $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);

        $pool = (new question_repository())->get_pool([(int) $topic->id]);

        $this->assertContains((int) $question->id, $pool);
    }

    /**
     * Questions in child categories are reached, not only those filed at the top.
     *
     * Topic categories are routinely containers with the questions a level down, so
     * failing to expand descendants would empty most real banks.
     */
    public function test_pool_expands_into_descendant_categories(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $contextid = $this->bank_context_id($course->id);

        $topic = $this->make_category(['contextid' => $contextid, 'name' => 'Cells']);
        $child = $this->make_category(['contextid' => $topic->contextid, 'parent' => $topic->id, 'name' => 'Mitosis']);

        $deep = $generator->create_question('multichoice', 'one_of_four', ['category' => $child->id]);

        $pool = (new question_repository())->get_pool([(int) $topic->id]);

        $this->assertContains((int) $deep->id, $pool, 'Questions in a child category must be reachable.');
    }

    /**
     * Question types other than multichoice are excluded.
     */
    public function test_pool_excludes_other_question_types(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Cells']);

        $truefalse = $generator->create_question('truefalse', null, ['category' => $topic->id]);
        $multichoice = $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);

        $pool = (new question_repository())->get_pool([(int) $topic->id]);

        $this->assertContains((int) $multichoice->id, $pool);
        $this->assertNotContains((int) $truefalse->id, $pool, 'v1 serves single-answer multichoice only.');
    }

    /**
     * Multiple-response multichoice is excluded: only single = 1 qualifies.
     */
    public function test_pool_excludes_multiple_response_multichoice(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Cells']);

        $single = $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);
        $multi = $generator->create_question('multichoice', 'two_of_four', ['category' => $topic->id]);

        $pool = (new question_repository())->get_pool([(int) $topic->id]);

        $this->assertContains((int) $single->id, $pool);
        $this->assertNotContains((int) $multi->id, $pool, 'A checkbox question cannot be answered with one click.');
    }

    /**
     * A question whose only version is a draft is never served.
     */
    public function test_pool_excludes_draft_questions(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Cells']);

        $draft = $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);
        $DB->set_field('question_versions', 'status', 'draft', ['questionid' => $draft->id]);

        $pool = (new question_repository())->get_pool([(int) $topic->id]);

        $this->assertNotContains((int) $draft->id, $pool, 'A draft is not ready to be asked.');
    }

    /**
     * An empty category yields an empty pool rather than an error.
     */
    public function test_pool_is_empty_for_a_category_with_no_questions(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Empty']);

        $this->assertSame([], (new question_repository())->get_pool([(int) $topic->id]));
    }

    /**
     * No categories at all yields an empty pool.
     */
    public function test_pool_is_empty_for_no_categories(): void {
        $this->resetAfterTest();

        $this->assertSame([], (new question_repository())->get_pool([]));
    }

    /**
     * A loaded question carries its answers keyed by id, with fractions.
     *
     * This is the shape engine::score_answer expects, so the repository adapts
     * Moodle's options->answers into it rather than making the engine know about
     * question bank internals.
     */
    public function test_load_returns_answers_keyed_by_id(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topic = $this->make_category(['contextid' => $this->bank_context_id($course->id), 'name' => 'Cells']);
        $created = $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);

        $question = (new question_repository())->load((int) $created->id);

        $this->assertNotNull($question);
        $this->assertSame((int) $created->id, (int) $question->id);
        $this->assertNotEmpty($question->answers);

        foreach ($question->answers as $answerid => $answer) {
            $this->assertSame($answerid, (int) $answer->id, 'Answers must be keyed by their own id.');
            $this->assertObjectHasProperty('fraction', $answer);
        }

        $fractions = array_map(static fn($a) => (float) $a->fraction, array_values($question->answers));
        $this->assertGreaterThan(0.0, max($fractions), 'One answer must be correct.');
    }

    /**
     * Loading a question that no longer exists returns null rather than throwing.
     *
     * A question can be deleted from the bank while a run is in progress, and that
     * must be recoverable rather than fatal.
     */
    public function test_load_returns_null_for_a_missing_question(): void {
        $this->resetAfterTest();

        $this->assertNull((new question_repository())->load(123456789));
    }
}
