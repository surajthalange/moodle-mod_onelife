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
 * Tests for the scope picker's server-side rules.
 *
 * The picker's client side is deliberately scriptless: single-topic mode uses radio
 * inputs, so the browser enforces "exactly one" natively. These rules are still the
 * authority, and every one of them must hold for a submission that was forged or
 * made with scripting disabled.
 *
 * The rules take the allowed modes and the valid topic ids as arguments rather than
 * looking them up, so they need no database and can run under basic_testcase.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\local;

/**
 * Tests for the scope_validator class.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\local\scope_validator
 */
final class scope_validator_test extends \basic_testcase {
    /** @var int[] Topic ids that exist in the fixture bank. */
    private const TOPICS = [11, 12, 13];

    /**
     * Topic element names are prefixed and never bare integers.
     *
     * Same trap as the settings form: an integer name is read by
     * HTML_QuickForm_group::getElementName() as a positional index, which silently
     * breaks hideIf() for the whole group. Topic ids are integers, so this is the
     * most likely place in the plugin to fall into it.
     */
    public function test_topic_element_names_are_prefixed_and_never_numeric(): void {
        foreach (self::TOPICS as $topicid) {
            $name = scope_validator::topic_element_name($topicid);

            $this->assertStringStartsWith('topic_', $name);
            $this->assertFalse(is_numeric($name), "Element name {$name} must not be numeric.");
        }
    }

    /**
     * In multi mode, unchecked topic boxes are not read as chosen.
     *
     * advcheckbox posts a zero through its hidden fallback input rather than being
     * absent, so selection is decided by value.
     */
    public function test_chosen_topics_ignores_unchecked_boxes(): void {
        $submitted = [
            'scopetype' => 'multi',
            'topic_11' => '1',
            'topic_12' => '0',
            'topic_13' => 0,
        ];

        $this->assertSame([11], scope_validator::chosen_topics('multi', $submitted));
    }

    /**
     * In single mode the choice comes from the radio, not the checkboxes.
     */
    public function test_chosen_topics_reads_the_single_radio(): void {
        $submitted = ['scopetype' => 'single', 'singletopic' => '12', 'topic_11' => '1'];

        $this->assertSame([12], scope_validator::chosen_topics('single', $submitted));
    }

    /**
     * All-topics mode selects nothing explicitly, whatever was posted.
     */
    public function test_chosen_topics_is_empty_for_all_mode(): void {
        $submitted = ['scopetype' => 'all', 'topic_11' => '1', 'singletopic' => '12'];

        $this->assertSame([], scope_validator::chosen_topics('all', $submitted));
    }

    /**
     * A mode the teacher did not permit is rejected, even though it never rendered.
     *
     * Not rendering a control is presentation. Refusing it on submission is the
     * actual restriction, and it is the only one that survives a forged post.
     */
    public function test_mode_not_permitted_is_rejected(): void {
        $submitted = ['scopetype' => 'all'];

        $errors = scope_validator::validate(['single', 'multi'], self::TOPICS, $submitted);

        $this->assertArrayHasKey('scopetype', $errors);
    }

    /**
     * Each permitted mode is accepted in turn.
     */
    public function test_each_permitted_mode_is_accepted(): void {
        $cases = [
            'single' => ['scopetype' => 'single', 'singletopic' => '11'],
            'multi' => ['scopetype' => 'multi', 'topic_11' => '1'],
            'all' => ['scopetype' => 'all'],
        ];

        foreach ($cases as $mode => $submitted) {
            $errors = scope_validator::validate([$mode], self::TOPICS, $submitted);

            $this->assertArrayNotHasKey('scopetype', $errors, "Mode {$mode} should be permitted.");
        }
    }

    /**
     * An unrecognised mode string is rejected.
     */
    public function test_unknown_mode_is_rejected(): void {
        $errors = scope_validator::validate(['single', 'multi', 'all'], self::TOPICS, ['scopetype' => 'sniper']);

        $this->assertArrayHasKey('scopetype', $errors);
    }

    /**
     * A missing mode is rejected rather than defaulted.
     */
    public function test_missing_mode_is_rejected(): void {
        $errors = scope_validator::validate(['single', 'multi', 'all'], self::TOPICS, []);

        $this->assertArrayHasKey('scopetype', $errors);
    }

    /**
     * Single mode requires exactly one topic, and rejects none.
     */
    public function test_single_mode_requires_a_topic(): void {
        $errors = scope_validator::validate(['single'], self::TOPICS, ['scopetype' => 'single']);

        $this->assertArrayHasKey('singletopic', $errors);
    }

    /**
     * Single mode accepts exactly one valid topic.
     */
    public function test_single_mode_accepts_one_topic(): void {
        $submitted = ['scopetype' => 'single', 'singletopic' => '12'];

        $this->assertSame([], scope_validator::validate(['single'], self::TOPICS, $submitted));
    }

    /**
     * The one-topic cap is enforced server-side, not only by the radio input.
     *
     * Radios make more than one selection impossible in a browser, but a forged post
     * can carry anything, so the cap is re-checked here.
     */
    public function test_single_mode_rejects_more_than_one_topic(): void {
        $submitted = ['scopetype' => 'single', 'singletopic' => ['11', '12']];

        $errors = scope_validator::validate(['single'], self::TOPICS, $submitted);

        $this->assertArrayHasKey('singletopic', $errors);
    }

    /**
     * A topic id outside the bank is rejected in single mode.
     */
    public function test_single_mode_rejects_a_topic_outside_the_bank(): void {
        $submitted = ['scopetype' => 'single', 'singletopic' => '9999'];

        $errors = scope_validator::validate(['single'], self::TOPICS, $submitted);

        $this->assertArrayHasKey('singletopic', $errors);
    }

    /**
     * Multi mode requires at least one topic.
     */
    public function test_multi_mode_requires_at_least_one_topic(): void {
        $submitted = ['scopetype' => 'multi', 'topic_11' => '0', 'topic_12' => '0'];

        $errors = scope_validator::validate(['multi'], self::TOPICS, $submitted);

        $this->assertArrayHasKey(scope_validator::TOPIC_GROUP, $errors);
    }

    /**
     * Multi mode accepts several topics.
     */
    public function test_multi_mode_accepts_several_topics(): void {
        $submitted = ['scopetype' => 'multi', 'topic_11' => '1', 'topic_13' => '1'];

        $this->assertSame([], scope_validator::validate(['multi'], self::TOPICS, $submitted));
    }

    /**
     * A topic id outside the bank is rejected in multi mode.
     */
    public function test_multi_mode_rejects_a_topic_outside_the_bank(): void {
        $submitted = ['scopetype' => 'multi', 'topic_11' => '1', 'topic_9999' => '1'];

        $errors = scope_validator::validate(['multi'], self::TOPICS, $submitted);

        $this->assertArrayHasKey(scope_validator::TOPIC_GROUP, $errors);
    }

    /**
     * All-topics mode needs no topic selection.
     */
    public function test_all_mode_needs_no_topics(): void {
        $this->assertSame([], scope_validator::validate(['all'], self::TOPICS, ['scopetype' => 'all']));
    }

    /**
     * A bank with no topics cannot be played, whatever was submitted.
     */
    public function test_no_topics_in_the_bank_is_rejected(): void {
        $errors = scope_validator::validate(['all'], [], ['scopetype' => 'all']);

        $this->assertArrayHasKey('scopetype', $errors);
    }
}
