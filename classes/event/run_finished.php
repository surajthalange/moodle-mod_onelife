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
 * The mod_onelife run finished event.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\event;

/**
 * Fired when a run ends, however it ends.
 *
 * A run closes in two places: a wrong answer or an exhausted pool inside answer(),
 * and a mid-run bank change inside finish(). Both fire this once, and neither fires
 * it for a run that was already closed.
 *
 * The event carries the streak reached and the scope played. It deliberately carries
 * no question id, no answer id and nothing about correctness per question. Event data
 * is broadly readable, and a stream of question ids alongside a streak would let a
 * reader reconstruct which questions a cohort is being asked and which one ended each
 * run, which is close to publishing the answer key.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_finished extends \core\event\base {
    /**
     * Set the basic event properties.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'onelife_run';
    }

    /**
     * The event name shown in reports.
     *
     * @return string the translated name
     */
    public static function get_name() {
        return get_string('eventrunfinished', 'mod_onelife');
    }

    /**
     * A readable description of what happened.
     *
     * @return string the description
     */
    public function get_description() {
        return "The user with id '{$this->userid}' finished run with id '{$this->objectid}' " .
            "with a streak of '{$this->other['streak']}' in the onelife activity " .
            "with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Where the run can be seen.
     *
     * @return \moodle_url the activity url
     */
    public function get_url() {
        return new \moodle_url('/mod/onelife/view.php', ['id' => $this->contextinstanceid]);
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
        if (!isset($this->other['streak'])) {
            throw new \coding_exception('The \'streak\' value must be set in other.');
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
        return ['db' => 'onelife_run', 'restore' => 'onelife_run'];
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
