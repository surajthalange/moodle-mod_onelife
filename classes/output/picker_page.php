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
 * Renderable for the scope picker page.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_onelife\output;

use mod_onelife\local\modes;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * The scope picker page.
 *
 * Decides whether the activity is playable at all and what to say when it is not,
 * so the template renders only what it is handed and contains no logic of its own.
 *
 * The mode and topic controls belong to the picker form, not here. A bank that
 * resolved but holds no topics is treated exactly like no bank: an empty form would
 * invite the learner to submit nothing and be told off for it.
 *
 * @package    mod_onelife
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class picker_page implements renderable, templatable {
    /** @var stdClass The activity instance. */
    private stdClass $instance;

    /** @var array Topic id to topic name. */
    private array $topics;

    /** @var bool Whether a usable topic bank was resolved. */
    private bool $hasbank;

    /** @var string The rendered picker form. */
    private string $formhtml;

    /** @var string[] Warnings to surface to the viewer. */
    private array $warnings;

    /** @var stdClass[] The viewer's personal records, best scope first. */
    private array $records;

    /**
     * Constructor.
     *
     * @param stdClass $instance the activity instance
     * @param array $topics topic id to topic name, ordered by name
     * @param bool $hasbank whether a usable topic bank was resolved
     * @param string $formhtml the rendered picker form
     * @param string[] $warnings warnings to surface
     * @param stdClass[] $records the viewer's personal records
     */
    public function __construct(
        stdClass $instance,
        array $topics,
        bool $hasbank,
        string $formhtml = '',
        array $warnings = [],
        array $records = []
    ) {
        $this->instance = $instance;
        $this->topics = $topics;
        $this->hasbank = $hasbank;
        $this->formhtml = $formhtml;
        $this->warnings = $warnings;
        $this->records = $records;
    }

    /**
     * Describe a scope the way the picker describes it.
     *
     * Uses the same mode labels the learner chose from and the topic names as
     * stored, never a raw code or a category id.
     *
     * @param stdClass $record the record
     * @return string the scope description
     */
    protected function scope_label(stdClass $record): string {
        $mode = modes::label($record->scopetype);

        if ($record->topicnames === [] || $record->scopetype === modes::ALL) {
            return $mode;
        }

        return $mode . ': ' . implode(', ', $record->topicnames);
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output the renderer
     * @return stdClass the context for mod_onelife/picker
     */
    public function export_for_template(renderer_base $output): stdClass {
        // A bank that resolved but holds no topics is as unplayable as no bank at all,
        // so both produce the explanatory notice rather than an empty form.
        $playable = $this->hasbank && $this->topics !== [];

        $context = new stdClass();
        $context->name = format_string($this->instance->name);
        $context->hasbank = $this->hasbank;
        $context->hasnotice = !$playable;
        $context->notice = $playable ? '' : get_string('topicbanknone', 'mod_onelife');
        $context->formhtml = $playable ? $this->formhtml : '';

        // The mode and topic controls are rendered by the picker form, which formslib
        // builds with real labels and fieldsets. They are deliberately not exported
        // here: duplicating them in the template would mean two sources of truth for
        // what a learner may choose, and only one of them would be the one that posts.
        $context->topiccount = count($this->topics);

        $context->warnings = [];
        foreach ($this->warnings as $warning) {
            $context->warnings[] = ['message' => $warning];
        }
        $context->haswarnings = $this->warnings !== [];

        $context->records = [];
        foreach ($this->records as $record) {
            $context->records[] = [
                'scope' => $this->scope_label($record),
                'beststreak' => (int) $record->beststreak,
                'laststreak' => (int) $record->laststreak,
                'lasttime' => userdate((int) $record->lasttime, get_string('strftimedatefullshort', 'langconfig')),
                'totalruns' => (int) $record->totalruns,
            ];
        }
        // No records is a normal state for a new activity, not an empty table.
        $context->hasrecords = $context->records !== [];

        return $context;
    }
}
