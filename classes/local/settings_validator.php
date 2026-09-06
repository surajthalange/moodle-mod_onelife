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
 * Server-side validation of the settings form.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\local;

use mod_onelife\topic_repository;

/**
 * The rules the settings form enforces, independent of the form itself.
 *
 * These live outside mod_form::validation() so they can be tested without building
 * a form. validation() delegates here and does nothing else, which means the tested
 * rules and the enforced rules cannot drift apart.
 *
 * Client-side JavaScript is a usability aid only. Every rule here must hold for a
 * submission made with scripting disabled or forged outright.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_validator {
    /** @var topic_repository Resolves which categories this course may use. */
    private topic_repository $repository;

    /**
     * Constructor.
     *
     * @param topic_repository $repository the repository used to check reachability
     */
    public function __construct(topic_repository $repository) {
        $this->repository = $repository;
    }

    /**
     * Validate a submission.
     *
     * Every rule runs on every submission, so a teacher sees all the problems at
     * once rather than one per attempt.
     *
     * @param int $courseid the course the instance belongs to
     * @param array $data the submitted form data
     * @return array element name to error message, empty when the submission is valid
     */
    public function validate(int $courseid, array $data): array {
        $errors = [];

        if (modes::from_form_data($data) === []) {
            $errors[modes::GROUP_NAME] = get_string('errnomodes', 'mod_onelife');
        }

        $targetstreak = $data['targetstreak'] ?? '';
        if (!$this->is_whole_number($targetstreak) || (int) $targetstreak < 1) {
            $errors['targetstreak'] = get_string('errtargetstreak', 'mod_onelife');
        }

        // Blank and zero both disable the completion rule and are valid; negative is not.
        $completionstreak = $data['completionstreak'] ?? '';
        if (trim((string) $completionstreak) !== '') {
            if (!$this->is_whole_number($completionstreak) || (int) $completionstreak < 0) {
                $errors['completionstreak'] = get_string('errcompletionstreak', 'mod_onelife');
            }
        }

        // Zero means auto-detect, which is always allowed.
        $bankid = (int) ($data['topicbankcategoryid'] ?? 0);
        if ($bankid > 0 && !$this->repository->is_usable_bank($courseid, $bankid)) {
            $errors['topicbankcategoryid'] = get_string('errtopicbank', 'mod_onelife');
        }

        return $errors;
    }

    /**
     * Whether a submitted value is a whole number.
     *
     * Rejects decimals, exponent notation and anything non-numeric, so "1e3" and
     * "2.5" never reach an integer column.
     *
     * @param mixed $value the submitted value
     * @return bool true when the value is a whole number
     */
    private function is_whole_number($value): bool {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        return (bool) preg_match('/^-?[0-9]+$/', $value);
    }
}
