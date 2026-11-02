<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The floating live-video tile of the «Πίνακας» console look (v3.429.0).
 *
 * The page's live-video code (war-room.php) attaches a LiveKit track to the <video> of the Live card. The tile
 * shows the same stream in a <video> of its own, and the one thing that must not be taken for granted is HOW
 * the track gets there: through the SDK (track.attach), never by copying the card's srcObject. adaptiveStream
 * pauses a track whose attached elements are all hidden, and the card IS hidden whenever the Media tab is not
 * the open one - a copied picture would freeze the moment command looked at the feed instead. The wiring is
 * pinned by source (a page that talks to LiveKit cannot run here); the picture itself was exercised in a
 * browser with a stand-in track.
 */
final class ConsoleLiveTileTest extends TestCase
{
    private string $root;

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

    public function testThePageAttachesTheTrackToTheTileThroughTheSdkLikeToTheCard(): void
    {
        $page = $this->source('war-room.php');

        $start = (int) strpos($page, 'function attachRemote(participant, track)');
        $this->assertGreaterThan(0, $start);
        $body = substr($page, $start, 2200);
        $this->assertStringContainsString("liveAttach(track, vid)", $body, 'the card is attached as it always was');
        $this->assertStringContainsString('liveAttachTile(track, uid);', $body, 'the tile gets the same track');

        $tile = (int) strpos($page, 'function liveAttachTile(track, uid)');
        $this->assertGreaterThan($start, $tile);
        $fn = substr($page, $tile, 300);
        $this->assertStringContainsString('video[data-live-tile="', $fn);
        $this->assertStringContainsString('liveAttach(track, tile)', $fn, 'liveAttach: attach, then keep asking the browser to play (the WebKit rule)');
        $this->assertStringNotContainsString('srcObject', $fn, 'a copied srcObject would be paused with the hidden card');

        // The tile is built after the stream flows and goes before it ends: three doors on window.
        foreach (['window.liveAttachTile = liveAttachTile;', 'window.liveReattachAll = reattachAll;', 'window.liveDetachTile = function (video) {'] as $door) {
            $this->assertSame(1, substr_count($page, $door), $door);
        }
        // A browser that paused the tile's video on a locked screen plays it again like the card's.
        $this->assertStringContainsString("'#myLivePreview, video[data-live-video], video[data-live-tile]'", $page);
    }

    public function testTheTileNeverBorrowsTheCardsTag(): void
    {
        $js = $this->source('assets/js/war-room-console-overlay.js');
        // data-live-video is the Live card's: the page's own code looks it up, counts it and logs it.
        $this->assertStringNotContainsString('data-live-video', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $js) ?? '');
        $this->assertStringContainsString("setAttribute('data-live-tile', r.uid)", $js);
        // Reveal first, attach second: the video is in a shown tile before the page is asked to attach.
        $show = (int) strpos($js, 'tile.hidden = !pick.show;');
        $attach = (int) strpos($js, 'window.liveReattachAll()');
        $this->assertGreaterThan(0, $show);
        $this->assertGreaterThan($show, $attach);
        // Ended streams are let go through the page (detach) before their element goes.
        $this->assertStringContainsString('window.liveDetachTile(v)', $js);
    }

    public function testTheConsoleLetsTheTileSayThatCommandHasLookedAtTheStream(): void
    {
        $console = $this->source('assets/js/war-room-console.js');
        $this->assertMatchesRegularExpression('~window\.wrConsole = \{[^}]*\bwatchLive,~s', $console);
        // It is the red LIVE button's own function: the flashing stops and the stream counts as seen.
        $this->assertStringContainsString('function watchLive() {', $console);
        $this->assertStringContainsString('seenLive.add(String(r.id))', $console);
    }

    public function testTheTileIsPartOfTheBoardLayoutOnly(): void
    {
        $css = $this->source('assets/css/war-room-console-overlay.css');
        // Hidden for a window under 1200 px (where the script has not built the board), shown by the layout.
        $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.wrc-ov-tile,~', $css);
        $this->assertStringContainsString('html[data-wrc-skin="overlay"][data-wrc-ov] .wrc-ov-tile {', $css);
        $this->assertStringContainsString('border: 3px solid var(--ov-org)', $css, 'the photo\'s orange frame');
        // The tile sits under the queue in the left column.
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('queueEl.parentNode.insertBefore(tile, queueEl.nextSibling)', $js);
    }
}
