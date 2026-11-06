<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Chat messages written with no signal (v3.424.0).
 *
 * A message waits in the offline queue, is drawn in its room as "waiting to
 * send", and is sent later with the id the phone made for it and the time it
 * was written. The pieces live in war-room.php (the queue and the chat room),
 * mission-chat.php (the replay answer), migration 198 and the red offline bar
 * in includes/footer.php. Source checks only: the behaviour was driven in a
 * browser, and the queue's ordering is covered by tests/js/media-queue.test.js.
 */
final class ChatOfflineQueueWiringTest extends TestCase
{
    private function source(string $file): string
    {
        $text = WarRoomSource::read($file);
        $this->assertNotFalse($text, $file);
        return $text;
    }

    /** The text of `function $name(` up to the next function declaration. */
    private function functionBody(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        $this->assertNotFalse($start, $name);
        $rest = substr($source, $start + 10);
        preg_match('/\n\s*function /', $rest, $m, PREG_OFFSET_CAPTURE);
        return substr($rest, 0, isset($m[0]) ? $m[0][1] : 2000);
    }

    public function testTheQueueSendsChatMessagesAsItsOwnKind(): void
    {
        $page = $this->source('war-room.php');
        $send = substr($page, (int) strpos($page, 'function sendQueuedItem('), 300);
        $this->assertStringContainsString("item.kind === 'chat'", $send);
        $chat = $this->functionBody($page, 'sendQueuedChat');
        // Every field the server's replay answer needs.
        foreach (['mission-chat.php', "action: 'send'", 'client_uuid: item.uuid', 'reported_at: item.at'] as $needle) {
            $this->assertStringContainsString($needle, $chat);
        }
        // A stale page token keeps the message instead of discarding it.
        $this->assertStringContainsString('result.csrf', $chat);
        $this->assertStringContainsString('window.wrChat.chatLeftQueue', $chat);
    }

    public function testTheRoomKeepsAMessageItCouldNotSendAndShowsItWaiting(): void
    {
        $page = $this->source('war-room.php');
        $submit = substr($page, (int) strpos($page, "chatForm.addEventListener('submit'"), 2200);
        $this->assertStringContainsString("kind: 'chat'", $submit);
        $this->assertStringContainsString('client_uuid: item.uuid', $submit);
        $this->assertStringContainsString('enqueueAction(item)', $submit);
        $this->assertStringContainsString('navigator.onLine === false', $submit);
        // Bounded like every field write, so a dead connection fails in 20 s.
        $this->assertStringContainsString('fetchWithTimeout(', $submit);
        // The pending bubble is drawn from the queue, in the room it belongs to.
        $pending = $this->functionBody($page, 'renderPendingChat');
        $this->assertStringContainsString("q.kind === 'chat'", $pending);
        $this->assertStringContainsString('activeTeamId', $pending);
        $this->assertStringContainsString("t('chat.pending')", $pending);
    }

    public function testASentMessageNeverMovesTheRoomsCursor(): void
    {
        // A message that waited out an outage has an id far past what the others
        // said meanwhile; moving lastIdByRoom there would skip all of it.
        $page = $this->source('war-room.php');
        $left = $this->functionBody($page, 'chatLeftQueue');
        $this->assertStringNotContainsString('lastIdByRoom', $left);
        $this->assertStringContainsString('pollRoom()', $left);
        // And the pending bubble is kept below whatever the poll just drew.
        $poll = substr($page, (int) strpos($page, 'function pollRoom()'), 1800);
        $this->assertStringContainsString('renderPendingChat()', $poll);
    }

    public function testTheEndpointAnswersAReplayOnceAndKeepsTheWrittenTime(): void
    {
        $endpoint = $this->source('mission-chat.php');
        $this->assertStringContainsString("post('client_uuid')", $endpoint);
        $this->assertStringContainsString('client_uuid = ?', $endpoint);
        $this->assertStringContainsString('=== 1062', $endpoint);
        $this->assertStringContainsString('resolveEventTimestamp()', $endpoint);
        $this->assertStringNotContainsString('message, created_at) VALUES (?, ?, ?, ?, NOW())', $endpoint);
        $this->assertStringContainsString("'csrf' => true", $endpoint);
        // The replay is answered BEFORE anyone is notified a second time.
        $this->assertLessThan(strpos($endpoint, 'notifyMissionTeamChat($missionId'), strpos($endpoint, '$already = dbFetchOne('));
    }

    public function testTheGuardColumnExistsOnEveryInstallPath(): void
    {
        $this->assertMatchesRegularExpression("/'version'\s*=>\s*198,[^\]]*client_uuid/s", $this->source('includes/migrations.php'));
        $this->assertStringContainsString('uk_chat_client_uuid', $this->source('includes/migrations.php'));
        $schema = $this->source('sql/schema.sql');
        $this->assertStringContainsString('`client_uuid` VARCHAR(64) NULL', $schema);
        $this->assertStringContainsString('uk_chat_client_uuid', $schema);
        $this->assertGreaterThanOrEqual(198, DB_SCHEMA_VERSION);
    }

    public function testTheBannerCountsMessagesOnTheirOwnLine(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("t('queue.chat_pending'", $page);
        $this->assertStringContainsString("q.kind === 'chat'", $page);
    }

    public function testGpsPositionsAreOnlyPromisedWhereTheyAreReallyKept(): void
    {
        $footer = $this->source('includes/footer.php');
        // The app's sentence needs the Android background service to be running.
        $this->assertStringContainsString("t('myping.native_keeps')", $footer);
        $this->assertStringContainsString('refreshNativeTrackingLive()', $footer);
        // Otherwise the page's own sentence, and only for a person this page tracks;
        // anyone else (an admin with no position) gets none.
        $this->assertMatchesRegularExpression('/\(live \? \' \' \+ nativeKeeps : \(webKept \? \' \' \+ webKeeps : \'\'\)\)/', $footer);
        $this->assertStringContainsString("typeof webPositionsKept === 'function' && webPositionsKept()", $footer);
    }

    public function testEveryNewStringExistsInGreekAndEnglish(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $page = $this->source('war-room.php');
        foreach (['chat.pending', 'queue.chat_pending', 'queue.chat_failed'] as $key) {
            $this->assertSame(2, preg_match_all("/'" . preg_quote($key, '/') . "'\s*=>/", $lang), "$key is needed once per language");
            $this->assertStringContainsString("'$key'", $page, "$key is not used by the page");
        }
        $this->assertSame(2, preg_match_all("/'queue\.chat_pending'\s*=>[^\r\n]*\{count\}/", $lang));
        $this->assertSame(2, preg_match_all("/'queue\.chat_failed'\s*=>[^\r\n]*\{text\}[^\r\n]*\{error\}/", $lang));
    }
}
