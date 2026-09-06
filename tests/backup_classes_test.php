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
 * Guards the backup contract.
 *
 * FEATURE_BACKUP_MOODLE2 is a promise: Moodle's course-module delete path loads
 * backup_<mod>_activity_task on the strength of it and throws if the class is not
 * there. This asserts the promise and the classes stay in step, so the pair cannot
 * drift apart later.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife;

/**
 * Tests that the backup declaration matches the files on disk.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::onelife_supports
 */
final class backup_classes_test extends \advanced_testcase {
    /**
     * The module claims to support Moodle 2 backup.
     */
    public function test_backup_is_declared_supported(): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/onelife/lib.php');

        $this->assertTrue(onelife_supports(FEATURE_BACKUP_MOODLE2));
    }

    /**
     * All four backup and restore classes exist and load.
     *
     * Declaring support without these makes course-module deletion throw
     * "Class backup_onelife_activity_task not found", which is a failure a
     * teacher hits while tidying a course rather than while backing one up.
     */
    public function test_the_four_backup_classes_load(): void {
        global $CFG;

        // Load them the way a real backup does. The plan builders pull in core's base
        // classes and then require every module's task file, guarded by
        // FEATURE_BACKUP_MOODLE2, so this exercises the actual discovery path rather
        // than requiring the plugin files bare, which fails on the missing base class
        // and then leaves PHP treating them as included.
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
        require_once($CFG->dirroot . '/backup/moodle2/restore_plan_builder.class.php');

        $base = $CFG->dirroot . '/mod/onelife/backup/moodle2/';

        $files = [
            'backup_onelife_activity_task.class.php' => 'backup_onelife_activity_task',
            'backup_onelife_stepslib.php' => 'backup_onelife_activity_structure_step',
            'restore_onelife_activity_task.class.php' => 'restore_onelife_activity_task',
            'restore_onelife_stepslib.php' => 'restore_onelife_activity_structure_step',
        ];

        foreach ($files as $file => $class) {
            $this->assertFileExists($base . $file);
            $this->assertTrue(class_exists($class), "Class {$class} must be discoverable by a real backup.");
        }
    }

    /**
     * Deleting a course module does not throw.
     *
     * This is the exact path the PRD warns about, exercised rather than assumed.
     */
    public function test_deleting_a_course_module_does_not_throw(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('onelife', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('onelife', $instance->id, $course->id, false, MUST_EXIST);

        course_delete_module($cm->id);

        $this->assertFalse($DB->record_exists('onelife', ['id' => $instance->id]));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cm->id]));
    }
}
