# Changes

## 1.0.0 (2026-09-06)

First release. Published in the [Moodle plugins directory](https://marketplace.moodle.com/plugins/4140).

- A self-directed revision activity built from the course's own question bank. The learner
  chooses which topics to practise and plays a run of single-answer multiple-choice
  questions. One wrong answer ends the run; the score is the streak.
- Personal bests tracked per scope, so a learner practising one topic is always measured
  against their own previous attempt at that topic rather than against the whole bank.
- Explanations taken from each question's general feedback. Where the feedback contains a
  horizontal rule, only the part after the first rule is shown, so one field can serve both
  the question bank and this activity.
- Deliberately ungraded. The activity is low-stakes practice and stays out of the gradebook
  by design, not by omission.
- Completion by number of runs or by reaching a streak.
- Backup and restore, events, and a privacy provider.
- Supports Moodle 4.5 LTS through 5.2, on MySQL/MariaDB and PostgreSQL, tested on every
  supported branch.
