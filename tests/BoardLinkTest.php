<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Board view link (v3.499.0): the read-only, non-interactive, big-font
 * «Οθόνη τοίχου» mirror of the live map + team roster (board-view.php), for
 * a second screen or a liaising authority. Same token idiom as
 * mission_locate_links (see LocateLinkTest) - this file pins the extra data
 * shape: loadBoardViewPins() only shows GPS-on Action Room participants, and
 * loadBoardViewTeams() aggregates them per team.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class BoardLinkTest extends TestCase
{
    private int $missionId;
    private int $userId;
    private int $shiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'DEPARTMENT_ADMIN')",
            ['Συντονιστής Τεστ', 'bl-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['BL Mission', 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->userId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200)]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeVolunteer(string $name): int {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'VOLUNTEER')",
            [$name, 'bl-vol-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function addParticipant(int $userId): void {
        dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $userId]);
        forgetActionRoomParticipantIds($this->missionId);
    }

    private function ping(int $userId, float $lat, float $lng, ?string $createdAt = null): void {
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, created_at) VALUES (?, ?, ?, ?, 8, ?)",
            [$userId, $this->shiftId, $lat, $lng, $createdAt ?? date('Y-m-d H:i:s')]
        );
    }

    public function testCreateReturnsA64CharHexTokenDistinctFromLocateLinks(): void
    {
        $link = createMissionBoardLink($this->missionId, 'Οθόνη γραφείου', $this->userId, null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $link['token']);
        $this->assertNull(findActiveMissionLocateLink($link['token']));
    }

    public function testAnActiveTokenResolvesAndARevokedOneDoesNot(): void
    {
        $link = createMissionBoardLink($this->missionId, null, $this->userId, null);
        $this->assertNotNull(findActiveMissionBoardLink($link['token']));
        revokeMissionBoardLink($link['id'], $this->missionId);
        $this->assertNull(findActiveMissionBoardLink($link['token']));
    }

    public function testTheListedUrlIsBuiltServerSideFromBaseUrlNotLocationOrigin(): void
    {
        $link = createMissionBoardLink($this->missionId, 'A', $this->userId, null);
        $listed = loadMissionBoardLinks($this->missionId)[0];
        $this->assertSame(rtrim(BASE_URL, '/') . '/board-view.php?token=' . $link['token'], $listed['url']);
    }

    public function testViewingIncrementsTheCounterAndStampsTheTime(): void
    {
        $link = createMissionBoardLink($this->missionId, null, $this->userId, null);
        recordBoardLinkView((int) $link['id']);
        recordBoardLinkView((int) $link['id']);
        $row = dbFetchOne("SELECT view_count, last_viewed_at FROM mission_board_links WHERE id = ?", [$link['id']]);
        $this->assertSame(2, (int) $row['view_count']);
        $this->assertNotNull($row['last_viewed_at']);
    }

    public function testPinsOnlyIncludeActionRoomParticipants(): void
    {
        $onGps = $this->makeVolunteer('Έχει GPS');
        $offGps = $this->makeVolunteer('Χωρίς GPS');
        $this->addParticipant($onGps);
        // offGps gets a ping but is never added as a participant.
        $this->ping($onGps, 35.3, 24.5);
        $this->ping($offGps, 35.31, 24.51);

        $pins = loadBoardViewPins($this->missionId);
        $this->assertCount(1, $pins);
        $this->assertSame('Έχει GPS', $pins[0]['name']);
    }

    public function testOnlyTheLatestPingPerVolunteerIsShown(): void
    {
        $vol = $this->makeVolunteer('Παλιά και νέα θέση');
        $this->addParticipant($vol);
        $this->ping($vol, 35.0, 24.0, date('Y-m-d H:i:s', time() - 300));
        $this->ping($vol, 35.5, 24.5, date('Y-m-d H:i:s'));

        $pins = loadBoardViewPins($this->missionId);
        $this->assertCount(1, $pins);
        $this->assertSame(35.5, $pins[0]['lat']);
    }

    public function testNoVitalsOrPhoneFieldLeakIntoThePinShape(): void
    {
        $vol = $this->makeVolunteer('Απλός Εθελοντής');
        $this->addParticipant($vol);
        $this->ping($vol, 35.3, 24.5);
        $pin = loadBoardViewPins($this->missionId)[0];
        $this->assertArrayNotHasKey('heart_rate', $pin);
        $this->assertArrayNotHasKey('battery_level', $pin);
        $this->assertArrayNotHasKey('phone', $pin);
    }

    public function testTeamToneIsNoneWhenNobodyOnTheTeamHasAPin(): void
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, 1, '#ff0000', ?)",
            [$this->missionId, 'Alpha', $this->userId]
        );
        $vol = $this->makeVolunteer('Μέλος χωρίς στίγμα');
        dbExecute("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $vol]);

        $teams = loadBoardViewTeams($this->missionId, []);
        $this->assertCount(1, $teams);
        $this->assertSame('none', $teams[0]['tone']);
        $this->assertSame(0, $teams[0]['gps_on']);
        $this->assertSame(1, $teams[0]['members_total']);
    }

    public function testTeamToneIsOkWhenItsPinsAreFresh(): void
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, 1, '#00ff00', ?)",
            [$this->missionId, 'Bravo', $this->userId]
        );
        $vol = $this->makeVolunteer('Μέλος με στίγμα');
        $this->addParticipant($vol);
        dbExecute("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $vol]);
        $this->ping($vol, 35.3, 24.5);

        $pins = loadBoardViewPins($this->missionId);
        $teams = loadBoardViewTeams($this->missionId, $pins);
        $this->assertSame('ok', $teams[0]['tone']);
        $this->assertSame(1, $teams[0]['gps_on']);
    }

    public function testTeamToneIsWarnWhenItsOnlyPinIsStale(): void
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, 1, '#0000ff', ?)",
            [$this->missionId, 'Charlie', $this->userId]
        );
        $vol = $this->makeVolunteer('Μέλος με παλιό στίγμα');
        $this->addParticipant($vol);
        dbExecute("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $vol]);
        $this->ping($vol, 35.3, 24.5, date('Y-m-d H:i:s', time() - warRoomPingStaleThresholdSeconds() - 60));

        $pins = loadBoardViewPins($this->missionId);
        $this->assertTrue($pins[0]['is_stale']);
        $teams = loadBoardViewTeams($this->missionId, $pins);
        $this->assertSame('warn', $teams[0]['tone']);
    }
}
