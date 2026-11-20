# Training cases (sub-project 2 of 3) - design

Date: 2026-11-20
Status: draft for review

## Background and scope

The overall goal is AR training: virtual incidents that volunteers find and handle during a drill, without a real mission.
It is split into three sub-projects, each with its own spec, plan and implementation:

| # | Sub-project | Status |
|---|---|---|
| 1 | Training missions: the `is_training` flag, isolation from real data, the training record, the participation certificate | released as v3.504.0 / v3.504.1 |
| 2 | **Training cases** (this document): a virtual patient placed at a GPS spot, with decisions the volunteer makes and immediate feedback | this spec |
| 3 | AR for training cases: a patient icon and polish in the AR view (the order's beacon already appears in AR) | later |

A **training case** is a short case study at a place: "you found a 40-year-old unresponsive but breathing, pulse 140, very low blood pressure: what do you do?"
The volunteer answers a sequence of multiple-choice decisions (call an ambulance, ask another team for a stretcher, move him, ...) and sees feedback after each choice.

Out of scope here (deliberately): patients that deteriorate over time, choices that send real orders to other teams, a per-volunteer random draw, team runs, an exam mode, certificate changes, points or badges, AR changes beyond the existing order beacon.
The data shape leaves room for the first three (see "Later").

## Decisions taken

1. **What it is.** A case with decisions (not only a triage category). Triage can appear inside a case as one decision.
2. **Answers are exercise-only for now.** A choice gives feedback and a score; it sends nothing to anyone. Attaching a real order to a choice (for example "ask Team Beta for a stretcher") is a later addition; the choice structure must make that easy.
3. **Who answers.** Each volunteer, on their own phone, has their own run and their own training record. No team runs.
4. **Feedback.** Immediately after each choice (teaching style), then the case continues. No going back to change an answer.
5. **How cases are written.** A case editor (a plain form) plus a few starter cases, stored as data in a library, with export and import so the library can be enriched later.
6. **Random cases.** The commander may place a specific case or "random from the library" (optionally filtered). The draw happens once, when the case is staged, so everyone at that spot gets the same case. A per-volunteer draw is a later setting.
7. **How it reaches volunteers.** A new kind of scenario item (`case`), staged in a scenario phase and sent like an order to a team, a slot or everyone. Playing it creates an ordinary point order at the spot, so navigation, directions, the AR beacon and arrival detection are reused. Arrival opens the case.
8. **Architecture.** Cases are documents in a library table; staging takes a **snapshot** of the document, so later library edits never change a prepared or running drill. Runs and answers are small relational tables.

## 1. What gets stored

**`training_cases` (the library).** One row per case: id, a stable `slug`, title, type tag, difficulty (1 to 3), language (`el` or `en`), estimated minutes, `document` (JSON), `format_version`, `is_starter`, `reviewed` (a flag, see "Medical content"), `reviewed_by`, `reviewed_at`, `archived_at`, `created_by`, timestamps. Unique on `slug`.

**The case document** (JSON, plain text only, `format_version` 1):
- `title`, `story`, `closing` (lessons shown at the end);
- `findings`: a list of `{label, value, unit}` (pulse, pressure, breathing, consciousness, ...);
- `start`: the id of the first step;
- `steps`: a map of step id to `{prompt, hint, choices}`, where each choice is `{id, label, rating, feedback, next}`, `rating` is `best`, `acceptable` or `harmful`, and `next` is a step id or `null` for the end.
  A step has 2 to 5 choices and at least one `best`. The optional `effect` field on a choice is reserved for a later "send a real order" action and is ignored for now.

**Scenario item kind `case`.** Added to the `kind` ENUM of `mission_scenario_items` (a migration). Its payload holds: the case snapshot (the whole document plus `case_id` and `format_version`), the GPS spot, `radius_m` (default 25), the audience (`team`, `slot` or `all`, with the team or slot), an optional briefing text, and how it was drawn (`draw_mode`: `chosen` or `random`, and the pool filter) so a per-volunteer draw can be added later.

**`mission_case_placements`.** Created when the item is played: `mission_id`, `scenario_item_id`, `dispatch_id` (the ordinary point order created at the spot), `snapshot` (JSON), `lat`, `lng`, `radius_m`, audience fields, `created_at`. This is the single place that links the order to the case.

**`mission_case_runs`.** One per volunteer and placement: `placement_id`, `user_id`, `started_at`, `finished_at`, `score_points`, `score_max`, `current_step`. Unique on (`placement_id`, `user_id`).

**`mission_case_answers`.** `run_id`, `step_id`, `choice_id`, `rating`, `answered_at`, `ms_taken`. Unique on (`run_id`, `step_id`), so a repeated answer is ignored.

**Isolation.** Everything is keyed by `mission_id` (or by a placement), and cases work only in training missions (`missions.is_training = 1`), checked by the server. Scores never feed operational statistics, points or achievements.

## 2. What the volunteer sees

1. The order arrives like any point order. Its title is neutral and the commander can add a short briefing line. Ελήφθη and Ξεκινάω work as usual; navigation, directions and the AR beacon are the existing ones.
2. Arrival opens the case: the existing arrival detection (GPS inside the radius plus the dwell check, or the manual «Έφτασα») records the arrival as usual and, for a volunteer in the audience, also opens the case. Each volunteer gets their own run, even if several arrive together. A volunteer outside the audience gets nothing.
3. The case screen is full screen, with large buttons: a fixed banner "Άσκηση, δεν είναι πραγματικό περιστατικό", a "Σταμάτα" button, the SOS button still reachable, the story, the findings as a compact table, then the first step.
4. After each choice: a badge (best, acceptable, harmful) and the feedback text; «Συνέχεια» goes to the next step (the case may branch) or to the end. An answer cannot be changed.
5. The end: the score ("7 / 8 (88%)" with a label: Άριστα from 80%, Καλά from 50%, Χρειάζεται εξάσκηση below), the volunteer's path next to the best path, the case's closing lessons. The run is saved and the order is completed for that volunteer. A finished case can be re-read, not replayed.
6. The run is saved after every answer; reopening resumes at the current step.
7. Offline: the phone already holds the snapshot with the order, so the case runs without a network. Answers queue like other field actions and sync later.
8. The ratings are in the document on the phone, so a technical user could read them in advance. With immediate teaching feedback this is accepted. A later exam mode would have the server hold them back.

## 3. The library, the editor, random draw, files

**Access.** System admins only, the same people who can use scenarios today.

**Library page.** A list with tags, language, a "starter" mark and a "reviewed" mark; actions: create, edit, duplicate, export, import, archive. Archiving never affects a drill that already holds a snapshot.

**Editor.** One plain form (no drag and drop): details (title, type, difficulty, language, estimated minutes); the story; the findings as rows; ordered step cards, each with a prompt, an optional hint and 2 to 5 choices (label, rating, feedback, next step or end); closing lessons; and a «Δοκιμή» button that plays the case without saving a run.

**Checks on save.** Every step has at least 2 choices and at least one `best`; every `next` points to an existing step or the end; there are no loops and no unreachable steps (so a case always finishes); text fields are plain text with length limits. Errors are shown in plain language.

**Starter cases.** Three ship with the app as files and are loaded into the library idempotently by `slug`. They are not editable in place, only duplicable, so later releases can improve them. They are medical guidance drafted without clinical review: each case has a `reviewed` flag that an admin sets after a medically qualified person has checked it, and staging an unreviewed case shows a warning.

**Staging.** A «Περιστατικό» button in the scenario card lets the commander choose a specific case or «Τυχαίο», optionally filtered by type, difficulty and language. A random draw happens at staging time; the commander sees which case came up and can draw again. It prefers cases not already staged in this scenario and skips unreviewed ones unless allowed. The commander then sets the spot on the map, the radius, the audience, an optional briefing and the phase, like any other staged order.

**Files.** Export writes one JSON file with its `format_version` (one case, or a pack of several). Import validates it with the same rules, shows a preview before saving, ignores anything unknown, enforces a size limit, and renames a case on a `slug` clash.

## 4. The Action Room view, scoring, the training record

**Scoring.** best = 2, acceptable = 1, harmful = 0. A run's score is the points earned over the points possible for the steps that volunteer answered. There are no points or badges for it.

**Command view.** A «Περιστατικά» card (command staff only): one row per placed case with each volunteer's state (not started, in progress at step N, finished with score and time); a click shows that run (each step, the choice, its rating, the time); a per-step breakdown of how many chose each option, for the group debrief. The card refreshes itself while open through its own light request, not the main 5-second poll.

**Volunteers** see only their own results; there is no ranking.

**Training record.** Each drill in «Εκπαιδευτικές ασκήσεις» (profile, admin volunteer view) gains a line such as "Περιστατικά: 2, μέσος όρος 85%". The certificate is unchanged.

## 5. Errors, testing, scope

**Failure modes.**
- A broken case document: staging refuses it; if one still fails to open on a phone, the volunteer sees "Το περιστατικό δεν είναι διαθέσιμο" and command is told; the order and the arrival are still recorded.
- Staging in a non-training mission: refused by the server; the scenario card does not offer the button.
- Double taps and offline replays: one answer per step per run (a database constraint), a repeated answer is ignored.
- The server checks that the chosen option exists in the snapshot and computes the rating and score itself; it never trusts the phone's rating.
- Imported files: size limit, version check, length limits; all case text is plain text and escaped on output.
- The migration follows the same pattern as the training missions one: an exception propagates and the schema version is not recorded.

**Tests** (PHPUnit and `node --test tests/js`): document validation (loops, unreachable steps, rating rules); scoring; random-draw rules; snapshot immutability; export/import round trip; server-side recomputation and idempotent answers; staging and playing a case creates the order and the placement; training-only gate; isolation (scores never reach operational numbers, and the guard test is extended to the new tables); JS tests for the volunteer's step screen; source tests for the pages; and a browser checklist for what the automated run cannot do.

**Later (kept possible by the data shape).** A real order attached to a choice (the reserved `effect` field); a per-volunteer random draw (`draw_mode`); an exam mode; team runs; time-based deterioration; AR polish (sub-project 3).

**Delivery.** A migration (the new scenario kind and the four tables), the case library and editor pages, the scenario staging and play, the volunteer's case screen and its offline handling, the Action Room card, the training-record line, tests, and three starter cases. Expected release: v3.505.0.

## Points to verify when writing the plan

These depend on code that was only skimmed while designing and must be read first:
- how `SCENARIO_TEAM_KINDS`, the staging validators and `playScenarioPhase` treat each kind, and what a new kind must implement;
- the arrival hook (`checkArrivalPrompts`, `recordAutoArrival`, the manual «Έφτασα») and where the case can be opened from it;
- how the phone receives an order and how the offline queue handles new actions (so a case snapshot and answers travel offline);
- the field UI file that renders «Οι Εντολές μου», for the case screen;
- how an order is completed (per team or per person) and how Ολοκληρώθηκε can be driven by the end of a case for one volunteer without completing it for the rest of the audience;
- the permission check scenarios use, to reuse it for the library.
