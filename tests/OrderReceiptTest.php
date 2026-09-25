<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Ελήφθη» has two doors since v3.332.0: the Action Room page and the button on
 * the Android app's notification (mobile-order-ack.php). Both go through
 * receiveMissionOrder() / receiveMissionDispatch() / receiveMissionSector(),
 * and what matters about those is pinned here: a second press records nothing
 * new, an order for somebody else is refused, and a receipt from the phone —
 * which has no session for logAudit() to read — still says who pressed it.
 *
 * notificationPopupRef() is the other shared piece: the page reads it to open
 * an order as a popup, mobile-alerts.php to decide which order a notification's
 * button confirms. If the two ever read a notification differently, the phone
 * would confirm one order while the page showed another.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class OrderReceiptTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $outsider;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Receipt Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Receipt Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->member = $this->makeVolunteer('Άννα Α.', $this->teamId);
        $this->outsider = $this->makeVolunteer('Βασίλης Β.', $this->otherTeamId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'rc-' . uniqid('', true) . '@example.invalid', 'x']
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

    private function sendTask(int $to): array
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, 'task', ?, ?)",
            [$this->missionId, 'Έλεγξε το ρέμα', $this->adminId]
        );
        $recipientId = (int) dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
            [$orderId, $to, $this->teamId]
        );
        return [$orderId, $recipientId];
    }

    private function auditRows(string $action, int $recordId): array
    {
        return dbFetchAll("SELECT notes FROM audit_logs WHERE action = ? AND record_id = ?", [$action, $recordId]);
    }

    public function testEachKindOfNotificationIsReadAsTheOrderItAnnounces(): void
    {
        $this->assertSame(['kind' => 'order', 'id' => 12], notificationPopupRef(['orderId' => 12, 'bannerMission' => 3]));
        $this->assertSame(['kind' => 'dispatch', 'id' => 5], notificationPopupRef(['dispatchId' => '5']));
        $this->assertSame(['kind' => 'sector', 'id' => 9], notificationPopupRef(['sectorId' => 9]));
        $this->assertSame('info', notificationPopupRef(['popupInfo' => 'mission_route_cancelled', 'routeId' => 4])['kind']);
        $this->assertNull(notificationPopupRef(['url' => 'war-room.php?id=1', 'bannerMission' => 1]), 'a bystander admin\'s copy names no order');
        $this->assertNull(notificationPopupRef(null));
        $this->assertNull(notificationPopupRef('not json'));
    }

    public function testASecondPressRecordsNothingNew(): void
    {
        [$orderId, $recipientId] = $this->sendTask($this->member);

        $this->assertNull(receiveMissionOrder($orderId, $this->member, 'Άννα Α.', 'app_notification'));
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = '2026-01-01 10:00:00' WHERE id = ?", [$recipientId]);
        $this->assertNull(receiveMissionOrder($orderId, $this->member, 'Άννα Α.'), 'pressing again is not an error');

        $this->assertSame(
            '2026-01-01 10:00:00',
            dbFetchValue("SELECT acknowledged_at FROM mission_order_recipients WHERE id = ?", [$recipientId]),
            'the first receipt is the one that counts — the page and the phone both pressing must not move it'
        );
        $this->assertCount(1, $this->auditRows('acknowledge_mission_order', $recipientId));
    }

    public function testAnOrderForSomebodyElseIsRefused(): void
    {
        [$orderId, $recipientId] = $this->sendTask($this->member);

        $this->assertNotNull(receiveMissionOrder($orderId, $this->outsider, 'Βασίλης Β.', 'app_notification'));
        $this->assertNull(dbFetchValue("SELECT acknowledged_at FROM mission_order_recipients WHERE id = ?", [$recipientId]));
    }

    public function testAReceiptFromThePhoneStillSaysWhoPressedIt(): void
    {
        [$orderId, $recipientId] = $this->sendTask($this->member);
        receiveMissionOrder($orderId, $this->member, 'Άννα Α.', 'app_notification');

        $notes = json_decode((string) $this->auditRows('acknowledge_mission_order', $recipientId)[0]['notes'], true);
        $this->assertSame($this->member, $notes['new']['user_id']);
        $this->assertSame('app_notification', $notes['new']['via']);
    }

    public function testAReceiptFromThePageIsAuditedAsBefore(): void
    {
        [$orderId, $recipientId] = $this->sendTask($this->member);
        receiveMissionOrder($orderId, $this->member, 'Άννα Α.');

        $notes = json_decode((string) $this->auditRows('acknowledge_mission_order', $recipientId)[0]['notes'], true);
        $this->assertSame(['order_id' => $orderId], $notes['new']);
    }

    public function testADispatchIsReceivedOnlyByItsOwnTeamAndOnlyOnce(): void
    {
        $dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode(['lat' => 35.1, 'lng' => 25.1]), 'Ρέμα', $this->adminId]
        );

        $this->assertNotNull(receiveMissionDispatch($this->mission(), $dispatchId, $this->outsider, 'Βασίλης Β.', 'app_notification'));
        $this->assertNull(receiveMissionDispatch($this->mission(), $dispatchId, $this->member, 'Άννα Α.', 'app_notification'));
        $this->assertNull(receiveMissionDispatch($this->mission(), $dispatchId, $this->member, 'Άννα Α.'));

        $this->assertSame(
            [$this->member],
            array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_dispatch_receipts WHERE dispatch_id = ?", [$dispatchId]), 'user_id'))
        );
    }

    public function testASectorIsReceivedByItsTeamOrByCommandStandingIn(): void
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        $sectorId = (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, created_by) VALUES (?, ?, ?, ?, ?, 'assigned', ?)",
            [$this->missionId, $areaId, $this->teamId, 'Τομέας Α', json_encode([[35.1, 25.1], [35.15, 25.1], [35.15, 25.15]]), $this->adminId]
        );

        $this->assertNotNull(receiveMissionSector($this->mission(), $sectorId, $this->outsider, 'Βασίλης Β.', false, true, 'app_notification'));
        $this->assertNull(dbFetchValue("SELECT acknowledged_at FROM mission_search_sectors WHERE id = ?", [$sectorId]));

        $this->assertNotNull(
            receiveMissionSector($this->mission(), $sectorId, $this->member, 'Άννα Α.', false, false),
            'a team member who is not an approved participant any more cannot confirm it'
        );

        $this->assertNull(receiveMissionSector($this->mission(), $sectorId, $this->adminId, 'Receipt Admin', true, false));
        $this->assertSame($this->adminId, (int) dbFetchValue("SELECT acknowledged_by FROM mission_search_sectors WHERE id = ?", [$sectorId]));
    }
}
