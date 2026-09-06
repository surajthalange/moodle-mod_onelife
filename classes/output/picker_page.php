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
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_suddendeath\output;

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
 * @package    mod_suddendeath
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

    /**
     * Constructor.
     *
     * @param stdClass $instance the activity instance
     * @param array $topics topic id to topic name, ordered by name
     * @param bool $hasbank whether a usable topic bank was resolved
     * @param string $formhtml the rendered picker form
     * @param string[] $warnings warnings to surface
     */
    public function __construct(
        stdClass $instance,
        array $topics,
        bool $hasbank,
        string $formhtml = '',
        array $warnings = []
    ) {
        $this->instance = $instance;
        $this->topics = $topics;
        $this->hasbank = $hasbank;
        $this->formhtml = $formhtml;
        $this->warnings = $warnings;
    }

    /**
     * Build the template context.
     *
     * @param renderer_base $output the renderer
     * @return stdClass the context for mod_suddendeath/picker
     */
    public function export_for_template(renderer_base $output): stdClass {
        // A bank that resolved but holds no topics is as unplayable as no bank at all,
        // so both produce the explanatory notice rather than an empty form.
        $playable = $this->hasbank && $this->topics !== [];

        $context = new stdClass();
        $context->name = format_string($this->instance->name);
        $context->hasbank = $this->hasbank;
        $context->hasnotice = !$playable;
        $context->notice = $playable ? '' : get_string('topicbanknone', 'mod_suddendeath');
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

        return $context;
    }
}
