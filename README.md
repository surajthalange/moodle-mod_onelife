# Sudden Death

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

## Development

This repository is the source of truth for the plugin. The Moodle installation is disposable
infrastructure; the plugin is linked into it with a Windows directory junction:

    New-Item -ItemType Junction `
      -Path   'C:\laragon\www\personal\moodle\public\mod\suddendeath' `
      -Target 'C:\laragon\www\personal\plugins\moodle-mod_suddendeath'

See `C:\laragon\www\personal\OPERATIONS.md` for machine-specific commands.
