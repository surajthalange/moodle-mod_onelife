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
 * Tests for scope mode encoding and the form read path.
 *
 * These cover two of the section 7 form pitfalls directly: that no dynamic element
 * is ever given a bare integer name, and that an unchecked advcheckbox submitting a
 * zero through its hidden fallback input is not read as a selection.
 *
 * Pure logic, so basic_testcase, which forbids database access.
 *
 * Metadata stays in doc-comments rather than PHP attributes: attributes arrived in
 * PHPUnit 10 and Moodle 4.5, this plugin's floor, ships PHPUnit ^9.6.34.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\local;

/**
 * Tests for the modes class.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_suddendeath\local\modes
 */
final class modes_test extends \basic_testcase {
    /**
     * The stored codes are the three the PRD fixes, and nothing else.
     *
     * The codes are the on-disk contract. The visible labels are free to change in
     * the language pack without touching stored data, which is the whole reason the
     * PRD keeps the internal codes stable.
     */
    public function test_codes_are_the_three_stored_values(): void {
        $this->assertSame(['single', 'multi', 'all'], modes::codes());
    }

    /**
     * Element names are prefixed and never a bare integer.
     *
     * HTML_QuickForm_group::getElementName() treats an integer name as a positional
     * index rather than a name, which silently breaks disabledIf() and hideIf() for
     * the whole group. Prefixing every dynamic element is what avoids that.
     */
    public function test_element_names_are_prefixed_and_never_numeric(): void {
        foreach (modes::codes() as $code) {
            $name = modes::element_name($code);

            $this->assertStringStartsWith('mode_', $name);
            $this->assertFalse(is_numeric($name), "Element name {$name} must not be numeric.");
            $this->assertNotSame('', preg_replace('/[0-9]/', '', $name));
        }
    }

    /**
     * An unchecked advcheckbox submits zero and must not read as selected.
     *
     * advcheckbox renders a hidden input alongside the checkbox, so an unchecked box
     * still posts a value of 0 rather than being absent. Reading the keys without
     * filtering treats every mode as selected.
     */
    public function test_unchecked_checkboxes_are_not_read_as_selected(): void {
        $submitted = [
            'mode_single' => '1',
            'mode_multi' => '0',
            'mode_all' => 0,
        ];

        $this->assertSame(['single'], modes::from_form_data($submitted));
    }

    /**
     * All boxes unchecked yields no modes, not all modes.
     */
    public function test_all_unchecked_yields_no_modes(): void {
        $submitted = ['mode_single' => '0', 'mode_multi' => '0', 'mode_all' => '0'];

        $this->assertSame([], modes::from_form_data($submitted));
    }

    /**
     * Every box checked yields all three, in the canonical order.
     */
    public function test_all_checked_yields_all_modes_in_canonical_order(): void {
        $submitted = ['mode_all' => '1', 'mode_single' => '1', 'mode_multi' => '1'];

        $this->assertSame(['single', 'multi', 'all'], modes::from_form_data($submitted));
    }

    /**
     * Keys that are not mode elements are ignored.
     */
    public function test_unrelated_form_keys_are_ignored(): void {
        $submitted = [
            'name' => 'Revision Sprint',
            'targetstreak' => '15',
            'mode_single' => '1',
            'mode_nonsense' => '1',
        ];

        $this->assertSame(['single'], modes::from_form_data($submitted));
    }

    /**
     * Storage is the comma-separated form the instance column holds.
     */
    public function test_to_storage_joins_in_canonical_order(): void {
        $this->assertSame('single,multi,all', modes::to_storage(['all', 'single', 'multi']));
        $this->assertSame('single', modes::to_storage(['single']));
        $this->assertSame('', modes::to_storage([]));
    }

    /**
     * Reading storage back tolerates the states a real column reaches.
     */
    public function test_from_storage_handles_real_column_values(): void {
        $this->assertSame(['single', 'multi', 'all'], modes::from_storage('single,multi,all'));
        $this->assertSame(['single'], modes::from_storage('single'));
        $this->assertSame([], modes::from_storage(''));
        $this->assertSame([], modes::from_storage(null));
        $this->assertSame(['single', 'multi'], modes::from_storage(' single , multi '));
    }

    /**
     * Unknown codes in storage are dropped rather than propagated.
     *
     * A downgrade, a hand-edited row or a future code that this version does not
     * understand must not reach the rest of the plugin.
     */
    public function test_from_storage_drops_unknown_codes(): void {
        $this->assertSame(['single', 'all'], modes::from_storage('single,sniper,all'));
        $this->assertSame([], modes::from_storage('crossfire,totalwar'));
    }

    /**
     * Storage survives a round trip unchanged.
     */
    public function test_storage_round_trip(): void {
        $stored = modes::to_storage(['multi', 'all']);

        $this->assertSame(['multi', 'all'], modes::from_storage($stored));
    }
}
