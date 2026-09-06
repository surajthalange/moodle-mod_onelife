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
 * Tests for the privacy provider.
 *
 * The plugin stores a userid, run timings and per-question answers, so this is a
 * full provider rather than a null one. Every delete path is tested for what it
 * leaves behind as well as what it removes: a delete that also wipes another
 * learner's rows is as wrong as one that wipes nothing.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\privacy;

use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * Tests for the mod_suddendeath privacy provider.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var stdClass A second instance. */
    private stdClass $otherinstance;

    /** @var int The learner. */
    private int $userid;

    /** @var int A second learner. */
    private int $otheruserid;

    /**
     * Build two instances, two learners and a run each.
     */
    private function set_up(): void {
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->userid = (int) $this->getDataGenerator()->create_user()->id;
        $this->otheruserid = (int) $this->getDataGenerator()->create_user()->id;

        $this->instance = $this->getDataGenerator()->create_module('suddendeath', ['course' => $this->course->id]);
        $this->otherinstance = $this->getDataGenerator()->create_module('suddendeath', ['course' => $this->course->id]);

        $this->make_run((int) $this->instance->id, $this->userid, 7);
        $this->make_run((int) $this->instance->id, $this->otheruserid, 4);
        $this->make_run((int) $this->otherinstance->id, $this->userid, 2);
    }

    /**
     * Record a finished run with one answer.
     *
     * @param int $suddendeathid the instance
     * @param int $userid the learner
     * @param int $streak the streak reached
     * @return int the run id
     */
    private function make_run(int $suddendeathid, int $userid, int $streak): int {
        global $DB;

        $runid = (int) $DB->insert_record('suddendeath_run', (object) [
            'suddendeathid' => $suddendeathid,
            'userid' => $userid,
            'scopetype' => 'all',
            'topicids' => '3,4',
            'streak' => $streak,
            'targetstreak' => 15,
            'currentquestionid' => null,
            'timecreated' => time() - 300,
            'timefinish' => time() - 100,
        ]);

        $DB->insert_record('suddendeath_answer', (object) [
            'runid' => $runid,
            'topicid' => 3,
            'questionid' => 55,
            'correct' => 1,
            'timecreated' => time() - 200,
        ]);

        return $runid;
    }

    /**
     * The module context for an instance.
     *
     * @param stdClass $instance the instance
     * @return context_module the context
     */
    private function context_for(stdClass $instance): context_module {
        return context_module::instance(
            get_coursemodule_from_instance('suddendeath', $instance->id)->id
        );
    }

    /**
     * Metadata declares both tables and every field they hold.
     *
     * An undeclared field is a privacy statement that does not match the schema,
     * which is exactly what Marketplace review checks for.
     */
    public function test_metadata_declares_every_stored_field(): void {
        global $DB;

        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_suddendeath'));
        $items = $collection->get_collection();

        $declared = [];
        foreach ($items as $item) {
            $declared[$item->get_name()] = array_keys($item->get_privacy_fields());
        }

        $this->assertArrayHasKey('suddendeath_run', $declared);
        $this->assertArrayHasKey('suddendeath_answer', $declared);

        foreach (['suddendeath_run', 'suddendeath_answer'] as $table) {
            $columns = array_keys($DB->get_columns($table));
            foreach ($columns as $column) {
                if ($column === 'id') {
                    continue;
                }
                $this->assertContains(
                    $column,
                    $declared[$table],
                    "Column {$table}.{$column} is stored but not declared in the privacy metadata."
                );
            }
        }
    }

    /**
     * Only contexts the learner actually has data in are returned.
     */
    public function test_contexts_for_userid(): void {
        $this->set_up();

        // Cast: the database returns ids as strings, and this asserts the set of
        // contexts rather than their PHP type.
        $contexts = array_map('intval', provider::get_contexts_for_userid($this->userid)->get_contextids());

        $this->assertContains((int) $this->context_for($this->instance)->id, $contexts);
        $this->assertContains((int) $this->context_for($this->otherinstance)->id, $contexts);
        $this->assertCount(2, $contexts);
    }

    /**
     * A learner with no runs has no contexts.
     */
    public function test_no_contexts_for_a_learner_with_no_runs(): void {
        $this->set_up();

        $stranger = (int) $this->getDataGenerator()->create_user()->id;

        $this->assertCount(0, provider::get_contexts_for_userid($stranger)->get_contextids());
    }

    /**
     * Export writes the learner's runs, and only theirs.
     */
    public function test_export_contains_the_users_runs(): void {
        $this->set_up();

        $context = $this->context_for($this->instance);
        $this->export_context_data_for_user($this->userid, $context, 'mod_suddendeath');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([get_string('privacy:path:runs', 'mod_suddendeath')]);
        $this->assertNotEmpty($data);

        $streaks = array_map(static fn($run) => (int) $run['streak'], (array) $data->runs);
        $this->assertContains(7, $streaks, "The learner's own streak must be exported.");
        $this->assertNotContains(4, $streaks, "Another learner's streak must never be exported.");
    }

    /**
     * Deleting for one user removes their rows and leaves everyone else's.
     */
    public function test_delete_for_user(): void {
        global $DB;

        $this->set_up();

        $context = $this->context_for($this->instance);
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->userid),
            'mod_suddendeath',
            [$context->id]
        ));

        $this->assertSame(
            0,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->instance->id, 'userid' => $this->userid])
        );
        $this->assertSame(
            1,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->instance->id, 'userid' => $this->otheruserid]),
            "Another learner's run must survive."
        );
        $this->assertSame(
            1,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->otherinstance->id, 'userid' => $this->userid]),
            'A run in an unapproved context must survive.'
        );
    }

    /**
     * Deleting a user's data removes their answers too, leaving none orphaned.
     */
    public function test_delete_for_user_removes_answers(): void {
        global $DB;

        $this->set_up();

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->userid),
            'mod_suddendeath',
            [$this->context_for($this->instance)->id]
        ));

        $orphans = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {suddendeath_answer} a
               LEFT JOIN {suddendeath_run} r ON r.id = a.runid
              WHERE r.id IS NULL'
        );
        $this->assertSame(0, $orphans, 'Deleting runs must take their answers with them.');
    }

    /**
     * Deleting a whole context removes every learner's data in it, and no other.
     */
    public function test_delete_for_all_users_in_context(): void {
        global $DB;

        $this->set_up();

        provider::delete_data_for_all_users_in_context($this->context_for($this->instance));

        $this->assertSame(0, $DB->count_records('suddendeath_run', ['suddendeathid' => $this->instance->id]));
        $this->assertSame(
            1,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->otherinstance->id]),
            'Another activity must be untouched.'
        );
    }

    /**
     * Everyone with data in a context is listed.
     */
    public function test_get_users_in_context(): void {
        $this->set_up();

        $context = $this->context_for($this->instance);
        $userlist = new userlist($context, 'mod_suddendeath');
        provider::get_users_in_context($userlist);

        $found = array_map('intval', $userlist->get_userids());
        $this->assertContains($this->userid, $found);
        $this->assertContains($this->otheruserid, $found);
        $this->assertCount(2, $found);
    }

    /**
     * Deleting an approved userlist removes only those learners.
     */
    public function test_delete_for_users(): void {
        global $DB;

        $this->set_up();

        $context = $this->context_for($this->instance);
        provider::delete_data_for_users(new approved_userlist(
            $context,
            'mod_suddendeath',
            [$this->userid]
        ));

        $this->assertSame(
            0,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->instance->id, 'userid' => $this->userid])
        );
        $this->assertSame(
            1,
            $DB->count_records('suddendeath_run', ['suddendeathid' => $this->instance->id, 'userid' => $this->otheruserid]),
            'A learner who was not approved for deletion must keep their data.'
        );
    }
}
