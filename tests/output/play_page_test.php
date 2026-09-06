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
 * Tests for the play and summary renderables.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\output;

use stdClass;

/**
 * Tests for play_page and summary_page.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\output\play_page
 * @covers     \mod_onelife\output\summary_page
 */
final class play_page_test extends \advanced_testcase {
    /**
     * A question stub with four answers.
     *
     * @return stdClass the question
     */
    private function question(): stdClass {
        $question = new stdClass();
        $question->id = 77;
        $question->questiontext = '<p>Which organelle makes ATP?</p>';
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '<p>Correct!</p><hr /><p>ATP is made in the mitochondrion.</p>';
        $question->answers = [];
        foreach ([21 => 'Nucleus', 22 => 'Mitochondrion', 23 => 'Ribosome', 24 => 'Golgi'] as $id => $text) {
            $answer = new stdClass();
            $answer->id = $id;
            $answer->answer = $text;
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = $id === 22 ? 1.0 : 0.0;
            $question->answers[$id] = $answer;
        }
        return $question;
    }

    /**
     * The play page exposes the question, every answer, and the streak.
     */
    public function test_play_page_exports_question_answers_and_streak(): void {
        global $PAGE;

        $this->resetAfterTest();

        $page = new play_page($this->question(), 3, 15, 42);
        $context = $page->export_for_template($PAGE->get_renderer('mod_onelife'));

        $this->assertStringContainsString('ATP', $context->questiontext);
        $this->assertSame(3, $context->streak);
        $this->assertSame(15, $context->targetstreak);
        $this->assertSame(42, $context->cmid);
        $this->assertSame(77, $context->questionid);
        $this->assertCount(4, $context->answers);
        $this->assertSame([21, 22, 23, 24], array_column($context->answers, 'id'));
        $this->assertSame(
            ['Nucleus', 'Mitochondrion', 'Ribosome', 'Golgi'],
            array_column($context->answers, 'text')
        );
    }

    /**
     * The play page never leaks which answer is correct.
     *
     * The fractions are on the question object handed in, so forgetting to strip
     * them would put the answer key in the page source.
     */
    public function test_play_page_does_not_leak_the_correct_answer(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('mod_onelife');
        $context = (new play_page($this->question(), 0, 15, 42))->export_for_template($renderer);

        foreach ($context->answers as $answer) {
            $this->assertArrayNotHasKey('fraction', $answer);
            $this->assertArrayNotHasKey('correct', $answer);
        }

        $html = $renderer->render_from_template('mod_onelife/play', $context);
        $this->assertStringNotContainsString('fraction', $html);
    }

    /**
     * A sesskey is carried, because the play form posts.
     */
    public function test_play_page_carries_a_sesskey(): void {
        global $PAGE;

        $this->resetAfterTest();

        $context = (new play_page($this->question(), 0, 15, 42))
            ->export_for_template($PAGE->get_renderer('mod_onelife'));

        $this->assertSame(sesskey(), $context->sesskey);
    }

    /**
     * The play page renders through the real template.
     */
    public function test_play_page_renders(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('mod_onelife');
        $context = (new play_page($this->question(), 2, 15, 42))->export_for_template($renderer);
        $html = $renderer->render_from_template('mod_onelife/play', $context);

        $this->assertStringContainsString('Mitochondrion', $html);
        $this->assertStringContainsString('Which organelle makes ATP?', $html);
    }

    /**
     * The summary reports the streak, the score and the correct answer.
     */
    public function test_summary_exports_outcome(): void {
        global $PAGE;

        $this->resetAfterTest();

        $page = new summary_page(7, 15, 'Mitochondrion', '<p>ATP is made in the mitochondrion.</p>', false, 42);
        $context = $page->export_for_template($PAGE->get_renderer('mod_onelife'));

        $this->assertSame(7, $context->streak);
        $this->assertSame(15, $context->targetstreak);
        $this->assertSame(47, $context->percent);
        $this->assertSame('Mitochondrion', $context->correctanswer);
        $this->assertStringContainsString('mitochondrion', $context->explanation);
        $this->assertFalse($context->exhausted);
        $this->assertTrue($context->hasexplanation);
    }

    /**
     * A run that ran out of questions says so rather than implying a mistake.
     */
    public function test_summary_reports_an_exhausted_pool(): void {
        global $PAGE;

        $this->resetAfterTest();

        $page = new summary_page(4, 15, '', '', true, 42);
        $context = $page->export_for_template($PAGE->get_renderer('mod_onelife'));

        $this->assertTrue($context->exhausted);
        $this->assertNotEmpty($context->exhaustedmessage);
    }

    /**
     * Missing explanation text is reported as absent, not rendered empty.
     */
    public function test_summary_without_an_explanation(): void {
        global $PAGE;

        $this->resetAfterTest();

        $page = new summary_page(0, 15, 'Mitochondrion', '', false, 42);
        $context = $page->export_for_template($PAGE->get_renderer('mod_onelife'));

        $this->assertFalse($context->hasexplanation);
        $this->assertSame(0, $context->percent);
    }

    /**
     * The summary renders through the real template.
     */
    public function test_summary_renders(): void {
        global $PAGE;

        $this->resetAfterTest();

        $renderer = $PAGE->get_renderer('mod_onelife');
        $page = new summary_page(7, 15, 'Mitochondrion', '<p>Because mitochondria.</p>', false, 42);
        $html = $renderer->render_from_template('mod_onelife/summary', $page->export_for_template($renderer));

        $this->assertStringContainsString('Mitochondrion', $html);
        $this->assertStringContainsString('Because mitochondria.', $html);
    }
}
