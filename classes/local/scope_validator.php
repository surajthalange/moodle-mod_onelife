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
 * Server-side rules for the scope picker.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

/**
 * What a learner is allowed to submit from the scope picker.
 *
 * The picker carries no JavaScript. Single-topic mode uses radio inputs, so the
 * browser enforces "exactly one" natively, which is also what makes it work on a
 * keyboard and read correctly to a screen reader. These rules are still the
 * authority: not rendering a control is presentation, refusing it on submission is
 * the actual restriction, and only the latter survives a forged post.
 *
 * Allowed modes and valid topic ids are passed in rather than looked up, so the
 * rules need no database and stay unit-testable.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope_validator {
    /** @var string Name of the group holding the multi-select topic checkboxes. */
    public const TOPIC_GROUP = 'topicgroup';

    /** @var string Prefix for every per-topic element. */
    public const TOPIC_PREFIX = 'topic_';

    /** @var string Name of the single-topic radio element. */
    public const SINGLE_ELEMENT = 'singletopic';

    /**
     * The form element name for a topic.
     *
     * Topic ids are integers, so this is the likeliest place in the plugin to end up
     * with a bare integer element name. HTML_QuickForm_group::getElementName() reads
     * an integer as a positional index rather than a name, which silently breaks
     * hideIf() for the whole group.
     *
     * @param int $topicid the topic category id
     * @return string the element name
     */
    public static function topic_element_name(int $topicid): string {
        return self::TOPIC_PREFIX . $topicid;
    }

    /**
     * The topic ids a submission selects, for the given mode.
     *
     * Reads by value, never by key presence: an unchecked advcheckbox posts a zero
     * through its hidden fallback input rather than being absent.
     *
     * Ids outside the bank are returned rather than filtered, so validate() can
     * reject them instead of silently ignoring a tampered submission.
     *
     * @param string $scopetype the submitted mode
     * @param array $submitted the submitted form data
     * @return int[] the chosen topic ids
     */
    public static function chosen_topics(string $scopetype, array $submitted): array {
        if ($scopetype === modes::SINGLE) {
            $raw = $submitted[self::SINGLE_ELEMENT] ?? null;
            if ($raw === null || $raw === '') {
                return [];
            }

            $ids = [];
            foreach (is_array($raw) ? $raw : [$raw] as $value) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        if ($scopetype === modes::MULTI) {
            $ids = [];
            foreach ($submitted as $key => $value) {
                if (strpos((string) $key, self::TOPIC_PREFIX) !== 0 || empty($value)) {
                    continue;
                }
                $id = (int) substr((string) $key, strlen(self::TOPIC_PREFIX));
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            sort($ids);

            return $ids;
        }

        // All-topics mode selects nothing explicitly.
        return [];
    }

    /**
     * Validate a scope submission.
     *
     * @param string[] $allowedmodes the modes the teacher permitted
     * @param int[] $validtopicids the topic ids the bank actually offers
     * @param array $submitted the submitted form data
     * @return array element name to error message, empty when the submission is valid
     */
    public static function validate(array $allowedmodes, array $validtopicids, array $submitted): array {
        if ($validtopicids === []) {
            return ['scopetype' => get_string('errnotopicsinbank', 'mod_suddendeath')];
        }

        $scopetype = (string) ($submitted['scopetype'] ?? '');

        if (!in_array($scopetype, modes::codes(), true) || !in_array($scopetype, $allowedmodes, true)) {
            return ['scopetype' => get_string('errmodenotallowed', 'mod_suddendeath')];
        }

        $chosen = self::chosen_topics($scopetype, $submitted);
        $errors = [];

        if ($scopetype === modes::SINGLE) {
            if (count($chosen) !== 1) {
                $errors[self::SINGLE_ELEMENT] = get_string('errchooseonetopic', 'mod_suddendeath');
            } else if (!in_array($chosen[0], $validtopicids, true)) {
                $errors[self::SINGLE_ELEMENT] = get_string('errunknowntopic', 'mod_suddendeath');
            }
        } else if ($scopetype === modes::MULTI) {
            if ($chosen === []) {
                $errors[self::TOPIC_GROUP] = get_string('errchooseatopic', 'mod_suddendeath');
            } else {
                foreach ($chosen as $topicid) {
                    if (!in_array($topicid, $validtopicids, true)) {
                        $errors[self::TOPIC_GROUP] = get_string('errunknowntopic', 'mod_suddendeath');
                        break;
                    }
                }
            }
        }

        return $errors;
    }
}
