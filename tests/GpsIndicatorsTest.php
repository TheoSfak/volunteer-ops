<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every GPS indication and check is about the people whose GPS is on (v3.402.1),
 * never about everybody on the mission.
 *
 * "3 of 20 without a position" is a different statement from "3 of 5" when fifteen
 * of the twenty were never being asked for a position; the Action Room already
 * lets command switch GPS off per person (mission_action_room_participants), and
 * a figure that still counted those people as part of the total made a quiet team
 * look better, or a mission look worse, than it was.
 *
 * Two layers:
 *  - the one server-side check that still read every team member: «a team has
 *    stopped moving», run against the test database in a transaction that is
 *    always rolled back;
 *  - source pins for the console, whose figures are computed in the browser from
 *    pure helpers (tests/js/war-room-console.test.js runs those).
 */
final class GpsIndicatorsTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Gps Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Gps Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200)]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)",
            [$this->missionId, $this->adminId]
        );
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        forgetActionRoomParticipantIds($this->missionId);
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'gpsi-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** A team member who stood still: a fix every ten minutes for the last forty, all at one spot. */
    private function standingMember(bool $gpsOn): int
    {
        $uid = $this->makeUser('Member ' . uniqid());
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $uid, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$this->teamId, $this->missionId, $uid]
        );
        if ($gpsOn) {
            setActionRoomParticipation($this->missionId, $uid, true, $this->adminId);
        }
        foreach ([10, 20, 30, 40] as $minutesAgo) {
            dbInsert(
                "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, created_at) VALUES (?, ?, 35.2000, 24.9000, 8, 'auto', ?)",
                [$uid, $this->shiftId, date('Y-m-d H:i:s', time() - $minutesAgo * 60)]
            );
        }
        return $uid;
    }

    public function testATeamThatStoodStillWithItsGpsOnIsReportedAsStalled(): void
    {
        $this->standingMember(true);
        $stalled = assistantStalledTeams($this->missionId, [$this->shiftId], time());
        $this->assertCount(1, $stalled);
        $this->assertSame($this->teamId, $stalled[0]['team_id']);
        $this->assertSame(1, $stalled[0]['members']);
    }

    public function testSomeoneSwitchedOffMidOperationDoesNotMakeTheirTeamLookStalled(): void
    {
        // Their fixes from before the switch are still in the window; nobody is
        // being measured any more, so there is nothing to report.
        $this->standingMember(false);
        $this->assertSame([], assistantStalledTeams($this->missionId, [$this->shiftId], time()));
    }

    public function testOnlyTheMembersOnGpsAreCountedInAStalledTeam(): void
    {
        $this->standingMember(true);
        $this->standingMember(true);
        $this->standingMember(false);
        $stalled = assistantStalledTeams($this->missionId, [$this->shiftId], time());
        $this->assertCount(1, $stalled);
        $this->assertSame(2, $stalled[0]['members'], 'the third member has no GPS and is not part of the figure');
    }

    // ── the console: figures come from the helper, never from the raw member list ──

    public function testTheConsoleMeasuresContactAmongTheMembersWhoseGpsIsOn(): void
    {
        $js = file_get_contents(__DIR__ . '/../assets/js/war-room-console.js');
        $this->assertStringContainsString('function wrcContactOf(', $js);
        // The two places that used to read `members.filter(m => isStale(...))` and
        // `members.length` now go through the helper.
        $this->assertStringContainsString('wrcContactOf(members, isStale)', $js);
        $this->assertStringNotContainsString('members.filter(m => isStale(m.user_id))', $js,
            'a figure about contact must never be taken over everybody, only over the members on GPS');
        $this->assertStringContainsString('of: f.gpsPeople', $js, 'the readiness row\'s total is the people on GPS');
        $this->assertStringNotContainsString('stale > 0 ? \'warn\' : \'ok\', n: f.stale, of: f.people', $js);
    }
}
