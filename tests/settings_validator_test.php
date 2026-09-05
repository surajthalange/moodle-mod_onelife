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
 * Tests for the settings form's server-side validation rules.
 *
 * The rules live in their own class rather than inside mod_form::validation() so
 * they can be exercised without building a form. validation() delegates here, which
 * is what makes the server-side guarantees testable at all.
 *
 * Client-side JavaScript is usability only. Every rule below must hold with
 * JavaScript disabled, so these tests are the real contract.
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
 * Tests for the settings_validator class.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\local\settings_validator
 */
final class settings_validator_test extends \advanced_testcase {
    /**
     * A submission with every field valid, which individual tests then spoil.
     *
     * @param array $overrides fields to replace
     * @return array submitted form data
     */
    private function valid_submission(array $overrides = []): array {
        return $overrides + [
            'topicbankcategoryid' => 0,
            'targetstreak' => '15',
            'completionstreak' => '0',
            'mode_single' => '1',
            'mode_multi' => '0',
            'mode_all' => '0',
        ];
    }

    /**
     * The nearest context a bank can live in for this course on this version.
     *
     * @param int $courseid the course
     * @return int a context id
     */
    private function nearest_bank_context_id(int $courseid): int {
        if (\core_component::get_component_directory('mod_qbank') !== null) {
            $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $courseid]);
            return context_module::instance($qbank->cmid)->id;
        }

        return context_course::instance($courseid)->id;
    }

    /**
     * Build a usable topic bank in a course, returning its category id.
     *
     * @param int $courseid the course
     * @return int the bank category id
     */
    private function make_bank(int $courseid): int {
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $bank = $generator->create_question_category([
            'contextid' => $this->nearest_bank_context_id($courseid),
            'name' => 'Topic bank',
        ]);
        $generator->create_question_category([
            'contextid' => $bank->contextid,
            'parent' => $bank->id,
            'name' => 'Cells',
        ]);

        return (int) $bank->id;
    }

    /**
     * A fully valid submission produces no errors.
     */
    public function test_valid_submission_has_no_errors(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());

        $this->assertSame([], $validator->validate($course->id, $this->valid_submission()));
    }

    /**
     * At least one mode must be permitted.
     *
     * With every box unchecked the activity would offer the learner nothing, so this
     * is rejected server-side rather than left to the client-side script.
     */
    public function test_no_modes_selected_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = $this->valid_submission(['mode_single' => '0', 'mode_multi' => '0', 'mode_all' => '0']);

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $data);

        $this->assertArrayHasKey(modes::GROUP_NAME, $errors);
    }

    /**
     * Any single mode is enough.
     */
    public function test_one_mode_selected_is_accepted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = $this->valid_submission(['mode_single' => '0', 'mode_multi' => '0', 'mode_all' => '1']);

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());

        $this->assertArrayNotHasKey(modes::GROUP_NAME, $validator->validate($course->id, $data));
    }

    /**
     * Data provider for target streak validation.
     *
     * @return array the cases
     */
    public static function targetstreak_provider(): array {
        return [
            'zero is rejected' => ['0', true],
            'negative is rejected' => ['-5', true],
            'blank is rejected' => ['', true],
            'non-numeric is rejected' => ['fifteen', true],
            'one is the minimum and accepted' => ['1', false],
            'the default is accepted' => ['15', false],
            'a large value is accepted' => ['500', false],
        ];
    }

    /**
     * The target streak must be a whole number of at least one.
     *
     * A target of zero would make score_percent meaningless, and the engine already
     * guards against dividing by it. Rejecting it here stops it being stored at all.
     *
     * @dataProvider targetstreak_provider
     * @param string $value the submitted value
     * @param bool $expecterror whether it should be rejected
     */
    public function test_targetstreak_rules(string $value, bool $expecterror): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission(['targetstreak' => $value]));

        if ($expecterror) {
            $this->assertArrayHasKey('targetstreak', $errors);
        } else {
            $this->assertArrayNotHasKey('targetstreak', $errors);
        }
    }

    /**
     * Data provider for completion streak validation.
     *
     * @return array the cases
     */
    public static function completionstreak_provider(): array {
        return [
            'zero disables the rule and is valid' => ['0', false],
            'blank disables the rule and is valid' => ['', false],
            'a positive value is valid' => ['10', false],
            'negative is rejected' => ['-1', true],
            'non-numeric is rejected' => ['ten', true],
        ];
    }

    /**
     * The completion streak may be zero or blank to disable, but never negative.
     *
     * @dataProvider completionstreak_provider
     * @param string $value the submitted value
     * @param bool $expecterror whether it should be rejected
     */
    public function test_completionstreak_rules(string $value, bool $expecterror): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission(['completionstreak' => $value]));

        if ($expecterror) {
            $this->assertArrayHasKey('completionstreak', $errors);
        } else {
            $this->assertArrayNotHasKey('completionstreak', $errors);
        }
    }

    /**
     * Auto-detect, which submits zero, is always acceptable.
     */
    public function test_autodetect_topic_bank_is_accepted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission(['topicbankcategoryid' => 0]));

        $this->assertArrayNotHasKey('topicbankcategoryid', $errors);
    }

    /**
     * A real bank reachable from this course is accepted.
     */
    public function test_reachable_topic_bank_is_accepted(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $bankid = $this->make_bank($course->id);

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission(['topicbankcategoryid' => $bankid]));

        $this->assertArrayNotHasKey('topicbankcategoryid', $errors);
    }

    /**
     * A category id that does not exist at all is rejected.
     */
    public function test_nonexistent_topic_bank_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission(['topicbankcategoryid' => 123456789]));

        $this->assertArrayHasKey('topicbankcategoryid', $errors);
    }

    /**
     * A bank belonging to an unrelated course is rejected.
     *
     * The select is built from reachable categories, so this value can only arrive by
     * tampering. It must fail server-side regardless of what the client sent.
     */
    public function test_topic_bank_from_an_unrelated_course_is_rejected(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $foreignbankid = $this->make_bank($othercourse->id);

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $this->valid_submission([
            'topicbankcategoryid' => $foreignbankid,
        ]));

        $this->assertArrayHasKey('topicbankcategoryid', $errors);
    }

    /**
     * Several bad fields report several errors, not just the first.
     *
     * A form that surfaces one problem per submission makes the teacher play
     * whack-a-mole, so every rule runs on every submission.
     */
    public function test_multiple_problems_are_all_reported(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = $this->valid_submission([
            'targetstreak' => '0',
            'completionstreak' => '-3',
            'mode_single' => '0',
        ]);

        $validator = new settings_validator(new \mod_suddendeath\topic_repository());
        $errors = $validator->validate($course->id, $data);

        $this->assertArrayHasKey('targetstreak', $errors);
        $this->assertArrayHasKey('completionstreak', $errors);
        $this->assertArrayHasKey(modes::GROUP_NAME, $errors);
    }
}
