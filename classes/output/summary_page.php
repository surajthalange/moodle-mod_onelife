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
 * Renderable for the end of a run.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\output;

use mod_suddendeath\engine;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * How the run ended, and what the learner should take from it.
 *
 * A run that ran out of questions is reported as such rather than as a loss: the
 * learner did not get anything wrong, and saying otherwise would be a lie about
 * their score.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary_page implements renderable, templatable {
    /** @var int The streak reached. */
    private int $streak;

    /** @var int The streak counting as 100 per cent. */
    private int $targetstreak;

    /** @var string The text of the correct answer. */
    private string $correctanswer;

    /** @var string The explanation, already extracted from general feedback. */
    private string $explanation;

    /** @var bool Whether the run ended because the pool ran out. */
    private bool $exhausted;

    /** @var int The course module id. */
    private int $cmid;

    /**
     * Constructor.
     *
     * @param int $streak the streak reached
     * @param int $targetstreak the target streak
     * @param string $correctanswer the correct answer's text
     * @param string $explanation the explanation
     * @param bool $exhausted whether the pool ran out
     * @param int $cmid the course module id
     */
    public function __construct(
        int $streak,
        int $targetstreak,
        string $correctanswer,
        string $explanation,
        bool $exhausted,
        int $cmid
    ) {
        $this->streak = $streak;
        $this->targetstreak = $targetstreak;
        $this->correctanswer = $correctanswer;
        $this->explanation = $explanation;
        $this->exhausted = $exhausted;
        $this->cmid = $cmid;
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output the renderer
     * @return stdClass the context for mod_suddendeath/summary
     */
    public function export_for_template(renderer_base $output): stdClass {
        $context = new stdClass();
        $context->cmid = $this->cmid;
        $context->streak = $this->streak;
        $context->targetstreak = $this->targetstreak;
        $context->percent = engine::score_percent($this->streak, $this->targetstreak);
        $context->correctanswer = $this->correctanswer;
        $context->hascorrectanswer = $this->correctanswer !== '';
        $context->explanation = $this->explanation;
        $context->hasexplanation = trim(strip_tags($this->explanation)) !== '';
        $context->exhausted = $this->exhausted;
        $context->exhaustedmessage = $this->exhausted
            ? get_string('poolexhausted', 'mod_suddendeath')
            : '';
        $context->sesskey = sesskey();

        return $context;
    }
}
