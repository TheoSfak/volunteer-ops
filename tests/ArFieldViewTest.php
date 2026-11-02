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
        $this->warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');
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
        // Only the picture on this screen: no upload, no recording of the stream.
        $this->assertStringNotContainsString('MediaRecorder', $this->module);
        $this->assertStringNotContainsString('toBlob', $this->module);
        $this->assertStringNotContainsString('fetch(', $this->module);
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
}
