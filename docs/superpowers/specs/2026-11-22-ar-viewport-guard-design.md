# AR screen: viewport guard and readout (sub-project 3, first part)

Status: design approved in conversation 2026-11-22. Release target: v3.505.1.

## Problem

Tested on an Android phone in Chrome, the AR «Προβολή Πεδίου» screen and the Action Room behind it were cut off on the right and at the bottom. In the screenshots:

- The Action Room's clock bar (`.app-clock`, centred, fixed, `left:0; right:0`) shows its text starting 203 px in and running off the right edge, so the bar is laid out about a third wider than the visible area.
- The AR screen's top row, banner and compass tape reach or pass the right edge, and its bottom bar is missing.
- The bottom bar holds the distance, elevation difference (Δζ), time and GPS tiles, the layer chips and the green «Ελήφθη» button (`.wr-ar-go`). The banner says to press «Ελήφθη», so the user could not continue.
- The order popup and the clock bar are already cut off before AR is opened.

Layers pinned with `position: fixed` follow the layout viewport. When the page is zoomed in, the layout viewport is larger than what is visible, so every fixed layer loses its right and bottom part. The likely trigger is Chrome's double-tap zoom on the AR screen (quick taps on its chips), which sets no touch rules; the zoom then stays after AR is closed. This is a hypothesis: it fits the numbers but has not been confirmed on the phone, which is why the readout is part of this work.

A separate fault was found on the way: `.wr-ar` is `position: fixed; inset: 0`, so the clock bar (22 px, z-index 100000) is drawn over the close button and the top row. The app's other fixed layers (`.wr-arrow`, `.wr-op-root`, the map fullscreen layer) all use `top: var(--app-clock-h, 0px)` for this reason; the AR screen is the one that does not.

## Changes

1. **Zoom lock while AR is open.**
   - `arOpen` saves the content of `meta[name="viewport"]` and sets `width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no`. That also resets a zoom the page already has.
   - `arClose` restores the saved content, so the normal app can be pinch-zoomed again.
   - `.wr-ar` gets `touch-action: none`, which stops pinch and double-tap zoom on the screen itself. Its buttons are plain taps and are not affected.
   - If there is no viewport meta, nothing is changed.
2. **Double-tap guard for the Action Room.** `touch-action: manipulation` on the page root in `war-room.php`: double-tap zoom is off, pinch zoom stays for accessibility.
3. **AR below the clock bar.** `.wr-ar` uses `top: var(--app-clock-h, 0px)` like the other fixed layers, so the clock stays visible and nothing is covered. The canvas sizes already come from the element's own `clientWidth`/`clientHeight`.
4. **Readout.** The debug panel (tap the GPS tile) gets one more line from a new pure function `arViewportReport(m)`:
   - Input: `innerWidth`, `innerHeight`, `visualViewport` width, height and scale (or null), `documentElement.scrollWidth`, and the AR element's `clientWidth`/`clientHeight`.
   - Output: `{text, warn}`. `text` reads like `vp 393x852 @1.00 | page 393 | ar 393x852`. `warn` is true, and the text starts with `⚠`, when the scale differs from 1 by more than 0.02, when the page is wider than the window, or when the AR element differs from the window by more than 1 px in either direction.
   - If `visualViewport` is missing, the scale part reads `n/a` and does not warn.
5. **Release.** APP_VERSION and sw.js `CACHE_VERSION` become 3.505.1 (they must agree; CI checks it). No migration, no schema change.

## Tests

- `tests/js/war-room-ar.test.js`: `arViewportReport` for a normal phone, a zoomed page (scale 1.3), a page wider than the window, an AR element larger than the window, and no `visualViewport`.
- A PHP source test, in the style of `tests/ArFieldViewTest.php`, for the wiring: the viewport meta is saved and restored, `.wr-ar` has `touch-action: none` and the clock offset, and the Action Room root has `touch-action: manipulation`.
- Phone checklist, to add to the existing browser checklist: open AR, double-tap and pinch on it, close it, and confirm the page is not zoomed; tap the GPS tile and read the new line; confirm the clock bar is visible and the close button is clear of it; confirm the bottom bar and the «Ελήφθη» button show.

## Not in this change

- No other AR polish yet (radar, labels). That waits for the phone result of this one.
- If the readout still shows a mismatch with the zoom locked, the cause is something else and gets its own fix.
- This cannot be verified in this environment against Chrome's real zoom behaviour; the phone test is the check.
