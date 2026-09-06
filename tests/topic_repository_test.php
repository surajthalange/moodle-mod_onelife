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
 * Tests for the mod_suddendeath topic repository.
 *
 * Every behaviour covered here was a real defect in the reference implementation,
 * so each test is written to fail against the naive version of the same logic.
 *
 * These tests need the database and core's question generator, so they extend
 * advanced_testcase rather than basic_testcase.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath;

use context_course;
use context_coursecat;
use context_module;
use stdClass;

/**
 * Tests for the topic_repository class.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\topic_repository
 */
final class topic_repository_test extends \advanced_testcase {
    /**
     * Whether this Moodle ships mod_qbank, which is the 5.0+ question bank module.
     *
     * mod_qbank does not exist on 4.5. Its presence, rather than a version number,
     * is what the repository branches on.
     *
     * @return bool true when mod_qbank is installed
     */
    private function has_qbank_module(): bool {
        return \core_component::get_component_directory('mod_qbank') !== null;
    }

    /**
     * Skip the current test unless mod_qbank is available.
     */
    private function require_qbank_module(): void {
        if (!$this->has_qbank_module()) {
            $this->markTestSkipped('mod_qbank is not available before Moodle 5.0.');
        }
    }

    /**
     * Skip the current test when mod_qbank is available.
     */
    private function require_no_qbank_module(): void {
        if ($this->has_qbank_module()) {
            $this->markTestSkipped('Legacy context layout only applies before Moodle 5.0.');
        }
    }

    /**
     * Create a question category in a context of our choosing, bypassing the generator.
     *
     * Needed because the core generator will not put a category in a non-module context
     * on 5.0+, and core's own question_get_top_category() refuses one outright: it
     * returns false for any context that is not CONTEXT_MODULE, without even handing
     * back a top row that already exists. So there is no supported way to build a
     * course-category bank on a current site, and the generator quietly relocates the
     * request instead.
     *
     * That shape still exists in the wild, on any site upgraded from 4.5, and this is
     * how it is stored there. Writing the rows directly is the only way to cover it on
     * 5.0+; the alternative, taken previously, was to skip the case on every version
     * the plugin is actually likely to run on. Verified against a real 5.2 site before
     * being written here.
     *
     * @param int $contextid the context to file the category in
     * @param int $parent the parent category id, 0 for the hidden top category
     * @param string $name the category name
     * @return stdClass the stored category record
     */
    private function make_raw_category(int $contextid, int $parent, string $name): stdClass {
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

    /**
     * Build a whole bank in one context: top, bank, one topic, one question.
     *
     * @param int $contextid the context to build in
     * @param string $bankname the bank category name
     * @param string $topicname the topic category name
     * @return stdClass the bank category
     */
    private function make_raw_bank(int $contextid, string $bankname, string $topicname): stdClass {
        global $DB;

        $topid = $DB->get_field('question_categories', 'id', ['contextid' => $contextid, 'parent' => 0]);
        if (!$topid) {
            $topid = (int) $this->make_raw_category($contextid, 0, 'top')->id;
        }

        $bank = $this->make_raw_category($contextid, (int) $topid, $bankname);
        $topic = $this->make_raw_category($contextid, (int) $bank->id, $topicname);
        $this->make_question((int) $topic->id);

        return $bank;
    }

    /**
     * Create a question category, returning the record as actually stored.
     *
     * The core generator rewrites contextid on 5.0+: a non-module context causes it to
     * provision a qbank instance and file the category under that module context
     * instead. Re-reading the row means fixtures assert against where the category
     * really landed rather than where it was requested.
     *
     * @param array $record fields for the new category
     * @return stdClass the stored category record
     */
    private function make_category(array $record): stdClass {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $created = $generator->create_question_category($record);

        return $DB->get_record('question_categories', ['id' => $created->id], '*', MUST_EXIST);
    }

    /**
     * Add one single-answer multichoice question to a category.
     *
     * @param int $categoryid the category to file the question under
     * @return stdClass the created question
     */
    private function make_question(int $categoryid): stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        return $generator->create_question('multichoice', 'one_of_four', ['category' => $categoryid]);
    }

    /**
     * Build the auto-provisioned "Default for X" category for a context.
     *
     * Named exactly as core names it, via get_string, so the test never depends on
     * the English wording.
     *
     * @param int $contextid the context to create it in
     * @return stdClass the stored category record
     */
    private function make_default_category(int $contextid): stdClass {
        $context = \core\context::instance_by_id($contextid);
        $name = get_string('defaultfor', 'question', $context->get_context_name(false, true));

        return $this->make_category(['contextid' => $contextid, 'name' => $name]);
    }

    /**
     * The version branch is decided by mod_qbank being installed, not by luck.
     *
     * Asserting the predicate directly means a run on 4.5 and a run on 5.x each prove
     * they took the branch they were supposed to, rather than passing because the
     * fixture happened to suit both.
     */
    public function test_version_branch_follows_qbank_availability(): void {
        $this->resetAfterTest();

        $this->assertSame(
            $this->has_qbank_module(),
            topic_repository::uses_qbank_module_contexts(),
            'The repository must branch on mod_qbank being installed.'
        );
    }

    /**
     * On 5.0+ the contexts of the course's qbank modules are searched, nearest first.
     */
    public function test_context_ids_include_qbank_module_contexts(): void {
        $this->resetAfterTest();
        $this->require_qbank_module();

        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontextid = context_module::instance($qbank->cmid)->id;

        $repository = new topic_repository();
        $contextids = $repository->get_context_ids($course->id);

        $this->assertContains($qbankcontextid, $contextids, 'The qbank module context must be searched.');
        $this->assertSame(
            $qbankcontextid,
            $contextids[0],
            'The qbank module context is nearer than the course context and must come first.'
        );
    }

    /**
     * The course context is always searched, on every supported version.
     */
    public function test_context_ids_include_the_course_context(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $coursecontextid = context_course::instance($course->id)->id;

        $repository = new topic_repository();

        $this->assertContains($coursecontextid, $repository->get_context_ids($course->id));
    }

    /**
     * Parent contexts are searched too, nearest first, which is the shared-bank case.
     *
     * A bank shared across a whole course category lives at the category context, not
     * inside each course, so a course-context-only search would never find it.
     */
    public function test_context_ids_include_parent_contexts_nearest_first(): void {
        $this->resetAfterTest();

        $categoryid = $this->getDataGenerator()->create_category()->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);

        $coursecontextid = context_course::instance($course->id)->id;
        $catcontextid = context_coursecat::instance($categoryid)->id;

        $repository = new topic_repository();
        $contextids = $repository->get_context_ids($course->id);

        $this->assertContains($coursecontextid, $contextids);
        $this->assertContains($catcontextid, $contextids);
        $this->assertLessThan(
            array_search($catcontextid, $contextids, true),
            array_search($coursecontextid, $contextids, true),
            'The course context is nearer than its category and must be searched first.'
        );
    }

    /**
     * A bank in the course context is found on the pre-5.0 layout.
     */
    public function test_bank_in_course_context_is_found(): void {
        $this->resetAfterTest();
        $this->require_no_qbank_module();

        $course = $this->getDataGenerator()->create_course();
        $coursecontextid = context_course::instance($course->id)->id;

        $bank = $this->make_category(['contextid' => $coursecontextid, 'name' => 'Topic bank']);
        $topic = $this->make_category(['contextid' => $coursecontextid, 'parent' => $bank->id, 'name' => 'Cells']);
        $this->make_question((int) $topic->id);

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($bank->id, $found->id);
    }

    /**
     * A bank at the course category context is found on every supported version.
     *
     * The pre-5.0 version of this test is skipped from 5.0 onwards, because the core
     * generator will not build the shape there. That left the case unverified on 5.0,
     * 5.1 and 5.2, which are the versions most sites are on, while the walk through
     * parent contexts stayed in the code for exactly those sites. Building the rows
     * directly closes that gap.
     */
    public function test_bank_at_course_category_context_is_found_on_any_version(): void {
        $this->resetAfterTest();

        $categoryid = $this->getDataGenerator()->create_category()->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);
        $catcontextid = (int) context_coursecat::instance($categoryid)->id;

        $bank = $this->make_raw_bank($catcontextid, 'Department bank', 'Shared physics');

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found, 'The walk through parent contexts must reach the category context.');
        $this->assertEquals($bank->id, $found->id);
        $this->assertSame(['Shared physics'], array_values($repository->get_topics($course->id, (int) $found->id)));
    }

    /**
     * A nearer bank wins even when its category id is higher.
     *
     * Resolution must be by context distance, not by id. Ordering by id instead lets a
     * department or system bank outrank the course's own for as long as it was created
     * first, which is always. The ids here are deliberately the wrong way round: an
     * id-ordered implementation passes every other test in this file and fails here.
     */
    public function test_a_nearer_bank_wins_over_a_lower_id_bank_further_away(): void {
        $this->resetAfterTest();

        $categoryid = $this->getDataGenerator()->create_category()->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);
        $catcontextid = (int) context_coursecat::instance($categoryid)->id;
        $coursecontextid = (int) context_course::instance($course->id)->id;

        $far = $this->make_raw_bank($catcontextid, 'Department bank', 'Shared physics');
        $near = $this->make_raw_bank($coursecontextid, 'Course bank', 'Course optics');

        $this->assertGreaterThan(
            $far->id,
            $near->id,
            'The nearer bank must have the higher id or this test proves nothing.'
        );

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($near->id, $found->id, 'The nearer context must win despite the higher id.');
    }

    /**
     * A bank in a parent context is found on the pre-5.0 layout.
     */
    public function test_bank_in_parent_context_is_found(): void {
        $this->resetAfterTest();
        $this->require_no_qbank_module();

        $categoryid = $this->getDataGenerator()->create_category()->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);
        $catcontextid = context_coursecat::instance($categoryid)->id;

        $bank = $this->make_category(['contextid' => $catcontextid, 'name' => 'Shared bank']);
        $topic = $this->make_category(['contextid' => $catcontextid, 'parent' => $bank->id, 'name' => 'Cells']);
        $this->make_question((int) $topic->id);

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($bank->id, $found->id);
    }

    /**
     * The nearest context wins even when its category id is higher.
     *
     * The fixture is built so that the far context holds the LOWER id. An
     * implementation that picks the globally lowest candidate id passes every other
     * test in this file and fails this one, which is the point of it.
     */
    public function test_nearest_context_wins_over_lower_category_id(): void {
        $this->resetAfterTest();

        $categoryid = $this->getDataGenerator()->create_category()->id;
        $course = $this->getDataGenerator()->create_course(['category' => $categoryid]);

        // Far context first, so it takes the lower id.
        $farcontextid = context_coursecat::instance($categoryid)->id;
        $farbank = $this->make_category(['contextid' => $farcontextid, 'name' => 'Far shared bank']);
        $this->make_category(['contextid' => $farbank->contextid, 'parent' => $farbank->id, 'name' => 'Far topic']);

        // Near context second, so it takes the higher id.
        $nearcontextid = $this->nearest_bank_context_id($course->id);
        $nearbank = $this->make_category(['contextid' => $nearcontextid, 'name' => 'Near course bank']);
        $this->make_category(['contextid' => $nearbank->contextid, 'parent' => $nearbank->id, 'name' => 'Near topic']);

        $this->assertGreaterThan(
            (int) $farbank->id,
            (int) $nearbank->id,
            'Fixture invalid: the nearer bank must have the higher id for this test to mean anything.'
        );

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found);
        $this->assertEquals(
            $nearbank->id,
            $found->id,
            'The nearer context must win even though the far one has the lower category id.'
        );
    }

    /**
     * The auto-provisioned "Default for X" category is skipped despite a lower id.
     *
     * The default category is deliberately given a child here. A implementation that
     * only filters on "has children" would pick it, because it also has the lower id.
     * Only matching the name excludes it.
     */
    public function test_default_for_category_is_skipped_despite_lower_id(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = $this->nearest_bank_context_id($course->id);

        // Created first, so it holds the lower id, exactly as core provisions it.
        $default = $this->make_default_category($contextid);
        $this->make_category(['contextid' => $default->contextid, 'parent' => $default->id, 'name' => 'Stray child']);

        $real = $this->make_category(['contextid' => $contextid, 'name' => 'Real topic bank']);
        $this->make_category(['contextid' => $real->contextid, 'parent' => $real->id, 'name' => 'Cells']);

        $this->assertLessThan(
            (int) $real->id,
            (int) $default->id,
            'Fixture invalid: the default category must have the lower id for this test to mean anything.'
        );

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, null);

        $this->assertNotNull($found);
        $this->assertEquals($real->id, $found->id, 'The auto-provisioned default category must never be chosen.');
    }

    /**
     * An explicit topicbankcategoryid on the instance beats auto-detection.
     */
    public function test_explicit_category_id_overrides_autodetection(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = $this->nearest_bank_context_id($course->id);

        $auto = $this->make_category(['contextid' => $contextid, 'name' => 'Would be auto-detected']);
        $this->make_category(['contextid' => $auto->contextid, 'parent' => $auto->id, 'name' => 'Auto topic']);

        $chosen = $this->make_category(['contextid' => $contextid, 'name' => 'Explicitly configured']);
        $this->make_category(['contextid' => $chosen->contextid, 'parent' => $chosen->id, 'name' => 'Chosen topic']);

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, (int) $chosen->id);

        $this->assertNotNull($found);
        $this->assertEquals($chosen->id, $found->id);
        $this->assertSame([], $repository->get_warnings(), 'A valid explicit id must not warn.');
    }

    /**
     * An invalid explicit id falls back to auto-detection and surfaces a warning.
     */
    public function test_invalid_explicit_category_id_falls_back_and_warns(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = $this->nearest_bank_context_id($course->id);

        $auto = $this->make_category(['contextid' => $contextid, 'name' => 'Auto-detected bank']);
        $this->make_category(['contextid' => $auto->contextid, 'parent' => $auto->id, 'name' => 'Cells']);

        $repository = new topic_repository();
        $found = $repository->get_topic_bank_category($course->id, 123456789);

        $this->assertNotNull($found, 'An unusable explicit id must fall back, not fail.');
        $this->assertEquals($auto->id, $found->id);
        $this->assertNotEmpty($repository->get_warnings(), 'Falling back silently would hide a misconfiguration.');
    }

    /**
     * get_topics returns the bank's children keyed by id and ordered by name.
     */
    public function test_get_topics_returns_children_ordered_by_name(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = $this->nearest_bank_context_id($course->id);

        $bank = $this->make_category(['contextid' => $contextid, 'name' => 'Topic bank']);
        // Deliberately created out of alphabetical order.
        $this->make_category(['contextid' => $bank->contextid, 'parent' => $bank->id, 'name' => 'Zoology']);
        $this->make_category(['contextid' => $bank->contextid, 'parent' => $bank->id, 'name' => 'Anatomy']);
        $this->make_category(['contextid' => $bank->contextid, 'parent' => $bank->id, 'name' => 'Microbiology']);

        $repository = new topic_repository();
        $topics = $repository->get_topics($course->id, (int) $bank->id);

        $this->assertSame(['Anatomy', 'Microbiology', 'Zoology'], array_values($topics));
    }

    /**
     * A topic holding no questions of its own is still usable via its children.
     *
     * Topic categories legitimately act as containers, with the questions one level
     * down. Requiring questions directly in the topic would hide most real banks.
     */
    public function test_topic_with_questions_only_in_children_is_usable(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $contextid = $this->nearest_bank_context_id($course->id);

        $bank = $this->make_category(['contextid' => $contextid, 'name' => 'Topic bank']);
        $topic = $this->make_category(['contextid' => $bank->contextid, 'parent' => $bank->id, 'name' => 'Cells']);
        $child = $this->make_category(['contextid' => $bank->contextid, 'parent' => $topic->id, 'name' => 'Mitosis']);

        // Questions live only in the grandchild, never directly in the topic.
        $this->make_question((int) $child->id);

        $repository = new topic_repository();

        $found = $repository->get_topic_bank_category($course->id, null);
        $this->assertNotNull($found);
        $this->assertEquals($bank->id, $found->id);

        $topics = $repository->get_topics($course->id, (int) $bank->id);
        $this->assertArrayHasKey((int) $topic->id, $topics);

        // Expanding the topic must reach the child that actually holds the questions.
        $descendants = question_categorylist((int) $topic->id);
        $this->assertContains((int) $child->id, array_map('intval', $descendants));
    }

    /**
     * The nearest context a bank can be created in for this course on this version.
     *
     * On 5.0+ that is a qbank module context; before that it is the course context.
     * Fixtures use this so they express "the near context" rather than hard-coding a
     * layout that only exists on one branch.
     *
     * @param int $courseid the course
     * @return int a context id
     */
    private function nearest_bank_context_id(int $courseid): int {
        if ($this->has_qbank_module()) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $courseid]);
            return context_module::instance($qbank->cmid)->id;
        }

        return context_course::instance($courseid)->id;
    }
}
