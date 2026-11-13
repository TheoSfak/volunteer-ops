<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * M4 (project_lora_meshtastic_fallback_brainstorm): an order sent to a bound
 * node over LoRa, and its "Ελήφθη" coming back the same way. The radio bytes
 * (ORDER_MSG, PORT.PRIVATE) are assets/js/mesh-protocol.js's business and
 * pinned in tests/js/mesh-protocol.test.js; this covers only what the server
 * does once the gateway (assets/js/mesh-gateway.js) has decoded a packet and
 * asks mesh-ingest.php to act on it: which orders are offered to send, and
 * that an ack arriving with via='mesh' behaves exactly like any other
 * receiveMissionOrder() caller (see tests/OrderReceiptTest.php for the
 * shared rules - idempotent, refuses a stranger's order, records who/how).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class MeshOrderDownlinkTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $alice;
    private int $outsider;

    private const NODE_ALICE = 0x570dee8c;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Downlink Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Downlink Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        forgetActionRoomParticipantIds($this->missionId);
        $this->alice = $this->makeParticipant('Alice A.');
        $this->outsider = $this->makeUser('Not On Mission');
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
            [$name, 'downlink-' . uniqid('', true) . '@example.invalid', 'x']
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

    private function orderFor(int $userId, string $type = 'task', ?string $text = 'Έλεγξε το ρέμα'): int
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $type, $text, $this->adminId]
        );
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)", [$orderId, $userId]);
        return $orderId;
    }

    public function testPendingOrdersListsOnlyWhatThisPersonHasNotAcknowledgedYet(): void
    {
        $pending = $this->orderFor($this->alice, 'location', null);
        $already = $this->orderFor($this->alice, 'task', 'done already');
        receiveMissionOrder($already, $this->alice, 'Alice A.');

        $orders = meshPendingOrders($this->missionId, $this->alice);

        $this->assertCount(1, $orders);
        $this->assertSame($pending, $orders[0]['id']);
        $this->assertSame('Θέση', $orders[0]['label']);
    }

    public function testPendingOrdersIgnoresAnotherMissionsOrderForTheSamePerson(): void
    {
        $otherMissionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, (SELECT id FROM mission_types ORDER BY id LIMIT 1), ?, 1)",
            ['Other Mission', 'Χανιά', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), STATUS_OPEN]
        );
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, created_by) VALUES (?, 'location', ?)",
            [$otherMissionId, $this->adminId]
        );
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)", [$orderId, $this->alice]);

        $this->assertSame([], meshPendingOrders($this->missionId, $this->alice));
    }

    public function testOrderLabelTruncatesTaskTextAndTranslatesTheType(): void
    {
        $this->assertSame('Θέση', meshOrderLabel('location', null));
        $this->assertSame(
            'Εργασία: ' . str_repeat('α', 40),
            meshOrderLabel('task', str_repeat('α', 60)),
            'the label carries only a lookup hint, never the full instructions a volunteer would need over LoRa'
        );
        $this->assertSame('Φόρτιση κινητού', meshOrderLabel('charge_phone', null));
    }

    public function testAnAckWithViaMeshIsIndistinguishableFromAnyOtherReceipt(): void
    {
        $orderId = $this->orderFor($this->alice, 'location', null);

        $error = receiveMissionOrder($orderId, $this->alice, 'Alice A.', MESH_ACK_VIA);

        $this->assertNull($error);
        $row = dbFetchOne(
            "SELECT acknowledged_at FROM mission_order_recipients WHERE order_id = ? AND user_id = ?",
            [$orderId, $this->alice]
        );
        $this->assertNotNull($row['acknowledged_at']);
    }

    public function testAnAckForSomebodyOffTheMissionIsRefusedBeforeItEverReachesReceiveMissionOrder(): void
    {
        // mesh-ingest.php's own gate (meshBoundUsers() + actionRoomParticipantIds()
        // intersected): the same shape as the position funnel's 'refused' case, just
        // checked here at the function it is actually made of.
        $this->assertSame([], meshBoundUsers([self::NODE_ALICE]), 'nothing bound yet');
        meshBindNode(self::NODE_ALICE, $this->outsider, null, $this->adminId);

        $owner = meshBoundUsers([self::NODE_ALICE])[self::NODE_ALICE] ?? null;
        $this->assertSame($this->outsider, $owner);
        $this->assertNotContains($owner, actionRoomParticipantIds($this->missionId), 'the outsider is not a participant of this mission');
    }
}
