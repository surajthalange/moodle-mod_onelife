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
 * Library of interface functions and constants for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return whether the module supports a given feature.
 *
 * @param string $feature one of the FEATURE_* constants
 * @return mixed true or false when the feature is known, null otherwise
 */
function suddendeath_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        // Deliberately ungraded in v1: the activity is low-stakes practice (PRD section 8).
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        // True now that backup/moodle2/ holds all four classes. Claiming true without
        // them makes the course-module delete path throw, which is why this stayed
        // false until they existed.
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_INTERACTIVECONTENT;
        default:
            return null;
    }
}

/**
 * Create a new Sudden Death instance.
 *
 * @param stdClass $moduleinstance data from the module form
 * @param mod_suddendeath_mod_form|null $mform the form itself, unused here
 * @return int id of the new instance
 */
function suddendeath_add_instance($moduleinstance, $mform = null) {
    global $DB;

    $now = time();
    $moduleinstance->timecreated = $now;
    $moduleinstance->timemodified = $now;

    return $DB->insert_record('suddendeath', $moduleinstance);
}

/**
 * Update an existing Sudden Death instance.
 *
 * @param stdClass $moduleinstance data from the module form
 * @param mod_suddendeath_mod_form|null $mform the form itself, unused here
 * @return bool true on success
 */
function suddendeath_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    return $DB->update_record('suddendeath', $moduleinstance);
}

/**
 * Course module information, including the custom completion rules.
 *
 * Registering the rule here is what makes it visible to core: the completion API
 * only evaluates rules that appear in customdata, and it filters out any whose
 * value is empty. A completionstreak of 0 therefore disables the rule without any
 * extra handling.
 *
 * @param stdClass $coursemodule the course module
 * @return cached_cm_info|false the info, or false when the instance is missing
 */
function suddendeath_get_coursemodule_info($coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionstreak';
    $instance = $DB->get_record('suddendeath', ['id' => $coursemodule->instance], $fields);
    if (!$instance) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $instance->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('suddendeath', $instance, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionstreak'] = (int) $instance->completionstreak;
    }

    return $info;
}

/**
 * Delete a Sudden Death instance along with every run and answer belonging to it.
 *
 * @param int $id id of the instance to remove
 * @return bool true on success, false when the instance does not exist
 */
function suddendeath_delete_instance($id) {
    global $DB;

    $moduleinstance = $DB->get_record('suddendeath', ['id' => $id]);
    if (!$moduleinstance) {
        return false;
    }

    // Answers hang off runs, so clear them first to avoid orphan rows.
    $runids = $DB->get_fieldset_select('suddendeath_run', 'id', 'suddendeathid = ?', [$id]);
    if (!empty($runids)) {
        [$insql, $params] = $DB->get_in_or_equal($runids);
        $DB->delete_records_select('suddendeath_answer', "runid {$insql}", $params);
    }
    $DB->delete_records('suddendeath_run', ['suddendeathid' => $id]);
    $DB->delete_records('suddendeath', ['id' => $id]);

    return true;
}

/**
 * Add this module's options to the course reset form.
 *
 * Without this the module is listed under "these activities can't be reset", which
 * leaves a teacher no way to clear a year's runs before reusing the course.
 *
 * @param MoodleQuickForm $mform the course reset form
 */
function suddendeath_reset_course_form_definition($mform) {
    $mform->addElement('header', 'suddendeathheader', get_string('modulenameplural', 'mod_suddendeath'));
    $mform->addElement('advcheckbox', 'reset_suddendeath_all', get_string('resetruns', 'mod_suddendeath'));
}

/**
 * Default state of this module's reset options.
 *
 * Ticked by default, matching every other activity: a teacher resetting a course
 * for a new cohort expects learner data to go.
 *
 * @param stdClass $course the course being reset
 * @return array default values keyed by form element name
 */
function suddendeath_reset_course_form_defaults($course) {
    return ['reset_suddendeath_all' => 1];
}

/**
 * Remove learner data for every Sudden Death activity in a course.
 *
 * Only acts when its own option was ticked. Course reset runs every component in
 * turn, so treating an absent setting as consent would destroy runs during a reset
 * a teacher asked for something else entirely.
 *
 * @param stdClass $data the submitted reset form data, including courseid
 * @return array one status row per action taken, in the shape core's report expects
 */
function suddendeath_reset_userdata($data) {
    global $DB;

    if (empty($data->reset_suddendeath_all)) {
        return [];
    }

    $instanceids = $DB->get_fieldset_select('suddendeath', 'id', 'course = ?', [$data->courseid]);

    if (!empty($instanceids)) {
        // Answers hang off runs, so clear them first to avoid orphan rows. Scoping the
        // delete through the run table rather than by course id keeps another course's
        // answers out of it even if two courses ever shared a run id.
        [$insql, $params] = $DB->get_in_or_equal($instanceids);
        $DB->delete_records_select(
            'suddendeath_answer',
            "runid IN (SELECT id FROM {suddendeath_run} WHERE suddendeathid {$insql})",
            $params
        );
        $DB->delete_records_select('suddendeath_run', "suddendeathid {$insql}", $params);
    }

    // Reported whether or not anything was found, because "nothing to delete" is
    // still an answer to what the teacher asked for.
    return [[
        'component' => get_string('modulenameplural', 'mod_suddendeath'),
        'item' => get_string('runsdeleted', 'mod_suddendeath'),
        'error' => false,
    ]];
}
