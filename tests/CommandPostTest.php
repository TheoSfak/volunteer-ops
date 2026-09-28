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

    // ── Following a device (v3.347.0) ───────────────────────────────────────

    /** A fix already stored for the device, $ageS seconds old. */
    private function storedFix(int $userId, float $lat, float $lng, int $ageS = 0): void
    {
        dbInsert(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, ?, ?, 8, 'auto', 'native', DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$userId, $this->shiftId, $lat, $lng, $ageS]
        );
    }

    /**
     * How old the device's first fix is, in seconds. It must still count as
     * fresh (startCommandPostFollow() refuses a stale one), and every later
     * fix in a test must be newer than it — so the tests' timelines are laid
     * out below this, and need room for a stop of COMMAND_POST_FOLLOW_SETTLE_S.
     */
    private function base(): int
    {
        $base = min(530, warRoomPingStaleThresholdSeconds() - 10);
        if ($base < COMMAND_POST_FOLLOW_SETTLE_S + 260) {
            $this->markTestSkipped('war_room_auto_ping_seconds is set too low here for these timelines.');
        }
        return $base;
    }

    /** Place it at the base point and make it follow the teammate's device, standing there. */
    private function followTeammateFromBase(): void
    {
        $this->place(self::LAT, self::LNG);
        $this->storedFix($this->teammate, self::LAT, self::LNG, $this->base());
        $result = startCommandPostFollow($this->missionId, $this->teammate, $this->adminId);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['changed'], 'The device stands on the pin: nothing moved.');
    }

    public function testOnlyAParticipantWithAPositionCanBeFollowed(): void
    {
        $this->place(self::LAT, self::LNG);
        $this->assertSame('cp.err_follow_not_participant',
            startCommandPostFollow($this->missionId, $this->notInActionRoom, $this->adminId)['error']);
        $this->assertSame('cp.err_follow_no_fix',
            startCommandPostFollow($this->missionId, $this->teammate, $this->adminId)['error'], 'No fix yet, nowhere to go.');
        $this->assertNull(loadMissionCommandPost($this->missionId)['follow']);
    }

    public function testADeviceThatHasGoneQuietCannotBeFollowed(): void
    {
        $this->place(self::LAT, self::LNG);
        $this->storedFix($this->teammate, $this->north(5000), self::LNG, warRoomPingStaleThresholdSeconds() + 60);
        $result = startCommandPostFollow($this->missionId, $this->teammate, $this->adminId);
        $this->assertSame('cp.err_follow_stale_fix', $result['error'], 'The pin would jump to where it was, not where it is.');
        $this->assertMatchesRegularExpression('/\d\d:\d\d/', $result['vars']['time']);
        $cp = loadMissionCommandPost($this->missionId);
        $this->assertNull($cp['follow']);
        $this->assertEqualsWithDelta(self::LAT, $cp['lat'], 1e-6);
    }

    public function testFollowingGoesToTheDeviceAndSaysWhoseItIs(): void
    {
        $this->place(self::LAT, self::LNG);
        $this->storedFix($this->teammate, $this->north(800), self::LNG, 30);
        $result = startCommandPostFollow($this->missionId, $this->teammate, $this->adminId);
        $this->assertTrue($result['changed']);
        $this->assertSame('moved', $result['action']);
        $this->assertEqualsWithDelta(800, $result['moved_m'], 3);
        $this->assertTrue(commandPostChangeIsNews($result), 'The endpoint announces it like any move.');

        $cp = loadMissionCommandPost($this->missionId);
        $this->assertEqualsWithDelta($this->north(800), $cp['lat'], 1e-6);
        $this->assertSame($this->teammate, $cp['follow']['user_id']);
        $this->assertSame('Γιώργος Γ.', $cp['follow']['name']);
        $this->assertEqualsWithDelta(time() - 30, $cp['follow']['ts'], 3, 'The fix time, from the stored fix.');
        $this->assertSame(['set', 'follow', 'moved'], $this->logActions());
    }

    public function testTheDriveIsNotAnnouncedOnlyTheStop(): void
    {
        $this->followTeammateFromBase();
        $before = count($this->noticesFor($this->member));

        $t = $this->base();
        // On the road: the pin follows every fix, nobody is told.
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(500), self::LNG, $t - 30));
        $this->assertEqualsWithDelta($this->north(500), loadMissionCommandPost($this->missionId)['lat'], 1e-6, 'Live on the map.');
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(1000), self::LNG, $t - 80));
        // Pulled up; a few metres of GPS wander, not long enough yet.
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(1010), self::LNG, $t - 130));
        $this->assertCount($before, $this->noticesFor($this->member), 'Nothing while it drives or has only just stopped.');

        // Over four minutes at the same spot: that is where the command post is now.
        $settled = commandPostFollowFix($this->missionId, $this->teammate, $this->north(1005), self::LNG, $t - 330);
        $this->assertNotNull($settled);
        $this->assertEqualsWithDelta(1000, $settled['moved_m'], 3);
        $notices = $this->noticesFor($this->member);
        $this->assertCount($before + 1, $notices, 'Told once.');
        $this->assertStringContainsString('σταμάτησε σε νέο σημείο', $notices[$before]['message']);
        $this->assertSame([], array_filter($this->noticesFor($this->teammate), fn($n) => str_contains($n['message'], 'σταμάτησε')),
            'Not the person holding the device: they were in the vehicle.');

        $cp = loadMissionCommandPost($this->missionId);
        $this->assertSame('moved', $cp['action']);
        $this->assertSame('Γιώργος Γ.', $cp['by']);

        // Staying there is not news again.
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(1003), self::LNG, $t - 430));
        $this->assertCount($before + 1, $this->noticesFor($this->member));
    }

    public function testComingBackToWhereItWasIsNotNews(): void
    {
        $this->followTeammateFromBase();
        $before = count($this->noticesFor($this->member));
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(600), self::LNG, $this->base() - 30);
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(20), self::LNG, $this->base() - 230);
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(15), self::LNG, 0));
        $this->assertCount($before, $this->noticesFor($this->member), 'A drive round the block and back.');
        $this->assertNull(dbFetchValue("SELECT cand_lat FROM mission_command_posts WHERE mission_id = ?", [$this->missionId]));
    }

    public function testAFixOlderThanThePinsIsIgnored(): void
    {
        $this->followTeammateFromBase();
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(300), self::LNG, 100);
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(900), self::LNG, 400);
        $this->assertEqualsWithDelta($this->north(300), loadMissionCommandPost($this->missionId)['lat'], 1e-6,
            'A queued fix from a dead zone must not drag the pin back in time.');
    }

    public function testAnotherPersonsFixDoesNothing(): void
    {
        $this->followTeammateFromBase();
        $this->assertNull(commandPostFollowFix($this->missionId, $this->member, $this->north(900), self::LNG, 0));
        $this->assertEqualsWithDelta(self::LAT, loadMissionCommandPost($this->missionId)['lat'], 1e-6);
    }

    public function testMovingItByHandStopsFollowingAndSaysSo(): void
    {
        $this->followTeammateFromBase();
        setMissionCommandPost($this->missionId, $this->north(300), self::LNG, $this->adminId);
        $this->assertNull(loadMissionCommandPost($this->missionId)['follow'], 'Or the next fix would carry it straight back.');
        $this->assertSame(['set', 'follow', 'unfollow', 'moved'], $this->logActions());
        $this->assertNull(commandPostFollowFix($this->missionId, $this->teammate, $this->north(900), self::LNG, 0));
        $this->assertEqualsWithDelta($this->north(300), loadMissionCommandPost($this->missionId)['lat'], 1e-6);
    }

    public function testKeepItHereStaysWhereTheDeviceLeftIt(): void
    {
        $this->followTeammateFromBase();
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(250), self::LNG, 60);
        $this->assertTrue(stopCommandPostFollow($this->missionId, $this->adminId));
        $this->assertFalse(stopCommandPostFollow($this->missionId, $this->adminId), 'Nothing left to stop.');
        $cp = loadMissionCommandPost($this->missionId);
        $this->assertNull($cp['follow']);
        $this->assertEqualsWithDelta($this->north(250), $cp['lat'], 1e-6);
    }

    public function testAFixThroughTheRealPingPathMovesThePin(): void
    {
        $this->followTeammateFromBase();
        dbExecute("DELETE FROM volunteer_pings WHERE user_id = ?", [$this->teammate]);
        $device = dbFetchOne("SELECT id, name, language FROM users WHERE id = ?", [$this->teammate]);
        $result = recordVolunteerPing($device, $this->shiftId, $this->north(400), self::LNG, 8.0, null, 'auto', 'native', 0);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertEqualsWithDelta(400, gpsDistanceMeters(self::LAT, self::LNG, loadMissionCommandPost($this->missionId)['lat'], self::LNG), 5);
    }

    public function testTheTimelineSaysWhoseDeviceItFollowed(): void
    {
        $this->followTeammateFromBase();
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(700), self::LNG, $this->base() - 30);
        commandPostFollowFix($this->missionId, $this->teammate, $this->north(700), self::LNG, 0);
        stopCommandPostFollow($this->missionId, $this->adminId);

        $lines = array_map(fn($e) => commandPostActivityText($e, 'el'), loadCommandPostActivityEvents($this->missionId));
        $this->assertSame([
            'Συντονιστής Τεστ όρισε το Συντονιστικό στον χάρτη',
            'Συντονιστής Τεστ έβαλε το Συντονιστικό να ακολουθεί τη συσκευή: Γιώργος Γ.',
            'Το Συντονιστικό μετακινήθηκε κατά ~700 μ. μαζί με τη συσκευή: Γιώργος Γ.',
            'Συντονιστής Τεστ σταμάτησε την παρακολούθηση της συσκευής (Γιώργος Γ.) — το Συντονιστικό μένει σταθερό',
        ], $lines);
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
