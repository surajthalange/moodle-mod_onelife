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
 * Backup task for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/onelife/backup/moodle2/backup_onelife_stepslib.php');

/**
 * Backs up a One Life activity.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_onelife_activity_task extends backup_activity_task {
    /**
     * The activity has no backup settings of its own.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step that writes onelife.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_onelife_activity_structure_step('onelife_structure', 'onelife.xml'));
    }

    /**
     * Encode links to this activity so they survive a restore elsewhere.
     *
     * @param string $content HTML that may contain links to this module
     * @return string the content with links encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = "/({$base}\/mod\/onelife\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@ONELIFEINDEX*$2@$', $content);

        $search = "/({$base}\/mod\/onelife\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@ONELIFEVIEWBYID*$2@$', $content);

        return $content;
    }
}
