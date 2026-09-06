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
 * Renderer for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\output;

use plugin_renderer_base;

/**
 * Renders the plugin's pages.
 *
 * Each method does nothing but hand a renderable's exported context to its template,
 * so all presentation lives in Mustache and all decisions live in the renderable.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the scope picker page.
     *
     * @param picker_page $page the renderable
     * @return string the rendered HTML
     */
    protected function render_picker_page(picker_page $page): string {
        return $this->render_from_template('mod_suddendeath/picker', $page->export_for_template($this));
    }

    /**
     * Render one question in a run.
     *
     * @param play_page $page the renderable
     * @return string the rendered HTML
     */
    protected function render_play_page(play_page $page): string {
        return $this->render_from_template('mod_suddendeath/play', $page->export_for_template($this));
    }

    /**
     * Render the end of a run.
     *
     * @param summary_page $page the renderable
     * @return string the rendered HTML
     */
    protected function render_summary_page(summary_page $page): string {
        return $this->render_from_template('mod_suddendeath/summary', $page->export_for_template($this));
    }
}
