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
        dbExecute("UPDATE users SET role = ? WHERE id = ?", [ROLE_DEPARTMENT_ADMIN, $this->adminId]);
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
        // The tests below this one's header pin the question. The switch is on
        // by default (v3.381.0), so they run with it off; the automatic ones
        // switch it on themselves.
        $this->setAuto(false);
    }

    private function setAuto(bool $on): void
    {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('arrival_auto_enabled', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [$on ? '1' : '0']
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
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
    private function fix(int $userId, float $degreesNorth, ?float $accuracy = 10.0, int $ageSeconds = 5, ?float $speed = null): void
    {
        checkArrivalPrompts(
            $this->missionId, $userId, getUserTeamIdForMission($this->missionId, $userId),
            self::LAT + $degreesNorth, self::LNG, $accuracy, $ageSeconds, $speed
        );
    }

    /**
     * Pretend the first of two good fixes was $seconds ago — the dwell the
     * server waits for is wall-clock time, and a test cannot sleep for it.
     */
    private function ageTheDwell(int $userId, int $seconds = 15): void
    {
        dbExecute(
            "UPDATE mission_arrival_dwell
                SET first_hit_at = DATE_SUB(NOW(), INTERVAL ? SECOND), last_hit_at = DATE_SUB(NOW(), INTERVAL ? SECOND)
              WHERE user_id = ?",
            [$seconds, max(1, $seconds - 10), $userId]
        );
    }

    /** Two accurate fixes at the point, far enough apart in time to count. */
    private function arriveByGps(int $userId, float $degreesNorth = self::NEAR): void
    {
        $this->fix($userId, $degreesNorth);
        $this->ageTheDwell($userId);
        $this->fix($userId, $degreesNorth);
    }

    private function teamArrivedAt(int $dispatchId): ?string
    {
        $value = dbFetchValue(
            "SELECT arrived_at FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?",
            [$dispatchId, 't' . $this->teamId]
        );
        return $value === false ? null : $value;
    }

    /** What the person was told once the server had recorded their arrival. */
    private function arrivedNoticesFor(int $userId): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND data LIKE '%mission_arrived_auto%'",
            [$userId]
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

    // ── Automatic arrival (v3.381.0) ─────────────────────────────────────────
    //
    // With the switch on, two accurate fixes at the place — a few seconds
    // apart, no fix away from it in between — record the team's arrival by
    // themselves, through the same functions the «Έφτασα» button calls.

    public function testTheSwitchIsOnWhenNobodyHasEverSetIt(): void
    {
        dbExecute("DELETE FROM settings WHERE setting_key = 'arrival_auto_enabled'");
        $this->assertTrue(arrivalAutoEnabled());
        $this->setAuto(false);
        $this->assertFalse(arrivalAutoEnabled());
        $this->setAuto(true);
        $this->assertTrue(arrivalAutoEnabled());
    }

    public function testWithTheSwitchOffAGoodPairOfFixesOnlyAsks(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $this->arriveByGps($this->member);

        $this->assertNull($this->teamArrivedAt($dispatchId), 'off means off: the old question, nothing recorded');
        $this->assertCount(1, $this->questionsFor($this->member));
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testTheFirstGoodFixOnlyStartsTheWait(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR);

        $this->assertNull($this->teamArrivedAt($dispatchId), 'one fix could be a car going past');
        $this->assertSame([], $this->questionsFor($this->member), 'and it is not a reason to ring the phone either');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_dwell WHERE user_id = ?", [$this->member]));
    }

    public function testASecondFixTooSoonAfterTheFirstDoesNotCount(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR);
        $this->fix($this->member, self::NEAR);

        $this->assertNull($this->teamArrivedAt($dispatchId), 'two fixes a second apart are one moment, not a stay');
    }

    public function testTwoGoodFixesRecordTheArrivalAsTheTeamsAndSayHow(): void
    {
        $this->setAuto(true);
        dbExecute("UPDATE missions SET responsible_user_id = ? WHERE id = ?", [$this->adminId, $this->missionId]);
        $dispatchId = $this->sendPoint($this->teamId, 'Πηγή');

        $this->arriveByGps($this->member);

        $this->assertNotNull($this->teamArrivedAt($dispatchId));
        $this->assertSame($this->member, (int) dbFetchValue(
            "SELECT arrived_by FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?", [$dispatchId, 't' . $this->teamId]
        ));
        $audits = dbFetchAll("SELECT notes FROM audit_logs WHERE action = 'team_arrived_dispatch' AND record_id = ?", [$dispatchId]);
        $this->assertCount(1, $audits);
        $this->assertSame('auto_gps', json_decode($audits[0]['notes'], true)['new']['via'], 'a measurement, not a statement — the record says so');

        // Command is told, and is told it was the GPS.
        $toCommand = dbFetchAll(
            "SELECT message FROM notifications WHERE user_id = ? AND data LIKE '%dispatch-ack-mission-%'",
            [$this->adminId]
        );
        $this->assertCount(1, $toCommand);
        $this->assertStringContainsString(t('arrival.auto_suffix', [], 'el'), $toCommand[0]['message']);

        // The person is told, out loud: a notice the page speaks.
        $notices = $this->arrivedNoticesFor($this->member);
        $this->assertCount(1, $notices);
        $data = json_decode($notices[0]['data'], true);
        $this->assertSame('mission_arrived_auto', $data['popupInfo']);
        $this->assertSame('speak', $data['alarmStyle']);
        $this->assertSame($this->missionId, $data['bannerMission']);
        $this->assertStringContainsString('Πηγή', $notices[0]['message']);

        // Nothing left to ask.
        $this->assertSame([], $this->questionsFor($this->member));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_dwell WHERE user_id = ?", [$this->member]));
    }

    public function testATeammatesArrivalIsNotAnnouncedTwiceToCommand(): void
    {
        $this->setAuto(true);
        dbExecute("UPDATE missions SET responsible_user_id = ? WHERE id = ?", [$this->adminId, $this->missionId]);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->arriveByGps($this->member);
        $this->arriveByGps($this->teammate);

        $this->assertSame(1, (int) dbFetchValue(
            "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE '%dispatch-ack-mission-%'", [$this->adminId]
        ), 'the team arrived once');
        $this->assertSame([], $this->arrivedNoticesFor($this->teammate), 'the one who did not trigger it is not told they arrived');
        $this->assertNotNull($this->teamArrivedAt($dispatchId));
    }

    public function testALeftFixBetweenTheTwoStartsTheWaitAgain(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR);
        $this->ageTheDwell($this->member);
        $this->fix($this->member, 0.0008);            // ~90 m away and sure of it: they drove past
        $this->fix($this->member, self::NEAR);        // ...and this is a first fix again

        $this->assertNull($this->teamArrivedAt($dispatchId));
    }

    public function testAFirstFixLongAgoIsNotAStay(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR);
        $this->ageTheDwell($this->member, 900);       // a quarter of an hour ago

        $this->fix($this->member, self::NEAR);

        $this->assertNull($this->teamArrivedAt($dispatchId), 'the first fix is too old to be the start of this stay');
    }

    public function testGoingPastFastNeverArrivesAndNeverAsks(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR, 10.0, 5, 13.0);
        $this->ageTheDwell($this->member);
        $this->fix($this->member, self::NEAR, 10.0, 5, 13.0);   // ~47 km/h

        $this->assertNull($this->teamArrivedAt($dispatchId));
        $this->assertSame([], $this->questionsFor($this->member));
    }

    public function testAPhoneThatReportsItIsOnFootOrStoppedArrivesOnTheFirstFix(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR, 10.0, 5, 1.2);   // walking pace, Doppler-measured

        $this->assertNotNull($this->teamArrivedAt($dispatchId), 'no second fix to wait for: the phone itself says it is not driving past');
        $this->assertCount(1, $this->arrivedNoticesFor($this->member));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_dwell WHERE user_id = ?", [$this->member]));
    }

    public function testAVagueButNearbyFixFallsBackToTheQuestion(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);

        $this->fix($this->member, self::NEAR, 40.0);

        $this->assertNull($this->teamArrivedAt($dispatchId), '±40 m cannot say which side of 30 m they are on');
        $this->assertCount(1, $this->questionsFor($this->member), 'so it asks, as before');
    }

    public function testATeamThatHasArrivedIsNotRecordedAgain(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->teamId);
        $this->assertNull(advanceMissionDispatch($this->mission(), $dispatchId, $this->teammate, 'Γιώργος Γ.', 'arrive'));

        $this->arriveByGps($this->member);

        $this->assertSame([], $this->arrivedNoticesFor($this->member));
        $this->assertSame($this->teammate, (int) dbFetchValue(
            "SELECT arrived_by FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?", [$dispatchId, 't' . $this->teamId]
        ));
    }

    public function testAnotherTeamsPointIsNeverArrivedAt(): void
    {
        $this->setAuto(true);
        $dispatchId = $this->sendPoint($this->otherTeamId);

        $this->arriveByGps($this->member);

        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_progress WHERE dispatch_id = ?", [$dispatchId]));
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testInsideAnAreaRecordsTheArrival(): void
    {
        $this->setAuto(true);
        $areaId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'polygon', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode([[35.19, 24.89], [35.21, 24.89], [35.21, 24.91], [35.19, 24.91]]), 'Ρέμα', $this->adminId]
        );

        $this->arriveByGps($this->member, 0.0);

        $this->assertNotNull($this->teamArrivedAt($areaId));
        $notices = $this->arrivedNoticesFor($this->member);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('Ρέμα', $notices[0]['message']);
    }

    // ── Search sectors (v3.382.0) ────────────────────────────────────────────
    //
    // A sector has no «Έφτασα» of its own: arriving is the step the page calls
    // «Έφτασα — Έναρξη Έρευνας», en_route -> in_progress. With the switch on, a
    // team that has received its sector and is standing in it is moved there by
    // the GPS, the way its button would. There is no question for a sector, so
    // with the switch off, or on a fix too vague for the 30 m line, nothing
    // happens at all.

    /** A sector around the point (LAT/LNG), for $teamId, in $status. */
    private function sector(?int $teamId, string $status = 'assigned', bool $received = true, string $label = 'Τομέας Ε'): int
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 24.8], [35.3, 24.8], [35.3, 25.0]]), $this->adminId]
        );
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, acknowledged_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?)",
            [
                $this->missionId, $areaId, $teamId, $label,
                json_encode([[35.19, 24.89], [35.21, 24.89], [35.21, 24.91], [35.19, 24.91]]),
                $status, $received ? date('Y-m-d H:i:s') : null, $this->adminId,
            ]
        );
    }

    private function sectorStatus(int $sectorId): string
    {
        return (string) dbFetchValue("SELECT status FROM mission_search_sectors WHERE id = ?", [$sectorId]);
    }

    /** @return string[] e.g. ['assigned>en_route', 'en_route>in_progress'] */
    private function sectorMoves(int $sectorId): array
    {
        return array_map(
            fn($r) => $r['from_status'] . '>' . $r['to_status'],
            dbFetchAll("SELECT from_status, to_status FROM mission_sector_status_log WHERE sector_id = ? ORDER BY id", [$sectorId])
        );
    }

    public function testATeamInsideItsReceivedSectorStartsTheSearch(): void
    {
        $this->setAuto(true);
        dbExecute("UPDATE missions SET responsible_user_id = ? WHERE id = ?", [$this->adminId, $this->missionId]);
        $sectorId = $this->sector($this->teamId, 'assigned');

        $this->arriveByGps($this->member, 0.0);

        $this->assertSame('in_progress', $this->sectorStatus($sectorId));
        $this->assertSame(['assigned>en_route', 'en_route>in_progress'], $this->sectorMoves($sectorId),
            'the departure they never pressed is on the record, as for a dispatch');
        $this->assertSame($this->member, (int) dbFetchValue("SELECT status_updated_by FROM mission_search_sectors WHERE id = ?", [$sectorId]));
        $audits = dbFetchAll("SELECT notes FROM audit_logs WHERE action = 'update_mission_sector_status' AND record_id = ?", [$sectorId]);
        $this->assertNotEmpty($audits);
        $this->assertSame('auto_gps', json_decode(end($audits)['notes'], true)['new']['via']);

        // Command hears the status change, once, and that it was the GPS.
        $toCommand = dbFetchAll(
            "SELECT message FROM notifications WHERE user_id = ? AND data LIKE '%sector-status-mission-%'", [$this->adminId]
        );
        $this->assertCount(1, $toCommand);
        $this->assertStringContainsString(t('arrival.auto_suffix', [], 'el'), $toCommand[0]['message']);

        // The volunteer is told out loud that they arrived and the search began.
        $notices = $this->arrivedNoticesFor($this->member);
        $this->assertCount(1, $notices);
        $this->assertSame('speak', json_decode($notices[0]['data'], true)['alarmStyle']);
        $this->assertStringContainsString('«Ε»', $notices[0]['message'], 'the sector is named without saying «Τομέας» twice');
        $this->assertStringContainsString('έρευνα', $notices[0]['message']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_dwell WHERE user_id = ?", [$this->member]));
    }

    public function testATeamInsideASectorItIsOnItsWayToIsMovedOnWithOneStep(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'en_route');

        $this->arriveByGps($this->member, 0.0);

        $this->assertSame('in_progress', $this->sectorStatus($sectorId));
        $this->assertSame(['en_route>in_progress'], $this->sectorMoves($sectorId));
    }

    public function testASectorIsNotMovedOnTheFirstFixWhenThePhoneGivesNoSpeed(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'en_route');

        $this->fix($this->member, 0.0);

        $this->assertSame('en_route', $this->sectorStatus($sectorId), 'one fix could be a car going over the area');
        $this->assertSame([], $this->sectorMoves($sectorId));
    }

    public function testAPhoneThatReportsItIsWalkingStartsTheSearchOnTheFirstFix(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'en_route');

        $this->fix($this->member, 0.0, 10.0, 5, 1.1);

        $this->assertSame('in_progress', $this->sectorStatus($sectorId));
    }

    public function testDrivingAcrossASectorDoesNotStartTheSearch(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'en_route');

        $this->fix($this->member, 0.0, 10.0, 5, 12.0);
        $this->ageTheDwell($this->member);
        $this->fix($this->member, 0.0, 10.0, 5, 12.0);

        $this->assertSame('en_route', $this->sectorStatus($sectorId));
    }

    public function testASectorTheTeamHasNotReceivedIsLeftAlone(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'assigned', false);

        $this->fix($this->member, 0.0, 10.0, 5, 0.0);

        $this->assertSame('assigned', $this->sectorStatus($sectorId), '«Ελήφθη» comes first, as the page insists');
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testASectorSentBackOrAlreadyBeingSearchedIsLeftAlone(): void
    {
        $this->setAuto(true);
        $back = $this->sector($this->teamId, 'needs_recheck');
        $searching = $this->sector($this->teamId, 'in_progress');
        $done = $this->sector($this->teamId, 'completed');

        $this->fix($this->member, 0.0, 10.0, 5, 0.0);

        $this->assertSame('needs_recheck', $this->sectorStatus($back), 'a recheck is resumed by the team, not by standing where they already are');
        $this->assertSame('in_progress', $this->sectorStatus($searching));
        $this->assertSame('completed', $this->sectorStatus($done));
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testAnotherTeamsSectorAndAnUnassignedOneAreLeftAlone(): void
    {
        $this->setAuto(true);
        $theirs = $this->sector($this->otherTeamId, 'en_route');
        $nobodys = $this->sector(null, 'not_started');

        $this->fix($this->member, 0.0, 10.0, 5, 0.0);

        $this->assertSame('en_route', $this->sectorStatus($theirs));
        $this->assertSame('not_started', $this->sectorStatus($nobodys));
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testASectorTheTeamDeclinedIsLeftAlone(): void
    {
        $this->setAuto(true);
        $sectorId = $this->sector($this->teamId, 'en_route');
        $this->assertNull(declineMissionOrder($this->mission(), 'sector', $sectorId, $this->member, 'Άννα Α.', 'no_access', null, true));

        $this->fix($this->member, 0.0, 10.0, 5, 0.0);

        $this->assertSame('en_route', $this->sectorStatus($sectorId), 'a team that said «Δεν μπορώ» has not started searching');
    }

    public function testASectorIsNeverAskedAboutNorMovedWithTheSwitchOffOrOnAVagueFix(): void
    {
        $sectorId = $this->sector($this->teamId, 'en_route');

        $this->setAuto(false);
        $this->fix($this->member, 0.0, 10.0, 5, 0.0);
        $this->setAuto(true);
        $this->fix($this->member, 0.0, 40.0, 5, 0.0);          // ±40 m: not sure enough

        $this->assertSame('en_route', $this->sectorStatus($sectorId));
        $this->assertSame([], $this->questionsFor($this->member), 'there is no «Έφτασες;» for a sector');
        $this->assertSame([], $this->arrivedNoticesFor($this->member));
    }

    public function testARoutePointArrivalIsRecordedForTheNextPointOnly(): void
    {
        $this->setAuto(true);
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $this->teamId, 'Περίπολος', $this->adminId]
        );
        dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $this->member]);
        $first = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)", [$routeId, self::LAT + 0.01, self::LNG]);
        $second = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 2, ?, ?)", [$routeId, self::LAT, self::LNG]);
        foreach ([$first, $second] as $wpId) {
            dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$wpId, $routeId, $this->teamId]);
        }

        // At point 2 first: not the one the route is waiting for.
        $this->arriveByGps($this->member);
        $this->assertNull(dbFetchValue("SELECT arrived_at FROM mission_route_progress WHERE waypoint_id = ?", [$second]));

        // At point 1: that is the one.
        $this->arriveByGps($this->member, 0.01);
        $row = dbFetchOne("SELECT arrived_at, arrived_by, arrived_distance_m FROM mission_route_progress WHERE waypoint_id = ?", [$first]);
        $this->assertNotNull($row['arrived_at']);
        $this->assertSame($this->member, (int) $row['arrived_by']);
        $audits = dbFetchAll("SELECT notes FROM audit_logs WHERE action = 'arrive_route_waypoint' AND record_id = ?", [$first]);
        $this->assertCount(1, $audits);
        $this->assertSame('auto_gps', json_decode($audits[0]['notes'], true)['new']['via']);
        $this->assertCount(1, $this->arrivedNoticesFor($this->member));
    }
}
