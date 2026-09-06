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
 * Unit tests for the mod_onelife scoring engine.
 *
 * The engine is pure: no database, no output, no global state. These tests extend
 * basic_testcase rather than advanced_testcase precisely because basic_testcase
 * forbids database access, so any impurity fails the suite.
 *
 * Metadata is written as doc-comment annotations rather than PHP attributes, and
 * must stay that way. PHPUnit 11 reports the annotations as deprecated, but
 * attributes arrived in PHPUnit 10 and Moodle 4.5, this plugin's supported floor,
 * ships PHPUnit ^9.6.34. Converting to attributes would silently stop these tests
 * running on 4.5. Revisit only when the floor moves past Moodle 5.0.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife;

use stdClass;

/**
 * Tests for the engine class.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_onelife\engine
 */
final class engine_test extends \basic_testcase {
    /**
     * Build a question stub of the shape question_bank returns: answers keyed by id.
     *
     * @param array $fractions answer id to fraction
     * @param string $generalfeedback general feedback text
     * @return stdClass the question stub
     */
    private function make_question(array $fractions, string $generalfeedback = ''): stdClass {
        $question = new stdClass();
        $question->id = 501;
        $question->generalfeedback = $generalfeedback;
        $question->answers = [];
        foreach ($fractions as $answerid => $fraction) {
            $answer = new stdClass();
            $answer->id = $answerid;
            $answer->fraction = $fraction;
            $answer->answer = 'Answer ' . $answerid;
            $question->answers[$answerid] = $answer;
        }
        return $question;
    }

    /**
     * Data provider for next_streak.
     *
     * @return array the cases
     */
    public static function next_streak_provider(): array {
        return [
            'from zero, correct' => [0, true, 1],
            'from zero, incorrect' => [0, false, 0],
            'mid run, correct' => [4, true, 5],
            'mid run, incorrect' => [4, false, 0],
            'large value, correct' => [999999, true, 1000000],
            'large value, incorrect' => [999999, false, 0],
            'negative treated as zero, correct' => [-3, true, 1],
            'negative treated as zero, incorrect' => [-3, false, 0],
        ];
    }

    /**
     * A correct answer increments the streak; a wrong one ends the run at zero.
     *
     * @dataProvider next_streak_provider
     * @param int $current current streak
     * @param bool $correct whether the answer was correct
     * @param int $expected expected new streak
     */
    public function test_next_streak(int $current, bool $correct, int $expected): void {
        $this->assertSame($expected, engine::next_streak($current, $correct));
    }

    /**
     * Data provider for score_percent.
     *
     * @return array the cases
     */
    public static function score_percent_provider(): array {
        return [
            'zero streak' => [0, 15, 0],
            'below target' => [6, 15, 40],
            'below target, rounds up' => [7, 15, 47],
            'exactly target' => [15, 15, 100],
            'above target is capped' => [20, 15, 100],
            'far above target is capped' => [1000, 15, 100],
            'target of one, no streak' => [0, 1, 0],
            'target of one, met' => [1, 1, 100],
            'target of one, exceeded' => [5, 1, 100],
            'target of zero is not a division error' => [7, 0, 0],
            'target of zero with zero streak' => [0, 0, 0],
            'negative target' => [7, -5, 0],
            'negative streak' => [-7, 15, 0],
        ];
    }

    /**
     * The streak is expressed as a percentage of the target, capped at 100.
     *
     * @dataProvider score_percent_provider
     * @param int $streak streak reached
     * @param int $target target streak
     * @param int $expected expected percentage
     */
    public function test_score_percent(int $streak, int $target, int $expected): void {
        $this->assertSame($expected, engine::score_percent($streak, $target));
    }

    /**
     * Data provider for extract_explanation.
     *
     * @return array the cases
     */
    public static function extract_explanation_provider(): array {
        return [
            'empty string' => ['', ''],
            'whitespace only' => ["   \n\t ", ''],
            'plain text, no delimiter' => [
                'Because the mitochondrion makes ATP.',
                'Because the mitochondrion makes ATP.',
            ],
            'html, no delimiter' => [
                '<p>Because the <em>mitochondrion</em> makes ATP.</p>',
                '<p>Because the <em>mitochondrion</em> makes ATP.</p>',
            ],
            'delimiter splits, text after is kept' => [
                '<p>Well done.</p><hr /><p>ATP is made in the mitochondrion.</p>',
                '<p>ATP is made in the mitochondrion.</p>',
            ],
            'delimiter without self closing slash' => [
                '<p>Well done.</p><hr><p>The explanation.</p>',
                '<p>The explanation.</p>',
            ],
            'delimiter with attributes' => [
                '<p>Well done.</p><hr class="divider" /><p>The explanation.</p>',
                '<p>The explanation.</p>',
            ],
            'delimiter with surrounding whitespace' => [
                "<p>Well done.</p>\n  <hr />\n  <p>The explanation.</p>\n",
                '<p>The explanation.</p>',
            ],
            'only the first delimiter splits' => [
                '<p>A</p><hr /><p>B</p><hr /><p>C</p>',
                '<p>B</p><hr /><p>C</p>',
            ],
            'delimiter with nothing after it' => [
                '<p>Well done.</p><hr />',
                '',
            ],
            'delimiter at the very start' => [
                '<hr /><p>Everything is explanation.</p>',
                '<p>Everything is explanation.</p>',
            ],
        ];
    }

    /**
     * The explanation is whatever follows the first horizontal rule.
     *
     * @dataProvider extract_explanation_provider
     * @param string $feedback the general feedback
     * @param string $expected the expected explanation
     */
    public function test_extract_explanation(string $feedback, string $expected): void {
        $this->assertSame($expected, engine::extract_explanation($feedback));
    }

    /**
     * A correct choice is reported correct, with the fraction carried through.
     */
    public function test_score_answer_correct(): void {
        $question = $this->make_question([11 => 0.0, 12 => 1.0, 13 => 0.0]);

        $result = engine::score_answer($question, 12);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->correct);
        $this->assertSame(12, $result->chosenanswerid);
        $this->assertSame(12, $result->correctanswerid);
        $this->assertEqualsWithDelta(1.0, $result->fraction, 0.0001);
    }

    /**
     * A wrong choice is reported incorrect but still resolves the correct answer.
     */
    public function test_score_answer_incorrect(): void {
        $question = $this->make_question([11 => 0.0, 12 => 1.0, 13 => 0.0]);

        $result = engine::score_answer($question, 13);

        $this->assertTrue($result->valid);
        $this->assertFalse($result->correct);
        $this->assertSame(13, $result->chosenanswerid);
        $this->assertSame(12, $result->correctanswerid);
        $this->assertEqualsWithDelta(0.0, $result->fraction, 0.0001);
    }

    /**
     * An answer id that does not belong to the question is rejected, not scored.
     *
     * This is the tampered-submission path, so it must never come back correct.
     */
    public function test_score_answer_id_not_belonging_to_question(): void {
        $question = $this->make_question([11 => 0.0, 12 => 1.0]);

        $result = engine::score_answer($question, 9999);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->correct);
        $this->assertSame(9999, $result->chosenanswerid);
        $this->assertSame(12, $result->correctanswerid);
        $this->assertEqualsWithDelta(0.0, $result->fraction, 0.0001);
    }

    /**
     * A question carrying no answers cannot be scored and has no correct answer.
     */
    public function test_score_answer_question_without_answers(): void {
        $question = $this->make_question([]);

        $result = engine::score_answer($question, 11);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->correct);
        $this->assertNull($result->correctanswerid);
        $this->assertEqualsWithDelta(0.0, $result->fraction, 0.0001);
    }

    /**
     * Partial credit still counts as correct: any positive fraction is a survival.
     */
    public function test_score_answer_partial_fraction_counts_as_correct(): void {
        $question = $this->make_question([11 => 0.5, 12 => 1.0]);

        $result = engine::score_answer($question, 11);

        $this->assertTrue($result->valid);
        $this->assertTrue($result->correct);
        $this->assertSame(12, $result->correctanswerid);
    }

    /**
     * A negative fraction, an actively penalised distractor, is not correct.
     */
    public function test_score_answer_negative_fraction_is_incorrect(): void {
        $question = $this->make_question([11 => -0.25, 12 => 1.0]);

        $result = engine::score_answer($question, 11);

        $this->assertTrue($result->valid);
        $this->assertFalse($result->correct);
    }

    /**
     * Choosing from a pool never returns something already answered in this run.
     *
     * The pool itself is built by question_repository, which queries. Only the
     * choice is here, which is what keeps this class free of the database.
     */
    public function test_select_question_id_excludes_already_answered(): void {
        $chosen = engine::select_question_id([11, 12, 13], [11, 13]);

        $this->assertSame(12, $chosen);
    }

    /**
     * An exhausted pool returns null rather than an error.
     *
     * The caller ends the run cleanly on null. Throwing here would make running out
     * of questions look like a fault, which it is not.
     */
    public function test_select_question_id_returns_null_when_exhausted(): void {
        $this->assertNull(engine::select_question_id([11, 12], [11, 12]));
        $this->assertNull(engine::select_question_id([], []));
        $this->assertNull(engine::select_question_id([], [11]));
    }

    /**
     * With nothing excluded, the choice comes from the pool.
     */
    public function test_select_question_id_picks_from_the_pool(): void {
        for ($i = 0; $i < 20; $i++) {
            $this->assertContains(engine::select_question_id([11, 12, 13], []), [11, 12, 13]);
        }
    }

    /**
     * A single remaining candidate is returned deterministically.
     */
    public function test_select_question_id_returns_the_only_candidate(): void {
        $this->assertSame(12, engine::select_question_id([11, 12], [11]));
    }

    /**
     * Exclusions that are not in the pool are harmless.
     */
    public function test_select_question_id_ignores_irrelevant_exclusions(): void {
        $this->assertSame(11, engine::select_question_id([11], [98, 99]));
    }

    /**
     * The choice varies across runs, so a pool is sampled rather than ordered.
     *
     * A first-in-list implementation passes every other test here and makes every
     * run identical, which would defeat the point of a revision tool.
     */
    public function test_select_question_id_does_not_always_pick_the_same_one(): void {
        $pool = range(1, 40);
        $seen = [];

        for ($i = 0; $i < 40; $i++) {
            $seen[engine::select_question_id($pool, [])] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'Selection must not be a fixed order.');
    }
}
