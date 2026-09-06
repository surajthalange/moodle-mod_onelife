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
 * Custom completion rules for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\completion;

use core_completion\activity_custom_completion;

/**
 * Completes the activity when the learner reaches the required streak.
 *
 * get_completion_state() no longer exists on either 4.5 or 5.2, so this API covers
 * the whole supported range with no version branch.
 *
 * The rule asks whether the learner has ever finished a run at or above the target,
 * not what their current standing is. Completion is an achievement, so a poor run
 * afterwards does not take it back.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Whether the learner has met a completion rule.
     *
     * @param string $rule the rule to evaluate
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $required = (int) $DB->get_field('suddendeath', 'completionstreak', ['id' => $this->cm->instance]);

        // Zero, or blank, means the teacher did not ask for a streak requirement, so
        // this rule can never be what completes the activity.
        if ($required < 1) {
            return COMPLETION_INCOMPLETE;
        }

        // Only finished runs count. An open run would let a learner be marked complete
        // while sitting on a streak they have not yet survived.
        $met = $DB->record_exists_select(
            'suddendeath_run',
            'suddendeathid = :instanceid
                 AND userid = :userid
                 AND timefinish IS NOT NULL
                 AND streak >= :required',
            [
                'instanceid' => $this->cm->instance,
                'userid' => $this->userid,
                'required' => $required,
            ]
        );

        return $met ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The custom rules this module defines.
     *
     * @return array the rule names
     */
    public static function get_defined_custom_rules(): array {
        return ['completionstreak'];
    }

    /**
     * Descriptions of the custom rules, for the activity's completion summary.
     *
     * @return array rule name to description
     */
    public function get_custom_rule_descriptions(): array {
        global $DB;

        $required = (int) $DB->get_field('suddendeath', 'completionstreak', ['id' => $this->cm->instance]);

        return [
            'completionstreak' => get_string('completiondetail:streak', 'mod_suddendeath', $required),
        ];
    }

    /**
     * The order rules are shown in.
     *
     * @return array the rule names in display order
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionstreak',
        ];
    }
}
