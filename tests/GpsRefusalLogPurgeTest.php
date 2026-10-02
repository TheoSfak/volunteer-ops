<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * purgeGpsRefusalLog() is the manual "Καθαρισμός Log GPS" button. Deleting
 * refusal rows must also raise gps_refusal_log_since, otherwise
 * loadMissionGpsGaps() reads "no refusals logged" for a gap whose refusals
 * were just deleted and tells the coordinator the phone sent nothing.
 */
final class GpsRefusalLogPurgeTest extends TestCase
{
    private int $missionId;
    private int $userId;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../includes/functions-gps-quality.php';
        db()->beginTransaction();
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Refusal purge', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $typeId, STATUS_OPEN]
        );
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Purge Walker', 'purge-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function refusal(int $daysAgo): void
    {
        dbExecute(
            "INSERT INTO volunteer_ping_refusal_log (mission_id, user_id, reason, fix_at)
             VALUES (?, ?, 'imprecise', DATE_SUB(NOW(), INTERVAL ? DAY))",
            [$this->missionId, $this->userId, $daysAgo]
        );
    }

    private function remaining(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_ping_refusal_log WHERE mission_id = ?", [$this->missionId]);
    }

    private function since(): ?string
    {
        $v = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'gps_refusal_log_since'");
        return $v === false || $v === null ? null : (string) $v;
    }

    private function setSince(?string $value): void
    {
        dbExecute("DELETE FROM settings WHERE setting_key = 'gps_refusal_log_since'");
        if ($value !== null) {
            dbExecute("INSERT INTO settings (setting_key, setting_value) VALUES ('gps_refusal_log_since', ?)", [$value]);
        }
    }

    public function testRowsOlderThanTheCutoffGoAndNewerOnesStay(): void
    {
        $this->setSince(date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->refusal(10);
        $this->refusal(8);
        $this->refusal(6);
        $this->refusal(1);

        $r = purgeGpsRefusalLog(7);

        $this->assertSame(2, $r['deleted']);
        $this->assertTrue($r['complete']);
        $this->assertSame(2, $this->remaining());
    }

    public function testLogSinceIsRaisedToTheCutoffSoOlderGapsAreNotJudged(): void
    {
        $this->setSince(date('Y-m-d H:i:s', time() - 30 * 86400));
        $this->refusal(10);

        $r = purgeGpsRefusalLog(7);

        $this->assertSame(1, $r['deleted']);
        $this->assertSame($r['cutoff'], $this->since());
        $this->assertGreaterThan(time() - 8 * 86400, strtotime((string) $this->since()));
    }

    public function testLogSinceIsCreatedWhenThereWasNone(): void
    {
        $this->setSince(null);
        $this->refusal(10);

        $r = purgeGpsRefusalLog(7);

        $this->assertSame(1, $r['deleted']);
        $this->assertSame($r['cutoff'], $this->since());
    }

    public function testLogSinceIsNeverLowered(): void
    {
        $recent = date('Y-m-d H:i:s', time() - 3600);
        $this->setSince($recent);
        $this->refusal(10);   // rows older than the log's own start can exist (imports, clock skew)

        purgeGpsRefusalLog(7);

        $this->assertSame($recent, $this->since());
    }

    public function testNothingToDeleteLeavesTheSettingAlone(): void
    {
        $since = date('Y-m-d H:i:s', time() - 30 * 86400);
        $this->setSince($since);
        $this->refusal(2);

        $r = purgeGpsRefusalLog(7);

        $this->assertSame(0, $r['deleted']);
        $this->assertSame($since, $this->since());
        $this->assertSame(1, $this->remaining());
    }

    public function testItWorksInChunks(): void
    {
        $this->setSince(date('Y-m-d H:i:s', time() - 30 * 86400));
        for ($i = 0; $i < 7; $i++) {
            $this->refusal(9);
        }
        $this->refusal(1);

        $r = purgeGpsRefusalLog(7, 20, 3);

        $this->assertSame(7, $r['deleted']);
        $this->assertSame(1, $this->remaining());
    }
}
