<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The satellite view no longer goes grey (v3.420.2).
 *
 * Esri has photographs only down to z18 over most Cretan towns and answers a
 * deeper request with a flat grey «Map data not yet available» picture (HTTP
 * 200, not 404), so the maps - built with maxNativeZoom 19 - turned grey from
 * zoom 19 up, in the middle of a city. assets/js/esri-imagery.js recognises
 * that tile and paints the shallower photograph enlarged. Every page that
 * draws the Esri photo has to go through it, or that page keeps the grey
 * screen; the behaviour of the layer itself is pinned in
 * tests/js/esri-imagery.test.js.
 */
final class EsriImageryFallbackTest extends TestCase
{
    private const PAGES = ['war-room.php', 'mission-form.php', 'mission-archive.php'];

    private static function source(string $file): string
    {
        return (string) WarRoomSource::read($file);
    }

    public function testEveryPageThatShowsTheEsriPhotoUsesTheGreyProofLayer(): void
    {
        foreach (self::PAGES as $page) {
            $src = self::source($page);

            $this->assertStringContainsString('voEsriImagery(', $src, "$page draws the photo through voEsriImagery()");
            $this->assertSame(0, preg_match('~L\.tileLayer\(\s*[\'"][^\'"]*World_Imagery~', $src), "$page has no plain L.tileLayer on World_Imagery left (it would go grey)");
            $this->assertStringContainsString('/assets/js/esri-imagery.js?v=<?= APP_VERSION ?>', $src, "$page loads the layer script");
        }
    }

    public function testTheLayerScriptLoadsBeforeAnyPageScriptCallsIt(): void
    {
        foreach (self::PAGES as $page) {
            $src = self::source($page);
            $loadedAt = strpos($src, '/assets/js/esri-imagery.js');
            $usedAt = strpos($src, 'voEsriImagery(');

            $this->assertNotFalse($loadedAt);
            $this->assertNotFalse($usedAt);
            $this->assertLessThan($usedAt, $loadedAt, "$page includes esri-imagery.js above its first use");
            $this->assertGreaterThan(
                strpos($src, 'leaflet@1.9.4/dist/leaflet.js'),
                $loadedAt,
                "$page includes esri-imagery.js after Leaflet"
            );
        }
    }

    public function testTheLayerReadsTilesWithoutNeedingAConnectSrcEntry(): void
    {
        // bootstrap.php's Content-Security-Policy does not list Esri under
        // connect-src, so a fetch() would be blocked. The layer inspects tiles
        // as CORS images instead, which img-src (any https host) allows.
        $js = self::source('assets/js/esri-imagery.js');
        $this->assertStringContainsString("crossOrigin = 'anonymous'", $js);
        $this->assertSame(0, preg_match('/\bfetch\s*\(/', $js), 'the layer must not use fetch()');
        $this->assertSame(0, preg_match('/XMLHttpRequest/', $js));

        $csp = self::source('bootstrap.php');
        $this->assertMatchesRegularExpression('/img-src [^;]*https:/', $csp, 'img-src allows https images');
    }

    public function testTheOtherBaseLayersKeepTheirOwnNativeDepth(): void
    {
        // The street and topographic layers upscale past their native zoom with
        // real tiles at every depth; only the Esri photo needed the fallback.
        $warRoom = self::source('war-room.php');
        $this->assertStringContainsString("tile.openstreetmap.org/{z}/{x}/{y}.png', {\n            attribution: '© OpenStreetMap',\n            maxNativeZoom: 19,", str_replace("\r\n", "\n", $warRoom));
        $this->assertStringContainsString('const MAP_MAX_ZOOM = 21;', $warRoom);
    }
}
