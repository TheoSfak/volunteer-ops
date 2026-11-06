<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The offline field view (v3.422.0) is five files that only work together:
 * war-room.php writes the snapshot and replays the queue, offline.html and
 * assets/js/field-mode.js read the one and append to the other, sw.js has to
 * hold the page's scripts for a cold start with no network. None of that is
 * checked by the Node tests of field-mode.js, and a rename in one file would
 * break the field view silently - on the one screen nobody can reload.
 *
 * Source checks only; the behaviour is covered by tests/js/field-mode.test.js
 * and by driving the page in a browser.
 */
final class FieldModeWiringTest extends TestCase
{
    private function source(string $file): string
    {
        $text = WarRoomSource::read($file);
        $this->assertNotFalse($text, $file);
        return $text;
    }

    public function testTheServiceWorkerHoldsEverythingTheOfflinePageLoads(): void
    {
        $sw = $this->source('sw.js');
        $page = $this->source('offline.html');
        preg_match_all('/<(?:script src|link rel="stylesheet" href)="([^"]+)"/', $page, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $asset) {
            $this->assertStringContainsString("'./" . $asset . "'", $sw, "$asset is loaded by offline.html but not precached by sw.js");
            $this->assertFileExists(__DIR__ . '/../' . $asset);
        }
        // The Leaflet marker/layer images the stylesheet points at.
        foreach (glob(__DIR__ . '/../assets/leaflet/images/*.png') as $png) {
            $this->assertStringContainsString("'./assets/leaflet/images/" . basename($png) . "'", $sw);
        }
    }

    public function testTheQueueKindsTheOfflinePageWritesAreTheOnesTheLivePageReplays(): void
    {
        $js = $this->source('assets/js/field-mode.js');
        $wr = $this->source('war-room.php');
        $send = substr($wr, (int) strpos($wr, 'function sendQueuedItem('));
        $send = substr($send, 0, 3000);
        foreach (['status', 'route', 'order', 'decline'] as $kind) {
            $this->assertStringContainsString("kind: '$kind'", $js, "field-mode.js never writes kind $kind");
        }
        $this->assertStringContainsString("item.kind === 'order'", $send);
        $this->assertStringContainsString("item.kind === 'decline'", $send);
        $this->assertStringContainsString("item.kind === 'status'", $send);
        // Same endpoints the live buttons post to.
        $this->assertStringContainsString("'mission-order.php'", $send);
        $this->assertStringContainsString("'mission-decline.php'", $send);
        // And the same storage key as the live page.
        $this->assertStringContainsString("'wr_offline_queue_' + missionId", $js);
        $this->assertStringContainsString("const OFFLINE_QUEUE_KEY = 'wr_offline_queue_' + WR_MISSION_ID", $wr);
    }

    public function testTheSnapshotCarriesWhatTheFieldViewReads(): void
    {
        $wr = $this->source('war-room.php');
        $js = $this->source('assets/js/field-mode.js');
        $start = (int) strpos($wr, 'function saveFieldSnapshot()');
        $save = substr($wr, $start, 6000);
        foreach (['orders', 'command', 'sectors', 'areas', 'pois', 'points', 'members', 'view', 'me:'] as $field) {
            $this->assertStringContainsString($field, $save, "snapshot does not write $field");
        }
        foreach (['snapshot.orders', 'snapshot.sectors', 'snapshot.areas', 'snapshot.pois', 'snapshot.points', 'snapshot.members', 'snapshot.command', 'snapshot.view', 'snapshot.me'] as $read) {
            $this->assertStringContainsString($read, $js, "field-mode.js never reads $read");
        }
        // Other teams' movements stay off the phone: members are filtered to my team.
        $this->assertStringContainsString('p.team_label === label', $wr);
    }

    public function testTheOfflineReloadDoesNotTearDownTheFieldViewBlindly(): void
    {
        $page = $this->source('offline.html');
        // A probe that a failed fetch() can answer, before any reload of the map page.
        $this->assertStringContainsString('function networkAnswers()', $page);
        $this->assertStringContainsString("api-war-room-layout.php", $page);
        // sw.js leaves api-* paths to the network, which is what makes that probe honest.
        $this->assertStringContainsString("url.pathname.includes('api-')", $this->source('sw.js'));
    }

    public function testBothLanguagesCarryEveryFieldViewString(): void
    {
        $page = $this->source('offline.html');
        $js = $this->source('assets/js/field-mode.js');
        preg_match_all("/\bt\('([A-Za-z_]+)'/", $js, $m);
        // t('layer_' + k) style prefixes are expanded below.
        $keys = array_values(array_filter(array_unique($m[1]), fn($k) => substr($k, -1) !== '_'));
        // layer_* and reason_* are built from a variable.
        foreach (['street', 'topo', 'satellite', 'hybrid'] as $k) {
            $keys[] = 'layer_' . $k;
        }
        foreach (['unsafe', 'no_access', 'busy', 'injury', 'other'] as $k) {
            $keys[] = 'reason_' . $k;
        }
        $el = substr($page, (int) strpos($page, "el: {"), (int) strpos($page, "en: {") - (int) strpos($page, "el: {"));
        $en = substr($page, (int) strpos($page, "en: {"));
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/\b' . preg_quote($key, '/') . ':/', $el, "el is missing $key");
            $this->assertMatchesRegularExpression('/\b' . preg_quote($key, '/') . ':/', $en, "en is missing $key");
        }
    }
}
