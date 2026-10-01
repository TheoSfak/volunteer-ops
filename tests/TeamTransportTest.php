<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * How a team moves (v3.357.0): foot, motorbike or car.
 *
 * It decides the icon, the speed a position may imply before it is refused as
 * a GPS glitch, whether an arrival time is worked out on foot or by road, and
 * which figure the assistant leads with. Everything defaults to foot, so every
 * team made before this existed behaves exactly as it did.
 *
 * The first block needs no database. The rest runs inside a transaction that
 * is always rolled back.
 */
final class TeamTransportTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $volunteerId;
    private int $teamId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Transport Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Transport Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->volunteerId = $this->makeUser('Rider R.');
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $this->volunteerId, PARTICIPATION_APPROVED]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΒΗΤΑ', 2, $this->adminId]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$this->teamId, $this->missionId, $this->volunteerId]
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
            [$name, 'transport-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function setTransport(string $transport): void
    {
        dbExecute("UPDATE mission_teams SET transport = ? WHERE id = ?", [$transport, $this->teamId]);
    }

    private function user(): array
    {
        return dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->volunteerId]);
    }

    private function northOf(float $lat, float $metres): float
    {
        return $lat + $metres / 111320.0;
    }

    private function seedPing(float $lat, int $secondsAgo): void
    {
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, ?, 25.13, 6, 'auto', 'native', DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$this->volunteerId, $this->shiftId, $lat, $secondsAgo]
        );
    }

    private function pingCount(): int
    {
        return (int) dbFetchValue(
            "SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ? AND shift_id = ?",
            [$this->volunteerId, $this->shiftId]
        );
    }

    // ── The three values ────────────────────────────────────────────────────

    public function testAnythingUnknownIsOnFoot(): void
    {
        $this->assertSame('foot', normalizeTeamTransport(null));
        $this->assertSame('foot', normalizeTeamTransport(''));
        $this->assertSame('foot', normalizeTeamTransport('helicopter'));
        $this->assertSame('foot', normalizeTeamTransport(['car']));
        $this->assertSame('motorbike', normalizeTeamTransport('motorbike'));
        $this->assertSame('car', normalizeTeamTransport('car'));
    }

    public function testOnlyTheTwoVehiclesCountAsVehicles(): void
    {
        $this->assertFalse(teamTransportIsVehicle('foot'));
        $this->assertFalse(teamTransportIsVehicle(null));
        $this->assertTrue(teamTransportIsVehicle('motorbike'));
        $this->assertTrue(teamTransportIsVehicle('car'));
    }

    public function testEveryTransportHasAnIconAndNoOtherDoes(): void
    {
        $this->assertSame(TEAM_TRANSPORTS, array_keys(teamTransportIconPaths()));
        foreach (TEAM_TRANSPORTS as $transport) {
            $svg = teamTransportIconSvg($transport, 20);
            $this->assertStringContainsString('<svg', $svg);
            $this->assertStringContainsString('<path', $svg);
            $this->assertStringContainsString('width="20"', $svg);
        }
        $this->assertNotSame(teamTransportIconSvg('foot'), teamTransportIconSvg('car'));
        $this->assertSame(teamTransportIconSvg('foot'), teamTransportIconSvg('nonsense'), 'an unknown value draws the default');
    }

    public function testATeamNobodyChoseForIsOnFootInTheDatabase(): void
    {
        $this->assertSame('foot', teamTransportForTeam($this->teamId));
        $this->assertSame('foot', teamTransportForUser($this->missionId, $this->volunteerId));
        $this->assertSame('foot', teamTransportForUser($this->missionId, $this->adminId), 'on no team is on foot');
        $this->setTransport('car');
        $this->assertSame('car', teamTransportForTeam($this->teamId));
        $this->assertSame('car', teamTransportForUser($this->missionId, $this->volunteerId));
    }

    public function testTheTeamsListCarriesIt(): void
    {
        $this->setTransport('motorbike');
        $teams = loadMissionTeamsForMission($this->missionId);
        $this->assertSame('motorbike', $teams[$this->teamId]['transport']);
    }

    // ── What the assistant says ─────────────────────────────────────────────

    private function routed(): array
    {
        return [
            'walking' => ['meters' => 3200, 'minutes' => 45],
            'driving' => ['meters' => 5100, 'minutes' => 9],
            'walk_tried' => true,
        ];
    }

    public function testATeamOnFootGetsTheWalkingFigureFirst(): void
    {
        $words = aiLiveDistanceToTargetWords(2500.0, 'ΒΔ', $this->routed(), true, null, 'foot');
        $this->assertLessThan(mb_strpos($words, 'με αμάξι'), mb_strpos($words, 'με τα πόδια'));
        $this->assertSame($words, aiLiveDistanceToTargetWords(2500.0, 'ΒΔ', $this->routed(), true), 'the default is unchanged');
    }

    public function testAVehicleTeamGetsTheRoadFigureFirstAndBothAreStillNamed(): void
    {
        foreach (['motorbike', 'car'] as $transport) {
            $words = aiLiveDistanceToTargetWords(2500.0, 'ΒΔ', $this->routed(), true, null, $transport);
            $this->assertLessThan(mb_strpos($words, 'με τα πόδια'), mb_strpos($words, 'με αμάξι'), $transport);
            $this->assertStringContainsString('9 λεπτά', $words);
            $this->assertStringContainsString('45 λεπτά', $words);
        }
    }

    public function testAVehicleTeamIsNotToldItHasNoWalkingRoute(): void
    {
        $routed = ['walking' => null, 'driving' => ['meters' => 5100, 'minutes' => 9], 'walk_tried' => true];
        $this->assertStringContainsString(AI_LIVE_ROUTE_NO_WALK_MARK, aiLiveDistanceToTargetWords(2500.0, 'ΒΔ', $routed, true, null, 'foot'));
        $this->assertStringNotContainsString(AI_LIVE_ROUTE_NO_WALK_MARK, aiLiveDistanceToTargetWords(2500.0, 'ΒΔ', $routed, true, null, 'car'));
    }

    public function testTheWordsForEachTransport(): void
    {
        $this->assertSame('με τα πόδια', aiLiveTransportWords('foot'));
        $this->assertSame('με μηχανή', aiLiveTransportWords('motorbike'));
        $this->assertSame('με αμάξι', aiLiveTransportWords('car'));
        $this->assertSame('με τα πόδια', aiLiveTransportWords('xyz'));
    }

    // ── How fast a position may imply the team travelled ────────────────────

    public function testACarOnARoadIsStoredWhereTheSameJumpOnFootIsRefused(): void
    {
        // 272m in 20s is 49 km/h, with no Doppler speed from the phone.
        // On foot that is a glitch; declared as a car it is Tuesday.
        $this->seedPing(35.33, 20);
        $onFoot = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.33, 272), 25.13, 6.0, 80, 'auto', 'native', 0);
        $this->assertFalse($onFoot['ok'], 'a team on foot cannot be doing 49 km/h');

        dbExecute("DELETE FROM volunteer_pings WHERE user_id = ? AND shift_id = ?", [$this->volunteerId, $this->shiftId]);
        foreach (['motorbike', 'car'] as $transport) {
            $this->setTransport($transport);
            dbExecute("DELETE FROM volunteer_pings WHERE user_id = ? AND shift_id = ?", [$this->volunteerId, $this->shiftId]);
            $this->seedPing(35.33, 20);
            $ride = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.33, 272), 25.13, 6.0, 80, 'auto', 'native', 0);
            $this->assertTrue($ride['ok'], "$transport: " . ($ride['error'] ?? ''));
            $this->assertSame(2, $this->pingCount(), $transport);
        }
    }

    public function testAVehicleStillCannotTeleport(): void
    {
        // 1.7 km in 20 s is 306 km/h — over any road speed.
        $this->setTransport('car');
        $this->seedPing(35.33, 20);
        $spike = recordVolunteerPing($this->user(), $this->shiftId, $this->northOf(35.33, 1700), 25.13, 6.0, 80, 'auto', 'native', 0);
        $this->assertFalse($spike['ok']);
        $this->assertSame(1, $this->pingCount());
    }

    // ── Arrival time at a dispatch point ────────────────────────────────────

    private function sendDispatchWithPing(): int
    {
        $this->seedPing(35.10, 30);
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode(['lat' => 35.12, 'lng' => 25.13]), 'Ρέμα', $this->adminId]
        );
    }

    public function testTheArrivalTimeIsWorkedOutOnFootForATeamOnFoot(): void
    {
        $dispatchId = $this->sendDispatchWithPing();
        $eta = computeDispatchEta($dispatchId, $this->teamId, 35.12, 25.13);
        $this->assertSame('foot', $eta['mode']);
        $this->assertSame('foot', $eta['transport']);
        $this->assertContains($eta['source'], ['google', 'straight_line'], 'never the road router for a team on foot');
        // ~2.2 km. Whichever way it was worked out, nobody walks that in 3 minutes.
        $this->assertGreaterThan(15, $eta['minutes']);
    }

    public function testTheArrivalTimeIsWorkedOutByRoadForAVehicle(): void
    {
        $this->setTransport('car');
        $dispatchId = $this->sendDispatchWithPing();
        $eta = computeDispatchEta($dispatchId, $this->teamId, 35.12, 25.13);
        $this->assertSame('vehicle', $eta['mode']);
        $this->assertSame('car', $eta['transport']);
        $this->assertContains($eta['source'], ['osrm', 'straight_line']);
        $this->assertLessThan(30, $eta['minutes'], '2.2 km by road is minutes, not an hour');
    }

    public function testSwitchingTheTeamDropsTheTimeCachedTheOtherWay(): void
    {
        $dispatchId = $this->sendDispatchWithPing();
        $this->setTransport('car');
        $car = computeDispatchEta($dispatchId, $this->teamId, 35.12, 25.13);
        $this->assertSame('vehicle', $car['mode']);

        // Same ping, so the cache would answer — but it was worked out for a car.
        $this->setTransport('foot');
        $foot = computeDispatchEta($dispatchId, $this->teamId, 35.12, 25.13);
        $this->assertSame('foot', $foot['mode']);
        $this->assertSame('foot', dbFetchValue("SELECT mode FROM dispatch_eta_cache WHERE dispatch_id = ?", [$dispatchId]));
    }

    public function testTheWalkingFallbackIsFourKilometresAnHour(): void
    {
        // 0.036 degrees of latitude ≈ 4,007 m: one hour, give or take the rounding.
        $this->assertEqualsWithDelta(60, straightLineWalkingEtaMinutes(35.0, 25.0, 35.036, 25.0), 1);
        $this->assertSame(1, straightLineWalkingEtaMinutes(35.0, 25.0, 35.0, 25.0), 'never zero minutes');
    }
}
