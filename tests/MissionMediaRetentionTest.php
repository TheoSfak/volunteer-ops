<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Mission videos are deleted 30 days after the mission closes; photos stay.
 * This is the one destructive job in the media code, so the tests pin what it
 * must NOT touch as hard as what it must:
 *
 *   - a video of a mission that is still OPEN, however old,
 *   - a video of a mission that closed recently,
 *   - any photo, however old the mission,
 *   - another file that merely sits in the same directory.
 *
 * Files are real, in a throw-away temp directory (the function takes the
 * directory as a parameter), and every database row is rolled back.
 */
final class MissionMediaRetentionTest extends TestCase
{
    private string $dir;
    private int $adminId;
    private int $typeId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->dir = sys_get_temp_dir() . '/vo-media-test-' . uniqid('', true);
        mkdir($this->dir, 0775, true);
        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Media Admin', 'media-' . uniqid('', true) . '@example.invalid', 'x']
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

    private function mission(string $status, int $closedDaysAgo): int
    {
        return (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, created_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, DATE_SUB(NOW(), INTERVAL ? DAY))",
            ['Media Mission', 'Ηράκλειο', '2026-01-01 09:00:00', '2026-01-01 10:00:00', $this->typeId, $status, $this->adminId, $closedDaysAgo]
        );
    }

    /** Inserts a media row and, unless $withFile is false, the file it points at. */
    private function media(int $missionId, string $type, bool $withFile = true, ?string $thumb = null): array
    {
        $name = uniqid($type . '_', true) . ($type === 'photo' ? '.jpg' : '.mp4');
        if ($withFile) {
            file_put_contents($this->dir . '/' . $name, 'x');
        }
        if ($thumb !== null) {
            file_put_contents($this->dir . '/' . $thumb, 'poster');
        }
        $id = (int) dbInsert(
            "INSERT INTO mission_photos (mission_id, user_id, media_type, stored_name, thumb_stored_name, original_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
            [$missionId, $this->adminId, $type, $name, $thumb, $name, $type === 'photo' ? 'image/jpeg' : 'video/mp4']
        );
        return ['id' => $id, 'file' => $this->dir . '/' . $name];
    }

    private function purgedAt(int $mediaId): ?string
    {
        $v = dbFetchValue("SELECT file_purged_at FROM mission_photos WHERE id = ?", [$mediaId]);
        return $v === false || $v === null ? null : (string) $v;
    }

    public function testAVideoOfAMissionClosedLongAgoIsDeletedButItsPosterAndRowStay(): void
    {
        $m = $this->mission(STATUS_CLOSED, 40);
        $v = $this->media($m, 'video', true, 'poster.jpg');

        $r = purgeExpiredMissionVideos($this->dir, 200, 30);

        $this->assertSame(1, $r['deleted']);
        $this->assertFileDoesNotExist($v['file']);
        $this->assertNotNull($this->purgedAt($v['id']));
        $this->assertFileExists($this->dir . '/poster.jpg', 'the poster frame is kept');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_photos WHERE id = ?", [$v['id']]), 'the row is kept');
    }

    public function testCompletedMissionsAreCoveredToo(): void
    {
        $v = $this->media($this->mission(STATUS_COMPLETED, 31), 'video');
        $this->assertSame(1, purgeExpiredMissionVideos($this->dir, 200, 30)['deleted']);
        $this->assertFileDoesNotExist($v['file']);
    }

    public function testNothingThatShouldSurviveIsTouched(): void
    {
        $oldClosed  = $this->mission(STATUS_CLOSED, 90);
        $stillOpen  = $this->mission(STATUS_OPEN, 90);
        $justClosed = $this->mission(STATUS_CLOSED, 5);
        $photo       = $this->media($oldClosed, 'photo');
        $openVideo   = $this->media($stillOpen, 'video');
        $recentVideo = $this->media($justClosed, 'video');
        file_put_contents($this->dir . '/unrelated.txt', 'keep me');

        $r = purgeExpiredMissionVideos($this->dir, 200, 30);

        $this->assertSame(0, $r['deleted']);
        foreach ([$photo, $openVideo, $recentVideo] as $item) {
            $this->assertFileExists($item['file']);
            $this->assertNull($this->purgedAt($item['id']));
        }
        $this->assertFileExists($this->dir . '/unrelated.txt');
    }

    public function testTheWindowIsMeasuredFromTheClosingNotFromTheUpload(): void
    {
        // 29 days after closing: still kept. 31: gone.
        $kept = $this->media($this->mission(STATUS_CLOSED, 29), 'video');
        $gone = $this->media($this->mission(STATUS_CLOSED, 31), 'video');

        purgeExpiredMissionVideos($this->dir, 200, 30);

        $this->assertFileExists($kept['file']);
        $this->assertFileDoesNotExist($gone['file']);
    }

    public function testZeroDaysSwitchesTheSweepOff(): void
    {
        $v = $this->media($this->mission(STATUS_CLOSED, 400), 'video');
        $r = purgeExpiredMissionVideos($this->dir, 200, 0);
        $this->assertSame(0, $r['deleted']);
        $this->assertFileExists($v['file']);
        $this->assertNull($this->purgedAt($v['id']));
    }

    public function testASecondRunHasNothingLeftToDoAndAMissingFileIsJustMarked(): void
    {
        $m = $this->mission(STATUS_CLOSED, 60);
        $this->media($m, 'video');
        $already = $this->media($m, 'video', false);

        $first = purgeExpiredMissionVideos($this->dir, 200, 30);
        $this->assertSame(1, $first['deleted']);
        $this->assertSame(1, $first['already_gone']);
        $this->assertNotNull($this->purgedAt($already['id']), 'a file that is already gone is marked, so it is not retried forever');

        $second = purgeExpiredMissionVideos($this->dir, 200, 30);
        $this->assertSame(0, $second['deleted'] + $second['already_gone'] + $second['failed']);
    }

    public function testAStoredNameCannotLeadOutOfTheDirectory(): void
    {
        $outside = sys_get_temp_dir() . '/vo-outside-' . uniqid('', true) . '.mp4';
        file_put_contents($outside, 'must survive');
        $m = $this->mission(STATUS_CLOSED, 60);
        dbInsert(
            "INSERT INTO mission_photos (mission_id, user_id, media_type, stored_name, original_name, mime_type, file_size) VALUES (?, ?, 'video', ?, 'x', 'video/mp4', 1)",
            [$m, $this->adminId, '../' . basename($outside)]
        );

        purgeExpiredMissionVideos($this->dir, 200, 30);

        $this->assertFileExists($outside, 'basename() keeps the delete inside the media directory');
        @unlink($outside);
    }

    public function testTheLimitBoundsOneRun(): void
    {
        $m = $this->mission(STATUS_CLOSED, 60);
        for ($i = 0; $i < 3; $i++) {
            $this->media($m, 'video');
        }
        $this->assertSame(2, purgeExpiredMissionVideos($this->dir, 2, 30)['deleted']);
        $this->assertSame(1, purgeExpiredMissionVideos($this->dir, 2, 30)['deleted'], 'the next run carries on');
    }

    public function testTheArchiveListSaysWhichVideosAreGoneAndWhenTheOthersWillBe(): void
    {
        $m = $this->mission(STATUS_CLOSED, 40);
        $gone = $this->media($m, 'video');
        $photo = $this->media($m, 'photo');
        purgeExpiredMissionVideos($this->dir, 200, 30);

        $byId = [];
        foreach (loadMissionMediaForArchive($m) as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertTrue($byId[$gone['id']]['purged']);
        $this->assertNull($byId[$gone['id']]['expires'], 'a deleted video has no deletion date left');
        $this->assertFalse($byId[$photo['id']]['purged']);
        $this->assertNull($byId[$photo['id']]['expires'], 'photos are never deleted');

        // A still-waiting video carries the date it will go: closing + the window.
        $m2 = $this->mission(STATUS_CLOSED, 10);
        $waiting = $this->media($m2, 'video');
        $item = loadMissionMediaForArchive($m2)[0];
        $this->assertFalse($item['purged']);
        $days = missionVideoRetentionDays();
        if ($days > 0) {
            $this->assertSame(date('d/m/Y', strtotime("+" . ($days - 10) . " days")), $item['expires']);
        }
        $this->assertSame($waiting['id'], $item['id']);
    }
}
