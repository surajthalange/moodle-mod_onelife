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
 * Tests for the run lifecycle.
 *
 * This is the only part of the plugin that writes learner data, so the three
 * guarantees in PRD section 6.3 are treated as the specification and each has a
 * test that fails without it.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\local;

use mod_onelife\activity_fixture_trait;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../activity_fixture_trait.php');

/**
 * Tests for the run_manager class.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\local\run_manager
 */
final class run_manager_test extends \advanced_testcase {
    use activity_fixture_trait;

    /**
     * Start a run for the test learner.
     *
     * @return stdClass|null the run
     */
    private function start(): ?stdClass {
        return (new run_manager())->start_or_resume($this->instance, $this->userid, modes::SINGLE, [$this->topicid]);
    }

    /**
     * The id of the correct answer for a question.
     *
     * @param int $questionid the question
     * @return int the answer id with the highest fraction
     */
    private function correct_answer_id(int $questionid): int {
        $question = (new question_repository())->load($questionid);
        $best = null;
        $bestfraction = 0.0;
        foreach ($question->answers as $answer) {
            if ((float) $answer->fraction > $bestfraction) {
                $bestfraction = (float) $answer->fraction;
                $best = (int) $answer->id;
            }
        }
        return $best;
    }

    /**
     * The id of a wrong answer for a question.
     *
     * @param int $questionid the question
     * @return int an answer id with fraction zero or less
     */
    private function wrong_answer_id(int $questionid): int {
        $question = (new question_repository())->load($questionid);
        foreach ($question->answers as $answer) {
            if ((float) $answer->fraction <= 0.0) {
                return (int) $answer->id;
            }
        }
        return 0;
    }

    // Guarantee 1: at most one in-progress run, and resuming never re-rolls.

    /**
     * Starting twice resumes the same run rather than creating a second.
     */
    public function test_starting_twice_resumes_the_same_run(): void {
        global $DB;

        $this->set_up_activity();

        $first = $this->start();
        $second = $this->start();

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(
            1,
            $DB->count_records('onelife_run', ['onelifeid' => $this->instance->id, 'userid' => $this->userid]),
            'A second in-progress run must never be created.'
        );
    }

    /**
     * Resuming returns the stored question, never a freshly chosen one.
     *
     * This is the anti-re-roll guarantee. Without it a learner refreshes until an
     * easy question appears, and the streak means nothing.
     */
    public function test_resuming_returns_the_stored_question(): void {
        $this->set_up_activity();

        $first = $this->start();
        $questionid = (int) $first->currentquestionid;

        // Ten resumes: with five questions in the pool, a re-rolling implementation
        // would almost certainly return a different one at least once.
        for ($i = 0; $i < 10; $i++) {
            $resumed = $this->start();
            $this->assertSame($questionid, (int) $resumed->currentquestionid);
        }
    }

    /**
     * A different learner gets their own run, not somebody else's.
     */
    public function test_each_learner_gets_their_own_run(): void {
        $this->set_up_activity();

        $mine = $this->start();
        $otherid = (int) $this->getDataGenerator()->create_user()->id;
        $theirs = (new run_manager())->start_or_resume($this->instance, $otherid, modes::SINGLE, [$this->topicid]);

        $this->assertNotSame((int) $mine->id, (int) $theirs->id);
        $this->assertSame($otherid, (int) $theirs->userid);
    }

    /**
     * A finished run does not block starting a new one.
     */
    public function test_a_finished_run_does_not_block_a_new_one(): void {
        $this->set_up_activity();

        $first = $this->start();
        (new run_manager())->finish($first);

        $second = $this->start();

        $this->assertNotSame((int) $first->id, (int) $second->id);
    }

    // Guarantee 2: the answer row is written before the streak moves.

    /**
     * A crash between recording the answer and updating the streak leaves the
     * answer recorded and the streak untouched.
     *
     * Tests the ordering rather than the end state: the seam throws after the
     * answer is written, so a streak that had already been incremented would show
     * up here as an inflated value with a recorded answer behind it.
     */
    public function test_answer_is_recorded_before_the_streak_moves(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        $manager = new class extends run_manager {
            /**
             * Simulate the request dying after the answer row is written.
             *
             * @param stdClass $run the run
             * @param bool $correct whether the answer was correct
             * @param int|null $nextquestionid the next question
             */
            protected function apply_answer_outcome(stdClass $run, bool $correct, ?int $nextquestionid): void {
                throw new \moodle_exception('error');
            }
        };

        try {
            $manager->answer($run, $this->userid, $questionid, $this->correct_answer_id($questionid));
            $this->fail('The seam should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame(
            1,
            $DB->count_records('onelife_answer', ['runid' => $run->id]),
            'The answer must already be recorded when the streak update is reached.'
        );
        $this->assertSame(
            0,
            (int) $DB->get_field('onelife_run', 'streak', ['id' => $run->id]),
            'The streak must not move until after the answer is recorded.'
        );
    }

    /**
     * A correct answer records the answer and advances the streak.
     */
    public function test_correct_answer_advances_the_streak(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        $result = (new run_manager())->answer($run, $this->userid, $questionid, $this->correct_answer_id($questionid));

        $this->assertTrue($result->accepted);
        $this->assertTrue($result->correct);
        $this->assertSame(1, $result->streak);
        $this->assertFalse($result->finished);
        $this->assertSame(1, $DB->count_records('onelife_answer', ['runid' => $run->id, 'correct' => 1]));
    }

    /**
     * A wrong answer keeps the streak the learner reached as their score.
     *
     * The streak is the score, and almost every run ends in a wrong answer. Resetting
     * it to zero on the way out would record 0 for nearly every run, leaving personal
     * bests able to show only runs that exhausted the pool.
     */
    public function test_wrong_answer_keeps_the_streak_reached(): void {
        global $DB;

        $this->set_up_activity(4);

        $manager = new run_manager();
        $run = $this->start();

        // Two correct answers, then one wrong.
        for ($i = 0; $i < 2; $i++) {
            $questionid = (int) $run->currentquestionid;
            $manager->answer($run, $this->userid, $questionid, $this->correct_answer_id($questionid));
            $run = $manager->get_in_progress_run((int) $this->instance->id, $this->userid);
        }

        $questionid = (int) $run->currentquestionid;
        $result = $manager->answer($run, $this->userid, $questionid, $this->wrong_answer_id($questionid));

        $this->assertTrue($result->finished);
        $this->assertSame(2, $result->streak, 'The reported score must be the streak reached.');
        $this->assertSame(
            2,
            (int) $DB->get_field('onelife_run', 'streak', ['id' => $run->id]),
            'The stored score must be the streak reached, not zero.'
        );
    }

    /**
     * A wrong answer ends the run: that is the whole format.
     */
    public function test_wrong_answer_finishes_the_run(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        $result = (new run_manager())->answer($run, $this->userid, $questionid, $this->wrong_answer_id($questionid));

        $this->assertTrue($result->accepted);
        $this->assertFalse($result->correct);
        $this->assertTrue($result->finished);

        $stored = $DB->get_record('onelife_run', ['id' => $run->id]);
        $this->assertNotNull($stored->timefinish);
        $this->assertNull($stored->currentquestionid);
    }

    // Guarantee 3: finishing clears up after itself.

    /**
     * Finishing sets timefinish and clears currentquestionid.
     */
    public function test_finishing_sets_timefinish_and_clears_the_question(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $this->assertNotNull($run->currentquestionid);

        (new run_manager())->finish($run);

        $stored = $DB->get_record('onelife_run', ['id' => $run->id]);
        $this->assertNotEmpty($stored->timefinish);
        $this->assertNull($stored->currentquestionid);
    }

    // Adversarial cases.

    /**
     * An answer belonging to a different question is refused.
     */
    public function test_answer_from_another_question_is_refused(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        $others = (new question_repository())->get_pool([$this->topicid]);
        $otherid = null;
        foreach ($others as $candidate) {
            if ((int) $candidate !== $questionid) {
                $otherid = (int) $candidate;
                break;
            }
        }
        $foreignanswer = $this->correct_answer_id($otherid);

        $result = (new run_manager())->answer($run, $this->userid, $questionid, $foreignanswer);

        $this->assertFalse($result->accepted);
        $this->assertSame(0, $DB->count_records('onelife_answer', ['runid' => $run->id]));
        $this->assertSame(0, (int) $DB->get_field('onelife_run', 'streak', ['id' => $run->id]));
    }

    /**
     * An answer to a run that has already finished is refused.
     */
    public function test_answer_to_a_finished_run_is_refused(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;
        (new run_manager())->finish($run);

        $reloaded = $DB->get_record('onelife_run', ['id' => $run->id]);
        $result = (new run_manager())->answer($reloaded, $this->userid, $questionid, $this->correct_answer_id($questionid));

        $this->assertFalse($result->accepted);
        $this->assertSame('finished', $result->reason);
        $this->assertSame(0, $DB->count_records('onelife_answer', ['runid' => $run->id]));
    }

    /**
     * Answering somebody else's run is refused.
     */
    public function test_answering_another_users_run_is_refused(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;
        $intruder = (int) $this->getDataGenerator()->create_user()->id;

        $result = (new run_manager())->answer($run, $intruder, $questionid, $this->correct_answer_id($questionid));

        $this->assertFalse($result->accepted);
        $this->assertSame('notyours', $result->reason);
        $this->assertSame(0, $DB->count_records('onelife_answer', ['runid' => $run->id]));
    }

    /**
     * A question that is not the run's current one is refused.
     *
     * This is what stops a learner keeping an old page open and answering a
     * question they have already moved past.
     */
    public function test_answer_for_a_question_that_is_not_current_is_refused(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $stale = 999999;

        $result = (new run_manager())->answer($run, $this->userid, $stale, 1);

        $this->assertFalse($result->accepted);
        $this->assertSame('stale', $result->reason);
        $this->assertSame(0, $DB->count_records('onelife_answer', ['runid' => $run->id]));
    }

    /**
     * A double submission records one answer, not two.
     *
     * The second post is refused as stale rather than treated as idempotent: by the
     * time it arrives the run has moved on, so accepting it would mean either
     * recording the same answer twice or silently discarding a genuine answer to the
     * next question. Refusing is unambiguous, and the controller sends the learner
     * to wherever the run actually is.
     */
    public function test_double_submission_records_one_answer(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;
        $answerid = $this->correct_answer_id($questionid);

        $manager = new run_manager();
        $first = $manager->answer($run, $this->userid, $questionid, $answerid);
        // The second click carries the same stale run object the page was rendered with.
        $second = $manager->answer($run, $this->userid, $questionid, $answerid);

        $this->assertTrue($first->accepted);
        $this->assertFalse($second->accepted);
        $this->assertSame('stale', $second->reason);
        $this->assertSame(1, $DB->count_records('onelife_answer', ['runid' => $run->id]));
        $this->assertSame(1, (int) $DB->get_field('onelife_run', 'streak', ['id' => $run->id]));
    }

    /**
     * A question deleted from the bank mid-run does not break the run.
     */
    public function test_question_deleted_mid_run_is_survivable(): void {
        global $DB;

        $this->set_up_activity();

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        // Remove the question from the pool the way a deletion would.
        $DB->set_field('question_versions', 'status', 'draft', ['questionid' => $questionid]);
        $DB->delete_records('question', ['id' => $questionid]);

        $manager = new run_manager();
        $current = $manager->get_current_question($DB->get_record('onelife_run', ['id' => $run->id]));

        // Either a replacement question is served or the run ends cleanly, but the
        // learner never sees a crash.
        $stored = $DB->get_record('onelife_run', ['id' => $run->id]);
        if ($current === null) {
            $this->assertNotEmpty($stored->timefinish, 'With no question to serve the run must be closed.');
        } else {
            $this->assertNotSame($questionid, (int) $current->id);
        }
    }

    /**
     * Exhausting the pool ends the run cleanly rather than erroring.
     */
    public function test_exhausting_the_pool_finishes_the_run(): void {
        global $DB;

        // One question means the pool is exhausted after a single correct answer.
        $this->set_up_activity(1);

        $run = $this->start();
        $questionid = (int) $run->currentquestionid;

        $result = (new run_manager())->answer($run, $this->userid, $questionid, $this->correct_answer_id($questionid));

        $this->assertTrue($result->accepted);
        $this->assertTrue($result->correct);
        $this->assertTrue($result->finished, 'With nothing left to ask the run must end.');
        $this->assertTrue($result->exhausted);
        $this->assertSame(1, $result->streak, 'The last correct answer still counts.');

        $stored = $DB->get_record('onelife_run', ['id' => $run->id]);
        $this->assertNotEmpty($stored->timefinish);
        $this->assertNull($stored->currentquestionid);
    }

    /**
     * A question is never asked twice in the same run.
     */
    public function test_questions_are_not_repeated_within_a_run(): void {
        $this->set_up_activity(4);

        $manager = new run_manager();
        $run = $this->start();

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $current = (int) $run->currentquestionid;
            if ($current === 0) {
                break;
            }
            $this->assertNotContains($current, $seen, 'A question was served twice in one run.');
            $seen[] = $current;

            $result = $manager->answer($run, $this->userid, $current, $this->correct_answer_id($current));
            if ($result->finished) {
                break;
            }
            $run = $manager->get_in_progress_run((int) $this->instance->id, $this->userid);
        }

        $this->assertGreaterThan(1, count($seen));
    }

    /**
     * Starting with no questions available yields no run at all.
     */
    public function test_no_questions_means_no_run(): void {
        global $DB;

        $this->set_up_activity(0);

        $run = $this->start();

        $this->assertNull($run, 'An unplayable run should not be recorded.');
        $this->assertSame(0, $DB->count_records('onelife_run', ['onelifeid' => $this->instance->id]));
    }
}
