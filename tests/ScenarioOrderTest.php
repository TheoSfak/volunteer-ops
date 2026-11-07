<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * General orders in the scenario (v3.457.0): a task, a spoken announcement, a broadcast message, or a request for a
 * location, a photo or a video, written down in a phase and sent to people by play. includes/functions-scenario.php
 * (addScenarioOrderItem, scenarioOrderInputFromPayload, scenarioOrderRecipients, createScenarioOrder) and the
 * sendMissionGeneralOrder() in includes/functions-warroom.php that the Action Room's own buttons now send through.
 *
 * The things that must hold: the order is the one a coordinator's button makes (same row, recipients, audit and
 * notifications); it goes only to people ON DUTY, and for a team only to that team's; an audience that is missing,
 * a slot that stands for no team and a team that is gone are NEVER "everybody"; an order nobody would receive fails
 * instead of being recorded as sent; and «Ανάκληση φάσης» never touches an order to people.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioOrderTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private int $secondTeamId;
    private int $otherTeamId;
    private int $shiftId;
    /** @var int[] On $teamId, on duty. */
    private array $members = [];
    /** On the mission, in the Action Room, but on no team. */
    private int $loneId;
    /** On $teamId and in the Action Room, but their shift has ended. */
    private int $offDutyId;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Order Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Order Mission');
        $this->otherMissionId = $mk('Other Order Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Order Mission'];
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $pastShiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->secondTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->otherTeamId = $this->makeTeam('ΓΑΜΜΑ', 1, $this->otherMissionId);
        foreach (['Άννα Α.', 'Βασίλης Β.'] as $name) {
            $id = $this->makeUser($name);
            $this->joinOnDuty($id, $this->shiftId);
            dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->teamId, $this->missionId, $id]);
            $this->members[] = $id;
        }
        $this->loneId = $this->makeUser('Χωρίς ομάδα');
        $this->joinOnDuty($this->loneId, $this->shiftId);
        $this->offDutyId = $this->makeUser('Εκτός βάρδιας');
        $this->joinOnDuty($this->offDutyId, $pastShiftId);
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->teamId, $this->missionId, $this->offDutyId]);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", [$name, 'so-' . uniqid('', true) . '@example.invalid', 'x']);
    }

    private function makeTeam(string $codename, int $number, ?int $missionId = null): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId ?? $this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function joinOnDuty(int $userId, int $shiftId): void
    {
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$shiftId, $userId, PARTICIPATION_APPROVED]);
        dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $userId]);
    }

    private function phase(): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, '');
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    private function stage(int $phaseId, array $over = []): array
    {
        return addScenarioOrderItem($this->mission, $phaseId, $this->adminId, $over + [
            'order_type' => 'task',
            'text' => 'Πηγαίνετε στο σημείο Α',
            'team_id' => (string) $this->teamId,
        ]);
    }

    private function play(int $phaseId, array $mapping = []): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Order Admin', $mapping);
    }

    private function orderRows(): array
    {
        return dbFetchAll("SELECT * FROM mission_orders WHERE mission_id = ? ORDER BY id", [$this->missionId]);
    }

    private function recipientIds(int $orderId): array
    {
        $ids = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_order_recipients WHERE order_id = ?", [$orderId]), 'user_id'));
        sort($ids);
        return $ids;
    }

    private function item(int $itemId): array
    {
        return dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
    }

    private function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    // ── The shared sender ───────────────────────────────────────────────────────────────────────────────────────

    public function testTheSixKindsAreSpecsAndLiveAndTheRecallAreNot(): void
    {
        foreach (['location', 'photo', 'video', 'task', 'speak', 'message'] as $type) {
            $this->assertIsArray(missionGeneralOrderSpec($type), $type);
        }
        foreach (['live', 'return_to_base', 'route', 'charge_phone', '', 'bogus'] as $type) {
            $this->assertNull(missionGeneralOrderSpec($type), $type);
        }
        $this->assertTrue(missionGeneralOrderSpec('task')['text']);
        $this->assertFalse(missionGeneralOrderSpec('photo')['text']);
        $this->assertSame('speak', missionGeneralOrderSpec('speak')['alarm']);
        $this->assertNull(missionGeneralOrderSpec('task')['alarm']);
        $this->assertSame(SCENARIO_ORDER_TYPES, ['task', 'speak', 'message', 'location', 'photo', 'video']);
    }

    public function testTheWordsAreTrimmedAndCutAtFiveHundredCharacters(): void
    {
        $this->assertSame('Γεια', missionGeneralOrderText("  Γεια \n"));
        $this->assertSame(500, mb_strlen(missionGeneralOrderText(str_repeat('Ω', 700))));
        $this->assertSame('', missionGeneralOrderText("   \t"));
    }

    public function testEachKindMakesTheSameRowRecipientsAuditAndNotificationAsTheButtons(): void
    {
        $cases = [
            'location' => ['', 'request_mission_location'],
            'photo' => ['', 'request_mission_photo'],
            'video' => ['', 'request_mission_video'],
            'task' => ['Δες το ρέμα', 'request_mission_task'],
            'speak' => ['Προσοχή σε όλους', 'request_mission_speak'],
            'message' => ['Συγκεντρωθείτε', 'global_message_war_room'],
        ];
        foreach ($cases as $type => [$text, $audit]) {
            $r = sendMissionGeneralOrder($this->mission, $this->adminId, $type, $this->members, $text);
            $this->assertSame(count($this->members), $r['count'], $type);
            $row = dbFetchOne("SELECT * FROM mission_orders WHERE id = ?", [$r['order_id']]);
            $this->assertSame($type, $row['order_type']);
            $this->assertSame($text !== '' ? $text : null, $row['task_text'], $type);
            $this->assertSame($this->sorted($this->members), $this->recipientIds($r['order_id']), $type);
            $log = dbFetchOne("SELECT action, table_name, notes FROM audit_logs WHERE action = ? ORDER BY id DESC LIMIT 1", [$audit]);
            $this->assertNotEmpty($log, "audit for $type");
            $note = json_decode((string) $log['notes'], true);
            $new = $note['new'] ?? $note;
            if ($type === 'message') {
                $this->assertSame($text, $new['message']);
                $this->assertArrayNotHasKey('recipient_ids', $new);
            } else {
                $this->assertSame($this->members, $new['recipient_ids'], $type);
                if ($text !== '') {
                    $this->assertSame($text, $new[$type === 'task' ? 'task_text' : 'speak_text']);
                }
            }
            $note = dbFetchOne("SELECT message, data FROM notifications WHERE user_id = ? AND data LIKE ? ORDER BY id DESC LIMIT 1", [$this->members[0], '%"orderId":' . $r['order_id'] . '%']);
            $this->assertNotEmpty($note, "notification for $type");
            $data = json_decode($note['data'], true);
            if ($type === 'speak') {
                $this->assertSame('speak', $data['alarmStyle']);
            } else {
                $this->assertArrayNotHasKey('alarmStyle', $data, $type);
            }
            if ($text !== '') {
                $this->assertSame($text, $note['message'], "the words as typed ($type)");
            }
        }
    }

    public function testAMessageWithoutWordsCarriesTheAuditPhotoFlagAndNotAnAudienceList(): void
    {
        $r = sendMissionGeneralOrder($this->mission, $this->adminId, 'message', $this->members, '', ['has_photo' => true]);
        $log = dbFetchOne("SELECT notes FROM audit_logs WHERE action = 'global_message_war_room' ORDER BY id DESC LIMIT 1");
        $note = json_decode((string) $log['notes'], true);
        $new = $note['new'] ?? $note;
        $this->assertTrue($new['has_photo']);
        $this->assertNull(dbFetchValue("SELECT task_text FROM mission_orders WHERE id = ?", [$r['order_id']]));
    }

    public function testAnOrderThatIsNotAGeneralOrderIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sendMissionGeneralOrder($this->mission, $this->adminId, 'live', $this->members, '');
    }

    // ── Writing it down ─────────────────────────────────────────────────────────────────────────────────────────

    public function testStagingWritesAnOrderItemAndNothingLive(): void
    {
        $phase = $this->phase();
        $r = $this->stage($phase, ['text' => '  Πηγαίνετε στο σημείο Α  ']);
        $this->assertTrue($r['ok'], json_encode($r));
        $row = $this->item($r['id']);
        $this->assertSame('order', $row['kind']);
        $this->assertNull($row['sent_at']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame('team', $payload['audience']);
        $this->assertSame($this->teamId, $payload['team_id']);
        $this->assertSame('task', $payload['order_type']);
        $this->assertSame('Πηγαίνετε στο σημείο Α', $payload['text']);
        $this->assertCount(0, $this->orderRows(), 'a staged order is not an order yet');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE data LIKE '%order%' AND user_id IN (" . implode(',', $this->members) . ")"));
    }

    public function testEveryKindCanBeStagedAndTheWordsAreOnlyForThreeOfThem(): void
    {
        $phase = $this->phase();
        foreach (SCENARIO_ORDER_TYPES as $type) {
            $r = $this->stage($phase, ['order_type' => $type, 'text' => 'λέξεις']);
            $this->assertTrue($r['ok'], $type . ' ' . json_encode($r));
            $payload = json_decode($this->item($r['id'])['payload'], true);
            $this->assertSame(in_array($type, ['task', 'speak', 'message'], true) ? 'λέξεις' : '', $payload['text'], $type);
        }
    }

    public function testStagingRefusesWhatCouldNeverBeSent(): void
    {
        $phase = $this->phase();
        foreach (['task', 'speak', 'message'] as $type) {
            $this->assertFalse($this->stage($phase, ['order_type' => $type, 'text' => "   "])['ok'], "$type needs words");
        }
        foreach (['live', 'return_to_base', 'route', '', 'bogus'] as $type) {
            $r = $this->stage($phase, ['order_type' => $type]);
            $this->assertFalse($r['ok'], $type);
            $this->assertSame(t('scenario.order_type_unknown'), $r['error']);
        }
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
    }

    public function testNoAudienceIsNeverEverybody(): void
    {
        $phase = $this->phase();
        foreach (['', '  ', '0', 'ALL', 'everyone', 'slot:', 'slot:abc', 'slot:0', '-1', '39x', 'null'] as $raw) {
            $r = $this->stage($phase, ['team_id' => $raw]);
            $this->assertFalse($r['ok'], "'$raw'");
            $this->assertSame(t('scenario.order_needs_audience'), $r['error'], "'$raw'");
        }
        $this->assertTrue($this->stage($phase, ['team_id' => 'all'])['ok'], "'all' in words is the one way to say everybody on duty");
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
    }

    public function testATeamOrASlotOfAnotherMissionIsRefused(): void
    {
        $phase = $this->phase();
        $r = $this->stage($phase, ['team_id' => (string) $this->otherTeamId]);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('common.team_not_found'), $r['error']);

        $other = ['id' => $this->otherMissionId, 'title' => 'x'];
        $slot = addScenarioSlot($other, $this->adminId, 'Ομάδα Α');
        $this->assertTrue($slot['ok']);
        $r = $this->stage($phase, ['team_id' => 'slot:' . $slot['id']]);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('scenario.slot_not_found'), $r['error']);
    }

    public function testAPlayedPhaseTakesNoMoreOrdersAndAPhaseOfAnotherMissionIsNotFound(): void
    {
        $phase = $this->phase();
        $this->assertTrue($this->stage($phase)['ok']);
        $this->assertTrue($this->play($phase)['ok']);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $this->stage($phase)['error']);

        $otherPhase = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'x'], $this->adminId, '')['id'];
        $this->assertSame(t('scenario.phase_not_found'), $this->stage($otherPhase)['error']);
    }

    // ── Sending it ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testPlayingATeamOrderGoesToThatTeamsPeopleOnDutyOnly(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase)['id'];
        $r = $this->play($phase);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $r['sent']);
        $this->assertSame('order', $r['results'][0]['kind']);
        $this->assertNull($r['results'][0]['dispatch_id']);

        $row = $this->item($item);
        $orderId = (int) $row['sent_ref_id'];
        $this->assertNotNull($row['sent_at']);
        $this->assertSame($this->sorted($this->members), $this->recipientIds($orderId), 'the team on duty - not the man whose shift ended, not the one with no team');
        $order = dbFetchOne("SELECT * FROM mission_orders WHERE id = ?", [$orderId]);
        $this->assertSame('task', $order['order_type']);
        $this->assertSame('Πηγαίνετε στο σημείο Α', $order['task_text']);
        $this->assertSame($this->adminId, (int) $order['created_by']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id IN (?, ?) AND data LIKE '%orderId%'", [$this->loneId, $this->offDutyId]));
    }

    public function testPlayingAnOrderToEveryoneGoesToEverybodyOnDutyAndToNoOneElse(): void
    {
        $phase = $this->phase();
        $this->stage($phase, ['order_type' => 'location', 'team_id' => 'all']);
        $r = $this->play($phase);
        $orderId = (int) $r['results'][0]['order_id'];
        $this->assertSame($this->sorted(array_merge($this->members, [$this->loneId])), $this->recipientIds($orderId));
        $this->assertSame('location', dbFetchValue("SELECT order_type FROM mission_orders WHERE id = ?", [$orderId]));
    }

    public function testABroadcastMessageNeverGoesBackToTheCoordinatorWhoPressedPlay(): void
    {
        $this->joinOnDuty($this->adminId, $this->shiftId);
        $phase = $this->phase();
        $this->stage($phase, ['order_type' => 'message', 'team_id' => 'all', 'text' => 'Συγκεντρωθείτε']);
        $this->stage($phase, ['order_type' => 'task', 'team_id' => 'all', 'text' => 'Αναφορά']);
        $r = $this->play($phase);
        $this->assertNotContains($this->adminId, $this->recipientIds((int) $r['results'][0]['order_id']), 'the message');
        $this->assertContains($this->adminId, $this->recipientIds((int) $r['results'][1]['order_id']), 'a task is for everyone on duty, as the button says');
    }

    public function testAnOrderNobodyWouldReceiveFailsAndIsNotRecordedAsSent(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase, ['team_id' => (string) $this->secondTeamId])['id'];
        $r = $this->play($phase);
        $this->assertSame(0, $r['sent']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(t('scenario.order_nobody'), $r['results'][0]['error']);
        $this->assertNull($this->item($item)['sent_at']);
        $this->assertCount(0, $this->orderRows(), 'nothing was created, and nobody was told');

        // The team gets somebody; the card's retry sends it.
        $id = $this->makeUser('Νέος');
        $this->joinOnDuty($id, $this->shiftId);
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->secondTeamId, $this->missionId, $id]);
        $retry = retryScenarioItem($this->mission, $item, $this->adminId, 'Order Admin');
        $this->assertTrue($retry['ok']);
        $this->assertTrue($retry['item']['ok']);
        $this->assertSame([$id], $this->recipientIds($retry['item']['order_id']));
    }

    public function testEveryoneOnDutyWithNobodyOnDutyFailsToo(): void
    {
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600), $this->shiftId]);
        $phase = $this->phase();
        $this->stage($phase, ['order_type' => 'message', 'team_id' => 'all', 'text' => 'Κανείς']);
        $r = $this->play($phase);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(t('scenario.order_nobody'), $r['results'][0]['error']);
        $this->assertCount(0, $this->orderRows());
    }

    public function testATeamThatIsGoneIsNotReplacedByEverybody(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase)['id'];
        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$this->teamId]);
        $r = $this->play($phase);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(t('common.team_not_found'), $r['results'][0]['error']);
        $this->assertNull($this->item($item)['sent_at']);
        $this->assertCount(0, $this->orderRows());
    }

    public function testASlotGoesToTheTeamItStandsForAndAnUnmappedOneRefusesThePhase(): void
    {
        $slot = addScenarioSlot($this->mission, $this->adminId, 'Ομάδα Α')['id'];
        $phase = $this->phase();
        $this->assertTrue($this->stage($phase, ['team_id' => 'slot:' . $slot, 'order_type' => 'photo'])['ok']);
        $refused = $this->play($phase);
        $this->assertFalse($refused['ok']);
        $this->assertNotEmpty($refused['unmapped']);
        $this->assertCount(0, $this->orderRows(), 'an unmapped slot is not everybody');

        $r = $this->play($phase, [$slot => $this->teamId]);
        $this->assertTrue($r['ok']);
        $this->assertSame($this->sorted($this->members), $this->recipientIds((int) $r['results'][0]['order_id']));
    }

    // ── Payload to order: never widened ─────────────────────────────────────────────────────────────────────────

    public function testAPayloadIsNeverReadAsEverybodyUnlessItSaysSo(): void
    {
        $good = ['order_type' => 'task', 'text' => 'Λέξεις', 'audience' => 'team', 'team_id' => 7];
        $this->assertSame(['order_type' => 'task', 'text' => 'Λέξεις', 'audience' => 'team', 'team_id' => 7], scenarioOrderInputFromPayload($good));
        $this->assertSame(['order_type' => 'task', 'text' => 'Λέξεις', 'audience' => 'all', 'team_id' => null], scenarioOrderInputFromPayload(['audience' => 'all', 'team_id' => null] + $good));

        $bad = [
            'no audience' => ['audience' => ''] + $good,
            'audience missing' => array_diff_key($good, ['audience' => 1]),
            'team without id' => ['team_id' => null] + $good,
            'team id zero' => ['team_id' => 0] + $good,
            'slot with no map' => ['audience' => 'slot', 'slot_id' => 3] + $good,
            'slot not in map' => ['audience' => 'slot', 'slot_id' => 4] + $good,
            'all in capitals' => ['audience' => 'ALL'] + $good,
            'unknown kind' => ['order_type' => 'live'] + $good,
            'no kind' => array_diff_key($good, ['order_type' => 1]),
            'task without words' => ['text' => ' '] + $good,
        ];
        foreach ($bad as $why => $payload) {
            $this->assertNull(scenarioOrderInputFromPayload($payload, [3 => null]), $why);
        }
        $this->assertNull(scenarioOrderInputFromPayload('{broken'));
        $this->assertNull(scenarioOrderInputFromPayload(null));
        $this->assertSame(11, scenarioOrderInputFromPayload(['audience' => 'slot', 'slot_id' => 3] + $good, [3 => 11])['team_id']);
        $this->assertNull(scenarioOrderInputFromPayload(['audience' => 'slot', 'slot_id' => 3] + $good, [3 => 0]), 'a slot mapped to team 0 is not mapped');
        // A kind with no words needs none.
        $this->assertSame('', scenarioOrderInputFromPayload(['order_type' => 'video', 'text' => 'ignored'] + $good)['text']);
    }

    public function testAnUnreadableOrderItemFailsAndSendsNothing(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase)['id'];
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode(['audience' => 'all', 'order_type' => 'task', 'text' => '']), $item]);
        $r = $this->play($phase);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(t('scenario.item_unreadable'), $r['results'][0]['error']);
        $this->assertCount(0, $this->orderRows());
    }

    // ── How far it has got ──────────────────────────────────────────────────────────────────────────────────────

    public function testATaskIsDoneWhenEveryoneHasDoneItAndAMessageWhenEveryoneHasReceivedIt(): void
    {
        $phase = $this->phase();
        $task = $this->stage($phase, ['order_type' => 'task'])['id'];
        $message = $this->stage($phase, ['order_type' => 'message', 'text' => 'Μήνυμα', 'team_id' => 'all'])['id'];
        $photo = $this->stage($phase, ['order_type' => 'photo'])['id'];
        $this->play($phase);

        $state = fn() => scenarioItemLiveStates($this->missionId, dbFetchAll("SELECT id, kind, sent_at, sent_ref_id FROM mission_scenario_items WHERE phase_id = ? ORDER BY seq", [$phase]));
        $refs = array_column(dbFetchAll("SELECT id, sent_ref_id FROM mission_scenario_items WHERE phase_id = ?", [$phase]), 'sent_ref_id', 'id');

        $s = $state();
        $this->assertSame(['state' => 'open', 'done' => 0, 'of' => 2], $s[$task]);
        $this->assertSame(['state' => 'open', 'done' => 0, 'of' => 3], $s[$message], 'a message went to everyone on duty (not the coordinator)');
        $this->assertSame(['state' => 'open', 'done' => 0, 'of' => 2], $s[$photo]);

        // A task is not done by being received...
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE order_id = ?", [$refs[$task]]);
        $this->assertSame('open', $state()[$task]['state']);
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ? AND user_id = ?", [$refs[$task], $this->members[0]]);
        $this->assertSame(['state' => 'open', 'done' => 1, 'of' => 2], $state()[$task]);
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ?", [$refs[$task]]);
        $this->assertSame(['state' => 'done', 'done' => 2, 'of' => 2], $state()[$task]);

        // ...a message is done by being received, and a photo by the photo (not by «Ελήφθη»).
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE order_id = ?", [$refs[$message]]);
        $this->assertSame('done', $state()[$message]['state']);
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE order_id = ?", [$refs[$photo]]);
        $this->assertSame('open', $state()[$photo]['state']);
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ?", [$refs[$photo]]);
        $this->assertSame('done', $state()[$photo]['state']);
    }

    public function testAnOrderWithNoOneLeftToWaitForReadsAsWithdrawn(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase)['id'];
        $this->play($phase);
        $ref = (int) $this->item($item)['sent_ref_id'];
        $rows = [['id' => $item, 'kind' => 'order', 'sent_at' => '2026-01-01 00:00:00', 'sent_ref_id' => $ref]];

        dbExecute("DELETE FROM mission_order_recipients WHERE order_id = ?", [$ref]);
        $this->assertSame('withdrawn', scenarioItemLiveStates($this->missionId, $rows)[$item]['state']);
        dbExecute("DELETE FROM mission_orders WHERE id = ?", [$ref]);
        $this->assertSame('withdrawn', scenarioItemLiveStates($this->missionId, $rows)[$item]['state']);
        // An order of another mission is not this scenario's to read.
        $rows[0]['sent_ref_id'] = (int) dbInsert("INSERT INTO mission_orders (mission_id, order_type, created_by) VALUES (?, 'task', ?)", [$this->otherMissionId, $this->adminId]);
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)", [$rows[0]['sent_ref_id'], $this->loneId]);
        $this->assertSame('withdrawn', scenarioItemLiveStates($this->missionId, $rows)[$item]['state']);
    }

    public function testAnOrderToPeopleIsOpenButNeverWithdrawable(): void
    {
        $phase = $this->phase();
        $this->stage($phase, ['order_type' => 'message', 'text' => 'Μήνυμα', 'team_id' => 'all']);
        $point = addScenarioDispatchItem($this->mission, $phase, $this->adminId, [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'label' => 'Ρέμα', 'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]), 'travel_mode' => '',
        ]);
        $this->assertTrue($point['ok']);
        $this->assertTrue($this->play($phase)['ok']);

        $p = loadMissionScenario($this->missionId)['phases'][0];
        $this->assertSame(2, $p['progress']['open']);
        $this->assertSame(1, $p['progress']['withdrawable'], 'the point can be taken back, the message cannot');
        $this->assertTrue($p['progress']['can_withdraw']);

        $w = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertTrue($w['ok']);
        $this->assertSame(1, $w['withdrawn']);
        $this->assertSame(['dispatch'], array_column($w['results'], 'kind'));
        $this->assertCount(1, $this->orderRows(), 'the message stays');
        $this->assertSame('open', loadMissionScenario($this->missionId)['phases'][0]['items'][0]['state']);
    }

    public function testAPhaseWithOnlyOrdersToPeopleHasNothingToWithdraw(): void
    {
        $phase = $this->phase();
        $this->stage($phase);
        $this->play($phase);
        $p = loadMissionScenario($this->missionId)['phases'][0];
        $this->assertSame(1, $p['progress']['open']);
        $this->assertSame(0, $p['progress']['withdrawable']);
        $this->assertFalse($p['progress']['can_withdraw']);
        $w = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertFalse($w['ok']);
        $this->assertSame(t('scenario.withdraw_nothing'), $w['error']);
        $this->assertCount(1, $this->orderRows());
    }

    // ── What the card is given ──────────────────────────────────────────────────────────────────────────────────

    public function testTheCardIsGivenTheKindTheWordsAndNoPlace(): void
    {
        $phase = $this->phase();
        $this->stage($phase, ['order_type' => 'speak', 'text' => 'Ανακοίνωση', 'team_id' => 'all']);
        $items = loadMissionScenario($this->missionId)['phases'][0]['items'];
        $this->assertCount(1, $items);
        $i = $items[0];
        $this->assertSame('order', $i['kind']);
        $this->assertSame('order', $i['type']);
        $this->assertSame('speak', $i['order_type']);
        $this->assertSame('Ανακοίνωση', $i['label']);
        $this->assertNull($i['geo'], 'no ghost on the map');
        $this->assertSame('all', $i['audience']);
        $this->assertNull($i['dispatch_id']);
        $this->assertNull($i['order_id']);
        $this->assertFalse($i['sent']);

        $this->play($phase);
        $i = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertNotNull($i['order_id']);
        $this->assertNull($i['dispatch_id'], 'an order is not a dispatch');
        $this->assertSame(3, $i['of_n']);
    }

    // ── The page and the endpoint ───────────────────────────────────────────────────────────────────────────────

    private function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testTheSixButtonsOfTheActionRoomSendThroughTheSharedFunction(): void
    {
        $page = $this->source('war-room.php');
        foreach (['location', 'photo', 'video', 'task', 'speak', 'message'] as $type) {
            $this->assertMatchesRegularExpression("/sendMissionGeneralOrder\\(\\s*\\\$mission, \\(int\\) \\\$user\\['id'\\], '$type'/", $page, $type);
        }
        // Only the stream request and the recall build their own order any more.
        $this->assertSame(2, preg_match_all('/^\s*(\$\w+ = )?createMissionOrderAndNotify\(/m', $page), 'two calls left: the stream request and the recall');
        $this->assertStringContainsString("'live', \$user['id']", $page);
        $this->assertStringContainsString("'return_to_base', \$user['id']", $page);
        $this->assertStringNotContainsString("'order.task.title'", $page);
    }

    public function testTheEndpointHasAnAddOrderActionForCommandOnly(): void
    {
        $src = $this->source('mission-scenario.php');
        $add = substr($src, (int) strpos($src, "if (\$action === 'add_order') {"), 1500);
        $this->assertStringContainsString('addScenarioOrderItem(', $add);
        $this->assertStringContainsString("post('order_type')", $add);
        $this->assertStringContainsString("post('team_id')", $add);
        $this->assertStringContainsString('deleteScenarioPhase(', $add, 'a phase made for a refused order is taken out again');
        $this->assertLessThan(strpos($src, "\$action === 'add_order'"), strpos($src, 'canManageActionRoom('), 'the command gate comes first');
    }

    public function testTheScenarioCodeNeverBuildsTheOrderItselfAndNoReaderOfTheLiveOrdersKnowsTheScenario(): void
    {
        $scenario = $this->source('includes/functions-scenario.php');
        foreach (['INSERT INTO mission_orders', 'INSERT INTO mission_order_recipients', 'createMissionOrderAndNotify(', 'sendNotification('] as $never) {
            $this->assertStringNotContainsString($never, $scenario, $never);
        }
        $this->assertStringContainsString('sendMissionGeneralOrder(', $scenario);
        $this->assertStringNotContainsString('mission_scenario', $this->source('includes/functions-warroom.php'));
    }

    public function testTheCardHasTheFormAndTheLanguageFilesHaveItsWords(): void
    {
        $js = $this->source('assets/js/war-room-scenario.js');
        foreach (["run('add_order'", 'data-sc="order-open"', 'data-sc="order-stage"', 'orderDraft', 'ORDER_TYPES'] as $needle) {
            $this->assertStringContainsString($needle, $js, $needle);
        }
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['scenario.order_add_btn', 'scenario.order_type_task', 'scenario.order_type_speak', 'scenario.order_type_message', 'scenario.order_type_location', 'scenario.order_type_photo', 'scenario.order_type_video', 'scenario.order_needs_audience', 'scenario.order_nobody', 'scenario.order_no_withdraw', 'scenario.state_open_people', 'scenario.state_open_ack', 'scenario.state_done_ack', 'scenario.target_all_people'] as $key) {
            $this->assertNotSame('', trim($strings['el'][$key] ?? ''), "$key (el)");
            $this->assertNotSame('', trim($strings['en'][$key] ?? ''), "$key (en)");
        }
    }
}
