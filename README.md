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

## Backup and restore

Runs and answers are backed up and restored under the standard **Include user data** setting.
There is one limitation worth knowing before you rely on it.

**Course-level backups carry question history correctly.** The question bank travels with the
course, so on restore each recorded answer is remapped onto the question it was actually about.
Topic categories are remapped too. This is the normal case and it works.

**Activity-level backups cannot.** A backup of the Sudden Death activity on its own does not
include the question bank, so on restore there is no question to remap onto. Moodle's older
`annotate_ids('question', ...)` mechanism, which used to bridge this, has not been functional
since question versioning arrived: questions are pulled in through the question bank steps and
`add_question_references()` instead, and the annotation is a no-op. This plugin therefore does
not use it, rather than implying a guarantee that does not hold.

**What to expect if you do it anyway.** The activity restores, and so do the runs, the streaks,
the scope each run was played over, and the timings. Personal records and statistics stay
correct, because they are computed from streaks rather than from questions. What is lost is
which specific question each answer was about: those references are cleared rather than carried
across, because an untranslated id would point at whatever question happens to occupy it on the
target site, and silently misreporting which question a learner answered is worse than recording
that it is no longer known. Any run still in progress at backup time is closed on restore, since
its question cannot be served.

**If you need the question history, back up at course level.** Restoring an activity into a
course whose bank already holds the same questions does not help: the ids differ, and the plugin
will not guess.

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
