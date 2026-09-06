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
 * Tests for personal records.
 *
 * This is the first code that reads across runs rather than within one, so it is the
 * first place a missing WHERE clause would leak another learner's data. The
 * isolation tests are built so that dropping either guard changes the answer rather
 * than merely widening it.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\local;

use stdClass;

/**
 * Tests for the stats_repository class.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\local\stats_repository
 */
final class stats_repository_test extends \advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var stdClass A second instance in the same course. */
    private stdClass $otherinstance;

    /** @var int The learner. */
    private int $userid;

    /** @var int A second learner. */
    private int $otheruserid;

    /**
     * Build two instances and two learners.
     */
    private function set_up(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->otheruserid = (int) $this->getDataGenerator()->create_user()->id;

        $this->instance = $this->getDataGenerator()->create_module('onelife', ['course' => $course->id]);
        $this->otherinstance = $this->getDataGenerator()->create_module('onelife', ['course' => $course->id]);
    }

    /**
     * Insert a run row directly, so streaks and times can be set exactly.
     *
     * @param int $onelifeid the instance
     * @param int $userid the learner
     * @param string $scopetype the mode
     * @param string $topicids the raw topicids column value
     * @param int $streak the streak reached
     * @param int|null $timefinish when it finished, or null for an open run
     * @return int the run id
     */
    private function make_run(
        int $onelifeid,
        int $userid,
        string $scopetype,
        string $topicids,
        int $streak,
        ?int $timefinish
    ): int {
        global $DB;

        return (int) $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $onelifeid,
            'userid' => $userid,
            'scopetype' => $scopetype,
            'topicids' => $topicids,
            'streak' => $streak,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => $timefinish ? $timefinish - 60 : time(),
            'timefinish' => $timefinish,
        ]);
    }

    /**
     * Records for the test learner in the test instance.
     *
     * @return array the records
     */
    private function records(): array {
        return (new stats_repository())->get_records((int) $this->instance->id, $this->userid);
    }

    // Scope keying.

    /**
     * Topics chosen in a different order are the same scope.
     *
     * The chosen topics are a set, not a sequence. Which order the checkboxes were
     * read in is an implementation detail, so a learner who picks Cells and Genetics
     * twice must see one record, not two.
     */
    public function test_scope_key_ignores_topic_order(): void {
        $this->assertSame(
            stats_repository::scope_key('multi', [3, 4]),
            stats_repository::scope_key('multi', [4, 3])
        );
    }

    /**
     * Repeated topic ids do not create a different scope.
     */
    public function test_scope_key_ignores_duplicate_topics(): void {
        $this->assertSame(
            stats_repository::scope_key('multi', [3, 4]),
            stats_repository::scope_key('multi', [4, 3, 3, 4])
        );
    }

    /**
     * The mode is part of the scope.
     *
     * Playing every topic because the teacher offered "all topics" is a different
     * choice from ticking every topic by hand, so their bests are not pooled.
     */
    public function test_scope_key_distinguishes_modes(): void {
        $this->assertNotSame(
            stats_repository::scope_key('all', [3, 4]),
            stats_repository::scope_key('multi', [3, 4])
        );
    }

    /**
     * Different topic sets are different scopes.
     */
    public function test_scope_key_distinguishes_topic_sets(): void {
        $this->assertNotSame(
            stats_repository::scope_key('multi', [3, 4]),
            stats_repository::scope_key('multi', [3, 5])
        );
    }

    /**
     * Runs recorded with the topics in either order collapse to one record.
     */
    public function test_runs_with_reordered_topics_share_one_record(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'multi', '3,4', 5, time() - 100);
        $this->make_run((int) $this->instance->id, $this->userid, 'multi', '4,3', 9, time() - 50);

        $records = $this->records();

        $this->assertCount(1, $records, 'Topic order must not split a scope in two.');
        $this->assertSame(9, reset($records)->beststreak);
        $this->assertSame(2, reset($records)->totalruns);
    }

    // Content.

    /**
     * With no finished runs there are no records at all.
     */
    public function test_no_runs_means_no_records(): void {
        $this->set_up();

        $this->assertSame([], $this->records());
    }

    /**
     * The best streak per scope is the highest, not the latest.
     */
    public function test_best_streak_is_the_highest(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 11, time() - 300);
        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 4, time() - 100);

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame(11, reset($records)->beststreak);
    }

    /**
     * The last run is the most recent, which is not always the best.
     */
    public function test_last_run_is_the_most_recent_not_the_best(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 11, time() - 300);
        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 4, time() - 100);

        $records = $this->records();
        $record = reset($records);

        $this->assertSame(11, $record->beststreak);
        $this->assertSame(4, $record->laststreak, 'The last run was the weaker one.');
        $this->assertGreaterThan(0, $record->lasttime);
    }

    /**
     * Runs are counted per scope.
     */
    public function test_total_runs_counted_per_scope(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 2, time() - 400);
        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 3, time() - 300);
        $this->make_run((int) $this->instance->id, $this->userid, 'all', '3,4', 7, time() - 200);

        $records = $this->records();
        $this->assertCount(2, $records);

        $bykey = [];
        foreach ($records as $record) {
            $bykey[$record->scopetype] = $record;
        }

        $this->assertSame(2, $bykey['single']->totalruns);
        $this->assertSame(1, $bykey['all']->totalruns);
    }

    // Isolation. Each fixture gives the excluded rows a HIGHER streak, so dropping
    // the guard changes the reported best rather than merely adding a record.

    /**
     * Another learner's runs in the same instance never appear.
     */
    public function test_another_users_runs_are_excluded(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 3, time() - 200);
        $this->make_run((int) $this->instance->id, $this->otheruserid, 'single', '3', 99, time() - 100);

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame(3, reset($records)->beststreak, "Another learner's streak must not be reported.");
        $this->assertSame(1, reset($records)->totalruns);
    }

    /**
     * The same learner's runs in a different instance never appear.
     */
    public function test_other_instance_runs_are_excluded(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 3, time() - 200);
        $this->make_run((int) $this->otherinstance->id, $this->userid, 'single', '3', 99, time() - 100);

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame(3, reset($records)->beststreak, 'A record from another activity must not leak in.');
        $this->assertSame(1, reset($records)->totalruns);
    }

    /**
     * An in-progress run is not a record yet.
     *
     * Counting an open run would let a learner sit on a high streak they have not
     * yet survived, and would change the best the moment they opened the page.
     */
    public function test_unfinished_runs_are_excluded(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 3, time() - 200);
        $this->make_run((int) $this->instance->id, $this->userid, 'single', '3', 99, null);

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame(3, reset($records)->beststreak);
        $this->assertSame(1, reset($records)->totalruns);
    }

    /**
     * Only finished runs exist and none belong to this learner: no records.
     */
    public function test_records_are_empty_when_every_run_belongs_to_someone_else(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->otheruserid, 'single', '3', 42, time() - 100);

        $this->assertSame([], $this->records());
    }

    // Topic names.

    /**
     * Topic names are resolved for display.
     */
    public function test_topic_names_are_resolved(): void {
        $this->set_up();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['name' => 'Cells']);

        $this->make_run((int) $this->instance->id, $this->userid, 'single', (string) $category->id, 5, time() - 100);

        $records = $this->records();
        $record = reset($records);

        $this->assertSame(['Cells'], $record->topicnames);
    }

    /**
     * A topic deleted since the run degrades to a placeholder rather than erroring.
     *
     * Categories get tidied up. A learner's history should survive that.
     */
    public function test_deleted_topic_degrades_gracefully(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'single', '999999', 5, time() - 100);

        $records = $this->records();

        $this->assertCount(1, $records);
        $record = reset($records);
        $this->assertCount(1, $record->topicnames);
        $this->assertNotSame('', trim($record->topicnames[0]), 'A missing topic still needs something to show.');
    }

    /**
     * A run with no topics recorded, as all-topics mode may produce, still reports.
     */
    public function test_scope_with_no_topics_still_reports(): void {
        $this->set_up();

        $this->make_run((int) $this->instance->id, $this->userid, 'all', '', 6, time() - 100);

        $records = $this->records();

        $this->assertCount(1, $records);
        $this->assertSame(6, reset($records)->beststreak);
        $this->assertSame([], reset($records)->topicnames);
    }

    // Performance.

    /**
     * The query count is fixed and does not grow with runs or topics.
     *
     * This runs on every picker load, so a per-run or per-topic query would make the
     * page slower for exactly the learners who use it most.
     */
    public function test_query_count_does_not_grow(): void {
        global $DB;

        $this->set_up();

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $topicids = [];
        for ($i = 0; $i < 6; $i++) {
            $topicids[] = (int) $generator->create_question_category(['name' => 'Topic ' . $i])->id;
        }

        // A handful of runs across several scopes.
        $this->make_run((int) $this->instance->id, $this->userid, 'single', (string) $topicids[0], 3, time() - 500);
        $this->make_run((int) $this->instance->id, $this->userid, 'multi', implode(',', $topicids), 8, time() - 400);

        $before = $DB->perf_get_reads();
        $this->records();
        $small = $DB->perf_get_reads() - $before;

        for ($i = 0; $i < 20; $i++) {
            $this->make_run(
                (int) $this->instance->id,
                $this->userid,
                'multi',
                implode(',', $topicids),
                $i,
                time() - 300 + $i
            );
        }

        $before = $DB->perf_get_reads();
        $this->records();
        $large = $DB->perf_get_reads() - $before;

        $this->assertSame($small, $large, 'The query count must not grow with the number of runs.');
        $this->assertLessThanOrEqual(2, $large, 'Two queries: the runs, then the topic names.');
    }
}
