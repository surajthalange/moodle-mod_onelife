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
            return self::single_topic($submitted);
        }

        if ($scopetype === modes::MULTI) {
            return self::multi_topics($submitted);
        }

        // All-topics mode selects nothing explicitly.
        return [];
    }

    /**
     * The topic ids in a one-topic submission.
     *
     * The element is read as an array as well as a scalar, because a tampered post can
     * send either and both must reduce to a list this class can check.
     *
     * @param array $submitted the submitted form data
     * @return int[] the chosen topic ids
     */
    private static function single_topic(array $submitted): array {
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

    /**
     * The topic ids in a selected-topics submission.
     *
     * The ids come from the element names rather than their values, so an unticked box
     * contributes nothing and a fabricated name yields an id that validate() will find
     * is not in the bank.
     *
     * @param array $submitted the submitted form data
     * @return int[] the chosen topic ids, sorted
     */
    private static function multi_topics(array $submitted): array {
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

        if ($scopetype === modes::SINGLE) {
            return self::validate_single($chosen, $validtopicids);
        }

        if ($scopetype === modes::MULTI) {
            return self::validate_multi($chosen, $validtopicids);
        }

        // All-topics mode has nothing of its own to check.
        return [];
    }

    /**
     * Check a one-topic submission.
     *
     * @param int[] $chosen the topic ids extracted from the submission
     * @param int[] $validtopicids the topic ids the bank actually offers
     * @return array element name to error message, empty when valid
     */
    private static function validate_single(array $chosen, array $validtopicids): array {
        if (count($chosen) !== 1) {
            return [self::SINGLE_ELEMENT => get_string('errchooseonetopic', 'mod_suddendeath')];
        }

        if (!in_array($chosen[0], $validtopicids, true)) {
            return [self::SINGLE_ELEMENT => get_string('errunknowntopic', 'mod_suddendeath')];
        }

        return [];
    }

    /**
     * Check a selected-topics submission.
     *
     * The error is keyed on the group rather than on a member element, because an error
     * keyed on a member of a checkbox group is never rendered and the learner would see
     * the form come back silently unchanged.
     *
     * @param int[] $chosen the topic ids extracted from the submission
     * @param int[] $validtopicids the topic ids the bank actually offers
     * @return array element name to error message, empty when valid
     */
    private static function validate_multi(array $chosen, array $validtopicids): array {
        if ($chosen === []) {
            return [self::TOPIC_GROUP => get_string('errchooseatopic', 'mod_suddendeath')];
        }

        foreach ($chosen as $topicid) {
            if (!in_array($topicid, $validtopicids, true)) {
                return [self::TOPIC_GROUP => get_string('errunknowntopic', 'mod_suddendeath')];
            }
        }

        return [];
    }
}
