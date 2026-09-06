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
 * Restore task for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/onelife/backup/moodle2/restore_onelife_stepslib.php');

/**
 * Restores a One Life activity.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_onelife_activity_task extends restore_activity_task {
    /**
     * The activity has no restore settings of its own.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step that reads onelife.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_onelife_activity_structure_step('onelife_structure', 'onelife.xml'));
    }

    /**
     * File areas whose contents need decoding.
     *
     * @return array the file areas
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('onelife', ['intro'], 'onelife');

        return $contents;
    }

    /**
     * Link decoding rules for content encoded at backup time.
     *
     * @return array the decoding rules
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('ONELIFEVIEWBYID', '/mod/onelife/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('ONELIFEINDEX', '/mod/onelife/index.php?id=$1', 'course');

        return $rules;
    }

    /**
     * Restore log rules for this activity.
     *
     * @return array the log rules
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Restore log rules for course-level logs.
     *
     * @return array the log rules
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
