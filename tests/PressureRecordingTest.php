<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The air pressure the Android app sends with a fix (v3.426.0).
 *
 * The point of the design, and of these tests: the reading is stored on its
 * own, BEFORE the fix is judged. Indoors a GPS fix is refused as an
 * implausible jump or stops altogether, and that is exactly when the floor is
 * wanted - a pressure that rode on the fix would vanish with it.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class PressureRecordingTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $volunteerId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Pressure Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Pressure Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->volunteerId = $this->makeUser('Barometer P.');
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
            [$name, 'pressure-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function user(?int $id = null): array
    {
        return dbFetchOne("SELECT * FROM users WHERE id = ?", [$id ?? $this->volunteerId]);
    }

    /** One item as the native service posts it. */
    private function item(array $over = []): array
    {
        return $over + ['latitude' => 35.33, 'longitude' => 25.13, 'accuracy' => 8.0, 'battery_level' => 70, 'pressure_hpa' => 1013.25];
    }

    private function pressures(?int $userId = null): array
    {
        return dbFetchAll(
            "SELECT hpa, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s FROM volunteer_pressure
             WHERE mission_id = ? AND user_id = ? ORDER BY id",
            [$this->missionId, $userId ?? $this->volunteerId]
        );
    }

    private function pingCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ? AND shift_id = ?", [$this->volunteerId, $this->shiftId]);
    }

    public function testPressureIsAcceptedOnlyWhereAirCanBe(): void
    {
        $this->assertSame(1013.25, parsePressureHpa(1013.25));
        $this->assertSame(1013.26, parsePressureHpa('1013.256'), 'rounded to 0.01 hPa');
        $this->assertSame(300.0, parsePressureHpa(300));
        $this->assertSame(1100.0, parsePressureHpa(1100));
        foreach ([null, '', 'abc', true, false, 0, -1013.0, 299.99, 1100.01, 99999, '1e9', [], 'NaN'] as $bad) {
            $this->assertNull(parsePressureHpa($bad), 'rejected, never clamped: ' . var_export($bad, true));
        }
    }

    public function testNotKnownIsNeverTurnedIntoNoBarometer(): void
    {
        $this->assertSame(1, parseHasBarometer(1));
        $this->assertSame(1, parseHasBarometer('1'));
        $this->assertSame(0, parseHasBarometer(0));
        $this->assertSame(0, parseHasBarometer(false));
        $this->assertNull(parseHasBarometer(null));
        $this->assertNull(parseHasBarometer(''));
        $this->assertNull(parseHasBarometer('yes'));
        $this->assertNull(parseHasBarometer(2));
    }

    public function testAFixWithPressureStoresBoth(): void
    {
        $r = recordNativePingFromJson($this->user(), $this->shiftId, $this->item());
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, $this->pingCount());
        $rows = $this->pressures();
        $this->assertCount(1, $rows);
        $this->assertSame(1013.25, (float) $rows[0]['hpa']);
    }

    public function testAFixWithoutPressureStoresNoPressureRow(): void
    {
        $r = recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['pressure_hpa' => null]));
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->pingCount());
        $this->assertSame([], $this->pressures());
        // And an older app that never heard of the field behaves the same.
        $body = $this->item();
        unset($body['pressure_hpa']);
        $this->assertTrue(recordNativePingFromJson($this->user(), $this->shiftId, $body)['ok']);
        $this->assertSame([], $this->pressures());
    }

    public function testThePressureIsStoredEvenWhenTheFixBesideItIsRefused(): void
    {
        // A fake-GPS flag turns the fix away outright.
        $r = recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['simulated' => true, 'pressure_hpa' => 1012.4]));
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->pingCount());
        $rows = $this->pressures();
        $this->assertCount(1, $rows);
        $this->assertSame(1012.4, (float) $rows[0]['hpa']);
    }

    public function testThePressureIsStoredWhenTheFixIsAnImplausibleJump(): void
    {
        // Inside a building a fix lands 30 km away a second after the last good
        // one: refused. The reading from the same moment must survive.
        $first = recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['fix_age_ms' => 60000, 'pressure_hpa' => 1013.0]));
        $this->assertTrue($first['ok'], $first['error'] ?? '');
        $jump = recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['latitude' => 35.60, 'fix_age_ms' => 0, 'pressure_hpa' => 1012.6]));
        $this->assertFalse($jump['ok'], 'the jump is refused');
        $this->assertSame(1, $this->pingCount());
        $this->assertSame([1013.0, 1012.6], array_map(fn($r) => (float) $r['hpa'], $this->pressures()));
    }

    public function testTheRowIsStampedWithWhenTheReadingWasTaken(): void
    {
        recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['fix_age_ms' => 240000]));
        $rows = $this->pressures();
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(240, (int) $rows[0]['age_s'], 2, 'four minutes old when it was sent');
    }

    public function testAReadingOlderThanHalfAnHourIsHistoryNotAReading(): void
    {
        recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['fix_age_ms' => 1801 * 1000]));
        $this->assertSame([], $this->pressures());
    }

    public function testAnImpossiblePressureIsDroppedButTheFixStillLands(): void
    {
        foreach ([0, 5000, -3, 'junk'] as $bad) {
            $r = recordNativePingFromJson($this->user(), $this->shiftId, $this->item(['pressure_hpa' => $bad]));
            $this->assertTrue($r['ok'], 'the fix does not depend on the pressure');
        }
        $this->assertSame([], $this->pressures());
    }

    public function testAPressureOnlyItemIsKeptAndNeverBecomesAPing(): void
    {
        // The app's own tick when it has had no fix for a while.
        $r = recordNativePingFromJson($this->user(), $this->shiftId, ['pressure_hpa' => 1011.8, 'fix_age_ms' => 5000, 'has_baro' => 1]);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['pressure_only']);
        $this->assertTrue($r['stored']);
        $this->assertSame(0, $this->pingCount(), 'no coordinates, so no position');
        $this->assertCount(1, $this->pressures());
    }

    public function testABodyWithNeitherCoordinatesNorPressureIsStillAnInvalidFix(): void
    {
        $r = recordNativePingFromJson($this->user(), $this->shiftId, ['accuracy' => 5]);
        $this->assertFalse($r['ok']);
        $this->assertArrayNotHasKey('pressure_only', $r);
    }

    public function testBatchesCarryPressureOnlyItemsAndFixesInOrder(): void
    {
        $results = recordNativePingBatch($this->user(), $this->shiftId, [
            $this->item(['fix_age_ms' => 120000, 'pressure_hpa' => 1013.0]),
            ['pressure_hpa' => 1012.5, 'fix_age_ms' => 90000],
            ['pressure_hpa' => 1012.0, 'fix_age_ms' => 60000],
            $this->item(['fix_age_ms' => 0, 'pressure_hpa' => 1011.5]),
        ]);
        $this->assertCount(4, $results);
        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['pressure_only']);
        $this->assertTrue($results[2]['pressure_only']);
        $this->assertTrue($results[3]['ok'], $results[3]['error'] ?? '');
        $this->assertSame(2, $this->pingCount());
        $this->assertSame([1013.0, 1012.5, 1012.0, 1011.5], array_map(fn($r) => (float) $r['hpa'], $this->pressures()));
    }

    public function testSomebodyNotInTheActionRoomHasNoPressureStored(): void
    {
        $other = $this->makeUser('Not Ticked');
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $other, PARTICIPATION_APPROVED]);
        $r = recordNativePingFromJson($this->user($other), $this->shiftId, $this->item());
        $this->assertFalse($r['ok'], 'the fix is refused');
        $this->assertSame([], $this->pressures($other), 'and so is the reading: an untracked person is not tracked by their barometer either');
    }

    public function testSomebodyNotApprovedOnTheShiftHasNoPressureStored(): void
    {
        $stranger = $this->makeUser('Stranger');
        setActionRoomParticipation($this->missionId, $stranger, true, $this->adminId);
        $r = recordNativePingFromJson($this->user($stranger), $this->shiftId, ['pressure_hpa' => 1010.0]);
        $this->assertTrue($r['ok'], 'a pressure-only item is always answered, so the phone does not keep it');
        $this->assertFalse($r['stored']);
        $this->assertSame([], $this->pressures($stranger));
    }

    public function testAClosedMissionTakesNoPressure(): void
    {
        dbExecute("UPDATE missions SET status = ? WHERE id = ?", [STATUS_COMPLETED, $this->missionId]);
        $r = recordNativePingFromJson($this->user(), $this->shiftId, ['pressure_hpa' => 1010.0]);
        $this->assertFalse($r['stored']);
        $this->assertSame([], $this->pressures());
    }
}
