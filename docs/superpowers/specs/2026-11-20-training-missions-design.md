# Training missions (sub-project 1 of 3) - design

Date: 2026-11-20
Status: draft for review

## Background and scope

The goal of the whole effort is AR training: virtual victims and hazards that volunteers find through the
Action Room's AR camera view (`assets/js/war-room-ar.js`) so they can practise without a real mission.
It is split into three sub-projects, each with its own spec, plan and implementation:

| # | Sub-project | Depends on |
|---|---|---|
| 1 | **Training mission foundation** (this document): the `is_training` flag, isolation from real data, the volunteer's training record, the participation certificate | none |
| 2 | Virtual victims and hazards, staged in scenario phases, existing only in training missions | 1 |
| 3 | AR for virtual victims and hazards: finding and triaging them in the camera view, results and timing in the Action Room | 1, 2 |

Sub-project 1 is the safety base: nothing in 2 or 3 ships until real data is guaranteed to be isolated. It is
also useful on its own: a drill run with the existing scenario system rewards the volunteers who attend.

Out of scope here: virtual victims, hazards and AR (sub-projects 2 and 3); training badges or points;
converting an existing mission to or from training; bulk-importing past drills.

## Decisions taken

1. **Isolation level.** A training mission is fully isolated from operational statistics, reports, points,
   achievements and external outputs. Participation in it is recorded separately and rewarded with a
   record and a certificate (not points or badges).
2. **Flag lifecycle.** `is_training` is chosen when the mission is created and is locked afterwards.
   A wrong choice is fixed by deleting the draft or cancelling and recreating.
3. **Notifications.** Participant-facing messages of a training mission are prefixed "[ΑΣΚΗΣΗ]"
   (details in section 2).
4. **Mechanism.** The flag is copied onto each participation row by a database trigger, and operational
   queries filter on it, enforced by a guard test.

## 1. The flag and where it lives

- `missions.is_training TINYINT(1) NOT NULL DEFAULT 0`. A checkbox in `mission-form.php` on creation
  only. On edit it is shown read-only and the server ignores any posted value. Applies to both mission
  types (VOLUNTEER, MEDICAL).
- `participation_requests.is_training TINYINT(1) NOT NULL DEFAULT 0`, filled by a `BEFORE INSERT` trigger
  from the shift's mission:
  `SET NEW.is_training = COALESCE((SELECT m.is_training FROM shifts s JOIN missions m ON m.id = s.mission_id WHERE s.id = NEW.shift_id), 0)`.
  A single statement, so no `BEGIN/END` is needed (same style as the existing `trg_booking_insert`).
  Participation rows are created in 7+ places (`mission-view.php`, `shift-view.php` x3, `visitor-join.php`,
  `shift-swap-functions.php`, `install.php`); the trigger covers all of them and any future one.
- Because the mission flag is locked, the copied value cannot go stale.
- Delivery: one numbered migration in `includes/migrations.php` (with backfill; every existing mission is
  0 so nothing changes) and the same columns and trigger in `sql/schema.sql`.
  `tests/fixtures/schema-structure.sql` is not regenerated: it lags by design and
  `tests/migrate-fixture.php` brings it forward.

## 2. Keeping real numbers clean

Three tiers.

**Participation numbers (hours, attendance, points).** Every operational query that reads `actual_hours`,
`attended` or `points_awarded` adds `AND pr.is_training = 0`. Known readers: `includes/functions-core.php`
(hours rank), `dashboard.php`, `reports.php`, `profile.php`, `volunteers.php`, `volunteer-view.php`,
`volunteer-report.php`, `report-municipality.php`, `mission-stats.php`, `inactive-volunteers.php`,
`includes/export-functions.php`, and the inventory and print pages. The implementation plan re-greps
for the complete list.
- No points for a training row: `attendance.php` skips the `points_awarded` and `users.total_points`
  update when `is_training = 1`.
- `checkAndAwardAchievements()` counts only `is_training = 0` rows, so badges and streaks cannot be earned
  from drills.

**Mission-level aggregates.** Counts, totals and listings over missions filter `m.is_training = 0`: the
dashboard and report mission counts, municipality and official reports, statistics, and the Action Room
scorecard (`computeWarRoomTypeAggregates()`, shown on the "Action Room" tab of `reports.php` and in the
warroom CSV), whose shared `$where` carries `m.is_training = 0` so every pillar and the per-type mission
count leave drills out. The missions pages still show training missions (section 3).

**Outbound notifications.** Participant-facing notifications of a training mission carry the "[ΑΣΚΗΣΗ]"
prefix (`trainingTitlePrefix()`, built once as `$missionLabel` where a file sends several messages):
- the "new mission" notification (its audience is picked by the admin in the form, so it IS the
  drill's invitation and is not suppressed);
- shift reminders (`cron_shift_reminders.php`);
- participation approved/rejected, in both `participation-action.php` and `shift-view.php` (also the
  re-activation, the bulk activate/cancel and the "shift deleted" messages there);
- a volunteer added by an admin (`shift-view.php` add and mass add, `mission-view.php`), including the
  calendar title in the e-mail;
- mission cancelled or deleted, and the manual mission reminder (`mission-view.php`);
- shift swap (`includes/shift-swap-functions.php`, `shift-swap.php`).
For approved/rejected and the reminder the marker is in the message body; their in-app titles do not contain
the mission title. The automated `mission_needs_volunteers` broadcast (`cron_incomplete_missions.php`, which
mails and notifies every active volunteer about an open mission that is short of people) skips drills
entirely (`m.is_training = 0`): a drill is recruited for through its own "new mission" notification, never
by this cron. The calendar feeds are login-gated and show only the user's own shifts, so a drill shift
appears in the volunteer's own calendar and is not filtered from them. Action Room notifications (31 call sites in
`includes/functions-warroom.php`, no common choke point) are not prefixed in this sub-project; the Action
Room shows the "ΑΣΚΗΣΗ" badge instead (text via the `t()` key `hero.training_badge`, Greek and English).
Prefixing them is revisited with sub-projects 2 and 3.

**Guard test** (new PHP test). It fails if either:
- a file reading `actual_hours`, `attended` or `points_awarded` has no `is_training` reference and is not
  on a reviewed allow-list (each entry carries a stated reason); or
- a file on a short named list of reporting surfaces has no `is_training` reference.

Limitation: the scan works per file, so a file with the filter in one query and an unfiltered second query
would pass. Those cases are covered by the aggregate tests below.

## 3. The training record and the certificate

**Certificate.** `mission-certificates.php`, `mission-certificate-print.php` and `certificate-verify.php`
already issue bilingual participation certificates (certificate number, QR code, public verification,
CLOSED/COMPLETED gate, roster-based recipient picker) in `mission_certificates`. No new table. For
training missions:
- the document and the verify page read "Βεβαίωση συμμετοχής σε εκπαιδευτική άσκηση" (and the English
  equivalent) and show the hours attended;
- the issue page shows each recipient's recorded hours; recipients without `attended = 1` produce a
  clear warning. Pre-selecting the people with `attended = 1` already existed in
  `mission-certificates.php`; this sub-project only adds the exercise wording and the hours.

**The volunteer's training record.**
- A new "Εκπαιδευτικές ασκήσεις" section on `profile.php`: drills attended, total training hours, and a
  dated list (title, hours, link to the certificate if issued).
- The same section on `volunteer-view.php` for admins, and a separate training block in
  `volunteer-report.php`. Never added into operational totals. The volunteer export gets no change and no
  training columns in this sub-project (the earlier idea of filtering its participation count was a
  misreading: that subquery is the per-mission headcount of the MISSIONS csv, which still lists drills
  with their real headcount). The missions CSV honours the missions-list `training` filter (all /
  operational / training). Training columns in a volunteer export are a possible follow-up.
- Hours come from rows with `attended = 1` and `is_training = 1`, using the same shift-duration fallback
  the profile page already applies when `actual_hours` is empty.

**How a training mission looks.**
- An "ΑΣΚΗΣΗ" badge on `missions.php` (list and cards), `mission-view.php`, the Action Room header, and on
  `dashboard.php` wherever a drill title can render for a volunteer or in swap requests (my overdue
  missions, my upcoming shifts, available missions, the three shift-swap request lists). Admin dashboard
  widgets (recent missions, the overdue-missions alert and its bulk-complete, the pending-requests widget,
  statistics) exclude drills; drill participation requests remain listed and approvable on
  `participations.php`.
- A banner: "Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά".
- A missions-list filter: Όλες / Επιχειρησιακές / Εκπαιδευτικές. Training missions stay visible by
  default so volunteers can find them.

## 4. Errors, testing, scope

**Failure modes.**
- Trigger cannot be created (hosting permissions): an exception from `CREATE TRIGGER` propagates to the
  migration runner, which records `migration_last_error` and does not advance the schema version (visible in
  `_dbcheck.php`). The migration is retried on later requests. Meanwhile the columns already exist and the
  app keeps running, so participation rows created in that gap would carry `is_training = 0` even for a
  drill. The migration therefore ends, after the trigger is created, with an idempotent reconciliation
  `UPDATE` (`trainingReconcileParticipationsSql()`) that copies `missions.is_training` onto every
  participation row that disagrees; it never runs if the `CREATE TRIGGER` threw. Points already granted
  in the gap would need manual reversal. It never falls back silently to "no trigger". The alternative
  (setting the column in each insert site) is raised with the owner, not done unilaterally. Whether the
  trigger exists on the Hostinger database is checked after deployment
  (`SHOW TRIGGERS LIKE 'participation_requests'`).
- Tampered requests: the server ignores `is_training` on mission edit; training-only UI and endpoints
  re-check the stored flag, not the request.
- Missing mission or shift: `COALESCE(..., 0)` treats it as a real row (existing behaviour).
- Certificates: issuing for a training mission still requires CLOSED or COMPLETED.

**Tests** (PHPUnit and `node --test tests/js`).
- Guard test as above.
- Aggregate tests: seed one real and one training mission with attended participations; assert the hours
  rank, dashboard, reports, municipality report, exports, points and achievements ignore the training
  rows, and the training record counts only them.
- Trigger tests: a participation inserted through each path gets the right `is_training`, and editing a
  mission cannot flip it.
- Notification tests: "[ΑΣΚΗΣΗ]" prefix on the participant-facing training mission messages; the
  `mission_needs_volunteers` cron skips drills; the municipality report and the Action Room scorecard
  skip training missions.
- Certificate tests: training wording and hours on the document and the verify page.

**Delivery.** One migration, the schema update, the filters, the new profile and certificate pieces, the
tests, and the repo's usual version-bump commit. `tests/fixtures/schema-structure.sql` is not regenerated:
it lags by design and `tests/migrate-fixture.php` brings it forward (CI and local).

**Execution notes.**
- The guard test is per-file, as stated above: it cannot catch a second unfiltered query inside a file
  that filters elsewhere.
- The browser-level checks (the printed certificate, the badges, the banner, the missions-list filter)
  were not performed by the automated run and remain manual.
- The Hostinger trigger check (`trg_pr_set_training` exists, `migration_last_error` is empty) remains a
  deployment step.
