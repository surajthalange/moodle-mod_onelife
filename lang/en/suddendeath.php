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
 * English strings for mod_suddendeath.
 *
 * @package    mod_suddendeath
 * @copyright  2026 Suraj Thalange
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowedmodes'] = 'Allowed scope modes';
$string['allowedmodes_help'] = 'Which ways of choosing what to practise the learner may use.

* **One topic** - the learner picks a single topic.
* **Selected topics** - the learner picks any combination of topics.
* **All topics** - the learner plays the whole bank.

At least one must be permitted.';
$string['completionstreak'] = 'Learner must reach a streak of';
$string['completionstreak_help'] = 'When set, the activity is marked complete once the learner reaches this streak in a single run. Leave it blank, or set it to 0, to disable the rule.';
$string['completionstreakgroup'] = 'Reach a streak';
$string['errcompletionstreak'] = 'Enter a whole number of 0 or more, or leave this blank to disable the rule.';
$string['errnomodes'] = 'Permit at least one scope mode.';
$string['errtargetstreak'] = 'Enter a whole number of 1 or more.';
$string['errtopicbank'] = 'That topic bank is not available to this course. Choose another, or use auto-detect.';
$string['explanationconvention'] = 'Explanations in question feedback';
$string['explanationconvention_help'] = 'After each answer, Sudden Death shows the learner an explanation taken from the question\'s general feedback.

Where the general feedback contains a horizontal rule, only the part **after the first rule** is shown as the explanation. Anything before it is treated as a lead-in and is not displayed.

Where the general feedback contains no horizontal rule, all of it is shown.

This lets you write a short congratulatory line for the question bank and a longer teaching explanation for Sudden Death, in one field:

> Correct!
> ---
> Photosynthesis converts light energy into chemical energy stored as glucose.

Be aware of this if you already use horizontal rules for visual separation: the first one will split your feedback, and everything above it will be hidden from the learner.';
$string['mode_all'] = 'All topics';
$string['mode_multi'] = 'Selected topics';
$string['mode_single'] = 'One topic';
$string['modulename'] = 'Sudden Death';
$string['modulename_help'] = 'Sudden Death is a self-directed revision activity built from the course question bank.

The learner chooses which topics to practise and answers multiple-choice questions one at a time. A single wrong answer ends the run, and the score is the streak reached. Personal bests are tracked per scope, so there is always something to beat.

The activity is deliberately ungraded, making it low-stakes practice rather than assessment.';
$string['modulenameplural'] = 'Sudden Death activities';
$string['noinstances'] = 'There are no Sudden Death activities in this course.';
$string['pluginadministration'] = 'Sudden Death administration';
$string['pluginname'] = 'Sudden Death';
$string['scaffoldnotice'] = 'This activity is not playable yet. The scope picker and the run loop are still to come.';
$string['suddendeath:addinstance'] = 'Add a new Sudden Death activity';
$string['suddendeath:play'] = 'Play a Sudden Death run';
$string['suddendeath:view'] = 'View a Sudden Death activity';
$string['suddendeathname'] = 'Activity name';
$string['suddendeathname_help'] = 'The name shown to learners on the course page.';
$string['targetstreak'] = 'Target streak';
$string['targetstreak_help'] = 'The streak that counts as 100% in the run summary.

It does not cap the run. A learner may keep going past it, and the streak itself is reported uncapped.';
$string['topicbank'] = 'Topic bank';
$string['topicbank_help'] = 'The question category whose child categories become the topics a learner can choose from.

Leave this on **Auto-detect** to let the activity find the bank itself. It searches the question bank contexts available to this course, nearest first, and skips the "Default for..." category Moodle creates automatically.

Choose a category explicitly only if auto-detection picks the wrong one.';
$string['topicbankautodetect'] = 'Auto-detect';
$string['topicbanknone'] = 'No usable question bank was found for this course. Add categories containing questions to the course question bank, then edit this activity again.';
$string['warningtopicbankunusable'] = 'The configured topic bank category is no longer usable, so topics have been detected automatically instead. Check the activity settings.';
