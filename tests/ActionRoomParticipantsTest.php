<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Not everyone approved on a mission takes part in the Action Room.
 *
 * Ten volunteers go out as two teams of five and one phone per team carries
 * the operation: that phone reports the position and receives the orders,
 * while the other eight simply work. Before this existed, all ten were asked
 * for GPS, all ten filled every recipient list the coordinator had to read
 * through, and the eight who were never meant to report produced eight "has
 * gone quiet" advisories that buried the two that mattered.
 *
 * The tick is stored as a row in mission_action_room_participants — presence
 * means "takes part" — and every test below pins one place that has to read
 * it. The rules worth defending, in order of how badly each one bites:
 *
 *  - a position from somebody without the tick is REFUSED, not merely hidden,
 *    so it cannot leak back through a trail, a map layer or a report later;
 *  - SOS is never affected, because an emergency must not depend on a
 *    coordinator's roster choice;
 *  - dropping somebody from a team does not untick them, because a team move
 *    is a delete-and-insert and losing the position mid-move is the thing the
 *    single-save move exists to prevent.
 *
 * Runs inside a transaction that is always rolled back, so it leaves the
 * shared test database exactly as it found it.
 */
final class ActionRoomParticipantsTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    /** @var int[] Five volunteers on $teamId, none of them ticked yet. */
    private array $volunteerIds = [];

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Action Room Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        // status/show_in_ops are what recordVolunteerPing() demands of a
        // mission before it will store anything at all — without them every
        // ping assertion below would pass for the wrong reason.
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['GPS Tick Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );

        foreach (['Άννα Α.', 'Βασίλης Β.', 'Γιώργος Γ.', 'Δήμητρα Δ.', 'Ελένη Ε.'] as $name) {
            $id = $this->makeUser($name);
            $this->volunteerIds[] = $id;
            dbInsert(
                "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
                [$this->shiftId, $id, PARTICIPATION_APPROVED]
            );
            dbInsert(
                "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
                [$this->teamId, $this->missionId, $id]
            );
        }

        // The id cache is per request and this process runs many "requests" in
        // a row against ids that are rolled back between them.
        forgetActionRoomParticipantIds($this->missionId);
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
            [$name, 'argps-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function userRow(int $id): array
    {
        return dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }

    private function tick(int ...$ids): void
    {
        foreach ($ids as $id) {
            setActionRoomParticipation($this->missionId, $id, true, $this->adminId);
        }
    }

    // ── A refused ping says why (v3.319.0) ──────────────────────────────────

    public function testAPingRefusedForPoorAccuracyTellsTheCommandPostWhy(): void
    {
        // The case this exists for: a phone on Android's "approximate
        // location" never reports an error, it just answers with a fix a
        // kilometre wide. Every ping is refused, and without this the roster
        // line would go quiet and read exactly like somebody resting.
        $this->tick($this->volunteerIds[0]);

        $result = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 1500.0, 80, 'auto', 'browser');

        $this->assertFalse($result['ok']);
        $this->assertSame('imprecise', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    public function testAPingRefusedAsAnImpossibleJumpIsRecordedAsSuch(): void
    {
        $this->tick($this->volunteerIds[0]);
        $this->seedPing($this->volunteerIds[0], 35.33, 25.13, 10);

        $result = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, $this->northOf(35.33, 300), 25.13, 8.0, 80, 'auto', 'browser');

        $this->assertFalse($result['ok']);
        $this->assertSame('implausible', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    public function testTheNextGoodPingClearsARefusalFlag(): void
    {
        // A refusal flag says "nothing is arriving", not "something went wrong
        // once" — so a fix that does arrive has to clear it.
        $this->tick($this->volunteerIds[0]);
        recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 1500.0, 80, 'auto', 'browser');
        $this->assertSame('imprecise', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);

        $good = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 9.0, 80, 'auto', 'browser');

        $this->assertTrue($good['ok']);
        $this->assertNull($this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    public function testAnUnknownReasonIsStoredAsUnknownRatherThanThrownAway(): void
    {
        $this->tick($this->volunteerIds[0]);
        $this->assertTrue(recordVolunteerGpsErrorReason($this->missionId, $this->volunteerIds[0], 'nonsense'));
        $this->assertSame('unknown', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    // ── Spike vs. real travel (v3.316.0) ────────────────────────────────────

    /** Insert a ping directly, $secondsAgo in the past, bypassing the gates. */
    private function seedPing(int $userId, float $lat, float $lng, int $secondsAgo, float $acc = 8.0): void
    {
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, created_at)
             VALUES (?, ?, ?, ?, ?, 'auto', DATE_SUB(NOW(), INTERVAL ? SECOND))",
            [$userId, $this->shiftId, $lat, $lng, $acc, $secondsAgo]
        );
    }

    /** Metres north of a point, as degrees of latitude. */
    private function northOf(float $lat, float $metres): float
    {
        return $lat + $metres / 111320.0;
    }

    public function testAnIsolatedSpikeIsRefused(): void
    {
        // 300m in 10 seconds is 108 km/h. Nobody on foot does that, and the
        // fix before it is recent, so there is something to judge it against.
        $this->tick($this->volunteerIds[0]);
        $this->seedPing($this->volunteerIds[0], 35.33, 25.13, 10);

        $result = recordVolunteerPing(
            $this->userRow($this->volunteerIds[0]), $this->shiftId,
            $this->northOf(35.33, 300), 25.13, 8.0, 80, 'auto', 'browser'
        );

        $this->assertFalse($result['ok']);
    }

    public function testSomebodyGenuinelyTravellingIsNotErasedOnceTheirPositionGoesStale(): void
    {
        // Same impossible-on-foot speed, but nothing has been stored for
        // longer than the app's own staleness line. At that point "we do not
        // know where they are" is worse than "they may be moving fast", so
        // the fix is taken and the trail picks them up again.
        $this->tick($this->volunteerIds[0]);
        $staleAfter = warRoomPingStaleThresholdSeconds();
        $this->seedPing($this->volunteerIds[0], 35.33, 25.13, $staleAfter + 30);

        $result = recordVolunteerPing(
            $this->userRow($this->volunteerIds[0]), $this->shiftId,
            $this->northOf(35.33, 3000), 25.13, 8.0, 80, 'auto', 'browser'
        );

        $this->assertTrue($result['ok']);
    }

    public function testWalkingPaceIsNeverTouched(): void
    {
        // 30m in 20 seconds is 5,4 km/h — the ordinary case, which must pass
        // however tight the limit gets.
        $this->tick($this->volunteerIds[0]);
        $this->seedPing($this->volunteerIds[0], 35.33, 25.13, 20);

        $result = recordVolunteerPing(
            $this->userRow($this->volunteerIds[0]), $this->shiftId,
            $this->northOf(35.33, 30), 25.13, 8.0, 80, 'auto', 'browser'
        );

        $this->assertTrue($result['ok']);
    }

    public function testANoisyStationaryPhoneIsNotMistakenForMovement(): void
    {
        // 80m apart in 10 seconds is 29 km/h, past the speed limit, but both
        // fixes admit to +-45m, so their own uncertainty already covers the
        // gap. Refusing here would throw away the readings of anybody standing
        // under a balcony. Both accuracies are deliberately inside the 50m
        // accuracy gate: this test is about the SPEED gate, and letting the
        // other one fire would have made it pass for the wrong reason.
        $this->tick($this->volunteerIds[0]);
        $this->seedPing($this->volunteerIds[0], 35.33, 25.13, 10, 45.0);

        $result = recordVolunteerPing(
            $this->userRow($this->volunteerIds[0]), $this->shiftId,
            $this->northOf(35.33, 80), 25.13, 45.0, 80, 'auto', 'browser'
        );

        $this->assertTrue($result['ok']);
    }

    // ── Why a device stopped reporting (v160) ───────────────────────────────

    private function storedGpsError(int $userId): ?array
    {
        return dbFetchOne(
            "SELECT last_gps_error, last_gps_error_at FROM mission_action_room_participants
              WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $userId]
        );
    }

    public function testEachPositionErrorCodeIsStoredAsItsOwnReason(): void
    {
        $this->tick($this->volunteerIds[0]);
        foreach ([1 => 'denied', 2 => 'unavailable', 3 => 'timeout'] as $code => $expected) {
            // Cleared each time: this pins the code-to-reason mapping. Since
            // v3.320.0 a symptom (2, 3) deliberately does not overwrite a
            // recent cause (1) — GpsFixIntegrityTest covers that order.
            clearVolunteerGpsError($this->missionId, $this->volunteerIds[0]);
            $this->assertTrue(recordVolunteerGpsError($this->missionId, $this->volunteerIds[0], $code));
            $this->assertSame($expected, $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
        }
    }

    public function testAnUnrecognisedCodeIsStoredAsUnknownRatherThanDropped(): void
    {
        // "Their phone failed and we could not tell how" is still worth more
        // to a coordinator than silence.
        $this->tick($this->volunteerIds[0]);
        $this->assertTrue(recordVolunteerGpsError($this->missionId, $this->volunteerIds[0], 99));
        $this->assertSame('unknown', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    public function testSomebodyWithoutTheTickCannotRaiseAWarningOnTheRoster(): void
    {
        // Their roster line is deliberately blank; a red GPS warning on it
        // would contradict the page.
        $this->assertFalse(recordVolunteerGpsError($this->missionId, $this->volunteerIds[0], 1));
        $this->assertNull($this->storedGpsError($this->volunteerIds[0]));
    }

    public function testAPositionArrivingClearsTheOutstandingFailure(): void
    {
        // The warning must never outlive the problem: somebody who walked out
        // of a gorge cannot still read as unreachable an hour later.
        $this->tick($this->volunteerIds[0]);
        recordVolunteerGpsError($this->missionId, $this->volunteerIds[0], 1);
        $this->assertSame('denied', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);

        $result = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 12.0, 80, 'auto', 'browser');

        $this->assertTrue($result['ok']);
        $this->assertNull($this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
        $this->assertNull($this->storedGpsError($this->volunteerIds[0])['last_gps_error_at']);
    }

    public function testARefusedPositionDoesNotClearTheFailure(): void
    {
        // A fix refused for being hopelessly imprecise is not a recovery, so the
        // flag must not be cleared. Since v3.319.0 the refusal writes its own
        // reason over the older one, which is the more useful answer: what the
        // device is doing NOW, not what it last managed to complain about.
        $this->tick($this->volunteerIds[0]);
        recordVolunteerGpsError($this->missionId, $this->volunteerIds[0], 2);

        $result = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 4000.0, 80, 'auto', 'browser');

        $this->assertFalse($result['ok']);
        $this->assertSame('imprecise', $this->storedGpsError($this->volunteerIds[0])['last_gps_error']);
    }

    // ── The flag itself ─────────────────────────────────────────────────────

    public function testApprovedOnTheMissionIsNotTheSameAsTakingPart(): void
    {
        // Five approved volunteers, all on a team, none ticked. The whole
        // point: a fresh team starts with nobody carrying the operation, and
        // the coordinator says who does.
        $this->assertSame([], actionRoomParticipantIds($this->missionId));
        $this->assertFalse(isActionRoomParticipant($this->missionId, $this->volunteerIds[0]));
    }

    public function testTickingSomebodyPutsThemInAndUntickingTakesThemOut(): void
    {
        $this->tick($this->volunteerIds[0]);
        $this->assertTrue(isActionRoomParticipant($this->missionId, $this->volunteerIds[0]));

        setActionRoomParticipation($this->missionId, $this->volunteerIds[0], false, $this->adminId);
        $this->assertFalse(isActionRoomParticipant($this->missionId, $this->volunteerIds[0]));
    }

    public function testTickingTwiceReportsNoSecondChange(): void
    {
        // The switch posts the state it wants rather than "flip it", so a
        // double tap from a phone on a bad connection arrives twice. The
        // second one must be a no-op the caller can tell apart, or the audit
        // log fills with changes that never happened.
        $this->assertTrue(setActionRoomParticipation($this->missionId, $this->volunteerIds[0], true, $this->adminId));
        $this->assertFalse(setActionRoomParticipation($this->missionId, $this->volunteerIds[0], true, $this->adminId));
    }

    public function testOneMissionsTickSaysNothingAboutAnother(): void
    {
        $otherMissionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Other', 'Χανιά', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600),
             (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1")]
        );
        $this->tick($this->volunteerIds[0]);

        $this->assertFalse(isActionRoomParticipant($otherMissionId, $this->volunteerIds[0]));
        forgetActionRoomParticipantIds($otherMissionId);
    }

    // ── The team form's GPS column ──────────────────────────────────────────

    public function testTheTeamFormSetsExactlyTheMembersItWasGiven(): void
    {
        applyTeamActionRoomTicks(
            $this->missionId,
            $this->volunteerIds,
            [$this->volunteerIds[0], $this->volunteerIds[3]],
            $this->adminId
        );

        $ids = actionRoomParticipantIds($this->missionId);
        sort($ids);
        $expected = [$this->volunteerIds[0], $this->volunteerIds[3]];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testSavingATeamUnticksAMemberWhoseBoxWasCleared(): void
    {
        $this->tick($this->volunteerIds[0], $this->volunteerIds[1]);

        applyTeamActionRoomTicks($this->missionId, $this->volunteerIds, [$this->volunteerIds[0]], $this->adminId);

        $this->assertTrue(isActionRoomParticipant($this->missionId, $this->volunteerIds[0]));
        $this->assertFalse(isActionRoomParticipant($this->missionId, $this->volunteerIds[1]));
    }

    public function testDroppingSomebodyFromATeamDoesNotTakeAwayTheirGps(): void
    {
        // A volunteer moved out of one team is usually being moved INTO
        // another in the same save. Unticking them here would take the
        // position away mid-move, which is exactly what the single-save team
        // move exists to prevent — so the form only ever sets the flags of the
        // members it is actually saving.
        $this->tick($this->volunteerIds[4]);

        $remaining = array_slice($this->volunteerIds, 0, 4);
        applyTeamActionRoomTicks($this->missionId, $remaining, [$this->volunteerIds[0]], $this->adminId);

        $this->assertTrue(isActionRoomParticipant($this->missionId, $this->volunteerIds[4]));
    }

    // ── Positions ───────────────────────────────────────────────────────────

    public function testAPositionFromSomebodyWithoutTheTickIsRefusedNotStored(): void
    {
        $result = recordVolunteerPing($this->userRow($this->volunteerIds[1]), $this->shiftId, 35.33, 25.13, 12.0, 80, 'auto');

        $this->assertFalse($result['ok']);
        $this->assertSame(
            0,
            (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ?", [$this->volunteerIds[1]]),
            'refused at the door — a stored position could still surface through a trail or a report'
        );
    }

    public function testTheTickedVolunteersPositionIsStoredAsBefore(): void
    {
        $this->tick($this->volunteerIds[0]);

        $result = recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, 12.0, 80, 'auto');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ?", [$this->volunteerIds[0]]));
    }

    public function testUntickingSomeoneMidOperationStopsTheirNextPosition(): void
    {
        $this->tick($this->volunteerIds[0]);
        $this->assertTrue(recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, null, null, 'auto')['ok']);

        setActionRoomParticipation($this->missionId, $this->volunteerIds[0], false, $this->adminId);

        $this->assertFalse(recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.34, 25.14, null, null, 'auto')['ok']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_pings WHERE user_id = ?", [$this->volunteerIds[0]]));
    }

    public function testATrailNeverIncludesSomebodyWhoNoLongerTakesPart(): void
    {
        // Their earlier fixes are still on file: they were ticked when those
        // were recorded. The trail is the display half of the same rule and
        // has to drop them the moment the switch goes off, not whenever their
        // last fix happens to age out.
        $this->tick($this->volunteerIds[0]);
        recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.33, 25.13, null, null, 'manual');
        recordVolunteerPing($this->userRow($this->volunteerIds[0]), $this->shiftId, 35.34, 25.14, null, null, 'manual');

        $this->assertNotSame([], loadMissionTrailForMission($this->missionId, 0, true));

        setActionRoomParticipation($this->missionId, $this->volunteerIds[0], false, $this->adminId);

        $this->assertSame([], loadMissionTrailForMission($this->missionId, 0, true));
    }

    // ── Who an order or an alert may reach ──────────────────────────────────

    public function testOnlyTheTickedAreOfferedAsRecipients(): void
    {
        $this->tick($this->volunteerIds[0], $this->volunteerIds[2]);

        $ids = actionRoomNotifyRecipientIds($this->missionId);
        sort($ids);
        $expected = [$this->volunteerIds[0], $this->volunteerIds[2]];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testTheSenderIsNeverOneOfTheirOwnRecipients(): void
    {
        $this->tick($this->volunteerIds[0], $this->volunteerIds[2]);

        $this->assertSame(
            [$this->volunteerIds[2]],
            actionRoomNotifyRecipientIds($this->missionId, null, $this->volunteerIds[0])
        );
    }

    public function testATeamTargetedAlertStaysInsideThatTeam(): void
    {
        $otherTeamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΒΗΤΑ', 2, $this->adminId]
        );
        $outsider = $this->makeUser('Ζωή Ζ.');
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $outsider, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$otherTeamId, $this->missionId, $outsider]
        );
        $this->tick($this->volunteerIds[0], $outsider);

        $this->assertSame([$this->volunteerIds[0]], actionRoomNotifyRecipientIds($this->missionId, $this->teamId));
        $this->assertSame([$outsider], actionRoomNotifyRecipientIds($this->missionId, $otherTeamId));
    }

    // ── The assistant ───────────────────────────────────────────────────────

    public function testSilenceIsOnlyReportedForPeopleWhoWereAskedToReport(): void
    {
        // Two of five carry the operation. Neither has pinged. The other
        // three were never asked to, so raising them would be reporting a
        // decision the coordinator made on purpose — and on a real screen
        // that is three false alarms hiding two real ones.
        $this->tick($this->volunteerIds[0], $this->volunteerIds[1]);

        $raw = collectMissionAssistantRaw($this->missionId, $this->adminId, [$this->shiftId], null, time());
        $silentIds = array_map('intval', array_column($raw['silent'], 'id'));
        sort($silentIds);
        $expected = [$this->volunteerIds[0], $this->volunteerIds[1]];
        sort($expected);

        $this->assertSame($expected, $silentIds);
    }

    // ── What the coordinator sees ───────────────────────────────────────────

    public function testTheTeamsCardKnowsWhichMembersCarryTheOperation(): void
    {
        // Everyone stays on the card — the whole point of a roster is knowing
        // who is out there — so the flag rides on each member instead.
        $this->tick($this->volunteerIds[0]);

        $teams = loadMissionTeamsForMission($this->missionId);
        $members = $teams[$this->teamId]['members'];
        $this->assertCount(5, $members);

        $takingPart = array_values(array_filter($members, fn($m) => $m['takes_part']));
        $this->assertCount(1, $takingPart);
        $this->assertSame($this->volunteerIds[0], $takingPart[0]['user_id']);
    }
}
