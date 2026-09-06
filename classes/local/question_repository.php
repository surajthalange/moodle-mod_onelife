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
 * Builds the pool of questions a run may draw on.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

use stdClass;

/**
 * Finds and loads the questions a Sudden Death run can ask.
 *
 * This is the querying half of PRD section 6.1's select_question. The choosing half
 * stays pure in engine, because engine's freedom from the database is a tested
 * property and injecting a pool would only move the query somewhere less obvious.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_repository {
    /**
     * The question ids a run may ask, given the categories in scope.
     *
     * Three rules, all from PRD section 6.1:
     *
     * Descendants are expanded, because topic categories are routinely containers
     * with the questions a level or more further down.
     *
     * Only single-answer multichoice qualifies. A multiple-response question cannot
     * be answered with one click, which is the whole interaction here.
     *
     * Only the latest ready version of each bank entry is served, never a draft and
     * never a superseded version.
     *
     * @param int[] $categoryids the categories in scope
     * @return int[] candidate question ids
     */
    public function get_pool(array $categoryids): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/questionlib.php');

        if ($categoryids === []) {
            return [];
        }

        $expanded = [];
        foreach ($categoryids as $categoryid) {
            foreach (question_categorylist((int) $categoryid) as $descendant) {
                $expanded[(int) $descendant] = (int) $descendant;
            }
        }

        if ($expanded === []) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values($expanded), SQL_PARAMS_NAMED, 'cat');
        $params['qtype'] = 'multichoice';
        $params['ready'] = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $params['readymax'] = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $sql = "SELECT q.id
                  FROM {question} q
                  JOIN {question_versions} qv ON qv.questionid = q.id
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {qtype_multichoice_options} mco ON mco.questionid = q.id
                 WHERE qbe.questioncategoryid {$insql}
                   AND q.qtype = :qtype
                   AND mco.single = 1
                   AND qv.status = :ready
                   AND qv.version = (
                           SELECT MAX(latest.version)
                             FROM {question_versions} latest
                            WHERE latest.questionbankentryid = qbe.id
                              AND latest.status = :readymax
                       )";

        return array_map('intval', array_keys($DB->get_records_sql($sql, $params)));
    }

    /**
     * Load a question in the shape the engine scores.
     *
     * Moodle returns answers under options->answers. Flattening them onto the
     * question here means the engine never has to know about question bank
     * internals, which is what lets it stay pure.
     *
     * @param int $questionid the question to load
     * @return stdClass|null the question, or null when it no longer exists
     */
    public function load(int $questionid): ?stdClass {
        global $CFG, $DB;

        // PHPUnit's bootstrap preloads these, a plain web request does not.
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/engine/lib.php');

        if (!$DB->record_exists('question', ['id' => $questionid])) {
            return null;
        }

        $data = \question_bank::load_question_data($questionid);

        if (empty($data) || empty($data->options->answers)) {
            return null;
        }

        $question = new stdClass();
        $question->id = (int) $data->id;
        $question->name = $data->name ?? '';
        $question->questiontext = $data->questiontext ?? '';
        $question->questiontextformat = $data->questiontextformat ?? FORMAT_HTML;
        $question->generalfeedback = $data->generalfeedback ?? '';
        $question->generalfeedbackformat = $data->generalfeedbackformat ?? FORMAT_HTML;

        $question->answers = [];
        foreach ($data->options->answers as $answer) {
            $question->answers[(int) $answer->id] = $answer;
        }

        return $question;
    }
}
