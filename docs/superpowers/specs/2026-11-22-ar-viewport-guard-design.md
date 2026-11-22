# Action Room fits the phone (sub-project 3, first part) — v3.505.1

Status: cause found and fixed 2026-11-22. This replaces the earlier draft of this file, which guessed at a zoomed page and planned a zoom lock and a readout; the real cause turned out to be simpler and both were dropped.

## What was seen

On an Android phone in Chrome, on the live site, the Action Room of a training mission was cut off on the right and at the bottom: the clock bar, the order popup and the AR «Προβολή Πεδίου» screen all lost their right edge, and the AR screen's bottom bar (distance, elevation difference, time, GPS, the layer chips and the green «Ελήφθη» button) was off screen, so the banner told the user to press a button they could not see. The user then confirmed that on a mission that is not a training exercise both the AR screen and the app are fine.

## Cause

The training notice in the Action Room hero is a whole sentence, «ΑΣΚΗΣΗ · Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά», rendered in a Bootstrap `.badge`, which is `white-space: nowrap`. Measured in a browser at 360 px, that one line is 417 px wide and stretches the page to 457 px (`scrollWidth` 457 against a 373 px window). A page wider than the screen makes the phone lay out every `position: fixed` layer at the wider size, so each fixed layer extends past the visible area. The notice came in with v3.504.0 (training missions), so it affected every training mission from then on; the earlier local test at the same width could not show it because the local Apache serves an old v3.500.0 copy of the app, not this checkout.

## Changes

1. `.wr-training-badge` wraps (`white-space: normal`, `max-width: 100%`, `text-align: start`). Measured after: page width equals the window, the notice is 43 px tall on two lines.
2. The hero's status group (status chip plus the «Επιχειρησιακό» button, shown to people with the ops-dashboard permission) wraps (`flex-wrap mw-100` in place of `flex-shrink-0`). It also ran past the hero card at 360 px on a mission with a long status; this is separate from the notice but the same kind of fault.
3. The AR screen starts below the clock bar (`top: var(--app-clock-h, 0px)`), as `.wr-arrow` and `.wr-op-root` already do. Before, the clock bar (22 px, z-index 100000) was drawn over the close button and the top row of the AR screen.
4. Version 3.505.1 (`config.php`, `sw.js`). No migration.

## Tests

- `tests/ActionRoomFitsPhoneTest.php` pins the three rules above.
- Checked in a browser at 360 px with the training flag on: no element past the window, the AR layer fits inside the window and starts at the bottom of the clock bar.
- The phone check is the user's: open AR on a training mission and confirm the bottom bar and the «Ελήφθη» button show.

## Not changed

- No general `overflow-x: hidden` on the page. It would hide the next overflow instead of making it visible, and risks sticky and fixed layers; the fault was one named element.
- No zoom lock or readout panel. With the cause found they would only add code to guard against a guess.
- The rest of the AR polish (radar, labels) waits for the phone result of this fix.
