<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Βγήκες από τον τομέα» (v3.391.0): a member of a team that has arrived in its
 * sector and has not pressed «Ολοκλήρωση Τομέα» is told, once, when a fix puts
 * them more than 100 m outside it.
 *
 * checkSectorExitWarnings() runs on every ping from every source, so what it
 * must NOT do matters as much as what it does: a teammate still walking to the
 * sector is far outside it too, a person pacing the boundary must not be asked on
 * every crossing, and a vague or stale fix says nothing either way.
 *
 * The sector is a 200 m square. Runs inside a transaction that is always rolled
 * back.
 */
final class SectorExitWarningTest extends TestCase
{
    private const SOUTH = 35.2000;
    private const NORTH = 35.2018;   // 0.0018 degrees of latitude: 200 m
    private const WEST = 24.9000;
    private const EAST = 24.9022;    // 0.0022 degrees of longitude at 35 N: 200 m
    private const MID_LNG = 24.9011;

    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $teammate;
    private int $stranger;
    private int $areaId;
    private int $sectorId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Exit Admin');
        dbExecute("UPDATE users SET role = ? WHERE id = ?", [ROLE_DEPARTMENT_ADMIN, $this->adminId]);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Exit Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->member = $this->makeVolunteer('Άννα Α.', $this->teamId);
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.', $this->teamId);
        $this->stranger = $this->makeVolunteer('Μαρία Μ.', $this->otherTeamId);
        $this->areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη Εξόδου', json_encode([[self::SOUTH, self::WEST], [self::NORTH, self::WEST], [self::NORTH, self::EAST], [self::SOUTH, self::EAST]]), $this->adminId]
        );
        $this->sectorId = $this->makeSector('in_progress');
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
            [$name, 'exit-' . uniqid('', true) . '@example.invalid', 'x']
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

    /** The team's sector, with the status stamped a fixed minute ago. */
    private function makeSector(string $status): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, acknowledged_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 MINUTE), DATE_SUB(NOW(), INTERVAL 5 MINUTE), ?)",
            [
                $this->missionId, $this->areaId, $this->teamId, 'Τομέας Α3',
                json_encode([[self::SOUTH, self::WEST], [self::NORTH, self::WEST], [self::NORTH, self::EAST], [self::SOUTH, self::EAST]]),
                $status, $this->adminId,
            ]
        );
    }

    /** A fix $metres north of the sector's north edge (negative: inside it). */
    private function northOfSector(float $metres): array
    {
        return [self::NORTH + $metres / 111320.0, self::MID_LNG];
    }

    private function fix(int $userId, array $at, ?float $accuracy = 10.0, int $ageSeconds = 5): void
    {
        checkSectorExitWarnings(
            $this->missionId, $userId, getUserTeamIdForMission($this->missionId, $userId),
            $at[0], $at[1], $accuracy, $ageSeconds
        );
    }

    private function inside(): array
    {
        return $this->northOfSector(-100.0);
    }

    private function notices(int $userId): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND data LIKE '%mission_sector_exit%'",
            [$userId]
        );
    }

    private function state(int $userId): ?string
    {
        $value = dbFetchValue("SELECT state FROM mission_sector_exit_watch WHERE sector_id = ? AND user_id = ?", [$this->sectorId, $userId]);
        return $value === false ? null : $value;
    }

    public function testWalkingOutAfterBeingInTheSectorWarnsOnceAndOnlyOnce(): void
    {
        $this->fix($this->member, $this->inside());
        $this->assertSame('near', $this->state($this->member));
        $this->assertSame([], $this->notices($this->member), 'inside the sector there is nothing to say');

        $this->fix($this->member, $this->northOfSector(150.0));
        $this->fix($this->member, $this->northOfSector(400.0));
        $this->fix($this->member, $this->northOfSector(150.0));

        $notices = $this->notices($this->member);
        $this->assertCount(1, $notices, 'once per walk out, not once per fix');
        $this->assertSame('warned', $this->state($this->member));
        $data = json_decode($notices[0]['data'], true);
        $this->assertSame($this->sectorId, $data['exitSectorId']);
        $this->assertSame($this->missionId, $data['bannerMission'], 'an order-class alert: popup on the page, the loud channel on the phone');
        $this->assertSame('mission_sector_exit', $data['popupInfo']);
        $this->assertArrayNotHasKey('sectorId', $data, 'sectorId would turn it into an order about the sector');
        $this->assertSame(
            ['kind' => 'info', 'info' => 'mission_sector_exit', 'id' => 0, 'sectorId' => $this->sectorId],
            notificationPopupRef($data)
        );
        $this->assertStringContainsString('«Α3»', $notices[0]['message'], 'the sector, without the word "Τομέας" that the sentence already says');
        $this->assertStringContainsString('100', $notices[0]['message']);
        $this->assertStringContainsString('Ολοκλήρωση Τομέα', $notices[0]['message'], 'it names the button to press');
        $this->assertSame([], $this->notices($this->teammate), 'nobody else is told');
    }

    public function testTheLineIsOneHundredMetresNotSooner(): void
    {
        $this->fix($this->member, $this->inside());

        $this->fix($this->member, $this->northOfSector(40.0));
        $this->fix($this->member, $this->northOfSector(98.0));
        $this->assertSame([], $this->notices($this->member), 'just outside the boundary is still the sector as far as this goes');
        $this->assertSame('near', $this->state($this->member));

        $this->fix($this->member, $this->northOfSector(103.0));
        $this->assertCount(1, $this->notices($this->member));
    }

    public function testSomeoneWhoWasNeverAtTheSectorIsNeverTold(): void
    {
        // A teammate still walking to it: the team's status is in_progress
        // because somebody else arrived, and this person is far outside.
        $this->fix($this->teammate, $this->northOfSector(900.0));
        $this->fix($this->teammate, $this->northOfSector(600.0));
        $this->fix($this->teammate, $this->northOfSector(300.0));
        $this->assertNull($this->state($this->teammate));
        $this->assertSame([], $this->notices($this->teammate));

        // Once they get there, leaving counts.
        $this->fix($this->teammate, $this->northOfSector(60.0));
        $this->assertSame('near', $this->state($this->teammate), 'within 100 m of the edge is being there: a «Έφτασα» pressed beside the sector counts');
        $this->fix($this->teammate, $this->northOfSector(250.0));
        $this->assertCount(1, $this->notices($this->teammate));
    }

    public function testComingBackToTheBoundaryDoesNotRearmButGoingInsideDoes(): void
    {
        $this->fix($this->member, $this->inside());
        $this->fix($this->member, $this->northOfSector(200.0));
        $this->assertCount(1, $this->notices($this->member));

        // Pacing the 100 m line, back and forth: asked once, not on every crossing.
        $this->fix($this->member, $this->northOfSector(60.0));
        $this->fix($this->member, $this->northOfSector(180.0));
        $this->fix($this->member, $this->northOfSector(40.0));
        $this->fix($this->member, $this->northOfSector(180.0));
        $this->assertCount(1, $this->notices($this->member));
        $this->assertSame('warned', $this->state($this->member));

        // Back inside the sector itself, and out again: a new walk, a new question.
        $this->fix($this->member, $this->inside());
        $this->assertSame('near', $this->state($this->member));
        $this->fix($this->member, $this->northOfSector(200.0));
        $this->assertCount(2, $this->notices($this->member));
    }

    public function testOnlyASectorThatIsBeingSearchedIsWatched(): void
    {
        foreach (['assigned', 'en_route', 'completed', 'needs_recheck', 'not_started'] as $status) {
            dbExecute("UPDATE mission_search_sectors SET status = ? WHERE id = ?", [$status, $this->sectorId]);
            $this->fix($this->member, $this->inside());
            $this->fix($this->member, $this->northOfSector(300.0));
            $this->assertNull($this->state($this->member), $status);
            $this->assertSame([], $this->notices($this->member), $status);
        }
    }

    public function testCompletingTheSectorBeforeLeavingSaysNothing(): void
    {
        $this->fix($this->member, $this->inside());
        dbExecute("UPDATE mission_search_sectors SET status = 'completed', status_updated_at = NOW() WHERE id = ?", [$this->sectorId]);
        $this->fix($this->member, $this->northOfSector(300.0));
        $this->assertSame([], $this->notices($this->member));
    }

    public function testAnotherTeamsSectorIsNoOnesBusinessButTheirOwn(): void
    {
        $this->fix($this->stranger, $this->inside());
        $this->fix($this->stranger, $this->northOfSector(300.0));
        $this->assertNull($this->state($this->stranger));
        $this->assertSame([], $this->notices($this->stranger));

        // And a person with no team at all.
        $loner = $this->makeUser('Χωρίς Ομάδα');
        checkSectorExitWarnings($this->missionId, $loner, null, $this->inside()[0], $this->inside()[1], 10.0, 5);
        checkSectorExitWarnings($this->missionId, $loner, null, $this->northOfSector(300.0)[0], $this->northOfSector(300.0)[1], 10.0, 5);
        $this->assertSame([], $this->notices($loner));
    }

    public function testAFixTooVagueOrTooOldSaysNothingEitherWay(): void
    {
        // Too vague to arm.
        $this->fix($this->member, $this->inside(), 70.0);
        $this->fix($this->member, $this->inside(), null);
        $this->fix($this->member, $this->inside(), 10.0, 300);
        $this->assertNull($this->state($this->member));

        // Armed by a good one, then only bad ones outside: still nothing.
        $this->fix($this->member, $this->inside());
        $this->fix($this->member, $this->northOfSector(300.0), 70.0);
        $this->fix($this->member, $this->northOfSector(300.0), null);
        $this->fix($this->member, $this->northOfSector(300.0), 10.0, 300);
        $this->assertSame([], $this->notices($this->member));
        $this->assertSame('near', $this->state($this->member));

        // The first good one outside is the one that counts.
        $this->fix($this->member, $this->northOfSector(300.0));
        $this->assertCount(1, $this->notices($this->member));
    }

    public function testASectorResumedAfterARecheckStartsClean(): void
    {
        $this->fix($this->member, $this->inside());
        $this->assertSame('near', $this->state($this->member));

        // Completed, sent back for a recheck, resumed: a new stint of the sector.
        dbExecute("UPDATE mission_search_sectors SET status = 'in_progress', status_updated_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?", [$this->sectorId]);

        // Last pass's "near" must not make a person who is back at base look like they walked out.
        $this->fix($this->member, $this->northOfSector(400.0));
        $this->assertSame([], $this->notices($this->member));

        // They get there this time, and leaving counts again.
        $this->fix($this->member, $this->inside());
        $this->fix($this->member, $this->northOfSector(400.0));
        $this->assertCount(1, $this->notices($this->member));
    }

    public function testTwoFixesLandingTogetherCannotBothWarn(): void
    {
        $this->fix($this->member, $this->inside());
        // What a second request would find after the first has marked it: the
        // UPDATE near -> warned is the gate, and it can only be won once.
        $stint = (string) dbFetchValue("SELECT status_updated_at FROM mission_search_sectors WHERE id = ?", [$this->sectorId]);
        $first = dbExecute(
            "UPDATE mission_sector_exit_watch SET state = 'warned', updated_at = NOW() WHERE sector_id = ? AND user_id = ? AND stint_at = ? AND state = 'near'",
            [$this->sectorId, $this->member, $stint]
        );
        $second = dbExecute(
            "UPDATE mission_sector_exit_watch SET state = 'warned', updated_at = NOW() WHERE sector_id = ? AND user_id = ? AND stint_at = ? AND state = 'near'",
            [$this->sectorId, $this->member, $stint]
        );
        $this->assertSame([1, 0], [$first, $second]);
    }

    public function testARealPingThroughTheServerArmsAndThenWarns(): void
    {
        // Through recordVolunteerPing() itself, on the smoothed estimate the
        // page and the command post see, not just the function on its own.
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('war_room_gps_smoothing', '0', NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        forgetActionRoomParticipantIds($this->missionId);
        setActionRoomParticipation($this->missionId, $this->member, true, $this->adminId);
        $user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->member]);

        $in = $this->inside();
        $result = recordVolunteerPing($user, $this->shiftId, $in[0], $in[1], 8.0, 80, 'auto', 'browser');
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame('near', $this->state($this->member));
        $this->assertSame([], $this->notices($this->member));

        // An hour later, so the speed gate has nothing to object to.
        dbExecute("UPDATE volunteer_pings SET created_at = DATE_SUB(created_at, INTERVAL 1 HOUR) WHERE user_id = ?", [$this->member]);
        $out = $this->northOfSector(150.0);
        $result = recordVolunteerPing($user, $this->shiftId, $out[0], $out[1], 8.0, 80, 'auto', 'browser');
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertCount(1, $this->notices($this->member));
        $this->assertSame('warned', $this->state($this->member));
    }

    public function testThePopupOffersCompleteSectorAndTheMapTapNeverIsIt(): void
    {
        $page = (string) file_get_contents(__DIR__ . '/../war-room.php');
        $fn = function (string $signature) use ($page): string {
            $start = strpos($page, $signature);
            $this->assertNotFalse($start, "$signature is gone");
            $this->assertSame(1, preg_match('~\r?\n\}\r?\n~', $page, $m, PREG_OFFSET_CAPTURE, $start), "$signature never closes");
            return substr($page, $start, $m[0][1] - $start);
        };

        // The notice is built from the poll's `info` popup, with the sector's id.
        $this->assertStringContainsString("b.popup.info === 'mission_sector_exit'", $fn('function opModelFromNotice('));
        // «Ολοκλήρωση Τομέα» is the first button, and only while it can be pressed…
        $go = $fn('function opGoAction(');
        $this->assertMatchesRegularExpression("~m\\.type === 'sector_exit' && m\\.advanceStatus\\) return \\['advance'~", $go);
        $this->assertLessThan(strpos($go, "m.kind === 'info'"), strpos($go, "m.type === 'sector_exit'"), 'before the generic «Κατάλαβα» of a notice');
        // …and tapping the small map must never be it.
        $this->assertMatchesRegularExpression("~m\\.type === 'sector_exit' \\? \\['wpmap'~", $fn('function opMiniHtml('));
        // «Γυρνάω πίσω» is the notice's own dismiss.
        $this->assertStringContainsString("opBtn('dismiss', 'bi-arrow-return-left', t('popup.btn.sector_exit_back')", $fn('function opNoticeCardBtnHtml('));
        // Kept current by the sync, dropped once the sector is done; ignored on arrival if it already is.
        $sync = $fn('function orderPopupSync(');
        $this->assertStringContainsString("m.type === 'sector_exit'", $sync);
        $this->assertStringContainsString('opSectorExitOpen(m.id)', $sync);
        $this->assertStringContainsString("b.popup.info === 'mission_sector_exit' && !opSectorExitOpen(b.popup.sectorId)", $fn('function orderPopupTakeBanner('));
        $this->assertStringContainsString("sector.status === 'in_progress'", $fn('function opSectorExitOpen('));
    }

    public function testThePingHooksItAndTheEnglishWordingExists(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../includes/functions-warroom.php');
        $start = strpos($source, 'function recordVolunteerPing(');
        $end = strpos($source, "\nfunction ", $start + 10);
        $this->assertNotFalse($start);
        $this->assertStringContainsString('checkSectorExitWarnings(', substr($source, $start, $end - $start), 'recordVolunteerPing() must call it on every fix');

        $lang = loadLangStrings('war-room');
        foreach (['el', 'en'] as $code) {
            foreach (['sector_exit.title', 'sector_exit.message', 'popup.type.sector_exit', 'popup.btn.sector_exit_back'] as $key) {
                $this->assertArrayHasKey($key, $lang[$code], "$code $key");
            }
        }
        $this->assertStringContainsString('{m}', $lang['en']['sector_exit.message']);
        $this->assertStringContainsString('{label}', $lang['en']['sector_exit.message']);
    }
}
