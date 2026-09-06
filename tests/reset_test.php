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
 * Tests for course reset.
 *
 * Before this the module appeared under "these activities can't be reset", which
 * left a teacher no way to clear a year's runs before reusing a course.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife;

use stdClass;

/**
 * Tests for onelife_reset_userdata and its form hooks.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::onelife_reset_userdata
 */
final class reset_test extends \advanced_testcase {
    /** @var stdClass The course being reset. */
    private stdClass $course;

    /** @var stdClass An activity in that course. */
    private stdClass $instance;

    /** @var stdClass An activity in an unrelated course. */
    private stdClass $otherinstance;

    /**
     * Build two courses, each with an activity holding a run.
     */
    private function set_up(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/onelife/lib.php');

        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();

        $this->instance = $this->getDataGenerator()->create_module('onelife', ['course' => $this->course->id]);
        $this->otherinstance = $this->getDataGenerator()->create_module('onelife', ['course' => $othercourse->id]);

        $this->make_run((int) $this->instance->id);
        $this->make_run((int) $this->instance->id);
        $this->make_run((int) $this->otherinstance->id);
    }

    /**
     * Record a finished run with one answer.
     *
     * @param int $onelifeid the instance
     */
    private function make_run(int $onelifeid): void {
        global $DB;

        $runid = $DB->insert_record('onelife_run', (object) [
            'onelifeid' => $onelifeid,
            'userid' => 5,
            'scopetype' => 'all',
            'topicids' => '3,4',
            'streak' => 3,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => time() - 200,
            'timefinish' => time() - 100,
        ]);

        $DB->insert_record('onelife_answer', (object) [
            'runid' => $runid,
            'topicid' => 3,
            'questionid' => 9,
            'correct' => 1,
            'timecreated' => time() - 150,
        ]);
    }

    /**
     * Resetting removes runs and answers for the course being reset.
     */
    public function test_reset_removes_runs_and_answers(): void {
        global $DB;

        $this->set_up();

        $data = (object) ['courseid' => $this->course->id, 'reset_onelife_all' => 1];
        $status = onelife_reset_userdata($data);

        $this->assertNotEmpty($status);
        $this->assertSame(0, $DB->count_records('onelife_run', ['onelifeid' => $this->instance->id]));

        $orphans = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {onelife_answer} a
               LEFT JOIN {onelife_run} r ON r.id = a.runid
              WHERE r.id IS NULL'
        );
        $this->assertSame(0, $orphans, 'Answers must go with their runs.');
    }

    /**
     * Resetting one course leaves another alone.
     */
    public function test_reset_does_not_touch_other_courses(): void {
        global $DB;

        $this->set_up();

        onelife_reset_userdata((object) [
            'courseid' => $this->course->id,
            'reset_onelife_all' => 1,
        ]);

        $this->assertSame(
            1,
            $DB->count_records('onelife_run', ['onelifeid' => $this->otherinstance->id]),
            'Another course must be untouched.'
        );
    }

    /**
     * Without the setting ticked, nothing is removed.
     *
     * Course reset runs many components at once. Acting without being asked would
     * destroy data during an unrelated reset.
     */
    public function test_reset_without_the_setting_removes_nothing(): void {
        global $DB;

        $this->set_up();

        $status = onelife_reset_userdata((object) ['courseid' => $this->course->id]);

        $this->assertSame([], $status);
        $this->assertSame(2, $DB->count_records('onelife_run', ['onelifeid' => $this->instance->id]));
    }

    /**
     * The reset report names the component and says what it did.
     */
    public function test_reset_reports_its_outcome(): void {
        $this->set_up();

        $status = onelife_reset_userdata((object) [
            'courseid' => $this->course->id,
            'reset_onelife_all' => 1,
        ]);

        $this->assertCount(1, $status);
        $this->assertSame(get_string('modulenameplural', 'mod_onelife'), $status[0]['component']);
        $this->assertNotEmpty($status[0]['item']);
        $this->assertFalse($status[0]['error']);
    }

    /**
     * The module offers a reset option on the course reset form.
     *
     * This is what moves it out of the "can't be reset" list.
     */
    public function test_reset_form_offers_the_option(): void {
        $this->set_up();

        $form = new \MoodleQuickForm('reset', 'post', '/');
        onelife_reset_course_form_definition($form);

        $this->assertTrue($form->elementExists('reset_onelife_all'));
    }

    /**
     * The option defaults to on, matching every other activity.
     */
    public function test_reset_form_defaults_to_on(): void {
        $this->set_up();

        $defaults = onelife_reset_course_form_defaults($this->course);

        $this->assertArrayHasKey('reset_onelife_all', $defaults);
        $this->assertSame(1, $defaults['reset_onelife_all']);
    }
}
