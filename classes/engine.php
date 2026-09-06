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
 * Pure scoring logic for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife;

use stdClass;

/**
 * Scoring rules for a One Life run.
 *
 * Every method is static and pure: no database, no output, no global state, no
 * side effects. Callers pass in everything the calculation needs, which is what
 * makes the rules unit-testable without a Moodle site.
 *
 * Question selection is deliberately absent. It needs the question bank and the
 * context resolution rules, so it lives with topic_repository instead.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine {
    /**
     * Matches the first HTML horizontal rule, in any of its written forms.
     *
     * Authors insert this with one toolbar click in the editor, so the convention
     * needs no syntax they have to remember and survives the HTML round trip.
     */
    private const EXPLANATION_DELIMITER = '/<hr\b[^>]*>/i';

    /**
     * Work out the streak after an answer.
     *
     * A correct answer extends the streak by one. A wrong answer ends the run,
     * which is the whole point of the format, so the streak returns to zero.
     * A negative current streak is treated as zero rather than propagated.
     *
     * @param int $currentstreak the streak before this answer
     * @param bool $correct whether the answer was correct
     * @return int the streak after this answer
     */
    public static function next_streak(int $currentstreak, bool $correct): int {
        if (!$correct) {
            return 0;
        }

        return max(0, $currentstreak) + 1;
    }

    /**
     * Express a streak as a percentage of the target streak.
     *
     * The result is capped at 100. The target is defined as the streak that counts
     * as 100 per cent, and a summary that reads "173%" is confusing next to a
     * progress bar. The streak itself is reported separately and is never capped,
     * so nothing is lost by clamping the percentage.
     *
     * A target of zero or less is a misconfiguration rather than a perfect score,
     * so it yields zero and cannot raise a division error.
     *
     * @param int $streak the streak reached
     * @param int $targetstreak the streak counting as 100 per cent
     * @return int the percentage, 0 to 100 inclusive
     */
    public static function score_percent(int $streak, int $targetstreak): int {
        if ($targetstreak <= 0 || $streak <= 0) {
            return 0;
        }

        return (int) min(100, round($streak / $targetstreak * 100));
    }

    /**
     * Pull the explanation out of a question's general feedback.
     *
     * The convention is that everything after the first horizontal rule is the
     * explanation. Feedback with no rule is treated as being entirely explanation,
     * so authors who never learn the convention still get sensible output.
     *
     * HTML is preserved rather than stripped, because the caller renders this in a
     * page where the author's formatting is worth keeping.
     *
     * @param string $generalfeedback the question's general feedback
     * @return string the explanation, trimmed, possibly empty
     */
    public static function extract_explanation(string $generalfeedback): string {
        $parts = preg_split(self::EXPLANATION_DELIMITER, $generalfeedback, 2);

        if ($parts === false) {
            return '';
        }

        $explanation = count($parts) === 2 ? $parts[1] : $parts[0];

        return trim($explanation);
    }

    /**
     * Choose the next question to ask from a pool.
     *
     * Only the choice lives here. Building the pool needs the question bank, the
     * category tree and the version table, so question_repository does that and
     * hands the result in. Splitting it that way is what keeps this class free of
     * the database, which basic_testcase enforces.
     *
     * Returns null when nothing is left rather than throwing: running out of
     * questions is an ordinary end to a run, not a fault, and the caller closes the
     * run cleanly on null.
     *
     * @param int[] $pool candidate question ids
     * @param int[] $excludequestionids ids already answered in this run
     * @return int|null the chosen question id, or null when the pool is exhausted
     */
    public static function select_question_id(array $pool, array $excludequestionids): ?int {
        $remaining = array_values(array_diff($pool, $excludequestionids));

        if ($remaining === []) {
            return null;
        }

        // Sampled rather than ordered: a fixed order would make every run identical.
        return (int) $remaining[random_int(0, count($remaining) - 1)];
    }

    /**
     * Score one chosen answer against its question.
     *
     * The question must already carry its answers, keyed by answer id, exactly as
     * question_bank loads them. Nothing is fetched here.
     *
     * An answer id that does not belong to the question is reported as invalid and
     * never as correct. That is the tampered-submission path, so failing closed
     * matters more than being forgiving.
     *
     * @param stdClass $question the question, with an answers array keyed by id
     * @param int $chosenanswerid the answer id the learner submitted
     * @return stdClass valid, correct, chosenanswerid, correctanswerid and fraction
     */
    public static function score_answer(stdClass $question, int $chosenanswerid): stdClass {
        $answers = [];
        if (isset($question->answers) && is_array($question->answers)) {
            $answers = $question->answers;
        }

        // The correct answer is the highest positive fraction. Questions with no
        // positive fraction have no correct answer to report.
        $correctanswerid = null;
        $bestfraction = 0.0;
        foreach ($answers as $answer) {
            $fraction = (float) ($answer->fraction ?? 0.0);
            if ($fraction > $bestfraction) {
                $bestfraction = $fraction;
                $correctanswerid = (int) $answer->id;
            }
        }

        $valid = array_key_exists($chosenanswerid, $answers);
        $fraction = 0.0;
        if ($valid) {
            $fraction = (float) ($answers[$chosenanswerid]->fraction ?? 0.0);
        }

        $result = new stdClass();
        $result->valid = $valid;
        // Any positive fraction survives; zero and negative fractions end the run.
        $result->correct = $valid && $fraction > 0.0;
        $result->chosenanswerid = $chosenanswerid;
        $result->correctanswerid = $correctanswerid;
        $result->fraction = $fraction;

        return $result;
    }
}
