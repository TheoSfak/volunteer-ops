<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The mission's command post («Συντονιστικό») on the live map, v3.346.0.
 *
 * What these tests pin: one point per mission, placed and then moved; placing
 * it where it already is changes nothing; a nudge under
 * COMMAND_POST_NOTIFY_MIN_M is logged but announced to nobody; the first
 * placement and a real move reach every Action Room participant and every
 * other coordinator — never the person who moved it — as a popup notice that
 * carries the new spot; the note and the removal; and the activity lines both
 * timelines print.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class CommandPostTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $member;
    private int $teammate;
    private int $notInActionRoom;

    // Ζαρός, and points a known distance north of it (1° latitude ≈ 111.2 km).
    private const LAT = 35.1300000;
    private const LNG = 24.9000000;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Συντονιστής Τεστ');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['CP Mission', 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->member = $this->makeVolunteer('Άννα Α.', true);
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.', true);
        $this->notInActionRoom = $this->makeVolunteer('Δήμητρα Δ.', false);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'cp-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name, bool $inActionRoom): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        if ($inActionRoom) {
            dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
        }
        return $id;
    }

    private function mission(): array
    {
        return dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
    }

    /** A point $metres north of the base point. */
    private function north(float $metres): float
    {
        return self::LAT + $metres / 111195.0;
    }

    private function logActions(): array
    {
        return array_column(
            dbFetchAll("SELECT action FROM mission_command_post_log WHERE mission_id = ? ORDER BY id", [$this->missionId]),
            'action'
        );
    }

    /** The command post notices each user received, newest last. */
    private function noticesFor(int $userId): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications
             WHERE user_id = ? AND data LIKE '%mission_command_post%' AND banner_mission_id = ?
             ORDER BY id",
            [$userId, $this->missionId]
        );
    }

    /** What command's «set» action does after writing: announce when it is news. */
    private function place(float $lat, float $lng): array
    {
        $result = setMissionCommandPost($this->missionId, $lat, $lng, $this->adminId);
        if (commandPostChangeIsNews($result)) {
            notifyCommandPostPlaced($this->mission(), $result, $lat, $lng, loadMissionCommandPost($this->missionId)['note'] ?? null, $this->adminId);
        }
        return $result;
    }

    public function testNothingIsOnTheMapUntilCommandPlacesIt(): void
    {
        $this->assertNull(loadMissionCommandPost($this->missionId));
    }

    public function testTheFirstPlacementIsASetUpAndSaysWhoAndWhen(): void
    {
        $result = setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $this->assertSame(['changed' => true, 'action' => 'set', 'moved_m' => null], $result);

        $cp = loadMissionCommandPost($this->missionId);
        $this->assertEqualsWithDelta(self::LAT, $cp['lat'], 1e-7);
        $this->assertEqualsWithDelta(self::LNG, $cp['lng'], 1e-7);
        $this->assertSame('set', $cp['action']);
        $this->assertSame('Συντονιστής Τεστ', $cp['by']);
        $this->assertMatchesRegularExpression('/^\d\d:\d\d$/', $cp['at'], 'Today: the hour alone.');
        $this->assertNull($cp['note']);
        $this->assertSame(['set'], $this->logActions());
    }

    public function testPlacingItWhereItAlreadyIsChangesNothing(): void
    {
        setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $again = setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $this->assertFalse($again['changed']);
        $this->assertFalse(commandPostChangeIsNews($again));
        $this->assertSame(['set'], $this->logActions(), 'A double tap is not a move.');
    }

    public function testAMoveSaysHowFar(): void
    {
        setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $result = setMissionCommandPost($this->missionId, $this->north(400), self::LNG, $this->adminId);
        $this->assertTrue($result['changed']);
        $this->assertSame('moved', $result['action']);
        $this->assertEqualsWithDelta(400, $result['moved_m'], 2);
        $this->assertSame('moved', loadMissionCommandPost($this->missionId)['action']);
        $this->assertSame(['set', 'moved'], $this->logActions());
    }

    public function testTheFirstPlacementReachesEveryParticipantButNotWhoPlacedIt(): void
    {
        $this->place(self::LAT, self::LNG);

        foreach ([$this->member, $this->teammate] as $id) {
            $notices = $this->noticesFor($id);
            $this->assertCount(1, $notices, 'Every Action Room participant is told.');
            $this->assertSame(t('cp.notify_set_title', [], 'el'), $notices[0]['title']);
        }
        $this->assertSame([], $this->noticesFor($this->adminId), 'Not the coordinator who placed it.');
        $this->assertSame([], $this->noticesFor($this->notInActionRoom), 'Not someone who takes no part in the Action Room.');
    }

    public function testTheNoticeCarriesTheSpotForThePopupsMap(): void
    {
        $this->place(self::LAT, self::LNG);
        $data = json_decode($this->noticesFor($this->member)[0]['data'], true);
        $this->assertSame($this->missionId, (int) $data['bannerMission'], 'Loud, like every operational alert.');

        $ref = notificationPopupRef($data);
        $this->assertSame('info', $ref['kind']);
        $this->assertSame('mission_command_post', $ref['info']);
        $this->assertEqualsWithDelta(self::LAT, $ref['lat'], 1e-6);
        $this->assertEqualsWithDelta(self::LNG, $ref['lng'], 1e-6);
    }

    public function testANudgeIsLoggedButAnnouncedToNobody(): void
    {
        $this->place(self::LAT, self::LNG);
        $nudge = $this->place($this->north(COMMAND_POST_NOTIFY_MIN_M - 20), self::LNG);
        $this->assertTrue($nudge['changed']);
        $this->assertFalse(commandPostChangeIsNews($nudge));
        $this->assertCount(1, $this->noticesFor($this->member), 'Only the set-up — pulling the pin into place is not news.');
        $this->assertSame(['set', 'moved'], $this->logActions(), 'But it is in the record.');
    }

    public function testARealMoveIsAnnouncedWithHowFar(): void
    {
        $this->place(self::LAT, self::LNG);
        $this->place($this->north(1234), self::LNG);

        $notices = $this->noticesFor($this->member);
        $this->assertCount(2, $notices);
        $this->assertSame(t('cp.notify_moved_title', [], 'el'), $notices[1]['title']);
        $this->assertStringContainsString('~1,2 χλμ.', $notices[1]['message']);
        $this->assertStringNotContainsString('..', $notices[1]['message'], 'The unit ends in a full stop already.');
        $this->assertStringContainsString('CP Mission', $notices[1]['message']);
    }

    public function testEachPersonReadsItInTheirOwnLanguage(): void
    {
        dbExecute("UPDATE users SET language = 'en' WHERE id = ?", [$this->teammate]);
        $this->place(self::LAT, self::LNG);
        $this->place($this->north(1234), self::LNG);
        $this->assertSame(t('cp.notify_moved_title', [], 'en'), $this->noticesFor($this->teammate)[1]['title']);
        $this->assertStringContainsString('~1.2 km', $this->noticesFor($this->teammate)[1]['message']);
    }

    public function testTheNoteRidesWithTheNotice(): void
    {
        $this->place(self::LAT, self::LNG);
        setMissionCommandPostNote($this->missionId, 'Λευκό βαν ΕΚΑΒ', $this->adminId);
        $this->place($this->north(800), self::LNG);
        $this->assertStringContainsString('(Λευκό βαν ΕΚΑΒ)', $this->noticesFor($this->member)[1]['message']);
    }

    public function testTheNoteIsTidiedChangedOnlyWhenDifferentAndClearedWhenBlank(): void
    {
        $this->assertFalse(setMissionCommandPostNote($this->missionId, 'Κάπου', $this->adminId), 'No command post, nothing to write on.');

        setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $this->assertTrue(setMissionCommandPostNote($this->missionId, "  Λευκό   βαν\nΕΚΑΒ  ", $this->adminId));
        $this->assertSame('Λευκό βαν ΕΚΑΒ', loadMissionCommandPost($this->missionId)['note']);
        $this->assertFalse(setMissionCommandPostNote($this->missionId, 'Λευκό βαν ΕΚΑΒ', $this->adminId), 'Same words: no change.');

        $this->assertTrue(setMissionCommandPostNote($this->missionId, '   ', $this->adminId));
        $this->assertNull(loadMissionCommandPost($this->missionId)['note']);
        $this->assertSame(['set', 'note', 'note'], $this->logActions());
    }

    public function testRemovingItTakesItOffTheMapOnce(): void
    {
        $this->assertFalse(clearMissionCommandPost($this->missionId, $this->adminId), 'Nothing to remove.');
        setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $this->assertTrue(clearMissionCommandPost($this->missionId, $this->adminId));
        $this->assertNull(loadMissionCommandPost($this->missionId));
        $this->assertFalse(clearMissionCommandPost($this->missionId, $this->adminId));
        $this->assertSame(['set', 'cleared'], $this->logActions());

        // Placed again after removal: a new set-up, announced as one.
        $again = setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        $this->assertSame('set', $again['action']);
    }

    public function testBothTimelinesTellTheStoryInOrder(): void
    {
        setMissionCommandPost($this->missionId, self::LAT, self::LNG, $this->adminId);
        setMissionCommandPost($this->missionId, $this->north(348), self::LNG, $this->adminId);
        setMissionCommandPostNote($this->missionId, 'Στο βαν', $this->adminId);
        clearMissionCommandPost($this->missionId, $this->adminId);

        $events = loadCommandPostActivityEvents($this->missionId);
        $this->assertSame(['cp_set', 'cp_moved', 'cp_note', 'cp_cleared'], array_column($events, 'kind'));
        $lines = array_map(fn($e) => commandPostActivityText($e, 'el'), $events);
        $this->assertSame('Συντονιστής Τεστ όρισε το Συντονιστικό στον χάρτη', $lines[0]);
        $this->assertSame('Συντονιστής Τεστ μετακίνησε το Συντονιστικό κατά ~350 μ.', $lines[1]);
        $this->assertSame('Συντονιστής Τεστ σημείωσε στο Συντονιστικό: «Στο βαν»', $lines[2]);
        $this->assertSame('Συντονιστής Τεστ αφαίρεσε το Συντονιστικό από τον χάρτη', $lines[3]);

        $report = array_values(array_filter(
            loadMissionActivityEventsForReport($this->missionId),
            fn($e) => str_contains($e['text'], 'Συντονιστικό')
        ));
        $this->assertCount(4, $report, 'The mission report prints the same four lines.');
    }

    public function testDistancesReadTheWayAPersonSaysThem(): void
    {
        $this->assertSame('~350 μ.', commandPostDistanceText(348, 'el'));
        $this->assertSame('~50 μ.', commandPostDistanceText(50, 'el'));
        $this->assertSame('~1,2 χλμ.', commandPostDistanceText(1234, 'el'));
        $this->assertSame('~1.2 km', commandPostDistanceText(1234, 'en'));
        $this->assertSame('~13 χλμ.', commandPostDistanceText(12900, 'el'));
    }

    public function testOnlyARealPointIsAccepted(): void
    {
        $this->assertTrue(commandPostValidLatLng('35.13', '24.9'));
        $this->assertFalse(commandPostValidLatLng('0', '0'), 'An empty form, not the Gulf of Guinea.');
        $this->assertFalse(commandPostValidLatLng('91', '24.9'));
        $this->assertFalse(commandPostValidLatLng('35.13', '181'));
        $this->assertFalse(commandPostValidLatLng('', '24.9'));
        $this->assertFalse(commandPostValidLatLng('abc', '24.9'));
    }
}
