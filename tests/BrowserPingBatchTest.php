<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Positions a browser tab or an iPhone kept while it had no signal (v3.425.0).
 *
 * The page queues a position it could not send and replays the backlog in one
 * request to ping-location.php, which hands it to recordBrowserPingBatch(). The
 * Android app has done this since v3.322.0 (recordNativePingBatch(), tested in
 * GpsFixIntegrityTest); a browser has no background service, so until now a
 * fix it could not send was simply dropped and the trail had a hole in it.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class BrowserPingBatchTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $volunteerId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Browser Batch Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Browser Batch Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->volunteerId = $this->makeUser('Phone P.');
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $this->volunteerId, PARTICIPATION_APPROVED]
        );
        forgetActionRoomParticipantIds($this->missionId);
        setActionRoomParticipation($this->missionId, $this->volunteerId, true, $this->adminId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActionRoomParticipantIds($this->missionId);
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'browserbatch-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function user(): array
    {
        return dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->volunteerId]);
    }

    private function northOf(float $lat, float $metres): float
    {
        return $lat + $metres / 111320.0;
    }

    /** One queued position, as the page sends it. */
    private function fix(float $metresNorth, int $ageSeconds, array $over = []): array
    {
        return $over + ['lat' => $this->northOf(35.33, $metresNorth), 'lng' => 25.13, 'accuracy' => 8.0,
                        'battery_level' => 70, 'fix_age_ms' => $ageSeconds * 1000, 'speed' => 1.2, 'source' => 'auto'];
    }

    private function rows(): array
    {
        return dbFetchAll(
            "SELECT lat, via, source, UNIX_TIMESTAMP(created_at) AS t FROM volunteer_pings WHERE user_id = ? AND shift_id = ? ORDER BY id",
            [$this->volunteerId, $this->shiftId]
        );
    }

    public function testAFiveMinuteOutageArrivesWholeAndAtTheTimesTheFixesWereTaken(): void
    {
        // Eleven fixes 30 s apart while the phone could not send, walking north
        // at 1.2 m/s (36 m every 30 s), all delivered in one request.
        $backlog = [];
        for ($i = 0; $i < 11; $i++) {
            $backlog[] = $this->fix(($i + 1) * 36, 330 - $i * 30);
        }
        $results = recordBrowserPingBatch($this->user(), $this->shiftId, $backlog);

        $this->assertCount(11, $results);
        foreach ($results as $i => $r) {
            $this->assertTrue($r['ok'], "fix $i refused: " . ($r['error'] ?? ''));
            $this->assertArrayNotHasKey('skipped', $r, "fix $i skipped");
        }
        $rows = $this->rows();
        $this->assertCount(11, $rows);
        $this->assertSame(['browser'], array_values(array_unique(array_column($rows, 'via'))));
        for ($i = 1; $i < 11; $i++) {
            $this->assertEqualsWithDelta(30, (int) $rows[$i]['t'] - (int) $rows[$i - 1]['t'], 1, 'each stored 30 s after the one before');
        }
        // A walk through a dead zone is not an impossible jump.
        $this->assertNull(dbFetchValue(
            "SELECT last_gps_error FROM mission_action_room_participants WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $this->volunteerId]
        ) ?: null);
    }

    public function testABacklogSentNewestFirstIsStillStoredInOrder(): void
    {
        // The write path skips a fix older than the newest one stored, so a
        // newest-first backlog would have lost everything but its first fix.
        $backlog = [$this->fix(72, 0), $this->fix(36, 30), $this->fix(0, 60)];
        $results = recordBrowserPingBatch($this->user(), $this->shiftId, $backlog);
        foreach ($results as $i => $r) {
            $this->assertTrue($r['ok'], "fix $i: " . ($r['error'] ?? ''));
            $this->assertArrayNotHasKey('skipped', $r, "fix $i skipped");
        }
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertGreaterThan((int) $rows[0]['t'], (int) $rows[2]['t']);
        $this->assertGreaterThan((float) $rows[0]['lat'], (float) $rows[2]['lat'], 'the freshest fix, the furthest north, is stored last');
    }

    public function testEachFixInABacklogIsJudgedOnItsOwn(): void
    {
        // The middle fix claims to be 200 m out: refused. The ones around it land.
        $backlog = [$this->fix(0, 60), $this->fix(30, 30, ['accuracy' => 200.0]), $this->fix(60, 0)];
        $results = recordBrowserPingBatch($this->user(), $this->shiftId, $backlog);
        $this->assertTrue($results[0]['ok']);
        $this->assertFalse($results[1]['ok'] ?? true, 'the imprecise fix is refused');
        $this->assertTrue($results[2]['ok'], 'and the fix after it still lands');
        $this->assertCount(2, $this->rows());
    }

    public function testAFixOlderThanHalfAnHourIsDroppedWithoutFlaggingThePhone(): void
    {
        $results = recordBrowserPingBatch($this->user(), $this->shiftId, [$this->fix(0, WAR_ROOM_MAX_FIX_AGE_SECONDS + 120), $this->fix(0, 10)]);
        $this->assertFalse($results[0]['ok'], 'history is not a position');
        $this->assertTrue($results[1]['ok']);
        $this->assertCount(1, $this->rows());
        $this->assertNull(dbFetchValue(
            "SELECT last_gps_error FROM mission_action_room_participants WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $this->volunteerId]
        ) ?: null, 'nothing is wrong with the phone now');
    }

    public function testABacklogIsCappedAndMalformedEntriesAreRefusedNotFatal(): void
    {
        $results = recordBrowserPingBatch($this->user(), $this->shiftId, array_fill(0, BROWSER_PING_BATCH_MAX + 20, 'not a fix'));
        $this->assertCount(BROWSER_PING_BATCH_MAX, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertCount(0, $this->rows());
        $this->assertSame([], recordBrowserPingBatch($this->user(), $this->shiftId, []));
    }

    public function testAnotherPersonsShiftIsRefusedForTheWholeBatch(): void
    {
        // A batch names one shift; a person who is not approved on it gets
        // every fix refused, whatever the page claims.
        $stranger = $this->makeUser('Not On This Shift');
        $results = recordBrowserPingBatch(dbFetchOne("SELECT * FROM users WHERE id = ?", [$stranger]), $this->shiftId, [$this->fix(0, 30), $this->fix(10, 0)]);
        foreach ($results as $r) {
            $this->assertFalse($r['ok']);
        }
        $this->assertCount(0, $this->rows());
    }

    public function testAManualTapInABatchIsStoredAsManual(): void
    {
        recordBrowserPingBatch($this->user(), $this->shiftId, [$this->fix(0, 20, ['source' => 'manual'])]);
        $this->assertSame(['manual'], array_column($this->rows(), 'source'));
    }

    public function testTheEndpointHandsABatchToTheBatchFunctionAndFlagsAStaleToken(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../ping-location.php');
        $this->assertStringContainsString("post('batch')", $endpoint);
        $this->assertStringContainsString('recordBrowserPingBatch($user, $shiftId, $items)', $endpoint);
        $this->assertStringContainsString("'csrf' => true", $endpoint);
        // A batch is answered BEFORE the single-fix path reads lat/lng.
        $this->assertLessThan(strpos($endpoint, "(float) post('lat')"), strpos($endpoint, 'recordBrowserPingBatch'));
    }
}
