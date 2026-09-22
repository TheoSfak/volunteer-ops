<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The acknowledgement panel has to be able to show who has NOT confirmed.
 *
 * That is the whole reason it exists. The «Ελήφθη» scrolling banners it
 * replaced were built out of notifications, and a notification is an event —
 * so they could only ever report the people who DID answer, one marquee at a
 * time, each expiring after sixty seconds. Silence produces no event, so the
 * five volunteers who never pressed anything were invisible by construction,
 * and the question a coordinator actually asks ("who do I still need to chase?")
 * had no answer anywhere on the page.
 *
 * loadAckTrackerCardsForMission() answers it by starting from the recipient
 * list instead of from the events, which is what every test below pins.
 *
 * Runs inside a transaction that is always rolled back, so it leaves the shared
 * test database exactly as it found it.
 */
final class AckTrackerPanelTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;
    /** @var int[] Three volunteers on $teamId, in the order they were created. */
    private array $volunteerIds = [];
    private array $volunteerNames = [];

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Ack Panel Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Ack Panel Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        // created_by is NOT NULL with a foreign key and no default — leaving it
        // out inserts a 0 that references no user and the FK rejects the row.
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );

        // Names are deliberately ordered so the loader's own ORDER BY u.name
        // is observable rather than accidental.
        foreach (['Άννα Α.', 'Βασίλης Β.', 'Γιώργος Γ.'] as $name) {
            $id = $this->makeUser($name);
            $this->volunteerIds[] = $id;
            $this->volunteerNames[] = $name;
            dbInsert(
                "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
                [$shiftId, $id, PARTICIPATION_APPROVED]
            );
            dbInsert(
                "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
                [$this->teamId, $this->missionId, $id]
            );
        }

        // The admin is an approved participant too. That is not padding: it is
        // the case that made every dispatch card permanently incomplete before
        // the creator was excluded, because a sender cannot confirm receipt of
        // their own dispatch.
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $this->adminId, PARTICIPATION_APPROVED]
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
            [$name, 'ack-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** Sends an order of $type to every fixture volunteer. */
    private function sendOrder(string $type, ?string $text): int
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $type, $text, $this->adminId]
        );
        foreach ($this->volunteerIds as $id) {
            dbInsert(
                "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
                [$orderId, $id, $this->teamId]
            );
        }
        return $orderId;
    }

    private function cardFor(array $cards, string $key): ?array
    {
        foreach ($cards as $card) {
            if ($card['key'] === $key) {
                return $card;
            }
        }
        return null;
    }

    /**
     * The central claim: an order lists everyone it went to, not just whoever
     * happened to answer, and the unanswered boxes stay visibly empty.
     */
    public function testOrderCardListsEveryRecipientIncludingTheSilentOnes(): void
    {
        $orderId = $this->sendOrder('task', 'Έλεγχος μονοπατιού');

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'order:' . $orderId);
        $this->assertNotNull($card, 'A task sent to three people must produce a card.');
        $this->assertCount(3, $card['people']);
        $this->assertSame(
            $this->volunteerNames,
            array_column($card['people'], 'name'),
            'Recipients come back in name order, so the list does not reshuffle under the coordinator between polls.'
        );
        foreach ($card['people'] as $person) {
            $this->assertNull($person['ack_ts'], 'Nobody has confirmed yet, so every box must be empty.');
            $this->assertSame('ΑΛΦΑ 1', $person['team']);
        }

        // One of the three confirms.
        dbExecute(
            "UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE order_id = ? AND user_id = ?",
            [$orderId, $this->volunteerIds[1]]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'order:' . $orderId);
        $this->assertCount(3, $card['people'], 'Confirming must not remove the other two from the list.');
        $this->assertNull($card['people'][0]['ack_ts']);
        $this->assertNotNull($card['people'][1]['ack_ts'], 'The person who pressed «Ελήφθη» is ticked.');
        $this->assertNull($card['people'][2]['ack_ts']);
    }

    /** The typed words are the card, for the three types where a coordinator typed them. */
    public function testFreeTextOrdersShowWhatWasActuallyTyped(): void
    {
        $taskId = $this->sendOrder('task', 'Έλεγχος μονοπατιού');
        $liveId = $this->sendOrder('live', null);

        $cards = loadAckTrackerCardsForMission($this->missionId);
        $this->assertSame('Έλεγχος μονοπατιού', $this->cardFor($cards, 'order:' . $taskId)['detail']);
        $this->assertNull(
            $this->cardFor($cards, 'order:' . $liveId)['detail'],
            'A live-video request has no text of its own; the card title carries the meaning.'
        );
    }

    /**
     * Data requests are answered by the data arriving, not by a tickbox saying
     * the volunteer read the request — so they are deliberately not tracked.
     */
    public function testDataRequestOrdersGetNoCard(): void
    {
        $photoId = $this->sendOrder('photo', null);
        $locationId = $this->sendOrder('location', null);
        $taskId = $this->sendOrder('task', 'Έλεγχος');

        $cards = loadAckTrackerCardsForMission($this->missionId);
        $this->assertNull($this->cardFor($cards, 'order:' . $photoId));
        $this->assertNull($this->cardFor($cards, 'order:' . $locationId));
        $this->assertNotNull($this->cardFor($cards, 'order:' . $taskId), 'Control: the filter is not rejecting everything.');
    }

    /** An order nobody was addressed to is not a card. */
    public function testOrderWithNoRecipientsIsSkipped(): void
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'message', 'Κανείς σε βάρδια', $this->adminId]
        );

        $this->assertNull(
            $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'order:' . $orderId),
            'A 0-of-0 card could only ever be closed by hand and would tell the coordinator nothing.'
        );
    }

    /**
     * A sector records ONE acknowledged_at for the whole sector, not one per
     * member. The card says so, rather than inventing three ticks because one
     * person pressed the button.
     */
    public function testSectorCardIsOneRowNamingTheTeamAndThenTheAcknowledger(): void
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη 1', json_encode([[35.3, 24.8], [35.4, 24.8], [35.4, 24.9]]), $this->adminId]
        );
        $sectorId = (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, 'assigned', NOW(), ?)",
            [$this->missionId, $areaId, $this->teamId, 'Τ-1', json_encode([[35.3, 24.8], [35.4, 24.8], [35.4, 24.9]]), $this->adminId]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'sector:' . $sectorId);
        $this->assertNotNull($card);
        $this->assertCount(1, $card['people'], 'One acknowledged_at column means one row.');
        $this->assertSame('ΑΛΦΑ 1', $card['people'][0]['name']);
        $this->assertNull($card['people'][0]['ack_ts']);

        dbExecute(
            "UPDATE mission_search_sectors SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?",
            [$this->volunteerIds[0], $sectorId]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'sector:' . $sectorId);
        $this->assertNotNull($card['people'][0]['ack_ts']);
        $this->assertStringContainsString(
            'Άννα Α.',
            $card['people'][0]['name'],
            'Once acknowledged the row names who did it, so the information the single column holds is not lost.'
        );
    }

    /** A sector still sitting unassigned on the map is not an outstanding order. */
    public function testUnstartedSectorGetsNoCard(): void
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη 2', json_encode([[35.3, 24.8], [35.4, 24.8], [35.4, 24.9]]), $this->adminId]
        );
        $sectorId = (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, created_by)
             VALUES (?, ?, ?, ?, ?, 'not_started', ?)",
            [$this->missionId, $areaId, $this->teamId, 'Τ-2', json_encode([[35.3, 24.8], [35.4, 24.8], [35.4, 24.9]]), $this->adminId]
        );

        $this->assertNull($this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'sector:' . $sectorId));
    }

    /**
     * A dispatch has no recipient table — who it went to is derived. Two things
     * have to hold: only the targeted team, and never the sender, who cannot
     * confirm receipt of their own dispatch and would otherwise leave every
     * card permanently one short.
     */
    public function testDispatchCardDerivesTheTargetedTeamAndExcludesTheSender(): void
    {
        $outsiderId = $this->makeUser('Δ Outsider');
        $shiftId = (int) dbFetchValue("SELECT id FROM shifts WHERE mission_id = ? LIMIT 1", [$this->missionId]);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $outsiderId, PARTICIPATION_APPROVED]
        );

        $dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode([35.33, 24.85]), 'Σημείο Α', $this->adminId]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'dispatch:' . $dispatchId);
        $this->assertNotNull($card);
        $names = array_column($card['people'], 'name');
        $this->assertSame($this->volunteerNames, $names, 'Only the targeted team, in name order.');
        $this->assertNotContains('Ack Panel Admin', $names, 'The sender cannot confirm receipt of their own dispatch.');
        $this->assertNotContains('Δ Outsider', $names, 'An approved participant on no team is not on this team.');

        dbInsert(
            "INSERT INTO mission_dispatch_receipts (dispatch_id, team_id, user_id) VALUES (?, ?, ?)",
            [$dispatchId, $this->teamId, $this->volunteerIds[2]]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'dispatch:' . $dispatchId);
        $this->assertNull($card['people'][0]['ack_ts']);
        $this->assertNull($card['people'][1]['ack_ts']);
        $this->assertNotNull($card['people'][2]['ack_ts']);
    }

    /** A dispatch to "all teams" reaches everyone approved, team or not. */
    public function testDispatchToAllTeamsIncludesParticipantsWithNoTeam(): void
    {
        $outsiderId = $this->makeUser('Ε Χωρίς Ομάδα');
        $shiftId = (int) dbFetchValue("SELECT id FROM shifts WHERE mission_id = ? LIMIT 1", [$this->missionId]);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $outsiderId, PARTICIPATION_APPROVED]
        );

        $dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, NULL, 'polygon', ?, ?, ?)",
            [$this->missionId, json_encode([[35.3, 24.8], [35.4, 24.8], [35.4, 24.9]]), 'Περιοχή Β', $this->adminId]
        );

        $card = $this->cardFor(loadAckTrackerCardsForMission($this->missionId), 'dispatch:' . $dispatchId);
        $names = array_column($card['people'], 'name');
        $this->assertContains('Ε Χωρίς Ομάδα', $names);
        $this->assertCount(4, $names, 'Three team members plus the unteamed participant — and still not the sender.');
    }

    /** Newest first, across all three sources, so the just-issued order is on top. */
    public function testCardsComeBackNewestFirst(): void
    {
        $older = $this->sendOrder('task', 'Παλαιότερη');
        $newer = $this->sendOrder('message', 'Νεότερη');
        dbExecute("UPDATE mission_orders SET created_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 600), $older]);

        $cards = loadAckTrackerCardsForMission($this->missionId);
        $keys = array_column($cards, 'key');
        $this->assertLessThan(
            array_search('order:' . $older, $keys, true),
            array_search('order:' . $newer, $keys, true)
        );
    }

    /**
     * Nothing in this payload may be derived from the current time.
     *
     * Not a style preference: war-room.php md5s the whole poll payload to
     * decide whether it has to re-send ~51KB to every open tab every five
     * seconds. One now()-relative value in here — a rolling cutoff, an "age in
     * seconds" field — makes the hash differ on a tick where nothing actually
     * happened, and silently turns that caching off for the entire Action Room.
     * It has happened before.
     */
    public function testPayloadIsIdenticalWhenNothingHappened(): void
    {
        $this->sendOrder('task', 'Σταθερότητα');

        $first = json_encode(loadAckTrackerCardsForMission($this->missionId), JSON_UNESCAPED_UNICODE);
        $second = json_encode(loadAckTrackerCardsForMission($this->missionId), JSON_UNESCAPED_UNICODE);

        $this->assertSame($first, $second);
    }

    /** A volunteer's tab must never be handed the whole mission's name list. */
    public function testVolunteersAreNotGivenThePanelAtAll(): void
    {
        // The gate is in war-room.php ($canManageWarRoom ? ... : null) rather
        // than in the loader, so this pins the loader's own contract: it is
        // mission-scoped and takes no viewer, which is exactly why the caller
        // must never invoke it for one.
        $reflection = new ReflectionFunction('loadAckTrackerCardsForMission');
        $this->assertCount(
            1,
            $reflection->getParameters(),
            'If this ever grows a $userId parameter, the command-staff gate has moved and war-room.php must be re-checked.'
        );
    }
}
