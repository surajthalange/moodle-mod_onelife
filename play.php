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
 * Plays a One Life run.
 *
 * A thin controller. It resolves the module, checks access, hands the submission to
 * run_manager and renders whatever comes back. Every decision about the run's state
 * belongs to run_manager, not here.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->libdir . '/completionlib.php');

use mod_onelife\local\run_manager;
use mod_onelife\output\play_page;
use mod_onelife\output\summary_page;

$id = required_param('id', PARAM_INT);
$questionid = optional_param('questionid', 0, PARAM_INT);
$answerid = optional_param('answerid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('onelife', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$moduleinstance = $DB->get_record('onelife', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/onelife:play', $context);

$PAGE->set_url('/mod/onelife/play.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $moduleinstance);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));

$manager = new run_manager();
$viewurl = new moodle_url('/mod/onelife/view.php', ['id' => $cm->id]);
$playurl = new moodle_url('/mod/onelife/play.php', ['id' => $cm->id]);

$run = $manager->get_in_progress_run((int) $moduleinstance->id, (int) $USER->id);

if ($run === null) {
    // Nothing open to play: the picker is where a run begins.
    redirect($viewurl);
}

$outcome = null;

if ($answerid > 0 && confirm_sesskey()) {
    $outcome = $manager->answer($run, (int) $USER->id, $questionid, $answerid);

    if (!$outcome->accepted) {
        // A refusal is usually a double-click or a stale tab rather than an attack,
        // so the learner is sent to wherever the run actually is instead of being
        // shown an exception.
        if ($outcome->reason === 'finished') {
            redirect($viewurl);
        }
        redirect($playurl, get_string('answerrefused', 'mod_onelife'), null, \core\output\notification::NOTIFY_INFO);
    }

    if ($outcome->finished) {
        // Completion is re-evaluated when a run ends, not on every page view: the
        // streak rule can only change at the moment a run closes.
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, (int) $USER->id);
        }
    } else {
        // Post/redirect/get, so a refresh cannot resubmit the answer.
        redirect($playurl);
    }
}

$output = $PAGE->get_renderer('mod_onelife');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($moduleinstance->name));

if ($outcome !== null && $outcome->finished) {
    $correcttext = '';
    if (!empty($outcome->correctanswerid)) {
        $correcttext = (string) $DB->get_field('question_answers', 'answer', ['id' => $outcome->correctanswerid]);
    }

    echo $output->render(new summary_page(
        (int) $outcome->streak,
        (int) $moduleinstance->targetstreak,
        format_string($correcttext),
        $outcome->explanation,
        (bool) $outcome->exhausted,
        (int) $cm->id
    ));
} else {
    $run = $manager->get_in_progress_run((int) $moduleinstance->id, (int) $USER->id);
    $question = $run !== null ? $manager->get_current_question($run) : null;

    if ($question === null) {
        // The run closed itself, which happens when the pool empties or the stored
        // question disappears from the bank.
        echo $output->render(new summary_page(
            $run !== null ? (int) $run->streak : 0,
            (int) $moduleinstance->targetstreak,
            '',
            '',
            true,
            (int) $cm->id
        ));
    } else {
        echo $output->render(new play_page(
            $question,
            (int) $run->streak,
            (int) $moduleinstance->targetstreak,
            (int) $cm->id
        ));
    }
}

echo $OUTPUT->footer();
