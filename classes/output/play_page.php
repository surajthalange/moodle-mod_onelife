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
 * Renderable for one question in a run.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\output;

use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * One question, its answers, and the streak so far.
 *
 * The exported answers carry only an id and display text. Fractions are dropped
 * here rather than in the template, so the answer key cannot reach the page source
 * even if the template changes.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class play_page implements renderable, templatable {
    /** @var stdClass The question being asked. */
    private stdClass $question;

    /** @var int The streak so far. */
    private int $streak;

    /** @var int The streak counting as 100 per cent. */
    private int $targetstreak;

    /** @var int The course module id. */
    private int $cmid;

    /**
     * Constructor.
     *
     * @param stdClass $question the question, with answers keyed by id
     * @param int $streak the streak so far
     * @param int $targetstreak the target streak
     * @param int $cmid the course module id
     */
    public function __construct(stdClass $question, int $streak, int $targetstreak, int $cmid) {
        $this->question = $question;
        $this->streak = $streak;
        $this->targetstreak = $targetstreak;
        $this->cmid = $cmid;
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output the renderer
     * @return stdClass the context for mod_suddendeath/play
     */
    public function export_for_template(renderer_base $output): stdClass {
        $context = new stdClass();
        $context->cmid = $this->cmid;
        $context->questionid = (int) $this->question->id;
        $context->questiontext = format_text(
            $this->question->questiontext,
            $this->question->questiontextformat ?? FORMAT_HTML
        );
        $context->streak = $this->streak;
        $context->targetstreak = $this->targetstreak;
        $context->sesskey = sesskey();

        $context->answers = [];
        foreach ($this->question->answers as $answer) {
            // Only id and text. Nothing here says which one is right.
            $context->answers[] = [
                'id' => (int) $answer->id,
                'text' => format_string($answer->answer),
            ];
        }

        return $context;
    }
}
