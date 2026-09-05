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
 * Displays a single Sudden Death activity.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/suddendeath/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = optional_param('id', 0, PARAM_INT);
$s = optional_param('s', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('suddendeath', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('suddendeath', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('suddendeath', ['id' => $s], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('suddendeath', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/suddendeath:view', $context);

$event = \mod_suddendeath\event\course_module_viewed::create([
    'objectid' => $moduleinstance->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('suddendeath', $moduleinstance);
$event->trigger();

// Required by the FEATURE_COMPLETION_TRACKS_VIEWS rule.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/suddendeath/view.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $moduleinstance);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($moduleinstance->name));

if (trim(strip_tags($moduleinstance->intro))) {
    echo $OUTPUT->box(
        format_module_intro('suddendeath', $moduleinstance, $cm->id),
        'generalbox mod_introbox',
        'suddendeathintro'
    );
}

echo $OUTPUT->notification(
    get_string('scaffoldnotice', 'mod_suddendeath'),
    \core\output\notification::NOTIFY_INFO
);

echo $OUTPUT->footer();
