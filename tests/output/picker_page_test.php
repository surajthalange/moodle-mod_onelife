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
 * Tests for the picker page renderable.
 *
 * The renderable is where the decision about what a learner may see is made, so the
 * template only ever renders what it is handed. Testing export_for_template() is
 * therefore testing the visibility rules themselves.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\output;

use stdClass;

/**
 * Tests for the picker_page class.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\output\picker_page
 */
final class picker_page_test extends \advanced_testcase {
    /**
     * Export a picker page and return the template context.
     *
     * @param string $allowedmodes the instance's allowedmodes column
     * @param array $topics topic id to name
     * @param bool $hasbank whether a usable bank was resolved
     * @param string $formhtml the rendered form
     * @param string[] $warnings warnings to surface
     * @return stdClass the template context
     */
    private function export(
        string $allowedmodes,
        array $topics,
        bool $hasbank = true,
        string $formhtml = '<form></form>',
        array $warnings = []
    ): stdClass {
        global $PAGE;

        $instance = new stdClass();
        $instance->id = 1;
        $instance->name = 'Revision Sprint';
        $instance->allowedmodes = $allowedmodes;
        $instance->targetstreak = 15;

        $page = new picker_page($instance, $topics, $hasbank, $formhtml, $warnings);

        return $page->export_for_template($PAGE->get_renderer('mod_suddendeath'));
    }

    /**
     * Render the picker form for a set of modes and topics.
     *
     * The mode and topic controls belong to the form, not the template, so the
     * "must not render" requirement is asserted against the thing that renders them.
     *
     * @param string[] $allowedmodes the permitted modes
     * @param array $topics topic id to name
     * @return string the rendered form
     */
    private function render_form(array $allowedmodes, array $topics): string {
        $form = new \mod_suddendeath\form\scope_picker_form(null, [
            'modes' => $allowedmodes,
            'topics' => $topics,
            'cmid' => 1,
        ]);

        return $form->render();
    }

    /**
     * A mode the teacher did not permit is absent from the markup.
     *
     * Not merely hidden: a control that exists in the page can be re-enabled from
     * the console, so the requirement is that it is never rendered at all.
     */
    public function test_form_renders_only_permitted_modes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(['single', 'all'], [11 => 'Cells']);

        $this->assertStringContainsString(get_string('mode_single', 'mod_suddendeath'), $html);
        $this->assertStringContainsString(get_string('mode_all', 'mod_suddendeath'), $html);
        $this->assertStringNotContainsString(get_string('mode_multi', 'mod_suddendeath'), $html);
    }

    /**
     * A single permitted mode renders alone.
     */
    public function test_form_with_one_permitted_mode_renders_only_that_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(['multi'], [11 => 'Cells']);

        $this->assertStringContainsString(get_string('mode_multi', 'mod_suddendeath'), $html);
        $this->assertStringNotContainsString(get_string('mode_single', 'mod_suddendeath'), $html);
    }

    /**
     * Every topic in the bank is offered, with its name.
     */
    public function test_form_renders_every_topic(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(['multi'], [11 => 'Anatomy', 12 => 'Microbiology', 13 => 'Zoology']);

        $this->assertStringContainsString('Anatomy', $html);
        $this->assertStringContainsString('Microbiology', $html);
        $this->assertStringContainsString('Zoology', $html);
    }

    /**
     * Topic checkboxes carry prefixed names, never bare integers.
     */
    public function test_form_topic_elements_are_prefixed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_form(['multi'], [11 => 'Cells']);

        $this->assertStringContainsString('name="topic_11"', $html);
    }

    /**
     * The topic count is exported so the template can describe the bank.
     */
    public function test_topic_count_is_exported(): void {
        $this->resetAfterTest();

        $context = $this->export('multi', [11 => 'Anatomy', 12 => 'Microbiology']);

        $this->assertSame(2, $context->topiccount);
    }

    /**
     * With no usable bank the page reports it rather than rendering an empty form.
     *
     * An empty form invites the learner to submit nothing and get an error. Saying
     * why there is nothing to play is the useful outcome.
     */
    public function test_no_usable_bank_reports_instead_of_rendering_a_form(): void {
        $this->resetAfterTest();

        $context = $this->export('single,multi,all', [], false);

        $this->assertFalse($context->hasbank);
        $this->assertTrue($context->hasnotice);
        $this->assertNotEmpty($context->notice);
        $this->assertSame('', $context->formhtml);
    }

    /**
     * A bank that resolves but holds no topics is treated the same way.
     */
    public function test_bank_with_no_topics_reports_instead_of_rendering_a_form(): void {
        $this->resetAfterTest();

        $context = $this->export('single,multi,all', [], true);

        $this->assertTrue($context->hasnotice);
        $this->assertSame('', $context->formhtml);
    }

    /**
     * A usable bank renders the form and no notice.
     */
    public function test_usable_bank_renders_the_form(): void {
        $this->resetAfterTest();

        $context = $this->export('single,multi,all', [11 => 'Cells'], true, '<form id="picker"></form>');

        $this->assertTrue($context->hasbank);
        $this->assertFalse($context->hasnotice);
        $this->assertSame('<form id="picker"></form>', $context->formhtml);
    }

    /**
     * Warnings from topic resolution are surfaced to the teacher.
     */
    public function test_warnings_are_exported(): void {
        $this->resetAfterTest();

        $context = $this->export('all', [11 => 'Cells'], true, '<form></form>', ['Bank went missing']);

        $this->assertTrue($context->haswarnings);
        $this->assertSame(['Bank went missing'], array_column($context->warnings, 'message'));
    }

    /**
     * No warnings means the warning region is not rendered at all.
     */
    public function test_no_warnings_means_no_warning_region(): void {
        $this->resetAfterTest();

        $context = $this->export('all', [11 => 'Cells']);

        $this->assertFalse($context->haswarnings);
        $this->assertSame([], $context->warnings);
    }

    /**
     * The instance name is exported already formatted for output.
     */
    public function test_instance_name_is_exported(): void {
        $this->resetAfterTest();

        $context = $this->export('all', [11 => 'Cells']);

        $this->assertSame('Revision Sprint', $context->name);
    }

    /**
     * The exported context renders through the real template without error.
     *
     * This is what makes the test cover the template as well as the renderable: a
     * context missing something picker.mustache needs fails here.
     */
    public function test_context_renders_through_the_real_template(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('mod_suddendeath');
        $context = $this->export('single,multi,all', [11 => 'Cells', 12 => 'Genetics']);

        $html = $renderer->render_from_template('mod_suddendeath/picker', $context);

        $this->assertStringContainsString('Revision Sprint', $html);
        $this->assertStringContainsString('suddendeath-picker', $html);
        // The form is passed through untouched, which is the template's whole job here.
        $this->assertStringContainsString('<form></form>', $html);
    }

    /**
     * The no-bank case renders the notice through the real template.
     */
    public function test_no_bank_case_renders_the_notice(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('mod_suddendeath');
        $context = $this->export('single,multi,all', [], false);

        $html = $renderer->render_from_template('mod_suddendeath/picker', $context);

        $this->assertStringContainsString(get_string('topicbanknone', 'mod_suddendeath'), $html);
    }
}
