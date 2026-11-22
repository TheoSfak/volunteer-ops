# The victim on the AR screen (3b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a training victim on the AR «Προβολή Πεδίου» screen: a red beacon for a known victim (point order), a pulsing red frame and the message «Είσαι κοντά» for a hidden one (area order), the case player above the AR, and a button to open the case from the AR. Release v3.507.0.

**Architecture:** The poll already carries the order's `case` card (3a). One pure function, `VoCaseFind.victimView(card, fix)` in `assets/js/training-case-find.js`, decides what the AR may show, so the hidden spot never reaches the drawing code. `assets/js/war-room-ar.js` calls it once per frame; `war-room.php` passes the card through `opModelFromDispatch` and holds the CSS; the case player's overlay moves above the AR. No server, no migration.

**Tech Stack:** PHP 8.2 (XAMPP `/c/xampp/php/php.exe`), PHPUnit (`vendor/phpunit/phpunit/phpunit`), plain JS (classic scripts, `node --test tests/js/*.test.js`), Canvas 2D for the AR screen.

Spec: `docs/superpowers/specs/2026-11-22-ar-victim-display-design.md`.

## Global Constraints

- Only an order that carries a training case (`m.case`) changes. Orders without a case draw exactly as before.
- The AR **never draws the hidden spot and never shows a distance or direction to it.** No line of `assets/js/war-room-ar.js` may read `.spot` of a case card; it reads the card only through `VoCaseFind.victimView` and `VoCaseFind.canOpen`.
- Close means the 3a rule (`classify`: 50 m, accuracy credit capped at 15 m). Do not restate the numbers in the AR file.
- The banner text is the existing `case.close_toast` («Είσαι κοντά - ψάξτε γύρω σας»). New language keys, Greek and English: `ar.victim` («Θύμα» / «Victim»), `ar.case_open` («Άνοιγμα περιστατικού» / «Open the case»).
- The case player's overlay is `z-index: 2200`; the AR is `2100`; the toast stays `100001`.
- Readability, new elements only: text at least 13 px, dark backing, the new text never under the top or bottom bars at 360 px. Existing AR content is not touched.
- Version 3.507.0 in `config.php` (`APP_VERSION`) and `sw.js` (`CACHE_VERSION = 'vo-v3.507.0'`) together. No migration, `DB_SCHEMA_VERSION` stays 226.
- Working-tree files may be CRLF or LF: edit with the Edit tool and single-line or short multi-line anchors, never `sed -i`. Verify an anchor is unique before editing it (Grep).
- Never add an AI co-author line or a «Generated with» line to a commit message. Nothing is pushed, merged, tagged or released.
- Baseline: the full PHPUnit suite has 8 known failures (ScenarioAreaTest 1, ScenarioBuildingTest 1, TrainingCasePagesSourceTest 2, TrainingGatingTest 1, TrainingMissionSchemaTest 3). No new ones.

## File map

- Modify `assets/js/training-case-find.js`: `victimView`, `canOpen`.
- Modify `tests/js/training-case-find.test.js`: tests for both.
- Modify `assets/js/war-room-ar.js`: red victim marker, near frame, banner, case button, Escape guard.
- Modify `war-room.php`: `opModelFromDispatch` carries `case`; CSS for the frame and the button.
- Modify `assets/js/training-case.js`: overlay `z-index` 2200.
- Modify `includes/lang/war-room.php`: `ar.victim`, `ar.case_open` (el and en).
- Create `tests/TrainingCaseArSourceTest.php`: source guards.
- Modify `config.php`, `sw.js`: version.

---

### Task 1: The pure rule, `victimView` and `canOpen`

**Files:**
- Modify: `assets/js/training-case-find.js` (before `const api = {`, and the `api` line)
- Test: `tests/js/training-case-find.test.js` (append)

**Interfaces:**
- Consumes: `classify(distance, accuracy)`, `distanceM(a, b)`, `num(v)` already in the file.
- Produces: `VoCaseFind.victimView(card, fix)` returning `{mode: 'none'}`, `{mode: 'marker', found: boolean}` or `{mode: 'close'}`; `VoCaseFind.canOpen(card, foundLocally)` returning boolean. The card is the order's `case` object from the poll: `{placement_id, can_play, available, found, hidden, state, spot: {lat, lng}|null, briefing}`; `fix` is `{lat, lng, acc}` or null.

- [ ] **Step 1: Write the failing tests**

Append to `tests/js/training-case-find.test.js`:

```js
// ---- v3.507.0: what the AR screen may show --------------------------------------------------------

const SPOT = {lat: 35.3, lng: 25.1};
const card = (over) => Object.assign({placement_id: 7, can_play: true, available: false, found: false, hidden: false, state: 'new', spot: SPOT, briefing: ''}, over || {});
const fixAt = (metres, acc) => ({lat: north(SPOT.lat, metres), lng: SPOT.lng, acc: acc === undefined ? 5 : acc});

test('victimView: nothing for no card, a card I cannot play, a finished run or a card without a spot', () => {
    assert.deepEqual(F.victimView(null, fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({can_play: false}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({state: 'done'}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({spot: null}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({spot: {lat: 'x', lng: 25.1}}), fixAt(5)), {mode: 'none'});
});

test('victimView: a victim whose place is shown (a point order) is a marker whatever the distance, and says whether it is found', () => {
    assert.deepEqual(F.victimView(card(), fixAt(400)), {mode: 'marker', found: false});
    assert.deepEqual(F.victimView(card(), null), {mode: 'marker', found: false}, 'no GPS fix yet: still drawn at the order\'s place');
    assert.deepEqual(F.victimView(card({found: true}), fixAt(400)), {mode: 'marker', found: true});
    assert.deepEqual(F.victimView(card({available: true}), fixAt(400)), {mode: 'marker', found: true});
});

test('victimView: a hidden victim is "close" from 50 m (with the accuracy credit) and never carries a spot or a distance', () => {
    const hidden = (over) => card(Object.assign({hidden: true}, over || {}));
    assert.deepEqual(F.victimView(hidden(), fixAt(200)), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden(), fixAt(60)), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden(), fixAt(49)), {mode: 'close'});
    assert.deepEqual(F.victimView(hidden(), fixAt(30)), {mode: 'close'});
    assert.deepEqual(F.victimView(hidden(), fixAt(8)), {mode: 'close'}, 'within 10 m and not yet found: still only "close"');
    assert.deepEqual(F.victimView(hidden(), fixAt(60, 15)), {mode: 'close'}, '60 m with the full 15 m credit is 45 m effective: close');
    assert.deepEqual(F.victimView(hidden(), fixAt(66, 15)), {mode: 'none'}, '66 m less the 15 m credit is 51 m: still far');
    assert.deepEqual(F.victimView(hidden(), fixAt(66, 100)), {mode: 'none'}, 'the credit is capped at 15 m, whatever the reported accuracy');
    assert.deepEqual(F.victimView(hidden(), fixAt(120, 100)), {mode: 'none'}, 'a vague fix cannot make a far victim close');
    assert.deepEqual(F.victimView(hidden(), null), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden({found: true}), fixAt(8)), {mode: 'none'}, 'found: nothing left to hunt for');
    const close = F.victimView(hidden(), fixAt(30));
    assert.deepEqual(Object.keys(close), ['mode'], 'no spot, no distance, no direction');
    assert.deepEqual(Object.keys(F.victimView(hidden(), fixAt(300))), ['mode']);
});

test('canOpen: the case may be opened from the AR when it is playable, open to my team (or found on this phone) and not finished', () => {
    assert.equal(F.canOpen(card({available: true}), false), true);
    assert.equal(F.canOpen(card(), true), true, 'a find this phone has reported before the poll caught up');
    assert.equal(F.canOpen(card(), false), false);
    assert.equal(F.canOpen(card({available: true, can_play: false}), false), false);
    assert.equal(F.canOpen(card({available: true, state: 'done'}), false), false);
    assert.equal(F.canOpen(null, true), false);
    assert.equal(F.canOpen(card({state: 'running', available: true}), false), true, 'a run in progress can be resumed');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test tests/js/training-case-find.test.js`
Expected: FAIL with `F.victimView is not a function`.

- [ ] **Step 3: Implement**

In `assets/js/training-case-find.js`, insert before the `const api = {` line:

```js
    /**
     * What the AR screen may show for an order's case (v3.507.0). The one place that decides it, so the hidden spot of an area
     * order never reaches the drawing code. 'marker': the victim's place is shown (a point order), drawn at the order's own
     * place, and `found` says the team has found it or arrived; 'close': a hidden victim the volunteer is near, with no spot,
     * distance or direction; 'none': draw nothing about it. card is the order's `case` object from the poll, fix {lat, lng, acc}.
     */
    function victimView(card, fix) {
        const none = {mode: 'none'};
        if (!card || !card.can_play || card.state === 'done' || !card.spot) return none;
        const sLat = num(card.spot.lat);
        const sLng = num(card.spot.lng);
        if (sLat === null || sLng === null) return none;
        if (!card.hidden) return {mode: 'marker', found: !!(card.found || card.available)};
        if (card.found) return none;
        const lat = fix ? num(fix.lat) : null;
        const lng = fix ? num(fix.lng) : null;
        if (lat === null || lng === null) return none;
        return classify(distanceM({lat: lat, lng: lng}, {lat: sLat, lng: sLng}), fix.acc) === 'far' ? none : {mode: 'close'};
    }

    /** Whether the AR shows «Άνοιγμα περιστατικού»: playable, open to my team or found on this phone, and not finished. */
    function canOpen(card, foundLocally) {
        return !!(card && card.can_play && card.state !== 'done' && (card.available || foundLocally));
    }

```

Change the `api` line to:

```js
    const api = {FOUND_M, CLOSE_M, ACCURACY_CREDIT_M, distanceM, classify, watchList, createTracker, victimView, canOpen};
```

- [ ] **Step 4: Run to verify it passes**

Run: `node --test tests/js/training-case-find.test.js`
Expected: PASS (all tests, including the earlier ones).

- [ ] **Step 5: Commit**

```bash
git add assets/js/training-case-find.js tests/js/training-case-find.test.js
git commit -m "AR victim: one pure rule for what the AR screen may show of a training case"
```

---

### Task 2: The victim on the AR screen (red beacon, near frame, banner)

**Files:**
- Modify: `war-room.php` (`opModelFromDispatch`; CSS)
- Modify: `assets/js/war-room-ar.js`
- Modify: `includes/lang/war-room.php`
- Create: `tests/TrainingCaseArSourceTest.php`

**Interfaces:**
- Consumes: `VoCaseFind.victimView(card, fix)` (Task 1); the order model's new `case` field; `arMe()` returns `{lat, lng, acc}`; `AR_COL.red`; `t()`.
- Produces: the order model carries `case: d.case || null`; `arState.caseNear` (boolean per frame); a `.wr-ar-casenear` element; language keys `ar.victim`, `ar.case_open`.

- [ ] **Step 1: Write the failing source tests**

Create `tests/TrainingCaseArSourceTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The victim on the AR screen (v3.507.0): how war-room.php, assets/js/war-room-ar.js and the case player fit together. */
final class TrainingCaseArSourceTest extends TestCase
{
    private string $page;
    private string $ar;
    private string $player;
    private string $lang;

    protected function setUp(): void
    {
        $read = static fn (string $path): string => str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $path));
        $this->page = $read('war-room.php');
        $this->ar = $read('assets/js/war-room-ar.js');
        $this->player = $read('assets/js/training-case.js');
        $this->lang = $read('includes/lang/war-room.php');
    }

    public function test_the_ar_never_reads_the_spot_of_a_case_card(): void
    {
        // The hidden spot must not reach any drawing code: the AR asks victimView, which never returns it.
        $this->assertStringNotContainsString('.spot', $this->ar);
        $this->assertStringNotContainsString('spot_lat', $this->ar);
        $this->assertStringContainsString('VoCaseFind.victimView(', $this->ar);
    }

    public function test_the_order_model_carries_the_case_card_to_the_ar(): void
    {
        $start = strpos($this->page, 'function opModelFromDispatch(');
        $this->assertNotFalse($start);
        $model = substr($this->page, $start, 3000);
        $this->assertStringContainsString('case: d.case || null,', $model);
    }

    public function test_a_known_victim_is_drawn_red_only_on_a_point_order(): void
    {
        $this->assertStringContainsString("victim.mode === 'marker' && t0.kind === 'point' ? AR_COL.red : AR_COL.green", $this->ar);
        $this->assertStringContainsString("t('ar.victim')", $this->ar);
    }

    public function test_the_near_frame_and_the_banner_exist_and_the_frame_is_dimmed_with_the_rest(): void
    {
        $this->assertStringContainsString('wr-ar-casenear', $this->ar);
        $this->assertStringContainsString('arState.caseNear', $this->ar);
        $this->assertStringContainsString("t('case.close_toast')", $this->ar);
        $this->assertMatchesRegularExpression('/\.wr-ar-casenear \{[^}]*pointer-events: none;/', $this->page);
        $this->assertStringContainsString('.wr-ar-dim .wr-ar-casenear', $this->page);
    }

    public function test_the_new_texts_exist_in_both_languages(): void
    {
        foreach (['ar.victim', 'ar.case_open'] as $key) {
            $this->assertSame(2, substr_count($this->lang, "'$key' =>"), "$key in Greek and English");
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseArSourceTest.php`
Expected: FAIL (the first test passes only after step 3 adds `victimView(` to the file; `.spot` is already absent).

- [ ] **Step 3: Implement**

3a. `war-room.php`, in `opModelFromDispatch`, after the line `        canComplete: !!d.can_complete,` (unique, verify with Grep) add:

```js
        // The training case on this order, for the AR screen (assets/js/war-room-ar.js, v3.507.0).
        case: d.case || null,
```

3b. `war-room.php` CSS: after the line `    .wr-ar-go[hidden] { display: none; }` add:

```css
    /* A hidden victim is near (v3.507.0): a red frame that pulses and says nothing about where. It sits under the bars and never takes a tap. */
    .wr-ar-casenear { position: absolute; inset: 0; pointer-events: none; box-shadow: inset 0 0 0 4px rgba(248, 81, 73, .85), inset 0 0 46px 10px rgba(248, 81, 73, .55); animation: wr-ar-casenear 1.4s ease-in-out infinite; }
    .wr-ar-casenear[hidden] { display: none; }
    @keyframes wr-ar-casenear { 0%, 100% { opacity: .45; } 50% { opacity: 1; } }
    @media (prefers-reduced-motion: reduce) { .wr-ar-casenear { animation: none; opacity: 1; } }
```

and change the line `    .wr-ar-dim .wr-ar-world, .wr-ar-dim .wr-ar-top, .wr-ar-dim .wr-ar-rail, .wr-ar-dim .wr-ar-radar, .wr-ar-dim .wr-ar-bottom, .wr-ar-dim .wr-ar-warn { opacity: .08; }` to include `.wr-ar-dim .wr-ar-casenear` in its selector list (before `{ opacity: .08; }`).

3c. `assets/js/war-room-ar.js`:

- After the line `        <canvas class="wr-ar-world"></canvas>` add `        <div class="wr-ar-casenear" hidden></div>`.
- In `arDrawWorld`, after the `const info = {dist: flatNav, ...};` line (the one that starts `const info = {dist: flatNav, dz:`) add:

```js
    // The victim of a training case on this order (v3.507.0). A known victim's place is the order's own place, so its beacon turns red;
    // a hidden one is never drawn at all, the screen only says the team is near. VoCaseFind.victimView decides, so no spot is read here.
    const victim = m.case && typeof VoCaseFind !== 'undefined' ? VoCaseFind.victimView(m.case, me) : {mode: 'none'};
    arState.caseNear = victim.mode === 'close';
    const markCol = victim.mode === 'marker' && t0.kind === 'point' ? AR_COL.red : AR_COL.green;
```

- Replace the three lines of the final `else` branch (the point order):

```js
        const title = String(m.text || m.title).split('\n')[0];
        if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: AR_COL.green, big: true, ring: true, lines: targetLines(title)});
        else S.edges.push({turn: arTurnTo(S.heading, navAngles.az), label: formatDistanceMeters(flatNav), col: AR_COL.green});
```

with:

```js
        const title = markCol === AR_COL.red ? t('ar.victim') : String(m.text || m.title).split('\n')[0];
        if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: markCol, big: true, ring: true, lines: targetLines(title)});
        else S.edges.push({turn: arTurnTo(S.heading, navAngles.az), label: formatDistanceMeters(flatNav), col: markCol});
```

- Replace `    pushMark(navAngles.az, AR_COL.green, 'tri');` with `    pushMark(navAngles.az, markCol, 'tri');` and, in the next line, change `col: AR_COL.green, size: 4` to `col: markCol, size: 4`.
- In `arFrame`, immediately before the line `    if (!me || !poseFresh) {` add `    arState.caseNear = false;` (the draw below sets it again when the team is near).
- In `arUpdateDynamicHud`, after its first line `    const info = arState.scene;` (that exact line is also in `arGo`, so anchor on the two lines `function arUpdateDynamicHud(el, m, S) {` and `    const info = arState.scene;`) add:

```js
    const nearFrame = el.querySelector('.wr-ar-casenear');
    if (nearFrame) nearFrame.hidden = !arState.caseNear;
```

  and immediately before `    el.querySelector('.wr-ar-banner').textContent = banner;` add:

```js
    if (arState.caseNear) banner = t('case.close_toast');
```

3d. `includes/lang/war-room.php`: after the Greek line `        'ar.sector' => 'Τομέας',` add `        'ar.victim' => 'Θύμα',` and `        'ar.case_open' => 'Άνοιγμα περιστατικού',`; after the English line `        'ar.sector' => 'Sector',` add `        'ar.victim' => 'Victim',` and `        'ar.case_open' => 'Open the case',`.

- [ ] **Step 4: Run to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseArSourceTest.php` then `/c/xampp/php/php.exe -l war-room.php`, `/c/xampp/php/php.exe -l includes/lang/war-room.php` and `node --test tests/js/war-room-ar.test.js tests/js/training-case-find.test.js`.
Expected: PASS, no syntax errors. Also run `node --check assets/js/war-room-ar.js`.

- [ ] **Step 5: Commit**

```bash
git add war-room.php assets/js/war-room-ar.js includes/lang/war-room.php tests/TrainingCaseArSourceTest.php
git commit -m "AR victim: a known victim is a red beacon, a hidden one a pulsing frame and 'you are close'"
```

---

### Task 3: The button, the player above the AR, and Escape

**Files:**
- Modify: `assets/js/war-room-ar.js`
- Modify: `assets/js/training-case.js` (the overlay's `z-index`)
- Modify: `war-room.php` (CSS for the button)
- Modify: `tests/TrainingCaseArSourceTest.php` (append tests)

**Interfaces:**
- Consumes: `VoCaseFind.canOpen(card, foundLocally)` (Task 1), `wrCaseFoundLocally(placementId)` and `openTrainingCase(placementId, briefing)` from `war-room.php` (3a).
- Produces: a button `data-ar="case"` in the AR; the player overlay above the AR; the AR's Escape handler ignores the key while the player is open.

- [ ] **Step 1: Write the failing tests**

Append inside the class in `tests/TrainingCaseArSourceTest.php`:

```php
    public function test_the_case_button_is_shown_by_canOpen_and_opens_the_same_player_as_the_order_row(): void
    {
        $this->assertStringContainsString('data-ar="case"', $this->ar);
        $this->assertStringContainsString('VoCaseFind.canOpen(', $this->ar);
        $this->assertStringContainsString('wrCaseFoundLocally', $this->ar);
        $this->assertStringContainsString("case 'case':", $this->ar);
        $this->assertStringContainsString('openTrainingCase(', $this->ar);
        $this->assertStringContainsString("t('ar.case_open')", $this->ar);
        $this->assertStringContainsString('.wr-ar-casebtn', $this->page);
    }

    public function test_the_case_player_sits_above_the_ar_screen(): void
    {
        preg_match('/\.wr-ar \{[^}]*z-index: (\d+);/', $this->page, $ar);
        preg_match('/id = \'voCaseOverlay\'.*?z-index:(\d+);/s', $this->player, $player);
        $this->assertNotEmpty($ar, 'the AR layer rule');
        $this->assertNotEmpty($player, 'the player overlay rule');
        $this->assertGreaterThan((int) $ar[1], (int) $player[1], 'a case found with the AR open must show over it');
        $this->assertLessThan(100001, (int) $player[1], 'the find toast stays above the player');
    }

    public function test_escape_closes_the_case_player_not_the_ar_under_it(): void
    {
        $this->assertStringContainsString("e.key === 'Escape' && !document.getElementById('voCaseOverlay')", $this->ar);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseArSourceTest.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Implement**

3a. `assets/js/war-room-ar.js`:

- Replace the line `            <button type="button" class="wr-ar-go" data-ar="go" hidden></button>` with:

```js
            <button type="button" class="wr-ar-go wr-ar-casebtn" data-ar="case" hidden>${escapeHtml(t('ar.case_open'))}</button>
            <button type="button" class="wr-ar-go" data-ar="go" hidden></button>
```

- After the line `            case 'poi': arCapturePoi(); break;` add:

```js
            case 'case': { const m = opEntries.get(arState.key); if (m && m.case && typeof openTrainingCase === 'function') openTrainingCase(m.case.placement_id, m.case.briefing); break; }
```

- Replace `    arState.onKey = e => { if (e.key === 'Escape') arClose(); };` with:

```js
    // The case player can sit over this screen (v3.507.0): its own close, not this one, answers the key then.
    arState.onKey = e => { if (e.key === 'Escape' && !document.getElementById('voCaseOverlay')) arClose(); };
```

- At the end of `arUpdateStaticHud` (after the line that sets `[data-ar-k="title"]`'s `textContent`, the last line of the function) add:

```js
    // «Άνοιγμα περιστατικού» while this order's case may be played: found or reached by my team, or found on this phone and not yet in the poll.
    const caseBtn = el.querySelector('[data-ar="case"]');
    if (caseBtn) {
        const found = m && m.case && typeof wrCaseFoundLocally === 'function' && wrCaseFoundLocally(m.case.placement_id);
        caseBtn.hidden = !(m && m.case && typeof VoCaseFind !== 'undefined' && VoCaseFind.canOpen(m.case, !!found));
    }
```

3b. `assets/js/training-case.js`: in the line that sets `overlay.style.cssText` change `z-index:2000;` to `z-index:2200;` and add above that line: `        // Above the AR screen (z-index 2100 in war-room.php): a victim found with the AR open must show over it.`

3c. `war-room.php` CSS, after the `.wr-ar-casenear` rules from Task 2 add:

```css
    /* «Άνοιγμα περιστατικού» in the AR (v3.507.0): the same big button as «Ελήφθη», in red so the two are told apart at a glance. */
    .wr-ar-casebtn { background: #f85149; color: #fff !important; }
    .wr-ar-casebtn[hidden] { display: none; }
```

- [ ] **Step 4: Run to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseArSourceTest.php tests/ArFieldViewTest.php tests/TrainingCaseWarRoomSourceTest.php`, `node --check assets/js/war-room-ar.js`, `node --check assets/js/training-case.js`, and `node --test tests/js/*.test.js`.
Expected: PASS (JS 700+ tests green; the PHP files pass).

- [ ] **Step 5: Commit**

```bash
git add assets/js/war-room-ar.js assets/js/training-case.js war-room.php tests/TrainingCaseArSourceTest.php
git commit -m "AR victim: the case opens over the AR, and a button opens it from the AR"
```

---

### Task 4: Version, full suites and the 360 px check

**Files:**
- Modify: `config.php` (`APP_VERSION`), `sw.js` (`CACHE_VERSION`)
- Test: the whole suites; a browser check

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: v3.507.0, ready for a pull request.

- [ ] **Step 1: Bump the version**

`config.php`: `define('APP_VERSION', '3.506.0');` to `'3.507.0'`. `sw.js`: `const CACHE_VERSION = 'vo-v3.506.0';` to `'vo-v3.507.0'`. Leave `DB_SCHEMA_VERSION` alone.

- [ ] **Step 2: Run the full suites**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit` and `node --test tests/js/*.test.js`.
Expected: PHPUnit has only the 8 known failures listed in Global Constraints, no others; JS passes (about 712 tests).

- [ ] **Step 3: Browser check at 360 px (best effort)**

The real camera and compass are the user's phone check; here only the screen's structure is checked. Serve the CURRENT code, not the stale copy at `http://localhost/volunteer-ops`: `/c/xampp/php/php.exe -S 127.0.0.1:8765 -t .` in the repo root (via the preview tools or a background shell), log in as the local admin (the credentials are in the project's local seed; do not write them in any file or message), and open the Action Room of the local training mission (set its `is_training` to 1 for the check and put it back to 0 afterwards; remove any temporary `.claude/launch.json`). Resize to 360 x 740. In the page's JS console:

1. Stub the sensors: `Object.defineProperty(navigator, 'maxTouchPoints', {value: 1});` replace `navigator.mediaDevices.getUserMedia` with one that resolves a `document.createElement('canvas').captureStream()`; fake a GPS fix with `navigator.geolocation.watchPosition = cb => { cb({coords: {latitude: 35.3, longitude: 25.1, accuracy: 5, speed: 0}, timestamp: Date.now()}); return 1; };`.
2. Put a point order with a case into `opEntries` by calling `opModelFromDispatch` on a fake dispatch `{id: 9001, type: 'point', geo: {lat: 35.3002, lng: 25.1}, label: 'Test', team_label: 'A', can_receive: true, can_ack: true, case: {placement_id: 1, can_play: true, available: false, found: false, hidden: false, state: 'new', spot: {lat: 35.3002, lng: 25.1}, briefing: ''}}` and `opEntries.set(model.key, model)`; call `arOpen(model)` and dispatch `window.dispatchEvent(new DeviceOrientationEvent('deviceorientationabsolute', {alpha: 0, beta: 90, gamma: 0, absolute: true}))` facing the order.
3. Check: no console errors; no element wider than 360 px (`document.documentElement.scrollWidth === 360`); the red beacon label (read the canvas colour at the beacon or add a temporary `console.log(VoCaseFind.victimView(model.case, arMe()))` and expect `{mode: 'marker', found: false}`); the case button is hidden (`available` false), then set `model.case.available = true` and check it shows above «Ελήφθη» and above the bottom bar.
4. Repeat with an area order (`type: 'area'`, a small polygon, `hidden: true`, `spot` inside): at 200 m from the spot the frame is hidden; move the fake fix to 30 m: `.wr-ar-casenear` is visible, the banner reads «Είσαι κοντά - ψάξτε γύρω σας», and nothing on the canvas or in any text names a distance or direction to the spot.
5. With the AR open click the button (or call `openTrainingCase(1, '')` with `VoTrainingCase` stubbed to `open` an empty overlay `div#voCaseOverlay` at `z-index: 2200`): the overlay is above the AR (`document.elementFromPoint(180, 370)` is inside it). Press Escape: the AR stays open.
6. Reset the viewport to desktop, stop the server, restore the local mission to `is_training = 0`, and confirm `git status` shows only the intended files.

Report what was and was not checked. Do not claim the real camera view was checked.

- [ ] **Step 4: Commit**

```bash
git add config.php sw.js
git commit -m "v3.507.0: The victim on the AR screen - red beacon, hidden-victim frame, case opens over the AR"
```

---

## Self-review (done when writing)

- Spec coverage: behaviours 1 and 2 are Task 2; 3 (case over the AR) and 4 (button) are Task 3; 5 (readability: dark backing via the existing `arLabelCard`, 14 px banner, the frame has no text, the button is the 16 px `.wr-ar-go` style) is met by reusing existing styles and checked in Task 4; the pure rule and its Node tests are Task 1; source guards, language keys and version are Tasks 2 to 4. The spec's marker result no longer carries a spot (amended in the spec).
- No placeholders; every code step shows the code. Names are consistent: `victimView`, `canOpen`, `arState.caseNear`, `markCol`, `.wr-ar-casenear`, `.wr-ar-casebtn`, `data-ar="case"`, `ar.victim`, `ar.case_open`.
- Known limit, not a defect: the AR's ten-minute timer message and its camera keep running under the case player.
