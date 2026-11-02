<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Photos and videos kept on the phone while there is no signal (v3.423.0).
 *
 * The feature is one module (assets/js/media-queue.js), the page that uses it
 * (war-room.php), the endpoint that answers a replay (mission-photo.php) and a
 * column that makes the replay safe (migration 197). A rename in one of them
 * would not fail anything until a volunteer is standing in a gorge with a
 * photo that has nowhere to go. Source checks only: the decisions are covered
 * by tests/js/media-queue.test.js, and the IndexedDB half and the real replay
 * were driven in a browser.
 */
final class MediaOfflineQueueWiringTest extends TestCase
{
    private function source(string $file): string
    {
        $text = file_get_contents(__DIR__ . '/../' . $file);
        $this->assertNotFalse($text, $file);
        return $text;
    }

    /** The markup of one <input ... id="$id" ...> tag. */
    private function inputTag(string $page, string $id): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/', $page, $m), $id);
        return $m[0];
    }

    public function testThePhotoAndVideoButtonsAskForNoDirectCapture(): void
    {
        $page = $this->source('war-room.php');
        foreach (['photoCaptureInput', 'videoCaptureInput', 'photoGalleryInput', 'videoGalleryInput'] as $id) {
            $this->assertStringNotContainsString('capture=', $this->inputTag($page, $id), "$id must not force the camera in markup");
        }
        // A Point of Interest is "here, now": a library photo would pin an old
        // picture to today's position, so that one keeps the camera everywhere.
        $this->assertStringContainsString('capture="environment"', $this->inputTag($page, 'poiCaptureInput'));
    }

    public function testTheDirectCameraIsAddedBackOnlyOffAnIphone(): void
    {
        $page = $this->source('war-room.php');
        // The two direct-camera inputs are marked, and the page adds the
        // attribute for them only when it is not an iPhone/iPad.
        $this->assertStringContainsString('data-direct-camera', $this->inputTag($page, 'photoCaptureInput'));
        $this->assertStringContainsString('data-direct-camera', $this->inputTag($page, 'videoCaptureInput'));
        $mode = substr($page, (int) strpos($page, 'function applyMediaPickerMode()'));
        $mode = substr($mode, 0, (int) strpos($mode, "wireMediaInput('photoCaptureInput'"));
        $this->assertStringContainsString('if (WR_IS_IOS)', $mode);
        $this->assertStringContainsString("setAttribute('capture', 'environment')", $mode);
        // The route point's own button, built in script, follows the same rule.
        $this->assertMatchesRegularExpression('/if \(!WR_IS_IOS\) input\.setAttribute\(\'capture\', \'environment\'\)/', $page);
        $this->assertStringContainsString('VoMediaQueue.isIosDevice(navigator)', $page);
    }

    public function testThePageLoadsTheModuleBeforeItsOwnScript(): void
    {
        $page = $this->source('war-room.php');
        $utils = strpos($page, 'assets/js/war-room-utils.js');
        $queue = strpos($page, 'assets/js/media-queue.js');
        $inline = strpos($page, "const csrfToken = '");
        $this->assertNotFalse($queue);
        $this->assertGreaterThan($utils, $queue);
        $this->assertLessThan($inline, $queue, 'the inline script reads VoMediaQueue at load');
        $this->assertFileExists(__DIR__ . '/../assets/js/media-queue.js');
    }

    public function testEveryPlaceAFileIsSentGoesThroughTheQueue(): void
    {
        $page = $this->source('war-room.php');
        // The three senders: media panel, route point, point of interest.
        $this->assertSame(3, substr_count($page, 'sendMediaOrQueue(spec, pct =>'));
        // None of them builds its own form any more, so none can forget the
        // duplicate guard or the capture time.
        $this->assertStringNotContainsString("data.append('media'", $page);
        // A queued file is sent by the queue, in its own lane.
        $send = substr($page, (int) strpos($page, 'function sendQueuedItem('), 300);
        $this->assertStringContainsString("item.kind === 'media'", $send);
        $this->assertStringContainsString('VoMediaQueue.nextQueueIndex(queue, lane)', $page);
        // The file is stored BEFORE its pointer is queued.
        $keep = substr($page, (int) strpos($page, 'function keepMediaForLater('), 400);
        $this->assertLessThan(strpos($keep, 'enqueueAction('), strpos($keep, 'VoMediaQueue.put('));
        // A completion waits for its own file.
        $this->assertStringContainsString("action === 'complete' && VoMediaQueue.queuedMediaFor(loadQueue(), id).any", $page);
    }

    public function testTheEndpointAnswersAReplayOnceAndKeepsTheFieldTime(): void
    {
        $endpoint = $this->source('mission-photo.php');
        $this->assertStringContainsString("post('client_uuid')", $endpoint);
        $this->assertStringContainsString('client_uuid = ?', $endpoint);
        // The unique key's duplicate-entry answer (two tabs at once) is handled too.
        $this->assertStringContainsString('=== 1062', $endpoint);
        // The stored time is the one the phone reported, same helper as every other replay.
        $this->assertStringContainsString('resolveEventTimestamp()', $endpoint);
        $this->assertStringNotContainsString('poi_note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', $endpoint);
        // A stale page must be told to reload, not have its file thrown away.
        $this->assertStringContainsString("'csrf' => true", $endpoint);
        $this->assertStringContainsString('result && result.csrf', $this->source('war-room.php'));
    }

    public function testTheGuardColumnExistsOnEveryInstallPath(): void
    {
        $migrations = $this->source('includes/migrations.php');
        $this->assertMatchesRegularExpression("/'version'\s*=>\s*197,[^\]]*client_uuid/s", $migrations);
        $this->assertStringContainsString('uk_photo_client_uuid', $migrations);
        $schema = $this->source('sql/schema.sql');
        $this->assertStringContainsString('`client_uuid` VARCHAR(64) NULL', $schema);
        $this->assertStringContainsString('uk_photo_client_uuid', $schema);
        $this->assertGreaterThanOrEqual(197, DB_SCHEMA_VERSION);
    }

    public function testTheRedOfflineBarSaysPhotosAndVideosAreSaved(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $this->assertSame(2, preg_match_all("/'gui\.offline_bar_warroom'\s*=>/", $lang), 'one per language');
        preg_match_all("/'gui\.offline_bar_warroom'\s*=>\s*'([^\r\n]*)',\r?\n/", $lang, $m);
        $this->assertCount(2, $m[1]);
        [$greek, $english] = $m[1];
        // Photos and videos are queued now, so the bar must not say they are not.
        $this->assertStringContainsString('φωτογραφίες και βίντεο αποθηκεύονται', $greek);
        $this->assertStringNotContainsString('Φωτογραφίες και μηνύματα όχι', $greek);
        $this->assertStringContainsString('photos and videos are saved', $english);
        $this->assertStringNotContainsString('Photos and messages are not', $english);
        // Chat messages are still NOT queued, and the bar must keep saying so.
        $this->assertStringContainsString('μηνύματα όχι', $greek);
        $this->assertStringContainsString('Messages are not', $english);
    }

    public function testEveryNewStringExistsInGreekAndEnglish(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $page = $this->source('war-room.php');
        $keys = [
            'queue.media_pending', 'queue.media_failed', 'queue.media_missing', 'queue.media_rejected',
            'media.saved_offline', 'media.save_failed', 'route.media_queued',
        ];
        foreach ($keys as $key) {
            $this->assertSame(2, preg_match_all("/'" . preg_quote($key, '/') . "'\s*=>/", $lang), "$key is needed once per language");
            $this->assertStringContainsString("'$key'", $page, "$key is not used by the page");
        }
        // The banner interpolates a count.
        $this->assertSame(2, preg_match_all("/'queue\.media_pending'\s*=>[^\n]*\{count\}/", $lang));
        $this->assertSame(2, preg_match_all("/'queue\.media_failed'\s*=>[^\n]*\{error\}/", $lang));
    }
}
