<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «AR Προβολή Πεδίου» (assets/js/war-room-ar.js) — the camera view of an order's place.
 *
 * It is a fourth way to follow an order beside the map, the 🚗/🚶 directions and the
 * «Πυξίδα» arrow, and it reads the same order model they do, so every kind of order
 * with a place needs no setting of its own. The geometry is pinned in
 * tests/js/war-room-ar.test.js; the screen was checked in a browser with a fake
 * camera, compass and GPS. These pin the wiring an edit would quietly undo: the
 * script is loaded, the popup offers the button next to «Πυξίδα» and opAct opens it,
 * and every string the screen asks for exists in both languages.
 */
final class ArFieldViewTest extends TestCase
{
    private string $warRoom;
    private string $module;
    private string $lang;

    protected function setUp(): void
    {
        $this->warRoom = (string) WarRoomSource::full();
        $this->module = (string) file_get_contents(__DIR__ . '/../assets/js/war-room-ar.js');
        $this->lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
    }

    public function testThePageLoadsTheModuleWithItsFileTimeInTheUrl(): void
    {
        $this->assertStringContainsString('/assets/js/war-room-ar.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . \'/assets/js/war-room-ar.js\') ?>', $this->warRoom);
    }

    public function testThePopupOffersARNextToTheCompassAndOpActOpensIt(): void
    {
        $this->assertMatchesRegularExpression(
            "~function opCompassBtnHtml\(m\) \{.*?opBtn\('compass'.*?arSupported\(\).*?opBtn\('ar', 'bi-badge-ar', t\('ar\.open_btn'\)~s",
            $this->warRoom
        );
        $this->assertStringContainsString("if (op === 'ar') { arOpen(m); return; }", $this->warRoom);
    }

    public function testTheScreenReadsTheOrderModelAndSendsStepsThroughOpAct(): void
    {
        // No second source of orders: the same rows the popup and the Πυξίδα read.
        $this->assertStringContainsString('opEntries.forEach', $this->module);
        $this->assertStringContainsString('m.target.nav', $this->module);
        $this->assertStringContainsString('opAct(act.op, m, btn)', $this->module);
        $this->assertStringContainsString('routeArrive(m.nextWaypointId, false)', $this->module);
    }

    public function testTheHeightDifferenceComesFromTheTerrainNotThePhoneAltitude(): void
    {
        $this->assertStringContainsString('measureElevations(pts)', $this->module);
        $this->assertStringNotContainsString('coords.altitude', $this->module, "the phone's GPS altitude is 15-30 m off and must not feed the Δz");
    }

    public function testTheCameraNeverLeavesThePhone(): void
    {
        // Only the picture on this screen: no recording of the stream, no
        // grabbing a frame off it. The «Σημείο» button (v3.468.0) still goes
        // through the device's OWN camera app and the existing, separately
        // tested #poiCaptureInput flow in war-room.php — never this video
        // element — and the ground-paint layer's fetch() (mission-sector-coverage.php)
        // is a plain GET of sector numbers, nothing captured from the stream.
        $this->assertStringNotContainsString('MediaRecorder', $this->module);
        $this->assertStringNotContainsString('toBlob', $this->module);
        $this->assertStringNotContainsString('drawImage', $this->module, 'would be grabbing a frame off the live video element');
        $this->assertStringNotContainsString('captureStream', $this->module);
    }

    public function testEveryStringTheScreenAsksForExistsInGreekAndEnglish(): void
    {
        preg_match_all("~\bt\('(ar\.[a-z_]+)'[,)]~", $this->module, $m);
        preg_match_all("~'(ar\.[a-z_]+)'\s*=>~", $this->lang, $defined);
        $keys = array_unique($m[1]);
        $this->assertNotEmpty($keys);
        $counts = array_count_values($defined[1]);
        foreach ($keys as $key) {
            $this->assertSame(2, $counts[$key] ?? 0, "$key must be defined once in Greek and once in English");
        }
        // Chosen by a variable, so the scan above cannot see them.
        $chosen = ['ar.turn_right', 'ar.turn_left'];
        foreach (['danger', 'cliff', 'forbidden'] as $kind) {
            array_push($chosen, 'ar.kind_' . $kind, 'ar.ahead_' . $kind, 'ar.inside_' . $kind);
        }
        foreach ($chosen as $key) {
            $this->assertSame(2, $counts[$key] ?? 0, "$key must be defined in both languages");
        }
    }

    public function testTheAROpensFromTheCompassScreenTheCardAndAOneTimeOffer(): void
    {
        // «Πυξίδα» screen: a button that hands the same order over to the camera.
        $this->assertStringContainsString('data-arrow="ar" hidden', $this->warRoom);
        $this->assertStringContainsString("if (btn.dataset.arrow === 'ar') { const m = arrowState && opEntries.get(arrowState.key); arrowClose(); if (m) arOpen(m); return; }", $this->warRoom);
        // «Οι Εντολές μου» card: hidden until the phone can do it, wired by the module.
        $this->assertStringContainsString('id="myTasksArBtn"', $this->warRoom);
        $this->assertStringContainsString("document.getElementById('myTasksArBtn')", $this->module);
        // The offer: once per order per session, switchable off for good, never while the arrow or AR is open.
        $this->assertStringContainsString("const AR_OFFER_M = 150;", $this->module);
        $this->assertStringContainsString("wrArOfferOff", $this->module);
        $this->assertStringContainsString("sessionStorage.setItem(AR_OFFERED_KEY", $this->module);
        $this->assertStringContainsString("if (arState || arOfferOff() || document.hidden || !arSupported()) return;", $this->module);
        $this->assertStringContainsString("wr-arrow-open", $this->module);
        // The offer sits over the order strip (1905) and under the full-screen alarms (2000).
        $this->assertMatchesRegularExpression('~.wr-ar-offer {[^}]*z-index: 1906~', $this->warRoom);
    }

    public function testTheAROpensWithNoOrderAsAFreeView(): void
    {
        $this->assertStringContainsString('if (m && !(m.target && m.target.nav)) return;', $this->module);
        $this->assertStringContainsString('key: m ? m.key : null', $this->module);
        $this->assertStringContainsString('function arDrawLayers(S, me, info)', $this->module);
    }

    public function testAStillPhoneKeepsItsLastCompassReadingAndTheBeamStays(): void
    {
        // Browsers send orientation events only when the reading changes, so a phone held still goes quiet.
        // A 3 second limit blanked the beam and brought it back with the next wobble.
        $this->assertMatchesRegularExpression('~const AR_COMPASS_STALE_MS = (\d+)~', $this->module);
        preg_match('~const AR_COMPASS_STALE_MS = (\d+)~', $this->module, $m);
        $this->assertGreaterThanOrEqual(10000, (int) $m[1]);
        $this->assertStringContainsString('< AR_COMPASS_STALE_MS', $this->module);
        // The position and the compass are steadied, and the dimming needs two good fast fixes in a row.
        $this->assertStringContainsString('arSmoothFix(arState.sm', $this->module);
        $this->assertStringContainsString('arSmoothingTau(arAngleBetween(prevBasis, raw))', $this->module);
        $this->assertStringContainsString('arState.fastFixes >= 2', $this->module);
        $this->assertStringContainsString('data-ar="debug"', $this->module);
    }

    public function testTheCompassIsCheckedAndTheFigureEightIsOneTapAway(): void
    {
        // Three stand-ins for an accuracy no browser reports: the field strength, the wander of a held-still phone, iOS's own figure.
        $this->assertStringContainsString('new Magnetometer({frequency: 10})', $this->module);
        $this->assertStringContainsString('c.jitter = arJitterRms(', $this->module);
        $this->assertStringContainsString('webkitCompassAccuracy', $this->module);
        // It has to stay poor for a while before the pill appears, so one bad second does not flash it.
        $this->assertMatchesRegularExpression('~const AR_CALIB_SHOW_AFTER_MS = (\d+)~', $this->module);
        preg_match('~const AR_CALIB_SHOW_AFTER_MS = (\d+)~', $this->module, $m);
        $this->assertGreaterThanOrEqual(3000, (int) $m[1]);
        // The dialog is always reachable by hand: a link, as well as the pill.
        $this->assertSame(2, substr_count($this->module, 'data-ar="calib"'), 'the pill and the link both open it');
        $this->assertStringContainsString("case 'calib': arOpenCalib(); break;", $this->module);
        // A sensor that is not there must not break the screen.
        $this->assertStringContainsString("if (typeof Magnetometer === 'undefined') return;", $this->module);
        // The animation honours reduced motion.
        $this->assertStringContainsString('prefers-reduced-motion: reduce) { .wr-ar-fig8dot { animation: none;', $this->warRoom);
    }
}
