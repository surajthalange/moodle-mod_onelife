# Moodle Marketplace listing copy

Draft. Not published. Kept here so the wording is version controlled and reviewable
alongside the code it describes, rather than living only in the Marketplace form.

Field limits come from the Moodle Marketplace provider documentation, *Set up and
publish your plugin page*: the short description allows 270 characters but plugin
cards truncate at 119, and the description allows 7,000.

---

## Short description

Character budget: 270 maximum. The **first 119 characters must stand alone**, because
that is all a plugin card shows.

### First 119 characters, as a standalone sentence

> Learners pick their own topics from the course question bank and revise until one
> wrong answer ends the run.

### Full short description

> Learners pick their own topics from the course question bank and revise until one
> wrong answer ends the run. Personal bests are tracked per scope, so there is always
> a target to beat. Deliberately ungraded, so it stays practice.

---

## Description

### The problem it solves

Most Moodle revision is teacher-driven: the teacher builds a quiz and the learner
attempts what they are given. That works for assessment. It works badly for a learner
who wants to drill the one topic they know they are weak on, today, without asking.

One Life hands that learner the controls, using the question bank the course already
has.

### How a run works

The learner picks a scope: one topic, several topics, or everything in the bank. Then
they answer single-answer multiple-choice questions one at a time. A correct answer
extends the streak. **One wrong answer ends the run.** The score is the streak reached.

Because personal bests are recorded per scope, the same activity keeps giving the
learner a target: beat 9 on Cell structure, beat 14 on everything.

### How it differs from mod_game

`mod_game` is a teacher-configured game activity: the teacher chooses the source and
the learner plays what they are given. One Life is a learner-driven revision tool: the
learner chooses their own scope at play time, and the plugin tracks their personal
bests across scopes over time.

`mod_game` (GPLv3, by Vasilis Daloukas) is long-established prior art in this space and
is credited as such. One Life is a fresh implementation, not derived from its code.

### Setting it up as a teacher

1. Add a One Life activity to your course.
2. Leave the topic bank on auto-detect, or choose one. The topic bank is a question
   category whose child categories become the topics a learner can pick. Auto-detect
   finds the course's own bank, including a bank shared at the course category level.
3. Choose which scope modes learners may use, and the target streak that counts as
   100% in the run summary.
4. Optionally require a streak for activity completion.

There is nothing to configure per question, and no grade setup, because the activity
is deliberately ungraded.

### Explanations

After each answer the learner sees an explanation taken from the question's general
feedback. If the general feedback contains a horizontal rule, only the part after the
first rule is shown, so you can keep a short "Correct!" line for the question bank and
a longer teaching explanation for One Life in the same field.

### Deliberately ungraded

One Life does not write to the gradebook. That is a decision, not an omission: staying
out of the gradebook is what makes learners willing to fail at it.

### Accessibility

- No JavaScript is required for correctness. Answers are real submit buttons and scope
  choices are real radio inputs, so keyboard and screen reader behaviour is the
  browser's own. Topic cards are styled `<label>` elements wrapping native inputs;
  nothing is a `<div>` pretending to be a control.
- Fieldsets, legends, accessible names and focus outlines are all intact.
- Selected state is signalled by shape, weight and a tick as well as colour, so it
  survives colour vision deficiency and forced-colours mode.
- Colours come from the theme's own custom properties, so the activity inherits your
  theme. Layout is responsive down to phone widths.

### Privacy

One Life stores the learner's id, the timings of each run, the scope played, the
streak reached, and which questions were answered and whether each was correct.

The Privacy API is implemented in full, including the metadata provider, export,
delete for a user, delete for a context, and delete for an approved user list. Course
reset removes runs and answers.

### Supported versions

Moodle 4.5 LTS, 5.0, 5.1 and 5.2. Tested automatically on every supported branch
against both MariaDB and PostgreSQL, with unit tests and browser acceptance tests.

### Backup and restore: one limitation worth knowing

Runs and answers are backed up and restored under the standard **Include user data**
setting, and course-level backups carry everything correctly, including which question
each recorded answer was about.

**Activity-level backups cannot carry that last part.** A backup of the activity on its
own does not include the question bank, so there is nothing to match the stored answers
to. The activity, runs, streaks, scopes and timings all restore correctly, and so do
personal records and statistics. What is lost is which specific question each answer
referred to; those references are cleared rather than left pointing at whatever
question happens to occupy that id on the target site. An in-progress run is closed on
restore.

If you need the question history, back up at course level.

### Source, licence and support

- Source code: https://github.com/surajthalange/moodle-mod_onelife
- Issue tracker: https://github.com/surajthalange/moodle-mod_onelife/issues
- Licence: GNU GPL v3 or later
