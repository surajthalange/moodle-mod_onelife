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
 * Tests for the custom completion rule.
 *
 * get_completion_state() is gone from both 4.5 and 5.2, so
 * \core_completion\activity_custom_completion is the only API in play across the
 * whole supported range and there is no version divergence to handle.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\completion;

use cm_info;
use stdClass;

/**
 * Tests for the custom_completion class.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var int The learner. */
    private int $userid;

    /**
     * Build a course with completion on and an instance requiring a streak.
     *
     * @param int $completionstreak the streak required, 0 to disable
     */
    private function set_up(int $completionstreak): void {
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->getDataGenerator()->enrol_user($this->userid, $this->course->id);

        $this->instance = $this->getDataGenerator()->create_module('onelife', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionstreak' => $completionstreak,
        ]);
    }

    /**
     * Record a finished run with a given streak.
     *
     * @param int $streak the streak reached
     * @param int $agoseconds how long ago it finished
     */
    private function finished_run(int $streak, int $agoseconds = 100): void {
        global $DB;

        $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $this->instance->id,
            'userid' => $this->userid,
            'scopetype' => 'all',
            'topicids' => '',
            'streak' => $streak,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => time() - $agoseconds - 60,
            'timefinish' => time() - $agoseconds,
        ]);
    }

    /**
     * The completion state for the streak rule.
     *
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    private function state(): int {
        $cm = cm_info::create(get_coursemodule_from_instance('onelife', $this->instance->id));

        return (new custom_completion($cm, $this->userid))->get_state('completionstreak');
    }

    /**
     * The rule this module defines is declared.
     */
    public function test_defined_rules(): void {
        $this->assertSame(['completionstreak'], custom_completion::get_defined_custom_rules());
    }

    /**
     * With no runs at all the rule is not met.
     */
    public function test_no_runs_is_incomplete(): void {
        $this->set_up(5);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state());
    }

    /**
     * A streak below the target does not complete.
     */
    public function test_below_target_is_incomplete(): void {
        $this->set_up(5);

        $this->finished_run(4);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state());
    }

    /**
     * Exactly the target completes.
     */
    public function test_exactly_target_completes(): void {
        $this->set_up(5);

        $this->finished_run(5);

        $this->assertSame(COMPLETION_COMPLETE, $this->state());
    }

    /**
     * Above the target completes.
     */
    public function test_above_target_completes(): void {
        $this->set_up(5);

        $this->finished_run(9);

        $this->assertSame(COMPLETION_COMPLETE, $this->state());
    }

    /**
     * A later, worse run does not undo completion.
     *
     * Completion is an achievement, not a current standing. Having once reached the
     * streak is the thing being recorded, so a bad run afterwards must not take it
     * away.
     */
    public function test_a_later_worse_run_does_not_uncomplete(): void {
        $this->set_up(5);

        $this->finished_run(7, 500);
        $this->assertSame(COMPLETION_COMPLETE, $this->state());

        $this->finished_run(1, 100);

        $this->assertSame(COMPLETION_COMPLETE, $this->state(), 'A worse run must not revoke completion.');
    }

    /**
     * An unfinished run does not complete, however high its streak.
     *
     * Otherwise a learner could sit mid-run on a high streak and be marked complete
     * without ever surviving it.
     */
    public function test_an_unfinished_run_does_not_complete(): void {
        global $DB;

        $this->set_up(5);

        $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $this->instance->id,
            'userid' => $this->userid,
            'scopetype' => 'all',
            'topicids' => '',
            'streak' => 99,
            'targetstreak' => 15,
            'currentquestionid' => 1,
            'timecreated' => time() - 60,
            'timefinish' => null,
        ]);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state());
    }

    /**
     * Another learner's run does not complete this learner's activity.
     */
    public function test_another_users_run_does_not_complete(): void {
        global $DB;

        $this->set_up(5);

        $other = (int) $this->getDataGenerator()->create_user()->id;
        $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $this->instance->id,
            'userid' => $other,
            'scopetype' => 'all',
            'topicids' => '',
            'streak' => 99,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => time() - 200,
            'timefinish' => time() - 100,
        ]);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state());
    }

    /**
     * A run in a different instance does not complete this one.
     */
    public function test_a_run_in_another_instance_does_not_complete(): void {
        global $DB;

        $this->set_up(5);

        $other = $this->getDataGenerator()->create_module('onelife', ['course' => $this->course->id]);
        $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $other->id,
            'userid' => $this->userid,
            'scopetype' => 'all',
            'topicids' => '',
            'streak' => 99,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => time() - 200,
            'timefinish' => time() - 100,
        ]);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->state());
    }

    /**
     * A completionstreak of zero disables the rule.
     *
     * Core decides which rules apply by filtering customdata on a non-empty value,
     * so a zero streak makes the rule unavailable and get_state is never reached for
     * it. Asserting availability is therefore the honest test: calling get_state
     * directly would exercise a path Moodle never takes.
     */
    public function test_zero_disables_the_rule(): void {
        $this->set_up(0);

        $this->finished_run(50);

        $cm = cm_info::create(get_coursemodule_from_instance('onelife', $this->instance->id));
        $available = (new custom_completion($cm, $this->userid))->get_available_custom_rules();

        $this->assertNotContains('completionstreak', $available, 'A zero streak must disable the rule.');
    }

    /**
     * A configured streak makes the rule available.
     */
    public function test_a_set_streak_makes_the_rule_available(): void {
        $this->set_up(5);

        $cm = cm_info::create(get_coursemodule_from_instance('onelife', $this->instance->id));
        $available = (new custom_completion($cm, $this->userid))->get_available_custom_rules();

        $this->assertContains('completionstreak', $available);
    }

    /**
     * The rule description names the streak the teacher asked for.
     */
    public function test_rule_description_mentions_the_target(): void {
        $this->set_up(7);

        $cm = cm_info::create(get_coursemodule_from_instance('onelife', $this->instance->id));
        $descriptions = (new custom_completion($cm, $this->userid))->get_custom_rule_descriptions();

        $this->assertArrayHasKey('completionstreak', $descriptions);
        $this->assertStringContainsString('7', $descriptions['completionstreak']);
    }

    /**
     * View tracking is ordered before the streak rule.
     */
    public function test_sort_order_puts_view_first(): void {
        $this->set_up(5);

        $cm = cm_info::create(get_coursemodule_from_instance('onelife', $this->instance->id));
        $order = (new custom_completion($cm, $this->userid))->get_sort_order();

        $this->assertSame(['completionview', 'completionstreak'], $order);
    }
}
