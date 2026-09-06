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
 * The mod_suddendeath run started event.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\event;

/**
 * Fired when a learner starts a new run.
 *
 * Fired once per run, from run_manager where the row is written. Resuming an open
 * run does not fire it again: start_or_resume() returns the existing run before
 * reaching the event, which is the same guarantee that stops a refresh re-rolling
 * the question.
 *
 * The event carries the scope the learner chose and nothing about the question they
 * are about to be asked. Event data is broadly readable, so anything naming a
 * question would leak what is in play to anyone with report access.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_started extends \core\event\base {
    /**
     * Set the basic event properties.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'suddendeath_run';
    }

    /**
     * The event name shown in reports.
     *
     * @return string the translated name
     */
    public static function get_name() {
        return get_string('eventrunstarted', 'mod_suddendeath');
    }

    /**
     * A readable description of what happened.
     *
     * @return string the description
     */
    public function get_description() {
        return "The user with id '{$this->userid}' started run with id '{$this->objectid}' " .
            "in the suddendeath activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Where the run can be seen.
     *
     * @return \moodle_url the activity url
     */
    public function get_url() {
        return new \moodle_url('/mod/suddendeath/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the data the event was created with.
     *
     * @throws \coding_exception when a required field is missing
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set to the run id.');
        }
        if (!isset($this->other['scopetype'])) {
            throw new \coding_exception('The \'scopetype\' value must be set in other.');
        }
    }

    /**
     * Mapping used when restoring a backup that contains this event.
     *
     * @return array the object mapping
     */
    public static function get_objectid_mapping() {
        // Runs are restored under their own mapping, set by the restore step.
        return ['db' => 'suddendeath_run', 'restore' => 'suddendeath_run'];
    }

    /**
     * Mapping for the values in other.
     *
     * @return array the other mapping
     */
    public static function get_other_mapping() {
        // The topicids value is a comma separated list rather than a single id, so
        // core cannot remap it. The restore step rewrites the stored run itself.
        return false;
    }
}
