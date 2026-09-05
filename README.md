# mod_suddendeath

A Moodle activity module plugin.

- **Component:** `mod_suddendeath`
- **Moodle:** 5.2 (`MOODLE_502_STABLE`)

## Development

This repository is the source of truth for the plugin. The Moodle installation is
disposable infrastructure; the plugin is linked into it with a Windows directory
junction so the two stay in sync:

    mklink /J "C:\laragon\www\personal\moodle\public\mod\suddendeath" ^
              "C:\laragon\www\personal\plugins\moodle-mod_suddendeath"

See `C:\laragon\www\personal\OPERATIONS.md` for machine-specific commands.

## Status

Stub. No functionality yet.
