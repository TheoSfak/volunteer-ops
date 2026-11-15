<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * mission_highlight_videos: one row per mission (UNIQUE KEY on mission_id).
 * saveMissionHighlightVideo() replaces whatever was there; the 30-day sweep
 * (purgeExpiredMissionHighlightVideos()) deletes the row AND the file outright
 * once it's old enough - unlike the raw-video retention sweep, there is no
 * gallery value in a dead row here.
 *
 * Files are real, in a throw-away temp directory (the purge function takes the
 * directory as a parameter); every database row is rolled back.
 */
final class MissionHighlightVideoTest extends TestCase
{
    private string $dir;
    private int $adminId;
    private int $typeId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->dir = sys_get_temp_dir() . '/vo-hvideo-test-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Highlight Admin', 'hvideo-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $this->typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function mission(): int
    {
        return (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Highlight Mission', 'Ηράκλειο', '2026-01-01 09:00:00', '2026-01-01 10:00:00', $this->typeId, STATUS_OPEN, $this->adminId]
        );
    }

    private function placeFile(string $storedName): string
    {
        $path = $this->dir . '/' . $storedName;
        file_put_contents($path, 'webm-bytes');
        return $path;
    }

    public function testSavingReplacesThePreviousVideoFileAndRow(): void
    {
        $missionId = $this->mission();
        $this->placeFile('first.webm');
        saveMissionHighlightVideo($missionId, $this->adminId, 'first.webm', 'landscape', 'track-1');

        $this->placeFile('second.webm');
        saveMissionHighlightVideo($missionId, $this->adminId, 'second.webm', 'portrait', 'track-2');

        // The actual delete-old-file step lives in mission-highlight-video.php's
        // delete action and the purge sweep, not in saveMissionHighlightVideo()
        // itself (it only touches the DB row) - but there must only ever be one row.
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_highlight_videos WHERE mission_id = ?", [$missionId]));
        $video = loadMissionHighlightVideo($missionId);
        $this->assertSame('second.webm', $video['stored_name']);
        $this->assertSame('portrait', $video['orientation']);
        $this->assertSame('track-2', $video['music_track_id']);
    }

    public function testLoadReturnsNullWhenNoneExists(): void
    {
        $this->assertNull(loadMissionHighlightVideo($this->mission()));
    }

    public function testOldVideosAreDeletedFileAndRow(): void
    {
        $missionId = $this->mission();
        $this->placeFile('old.webm');
        $id = saveMissionHighlightVideo($missionId, $this->adminId, 'old.webm', 'landscape', null);
        dbExecute("UPDATE mission_highlight_videos SET created_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = ?", [$id]);

        $r = purgeExpiredMissionHighlightVideos($this->dir, 200, 30);

        $this->assertSame(1, $r['deleted']);
        $this->assertFileDoesNotExist($this->dir . '/old.webm');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_highlight_videos WHERE mission_id = ?", [$missionId]), 'the row is gone too, unlike the raw-video retention sweep');
    }

    public function testARecentVideoSurvives(): void
    {
        $missionId = $this->mission();
        $this->placeFile('recent.webm');
        saveMissionHighlightVideo($missionId, $this->adminId, 'recent.webm', 'landscape', null);

        $r = purgeExpiredMissionHighlightVideos($this->dir, 200, 30);

        $this->assertSame(0, $r['deleted']);
        $this->assertFileExists($this->dir . '/recent.webm');
        $this->assertNotNull(loadMissionHighlightVideo($missionId));
    }

    public function testZeroDaysSwitchesTheSweepOff(): void
    {
        $missionId = $this->mission();
        $this->placeFile('old.webm');
        $id = saveMissionHighlightVideo($missionId, $this->adminId, 'old.webm', 'landscape', null);
        dbExecute("UPDATE mission_highlight_videos SET created_at = DATE_SUB(NOW(), INTERVAL 400 DAY) WHERE id = ?", [$id]);

        $r = purgeExpiredMissionHighlightVideos($this->dir, 200, 0);

        $this->assertSame(0, $r['deleted']);
        $this->assertFileExists($this->dir . '/old.webm');
    }

    public function testAnAlreadyMissingFileIsCountedSeparatelyAndTheRowStillGoes(): void
    {
        $missionId = $this->mission();
        // No placeFile(): the row points at a file that was never written.
        $id = saveMissionHighlightVideo($missionId, $this->adminId, 'gone.webm', 'landscape', null);
        dbExecute("UPDATE mission_highlight_videos SET created_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = ?", [$id]);

        $r = purgeExpiredMissionHighlightVideos($this->dir, 200, 30);

        $this->assertSame(1, $r['already_gone']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_highlight_videos WHERE mission_id = ?", [$missionId]));
    }

    public function testAStoredNameCannotLeadOutOfTheDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/vo-hvideo-outside-' . uniqid('', true) . '.webm';
        file_put_contents($outside, 'must survive');
        $missionId = $this->mission();
        $id = saveMissionHighlightVideo($missionId, $this->adminId, '../' . basename($outside), 'landscape', null);
        dbExecute("UPDATE mission_highlight_videos SET created_at = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE id = ?", [$id]);

        purgeExpiredMissionHighlightVideos($this->dir, 200, 30);

        $this->assertFileExists($outside, 'basename() keeps the delete inside the media directory');
        @unlink($outside);
    }
}
