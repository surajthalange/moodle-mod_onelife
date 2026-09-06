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
 * The run lifecycle.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

use mod_suddendeath\engine;
use stdClass;

/**
 * Owns starting, serving, answering and finishing a run.
 *
 * Every database write for runs and answers happens here. play.php calls this and
 * renders; it holds no state logic of its own.
 *
 * The three guarantees from PRD section 6.3 are structural rather than incidental:
 *
 * One in-progress run per learner per instance. start_or_resume() returns the
 * existing run untouched, so refreshing cannot re-roll the question. That is why
 * the current question lives in a column and not the session (section 4.4).
 *
 * The answer row is written before the streak moves. A request that dies in between
 * leaves a recorded answer and an unchanged streak, which is recoverable; the other
 * order would inflate a streak with nothing behind it.
 *
 * Finishing sets timefinish and clears currentquestionid together.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_manager {
    /** @var question_repository Builds and loads the question pool. */
    private question_repository $questions;

    /**
     * Constructor.
     *
     * @param question_repository|null $questions the question source
     */
    public function __construct(?question_repository $questions = null) {
        $this->questions = $questions ?? new question_repository();
    }

    /**
     * The learner's in-progress run for this instance, if there is one.
     *
     * @param int $suddendeathid the instance id
     * @param int $userid the learner
     * @return stdClass|null the run, or null when none is open
     */
    public function get_in_progress_run(int $suddendeathid, int $userid): ?stdClass {
        global $DB;

        $run = $DB->get_record_select(
            'suddendeath_run',
            'suddendeathid = ? AND userid = ? AND timefinish IS NULL',
            [$suddendeathid, $userid]
        );

        return $run ?: null;
    }

    /**
     * Resume the learner's open run, or start a new one.
     *
     * An open run is returned exactly as stored, including its currentquestionid.
     * Nothing is re-chosen, so refreshing the page cannot shop for an easier
     * question.
     *
     * @param stdClass $instance the activity instance
     * @param int $userid the learner
     * @param string $scopetype the chosen mode
     * @param int[] $topicids the categories in scope
     * @return stdClass|null the run, or null when there is nothing to play
     */
    public function start_or_resume(stdClass $instance, int $userid, string $scopetype, array $topicids): ?stdClass {
        global $DB;

        $existing = $this->get_in_progress_run((int) $instance->id, $userid);
        if ($existing !== null) {
            return $existing;
        }

        $firstquestionid = engine::select_question_id($this->questions->get_pool($topicids), []);
        if ($firstquestionid === null) {
            // Recording a run nobody can play would leave junk in the learner's
            // history and skew any later statistics.
            return null;
        }

        $run = new stdClass();
        $run->suddendeathid = (int) $instance->id;
        $run->userid = $userid;
        $run->scopetype = $scopetype;
        // Stored sorted and deduplicated, so two runs over the same set of topics
        // group into one personal record however the learner ticked them.
        $canonical = array_values(array_unique(array_map('intval', $topicids)));
        sort($canonical, SORT_NUMERIC);
        $run->topicids = implode(',', $canonical);
        $run->streak = 0;
        $run->targetstreak = (int) $instance->targetstreak;
        $run->currentquestionid = $firstquestionid;
        $run->timecreated = time();
        $run->timefinish = null;
        $run->id = $DB->insert_record('suddendeath_run', $run);

        return $run;
    }

    /**
     * Load the run's current question.
     *
     * When the stored question has vanished from the bank mid-run, a replacement is
     * served if one is available and the run is closed cleanly if not. The learner
     * did nothing wrong, so neither outcome is an error.
     *
     * @param stdClass $run the run
     * @return stdClass|null the question, or null when the run has ended
     */
    public function get_current_question(stdClass $run): ?stdClass {
        if (empty($run->timefinish) && !empty($run->currentquestionid)) {
            $question = $this->questions->load((int) $run->currentquestionid);
            if ($question !== null) {
                return $question;
            }
        }

        if (!empty($run->timefinish)) {
            return null;
        }

        $replacement = $this->choose_next_question_id($run);
        if ($replacement === null) {
            $this->finish($run);
            return null;
        }

        $this->set_current_question($run, $replacement);

        return $this->questions->load($replacement);
    }

    /**
     * Record an answer and move the run on.
     *
     * Refuses rather than throws, so the controller can send the learner somewhere
     * sensible instead of showing an exception for an ordinary double-click.
     *
     * @param stdClass $run the run as the page was rendered with
     * @param int $userid the learner submitting
     * @param int $questionid the question being answered
     * @param int $chosenanswerid the chosen answer
     * @return stdClass the outcome
     */
    public function answer(stdClass $run, int $userid, int $questionid, int $chosenanswerid): stdClass {
        global $DB;

        // Re-read: the run object came from a rendered page and may be stale.
        $current = $DB->get_record('suddendeath_run', ['id' => $run->id]);

        if (!$current) {
            return $this->refusal('gone');
        }
        if ((int) $current->userid !== $userid) {
            return $this->refusal('notyours');
        }
        if (!empty($current->timefinish)) {
            return $this->refusal('finished');
        }
        // The double-click guard: the second post names a question the run has
        // already moved past.
        if ((int) $current->currentquestionid !== $questionid) {
            return $this->refusal('stale');
        }

        $question = $this->questions->load($questionid);
        if ($question === null) {
            return $this->refusal('gone');
        }

        $scored = engine::score_answer($question, $chosenanswerid);
        if (!$scored->valid) {
            // An answer that does not belong to this question is a tampered post,
            // so nothing is recorded and the run is left exactly as it was.
            return $this->refusal('invalidanswer');
        }

        $nextquestionid = $scored->correct ? $this->choose_next_question_id($current) : null;

        // Guarantee 2. The answer is written first and on its own, so a request that
        // dies here leaves evidence of the attempt and an unmoved streak.
        $this->record_answer($current, $question, $scored->correct);

        $this->apply_answer_outcome($current, (bool) $scored->correct, $nextquestionid);

        $exhausted = $scored->correct && $nextquestionid === null;

        $result = new stdClass();
        $result->accepted = true;
        $result->reason = '';
        $result->correct = (bool) $scored->correct;
        $result->streak = $scored->correct
            ? engine::next_streak((int) $current->streak, true)
            : (int) $current->streak;
        $result->finished = !$scored->correct || $exhausted;
        $result->exhausted = $exhausted;
        $result->correctanswerid = $scored->correctanswerid;
        $result->explanation = engine::extract_explanation((string) $question->generalfeedback);

        return $result;
    }

    /**
     * Close a run.
     *
     * Guarantee 3: timefinish and currentquestionid are set together, so a finished
     * run never keeps a question that could be answered.
     *
     * @param stdClass $run the run
     * @return stdClass the run as stored
     */
    public function finish(stdClass $run): stdClass {
        global $DB;

        $update = new stdClass();
        $update->id = $run->id;
        $update->timefinish = time();
        $update->currentquestionid = null;
        $DB->update_record('suddendeath_run', $update);

        $run->timefinish = $update->timefinish;
        $run->currentquestionid = null;

        return $run;
    }

    /**
     * Write the answer row.
     *
     * @param stdClass $run the run
     * @param stdClass $question the question answered
     * @param bool $correct whether it was correct
     */
    protected function record_answer(stdClass $run, stdClass $question, bool $correct): void {
        global $DB;

        $answer = new stdClass();
        $answer->runid = (int) $run->id;
        $answer->topicid = $this->topic_of($question);
        $answer->questionid = (int) $question->id;
        $answer->correct = $correct ? 1 : 0;
        $answer->timecreated = time();

        $DB->insert_record('suddendeath_answer', $answer);
    }

    /**
     * Apply the consequences of an answer to the run row.
     *
     * Separated from record_answer() so the ordering between the two is explicit and
     * can be tested by making this step fail.
     *
     * @param stdClass $run the run
     * @param bool $correct whether the answer was correct
     * @param int|null $nextquestionid the next question, or null to finish
     */
    protected function apply_answer_outcome(stdClass $run, bool $correct, ?int $nextquestionid): void {
        global $DB;

        $update = new stdClass();
        $update->id = $run->id;

        if ($correct) {
            $update->streak = engine::next_streak((int) $run->streak, true);
        }
        // On a wrong answer the streak stays at what the learner reached. The streak
        // is their score, and almost every run ends in a mistake, so resetting it here
        // would record zero for nearly every run and leave personal bests able to show
        // only runs that exhausted the pool.

        if (!$correct || $nextquestionid === null) {
            $update->timefinish = time();
            $update->currentquestionid = null;
        } else {
            $update->currentquestionid = $nextquestionid;
        }

        $DB->update_record('suddendeath_run', $update);
    }

    /**
     * Pick the next question for a run, excluding everything already answered.
     *
     * @param stdClass $run the run
     * @return int|null the next question id, or null when the pool is exhausted
     */
    protected function choose_next_question_id(stdClass $run): ?int {
        global $DB;

        $topicids = array_filter(array_map('intval', explode(',', (string) $run->topicids)));
        $answered = $DB->get_fieldset_select('suddendeath_answer', 'questionid', 'runid = ?', [$run->id]);

        $exclude = array_map('intval', $answered);
        if (!empty($run->currentquestionid)) {
            $exclude[] = (int) $run->currentquestionid;
        }

        return engine::select_question_id($this->questions->get_pool($topicids), $exclude);
    }

    /**
     * Store a new current question on a run.
     *
     * @param stdClass $run the run, updated in place
     * @param int $questionid the question to serve
     */
    protected function set_current_question(stdClass $run, int $questionid): void {
        global $DB;

        $DB->set_field('suddendeath_run', 'currentquestionid', $questionid, ['id' => $run->id]);
        $run->currentquestionid = $questionid;
    }

    /**
     * The category a question belongs to.
     *
     * @param stdClass $question the question
     * @return int the category id, or 0 when it cannot be resolved
     */
    protected function topic_of(stdClass $question): int {
        global $DB;

        $sql = "SELECT qbe.questioncategoryid
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE qv.questionid = ?";

        return (int) $DB->get_field_sql($sql, [$question->id], IGNORE_MULTIPLE);
    }

    /**
     * Build a refusal outcome.
     *
     * @param string $reason why the submission was refused
     * @return stdClass the outcome
     */
    private function refusal(string $reason): stdClass {
        $result = new stdClass();
        $result->accepted = false;
        $result->reason = $reason;
        $result->correct = false;
        $result->streak = 0;
        $result->finished = false;
        $result->exhausted = false;
        $result->correctanswerid = null;
        $result->explanation = '';

        return $result;
    }
}
