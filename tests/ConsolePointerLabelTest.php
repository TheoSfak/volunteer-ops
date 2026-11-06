<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The pointer's ring and label of the «Πίνακας» console look (v3.430.0): the position under the pointer as
 * degrees, minutes and seconds, and, a moment after the pointer rests, the height of the ground.
 *
 * The height is the one thing here that leaves the building, and it does so through a door that is already
 * rate-limited for two other users (the point menu and the measuring tool share 120 asks per ten minutes and
 * per mission in the session). So what is pinned is that the label uses THAT door and no other, and that it
 * only ever takes a part of the allowance. The pointer itself was exercised in a browser.
 */
final class ConsolePointerLabelTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(__DIR__ . '/..');
    }

    private function source(string $relative): string
    {
        $text = \WarRoomSource::read($relative);
        $this->assertNotFalse($text, $relative);
        return str_replace("\r\n", "\n", (string) $text);
    }

    public function testTheHeightComesFromTheDoorThePageAlreadyHasAndOnlyForAPartOfItsAllowance(): void
    {
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('measureElevations([[', $js, 'the page\'s own door to mission-measure.php (action=elevation)');
        $this->assertStringNotContainsString('open-meteo', $js, 'the browser never asks the service itself (the page\'s CSP forbids it, and the server holds the quota)');
        $this->assertStringNotContainsString("'mission-measure.php'", $js);

        // The page's door, and the server's allowance it is counted against.
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("action: 'elevation'", $page);
        $measure = $this->source('mission-measure.php');
        $this->assertMatchesRegularExpression('~const ELEVATION_RATE_MAX = (\d+);~', $measure);
        preg_match('~const ELEVATION_RATE_MAX = (\d+);~', $measure, $server);
        preg_match('~const PTR_BUDGET = (\d+);~', $js, $mine);
        preg_match('~const PTR_WINDOW_MS = (\d+);~', $js, $window);
        $this->assertNotEmpty($mine, 'the pointer has its own, smaller allowance');
        $this->assertSame('600000', $window[1] ?? null, 'counted over the same ten minutes as the server (MEASURE_RATE_WINDOW = 600)');
        $this->assertStringContainsString('const MEASURE_RATE_WINDOW = 600;', $measure);
        $this->assertLessThanOrEqual((int) $server[1] / 2, (int) $mine[1], 'at most half of what the server grants, the rest is for the point menu and the measuring tool');
    }

    public function testTheHeightIsAskedForWhenThePointerRestsAndOncePerCell(): void
    {
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('ptr.rest = setTimeout(', $js, 'a pointer that moves does not ask');
        $this->assertMatchesRegularExpression('~const PTR_REST_MS = (\d{3,4});~', $js);
        $this->assertStringContainsString('ptr.heights.has(cell)', $js, 'a cell that was asked about is not asked about again');
        $this->assertStringContainsString('ptr.heights.set(cell, false);', $js, 'not even when the answer was nothing');
    }

    public function testOnlyTheMouseAsksForAHeightNotTheMapMovingUnderAPointerThatStandsStill(): void
    {
        // Live follow re-centres the map on every position change: with a mouse resting over it that was one
        // lookup per re-centre, with nobody touching anything.
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('movePtr(e.latlng, oe.clientX, oe.clientY, true);', $js, 'the mouse\'s own movement may ask');
        $this->assertStringContainsString('movePtr(latlng, ptr.at.x, ptr.at.y, false);', $js, 'the map moving under the pointer only repaints the label');
        $this->assertMatchesRegularExpression('~clearTimeout\(ptr\.rest\);\n\s+if \(!ask\) return;\n~', $js, 'without ask the rest timer is not armed');
    }

    public function testOneHeightIsAskedAtATimeAndNoneForAWhileAfterAnAskThatBroughtNone(): void
    {
        // Each ask holds one of a shared host's few PHP workers while the outside service answers; a service that
        // does not answer used to fill them all and every other request of the page queued behind (the map layer's too).
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('wrcOvHeightGate(ptr, Date.now(), PTR_BUDGET, PTR_WINDOW_MS)', $js);
        $this->assertStringContainsString('ptr.busy = true;', $js);
        $this->assertStringContainsString('ptr.busy = false;', $js, 'the answer, whatever it is, frees the next ask');
        $this->assertStringContainsString('ptr.pausedUntil = wrcOvHeightPausedUntil(Date.now(), got, PTR_PAUSE_MS);', $js);

        // The page keeps away at least as long as the server does, so it never asks into a pause that is still on.
        preg_match('~const PTR_PAUSE_MS = (\d+);~', $js, $mine);
        $elevation = $this->source('includes/elevation.php');
        preg_match('~const ELEVATION_BREAKER_SECONDS = (\d+);~', $elevation, $server);
        $this->assertNotEmpty($mine);
        $this->assertNotEmpty($server);
        $this->assertGreaterThanOrEqual((int) $server[1] * 1000, (int) $mine[1]);

        // The server side of it: the lookup is bounded, logged and paused after a failure.
        $this->assertStringContainsString('externalBreakerPausedUntil(ELEVATION_BREAKER_KEY)', $elevation);
        $this->assertStringContainsString('externalBreakerTrip(ELEVATION_BREAKER_KEY, ELEVATION_BREAKER_SECONDS)', $elevation);
        $this->assertStringContainsString("externalCallLog('elevation'", $elevation);
    }

    public function testTheLabelNeverTakesTheMouseAndStaysOutOfTheWayOfWhatIsBeingRead(): void
    {
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString("oe.target.closest('.leaflet-popup, .leaflet-control')", $js);
        $this->assertStringContainsString('(hover: hover) and (pointer: fine)', $js, 'a touch screen has no pointer to follow');
        $css = $this->source('assets/css/war-room-console-overlay.css');
        $this->assertMatchesRegularExpression('~\.wrc-ov-ptr-ring \{[^}]*pointer-events: none;~', $css);
        $this->assertMatchesRegularExpression('~\.wrc-ov-ptr-tip \{[^}]*pointer-events: none;~', $css);
        // Under the panels (they are z-index 10 and up), over the map (0).
        $this->assertMatchesRegularExpression('~\.wrc-ov-ptr-tip \{[^}]*z-index: 5;~', $css);
    }

    public function testItCanBeSwitchedOffFromTheGearAndRemembersIt(): void
    {
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString("t('console.ov_coords_toggle')", $js);
        $this->assertStringContainsString("localStorage.setItem(PTR_KEY, ptr.on ? 'on' : 'off')", $js);
        $this->assertStringContainsString("localStorage.getItem(PTR_KEY) !== 'off'", $js, 'on until someone switches it off');
    }
}
