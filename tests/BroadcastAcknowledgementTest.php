<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A broadcast has to be acknowledgeable by the people it was sent to.
 *
 * The two Action Room broadcasts — «Καθολικό Μήνυμα» (order_type 'message')
 * and «Επιστροφή στη Βάση» ('return_to_base') — were once left out of
 * loadMyTaskOrdersForUser() on the grounds that they announce something rather
 * than ask anything, so a row for one could never be cleared and would hold
 * the volunteer's orders badge on for the rest of the mission.
 *
 * That reasoning left a worse hole behind it. Both still wrote a
 * mission_order_recipients row per recipient, and their ONLY acknowledgement
 * path was the live scrolling banner — which replays nothing, because
 * war-room.php starts each page load at MAX(notification id). A volunteer
 * whose tab happened to be closed when a recall order went out could therefore
 * never acknowledge it at all, the row stayed NULL forever, and the
 * coordinator's «Τι μου ξέφυγε» panel showed a permanently unanswerable
 * obligation that escalated to 'high' after thirty minutes and stayed there.
 *
 * Both halves are pinned here: the rows now reach the volunteer's own card,
 * and the badge problem is solved where it belongs instead (war-room.php
 * treats them as "acknowledgement is completion", the same rule
 * 'charge_phone' and 'speak' already use).
 *
 * Runs inside a transaction that is always rolled back, so it leaves the
 * shared test database exactly as it found it.
 */
final class BroadcastAcknowledgementTest extends TestCase
{
    private int $missionId;
    private int $userId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Broadcast Test', 'broadcast-test-' . uniqid('', true) . '@example.invalid', 'x']
        );
        // missions.mission_type_id is NOT NULL with a hardcoded default of 1
        // and a foreign key — take whichever type this database actually has
        // rather than trusting that default to point at a real row.
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Broadcast Test Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        // On shift right now — the only people a broadcast is sent to.
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $this->userId, PARTICIPATION_APPROVED]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    /** Creates an order of $type addressed to the fixture volunteer. */
    private function sendOrder(string $type, ?string $text): int
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $type, $text, $this->userId]
        );
        dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)",
            [$orderId, $this->userId]
        );
        return $orderId;
    }

    /** @return array<int, array<string, mixed>> keyed by order id */
    private function myOrders(): array
    {
        $byId = [];
        foreach (loadMyTaskOrdersForUser($this->missionId, $this->userId) as $row) {
            $byId[(int) $row['order_id']] = $row;
        }
        return $byId;
    }

    public function testABroadcastReachesTheVolunteersOwnOrdersCard(): void
    {
        $messageId = $this->sendOrder('message', 'Κλειστός ο δρόμος προς Ανώγεια.');
        $recallId  = $this->sendOrder('return_to_base', null);

        $mine = $this->myOrders();

        $this->assertArrayHasKey(
            $messageId,
            $mine,
            'a broadcast message the volunteer can only ever see in a transient banner is a message they can never acknowledge'
        );
        $this->assertArrayHasKey($recallId, $mine, 'the recall order must be acknowledgeable after the fact too');
    }

    public function testABroadcastIsLabelledWithWhatTheCoordinatorActuallyTyped(): void
    {
        $text = 'Κλειστός ο δρόμος προς Ανώγεια.';
        $messageId = $this->sendOrder('message', $text);

        $this->assertSame($text, $this->myOrders()[$messageId]['label']);
    }

    public function testAPhotoOnlyBroadcastStillGetsAName(): void
    {
        // A reference photo with no text at all is a supported way to send a
        // broadcast, and it stores NULL in task_text. Without a fallback the
        // volunteer would get an empty row with an acknowledge button under it.
        $photoOnlyId = $this->sendOrder('message', null);

        $label = $this->myOrders()[$photoOnlyId]['label'];
        $this->assertNotSame('', trim((string) $label));
        $this->assertStringNotContainsString('{', (string) $label, 'an unresolved {placeholder} means the wrong t() key');
    }

    public function testNoBroadcastLabelLeaksAnUnresolvedPlaceholder(): void
    {
        // The generic fallback is t('order.<type>.title'), and some of those
        // keys take a {mission} variable that this call site does not pass.
        // Both broadcast keys must be placeholder-free.
        $this->sendOrder('message', null);
        $this->sendOrder('return_to_base', null);

        foreach ($this->myOrders() as $row) {
            $this->assertDoesNotMatchRegularExpression('/\{[a-z_]+\}/', (string) $row['label']);
        }
    }

    public function testAcknowledgingABroadcastClearsItForTheCoordinator(): void
    {
        $recallId = $this->sendOrder('return_to_base', null);

        $this->assertNull($this->myOrders()[$recallId]['acknowledged_at'], 'starts unanswered');

        dbExecute(
            "UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE order_id = ? AND user_id = ?",
            [$recallId, $this->userId]
        );

        $this->assertNotNull($this->myOrders()[$recallId]['acknowledged_at']);
        $this->assertSame(
            0,
            (int) dbFetchValue(
                "SELECT COUNT(*) FROM mission_order_recipients WHERE order_id = ? AND acknowledged_at IS NULL",
                [$recallId]
            ),
            'once acknowledged the order must stop counting as outstanding in «Τι μου ξέφυγε»'
        );
    }

    public function testTheOtherOrderTypesAreUnaffected(): void
    {
        // Adding two types to the IN list must not change what the existing
        // ones render, and must not start listing the ones still excluded.
        $taskId = $this->sendOrder('task', 'Έλεγχος στο σημείο Β.');
        $photoRequestId = $this->sendOrder('photo', null);

        $mine = $this->myOrders();

        $this->assertSame('Έλεγχος στο σημείο Β.', $mine[$taskId]['label'], 'a task still shows its own text');
        $this->assertSame(t('order.photo.title'), $mine[$photoRequestId]['label']);
    }
}
