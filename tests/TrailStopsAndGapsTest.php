<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Stops and gaps in the «Πορεία Ομάδων» (v3.404.0).
 *
 * The drawing rules themselves (what is a stop, what is a gap) live in
 * assets/js/trail-display.js and are pinned by tests/js/trail-display.test.js.
 * What is pinned here is everything around them that a later edit could
 * quietly undo:
 *
 *  - the server and the page agree on what a gap is: one definition, used by
 *    the GPS quality page's «Κενά στην καταγραφή» and sent to the trail map;
 *  - both pages that draw the trail (the live Action Room and the archive)
 *    load the shared script before they use it and draw from its model, so
 *    neither goes back to its own copy of the old rules;
 *  - every word the new UI shows exists in both languages with the same
 *    placeholders, and none is left defined without being used.
 */
final class TrailStopsAndGapsTest extends TestCase
{
    private string $root;

    /** The keys this feature added; every one is shown somewhere. */
    private const KEYS = [
        'trail.collapse_stops', 'trail.stop_title', 'trail.stop_fixes',
        'trail.duration_m', 'trail.duration_hm', 'trail.gap_tooltip',
    ];

    protected function setUp(): void
    {
        $this->root = (string) realpath(__DIR__ . '/..');
    }

    private function source(string $relative): string
    {
        $text = file_get_contents($this->root . '/' . $relative);
        $this->assertNotFalse($text, $relative);
        return str_replace("\r\n", "\n", (string) $text);
    }

    public function testTheGapIsTheStalenessLineWithAOneMinuteFloor(): void
    {
        $gap = warRoomRecordingGapSeconds();
        $this->assertSame(max(60, warRoomPingStaleThresholdSeconds()), $gap);
        $this->assertGreaterThanOrEqual(60, $gap);
    }

    public function testTheTrailEndpointSendsTheGapAndTheQualityPageUsesTheSameFigure(): void
    {
        $this->assertStringContainsString("'gap_seconds' => warRoomRecordingGapSeconds()", $this->source('mission-track.php'));

        $quality = $this->source('mission-gps-quality.php');
        $this->assertStringContainsString('$gapMinSeconds  = warRoomRecordingGapSeconds();', $quality);
        $this->assertStringNotContainsString('max(60, warRoomPingStaleThresholdSeconds())', $quality, 'a second copy of the gap definition');
    }

    public function testBothTrailPagesLoadTheSharedScriptFirstAndDrawFromItsModel(): void
    {
        $live = $this->source('war-room.php');
        $archive = $this->source('mission-archive.php');

        foreach (['war-room.php' => $live, 'mission-archive.php' => $archive] as $name => $page) {
            $this->assertSame(1, substr_count($page, 'assets/js/trail-display.js?v='), "$name should load the script once");
            $this->assertStringContainsString('trailDisplayModel(', $page, "$name does not draw from the model");
            $this->assertStringContainsString('trailEffectiveGapSeconds(', $page, "$name cuts the line at the raw limit");
            $this->assertStringContainsString('trailStopBadgeHtml(', $page, "$name builds its own stop badge");
            $this->assertStringContainsString('result.gap_seconds', $page, "$name ignores the limit the endpoint sends");
        }

        // Loaded before the script that uses it, not after.
        $this->assertLessThan(
            (int) strpos($live, 'function renderTrailUpTo('),
            (int) strpos($live, 'assets/js/trail-display.js?v='),
            'war-room.php loads trail-display.js after the code that calls it'
        );
        $this->assertLessThan(
            (int) strpos($archive, 'function renderTrails('),
            (int) strpos($archive, 'assets/js/trail-display.js?v='),
            'mission-archive.php loads trail-display.js after the code that calls it'
        );
    }

    public function testTheOldWholeTrailPolylineIsGone(): void
    {
        // One solid line through every fix is what drew a walked line across a silence.
        $live = $this->source('war-room.php');
        $this->assertStringNotContainsString('const latlngs = points.map(p => [p.lat, p.lng]);', $live);
        $archive = $this->source('mission-archive.php');
        $this->assertStringNotContainsString("L.polyline(pts.map(p => [p.lat, p.lng]), {color, weight: 3, opacity: 0.8})", $archive);
    }

    public function testAHeartRateAlarmFixIsNeverFoldedIntoAStopOnTheLivePage(): void
    {
        // The trail rings such a fix so a stretch of route where somebody was in trouble stands out.
        $live = $this->source('war-room.php');
        $this->assertStringContainsString('keep: trailAlarmPoint,', $live);
        $this->assertStringContainsString("return point.hr_zone === 'critical' || point.hr_zone === 'low';", $live);
    }

    public function testTheCheckboxIsOnTheFilterBarAndRedrawsWithoutRefetching(): void
    {
        $live = $this->source('war-room.php');
        // markup + the model call + the change listener
        $this->assertSame(1, substr_count($live, 'id="trailCollapseStops"'));
        $this->assertGreaterThanOrEqual(2, substr_count($live, "getElementById('trailCollapseStops')"));
        $listener = substr($live, (int) strpos($live, "getElementById('trailCollapseStops')?.addEventListener('change'"), 700);
        $this->assertStringContainsString('renderTrailUpTo(currentTrails,', $listener);
        $this->assertStringNotContainsString('fetch(', $listener);

        $archive = $this->source('mission-archive.php');
        $this->assertSame(1, substr_count($archive, 'id="archiveCollapse"'));
        $archiveListener = substr($archive, (int) strpos($archive, "getElementById('archiveCollapse').addEventListener('change'"), 400);
        $this->assertStringContainsString('renderTrails(', $archiveListener);
        $this->assertStringNotContainsString('fetch(', $archiveListener);
    }

    public function testEveryNewKeyIsTranslatedInBothLanguagesWithTheSamePlaceholders(): void
    {
        $strings = require $this->root . '/includes/lang/war-room.php';
        foreach (self::KEYS as $key) {
            foreach (['el', 'en'] as $lang) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key is missing in $lang");
                $this->assertNotSame('', trim((string) $strings[$lang][$key]), "$key is empty in $lang");
            }
            preg_match_all('/\{[a-z_]+\}/', $strings['el'][$key], $a);
            preg_match_all('/\{[a-z_]+\}/', $strings['en'][$key], $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], "$key uses different placeholders in el and en");
        }
    }

    public function testNoNewKeyIsLeftDefinedWithoutBeingUsed(): void
    {
        $code = $this->source('war-room.php') . $this->source('mission-archive.php');
        foreach (self::KEYS as $key) {
            $this->assertStringContainsString("'$key'", $code, "$key is defined but no page uses it");
        }
    }
}
