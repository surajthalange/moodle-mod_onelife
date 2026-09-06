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
 * Lists every One Life activity in a course.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot . '/mod/onelife/lib.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);

$context = context_course::instance($course->id);

$event = \mod_onelife\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/onelife/index.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($course->shortname) . ': ' . get_string('modulenameplural', 'mod_onelife'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_onelife'));

$instances = get_all_instances_in_course('onelife', $course);

if (empty($instances)) {
    echo $OUTPUT->notification(get_string('noinstances', 'mod_onelife'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();
if ($usesections) {
    $table->head = [
        get_string('sectionname', 'format_' . $course->format),
        get_string('name'),
    ];
} else {
    $table->head = [get_string('name')];
}

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/onelife/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name),
        $instance->visible ? [] : ['class' => 'dimmed']
    );

    if ($usesections) {
        $table->data[] = [get_section_name($course, $instance->section), $link];
    } else {
        $table->data[] = [$link];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
