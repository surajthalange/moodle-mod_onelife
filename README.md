# Sudden Death

[![Moodle Plugin CI](https://github.com/surajthalange/moodle-mod_suddendeath/actions/workflows/ci.yml/badge.svg)](https://github.com/surajthalange/moodle-mod_suddendeath/actions/workflows/ci.yml)

A Moodle activity module giving learners a self-directed, streak-based revision tool built
from a course's question bank.

- **Component:** `mod_suddendeath`
- **Moodle support:** 4.5 LTS floor; targets 4.5, 5.0, 5.1, 5.2
- **Licence:** GPLv3 or later
- **Status:** alpha, scaffold only — not yet playable

## What it does

A learner opens the activity, chooses which topics to practise, and plays a run of
single-answer multiple-choice questions. One wrong answer ends the run. The score is the
streak. Personal bests are tracked per scope so there is always something to beat.

The activity is **deliberately ungraded**. That is a design decision, not an omission: the
plugin is low-stakes practice and staying out of the gradebook is part of the positioning.

## Writing explanations in question feedback

After each answer the learner is shown an explanation, taken from the question's **general
feedback**.

If the general feedback contains a horizontal rule, only the part *after the first rule* is
shown. If it contains no rule, all of it is shown. That lets one field serve both the question
bank and this activity:

    Correct!
    ---
    Photosynthesis converts light energy into chemical energy stored as glucose.

If you already use horizontal rules for visual separation, note that the first one will split
the feedback and everything above it will be hidden from the learner.

## Positioning against mod_game

`mod_game` is a teacher-configured game activity: the teacher chooses the source and the
learner plays what they are given. `mod_suddendeath` is a learner-driven revision tool: the
learner chooses their own scope at play time, and the plugin tracks their personal bests
across scopes over time.

## Prior art and provenance

The Millionaire-style quiz format is a well-established educational game pattern, also
implemented by [`mod_game`](https://moodle.org/plugins/mod_game) (GPLv3) by
**Vasilis Daloukas**, credited here as prior art in the same space.

This plugin is a fresh implementation. No source is copied from `mod_game` or from any other
existing plugin.

## Installation

Clone into your Moodle tree, then visit **Site administration → Notifications** to complete
the install.

Moodle 5.1 and later moved the codebase under `public/` (MDL-83424), so the path differs by
version:

    # Moodle 5.1+
    git clone https://github.com/surajthalange/moodle-mod_suddendeath.git \
      <moodle>/public/mod/suddendeath

    # Moodle 4.5 - 5.0
    git clone https://github.com/surajthalange/moodle-mod_suddendeath.git \
      <moodle>/mod/suddendeath

The directory must be named `suddendeath`, not the repository name.

## Development

The working copy lives inside the Moodle tree, so treat this GitHub remote as the backup of
record: a Moodle reinstall removes the local directory. Push before wiping a Moodle install.

Checks, from a `moodle-plugin-ci` installation:

    php ci/bin/moodle-plugin-ci phplint <path-to-plugin>
    php ci/bin/moodle-plugin-ci phpcs   <path-to-plugin>
    php ci/bin/moodle-plugin-ci validate --moodle=<moodle> <path-to-plugin>

Unit tests, from the Moodle root:

    php public/admin/tool/phpunit/cli/init.php
    php vendor/phpunit/phpunit/phpunit public/mod/suddendeath/tests/engine_test.php
