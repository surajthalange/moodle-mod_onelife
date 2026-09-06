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
 * Personal records across runs.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

use stdClass;

/**
 * A learner's personal bests, per scope, in one activity.
 *
 * This is the only code that reads across runs, so it is the only place a missing
 * condition would show one learner another's results. Both userid and suddendeathid
 * are conditions on the single query that reads runs; there is no code path that
 * reads a run without them.
 *
 * Two queries, whatever the data: one for the learner's finished runs, one for the
 * names of every topic those runs mention. Grouping happens in PHP because a scope
 * is a set of topics rather than the string the column happens to hold, and SQL
 * cannot group on that without a canonical column to group by.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stats_repository {
    /**
     * The canonical key for a scope.
     *
     * Topic ids are sorted and deduplicated, because the topics a learner chose are
     * a set: picking Cells then Genetics is the same scope as picking Genetics then
     * Cells, and their bests belong together.
     *
     * The mode stays in the key. Playing everything because the teacher offered
     * "all topics" is a different choice from ticking every topic by hand, so those
     * two are not pooled even when the topic sets coincide.
     *
     * @param string $scopetype the mode
     * @param int[] $topicids the topics in scope
     * @return string the canonical key
     */
    public static function scope_key(string $scopetype, array $topicids): string {
        $ids = array_values(array_unique(array_map('intval', $topicids)));
        sort($ids, SORT_NUMERIC);

        return $scopetype . ':' . implode(',', $ids);
    }

    /**
     * A learner's records in one activity, best scope first.
     *
     * @param int $suddendeathid the activity instance
     * @param int $userid the learner
     * @return stdClass[] records keyed by scope key
     */
    public function get_records(int $suddendeathid, int $userid): array {
        global $DB;

        // Query one. Both conditions are mandatory: suddendeathid keeps other
        // activities out, userid keeps other learners out. Unfinished runs are not
        // records yet, so an open run cannot inflate a best just by existing.
        $runs = $DB->get_records_select(
            'suddendeath_run',
            'suddendeathid = :suddendeathid AND userid = :userid AND timefinish IS NOT NULL',
            ['suddendeathid' => $suddendeathid, 'userid' => $userid],
            'timefinish DESC, id DESC',
            'id, scopetype, topicids, streak, timefinish'
        );

        if ($runs === []) {
            return [];
        }

        $records = [];
        $alltopicids = [];

        foreach ($runs as $run) {
            $topicids = $this->parse_topic_ids((string) $run->topicids);
            $key = self::scope_key((string) $run->scopetype, $topicids);

            foreach ($topicids as $topicid) {
                $alltopicids[$topicid] = $topicid;
            }

            if (!isset($records[$key])) {
                // Runs arrive newest first, so the first one seen for a scope is the
                // most recent, which is what "last run" means.
                $record = new stdClass();
                $record->key = $key;
                $record->scopetype = (string) $run->scopetype;
                $record->topicids = $topicids;
                $record->topicnames = [];
                $record->beststreak = (int) $run->streak;
                $record->totalruns = 0;
                $record->laststreak = (int) $run->streak;
                $record->lasttime = (int) $run->timefinish;
                $records[$key] = $record;
            }

            $records[$key]->totalruns++;
            $records[$key]->beststreak = max($records[$key]->beststreak, (int) $run->streak);
        }

        $this->attach_topic_names($records, $alltopicids);

        // Best scope first, so the record a learner is chasing is at the top.
        uasort($records, static function (stdClass $a, stdClass $b): int {
            return [$b->beststreak, $b->lasttime] <=> [$a->beststreak, $a->lasttime];
        });

        return $records;
    }

    /**
     * Resolve topic names for every record, in one query.
     *
     * A topic deleted since the run is replaced with a placeholder rather than
     * dropped: the learner played it, and their history should survive the category
     * being tidied up.
     *
     * @param stdClass[] $records the records, updated in place
     * @param int[] $topicids every topic id mentioned
     */
    protected function attach_topic_names(array $records, array $topicids): void {
        global $DB;

        $names = [];

        // Query two. One IN lookup for every topic across every record, so the count
        // does not grow with the number of scopes or topics.
        if ($topicids !== []) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($topicids), SQL_PARAMS_NAMED, 'topic');
            $names = $DB->get_records_select_menu(
                'question_categories',
                "id {$insql}",
                $params,
                '',
                'id, name'
            );
        }

        $missing = get_string('deletedtopic', 'mod_suddendeath');

        foreach ($records as $record) {
            foreach ($record->topicids as $topicid) {
                $record->topicnames[] = isset($names[$topicid])
                    ? format_string($names[$topicid])
                    : $missing;
            }
        }
    }

    /**
     * Read the topicids column into a list of ids.
     *
     * Normalised on read as well as on write, so runs recorded before the column was
     * canonicalised still group correctly.
     *
     * @param string $stored the column value
     * @return int[] the topic ids, sorted and deduplicated
     */
    protected function parse_topic_ids(string $stored): array {
        $ids = array_filter(array_map('intval', explode(',', $stored)));
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
