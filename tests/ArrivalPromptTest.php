<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Έφτασες;» (v3.333.0): a GPS fix at the place a team was sent asks the person
 * whether they have arrived — once, and never on a fix too vague or too old to
 * say so, never for another team's order, never after the team has arrived.
 * checkArrivalPrompts() runs on every ping from every source, so what it must
 * NOT do matters as much as what it does: a wrong question on every fix would
 * be a phone ringing in a pocket every few seconds.
 *
 * Also pins the two arrival paths the phone's «Έφτασα» uses
 * (advanceMissionDispatch() and recordRouteWaypointArrival()): recorded once,
 * and the audit row names the actor, who has no session there.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ArrivalPromptTest extends TestCase
{
    private const LAT = 35.2000000;
    private const LNG = 24.9000000;
    // ~11 m of latitude.
    private const NEAR = 0.0001;

    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $teammate;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Arrival Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Arrival Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->member = $this->makeVolunteer('Άννα Α.', $this->teamId);
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.', $this->teamId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'ap-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeTeam(string $codename, int $number): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function makeVolunteer(string $name, int $teamId): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$teamId, $this->missionId, $id]
        );
        return $id;
    }

    private function mission(): array
    {
        return dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
    }

    private function sendPoint(?int $teamId, string $label = 'Πηγή'): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => self::LAT, 'lng' => self::LNG]), $label, $this->adminId]
        );
    }

    /** A fix for $userId, $metresNorth of the point. */
    private function fix(int $userId, float $degreesNorth, ?float $accuracy = 10.0, int $ageSeconds = 5): void
    {
        checkArrivalPrompts(
            $this->missionId, $userId, getUserTeamIdForMission($this->missionId, $userId),
            self::LAT + $degreesNorth, self::LNG, $accuracy, $ageSeconds
        );
    }

    private function questionsFor(int $userId): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND (data LIKE '%arriveDispatchId%' OR data LIKE '%arriveWaypointId%')",
            [$userId]
        );
    }

    public function testAFixAtThePointAsksOnceAndOnlyOnce(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR);
        $this->fix($this->member, self::NEAR / 2);

        $questions = $this->questionsFor($this->member);
        $this->assertCount(1, $questions, 'the second fix at the same point must not ring the phone again');
        $data = json_decode($questions[0]['data'], true);
        $this->assertSame($dispatchId, $data['arriveDispatchId']);
        $this->assertSame($this->missionId, $data['bannerMission'], 'it is an order-class alert: popup on the page, the loud channel on the phone');
        $this->assertSame(['kind' => 'arrive', 'target' => 'dispatch', 'id' => $dispatchId], notificationPopupRef($data));
        $this->assertStringContainsString('Πηγή', $questions[0]['message']);
    }

    public function testNothingIsAskedFromFarAwayOrOnAFixThatCannotTell(): void
    {
        $this->sendPoint($this->teamId);

        $this->fix($this->member, 0.0008);                   // ~90 m
        $this->fix($this->member, self::NEAR, 70.0);          // near, but ±70 m
        $this->fix($this->member, self::NEAR, null);          // no accuracy at all
        $this->fix($this->member, self::NEAR, 10.0, 300);     // the outage buffer replaying

        $this->assertSame([], $this->questionsFor($this->member));
    }

    public function testAnotherTeamsPointAsksNothing(): void
    {
        $this->sendPoint($this->otherTeamId);

        $this->fix($this->member, self::NEAR);

        $this->assertSame([], $this->questionsFor($this->member));
    }

    public function testATeamThatHasArrivedIsNotAsked(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);
        $this->assertNull(advanceMissionDispatch($this->mission(), $dispatchId, $this->teammate, 'Γιώργος Γ.', 'arrive'));

        $this->fix($this->member, self::NEAR);

        $this->assertSame([], $this->questionsFor($this->member), 'a teammate already reported the team there');
        $this->assertFalse(arrivalPromptStillOpen(['kind' => 'arrive', 'target' => 'dispatch', 'id' => $dispatchId], $this->member));
    }

    public function testInsideAnAreaAsks(): void
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'polygon', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode([[35.19, 24.89], [35.21, 24.89], [35.21, 24.91], [35.19, 24.91]]), 'Ρέμα', $this->adminId]
        );

        $this->fix($this->member, 0.0);

        $questions = $this->questionsFor($this->member);
        $this->assertCount(1, $questions);
        $this->assertSame($areaId, json_decode($questions[0]['data'], true)['arriveDispatchId']);
    }

    public function testARouteAsksAboutItsNextPointOnly(): void
    {
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $this->teamId, 'Περίπολος', $this->adminId]
        );
        dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $this->member]);
        $first = (int) dbInsert(
            "INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)",
            [$routeId, self::LAT + 0.01, self::LNG]
        );
        $second = (int) dbInsert(
            "INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 2, ?, ?)",
            [$routeId, self::LAT, self::LNG]
        );
        foreach ([$first, $second] as $wpId) {
            dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$wpId, $routeId, $this->teamId]);
        }

        $this->fix($this->member, self::NEAR);
        $this->assertSame([], $this->questionsFor($this->member), 'point 2 is not the one the route is waiting for');

        checkArrivalPrompts($this->missionId, $this->member, $this->teamId, self::LAT + 0.01, self::LNG, 8.0, 3);
        $questions = $this->questionsFor($this->member);
        $this->assertCount(1, $questions);
        $data = json_decode($questions[0]['data'], true);
        $this->assertSame($first, $data['arriveWaypointId']);
        $this->assertSame($routeId, $data['arriveRouteId']);
    }

    public function testArrivingFromThePhoneIsRecordedOnceAndSaysWho(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $this->assertNull(advanceMissionDispatch($this->mission(), $dispatchId, $this->member, 'Άννα Α.', 'arrive', 'app_notification'));
        $this->assertNull(advanceMissionDispatch($this->mission(), $dispatchId, $this->member, 'Άννα Α.', 'arrive', 'app_notification'));

        $this->assertNotNull(dbFetchValue(
            "SELECT arrived_at FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?",
            [$dispatchId, 't' . $this->teamId]
        ));
        $audits = dbFetchAll("SELECT notes FROM audit_logs WHERE action = 'team_arrived_dispatch' AND record_id = ?", [$dispatchId]);
        $this->assertCount(1, $audits);
        $this->assertSame('app_notification', json_decode($audits[0]['notes'], true)['new']['via']);
    }

    public function testARoutePointArrivalIsRecordedOnce(): void
    {
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $this->teamId, 'Περίπολος', $this->adminId]
        );
        dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $this->member]);
        $wpId = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)", [$routeId, self::LAT, self::LNG]);
        dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$wpId, $routeId, $this->teamId]);
        $wp = loadWaypointForAction($wpId, $this->missionId, $this->member);
        $now = date('Y-m-d H:i:s');

        $this->assertTrue(recordRouteWaypointArrival($this->mission(), $wp, $this->member, self::LAT + self::NEAR, self::LNG, 9.0, $now, $now, 0, 'app_notification'));
        $this->assertFalse(recordRouteWaypointArrival($this->mission(), $wp, $this->member, self::LAT, self::LNG, 9.0, $now, $now, 0));

        $row = dbFetchOne("SELECT arrived_by, arrived_distance_m FROM mission_route_progress WHERE waypoint_id = ?", [$wpId]);
        $this->assertSame($this->member, (int) $row['arrived_by']);
        $this->assertSame(11, (int) $row['arrived_distance_m']);
        $this->assertFalse(arrivalPromptStillOpen(['kind' => 'arrive', 'target' => 'waypoint', 'id' => $wpId], $this->member));
    }
}
