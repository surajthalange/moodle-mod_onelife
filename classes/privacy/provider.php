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
 * Privacy provider for mod_onelife.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * Handles subject access requests for One Life.
 *
 * The plugin records who played, when, what they chose and how each answer went, so
 * this is a full provider rather than a null one. Every field in both tables is
 * declared, because a privacy statement that does not match the schema is worse than
 * none.
 *
 * Answers carry no userid of their own: they belong to a run, and the run says whose
 * they are. Every delete therefore resolves runs first and removes their answers
 * with them, so no answer is ever orphaned from the person it describes.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe everything this plugin stores about a person.
     *
     * @param collection $collection the collection to add to
     * @return collection the collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'onelife_run',
            [
                'onelifeid' => 'privacy:metadata:onelife_run:onelifeid',
                'userid' => 'privacy:metadata:onelife_run:userid',
                'scopetype' => 'privacy:metadata:onelife_run:scopetype',
                'topicids' => 'privacy:metadata:onelife_run:topicids',
                'streak' => 'privacy:metadata:onelife_run:streak',
                'targetstreak' => 'privacy:metadata:onelife_run:targetstreak',
                'currentquestionid' => 'privacy:metadata:onelife_run:currentquestionid',
                'timecreated' => 'privacy:metadata:onelife_run:timecreated',
                'timefinish' => 'privacy:metadata:onelife_run:timefinish',
            ],
            'privacy:metadata:onelife_run'
        );

        $collection->add_database_table(
            'onelife_answer',
            [
                'runid' => 'privacy:metadata:onelife_answer:runid',
                'topicid' => 'privacy:metadata:onelife_answer:topicid',
                'questionid' => 'privacy:metadata:onelife_answer:questionid',
                'correct' => 'privacy:metadata:onelife_answer:correct',
                'timecreated' => 'privacy:metadata:onelife_answer:timecreated',
            ],
            'privacy:metadata:onelife_answer'
        );

        return $collection;
    }

    /**
     * The contexts where a person has One Life data.
     *
     * @param int $userid the person
     * @return contextlist the contexts
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {onelife_run} r
                  JOIN {onelife} s ON s.id = r.onelifeid
                  JOIN {course_modules} cm ON cm.instance = s.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE r.userid = :userid";

        $contextlist->add_from_sql($sql, [
            'modname' => 'onelife',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Everyone who has data in a context.
     *
     * @param userlist $userlist the list to populate
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT r.userid
                  FROM {onelife_run} r
                  JOIN {onelife} s ON s.id = r.onelifeid
                  JOIN {course_modules} cm ON cm.instance = s.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, [
            'modname' => 'onelife',
            'cmid' => $context->instanceid,
        ]);
    }

    /**
     * Export a person's runs and answers.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('onelife', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            $runs = $DB->get_records(
                'onelife_run',
                ['onelifeid' => $cm->instance, 'userid' => $userid],
                'timecreated ASC'
            );

            if ($runs === []) {
                continue;
            }

            $export = [];
            foreach ($runs as $run) {
                $answers = $DB->get_records('onelife_answer', ['runid' => $run->id], 'timecreated ASC');

                $export[] = [
                    'scopetype' => $run->scopetype,
                    'topicids' => $run->topicids,
                    'streak' => (int) $run->streak,
                    'targetstreak' => (int) $run->targetstreak,
                    'timecreated' => transform::datetime($run->timecreated),
                    'timefinish' => $run->timefinish ? transform::datetime($run->timefinish) : null,
                    'answers' => array_values(array_map(static function (stdClass $answer): array {
                        return [
                            'questionid' => (int) $answer->questionid,
                            'topicid' => (int) $answer->topicid,
                            'correct' => transform::yesno($answer->correct),
                            'timecreated' => transform::datetime($answer->timecreated),
                        ];
                    }, $answers)),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:runs', 'mod_onelife')],
                (object) ['runs' => $export]
            );
        }
    }

    /**
     * Delete everyone's data in a context.
     *
     * @param context $context the context being purged
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('onelife', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        self::delete_runs_matching($DB->get_fieldset_select(
            'onelife_run',
            'id',
            'onelifeid = ?',
            [$cm->instance]
        ));
    }

    /**
     * Delete one person's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('onelife', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }

            self::delete_runs_matching($DB->get_fieldset_select(
                'onelife_run',
                'id',
                'onelifeid = ? AND userid = ?',
                [$cm->instance, $userid]
            ));
        }
    }

    /**
     * Delete the approved people's data in a context.
     *
     * @param approved_userlist $userlist the approved people
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('onelife', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if ($userids === []) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params['instanceid'] = $cm->instance;

        self::delete_runs_matching($DB->get_fieldset_select(
            'onelife_run',
            'id',
            "onelifeid = :instanceid AND userid {$insql}",
            $params
        ));
    }

    /**
     * Remove runs and the answers belonging to them.
     *
     * Answers are removed first: they have no userid of their own, so once the run
     * is gone there is nothing left to identify them by.
     *
     * @param array $runids the runs to remove
     */
    protected static function delete_runs_matching(array $runids): void {
        global $DB;

        if ($runids === []) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $runids));

        $DB->delete_records_select('onelife_answer', "runid {$insql}", $params);
        $DB->delete_records_select('onelife_run', "id {$insql}", $params);
    }
}
