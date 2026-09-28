<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Δεν μπορώ» at a route point and command's «Ξεκλείδωμα» (v3.350.0). A point
 * can ask for a photo, a video and a note, and «Ολοκληρώθηκε» waits for them.
 * When one cannot be had, the team says so with a reason and command is asked;
 * «Ξεκλείδωμα» closes the point without it and sends the team on. «Μετάβαση»
 * may no longer close such a point on the team's own say-so.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class RouteWaypointUnlockTest extends TestCase
{
    private const LAT = 35.2000000;
    private const LNG = 24.9000000;

    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $member;
    private int $teammate;
    private int $stranger;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Unlock Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Unlock Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );
        $this->member = $this->makeVolunteer('Άννα Α.');
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.');
        $this->stranger = $this->makeUser('Δήμητρα Δ.');
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $this->stranger, PARTICIPATION_APPROVED]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'ru-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$this->teamId, $this->missionId, $id]
        );
        dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
        return $id;
    }

    private function mission(): array
    {
        return dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
    }

    /**
     * A route for the team's two members, with its order. Each point is
     * [label, require_photo, require_video, require_note]. Returns
     * [routeId, orderId, [waypointId, ...]].
     */
    private function sendRoute(array $points): array
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, created_by) VALUES (?, 'route', ?)",
            [$this->missionId, $this->adminId]
        );
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, order_id, title, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamId, $orderId, 'Περίπολος', $this->adminId]
        );
        foreach ([$this->member, $this->teammate] as $userId) {
            dbInsert("INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)", [$orderId, $userId, $this->teamId]);
            dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $userId]);
        }
        $ids = [];
        foreach ($points as $i => [$label, $photo, $video, $note]) {
            $wpId = (int) dbInsert(
                "INSERT INTO mission_route_waypoints (route_id, seq, lat, lng, label, require_photo, require_video, require_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$routeId, $i + 1, self::LAT, self::LNG, $label, $photo, $video, $note]
            );
            dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$wpId, $routeId, $this->teamId]);
            $ids[] = $wpId;
        }
        return [$routeId, $orderId, $ids];
    }

    private function arrive(int $waypointId): void
    {
        dbExecute("UPDATE mission_route_progress SET departed_at = NOW(), arrived_at = NOW(), arrived_by = ? WHERE waypoint_id = ?", [$this->member, $waypointId]);
    }

    private function cant(int $waypointId, int $userId, string $reason = 'device', ?string $note = null): ?string
    {
        $wp = loadWaypointForAction($waypointId, $this->missionId, $userId);
        $name = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$userId]);
        return reportRouteWaypointCant($this->mission(), $wp, $userId, $name, $reason, $note);
    }

    private function unlock(int $waypointId): ?string
    {
        return unlockRouteWaypoint($this->mission(), loadWaypointForAction($waypointId, $this->missionId, $this->adminId), $this->adminId);
    }

    private function progress(int $waypointId): array
    {
        return dbFetchOne("SELECT * FROM mission_route_progress WHERE waypoint_id = ?", [$waypointId]);
    }

    private function pageWaypoint(int $userId, int $routeId, int $waypointId, bool $manage = false): array
    {
        foreach (loadRoutesForUser($this->missionId, $userId, $manage) as $route) {
            if ($route['id'] !== $routeId) {
                continue;
            }
            foreach ($route['waypoints'] as $wp) {
                if ($wp['id'] === $waypointId) {
                    return $wp;
                }
            }
        }
        $this->fail("Waypoint $waypointId is not on user $userId's page");
    }

    private function notices(int $userId, string $popupInfo): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND data LIKE ?",
            [$userId, '%"popupInfo":"' . $popupInfo . '"%']
        );
    }

    public function testTheTeamAsksToBeLetPastAPointWhosePhotoItCannotTake(): void
    {
        [$routeId, , [$wp1]] = $this->sendRoute([['Γέφυρα', 1, 0, 0], ['Πηγή', 0, 0, 0]]);
        $this->arrive($wp1);

        $this->assertNull($this->cant($wp1, $this->member, 'device', 'Έσπασε η κάμερα'));

        $row = $this->progress($wp1);
        $this->assertNotNull($row['cant_at']);
        $this->assertSame($this->member, (int) $row['cant_by']);
        $this->assertSame('device', $row['cant_reason']);
        $this->assertNull($row['completed_at'], 'Asking closes nothing: the point waits for command.');

        // Both the team and command see it on the page, reason as a code.
        $onPage = $this->pageWaypoint($this->teammate, $routeId, $wp1);
        $this->assertSame('device', $onPage['cant']['reason']);
        $this->assertSame('Έσπασε η κάμερα', $onPage['cant']['note']);
        $this->assertSame('Άννα Α.', $onPage['cant']['by']);
        $this->assertNull($onPage['unlocked']);

        // Command is asked loudly, with the point, as a popup that can unlock it.
        $notices = $this->notices($this->adminId, 'mission_route_unlock_request');
        $this->assertCount(1, $notices);
        $data = json_decode($notices[0]['data'], true);
        $this->assertSame($this->missionId, $data['bannerMission']);
        $this->assertSame($wp1, $data['waypointId']);
        $this->assertSame($routeId, $data['routeId']);
        $this->assertStringContainsString('1 «Γέφυρα»', $notices[0]['message']);
        $ref = notificationPopupRef($data);
        $this->assertSame('info', $ref['kind']);
        $this->assertSame($wp1, $ref['waypointId']);

        // A teammate pressing too answers nothing new: the first press stands.
        $this->assertNull($this->cant($wp1, $this->teammate, 'unsafe'));
        $this->assertSame($this->member, (int) $this->progress($wp1)['cant_by']);
        $this->assertCount(1, $this->notices($this->adminId, 'mission_route_unlock_request'));
    }

    public function testAskingNeedsAReasonAMemberAndSomethingMissing(): void
    {
        [, , [$withPhoto, $plain]] = $this->sendRoute([['Γέφυρα', 1, 0, 0], ['Πηγή', 0, 0, 0]]);

        $this->assertNotNull($this->cant($withPhoto, $this->member, 'bored'), 'An unknown reason is refused.');
        $this->assertNotNull($this->cant($withPhoto, $this->member, 'other'), '«Άλλο» needs a note.');
        $this->assertNotNull($this->cant($withPhoto, $this->stranger, 'device'), 'Only the route\'s own people.');
        $this->assertNotNull($this->cant($plain, $this->member, 'device'), 'A point that asks for nothing has nothing to excuse.');
        $this->assertNull($this->progress($withPhoto)['cant_at']);

        $this->assertNull($this->cant($withPhoto, $this->member, 'other', 'Απαγορεύεται από τον φύλακα'));
        $this->assertSame('other', $this->progress($withPhoto)['cant_reason']);
    }

    public function testUnlockClosesThePointAndSendsTheTeamOn(): void
    {
        [$routeId, , [$wp1, $wp2]] = $this->sendRoute([['Γέφυρα', 1, 1, 0], ['Πηγή', 0, 0, 0]]);
        $this->arrive($wp1);
        $this->cant($wp1, $this->member, 'not_allowed');

        $this->assertNull($this->unlock($wp1));

        $row = $this->progress($wp1);
        $this->assertNotNull($row['completed_at']);
        $this->assertNotNull($row['unlocked_at']);
        $this->assertSame($this->adminId, (int) $row['unlocked_by']);
        $this->assertSame($this->adminId, (int) $row['completed_by'], 'Closed by command, not by the team.');
        $this->assertSame(2, currentWaypointSeq($routeId), 'The next point is the team\'s now.');
        $this->assertNull(dbFetchValue("SELECT completed_at FROM mission_routes WHERE id = ?", [$routeId]));

        $onPage = $this->pageWaypoint($this->member, $routeId, $wp1);
        $this->assertSame('Unlock Admin', $onPage['unlocked']['by']);

        // Both members are told, by popup, to go on — naming the next point.
        foreach ([$this->member, $this->teammate] as $userId) {
            $notices = $this->notices($userId, 'mission_route_unlocked');
            $this->assertCount(1, $notices);
            $this->assertStringContainsString('2 «Πηγή»', $notices[0]['message']);
        }

        // Answered: a second press finds the point closed.
        $this->assertNotNull($this->unlock($wp1));
        $this->assertNull($this->progress($wp2)['completed_at']);
    }

    public function testUnlockingTheLastPointCompletesTheRoute(): void
    {
        [$routeId, $orderId, [$wp1]] = $this->sendRoute([['Κορυφή', 1, 0, 0]]);
        $this->arrive($wp1);

        // Asked by radio: nothing was pressed on the phone, but the team is at
        // the point without its photo, so command may still let it on.
        $this->assertNull($this->unlock($wp1));

        $this->assertNotNull(dbFetchValue("SELECT completed_at FROM mission_routes WHERE id = ?", [$routeId]));
        $this->assertSame(0, (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_order_recipients WHERE order_id = ? AND fulfilled_at IS NULL", [$orderId]
        ));
        $notices = $this->notices($this->member, 'mission_route_unlocked');
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('τελευταίο', $notices[0]['message']);
    }

    public function testNothingToUnlockWhereNothingIsMissing(): void
    {
        [, , [$plain, $noted]] = $this->sendRoute([['Πηγή', 0, 0, 0], ['Σταθμός', 0, 0, 1]]);
        dbExecute("UPDATE mission_route_progress SET note = 'Όλα καλά' WHERE waypoint_id = ?", [$noted]);

        $this->assertNotNull($this->unlock($plain));
        $this->assertNotNull($this->unlock($noted), 'The note it asked for is there: the team can press «Ολοκληρώθηκε».');
        $this->assertNull($this->progress($plain)['completed_at']);
    }

    public function testMovingOnPastAPointWithSomethingMissingIsBlockedUntilCommandAnswers(): void
    {
        [$routeId, , [$wp1, $wp2, $wp3]] = $this->sendRoute([['Γέφυρα', 1, 0, 0], ['Σταθμός', 0, 0, 1], ['Πηγή', 0, 0, 0]]);

        $blocker = routeJumpBlocker($routeId, 3);
        $this->assertSame($wp1, (int) $blocker['waypoint']['id']);
        $this->assertSame(['route.deliverable_photo'], $blocker['missing']);
        $this->assertNull(routeJumpBlocker($routeId, 1), 'Nothing before the first point.');

        $this->arrive($wp1);
        $this->unlock($wp1);
        $blocker = routeJumpBlocker($routeId, 3);
        $this->assertSame($wp2, (int) $blocker['waypoint']['id'], 'Next in the way: the point asking for a note.');
        $this->assertSame(['route.deliverable_note'], $blocker['missing']);

        dbExecute("UPDATE mission_route_progress SET note = 'Κλειστό' WHERE waypoint_id = ?", [$wp2]);
        $this->assertNull(routeJumpBlocker($routeId, 3));

        // Command's «Παράλειψη» clears the way as well.
        dbExecute("UPDATE mission_route_progress SET note = NULL WHERE waypoint_id = ?", [$wp2]);
        $this->assertNotNull(routeJumpBlocker($routeId, 3));
        dbExecute("UPDATE mission_route_progress SET skipped_at = NOW(), skipped_by = ? WHERE waypoint_id = ?", [$this->adminId, $wp2]);
        $this->assertNull(routeJumpBlocker($routeId, 3));
        $this->assertSame(3, currentWaypointSeq($routeId));
        $this->assertSame($wp3, $this->pageWaypoint($this->member, $routeId, $wp3)['id']);
    }

    public function testASkippedPointReachesThePageAsSkipped(): void
    {
        // Every screen tests wp.skipped_at to tell a closed point from an open
        // one; until v3.350.0 only skipped_at_display was sent, so a point
        // command skipped stayed the team's "current" point.
        [$routeId, , [$wp1]] = $this->sendRoute([['Γέφυρα', 0, 0, 0], ['Πηγή', 0, 0, 0]]);
        dbExecute("UPDATE mission_route_progress SET skipped_at = NOW(), skipped_by = ? WHERE waypoint_id = ?", [$this->adminId, $wp1]);

        $onPage = $this->pageWaypoint($this->member, $routeId, $wp1);
        $this->assertNotNull($onPage['skipped_at']);
        $this->assertNotFalse(strtotime($onPage['skipped_at']));
    }

    public function testMissingDeliverablesAreNamedInTheReadersLanguage(): void
    {
        [, , [$wp1]] = $this->sendRoute([['Γέφυρα', 1, 1, 1]]);
        $wp = loadWaypointForAction($wp1, $this->missionId, $this->member);

        $this->assertSame(['route.deliverable_photo', 'route.deliverable_video', 'route.deliverable_note'], routeWaypointMissingKeys($wp, null));
        $this->assertSame(['route.deliverable_photo', 'route.deliverable_video'], routeWaypointMissingKeys($wp, 'Σημείωση στο ίδιο πάτημα'));
        $this->assertSame([t('route.deliverable_photo', [], 'en'), t('route.deliverable_video', [], 'en'), t('route.deliverable_note', [], 'en')], missingRouteDeliverables($wp, null, 'en'));
        $this->assertSame('1 «Γέφυρα»', routeWaypointRef($wp));
        $this->assertSame('4', routeWaypointRef(['seq' => 4, 'label' => null]));
    }
}
