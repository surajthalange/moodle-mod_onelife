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
 * Backup task for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/suddendeath/backup/moodle2/backup_suddendeath_stepslib.php');

/**
 * Backs up a Sudden Death activity.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_suddendeath_activity_task extends backup_activity_task {
    /**
     * The activity has no backup settings of its own.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step that writes suddendeath.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_suddendeath_activity_structure_step('suddendeath_structure', 'suddendeath.xml'));
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

        $search = "/({$base}\/mod\/suddendeath\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SUDDENDEATHINDEX*$2@$', $content);

        $search = "/({$base}\/mod\/suddendeath\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SUDDENDEATHVIEWBYID*$2@$', $content);

        return $content;
    }
}
