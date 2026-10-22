<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A thumbnail that looks clickable opens the photo full size.
 *
 * The Points of Interest list gave its thumbnails the class `media-view-trigger` and never
 * attached a listener to it - the gallery, the broadcast photos and the route admin list each
 * wire their own, and this list had not - so a clue's photo could not be enlarged. The
 * console's feed and a closed route point's photo had the same gap. One document-level
 * handler (capture phase, because a Leaflet popup stops clicks bubbling out of itself) now serves
 * every thumbnail marked `.media-view-delegate`; this pins that each of those three uses it.
 */
final class MediaEnlargeTest extends TestCase
{
    private string $page;
    private string $console;

    protected function setUp(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');
        $console = file_get_contents(__DIR__ . '/../assets/js/war-room-console.js');
        $this->assertNotFalse($page);
        $this->assertNotFalse($console);
        $this->page = str_replace("\r\n", "\n", $page);
        $this->console = str_replace("\r\n", "\n", $console);
    }

    public function testOneCaptureHandlerOpensTheLightboxForEveryMarkedThumbnail(): void
    {
        $this->assertStringContainsString("e.target.closest('.media-view-delegate')", $this->page);
        $this->assertMatchesRegularExpression(
            "/openMediaViewModal\(el\.dataset\.id, el\.dataset\.mediaType, \{note: el\.dataset\.note, meta: el\.dataset\.meta\}\);\n\}, true\);/",
            $this->page,
            'the listener must be a capture-phase one (the trailing `true`)'
        );
    }

    public function testThePointsOfInterestThumbnailsOpenAndCarryTheirNote(): void
    {
        $start = strpos($this->page, 'function renderPointsOfInterest(items)');
        $end = strpos($this->page, 'function renderPoiLayer(items)', (int) $start);
        $fn = substr($this->page, (int) $start, (int) $end - (int) $start);
        $this->assertSame(2, substr_count($fn, 'class="media-view-delegate"'), 'photo and video thumbnails');
        $this->assertStringNotContainsString('media-view-trigger', $fn, 'a trigger class nobody listens to is the bug');
        $this->assertSame(2, substr_count($fn, 'data-note="${escapeHtml(photo.note'));
        $this->assertSame(2, substr_count($fn, 'data-meta="${escapeHtml([photo.reporter_name, photo.time]'));
    }

    public function testAClosedRoutePointsPhotoAndVideoOpen(): void
    {
        $start = strpos($this->page, 'function renderRouteWaypointClosed(wp)');
        $end = strpos($this->page, 'const distanceHtml', (int) $start);
        $fn = substr($this->page, (int) $start, (int) $end - (int) $start);
        $this->assertSame(2, substr_count($fn, 'class="media-view-delegate"'));
    }

    public function testTheConsoleFeedShowsAPointsPhotoAndOpensIt(): void
    {
        // A Point of Interest used to be a flag icon with no picture; its first photo with a thumbnail stands for it.
        $this->assertStringContainsString('const shot = (p.photos || []).find(x => x.has_thumb) || null;', $this->console);
        $this->assertStringContainsString('thumb: shot ? shot.id : null, thumbType: shot ? shot.media_type : null', $this->console);
        // The thumbnail carries what the lightbox needs, including whether it is a video.
        $this->assertStringContainsString('class="wrc-f-thumb media-view-delegate"', $this->console);
        $this->assertStringContainsString('data-media-type="${esc(i.thumbType || \'photo\')}"', $this->console);
        // A thumbnail that appears later (the server makes it after the upload) must redraw the row.
        $this->assertStringContainsString('i.when, i.thumb]', $this->console);
    }
}
