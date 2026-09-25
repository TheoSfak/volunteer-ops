<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the server does with a position once it arrives (v3.320.0).
 *
 * Every scenario below is a real way a correct position used to be stored
 * wrong, or a wrong one stored as correct:
 *
 *  - the Android app queues fixes while it has no signal and sends them in a
 *    burst; stamped with their ARRIVAL time, a position minutes old was drawn
 *    as current and a walk looked like an impossible jump;
 *  - two fixes arriving in the same second skipped the speed gate entirely;
 *  - inside the app the page and the native service both reported, so every
 *    position was written twice from two independent receivers;
 *  - a fake-GPS app's positions were accepted like any other.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class GpsFixIntegrityTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $volunteerId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('GPS Integrity Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['GPS Integrity Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->volunteerId = $this->makeUser('Walker W.');
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
            [$name, 'gpsfix-' . uniqid('', true) . '@example.invalid', 'x']
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

    /** Insert a ping directly, $secondsAgo in the past, bypassing every gate. */
    private function seedPing(float $lat, int $secondsAgo, ?string $via, float $acc = 8.0): void
    {
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, ?, 25.13, ?, 'auto', ?, DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$this->volunteerId, $this->shiftId, $lat, $acc, $via, $secondsAgo]
        );
    }

    private function pingCount(): int
    {
        return (int) dbFetchValue(
            "SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ? AND shift_id = ?",
            [$this->volunteerId, $this->shiftId]
        );
    }

    private function gpsError(): ?string
    {
        return dbFetchValue(
            "SELECT last_gps_error FROM mission_action_room_participants WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $this->volunteerId]
        ) ?: null;
    }

    // ── The offline queue ───────────────────────────────────────────────────

    public function testAQueueReplayedAfterAnOutageIsStoredAtTheTimesTheFixesWereTaken(): void
    {
        // Eight fixes, 20s apart, walking north at 1.2 m/s, all arriving now.
        // With arrival-time stamping, fix 2 onwards was "24m in one second",
        // 86 km/h, and refused as a GPS glitch.
        $lat = 35.33;
        for ($i = 0; $i < 8; $i++) {
            $ageMs = (7 - $i) * 20000;
            $result = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf($lat, $i * 24), 25.13, 8.0, 80, 'auto', 'native', $ageMs);
            $this->assertTrue($result['ok'], "queued fix {$i} was refused: " . ($result['error'] ?? ''));
            $this->assertArrayNotHasKey('skipped', $result, "queued fix {$i} was skipped");
        }

        $this->assertSame(8, $this->pingCount());
        $span = (int) dbFetchValue(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), MAX(created_at)) FROM volunteer_pings WHERE user_id = ? AND shift_id = ?",
            [$this->volunteerId, $this->shiftId]
        );
        $this->assertEqualsWithDelta(140, $span, 2, 'the stored times must span the 140s over which the fixes were taken');
        $newestAge = (int) dbFetchValue(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM volunteer_pings WHERE user_id = ? AND shift_id = ? ORDER BY id DESC LIMIT 1",
            [$this->volunteerId, $this->shiftId]
        );
        $this->assertLessThanOrEqual(2, $newestAge, 'the freshest fix is current');
        $this->assertNull($this->gpsError(), 'a walk must not flag the phone as producing impossible jumps');
    }

    // ── The backlog kept through a signal outage (v3.322.0) ─────────────────

    /** A native JSON fix, as BackgroundGeolocationService.locationToJson() sends it. */
    private function nativeFix(float $metresNorth, int $ageMs, bool $mock = false): array
    {
        return ['latitude' => $this->northOf(35.33, $metresNorth), 'longitude' => 25.13, 'accuracy' => 8.0,
                'speed' => 1.2, 'simulated' => $mock, 'fix_age_ms' => $ageMs, 'battery_level' => 70];
    }

    public function testAFiveMinuteOutageArrivesWholeInOrderAndAtItsOwnTimes(): void
    {
        // The last position before the gorge, then the fixes taken during five
        // and a half minutes without signal, walking north at 1.2 m/s (36m
        // every 30s), all delivered in one request once signal returns.
        $this->seedPing(35.33, 360, 'native');
        $backlog = [];
        for ($i = 0; $i < 12; $i++) {
            $backlog[] = $this->nativeFix(($i + 1) * 36, (330 - $i * 30) * 1000);
        }
        $results = recordNativePingBatch(dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->volunteerId]), $this->shiftId, $backlog);

        $this->assertCount(12, $results);
        foreach ($results as $i => $r) {
            $this->assertTrue($r['ok'], "fix $i refused: " . ($r['error'] ?? ''));
            $this->assertArrayNotHasKey('skipped', $r, "fix $i skipped");
        }
        $this->assertSame(13, $this->pingCount());
        $times = array_map('intval', array_column(dbFetchAll(
            "SELECT UNIX_TIMESTAMP(created_at) AS t FROM volunteer_pings WHERE user_id = ? AND shift_id = ? ORDER BY id",
            [$this->volunteerId, $this->shiftId]
        ), 't'));
        for ($i = 2; $i < 13; $i++) {
            $this->assertEqualsWithDelta(30, $times[$i] - $times[$i - 1], 1, 'each fix stored 30s after the one before');
        }
        $this->assertNull($this->gpsError(), 'a walk through a dead zone is not an impossible jump');
    }

    public function testEachFixInABacklogIsJudgedOnItsOwn(): void
    {
        $backlog = [$this->nativeFix(0, 60000), $this->nativeFix(30, 30000, true), $this->nativeFix(60, 0)];
        $results = recordNativePingBatch(dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->volunteerId]), $this->shiftId, $backlog);
        $this->assertTrue($results[0]['ok']);
        $this->assertFalse($results[1]['ok'], 'the mock fix is refused');
        $this->assertTrue($results[2]['ok'], 'and the fix after it still lands');
        $this->assertSame(2, $this->pingCount());
    }

    public function testABacklogIsCappedAndMalformedEntriesAreRefusedNotFatal(): void
    {
        $backlog = array_fill(0, NATIVE_PING_BATCH_MAX + 20, 'not a fix');
        $results = recordNativePingBatch(dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->volunteerId]), $this->shiftId, $backlog);
        $this->assertCount(NATIVE_PING_BATCH_MAX, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertSame(0, $this->pingCount());
    }

    public function testAClientThatSendsNoFixAgeIsStampedWithTheArrivalTimeAsBefore(): void
    {
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.33, 25.13, 8.0, 80, 'auto', 'native');
        $this->assertTrue($result['ok']);
        $age = (int) dbFetchValue(
            "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) FROM volunteer_pings WHERE user_id = ? AND shift_id = ?",
            [$this->volunteerId, $this->shiftId]
        );
        $this->assertLessThanOrEqual(2, $age);
    }

    public function testAFixOlderThanHalfAnHourIsDroppedWithoutFlaggingThePhone(): void
    {
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.33, 25.13, 8.0, 80, 'auto', 'native', (WAR_ROOM_MAX_FIX_AGE_SECONDS + 60) * 1000);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->pingCount());
        $this->assertNull($this->gpsError(), 'nothing is wrong with the phone now');
    }

    public function testAFixOlderThanTheNewestStoredOneIsNotStored(): void
    {
        // Keeps "highest id = latest position" true for the map pin and every
        // MAX(id) lookup.
        $this->seedPing(35.33, 5, 'native');
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.3301, 25.13, 8.0, 80, 'manual', 'browser', 30000);
        $this->assertTrue($result['ok']);
        $this->assertSame('older_than_latest', $result['skipped'] ?? null);
        $this->assertSame(1, $this->pingCount());
    }

    // ── The speed gate ──────────────────────────────────────────────────────

    public function testTwoFixesInTheSameSecondAreStillJudged(): void
    {
        // Used to be `$elapsed > 0` — a same-second pair skipped the gate.
        $this->seedPing(35.33, 0, 'browser');
        $result = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.33, 200), 25.13, 8.0, 80, 'manual', 'browser', 0);
        $this->assertFalse($result['ok']);
        $this->assertSame('implausible', $this->gpsError());
    }

    public function testTheSpeedGateMeasuresBetweenFixTimesNotArrivals(): void
    {
        // Stored fix 100s ago; a fix TAKEN 90s ago, 60m further on, arriving
        // now. Between the fixes: 60m in 10s = 21.6 km/h, a run — allowed.
        // Judged by arrival it would be 60m in 100s; judged by fix time it is
        // what actually happened. Pinned both ways: 150m in those same 10s is
        // 54 km/h and must be refused, which arrival time (5.4 km/h) missed.
        $this->seedPing(35.33, 100, 'native');
        $ok = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.33, 60), 25.13, 8.0, 80, 'auto', 'native', 90000);
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
        $this->assertArrayNotHasKey('skipped', $ok);

        $this->seedPing(35.34, 100, 'native');
        dbExecute("DELETE FROM volunteer_pings WHERE user_id = ? AND shift_id = ? AND lat < 35.339", [$this->volunteerId, $this->shiftId]);
        $bad = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.34, 150), 25.13, 8.0, 80, 'auto', 'native', 90000);
        $this->assertFalse($bad['ok']);
        $this->assertSame('implausible', $this->gpsError());
    }

    // ── One phone, one stream ───────────────────────────────────────────────

    public function testThePagesAutomaticFixIsNotStoredWhileTheNativeServiceIsDelivering(): void
    {
        $this->seedPing(35.33, 5, 'native');
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.3301, 25.13, 9.0, 80, 'auto', 'browser', 1000);
        $this->assertTrue($result['ok']);
        $this->assertSame('native_active', $result['skipped'] ?? null);
        $this->assertSame(1, $this->pingCount());
    }

    public function testAPoorFixFromThePageCannotFlagAPhoneWhoseNativeFixesAreFine(): void
    {
        $this->seedPing(35.33, 5, 'native');
        recordVolunteerPing($this->user(), $this->shiftId, 35.3301, 25.13, 900.0, 80, 'auto', 'browser', 1000);
        $this->assertNull($this->gpsError());
    }

    public function testAManualTapIsAlwaysStoredEvenWhileTheNativeServiceIsDelivering(): void
    {
        $this->seedPing(35.33, 5, 'native');
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.33005, 25.13, 6.0, 80, 'manual', 'browser', 500);
        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertSame(2, $this->pingCount());
    }

    public function testThePageTakesOverOnceTheNativeServiceHasGoneQuiet(): void
    {
        $cadence = (int) getSetting('war_room_auto_ping_seconds', '180');
        $this->seedPing(35.33, 2 * $cadence + 5, 'native');
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.3301, 25.13, 9.0, 80, 'auto', 'browser', 1000);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertArrayNotHasKey('skipped', $result);
        $this->assertSame(2, $this->pingCount());
    }

    public function testTheNativeServiceCountsAsInChargeOnlyWhileItIsDelivering(): void
    {
        // mission-gps-error.php ignores the page's own failures on this test,
        // or the roster flashed an error every cadence on a phone whose native
        // fixes were arriving fine (seen on the emulator).
        $this->assertFalse(volunteerHasRecentNativeFix($this->missionId, $this->volunteerId));
        $this->seedPing(35.33, 5, 'browser');
        $this->assertFalse(volunteerHasRecentNativeFix($this->missionId, $this->volunteerId), 'a browser fix is not the native service');
        $this->seedPing(35.33, warRoomNativeActiveWindowSeconds() + 10, 'native');
        $this->assertFalse(volunteerHasRecentNativeFix($this->missionId, $this->volunteerId), 'a service that went quiet is not in charge');
        $this->seedPing(35.33, 5, 'native');
        $this->assertTrue(volunteerHasRecentNativeFix($this->missionId, $this->volunteerId));
    }

    // ── Fake GPS ────────────────────────────────────────────────────────────

    public function testAMockLocationIsRefusedAndNamedOnTheRoster(): void
    {
        $result = recordVolunteerPing($this->user(), $this->shiftId, 35.33, 25.13, 3.0, 80, 'auto', 'native', 0, true);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->pingCount());
        $this->assertSame('mock', $this->gpsError());
    }

    // ── Reasons and parsing ─────────────────────────────────────────────────

    public function testTheAppsNamedReasonsAreStoredAndServerVerdictsCannotBeClaimed(): void
    {
        foreach (['location_off', 'power_save', 'imprecise'] as $reason) {
            // Cleared each time: order and precedence are pinned by the tier tests.
            clearVolunteerGpsError($this->missionId, $this->volunteerId);
            $this->assertContains($reason, VOLUNTEER_GPS_CLIENT_REASONS);
            $this->assertTrue(recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, $reason));
            $this->assertSame($reason, $this->gpsError());
        }
        // A client must not be able to paint "fake GPS" or "impossible jump"
        // on somebody — those are the server's own findings.
        $this->assertNotContains('mock', VOLUNTEER_GPS_CLIENT_REASONS);
        $this->assertNotContains('implausible', VOLUNTEER_GPS_CLIENT_REASONS);
        foreach (VOLUNTEER_GPS_CLIENT_REASONS as $reason) {
            $this->assertContains($reason, VOLUNTEER_GPS_ERROR_REASONS);
        }
    }

    public function testASymptomDoesNotOverwriteARecentCause(): void
    {
        // Location switched off: the app names the cause, then the page's own
        // capture reports "no position" seconds later. The roster must keep
        // saying WHY, not flip to the symptom.
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'location_off');
        $this->assertTrue(recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'unavailable'));
        $this->assertSame('location_off', $this->gpsError());
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'timeout');
        $this->assertSame('location_off', $this->gpsError());
    }

    public function testThePagesDeniedDoesNotOverwriteTheAppsLocationOff(): void
    {
        // Seen on the emulator: the app reported "location off" within a
        // second, then the page's own capture reported "denied" and replaced
        // it. The app's reason is the more precise one and must stay.
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'location_off');
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'denied');
        $this->assertSame('location_off', $this->gpsError());
    }

    public function testEveryReasonHasATier(): void
    {
        $this->assertEqualsCanonicalizing(VOLUNTEER_GPS_ERROR_REASONS, array_keys(VOLUNTEER_GPS_REASON_TIERS));
    }

    public function testACauseAlwaysReplacesASymptom(): void
    {
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'timeout');
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'power_save');
        $this->assertSame('power_save', $this->gpsError());
    }

    public function testAnOldCauseStopsBlockingTheSymptom(): void
    {
        // Fixed long ago but no position since: "no position" is the truth now.
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'location_off');
        dbExecute(
            "UPDATE mission_action_room_participants SET last_gps_error_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE)
              WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $this->volunteerId]
        );
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'unavailable');
        $this->assertSame('unavailable', $this->gpsError());
    }

    public function testFixAgeParsing(): void
    {
        $this->assertNull(parseFixAgeMs(null));
        $this->assertNull(parseFixAgeMs(''));
        $this->assertNull(parseFixAgeMs('abc'));
        $this->assertNull(parseFixAgeMs(true));
        $this->assertSame(0, parseFixAgeMs('-500'));
        $this->assertSame(1500, parseFixAgeMs('1500'));
        $this->assertSame(86400000, parseFixAgeMs('1e12'));
    }

    public function testAccuracyParsingNeverTurnsMissingIntoPerfect(): void
    {
        $this->assertNull(parseAccuracyMeters('', 35.3));
        $this->assertNull(parseAccuracyMeters('0', 35.3));
        $this->assertNull(parseAccuracyMeters('-4', 35.3));
        $this->assertNull(parseAccuracyMeters('12', null), 'no coordinates, no accuracy');
        $this->assertSame(12.5, parseAccuracyMeters('12.5', 35.3));
        $this->assertSame(5000.0, parseAccuracyMeters('99999', 35.3));
    }

    // ── v3.330.0: the phone is told to pause or stop ────────────────────────

    public function testThePhoneTracksPausesAndStopsExactlyWhenTheServerWouldTakeOrRefuseItsFixes(): void
    {
        $this->assertSame('track', nativeTrackingInstruction($this->volunteerId, $this->shiftId));

        // GPS tick removed mid-mission: paused, not stopped — a coordinator
        // may tick them again (a phone handover), and the phone must follow.
        setActionRoomParticipation($this->missionId, $this->volunteerId, false, $this->adminId);
        forgetActionRoomParticipantIds($this->missionId);
        $this->assertSame('pause', nativeTrackingInstruction($this->volunteerId, $this->shiftId));
        $refused = recordVolunteerPing($this->user(), $this->shiftId, 35.33, 25.13, 8.0, 80, 'auto', 'native');
        $this->assertFalse($refused['ok'], 'a paused phone is one whose fixes the server refuses');

        setActionRoomParticipation($this->missionId, $this->volunteerId, true, $this->adminId);
        forgetActionRoomParticipantIds($this->missionId);
        $this->assertSame('track', nativeTrackingInstruction($this->volunteerId, $this->shiftId));

        // Mission closed: stop — the service and its notification end.
        dbExecute("UPDATE missions SET status = ? WHERE id = ?", [STATUS_COMPLETED, $this->missionId]);
        $this->assertSame('stop', nativeTrackingInstruction($this->volunteerId, $this->shiftId));
    }

    public function testAnyoneNotApprovedOnThatShiftIsToldToStop(): void
    {
        $stranger = $this->makeUser('Not On This Shift');
        $this->assertSame('stop', nativeTrackingInstruction($stranger, $this->shiftId));
        dbExecute("UPDATE participation_requests SET status = ? WHERE shift_id = ? AND volunteer_id = ?", [PARTICIPATION_REJECTED, $this->shiftId, $this->volunteerId]);
        $this->assertSame('stop', nativeTrackingInstruction($this->volunteerId, $this->shiftId));
        $this->assertSame('stop', nativeTrackingInstruction($this->volunteerId, 0));
    }

    public function testStoppedByTheVolunteerIsARecordedReasonAPageSymptomCannotHide(): void
    {
        $this->assertContains('stopped_by_user', VOLUNTEER_GPS_CLIENT_REASONS);
        $this->assertTrue(recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'stopped_by_user'));
        $this->assertSame('stopped_by_user', $this->gpsError());
        // The page's own "no position came" must not replace the reason why.
        recordVolunteerGpsErrorReason($this->missionId, $this->volunteerId, 'timeout');
        $this->assertSame('stopped_by_user', $this->gpsError());
        // A fix that is accepted clears it, as it clears every other reason.
        $this->assertTrue(recordVolunteerPing($this->user(), $this->shiftId, 35.33, 25.13, 8.0, 80, 'auto', 'native')['ok']);
        $this->assertNull($this->gpsError());
    }
}
