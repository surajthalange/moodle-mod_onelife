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
 * Locates the topic bank and its topics for a course.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath;

use context_course;
use context_module;
use stdClass;

/**
 * Finds the question categories a Sudden Death activity draws its topics from.
 *
 * Three behaviours here are load-bearing and each is covered by its own test:
 *
 * 1. Context breadth. Question banks do not always live in the course. On 5.0+ they
 *    live in qbank module contexts; before that, a bank shared across a department
 *    commonly sits at the course-category context instead.
 * 2. Nearest context wins. When several contexts hold candidates, the nearest one is
 *    chosen. Picking the lowest category id instead lets an old system-wide bank
 *    outrank the course's own every time.
 * 3. The auto-provisioned default category is skipped. Core creates a
 *    "Default for X" category as soon as a bank context exists, always with a lower
 *    id than anything a teacher made. There is no schema flag marking it, so it is
 *    identified by the name core gives it.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class topic_repository {
    /** @var string[] Warnings raised while resolving, for the caller to surface. */
    private array $warnings = [];

    /**
     * Whether question banks live in qbank module contexts on this Moodle.
     *
     * mod_qbank arrived in Moodle 5.0 and does not exist on 4.5. Branching on the
     * module being installed tests the thing that actually matters, and keeps
     * working if the version numbering ever surprises us.
     *
     * @return bool true when mod_qbank is installed
     */
    public static function uses_qbank_module_contexts(): bool {
        return \core_component::get_component_directory('mod_qbank') !== null;
    }

    /**
     * Warnings raised by the most recent get_topic_bank_category() call.
     *
     * @return string[] translated warning messages, empty when all was well
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * The contexts that may hold this course's question bank, nearest first.
     *
     * Order is the whole point: callers take the first context that yields a usable
     * bank, so "nearest first" is what makes the nearest context win.
     *
     * @param int $courseid the course to search from
     * @return int[] context ids, nearest first, without duplicates
     */
    public function get_context_ids(int $courseid): array {
        $contextids = [];

        if (self::uses_qbank_module_contexts()) {
            $modinfo = get_fast_modinfo($courseid);
            foreach ($modinfo->get_instances_of('qbank') as $cm) {
                $contextids[] = (int) context_module::instance($cm->id)->id;
            }
        }

        // Self first, then parents nearest first, which is what get_parent_context_ids
        // returns with $includeself. On 4.5 this is the only source; on 5.0+ it still
        // matters, because an upgraded site can keep categories at these levels.
        foreach (context_course::instance($courseid)->get_parent_context_ids(true) as $contextid) {
            $contextids[] = (int) $contextid;
        }

        return array_values(array_unique($contextids));
    }

    /**
     * Resolve the topic bank category for a course.
     *
     * An explicit id configured on the instance always wins, provided it is still
     * usable. When it is not, resolution falls back to auto-detection and records a
     * warning rather than failing: a teacher who deletes a category should not take
     * the activity down with it, but should be told.
     *
     * @param int $courseid the course to search from
     * @param int|null $configuredid explicit category id from the instance, if any
     * @return stdClass|null the bank category, or null when nothing usable was found
     */
    public function get_topic_bank_category(int $courseid, ?int $configuredid): ?stdClass {
        $this->warnings = [];

        $contextids = $this->get_context_ids($courseid);

        if (!empty($configuredid)) {
            $configured = $this->usable_category($configuredid, $contextids);
            if ($configured !== null) {
                return $configured;
            }
            $this->warnings[] = get_string('warningtopicbankunusable', 'mod_suddendeath');
        }

        foreach ($contextids as $contextid) {
            $candidate = $this->first_usable_category_in_context($contextid);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The topics offered by a bank: its immediate children, ordered by name.
     *
     * A topic is not required to hold questions itself. Topic categories are
     * routinely containers with the questions a level or more further down, which is
     * why callers expand them with question_categorylist() rather than reading
     * questions straight off the topic.
     *
     * @param int $courseid the course the bank must be reachable from
     * @param int $bankcategoryid the bank category
     * @return array topic id to topic name, ordered by name
     */
    public function get_topics(int $courseid, int $bankcategoryid): array {
        global $DB;

        $bank = $DB->get_record('question_categories', ['id' => $bankcategoryid]);
        if (!$bank) {
            return [];
        }

        // Refuse a bank outside this course's reachable contexts, so a tampered or
        // stale instance setting cannot pull in another course's categories.
        if (!in_array((int) $bank->contextid, $this->get_context_ids($courseid), true)) {
            return [];
        }

        $children = $DB->get_records(
            'question_categories',
            ['parent' => $bankcategoryid],
            'name ASC',
            'id, name'
        );

        $topics = [];
        foreach ($children as $child) {
            $topics[(int) $child->id] = $child->name;
        }

        return $topics;
    }

    /**
     * Return the category if it is a usable bank reachable from these contexts.
     *
     * @param int $categoryid the category to check
     * @param int[] $contextids the contexts reachable from the course
     * @return stdClass|null the category, or null when it is not usable
     */
    private function usable_category(int $categoryid, array $contextids): ?stdClass {
        global $DB;

        $category = $DB->get_record('question_categories', ['id' => $categoryid]);
        if (!$category) {
            return null;
        }

        if (!in_array((int) $category->contextid, $contextids, true)) {
            return null;
        }

        if ($this->is_unusable_as_bank($category)) {
            return null;
        }

        return $category;
    }

    /**
     * The first category in a context that can serve as a topic bank.
     *
     * Only direct children of the context's top category are considered. A topic one
     * level further down also has children, and its sortorder is numbered within its
     * own parent, so it can sort ahead of the bank it belongs to; treating any
     * category with children as a candidate returns the topic instead of the bank.
     *
     * @param int $contextid the context to search
     * @return stdClass|null the category, or null when the context has none
     */
    private function first_usable_category_in_context(int $contextid): ?stdClass {
        global $DB;

        $topid = $DB->get_field('question_categories', 'id', ['contextid' => $contextid, 'parent' => 0]);
        if (!$topid) {
            return null;
        }

        $candidates = $DB->get_records_select(
            'question_categories',
            'contextid = ? AND parent = ?',
            [$contextid, $topid],
            'sortorder, id'
        );

        foreach ($candidates as $candidate) {
            if ($this->is_unusable_as_bank($candidate)) {
                continue;
            }
            return $candidate;
        }

        return null;
    }

    /**
     * Whether a category cannot serve as a topic bank.
     *
     * @param stdClass $category the category to judge
     * @return bool true when it must be skipped
     */
    private function is_unusable_as_bank(stdClass $category): bool {
        global $DB;

        // A parent of 0 marks the hidden "top" category core creates per context, not
        // a real category a teacher ever sees or files questions in.
        if ((int) $category->parent === 0) {
            return true;
        }

        if ($category->name === $this->default_category_name((int) $category->contextid)) {
            return true;
        }

        // A bank with no topics beneath it is not a bank.
        return !$DB->record_exists('question_categories', ['parent' => $category->id]);
    }

    /**
     * The name core gives the auto-provisioned default category in a context.
     *
     * Built with get_string so it matches on any site language, and shortened the
     * same way core shortens it, so the comparison holds for long context names.
     *
     * @param int $contextid the context
     * @return string the name, or an empty string when the context has gone
     */
    private function default_category_name(int $contextid): string {
        $context = \core\context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            return '';
        }

        return shorten_text(
            get_string('defaultfor', 'question', $context->get_context_name(false, true)),
            1333
        );
    }
}
