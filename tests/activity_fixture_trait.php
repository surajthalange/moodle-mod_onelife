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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A ready-to-play activity for tests that drive the run lifecycle.
 *
 * Shared by the run_manager tests and the event tests, which both need the same
 * thing: a course, a bank holding one topic of questions, an enrolled learner and an
 * activity instance. Keeping one copy means a change to how the bank is built, such
 * as the 5.0 move into mod_qbank, is made once.
 *
 * Not autoloaded: Moodle does not autoload from tests/, so each test file requires it
 * directly, the same way core's mod_forum tests pull in their generator trait.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife;

use context_course;
use context_module;
use stdClass;

/**
 * Builds the activity fixture into properties on the using test case.
 */
trait activity_fixture_trait {
    /** @var stdClass The activity instance under test. */
    private stdClass $instance;

    /** @var int The topic category holding the questions. */
    private int $topicid;

    /** @var int The learner. */
    private int $userid;

    /** @var int The course module id, which is the module context's instance id. */
    private int $cmid;

    /**
     * Build a course, a bank with questions, and an activity instance.
     *
     * Leaves no user logged in. Tests that care who acts, such as the event tests,
     * call setUser() themselves so that the decision is visible where it matters.
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

        $this->instance = $this->getDataGenerator()->create_module('onelife', [
            'course' => $course->id,
            'targetstreak' => 15,
            'allowedmodes' => 'single,multi,all',
        ]);
        $cm = get_coursemodule_from_instance('onelife', $this->instance->id, $course->id, false, MUST_EXIST);
        $this->cmid = (int) $cm->id;
    }
}
