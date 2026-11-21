# Training cases (sub-project 2 of 3) - design

Date: 2026-11-20
Status: approved, plan written (implemented in v3.505.0; this text matches what was built)

## Background and scope

The overall goal is AR training: virtual incidents that volunteers find and handle during a drill, without a real mission.
It is split into three sub-projects, each with its own spec, plan and implementation:

| # | Sub-project | Status |
|---|---|---|
| 1 | Training missions: the `is_training` flag, isolation from real data, the training record, the participation certificate | released as v3.504.0 / v3.504.1 |
| 2 | **Training cases** (this document): a virtual patient placed at a GPS spot, with decisions the volunteer makes and immediate feedback | this spec, v3.505.0 |
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
7. **How it reaches volunteers.** A `dispatch` scenario item whose payload carries a `case` block (no new kind, no ENUM migration), staged in a scenario phase and sent like any point order to a team, a slot or everyone. Playing it sends the ordinary point order and records a placement linking the order to the case, so navigation, directions, the AR beacon, per-team release, withdrawal and arrival detection are reused unchanged. Once the team's arrival is recorded, the case is available to the audience.
8. **Architecture.** Cases are documents in a library table; staging takes a **snapshot** of the document, so later library edits never change a prepared or running drill. Runs and answers are small relational tables.

## 1. What gets stored

**`training_cases` (the library).** One row per case: id, a stable `slug`, title, type tag, difficulty (1 to 3), language (`el` or `en`), estimated minutes, `document` (JSON), `format_version`, `is_starter`, `reviewed` (a flag, see "Medical content"), `reviewed_by`, `reviewed_at`, `archived_at`, `created_by`, timestamps. Unique on `slug`.

**The case document** (JSON, plain text only, `format_version` 1, at most 24000 bytes, at most 12 steps and 12 findings):
- `title`, `story`, `closing` (lessons shown at the end);
- `findings`: a list of `{label, value, unit}` (pulse, pressure, breathing, consciousness, ...);
- `start`: the id of the first step;
- `steps`: a map of step id to `{prompt, hint, choices}`, where each choice is `{id, label, rating, feedback, next}`, `rating` is `best`, `acceptable` or `harmful`, and `next` is a step id or `null` for the end. Step and choice ids match `^[a-z][a-z0-9_]{0,23}$`.
  A step has 2 to 5 choices and at least one `best`. The optional `effect` field on a choice is reserved for a later "send a real order" action and is ignored for now.

**The scenario item.** A `dispatch` item (the same kind as any staged point order); no change to the `kind` ENUM. Its payload is the ordinary point order plus a `case` block: the snapshot (the whole document plus `case_id`, `format_version`, and the `reviewed` / `reviewed_at` state recorded at staging) and how it was drawn (`draw_mode`: `chosen` or `random`, and the pool filter) so a per-volunteer draw can be added later. The audience (`team`, `slot` or `all`), the spot and the briefing are the order's own. There is no per-case radius: arrival detection uses the global arrival radius.
Because the payload now carries a whole document, `mission_scenario_items.payload` is widened to MEDIUMTEXT (migration 225, with its column comment kept).

**`mission_case_placements`.** Created when the item is played: `mission_id`, `scenario_item_id`, `dispatch_id` (the ordinary point order; set to NULL if the order is deleted, the placement and its runs stay), `title`, `snapshot` (MEDIUMTEXT JSON), audience fields, `created_at`. This is the single place that links the order to the case. A placement that fails to record after the order was sent is logged (`error_log`); the order and the arrival still work, and the volunteer sees «Το περιστατικό δεν είναι διαθέσιμο».

**`mission_case_runs`.** One per volunteer and placement: `placement_id`, `user_id`, `started_at`, `finished_at`, `score_points`, `score_max`, `current_step`. Unique on (`placement_id`, `user_id`).

**`mission_case_answers`.** `run_id`, `step_id`, `choice_id`, `rating`, `answered_at`, `ms_taken`. Unique on (`run_id`, `step_id`), so a repeated answer is ignored.

**The document is fetched, not polled.** The phone's poll carries only a small `case` card on each dispatch row (title, state, availability). The full document is fetched once with `training-case.php?action=load`, which needs no arrival so the phone can cache it ahead of time, and is kept in `localStorage`.

**Isolation.** Everything is keyed by `mission_id` (or by a placement), and cases work only in training missions (`missions.is_training = 1`), checked by the server at staging, at play and on every endpoint. Scores never feed operational statistics, points or achievements; the isolation guard test scans the new code and tables.

## 2. What the volunteer sees

1. The order arrives like any point order. Its title is neutral and the commander can add a short briefing line. Ελήφθη and Ξεκινάω work as usual; navigation, directions and the AR beacon are the existing ones.
2. Arrival is a team fact: the existing arrival detection (GPS inside the global arrival radius plus the dwell check, or the manual «Έφτασα») records one arrival for the team ("the first member moves the whole team on"). From then on the case is available to every volunteer in the audience (the dispatch row already exposes the arrival as `my_ack`; the server checks the same fact before a run starts or an answer is taken), and the arriving volunteer's teammates get a notification when the arrival is recorded. Each volunteer gets their own run, even if several open it together. A volunteer outside the audience gets nothing.
3. The case screen is a full-screen overlay with large buttons: a fixed banner "Άσκηση, δεν είναι πραγματικό περιστατικό", a "Σταμάτα" button, an SOS button kept reachable (held for one second, like the page's own), the story, the findings as a compact table, then the first step.
4. After each choice: a badge (best, acceptable, harmful) and the feedback text; «Συνέχεια» goes to the next step (the case may branch) or to the end. An answer cannot be changed.
5. The end: the score ("7 / 8 (88%)" with a label: Άριστα from 80%, Καλά from 50%, Χρειάζεται εξάσκηση below), the volunteer's path next to the best path, the case's closing lessons. The run is saved. Finishing does not complete the order: the order's «Ολοκληρώθηκε» stays the team's step, pressed as for any point order. A finished case can be re-read, not replayed.
6. The run is saved after every answer; reopening resumes at the current step.
7. Offline: the snapshot is cached on the phone, so the case runs without a network. Answers queue locally and sync later. The cache is per viewer (`wr_case_doc_<user>_<placement>` and `wr_case_answers_<user>_<placement>`), so a shared phone does not leak one volunteer's answers to the next.
8. The ratings are in the document on the phone, so a technical user could read them in advance. With immediate teaching feedback this is accepted. A later exam mode would have the server hold them back.

## 3. The library, the editor, random draw, files

**Access.** System admins only, the same people who can use scenarios.

**Library page** (`training-cases.php`). A list with tags, language, a "starter" mark and a "reviewed" mark; actions: create, edit, duplicate, export, import, archive, and mark reviewed (a confirmation box in a modal). Archiving never affects a drill that already holds a snapshot.

**Editor** (`training-case-edit.php`). One plain form (no drag and drop): details (title, type, difficulty, language, estimated minutes); the story; the findings as rows; ordered step cards, each with a prompt, an optional hint and 2 to 5 choices (label, rating, feedback, next step or end); closing lessons; and a «Δοκιμή» button that plays the case without saving a run. A post is capped at 100000 bytes (`TRAINING_CASE_MAX_EDITOR_BYTES`); a refused save re-draws the form with what was typed.

**Checks on save.** Every step has at least 2 choices and at least one `best`; every `next` points to an existing step or the end; there are no loops and no unreachable steps (so a case always finishes); text fields are plain text with length limits. Errors are shown in plain language. Editing a case resets its `reviewed` flag.

**Starter cases.** Three ship with the app as files (`includes/training-cases/*.json`) and are loaded into the library idempotently by `slug` when the library page is opened. They are not editable in place, only duplicable, so later releases can improve them.

**Medical content.** The starters are first-aid guidance drafted without clinical review. Each case has a `reviewed` flag that an admin sets after a medically qualified person has checked it. Staging an unreviewed case shows a warning, and the plan card carries an «Ανέλεγκτο» badge. The badge follows the staged snapshot (`reviewed` / `reviewed_at` recorded at staging), not the live library row, so a later review does not silently change what a prepared drill holds. No volunteer should be given an unreviewed case.

**Staging.** A case picker in the scenario's dispatch window lets the commander choose a specific case or «Τυχαίο», optionally filtered by type, difficulty and language. A random draw happens at staging time; the commander sees which case came up and can draw again. It prefers cases not already staged in this scenario and skips unreviewed ones unless allowed. The picker is hidden for area orders and offered only in training missions. The commander then sets the spot on the map, the audience, an optional briefing and the phase, like any other staged point order.

**Files.** Export writes one JSON file with its `format_version` (one case, or a pack of several). Import validates it with the same rules, shows a preview before saving, ignores anything unknown, renames a case on a `slug` clash, and is limited to 200000 bytes and 50 cases (`TRAINING_CASE_MAX_IMPORT_BYTES`, `TRAINING_CASE_MAX_IMPORT_CASES`).

## 4. The Action Room view, scoring, the training record

**Scoring.** best = 2, acceptable = 1, harmful = 0. A run's score is the points earned over the points possible for the steps that volunteer answered. There are no points or badges for it.

**Command view.** A «Περιστατικά» card (command staff only): one row per placed case with each volunteer's state (not started, in progress at step N, finished with score and time); a click shows that run (each step, the choice, its rating, the time); a per-step breakdown of how many chose each option, for the group debrief. The card refreshes itself every 20 seconds while open through its own light request (`training-case.php?action=overview`), not the main 5-second poll.

**Volunteers** see only their own results; there is no ranking.

**Training record.** Each drill in «Εκπαιδευτικές ασκήσεις» (profile, admin volunteer view) gains a line such as "Περιστατικά: 2, βαθμολογία 85%", where the score is the sum of points over the sum of maximum points of the finished runs. The certificate is unchanged.

## 5. The endpoint, errors, testing

**Endpoint** (`training-case.php`, registered in `WAR_ROOM_ACTION_SCRIPTS`). `load` is GET and needs no arrival, so the phone can cache it. `start` and `answer` are POST only with a CSRF token. `overview` is GET for command staff. Every error carries a machine code beside the text: `refused` (not allowed, not a drill, not in the audience, no arrival yet) is the only one that makes the phone drop a queued answer; everything else (`mission_closed`, `not_found`, `bad_token`, `bad_request`, `server_error`) leaves it queued and retried later.

**Answers.** `trainingCaseAnswer` is atomic: it locks the run row first, decides the rating and score from the snapshot (never from the phone), writes the answer and advances the run in one transaction (one retry on a lock error), and is self-healing: a run that lags its stored answer is healed when the answer is replayed.

**Failure modes.**
- A broken case document: staging refuses it; if one still fails to open on a phone, the volunteer sees «Το περιστατικό δεν είναι διαθέσιμο»; the order and the arrival are still recorded and the failure is logged.
- Staging in a non-training mission: refused by the server; the scenario window does not offer the picker.
- Double taps and offline replays: one answer per step per run (a database constraint), a repeated answer is ignored.
- Imported files: size and count limit, version check, length limits; all case text is plain text and escaped on output.
- The migration follows the same pattern as the training missions one: literal SQL, an exception propagates and the schema version is not recorded.

**Tests** (PHPUnit and `node --test tests/js`): document validation, scoring, random-draw rules, snapshot immutability, export/import round trip, server-side recomputation, idempotent and concurrent answers, staging and playing a case creates the order and the placement, training-only gate, isolation (the guard test covers the new tables), JS tests for the player's logic, source tests for the pages. What the automated run cannot do is in `2026-11-20-training-cases-browser-checklist.md`.

**Later (kept possible by the data shape).** A real order attached to a choice (the reserved `effect` field); a per-volunteer random draw (`draw_mode`); an exam mode; team runs; time-based deterioration; a visible alert for command about an unavailable case; AR polish (sub-project 3).

## Verified while planning

The points that were unknown when this spec was drafted (how staged kinds are handled, the arrival hook, the offline queue, the field UI, how an order is completed, the permission check) were read in the code and settled in the plan, "Where this plan differs from the approved spec" (`docs/superpowers/plans/2026-11-20-training-cases.md`). The differences from the first draft are folded into this text: no new scenario kind, arrival as a team fact, finishing a case does not complete the order, no per-case radius, the document fetched once and cached, and an unavailable case logged rather than alerted.
