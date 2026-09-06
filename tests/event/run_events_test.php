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
 * Tests for the run lifecycle events.
 *
 * The events are fired from run_manager rather than from play.php, so a run started
 * from anywhere is logged the same way. These tests drive run_manager directly,
 * which is the only thing that can prove that.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\event;

use context_course;
use context_module;
use mod_suddendeath\local\modes;
use mod_suddendeath\local\question_repository;
use mod_suddendeath\local\run_manager;
use stdClass;

/**
 * Tests that run_started and run_finished fire once, and only where they should.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\event\run_started
 * @covers     \mod_suddendeath\event\run_finished
 */
final class run_events_test extends \advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var int The topic category holding the questions. */
    private int $topicid;

    /** @var int The learner. */
    private int $userid;

    /** @var int The course module id, which is the event's context instance. */
    private int $cmid;

    /**
     * Build a course, a bank with questions, and an activity instance.
     *
     * @param int $questioncount how many questions to put in the topic
     */
    private function set_up_activity(int $questioncount = 5): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->getDataGenerator()->enrol_user($this->userid, $course->id);

        // Question banks moved into their own module in 5.0. On 4.5 the course context
        // still holds them, so the bank is placed wherever this version keeps it.
        if (\core_component::get_component_directory('mod_qbank') !== null) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
            $contextid = context_module::instance($qbank->cmid)->id;
        } else {
            $contextid = context_course::instance($course->id)->id;
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $bank = $generator->create_question_category(['contextid' => $contextid, 'name' => 'Bank']);
        $topic = $generator->create_question_category([
            'contextid' => $bank->contextid,
            'parent' => $bank->id,
            'name' => 'Cells',
        ]);
        $this->topicid = (int) $topic->id;

        for ($i = 0; $i < $questioncount; $i++) {
            $generator->create_question('multichoice', 'one_of_four', ['category' => $topic->id]);
        }

        $this->instance = $this->getDataGenerator()->create_module('suddendeath', [
            'course' => $course->id,
            'targetstreak' => 15,
            'allowedmodes' => 'single,multi,all',
        ]);
        $cm = get_coursemodule_from_instance('suddendeath', $this->instance->id, $course->id, false, MUST_EXIST);
        $this->cmid = (int) $cm->id;

        // Runs only ever start from a learner's own request, so the acting user is the
        // learner. Driving run_manager with nobody logged in would log every event as
        // user 0 and quietly make the description assertions meaningless.
        $this->setUser($this->userid);
    }

    /**
     * Start a run for the test learner.
     *
     * @param run_manager|null $manager the manager to use
     * @return stdClass|null the run
     */
    private function start(?run_manager $manager = null): ?stdClass {
        $manager = $manager ?? new run_manager();

        return $manager->start_or_resume($this->instance, $this->userid, modes::SINGLE, [$this->topicid]);
    }

    /**
     * The id of the correct answer for a question.
     *
     * @param int $questionid the question
     * @return int the answer id with the highest fraction
     */
    private function correct_answer_id(int $questionid): int {
        $question = (new question_repository())->load($questionid);
        $best = 0;
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
     * @return int an answer id scoring zero
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

    /**
     * Answer the run's current question correctly.
     *
     * @param run_manager $manager the manager under test
     * @param stdClass $run the run
     * @return stdClass the outcome
     */
    private function answer_correctly(run_manager $manager, stdClass $run): stdClass {
        $questionid = (int) $run->currentquestionid;

        return $manager->answer($run, $this->userid, $questionid, $this->correct_answer_id($questionid));
    }

    /**
     * Answer the run's current question wrongly, ending the run.
     *
     * @param run_manager $manager the manager under test
     * @param stdClass $run the run
     * @return stdClass the outcome
     */
    private function answer_wrongly(run_manager $manager, stdClass $run): stdClass {
        $questionid = (int) $run->currentquestionid;

        return $manager->answer($run, $this->userid, $questionid, $this->wrong_answer_id($questionid));
    }

    /**
     * Only this plugin's run events, in the order they fired.
     *
     * @param \phpunit_event_sink $sink the sink
     * @return \core\event\base[] the matching events
     */
    private function run_events(\phpunit_event_sink $sink): array {
        return array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof run_started || $event instanceof run_finished;
        }));
    }

    /**
     * Starting a run fires run_started exactly once.
     */
    public function test_starting_a_run_fires_run_started_once(): void {
        $this->set_up_activity();

        $sink = $this->redirectEvents();
        $run = $this->start();
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(run_started::class, $events[0]);
        $this->assertSame((int) $run->id, (int) $events[0]->objectid);
        $this->assertSame($this->cmid, (int) $events[0]->contextinstanceid);
        $this->assertSame($this->userid, (int) $events[0]->relateduserid);
        $this->assertSame(modes::SINGLE, $events[0]->other['scopetype']);
    }

    /**
     * Resuming an open run does not fire run_started again.
     *
     * A learner who refreshes, or who comes back to the activity page, goes through
     * start_or_resume every time. Firing there would turn one run into a stream of
     * starts and make any report built on the event useless.
     */
    public function test_resuming_a_run_does_not_refire_run_started(): void {
        $this->set_up_activity();

        $first = $this->start();

        $sink = $this->redirectEvents();
        $second = $this->start();
        $third = $this->start();
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame((int) $first->id, (int) $third->id);
        $this->assertSame([], $events, 'Resuming must fire nothing.');
    }

    /**
     * A wrong answer fires run_finished exactly once, carrying the streak reached.
     */
    public function test_a_wrong_answer_fires_run_finished_once(): void {
        $this->set_up_activity();

        $manager = new run_manager();
        $run = $this->start($manager);

        // One correct answer first, so the streak in the event is a number that could
        // only have come from the run rather than the zero it started at.
        $this->answer_correctly($manager, $run);
        $run = $manager->get_in_progress_run((int) $this->instance->id, $this->userid);

        $sink = $this->redirectEvents();
        $outcome = $this->answer_wrongly($manager, $run);
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertTrue($outcome->finished);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(run_finished::class, $events[0]);
        $this->assertSame((int) $run->id, (int) $events[0]->objectid);
        $this->assertSame(1, (int) $events[0]->other['streak']);
        $this->assertSame(modes::SINGLE, $events[0]->other['scopetype']);
    }

    /**
     * Exhausting the pool fires run_finished exactly once.
     *
     * This is the other way a run ends inside answer(), and it is easy to miss because
     * the last answer was correct.
     */
    public function test_exhausting_the_pool_fires_run_finished_once(): void {
        $this->set_up_activity(2);

        $manager = new run_manager();
        $run = $this->start($manager);

        $sink = $this->redirectEvents();
        for ($i = 0; $i < 2; $i++) {
            $run = $manager->get_in_progress_run((int) $this->instance->id, $this->userid);
            if ($run === null) {
                break;
            }
            $outcome = $this->answer_correctly($manager, $run);
        }
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertTrue($outcome->exhausted);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(run_finished::class, $events[0]);
        $this->assertSame(2, (int) $events[0]->other['streak']);
    }

    /**
     * Closing a run through finish() fires run_finished exactly once.
     *
     * finish() is the path taken when the bank changes mid-run and no replacement
     * question is available, so it needs the event as much as answer() does.
     */
    public function test_finishing_directly_fires_run_finished_once(): void {
        $this->set_up_activity();

        $manager = new run_manager();
        $run = $this->start($manager);

        $sink = $this->redirectEvents();
        $manager->finish($run);
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(run_finished::class, $events[0]);
        $this->assertSame((int) $run->id, (int) $events[0]->objectid);
    }

    /**
     * Closing an already closed run fires nothing.
     *
     * Without the guard a double submit, or finish() landing on a run that answer()
     * has already closed, would log the same run finishing twice.
     */
    public function test_finishing_an_already_closed_run_fires_nothing(): void {
        $this->set_up_activity();

        $manager = new run_manager();
        $run = $this->start($manager);
        $manager->finish($run);

        $sink = $this->redirectEvents();
        $manager->finish($run);
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertSame([], $events);
    }

    /**
     * One full run produces exactly one start and one finish, in that order.
     */
    public function test_a_whole_run_fires_one_of_each(): void {
        $this->set_up_activity();

        $sink = $this->redirectEvents();

        $manager = new run_manager();
        $run = $this->start($manager);
        $this->start($manager);
        $this->answer_correctly($manager, $run);
        $run = $manager->get_in_progress_run((int) $this->instance->id, $this->userid);
        $this->answer_wrongly($manager, $run);

        $events = $this->run_events($sink);
        $sink->close();

        $this->assertCount(2, $events);
        $this->assertInstanceOf(run_started::class, $events[0]);
        $this->assertInstanceOf(run_finished::class, $events[1]);
    }

    /**
     * Neither event carries anything that identifies a question.
     *
     * Event data is broadly readable. A question id beside a streak would tell a
     * reader which question ended the run, which over a cohort amounts to publishing
     * the answer key.
     */
    public function test_the_events_name_no_question(): void {
        $this->set_up_activity();

        $sink = $this->redirectEvents();
        $manager = new run_manager();
        $run = $this->start($manager);
        $questionid = (int) $run->currentquestionid;
        $manager->answer($run, $this->userid, $questionid, $this->wrong_answer_id($questionid));
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $keys = array_keys($event->other);
            $this->assertSame([], array_intersect($keys, ['questionid', 'currentquestionid', 'answerid']));

            $serialised = (string) json_encode($event->get_data());
            $message = 'No part of the event may carry the question id.';
            $this->assertStringNotContainsString((string) $questionid, $serialised, $message);
        }
    }

    /**
     * Both events describe themselves without error.
     *
     * get_name and get_description are called by every log report, so a missing lang
     * string or a bad field surfaces there rather than here.
     */
    public function test_the_events_describe_themselves(): void {
        $this->set_up_activity();

        $sink = $this->redirectEvents();
        $manager = new run_manager();
        $run = $this->start($manager);
        $this->answer_wrongly($manager, $run);
        $events = $this->run_events($sink);
        $sink->close();

        $this->assertSame('Run started', $events[0]::get_name());
        $this->assertSame('Run finished', $events[1]::get_name());

        foreach ($events as $event) {
            $this->assertStringContainsString((string) $this->userid, $event->get_description());
            $this->assertStringContainsString('/mod/suddendeath/view.php', $event->get_url()->out(false));
        }
    }
}
