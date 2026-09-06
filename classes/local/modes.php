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
 * Scope modes and their form encoding.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\local;

/**
 * The scope modes a teacher may permit, and how they cross the form boundary.
 *
 * The stored codes are deliberately stable and separate from the visible labels.
 * Labels live in the language pack and a site may override them; the codes are the
 * on-disk contract and must not change.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class modes {
    /** @var string Play one chosen topic. */
    public const SINGLE = 'single';

    /** @var string Play a chosen selection of topics. */
    public const MULTI = 'multi';

    /** @var string Play every topic in the bank. */
    public const ALL = 'all';

    /** @var string Prefix for every per-mode form element. */
    public const ELEMENT_PREFIX = 'mode_';

    /** @var string Name of the group the mode checkboxes live in. */
    public const GROUP_NAME = 'allowedmodesgroup';

    /**
     * The stored mode codes, in canonical order.
     *
     * @return string[] the codes
     */
    public static function codes(): array {
        return [self::SINGLE, self::MULTI, self::ALL];
    }

    /**
     * The form element name for a mode.
     *
     * Always prefixed, so the name can never be a bare integer.
     * HTML_QuickForm_group::getElementName() treats an integer name as a positional
     * index rather than a name, which silently breaks disabledIf() and hideIf() for
     * every element in the group.
     *
     * @param string $code the mode code
     * @return string the element name
     */
    public static function element_name(string $code): string {
        return self::ELEMENT_PREFIX . $code;
    }

    /**
     * Read the selected modes out of submitted form data.
     *
     * An unchecked advcheckbox is not absent from the submission: it posts a zero
     * through the hidden fallback input formslib renders beside it. Selection is
     * therefore decided by the value, never by the key being present.
     *
     * @param array $data submitted form data
     * @return string[] the selected codes, in canonical order
     */
    public static function from_form_data(array $data): array {
        $selected = [];

        foreach (self::codes() as $code) {
            $name = self::element_name($code);
            if (!empty($data[$name])) {
                $selected[] = $code;
            }
        }

        return $selected;
    }

    /**
     * Encode modes for the allowedmodes column.
     *
     * @param string[] $codes the modes to store
     * @return string comma-separated codes in canonical order
     */
    public static function to_storage(array $codes): string {
        $ordered = [];

        foreach (self::codes() as $code) {
            if (in_array($code, $codes, true)) {
                $ordered[] = $code;
            }
        }

        return implode(',', $ordered);
    }

    /**
     * Decode the allowedmodes column.
     *
     * Unknown codes are dropped rather than passed on, so a downgrade, a hand-edited
     * row or a code from a future version cannot reach the rest of the plugin.
     *
     * @param string|null $stored the column value
     * @return string[] the modes, in canonical order
     */
    public static function from_storage(?string $stored): array {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        $found = array_map('trim', explode(',', $stored));

        $valid = [];
        foreach (self::codes() as $code) {
            if (in_array($code, $found, true)) {
                $valid[] = $code;
            }
        }

        return $valid;
    }

    /**
     * The translated label for a mode.
     *
     * @param string $code the mode code
     * @return string the label
     */
    public static function label(string $code): string {
        return get_string('mode_' . $code, 'mod_onelife');
    }
}
