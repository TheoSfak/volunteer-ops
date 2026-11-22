# Finding the victim (sub-project 3a) — design

Status: design approved by the user 2026-11-22; spec awaiting review. Target release v3.506.0. 3b (the AR display) is a separate spec, release v3.507.0.

## Why

A drill sends a team to find a victim. In real operations that is usually a **sector** (a missing person: the rescuers walk the sector and the app must not say where the person is) and sometimes a **GPS point** (a known victim: the exact spot is shown). Today a training case opens only when the team presses «Έφτασα» or its GPS records arrival at the order's point, so a case cannot be hidden in a sector, and a team can "arrive" without ever finding anything. This part makes the rescuers find the victim, with the phone's GPS, and opens the case when they do. It works on the phone alone; the AR screen is 3b.

## Behaviour

- **Point order:** the victim is at the order's point (shown, as today). Within 50 m the phone says «Είσαι κοντά»; within 10 m it says «Βρέθηκε» and opens the case. The case still also opens on arrival, as in v3.505.0.
- **Polygon (area) order:** the commander pins a **hidden spot** inside the polygon when staging. Rescuers see only the polygon. Within 50 m the phone says «Είσαι κοντά» (no distance, no direction); within 10 m «Βρέθηκε» and the case opens. Pressing «Έφτασα» on a polygon order does **not** open the case: only finding does.
- **Team fact:** finding is the team's, like arrival. When one member finds the victim the case is open for every member of that team (each plays their own run, as now) and the other on-duty members get a notification.
- **Training missions only,** as every case feature.

## Phone side

New file `assets/js/training-case-find.js`, a classic script with one global `VoCaseFind`, pure and testable with `node --test`:

- `VoCaseFind.distanceM(a, b)` — haversine, metres.
- `VoCaseFind.classify(distanceM, accuracyM)` → `'far' | 'close' | 'found'`. GPS error is credited to the volunteer, capped so a bad fix cannot find the victim from afar: `effective = distanceM - min(accuracyM || 0, 15)`; `found` when `effective <= 10`, `close` when `effective <= 50`, else `far`. Constants `FOUND_M = 10`, `CLOSE_M = 50`, `ACCURACY_CREDIT_M = 15` live in this file and are mirrored in PHP (below); a test pins both sets to the same values.
- `VoCaseFind.watcher(cards, onClose, onFound)` — remembers, per placement, what has been announced. `close` fires once per placement per page load; `found` fires once and latches (a later bad fix never un-finds). A placement already `found`, or finished, is skipped.

Fed by its **own** high-accuracy `watchPosition`, started only while at least one watched card exists and stopped when none is left or the page is hidden. The passive location capture is not used: it can be switched off in settings, its accuracy is a battery setting, and its watcher is tied to a three-minute send cadence, which is far too coarse for a 10 m threshold. Only cards with a `spot` that are `can_play`, not `found` and not `done` are watched, so a volunteer with no hidden case pays nothing.

- **Close:** a short toast «Είσαι κοντά — ψάξτε γύρω σας» and `navigator.vibrate(200)` where available. Never a number or an arrow.
- **Found:** vibrate `[200, 100, 200]`, then the case player opens (`VoTrainingCase.open`) with the briefing and first question, and the found report is queued (below).
- **Offline:** the case document is prefetched (`training-case.php?action=load`, which the gate already allows before arrival) as soon as a card with a spot first appears in the poll, so finding and answering work with no signal. The found report is queued like answers: `{kind: 'case_found', placementId, lat, lng, acc, at}` in the existing offline queue, replayed by `sendQueuedItem()` **before** that placement's queued answers (the queue is FIFO; a test pins that a found item queued first replays first).

## Server side

### Schema (migration 226, plus `sql/schema.sql`)

- `mission_case_placements` gains `spot_lat DECIMAL(9,6) NULL`, `spot_lng DECIMAL(9,6) NULL`, `spot_hidden TINYINT(1) NOT NULL DEFAULT 0` (1 = polygon order: the spot is never shown).
- New table `mission_case_finds`: `id`, `placement_id` (FK, cascade), `scope_key VARCHAR(20)` (same as `mission_dispatch_progress`) (the team or person, from `dispatchProgressScopeKey()`), `found_by` (user FK, set null), `found_at DATETIME`, `lat`, `lng`, `accuracy_m` (nullable). `UNIQUE (placement_id, scope_key)`.
- Rows from before the migration have `spot_lat IS NULL`: they behave exactly as in v3.505.0 (arrival opens the case, nothing is watched).

### Staging

`addScenarioCaseItem()` (`includes/functions-scenario.php`) accepts a **point or a polygon**; the `case.err_needs_point` refusal becomes `case.err_needs_point_or_area`. Its `$input` gains `spot` (`lat`, `lng`), required for a polygon, ignored for a point (the point is the spot). A polygon's spot is refused unless it lies **inside the polygon** (ray casting, in a small pure function `scenarioPointInPolygon()` with its own tests). The staged `case` block gains `spot` and `spot_hidden`. The staging UI (`mission-scenario.php` and its script) gets a «Κρυφό σημείο θύματος» step after the polygon is drawn: a tap on the map inside the polygon places a pin that only command sees; tapping outside is refused with a message. An empty label still becomes the neutral «Περιστατικό».

`trainingCaseCreatePlacement()` copies the spot into the placement (for a point order it copies the order's own point, `spot_hidden = 0`).

### Gate

`trainingCaseGate()` (`includes/functions-training-case-runs.php`) decides «may this person play»:

- `needArrival` true and **placement not hidden**: arrival (as today) **or** the team has found it.
- `needArrival` true and **placement hidden**: the team has found it. Arrival is ignored.
- Loading the document (`needArrival` false) is unchanged: allowed for any participant in the audience, which is what makes the prefetch work.

A helper `trainingCaseTeamFound(int $placementId, string $scopeKey): bool` reads `mission_case_finds`.

### Found action

`training-case.php?action=found` (POST: `placement_id`, `lat`, `lng`, `accuracy`, `at`). It checks the gate **without** arrival, then re-checks the distance with the same rule as the phone (`FOUND_M`, `CLOSE_M`, `ACCURACY_CREDIT_M` as `TRAINING_CASE_*` constants in PHP): the report is refused with `code: 'too_far'` if `distance - min(accuracy, 15) > 10`, which stops a hand-made request from opening a hidden case from the sofa. A report for a placement the team already found succeeds with no change (idempotent, as `INSERT IGNORE` on the unique key). On the first find it writes the row, logs `team_found_case` to the audit log, and calls `trainingCaseNotifyFound()`, a sibling of `trainingCaseNotifyArrival()` sharing its recipient logic (a small shared helper, not a copy) with its own message `case.found_message`. Never throws to the caller, like the arrival notice.

### Poll card

`trainingCaseCardsForDispatches()` and the `case` block of the dispatch row (`includes/functions-warroom.php`) gain, for a volunteer who `can_play`:

- `spot` `{lat, lng}` or `null`, `hidden` bool, `found` bool (their team's), `found_at`.
- `available` becomes: not hidden → arrival or found; hidden → found.
- For a **hidden** placement not yet found, `title` is withheld (the card carries the neutral label), so a case title never gives the victim away; it is restored once found.

`spot` is sent only to approved participants in the order's audience. It travels to their phones with the poll and is **not drawn anywhere** for a hidden placement. The known limit, accepted by the user in favour of working with no signal: a volunteer who reads the network response can see where the victim is. This is a drill, not a security boundary; the server re-check above only stops *faking* a find, not *reading* the spot.

### Command's overview

`trainingCaseOverview()` adds, per placement and team, the time it was found and by whom, shown in the existing «Περιστατικά» card as «Βρέθηκε HH:MM από <όνομα>» (nothing is added for a placement not yet found). No new screen.

## Languages

New keys in `includes/lang/war-room.php`, Greek and English: `case.err_needs_point_or_area`, `case.err_spot_outside`, `case.err_too_far`, `case.close_toast`, `case.found_toast`, `case.found_title`, `case.found_message`, `case.spot_pin_help`, `case.overview_found`. Greek wording uses «βρέθηκε», «κοντά», «κρυφό σημείο».

## Tests

PHPUnit (the CRLF-related baseline failures stay out of scope):

- Migration 226 creates the columns and table; running it twice is safe.
- Staging: a point order copies its point; a polygon needs a spot; a spot outside the polygon is refused; a polygon spot on the polygon's edge is handled (inside counts); a non-training mission refuses both.
- Gate matrix: point/hidden × arrived/found/neither, for a member of the team, a member of another team (refused), a non-participant (refused).
- Found action: success inside 10 m; success at 14 m with accuracy 10 (credit); refusal at 30 m; refusal at 14 m with accuracy 100 (credit capped); idempotent second report; second member of the same team can then play; a member of another team cannot.
- Poll card: `spot` and `found` present for an eligible volunteer, absent for a non-participant; a hidden placement withholds its title until found.
- Notification: teammates (not the finder) are told once.
- `scenarioPointInPolygon()` on convex, concave and degenerate polygons.
- Old placements (null spot) behave as v3.505.0.

`node --test tests/js/*.test.js`: `classify` at the boundaries (9.9, 10, 10.1, 49.9, 50, 50.1 m), the accuracy credit and its cap, the latch (found never reverts, close fires once), and the constants match the PHP ones (read from the source, as the existing drift guards do).

Browser check at 360 px with a fake position feed: far → close → found opens the player; no number or direction is ever shown.

Field check (the user): GPS at 10 m is tight on a phone in the open and worse under trees; the accuracy credit is the first knob if «βρέθηκε» is hard to trigger, so the three distances are named constants, not literals.

## Not in this part

- The red patient marker on the AR screen, the AR «close»/«found» cues, opening the case from the AR, the readability pass (all 3b).
- A hidden spot for the **search sector** (`kind='sector'`) items: a case rides on a dispatch order only, as in v3.505.0; a polygon dispatch order is the "sector" for this purpose.
- Several victims per order, moving victims, any server-held secret spot.

## Release

v3.506.0, `config.php` and `sw.js` together, migration 226. Nothing is pushed, merged, tagged or released until the user asks.
