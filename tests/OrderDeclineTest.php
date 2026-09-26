<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Δεν μπορώ» on an order (v3.334.0): the order goes back to command with a
 * reason. A per-person order is the person's answer; a dispatch, route or
 * sector is the team's, and the first member to press answers for all of them.
 * It ends when it is taken back («Τελικά μπορώ»), when the team moves the order
 * on regardless, or when command reassigns it — and until then it counts as an
 * answer everywhere command looks, so nothing keeps asking the coordinator to
 * chase a team that already replied.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class OrderDeclineTest extends TestCase
{
    private const LAT = 35.2000000;
    private const LNG = 24.9000000;

    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $teammate;
    private int $stranger;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = $this->makeUser('Decline Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Decline Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->member = $this->makeVolunteer('Άννα Α.', $this->teamId);
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.', $this->teamId);
        $this->stranger = $this->makeVolunteer('Δήμητρα Δ.', $this->otherTeamId);
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
            [$name, 'od-' . uniqid('', true) . '@example.invalid', 'x']
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
        // Taking part in the Action Room, so team notifications reach them.
        dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
        return $id;
    }

    private function mission(): array
    {
        return dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
    }

    private function sendOrder(string $type, array $to, int $minutesAgo = 0, ?string $text = 'Έλεγξε τη γέφυρα'): int
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by, created_at) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $type, $text, $this->adminId, date('Y-m-d H:i:s', time() - $minutesAgo * 60)]
        );
        foreach ($to as $userId) {
            dbInsert(
                "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
                [$orderId, $userId, getUserTeamIdForMission($this->missionId, $userId)]
            );
        }
        return $orderId;
    }

    private function sendPoint(?int $teamId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => self::LAT, 'lng' => self::LNG]), 'Πηγή', $this->adminId]
        );
    }

    private function assignSector(int $teamId): int
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by) VALUES (?, ?, ?, ?, ?, 'assigned', ?, ?)",
            [$this->missionId, $areaId, $teamId, 'Α3', json_encode([[35.1, 25.1], [35.15, 25.1], [35.15, 25.15]]), date('Y-m-d H:i:s', time() - 1200), $this->adminId]
        );
    }

    /** A route for the team's two members, with its order, and one point at LAT/LNG. */
    private function sendRoute(): array
    {
        $orderId = $this->sendOrder('route', [$this->member, $this->teammate], 0, null);
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, order_id, title, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamId, $orderId, 'Περίπολος', $this->adminId]
        );
        foreach ([$this->member, $this->teammate] as $userId) {
            dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $userId]);
        }
        $wpId = (int) dbInsert(
            "INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)",
            [$routeId, self::LAT, self::LNG]
        );
        dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$wpId, $routeId, $this->teamId]);
        return [$routeId, $orderId];
    }

    private function decline(string $kind, int $id, int $userId, string $reason = 'no_access', ?string $note = null): ?string
    {
        $name = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$userId]);
        return declineMissionOrder($this->mission(), $kind, $id, $userId, $name, $reason, $note, true);
    }

    private function withdraw(string $kind, int $id, int $userId): ?string
    {
        $name = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$userId]);
        return withdrawOrderDecline($this->mission(), $kind, $id, $userId, $name, true);
    }

    private function myTask(int $userId, int $orderId): array
    {
        foreach (loadMyTaskOrdersForUser($this->missionId, $userId) as $task) {
            if ($task['order_id'] === $orderId) {
                return $task;
            }
        }
        $this->fail("Order $orderId is not in user $userId's list");
    }

    private function dispatchView(int $userId, int $dispatchId): array
    {
        foreach (loadMissionDispatchesForUser($this->missionId, $userId, false, true) as $dispatch) {
            if ($dispatch['id'] === $dispatchId) {
                return $dispatch;
            }
        }
        $this->fail("Dispatch $dispatchId is not visible to user $userId");
    }

    private function sectorView(int $userId, int $sectorId, bool $canManage = false): array
    {
        foreach (loadMissionSectorsForUser($this->missionId, $userId, $canManage, !$canManage) as $sector) {
            if ($sector['id'] === $sectorId) {
                return $sector;
            }
        }
        $this->fail("Sector $sectorId is not visible to user $userId");
    }

    private function ackCard(string $key): array
    {
        foreach (loadAckTrackerCardsForMission($this->missionId) as $card) {
            if ($card['key'] === $key) {
                return $card;
            }
        }
        $this->fail("No acknowledgement card $key");
    }

    private function notificationsFor(int $userId, string $code = ''): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND (title LIKE '%Δεν μπορώ%' OR title LIKE '%Τελικά μπορ%')",
            [$userId]
        );
    }

    private function declineRows(string $kind, int $id): array
    {
        return dbFetchAll("SELECT * FROM mission_order_declines WHERE target_kind = ? AND target_id = ? ORDER BY id", [$kind, $id]);
    }

    public function testAPersonHandsBackTheirOwnTaskAndCommandHearsItLoudly(): void
    {
        $orderId = $this->sendOrder('task', [$this->member, $this->teammate]);

        $this->assertNull($this->decline('order', $orderId, $this->member, 'no_access', 'Κόπηκε ο δρόμος'));

        $mine = $this->myTask($this->member, $orderId);
        $this->assertSame('no_access', $mine['declined']['reason']);
        $this->assertSame('Κόπηκε ο δρόμος', $mine['declined']['note']);
        $this->assertSame('Άννα Α.', $mine['declined']['by']);
        $this->assertNull($this->myTask($this->teammate, $orderId)['declined'],
            'A task sent to two people is two answers: one «Δεν μπορώ» does not speak for the other.');

        $staff = $this->notificationsFor($this->adminId);
        $this->assertCount(1, $staff);
        $this->assertStringContainsString('bannerMission', (string) $staff[0]['data'], 'Loud: banner, sound, the app\'s urgent channel.');
        $this->assertStringContainsString('Δεν υπάρχει πρόσβαση', $staff[0]['message']);
        $this->assertStringContainsString('Κόπηκε ο δρόμος', $staff[0]['message']);
        $this->assertSame([], $this->notificationsFor($this->teammate), 'A personal order tells no teammates.');

        $card = $this->ackCard('order:' . $orderId);
        $byName = array_column($card['people'], 'declined', 'name');
        $this->assertTrue($byName['Άννα Α.']);
        $this->assertFalse($byName['Γιώργος Γ.']);
        $this->assertCount(1, $card['declines']);
    }

    public function testTheReasonRules(): void
    {
        $orderId = $this->sendOrder('photo', [$this->member]);

        $this->assertSame(t('decline.pick_reason'), $this->decline('order', $orderId, $this->member, 'bored'));
        $this->assertSame(t('decline.other_needs_note'), $this->decline('order', $orderId, $this->member, 'other', '   '));
        $this->assertSame([], $this->declineRows('order', $orderId), 'Nothing is recorded for a refused request.');
        $this->assertNull($this->decline('order', $orderId, $this->member, 'other', 'Η κάμερα έσπασε'));

        $message = $this->sendOrder('message', [$this->member]);
        $this->assertSame(t('decline.not_supported'), $this->decline('order', $message, $this->member),
            'Information-only orders have nothing to decline: «Ελήφθη» is the whole order.');
        $this->assertSame(t('order.no_request_for_you'), $this->decline('order', $orderId, $this->stranger));

        $done = $this->sendOrder('task', [$this->member]);
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ?", [$done]);
        $this->assertSame(t('decline.already_done'), $this->decline('order', $done, $this->member));
        $this->assertFalse($this->myTask($this->member, $done)['can_decline']);
    }

    public function testTheFirstMemberAnswersForTheTeamAndAnyMemberCanTakeItBack(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $this->assertNull($this->decline('dispatch', $dispatchId, $this->member, 'unsafe'));
        $this->assertNull($this->decline('dispatch', $dispatchId, $this->teammate, 'busy'),
            'A second member answering is not an error to them…');
        $this->assertCount(1, $this->declineRows('dispatch', $dispatchId), '…and records nothing: the team already answered.');
        $this->assertCount(1, $this->notificationsFor($this->adminId), 'Command hears it once, not once per member.');
        $this->assertCount(1, $this->notificationsFor($this->teammate), 'The teammate is told the order left their list.');

        $view = $this->dispatchView($this->teammate, $dispatchId);
        $this->assertSame('unsafe', $view['my_declined']['reason'], 'Γιώργος sees his team as having declined, though Άννα pressed it.');
        $this->assertSame('Άννα Α.', $view['my_declined']['by']);
        $this->assertCount(1, $view['declines']);

        $this->assertNull($this->withdraw('dispatch', $dispatchId, $this->teammate));
        $this->assertNull($this->dispatchView($this->member, $dispatchId)['my_declined']);
        $this->assertNotNull($this->dispatchView($this->teammate, $dispatchId)['my_receipt'],
            '«Τελικά μπορώ» is also «Ελήφθη»: whoever says they will do it has received it.');
        $rows = $this->declineRows('dispatch', $dispatchId);
        $this->assertSame('withdrawn', $rows[0]['resolution']);
        $this->assertSame($this->teammate, (int) $rows[0]['resolved_by']);
        $this->assertCount(2, $this->notificationsFor($this->adminId), '«Τελικά μπορώ» is as loud as «Δεν μπορώ».');

        $this->assertNull($this->decline('dispatch', $dispatchId, $this->member, 'injury'));
        $this->assertCount(2, $this->declineRows('dispatch', $dispatchId), 'Declining again is a new row; the first stays as history.');
        $this->assertSame('injury', $this->dispatchView($this->teammate, $dispatchId)['my_declined']['reason']);
    }

    public function testADispatchToEveryoneIsAnsweredTeamByTeam(): void
    {
        $dispatchId = $this->sendPoint(null);

        $this->decline('dispatch', $dispatchId, $this->member);

        $this->assertNotNull($this->dispatchView($this->teammate, $dispatchId)['my_declined']);
        $this->assertNull($this->dispatchView($this->stranger, $dispatchId)['my_declined'],
            'Another team sent the same dispatch still has it.');
        $this->assertSame(t('dispatch.not_your_team'), $this->decline('dispatch', $this->sendPoint($this->teamId), $this->stranger));
    }

    public function testMovingOnAnywayEndsTheDecline(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);
        $this->decline('dispatch', $dispatchId, $this->member);
        $this->assertNull(advanceMissionDispatch($this->mission(), $dispatchId, $this->teammate, 'Γιώργος Γ.', 'depart'));
        $this->assertNull($this->dispatchView($this->member, $dispatchId)['my_declined'], 'The team set off after all, so it could.');
        $this->assertSame('progress', $this->declineRows('dispatch', $dispatchId)[0]['resolution']);

        $orderId = $this->sendOrder('location', [$this->member]);
        $this->decline('order', $orderId, $this->member);
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ?", [$orderId]);
        resolveFulfilledOrderDeclines($this->member);
        $this->assertNull($this->myTask($this->member, $orderId)['declined'], 'The location was sent after all.');
    }

    public function testASectorIsTheTeamsAndReassigningItAnswersTheDecline(): void
    {
        $sectorId = $this->assignSector($this->teamId);
        $this->assertSame(t('sector.not_your_team'), $this->decline('sector', $sectorId, $this->stranger));

        $this->decline('sector', $sectorId, $this->teammate, 'unsafe', 'Κατολίσθηση');
        $this->assertSame('unsafe', $this->sectorView($this->member, $sectorId)['declined']['reason']);
        $this->assertSame('Κατολίσθηση', $this->sectorView($this->adminId, $sectorId, true)['declined']['note'],
            'Command sees it on the sector itself, where it reassigns.');
        $card = $this->ackCard('sector:' . $sectorId);
        $this->assertTrue($card['people'][0]['declined']);

        // What mission-sector.php's assign action does when the team changes.
        dbExecute("UPDATE mission_search_sectors SET team_id = ? WHERE id = ?", [$this->otherTeamId, $sectorId]);
        resolveOrderDeclinesReassigned('sector', $sectorId, $this->adminId);
        $this->assertNull($this->sectorView($this->stranger, $sectorId)['declined'], 'The new team starts with a clean order.');
        $this->assertSame('reassigned', $this->declineRows('sector', $sectorId)[0]['resolution']);
    }

    public function testARouteIsDeclinedByItsWholeGroup(): void
    {
        [$routeId, $orderId] = $this->sendRoute();

        $this->decline('route', $routeId, $this->member, 'busy');

        foreach (loadRoutesForUser($this->missionId, $this->teammate, false) as $route) {
            $this->assertSame('busy', $route['declined']['reason']);
        }
        $card = $this->ackCard('order:' . $orderId);
        $this->assertSame([true, true], array_column($card['people'], 'declined'), 'Every member of the route shows ✗.');
        $this->assertCount(1, $card['declines']);

        // «Έφτασες;» is not asked of a group that said it is not going.
        checkArrivalPrompts($this->missionId, $this->teammate, $this->teamId, self::LAT, self::LNG, 8.0, 3);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_prompts WHERE user_id = ?", [$this->teammate]));

        $this->assertNull($this->withdraw('route', $routeId, $this->teammate));
        $this->assertNotNull(dbFetchValue("SELECT acknowledged_at FROM mission_order_recipients WHERE order_id = ? AND user_id = ?", [$orderId, $this->teammate]));
        checkArrivalPrompts($this->missionId, $this->teammate, $this->teamId, self::LAT, self::LNG, 8.0, 3);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_prompts WHERE user_id = ?", [$this->teammate]),
            'Once taken back, the route is theirs again, question and all.');
    }

    public function testTheArrivalQuestionSkipsADispatchTheTeamDeclined(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);
        $this->decline('dispatch', $dispatchId, $this->member);

        checkArrivalPrompts($this->missionId, $this->teammate, $this->teamId, self::LAT, self::LNG, 8.0, 3);

        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_arrival_prompts WHERE target_id = ? AND target_kind = 'dispatch'", [$dispatchId]));
    }

    public function testCommandIsNotAskedToChaseAnOrderTheFieldAnswered(): void
    {
        $orderId = $this->sendOrder('task', [$this->member, $this->teammate], 40);
        $sectorId = $this->assignSector($this->teamId);
        $dispatchId = $this->sendPoint($this->teamId);
        dbExecute("UPDATE mission_dispatch_points SET created_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 2400), $dispatchId]);

        $this->decline('order', $orderId, $this->member);
        receiveMissionOrder($orderId, $this->teammate, 'Γιώργος Γ.');
        $this->decline('sector', $sectorId, $this->member);
        $this->decline('dispatch', $dispatchId, $this->member);

        $raw = collectMissionAssistantRaw($this->missionId, $this->adminId, [$this->shiftId], null, time());
        $this->assertNotContains($orderId, array_map('intval', array_column($raw['orders'], 'id')),
            'One received, one declined: nobody is left to chase.');
        $this->assertNotContains($sectorId, array_map('intval', array_column($raw['sector'], 'id')));
        $this->assertNotContains($dispatchId, array_map('intval', array_column($raw['dispatch'], 'id')));
        $this->assertCount(3, $raw['declines']);

        $panel = assembleMissionAssistantItems($raw, null, time(), 'el', 600);
        $declined = array_values(array_filter($panel['pending'], fn($item) => $item['kind'] === 'declined'));
        $this->assertCount(3, $declined);
        $this->assertSame('high', $declined[0]['sev']);
        $this->assertSame(0, $panel['counts']['overdue'], 'An answer from the field is not an overdue order.');

        $seen = assembleMissionAssistantItems($raw, time() + 1, time(), 'el', 600);
        $this->assertSame([], array_values(array_filter($seen['pending'], fn($item) => $item['kind'] === 'declined')),
            '«Το είδα» clears them: a task has no command-side cancel, so nothing else ever would.');
    }

    public function testTheActivityLogRecordsBothAnswers(): void
    {
        $sectorId = $this->assignSector($this->teamId);
        $this->decline('sector', $sectorId, $this->member, 'unsafe', 'Πλημμύρα');
        $this->withdraw('sector', $sectorId, $this->teammate);

        $texts = array_column(loadMissionActivityEventsForReport($this->missionId), 'text');
        $declined = array_values(array_filter($texts, fn($t) => str_contains($t, '«Δεν μπορώ»')));
        $withdrawn = array_values(array_filter($texts, fn($t) => str_contains($t, '«Τελικά μπορώ»')));
        $this->assertCount(1, $declined);
        $this->assertStringContainsString('Δεν είναι ασφαλές', $declined[0]);
        $this->assertStringContainsString('Πλημμύρα', $declined[0]);
        $this->assertCount(1, $withdrawn);
        $this->assertStringContainsString('Γιώργος Γ.', $withdrawn[0]);
    }
}
