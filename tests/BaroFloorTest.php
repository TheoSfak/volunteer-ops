<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The floor a person is on, from the phone's barometer (v3.426.0).
 *
 * What is pinned: the height maths, that a person's pressure is only ever
 * compared with THEIR OWN ground-level reading (never another phone's), that a
 * reference phone left outside cancels the weather, that a person with no
 * ground level or no reading shows nothing, and that setting the ground level
 * refuses what it cannot honestly set.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class BaroFloorTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $alice;
    private int $bob;
    private int $outside;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Floor Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Floor Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        forgetActionRoomParticipantIds($this->missionId);
        $this->alice = $this->makeParticipant('Alice A.');
        $this->bob = $this->makeParticipant('Bob B.');
        $this->outside = $this->makeParticipant('Outside O.');
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
            [$name, 'floor-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeParticipant(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        setActionRoomParticipation($this->missionId, $id, true, $this->adminId);
        return $id;
    }

    /** A reading taken $ageSeconds ago. */
    private function reading(int $userId, float $hpa, int $ageSeconds = 0): void
    {
        dbInsert(
            "INSERT INTO volunteer_pressure (mission_id, user_id, hpa, created_at) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$this->missionId, $userId, $hpa, $ageSeconds]
        );
    }

    /** Several readings of about the same pressure over the last minute, so a median can be taken. */
    private function steady(int $userId, float $hpa): void
    {
        foreach ([50, 40, 30, 20, 10] as $age) {
            $this->reading($userId, $hpa, $age);
        }
    }

    // ── the maths ───────────────────────────────────────────────────────────

    public function testTheSamePressureIsZeroMetres(): void
    {
        $this->assertEqualsWithDelta(0.0, baroHeightMetres(1013.25, 1013.25), 0.0001);
    }

    public function testAboutAThirdOfAHectopascalIsAFloor(): void
    {
        // Sea level: 1 m is ~0.12 hPa, so 3 m is ~0.36 hPa.
        $this->assertEqualsWithDelta(3.0, baroHeightMetres(1013.25 - 0.361, 1013.25), 0.05);
        $this->assertEqualsWithDelta(-3.0, baroHeightMetres(1013.25 + 0.361, 1013.25), 0.05, 'higher pressure is below the ground level');
        $this->assertEqualsWithDelta(30.0, baroHeightMetres(1000.0 - 3.55, 1000.0), 0.5);
    }

    public function testAHeightBecomesTheNearestFloor(): void
    {
        $this->assertSame(0, baroFloorOfHeight(1.4, 3.0));
        $this->assertSame(1, baroFloorOfHeight(1.6, 3.0));
        $this->assertSame(2, baroFloorOfHeight(6.0, 3.0));
        $this->assertSame(-1, baroFloorOfHeight(-3.2, 3.0), 'a basement is negative');
        $this->assertSame(0, baroFloorOfHeight(-1.4, 3.0));
        $this->assertSame(2, baroFloorOfHeight(5.6, 2.8), 'the floor height is a setting');
    }

    public function testTheFloorHeightSettingIsKeptInsideWhatABuildingCanBe(): void
    {
        $this->assertSame(3.0, baroFloorHeightFromSetting('3'));
        $this->assertSame(2.8, baroFloorHeightFromSetting('2.8'));
        $this->assertSame(3.5, baroFloorHeightFromSetting('3,5'), 'a decimal comma is understood');
        foreach (['', 'abc', '0', '1.9', '6.1', '99', null] as $bad) {
            $this->assertSame(3.0, baroFloorHeightFromSetting($bad), 'falls back to 3: ' . var_export($bad, true));
        }
    }

    // ── the ground pressure: weather and drift ──────────────────────────────

    public function testWithoutAReferenceTheGroundLevelIsTheBaselineAsSet(): void
    {
        $g = baroGroundPressure(['hpa0' => 1005.0, 'ref_user_id' => null, 'ref_hpa0' => null, 'age_s' => 300], null);
        $this->assertSame(1005.0, $g['hpa']);
        $this->assertSame('ok', $g['conf']);
    }

    public function testABaselineOlderThanTwentyMinutesIsFlaggedAsDrifting(): void
    {
        $base = ['hpa0' => 1005.0, 'ref_user_id' => null, 'ref_hpa0' => null];
        $this->assertSame('ok', baroGroundPressure($base + ['age_s' => 1200], null)['conf']);
        $this->assertSame('drift', baroGroundPressure($base + ['age_s' => 1201], null)['conf']);
    }

    public function testAReferencePhoneOutsideCancelsTheWeather(): void
    {
        // Ground level 1005.00 inside, 1013.00 on the reference phone outside.
        // An hour later the weather has dropped 1 hPa everywhere: the ground is
        // now 1004.00 and the reference reads 1012.00.
        $base = ['hpa0' => 1005.0, 'ref_user_id' => 9, 'ref_hpa0' => 1013.0, 'age_s' => 3600];
        $g = baroGroundPressure($base, ['hpa' => 1012.0, 'age_s' => 20]);
        $this->assertEqualsWithDelta(1004.0, $g['hpa'], 0.0001);
        $this->assertSame('ok', $g['conf'], 'the weather is accounted for, so an old baseline is not drifting');
    }

    public function testAReferenceThatHasGoneQuietFallsBackToTheBaseline(): void
    {
        $base = ['hpa0' => 1005.0, 'ref_user_id' => 9, 'ref_hpa0' => 1013.0, 'age_s' => 3600];
        $g = baroGroundPressure($base, ['hpa' => 1012.0, 'age_s' => BARO_REFERENCE_MAX_AGE_SECONDS + 1]);
        $this->assertSame(1005.0, $g['hpa']);
        $this->assertSame('drift', $g['conf']);
        $this->assertSame('drift', baroGroundPressure($base, null)['conf'], 'a reference with no reading at all');
    }

    public function testStandingStillWhileTheWeatherFallsIsNotAClimb(): void
    {
        $latest = ['hpa' => 1004.0, 'age_s' => 5, 'at' => '10:00'];
        $withRef = baroFloorReading($latest, ['hpa0' => 1005.0, 'ref_user_id' => 9, 'ref_hpa0' => 1013.0, 'age_s' => 3600],
            ['hpa' => 1012.0, 'age_s' => 5], 3.0, 540);
        $this->assertSame(0, $withRef['floor']);
        $this->assertSame(0, $withRef['m']);
        // The same hour without a reference reads the weather as a climb of about 8 m.
        $without = baroFloorReading($latest, ['hpa0' => 1005.0, 'ref_user_id' => null, 'ref_hpa0' => null, 'age_s' => 3600], null, 3.0, 540);
        $this->assertGreaterThan(7, $without['m']);
        $this->assertSame('drift', $without['conf'], 'and says so');
    }

    public function testAReadingOlderThanTheStaleLineIsMarkedStale(): void
    {
        $base = ['hpa0' => 1005.0, 'ref_user_id' => null, 'ref_hpa0' => null, 'age_s' => 60];
        $this->assertFalse(baroFloorReading(['hpa' => 1004.6, 'age_s' => 540, 'at' => '10:00'], $base, null, 3.0, 540)['stale']);
        $this->assertTrue(baroFloorReading(['hpa' => 1004.6, 'age_s' => 541, 'at' => '10:00'], $base, null, 3.0, 540)['stale']);
        $this->assertSame('10:00', baroFloorReading(['hpa' => 1004.6, 'age_s' => 541, 'at' => '10:00'], $base, null, 3.0, 540)['at']);
    }

    // ── the median ──────────────────────────────────────────────────────────

    public function testTheMedianIgnoresADoorSlam(): void
    {
        foreach ([1000.0, 1000.1, 1000.1, 1003.0, 1000.2] as $i => $hpa) {
            $this->reading($this->alice, $hpa, 10 + $i * 5);
        }
        $m = recentPressureMedian($this->missionId, $this->alice);
        $this->assertSame(1000.1, $m['hpa']);
        $this->assertSame(5, $m['n']);
        $this->assertSame(10, $m['newest_age_s']);
    }

    public function testReadingsOlderThanTwoMinutesAreNotInTheMedian(): void
    {
        $this->reading($this->alice, 990.0, 300);
        $this->reading($this->alice, 1000.0, 130);
        $this->reading($this->alice, 1010.0, 30);
        $m = recentPressureMedian($this->missionId, $this->alice);
        $this->assertSame(1, $m['n']);
        $this->assertSame(1010.0, $m['hpa']);
        $this->assertNull(recentPressureMedian($this->missionId, $this->bob), 'no reading at all');
    }

    // ── setting and clearing the ground level ───────────────────────────────

    public function testTheGroundLevelIsEachPhonesOwnMedian(): void
    {
        $this->steady($this->alice, 1001.30);
        $this->steady($this->bob, 1002.10); // another phone, a different sensor: never compared with Alice's
        $r = setFloorBaselines($this->missionId, [$this->alice, $this->bob], null, $this->adminId);
        $this->assertTrue($r['ok']);
        $this->assertEqualsCanonicalizing([$this->alice, $this->bob], $r['set']);
        $b = loadFloorBaselinesByUser($this->missionId);
        $this->assertSame(1001.30, $b[$this->alice]['hpa0']);
        $this->assertSame(1002.10, $b[$this->bob]['hpa0']);
        $this->assertNull($b[$this->alice]['ref_user_id']);
    }

    public function testSomebodyWithNoRecentReadingIsSkippedAndNamedWithTheReason(): void
    {
        $this->steady($this->alice, 1001.0);
        $this->reading($this->bob, 1001.0, 600); // ten minutes ago
        $stranger = $this->makeUser('Not In The Room');
        $r = setFloorBaselines($this->missionId, [$this->alice, $this->bob, $stranger], null, $this->adminId);
        $this->assertTrue($r['ok']);
        $this->assertSame([$this->alice], $r['set']);
        $this->assertSame('no_reading', $r['skipped'][$this->bob]);
        $this->assertSame('not_participant', $r['skipped'][$stranger]);
        $this->assertArrayNotHasKey($this->bob, loadFloorBaselinesByUser($this->missionId));
    }

    public function testAReferencePhoneIsStoredWithItsReadingAtTheSameMoment(): void
    {
        $this->steady($this->alice, 1001.0);
        $this->steady($this->outside, 1013.4);
        $r = setFloorBaselines($this->missionId, [$this->alice], $this->outside, $this->adminId);
        $this->assertTrue($r['ok']);
        $b = loadFloorBaselinesByUser($this->missionId)[$this->alice];
        $this->assertSame($this->outside, $b['ref_user_id']);
        $this->assertSame(1013.4, $b['ref_hpa0']);
    }

    public function testAReferenceThatCannotBeUsedStopsTheWholeCallBeforeAnythingIsWritten(): void
    {
        $this->steady($this->alice, 1001.0);
        $r = setFloorBaselines($this->missionId, [$this->alice], $this->outside, $this->adminId);
        $this->assertFalse($r['ok']);
        $this->assertSame('ref_no_reading', $r['error']);
        $this->assertSame([], loadFloorBaselinesByUser($this->missionId), 'nobody was half set');

        $this->steady($this->outside, 1013.0);
        $r = setFloorBaselines($this->missionId, [$this->alice, $this->outside], $this->outside, $this->adminId);
        $this->assertSame('ref_in_list', $r['error'], 'a phone cannot be its own reference: its floor would always be zero');

        $stranger = $this->makeUser('Not In The Room');
        $this->assertSame('ref_not_participant', setFloorBaselines($this->missionId, [$this->alice], $stranger, $this->adminId)['error']);
        $this->assertSame([], loadFloorBaselinesByUser($this->missionId));
    }

    public function testSettingAgainReplacesTheGroundLevel(): void
    {
        $this->steady($this->alice, 1001.0);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        $this->steady($this->alice, 1002.0);
        // The newest readings now say 1002.0; the median over the last two
        // minutes of both batches is what the second call keeps.
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        $this->assertCount(1, loadFloorBaselinesByUser($this->missionId), 'one ground level per person, the newest');
        $this->assertGreaterThan(1001.0, loadFloorBaselinesByUser($this->missionId)[$this->alice]['hpa0']);
    }

    public function testClearingTakesTheGroundLevelOffOnlyForThoseNamed(): void
    {
        $this->steady($this->alice, 1001.0);
        $this->steady($this->bob, 1001.0);
        setFloorBaselines($this->missionId, [$this->alice, $this->bob], null, $this->adminId);
        $this->assertSame(1, clearFloorBaselines($this->missionId, [$this->alice]));
        $this->assertSame([$this->bob], array_keys(loadFloorBaselinesByUser($this->missionId)));
        $this->assertSame(0, clearFloorBaselines($this->missionId, []));
        $this->assertSame(0, clearFloorBaselines($this->missionId, [$this->alice]), 'already clear');
    }

    // ── what the poll carries ───────────────────────────────────────────────

    public function testAMissionThatNeverSetAGroundLevelHasNoFloors(): void
    {
        $this->steady($this->alice, 1001.0);
        $this->assertSame([], loadMissionFloors($this->missionId));
    }

    public function testAPersonTwoFloorsUpShowsAboutSixMetresAndFloorTwo(): void
    {
        $this->steady($this->alice, 1001.00);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        $this->reading($this->alice, 1001.00 - 0.722, 0); // 6 m up
        $floors = loadMissionFloors($this->missionId);
        $this->assertSame(2, $floors[$this->alice]['floor']);
        $this->assertEqualsWithDelta(6, $floors[$this->alice]['m'], 1);
        $this->assertSame('ok', $floors[$this->alice]['conf']);
        $this->assertFalse($floors[$this->alice]['stale']);
        $this->assertMatchesRegularExpression('/^\d\d:\d\d$/', $floors[$this->alice]['at']);
    }

    public function testABasementIsANegativeFloor(): void
    {
        $this->steady($this->alice, 1001.00);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        $this->reading($this->alice, 1001.00 + 0.40, 0);
        $this->assertSame(-1, loadMissionFloors($this->missionId)[$this->alice]['floor']);
    }

    public function testEachPersonIsOnlyComparedWithTheirOwnGroundLevel(): void
    {
        // Two phones that read a whole hectopascal apart while standing in the
        // same place: both are on the ground floor, neither is nine metres up.
        $this->steady($this->alice, 1001.00);
        $this->steady($this->bob, 1002.00);
        setFloorBaselines($this->missionId, [$this->alice, $this->bob], null, $this->adminId);
        $this->reading($this->alice, 1001.02, 0);
        $this->reading($this->bob, 1001.98, 0);
        $floors = loadMissionFloors($this->missionId);
        $this->assertSame(0, $floors[$this->alice]['floor']);
        $this->assertSame(0, $floors[$this->bob]['floor']);
    }

    public function testThePersonWithABaselineButNoReadingYetShowsNothing(): void
    {
        $this->steady($this->alice, 1001.0);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        dbExecute("DELETE FROM volunteer_pressure WHERE mission_id = ? AND user_id = ?", [$this->missionId, $this->alice]);
        $this->assertSame([], loadMissionFloors($this->missionId));
    }

    public function testAnOldReadingIsKeptButMarkedStale(): void
    {
        $this->steady($this->alice, 1001.0);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        dbExecute("DELETE FROM volunteer_pressure WHERE mission_id = ? AND user_id = ?", [$this->missionId, $this->alice]);
        $this->reading($this->alice, 1000.3, warRoomPingStaleThresholdSeconds() + 60);
        $floor = loadMissionFloors($this->missionId)[$this->alice];
        $this->assertTrue($floor['stale'], 'the last known floor, not the current one');
        $this->assertSame(2, $floor['floor']);
    }

    // ── who can be set ──────────────────────────────────────────────────────

    public function testTheWindowListsEveryParticipantByTeamWithWhoIsReady(): void
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΒΗΤΑ', 2, $this->adminId]
        );
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $this->alice]);
        $this->steady($this->alice, 1001.0);
        $this->reading($this->bob, 1001.0, 500); // a reading, but not a recent one
        dbExecute("UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE user_id = ?", [$this->bob]);
        dbInsert("INSERT INTO mobile_api_tokens (user_id, token_hash, device_label, has_barometer) VALUES (?, ?, 'test', 0)", [$this->bob, hash('sha256', uniqid('', true))]);
        setFloorBaselines($this->missionId, [$this->alice], null, $this->adminId);
        forgetActionRoomParticipantIds($this->missionId);

        $c = floorBaselineCandidates($this->missionId);
        $this->assertSame(3.0, $c['floor_height_m']);
        $this->assertCount(2, $c['groups'], 'the team, then people on no team');
        $team = $c['groups'][0];
        $this->assertSame($teamId, $team['team_id']);
        $this->assertStringContainsString('2', (string) $team['label']);
        $this->assertSame([$this->alice], array_column($team['members'], 'user_id'));
        $this->assertTrue($team['members'][0]['fresh']);
        $this->assertNotNull($team['members'][0]['baseline_at']);

        $loose = $c['groups'][1];
        $this->assertSame(0, $loose['team_id']);
        $byId = array_column($loose['members'], null, 'user_id');
        $this->assertFalse($byId[$this->bob]['fresh']);
        $this->assertSame(0, $byId[$this->bob]['has_baro'], 'a phone known to have no barometer');
        $this->assertNull($byId[$this->bob]['baseline_at']);
        $this->assertNull($byId[$this->outside]['has_baro'], 'never said is not no');
    }
}
