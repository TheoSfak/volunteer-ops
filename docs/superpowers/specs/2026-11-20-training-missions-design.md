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
3. **Notifications.** Training missions notify only the people in the drill, every message prefixed
   "[ΑΣΚΗΣΗ]". Anything that reaches beyond the participants is suppressed.
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
  0 so nothing changes), the same columns and trigger in `sql/schema.sql`, and a regenerated
  `tests/fixtures/schema-structure.sql`.

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
dashboard and report mission counts, municipality and official reports, the public and ICS calendar feeds,
statistics. The missions pages still show training missions (section 3).

**Outbound notifications.** Notifications go only to the people in the drill, each prefixed "[ΑΣΚΗΣΗ]"
(email subject, push title, SMS text). Suppressed for training missions: org-wide "new mission"
broadcasts, newsletters, public listings, the public calendar feed, and external or municipality outputs.

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
- the issue page pre-selects the people with `attended = 1` and shows each recipient's recorded hours;
  recipients without `attended = 1` produce a clear warning.
(The print template's current wording has not been read yet; the implementation plan starts there.)

**The volunteer's training record.**
- A new "Εκπαιδευτικές ασκήσεις" section on `profile.php`: drills attended, total training hours, and a
  dated list (title, hours, link to the certificate if issued).
- The same section on `volunteer-view.php` for admins, and a separate training block in
  `volunteer-report.php` and the volunteer export. Never added into operational totals.
- Hours come from rows with `attended = 1` and `is_training = 1`, using the same shift-duration fallback
  the profile page already applies when `actual_hours` is empty.

**How a training mission looks.**
- An "ΑΣΚΗΣΗ" badge on `missions.php`, the dashboard cards, `mission-view.php` and the Action Room header.
- A banner: "Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά".
- A missions-list filter: Όλες / Επιχειρησιακές / Εκπαιδευτικές. Training missions stay visible by
  default so volunteers can find them.

## 4. Errors, testing, scope

**Failure modes.**
- Trigger cannot be created (hosting permissions): the migration creates it inside try/catch and fails
  loudly, and is not recorded as applied. It never falls back silently, because that would leave
  `is_training` at 0 and leak. The alternative (setting the column in each insert site) is raised with the
  owner, not done unilaterally.
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
- Notification tests: "[ΑΣΚΗΣΗ]" prefix on training mission messages; broadcasts, newsletters, public
  feeds and municipality outputs skip training missions.
- Certificate tests: training wording and hours on the document and the verify page; attended-only
  pre-selection.

**Delivery.** One migration, schema and fixture updates, the filters, the new profile and certificate
pieces, the tests, and the repo's usual version-bump commit.
