<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * GPS positions kept by the page while there is no signal (v3.425.0).
 *
 * A browser tab or an iPhone has no background service, so a position the page
 * could not send used to be dropped. It is now queued with the moment the phone
 * took it and replayed in one request, oldest first. The pieces: war-room.php
 * (the queue, the third lane, the two senders), assets/js/media-queue.js (what
 * to keep and what goes next, tested in tests/js/media-queue.test.js),
 * ping-location.php + recordBrowserPingBatch() (tested in BrowserPingBatchTest)
 * and the red offline bar. Source checks only; the behaviour was driven in a
 * browser.
 */
final class PingOfflineQueueWiringTest extends TestCase
{
    private function source(string $file): string
    {
        $text = file_get_contents(__DIR__ . '/../' . $file);
        $this->assertNotFalse($text, $file);
        return $text;
    }

    public function testThePositionsHaveTheirOwnLaneAndLock(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("{fast: false, media: false, gps: false}", $page);
        $this->assertStringContainsString("flushQueueLane('gps')", $page);
        // One lock per lane, so a slow upload or a big backlog never reads as "busy" to the others.
        $this->assertStringContainsString("QUEUE_LOCK_KEY + '_' + lane", $page);
        $send = substr($page, (int) strpos($page, 'function sendQueuedItem('), 400);
        $this->assertStringContainsString("item.kind === 'ping'", $send);
    }

    public function testTheBacklogGoesOutInOneRequestWithEachFixOwnAge(): void
    {
        $page = $this->source('war-room.php');
        $start = (int) strpos($page, 'function sendQueuedPings(');
        $body = substr($page, $start, 1800);
        $this->assertStringContainsString('VoMediaQueue.collectPingBatch(', $body);
        $this->assertStringContainsString("'ping-location.php'", $body);
        $this->assertStringContainsString('batch: JSON.stringify(fixes)', $body);
        // Age taken at the moment of sending, from the fix's own time on the phone's clock.
        $this->assertStringContainsString('fix_age_ms: Math.max(0, now - p.at)', $body);
        // A stale page token keeps them; nothing else is retried, because a refused fix is a verdict.
        $this->assertStringContainsString('result.csrf', $body);
        $this->assertStringContainsString('result.networkError', $body);
    }

    public function testBothSendersKeepWhatTheyCouldNotSend(): void
    {
        $page = $this->source('war-room.php');
        $auto = substr($page, (int) strpos($page, 'function sendAutoPing('), 3200);
        $this->assertStringContainsString('keepPositionForLater(', $auto);
        $this->assertStringContainsString('at: position.timestamp', $auto, 'the moment the phone took the fix, not the moment it gave up');
        $this->assertStringContainsString('navigator.onLine === false', $auto);
        // A server verdict is not an outage: only no answer, or a lapsed login, is kept.
        $this->assertStringContainsString('result === null', $auto);
        $this->assertStringContainsString('showMyPingRefused(result.error)', $auto);
        // The manual button keeps its fix too, and says so.
        $this->assertStringContainsString("source: 'manual', at: fix.ts", $page);
        $this->assertStringContainsString("t('myping.saved_offline')", $page);
    }

    public function testInsideTheAppTheServiceKeepsThemNotThePage(): void
    {
        $page = $this->source('war-room.php');
        $keep = substr($page, (int) strpos($page, 'function keepPositionForLater('), 500);
        $this->assertStringContainsString('refreshNativeTrackingLive()', $keep);
        $this->assertStringContainsString('if (live) return false;', $keep);
        $this->assertStringContainsString('enqueuePing(ping)', $keep);
    }

    public function testTheQueueIsPrunedBeforeEveryPositionIsAdded(): void
    {
        $page = $this->source('war-room.php');
        $enqueue = substr($page, (int) strpos($page, 'function enqueuePing('), 300);
        $this->assertStringContainsString('VoMediaQueue.prunePings(loadQueue(), Date.now())', $enqueue);
        $this->assertStringContainsString("Object.assign({kind: 'ping'}, ping)", $enqueue);
    }

    public function testTheBannerCountsPositionsOnTheirOwnLine(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("t('queue.ping_pending'", $page);
        $this->assertStringContainsString('VoMediaQueue.countQueuedPings(queue)', $page);
        // Positions are not "field actions": the generic count leaves them out.
        $this->assertStringContainsString('queue.length - mediaCount - chatCount - pingCount', $page);
    }

    public function testTheServerHalfIsAlreadyThereAndTheModuleHasTheLimits(): void
    {
        $this->assertStringContainsString('function recordBrowserPingBatch(', $this->source('includes/functions-warroom.php'));
        $queue = $this->source('assets/js/media-queue.js');
        // The page's half hour must be the server's half hour, or the page keeps
        // positions the server will only log as refused.
        $this->assertStringContainsString('PING_QUEUE_MAX_AGE_MS = 1800000', $queue);
        $this->assertSame(1800, WAR_ROOM_MAX_FIX_AGE_SECONDS);
        $this->assertLessThanOrEqual(BROWSER_PING_BATCH_MAX, 50, 'the page sends 50 at most');
        $this->assertStringContainsString('PING_BATCH_MAX = 50', $queue);
    }

    public function testEveryNewStringExistsInGreekAndEnglish(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $page = $this->source('war-room.php');
        foreach (['queue.ping_pending', 'myping.saved_offline'] as $key) {
            $this->assertSame(2, preg_match_all("/'" . preg_quote($key, '/') . "'\s*=>/", $lang), "$key is needed once per language");
            $this->assertStringContainsString("'$key'", $page, "$key is not used by the page");
        }
        $this->assertSame(2, preg_match_all("/'myping\.web_keeps'\s*=>/", $lang));
        $this->assertStringContainsString("t('myping.web_keeps')", $this->source('includes/footer.php'));
        $this->assertSame(2, preg_match_all("/'queue\.ping_pending'\s*=>[^\r\n]*\{count\}/", $lang));
        // The sentence must not promise more than the server accepts.
        $this->assertSame(2, preg_match_all("/'myping\.web_keeps'\s*=>[^\r\n]*30/", $lang));
    }
}
