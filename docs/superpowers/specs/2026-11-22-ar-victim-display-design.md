# The victim on the AR screen (sub-project 3b) — design

Status: design approved by the user 2026-11-22; spec awaiting review. Target release v3.507.0. Follows 3a (finding the victim with the phone's GPS, v3.506.0). No migration and no server change.

## Why

3a lets a team find a training victim with the phone alone: «Είσαι κοντά» at 50 m, «Βρέθηκε» at 10 m, the case opens. The AR «Προβολή Πεδίου» screen knows nothing about it. It draws every order's place as a green beacon, so a known victim looks like any other destination, a hidden victim gives no sign that the team is near, and if the victim is found while the AR is open the case opens **behind** it: the case player is `z-index: 2000`, the AR is `2100`, so the volunteer sees nothing happen.

## Behaviour

Only for an order that carries a training case (`d.case`, which the poll already sends to a volunteer who may play it). Orders without a case look exactly as before.

1. **Point order with a case (a known victim).** The order's place and the victim's spot are the same place. The big green beacon becomes a **red victim beacon**: same pillar, ring and label card, red, with the title «Θύμα», the distance and Δz, and the walking time. The order's triangle on the compass tape and its dot on the radar are red too. Nothing is added beside it: one place, one marker.
2. **Area order with a hidden victim.** The AR **never draws the spot, and shows no distance or direction to it**. The area is drawn as for any area order. Within 50 m of the hidden spot (the 3a rule, with the accuracy credit) the screen gets a **red frame that pulses** and the top banner reads «Είσαι κοντά - ψάξτε γύρω σας» (the existing `case.close_toast`). The toast and the buzz are 3a's, from the page's own tracker, and are not repeated here. Once the team has found the victim the frame goes: there is nothing left to hunt for.
3. **Found while the AR is open.** The page's tracker (3a) opens the case player; the player now sits **above** the AR (`z-index` 2200). The AR stays open underneath. Closing the case returns the volunteer to the AR.
4. **«Άνοιγμα περιστατικού».** A button in the AR's bottom area, shown while the selected order's case may be played: the card says `can_play`, `available` (the team found the victim, or for a point order arrived), or this phone has already reported the find (`wrCaseFoundLocally`, which covers the minutes before the poll catches up or a find reported offline), and the volunteer's own run is not `done`. It opens the same player as the order row's button (`openTrainingCase`). It sits next to the existing «Ελήφθη» / «Έφτασα» button and does not replace it.
5. **Readability, new elements only.** Text at least 13 px, a dark backing behind every new label, the new text never under the top or bottom bars at 360 px. Existing AR content is not touched. (The user's phone check of v3.505.1 is still outstanding; the rest of the AR polish waits for it.)

## Design

### One pure rule, tested in Node

`assets/js/training-case-find.js` (already pure and Node-tested) gains `VoCaseFind.victimView(card, fix)`, so that the decision of what the AR may show lives where the hidden spot is already handled and is testable without a camera:

- `card` is the order's `case` object; `fix` is `{lat, lng, acc}` or null.
- It returns `{mode: 'none'}` when there is no card, the volunteer cannot play it (`can_play` false), the run is `done`, or the card has no spot.
- **Not hidden:** `{mode: 'marker', found: bool}`. The AR draws it at the order's own place, which for a point order is the spot, so no mode carries a spot or a distance.
- **Hidden and not yet found:** `{mode: 'close'}` when `classify(distance, acc)` is `close` or `found`, else `{mode: 'none'}`. It never returns the spot or the distance.
- **Hidden and found:** `{mode: 'none'}`.

`VoCaseFind.canOpen(card, foundLocally)` returns whether the button shows: `can_play`, not `done`, and (`available` or `foundLocally`).

### The AR

`assets/js/war-room-ar.js`:

- `arDrawWorld()` asks `victimView` once per frame for the selected order. `marker` switches the colour of the order's beacon, compass mark and radar dot from green to red and sets the label title to `t('ar.victim')`. `close` sets `arState.caseNear`.
- `arFrame()` toggles a class `wr-ar-case-near` on the AR element from `arState.caseNear` (the pulsing frame, `pointer-events: none`, under the bars).
- `arUpdateDynamicHud()` puts `t('case.close_toast')` in the banner while `caseNear` is set.
- A new button `data-ar="case"` calls `openTrainingCase(card.placement_id, card.briefing)`; it is shown by `canOpen`. The click handler guards `typeof openTrainingCase === 'function'`.
- The Escape handler of the AR ignores the key while `#voCaseOverlay` is on screen, so closing the case does not also close the AR.
- The AR reads the card only through `victimView` and `canOpen`. No other line of this file reads `.spot`; a source test pins that.

`war-room.php`:

- `opModelFromDispatch(d)` carries `case: d.case || null` into the order model, which the AR reads from `opEntries`.
- Styles for `.wr-ar-case-near` (red inner frame, pulse) and the case button.

`assets/js/training-case.js`: the overlay's `z-index` from 2000 to 2200. The toast stays above both (100001).

### Languages

`includes/lang/war-room.php`, Greek and English: `ar.victim` («Θύμα» / «Victim»), `ar.case_open` («Άνοιγμα περιστατικού» / «Open the case»). The banner reuses `case.close_toast`.

## Tests

- Node (`tests/js/training-case-find.test.js`): `victimView` for every card kind: no card, cannot play, done, point (marker, with and without found), hidden far, hidden close, hidden within 10 m and not yet found (close), hidden found (none); the result for a hidden card never has a `spot` or a distance key. `canOpen` for available, found locally, done, cannot play.
- PHP source tests (`tests/TrainingCaseWarRoomSourceTest.php` or a new `tests/TrainingCaseArSourceTest.php`): the AR file reads the card's spot only through `victimView`; `opModelFromDispatch` carries `case`; the player's z-index is above the AR's; the Escape guard exists; the new language keys exist in both languages; version markers agree.
- Browser at 360 px, best effort: the AR screen with the camera and the orientation stubbed, a point order with a case and an area order with a hidden case. Checked: red beacon label inside the screen, the frame and banner, the button above the bottom bar, no overflow, no console errors. The real camera and compass are the user's phone check.

## Not in this part

- A 3D patient model, sounds, an AR marker in the free view (no order selected), a marker for a found hidden victim.
- Any change to the existing AR labels, tiles, chips or radar beyond the colour of the victim's dot.
- Server changes: the poll already carries everything (`case.spot`, `hidden`, `found`, `available`, `can_play`, `state`).

## Known limits

- The AR keeps its camera, sensors and its ten-minute session timer running while the case player is open on top. The timer message would wait under the player.
- The hidden spot still travels to the phone (3a's accepted limit); this part only makes sure the AR never draws it or points at it.

## Release

v3.507.0, `config.php` and `sw.js` together. Nothing is pushed, merged, tagged or released until the user asks.
