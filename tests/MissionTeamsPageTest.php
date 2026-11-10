<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The «Ομάδες» page (v3.463.0): mission-teams-view.php, mission-teams.php, assets/js/mission-teams.js and what they all
 * stand on, includes/functions-mission-teams.php.
 *
 * The things that must hold:
 *   - the automatic split is the rule that was asked for: one number, whoever is left over goes into the LAST team, and
 *     people of one home team are kept side by side - and it is the same split every time it is asked;
 *   - no team made here switches anybody's GPS on, and nothing that happens to a team (a move, a delete, a second
 *     split) changes a GPS switch: GPS is its own act;
 *   - a leader and an only member cannot be moved away, two teams never read the same, a leader is a member;
 *   - the page and its endpoint are for command staff only, and a volunteer is sent away before anything is printed;
 *   - every word the script asks for is in the page's own small table, in both languages;
 *   - the Action Room's own team windows go through the same functions.
 *
 * The database cases run inside a transaction that is always rolled back.
 */
final class MissionTeamsPageTest extends TestCase
{
    private int $missionId = 0;
    private int $shiftId = 0;
    private int $adminId = 0;
    private int $partnerTeamId = 0;
    private array $mission = [];

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Teams Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Teams Page Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->mission = ['id' => $this->missionId, 'title' => 'Teams Page Mission'];
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->partnerTeamId = (int) dbInsert(
            "INSERT INTO volunteer_teams (name, color, is_default, is_active) VALUES (?, ?, 0, 1)",
            ['Partner ' . uniqid(), '#2a52be']
        );
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
            [$name, 'mtp-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** An approved volunteer of the mission: of the partner team when $partner, else with no home team set. */
    private function approve(string $name, bool $partner = false, bool $captain = false): int
    {
        $id = $this->makeUser($name);
        if ($partner || $captain) {
            dbExecute("UPDATE users SET volunteer_team_id = ?, is_team_captain = ? WHERE id = ?", [$partner ? $this->partnerTeamId : null, $captain ? 1 : 0, $id]);
        }
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $id, PARTICIPATION_APPROVED]);
        return $id;
    }

    /** @return int[] */
    private function approveMany(int $count, string $prefix, bool $partner = false): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->approve(sprintf('%s %02d', $prefix, $i), $partner);
        }
        return $ids;
    }

    /** team id => member ids, in the order the teams were made. */
    private function teams(): array
    {
        $teams = [];
        foreach (dbFetchAll("SELECT id FROM mission_teams WHERE mission_id = ? ORDER BY id", [$this->missionId]) as $row) {
            $teams[(int) $row['id']] = array_map('intval', array_column(
                dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ? ORDER BY user_id", [$row['id']]),
                'user_id'
            ));
        }
        return $teams;
    }

    private function gpsIds(): array
    {
        forgetActionRoomParticipantIds($this->missionId);
        $ids = actionRoomParticipantIds($this->missionId);
        sort($ids);
        return $ids;
    }

    private function people(int $count, string $homeKey = 'a', int $firstId = 1): array
    {
        $people = [];
        for ($i = 0; $i < $count; $i++) {
            $people[] = ['user_id' => $firstId + $i, 'name' => sprintf('P%03d', $firstId + $i), 'home_key' => $homeKey, 'is_captain' => false];
        }
        return $people;
    }

    private function source(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) \WarRoomSource::read($rel));
    }

    // ── the split ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testWhoeverIsLeftOverGoesIntoTheLastTeam(): void
    {
        $sizes = fn(int $people, int $size) => array_map(fn($team) => count($team['member_ids']), planAutoTeams($this->people($people), $size));
        $this->assertSame([5, 5, 5, 8], $sizes(23, 5), '23 in fives: the three left over join the fourth team');
        $this->assertSame([5, 5], $sizes(10, 5));
        $this->assertSame([5, 6], $sizes(11, 5));
        $this->assertSame([4, 4, 7], $sizes(15, 4));
        $this->assertSame([3], $sizes(3, 5), 'fewer people than one team are one team');
        $this->assertSame([1, 1, 1], $sizes(3, 1));
        $this->assertSame([], $sizes(0, 5));
        // A number that is no number of people is treated as one, never as a division by zero.
        $this->assertSame([1, 1], $sizes(2, 0));
    }

    public function testEverybodyIsOnExactlyOneTeam(): void
    {
        $people = array_merge($this->people(9, 'a'), $this->people(4, 'b', 100), $this->people(3, '', 200));
        $plan = planAutoTeams($people, 4);
        $placed = array_merge(...array_column($plan, 'member_ids'));
        sort($placed);
        $this->assertSame(array_column($people, 'user_id'), $placed);
        $this->assertSame(count($placed), count(array_unique($placed)));
    }

    public function testPeopleOfOneHomeTeamAreKeptTogether(): void
    {
        // Seven of the big home team, three of a partner, two with none; teams of four.
        $people = array_merge($this->people(3, 'partner', 100), $this->people(2, '', 200), $this->people(7, 'epidrasis'));
        $homeOf = array_column($people, 'home_key', 'user_id');
        $plan = planAutoTeams($people, 4);
        $homes = array_map(fn($team) => array_map(fn($id) => $homeOf[$id], $team['member_ids']), $plan);
        $this->assertSame([
            ['epidrasis', 'epidrasis', 'epidrasis', 'epidrasis'],   // the biggest home team first: a whole team of its own
            ['epidrasis', 'epidrasis', 'epidrasis', ''],            // bigger than a team, so cut once, in two large pieces
            ['partner', 'partner', 'partner', ''],                  // the partner fits whole on a team, so it is not cut
        ], $homes, 'those with no home team fill the places that are left');
    }

    /** How many of each home team every team of the split holds, e.g. ['5E', '4E+1-', '4X+2Y']. */
    private function layout(array $spec, int $size): array
    {
        $people = [];
        foreach ($spec as $key => $count) {
            $people = array_merge($people, $this->people($count, (string) $key, count($people) + 1));
        }
        $homeOf = array_column($people, 'home_key', 'user_id');
        return array_map(function ($team) use ($homeOf) {
            $counts = array_count_values(array_map(fn($id) => $homeOf[$id] === '' ? '-' : $homeOf[$id], $team['member_ids']));
            return implode('+', array_map(fn($key, $n) => $n . $key, array_keys($counts), $counts));
        }, planAutoTeams($people, $size));
    }

    public function testAHomeTeamIsCutOnlyWhenItFitsNowhereWhole(): void
    {
        // Laid out in one row and cut every five, the four of X would be 1 + 3. They fit whole on the last team.
        $this->assertSame(['5E', '4E+1-', '4X+2Y'], $this->layout(['E' => 9, 'X' => 4, 'Y' => 2, '' => 1], 5));
        // Fourteen cannot be one team: 5 + 5 + 4, never a piece of one. The six fit whole on the big last team.
        $this->assertSame(['5E', '5E', '4E+1F', '6R+2F'], $this->layout(['E' => 14, 'R' => 6, 'F' => 3], 5));
        $this->assertSame(['3E', '2E+2P'], $this->layout(['E' => 5, 'P' => 2], 3));
        // Three home teams of four in two teams of six: one of them has to be cut, and only one is.
        $this->assertSame(['4A+2C', '4B+2C'], $this->layout(['A' => 4, 'B' => 4, 'C' => 4], 6));
        // One home team only: nothing to keep together, the sizes are the whole story.
        $this->assertSame(['5E', '5E', '5E', '8E'], $this->layout(['E' => 23], 5));
        $this->assertSame(['6E', '6E', '6E', '2E+2Z+2-', '5Y+1-', '7X'], $this->layout(['E' => 20, 'X' => 7, 'Y' => 5, 'Z' => 2, '' => 3], 6));
    }

    public function testTheSplitDoesNotDependOnTheOrderPeopleCameIn(): void
    {
        $people = array_merge($this->people(7, 'a'), $this->people(5, 'b', 100), $this->people(2, '', 200));
        $plan = planAutoTeams($people, 3);
        for ($i = 0; $i < 5; $i++) {
            shuffle($people);
            $this->assertSame($plan, planAutoTeams($people, 3));
        }
    }

    public function testTheLeaderIsTheHomeTeamCaptainElseTheFirstMember(): void
    {
        $people = $this->people(6, 'a');
        $people[4]['is_captain'] = true;                      // P005
        $plan = planAutoTeams($people, 3);
        $this->assertSame(5, $plan[0]['leader_id'], 'the captain leads the team they are on');
        $this->assertSame(5, $plan[0]['member_ids'][0], 'and heads their home team in the list');
        $this->assertSame($plan[1]['member_ids'][0], $plan[1]['leader_id'], 'a team with no captain is led by its first member');
        foreach ($plan as $team) {
            $this->assertContains($team['leader_id'], $team['member_ids']);
        }
    }

    public function testTheHomeTeamAPersonIsGroupedUnder(): void
    {
        $this->assertSame(['key' => 'επίδρασις', 'name' => 'Επίδρασις'], missionTeamHomeGroup('Επίδρασις', false, null, 'Άλλη'));
        // A member whose badge was never filled in is still a member of the organisation.
        $this->assertSame('Επίδρασις', missionTeamHomeGroup(null, false, null, 'Επίδρασις')['name']);
        // A guest is of the organisation their account names, never of ours by default.
        $this->assertSame('ΕΜΑΚ', missionTeamHomeGroup('', true, ' ΕΜΑΚ ', 'Επίδρασις')['name']);
        $this->assertSame(['key' => '', 'name' => ''], missionTeamHomeGroup(null, true, null, 'Επίδρασις'));
        // The same organisation written in another case is one group.
        $this->assertSame(missionTeamHomeGroup('ΕΜΑΚ', true, null, null)['key'], missionTeamHomeGroup(null, true, 'Εμακ', null)['key']);
    }

    // ── the split, carried out ─────────────────────────────────────────────────────────────────────────────────

    public function testTheSplitMakesTheTeamsAndSwitchesNobodysGpsOn(): void
    {
        $ours = $this->approveMany(5, 'Ours');
        $partner = $this->approveMany(2, 'Partner', true);
        $this->assertSame([], $this->gpsIds(), 'approved on a mission is not GPS on: it is off until somebody switches it on');

        $result = autoCreateMissionTeams($this->mission, $this->adminId, 3, false);

        $this->assertTrue($result['ok']);
        $this->assertSame(7, $result['people']);
        $this->assertCount(2, $result['teams']);
        $teams = array_values($this->teams());
        $this->assertSame([3, 4], array_map('count', $teams), 'the one left over is on the last team');
        $all = array_merge(...$teams);
        sort($all);
        $expected = array_merge($ours, $partner);
        sort($expected);
        $this->assertSame($expected, $all);
        // The two of the partner team are on the same team.
        $this->assertSame([], array_diff($partner, $teams[1]));
        $this->assertSame([], $this->gpsIds(), 'a team made by the split has no GPS');
        foreach (dbFetchAll("SELECT id, leader_id, codename, team_number, color, transport FROM mission_teams WHERE mission_id = ?", [$this->missionId]) as $row) {
            $this->assertContains((int) $row['leader_id'], $this->teams()[(int) $row['id']], 'the leader is a member');
            $this->assertNotNull($row['team_number']);
            $this->assertSame('foot', $row['transport']);
        }
        $this->assertSame(['Alpha', 'Bravo'], array_column(dbFetchAll("SELECT codename FROM mission_teams WHERE mission_id = ? ORDER BY id", [$this->missionId]), 'codename'));
        // Everybody was told which team they are on.
        $told = (int) dbFetchValue("SELECT COUNT(DISTINCT user_id) FROM notifications WHERE user_id IN (" . implode(',', $all) . ")");
        $this->assertSame(7, $told);
    }

    public function testTheSplitShownIsTheSplitMade(): void
    {
        $this->approveMany(4, 'Ours');
        $this->approveMany(3, 'Partner', true);
        $preview = missionTeamsAutoPreview($this->missionId, 3, false);
        autoCreateMissionTeams($this->mission, $this->adminId, 3, false);
        $made = array_values($this->teams());
        $shown = array_map(function ($team) {
            sort($team['member_ids']);
            return $team['member_ids'];
        }, $preview);
        $this->assertSame($shown, $made);
        $leaders = array_map('intval', array_column(dbFetchAll("SELECT leader_id FROM mission_teams WHERE mission_id = ? ORDER BY id", [$this->missionId]), 'leader_id'));
        $this->assertSame(array_column($preview, 'leader_id'), $leaders);
    }

    public function testASecondSplitTakesOnlyThePeopleOnNoTeamUnlessEverybodyIsSplitAgain(): void
    {
        $first = $this->approveMany(6, 'First');
        autoCreateMissionTeams($this->mission, $this->adminId, 3, false);
        $before = $this->teams();
        $this->assertCount(2, $before);
        $this->assertSame(['ok' => false, 'error' => t('teamsp.auto_nobody')], autoCreateMissionTeams($this->mission, $this->adminId, 3, false));

        $late = $this->approveMany(2, 'Late');
        $result = autoCreateMissionTeams($this->mission, $this->adminId, 3, false);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['people']);
        $after = $this->teams();
        $this->assertCount(3, $after);
        $this->assertSame($before, array_intersect_key($after, $before), 'the teams that were there are as they were');
        $this->assertSame($late, array_values(array_diff_key($after, $before))[0]);
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], array_column(dbFetchAll("SELECT codename FROM mission_teams WHERE mission_id = ? ORDER BY id", [$this->missionId]), 'codename'));

        // Somebody's GPS is on; splitting everybody again must not touch it.
        setActionRoomParticipation($this->missionId, $first[0], true, $this->adminId);
        $again = autoCreateMissionTeams($this->mission, $this->adminId, 4, true);
        $this->assertTrue($again['ok']);
        $this->assertSame(8, $again['people']);
        $redone = $this->teams();
        $this->assertSame([4, 4], array_values(array_map('count', $redone)));
        $this->assertSame([], array_intersect_key($redone, $after), 'the old teams are gone');
        $this->assertSame([$first[0]], $this->gpsIds());
    }

    public function testTheSplitRefusesANumberThatIsNoTeamSize(): void
    {
        $this->approveMany(3, 'Ours');
        foreach ([0, -2, MISSION_TEAM_AUTO_MAX_SIZE + 1] as $size) {
            $result = autoCreateMissionTeams($this->mission, $this->adminId, $size, false);
            $this->assertFalse($result['ok']);
            $this->assertSame(t('teamsp.auto_size_invalid', ['max' => MISSION_TEAM_AUTO_MAX_SIZE]), $result['error']);
        }
        $this->assertSame([], $this->teams());
    }

    // ── a team by hand ─────────────────────────────────────────────────────────────────────────────────────────

    public function testATeamMadeOnThePageLeavesGpsAloneAndTheWindowsColumnSetsIt(): void
    {
        [$a, $b, $c, $d] = $this->approveMany(4, 'Ours');
        setActionRoomParticipation($this->missionId, $b, true, $this->adminId);

        $page = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$a, $b], 'leader_id' => $a, 'name' => '', 'transport' => 'car']);
        $this->assertTrue($page['ok']);
        $this->assertSame([$b], $this->gpsIds(), 'no gps_ids: nobody is switched on and nobody off');
        $this->assertSame('car', dbFetchValue("SELECT transport FROM mission_teams WHERE id = ?", [$page['team_id']]));

        // The Action Room's window hands over its GPS column: the members ticked are on, the other members off.
        $window = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$c, $d], 'leader_id' => $c, 'name' => 'Κόκκινη', 'transport' => 'foot', 'gps_ids' => [$c]]);
        $this->assertTrue($window['ok']);
        $this->assertSame('Κόκκινη', $window['label'], 'a typed name carries no number');
        $expected = [$b, $c];
        sort($expected);
        $this->assertSame($expected, $this->gpsIds());
    }

    public function testATeamIsRefusedWithTheWindowsOwnWords(): void
    {
        [$a, $b] = $this->approveMany(2, 'Ours');
        $stranger = $this->makeUser('Not approved');
        $try = fn(array $input) => createMissionTeam($this->mission, $this->adminId, $input + ['name' => '', 'transport' => 'foot']);

        $this->assertSame(t('team.create.select_member_warning'), $try(['member_ids' => [], 'leader_id' => $a])['error']);
        $this->assertSame(t('team.create.select_member_warning'), $try(['member_ids' => [$stranger], 'leader_id' => $stranger])['error'], 'somebody not approved is nobody');
        $this->assertSame(t('team.leader_must_be_member'), $try(['member_ids' => [$a], 'leader_id' => $b])['error']);
        $this->assertSame('warning', $try(['member_ids' => [$a], 'leader_id' => $b])['level']);

        $this->assertTrue($try(['member_ids' => [$a], 'leader_id' => $a, 'name' => 'Βουνό'])['ok']);
        $clash = $try(['member_ids' => [$b], 'leader_id' => $b, 'name' => 'βουνό']);
        $this->assertSame(t('team.rename.duplicate', ['name' => 'Βουνό']), $clash['error'], 'two teams never read the same');
        // The only member of a team cannot be taken into a new one.
        $taken = $try(['member_ids' => [$a, $b], 'leader_id' => $b]);
        $this->assertSame(t('team.move.leader_blocked', ['teams' => 'Βουνό']), $taken['error']);
        $this->assertCount(1, $this->teams());
    }

    public function testTheNextTeamTakesTheFirstNameAndColourThatAreFree(): void
    {
        [$a, $b, $c] = $this->approveMany(3, 'Ours');
        $make = fn(int $id) => createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$id], 'leader_id' => $id, 'name' => '', 'transport' => 'foot']);
        $alpha = $make($a);
        $make($b);
        deleteMissionTeam($this->mission, $alpha['team_id']);
        $next = missionTeamNextIdentity($this->missionId);
        $this->assertSame('Alpha', $next['codename'], 'not a second Bravo: the name that was freed');
        $this->assertSame(MISSION_TEAM_COLORS[0], $next['color']);
        $this->assertFalse($next['failed']);
        $this->assertGreaterThanOrEqual(10, $next['team_number']);
        $this->assertLessThanOrEqual(99, $next['team_number']);
        $typed = missionTeamNextIdentity($this->missionId, '  Μια πολύ μεγάλη ονομασία ομάδας  ');
        $this->assertNull($typed['team_number']);
        $this->assertSame('Μια πολύ μεγάλη ονομ', $typed['codename'], 'twenty characters at most, as the column holds');
        $this->assertTrue($typed['custom']);
    }

    // ── changing a team ────────────────────────────────────────────────────────────────────────────────────────

    public function testMovingAPersonKeepsThemOnOneTeamAndKeepsTheirGps(): void
    {
        [$a, $b, $c, $d] = $this->approveMany(4, 'Ours');
        $one = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$a, $b], 'leader_id' => $a, 'name' => 'Ένα', 'transport' => 'foot'])['team_id'];
        $two = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$c], 'leader_id' => $c, 'name' => 'Δύο', 'transport' => 'foot'])['team_id'];
        setActionRoomParticipation($this->missionId, $b, true, $this->adminId);
        dbExecute("DELETE FROM notifications WHERE user_id = ?", [$b]);

        $this->assertNull(moveMissionTeamMember($this->mission, $b, $two, $this->adminId));
        $this->assertSame([$one => [$a], $two => [$b, $c]], array_map(function ($ids) { sort($ids); return $ids; }, $this->teams()));
        $this->assertSame([$b], $this->gpsIds(), 'a move does not switch GPS off');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$b]), 'the person moved is told');
        $this->assertNull(moveMissionTeamMember($this->mission, $b, $two, $this->adminId), 'already there: nothing to do');

        // Somebody on no team is put on one the same way, and taken off a team by a move to none.
        $this->assertNull(moveMissionTeamMember($this->mission, $d, $one, $this->adminId));
        $this->assertNull(moveMissionTeamMember($this->mission, $d, null, $this->adminId));
        $this->assertNull(getUserTeamIdForMission($this->missionId, $d));
        $this->assertNull(moveMissionTeamMember($this->mission, $d, null, $this->adminId));
    }

    public function testALeaderAndAnOnlyMemberCannotBeMovedAway(): void
    {
        [$a, $b, $c] = $this->approveMany(3, 'Ours');
        $one = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$a, $b], 'leader_id' => $a, 'name' => 'Ένα', 'transport' => 'foot'])['team_id'];
        $two = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$c], 'leader_id' => $c, 'name' => 'Δύο', 'transport' => 'foot'])['team_id'];
        $before = $this->teams();

        $this->assertSame(t('team.move.leader_blocked', ['teams' => 'Ένα']), moveMissionTeamMember($this->mission, $a, $two, $this->adminId));
        $this->assertSame(t('team.move.leader_blocked', ['teams' => 'Ένα']), moveMissionTeamMember($this->mission, $a, null, $this->adminId));
        $this->assertSame(t('team.move.leader_blocked', ['teams' => 'Δύο']), moveMissionTeamMember($this->mission, $c, $one, $this->adminId));
        $this->assertSame(t('common.team_not_found'), moveMissionTeamMember($this->mission, $b, 999999999, $this->adminId));
        $this->assertSame(t('gps_participant.recipient_not_found'), moveMissionTeamMember($this->mission, $this->makeUser('Stranger'), $one, $this->adminId));
        $this->assertSame($before, $this->teams());

        // Once the team has another leader, the old one can go.
        $this->assertSame(t('team.leader_must_be_member'), setMissionTeamLeader($this->mission, $one, $c));
        $this->assertNull(setMissionTeamLeader($this->mission, $one, $b));
        $this->assertNull(moveMissionTeamMember($this->mission, $a, $two, $this->adminId));
        $this->assertSame($b, (int) dbFetchValue("SELECT leader_id FROM mission_teams WHERE id = ?", [$one]));
    }

    public function testRenamingATeamAndHowItMoves(): void
    {
        [$a, $b] = $this->approveMany(2, 'Ours');
        autoCreateMissionTeams($this->mission, $this->adminId, 1, false);
        [$one, $two] = array_keys($this->teams());

        $this->assertNull(renameMissionTeam($this->mission, $one, '  Λύκοι  '));
        $row = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$one]);
        $this->assertSame('Λύκοι', $row['codename']);
        $this->assertNull($row['team_number'], 'a typed name carries no number');
        $this->assertSame(t('team.rename.duplicate', ['name' => 'Λύκοι']), renameMissionTeam($this->mission, $two, 'λύκοι'));
        $this->assertNull(renameMissionTeam($this->mission, $two, ''), 'a blank name changes nothing');
        $this->assertSame('Bravo', dbFetchValue("SELECT codename FROM mission_teams WHERE id = ?", [$two]));
        $this->assertSame(t('common.team_not_found'), renameMissionTeam($this->mission, 999999999, 'Χ'));

        $this->assertNull(setMissionTeamTransport($this->mission, $one, 'motorbike'));
        $this->assertSame('motorbike', dbFetchValue("SELECT transport FROM mission_teams WHERE id = ?", [$one]));
        $this->assertSame(t('common.invalid_request'), setMissionTeamTransport($this->mission, $one, 'helicopter'));
        $this->assertSame('motorbike', dbFetchValue("SELECT transport FROM mission_teams WHERE id = ?", [$one]));
    }

    public function testDeletingATeamLeavesItsPeopleApprovedWithTheirGps(): void
    {
        [$a, $b] = $this->approveMany(2, 'Ours');
        $team = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$a, $b], 'leader_id' => $a, 'name' => 'Ένα', 'transport' => 'foot', 'gps_ids' => [$a]]);
        dbExecute("DELETE FROM notifications WHERE user_id IN (?, ?)", [$a, $b]);

        $this->assertSame('Ένα', deleteMissionTeam($this->mission, $team['team_id']));
        $this->assertSame([], $this->teams());
        $this->assertSame([$a], $this->gpsIds());
        $this->assertCount(2, missionTeamRoster($this->missionId));
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id IN (?, ?)", [$a, $b]));
        $this->assertNull(deleteMissionTeam($this->mission, $team['team_id']), 'no such team any more');
    }

    // ── GPS ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testGpsIsSwitchedForThePeopleNamedAndOnlyForPeopleOfTheMission(): void
    {
        [$a, $b, $c] = $this->approveMany(3, 'Ours');
        $stranger = $this->makeUser('Stranger');

        $this->assertSame(2, setMissionTeamsGps($this->mission, [$a, $b, $b, $stranger], true, $this->adminId));
        $expected = [$a, $b];
        sort($expected);
        $this->assertSame($expected, $this->gpsIds());
        $this->assertSame(0, setMissionTeamsGps($this->mission, [$a], true, $this->adminId), 'already on: nothing changed');
        $this->assertSame(1, setMissionTeamsGps($this->mission, [$a, $c], false, $this->adminId));
        $this->assertSame([$b], $this->gpsIds());
    }

    public function testThePageIsHandedEverybodyAndEveryTeam(): void
    {
        $ours = $this->approveMany(3, 'Ours');
        $partner = $this->approve('Partner 01', true, true);
        $team = createMissionTeam($this->mission, $this->adminId, ['member_ids' => [$ours[0], $partner], 'leader_id' => $partner, 'name' => '', 'transport' => 'car']);
        setActionRoomParticipation($this->missionId, $partner, true, $this->adminId);

        $state = missionTeamsPageState($this->missionId);

        $this->assertCount(1, $state['teams']);
        $card = $state['teams'][0];
        $this->assertSame($team['team_id'], $card['id']);
        $this->assertSame($team['label'], $card['label']);
        $this->assertSame('car', $card['transport']);
        $this->assertSame($partner, $card['leader_id']);
        $this->assertEqualsCanonicalizing([$ours[0], $partner], $card['member_ids']);
        $this->assertCount(2, $card['colors']);
        $people = array_column($state['people'], null, 'id');
        $this->assertCount(4, $people);
        $this->assertSame($team['team_id'], $people[$partner]['team_id']);
        $this->assertTrue($people[$partner]['gps']);
        $this->assertTrue($people[$partner]['captain']);
        $this->assertStringStartsWith('Partner ', $people[$partner]['home']);
        $this->assertSame('#2a52be', $people[$partner]['home_colors'][0]);
        $this->assertNull($people[$ours[1]]['team_id']);
        $this->assertFalse($people[$ours[1]]['gps']);
        // Nothing about a person is a secret of theirs here: no e-mail, no phone.
        $this->assertSame(['id', 'name', 'home', 'home_colors', 'country', 'external', 'captain', 'team_id', 'gps'], array_keys($state['people'][0]));
    }

    // ── the page's gate ────────────────────────────────────────────────────────────────────────────────────────

    public function testThePageIsGatedBeforeAnythingIsPrinted(): void
    {
        $page = $this->source('mission-teams-view.php');
        $login = strpos($page, 'requireLogin();');
        $mission = strpos($page, 'FROM missions m');
        $gate = strpos($page, 'canManageActionRoom(');
        $open = strpos($page, '$mission[\'status\'] !== STATUS_OPEN || empty($mission[\'show_in_ops\'])');
        $header = strpos($page, "include __DIR__ . '/includes/header.php';");
        $script = strpos($page, 'mission-teams.js?v=');
        foreach (['login' => $login, 'mission' => $mission, 'gate' => $gate, 'open' => $open, 'header' => $header, 'script' => $script] as $name => $at) {
            $this->assertNotFalse($at, $name);
        }
        $this->assertTrue($login < $mission && $mission < $gate && $gate < $open && $open < $header && $header < $script, 'login, mission, command gate, open gate, then markup, then the script');
        $refuse = substr($page, $gate, 330);
        $this->assertStringContainsString("t('teamsp.no_permission')", $refuse);
        $this->assertStringContainsString("redirect('mission-view.php?id=' . \$missionId)", $refuse);
        $this->assertStringContainsString("redirect('war-room.php?id=' . \$missionId)", substr($page, $open, 400));
        // The page is a frame: no team and no person is written into it.
        $this->assertStringNotContainsString('missionTeamsPageState(', $page);
        $this->assertStringNotContainsString('missionTeamRoster(', $page);
    }

    public function testTheEndpointIsForCommandOnlyAndChecksTheTokenBeforeItWrites(): void
    {
        $endpoint = $this->source('mission-teams.php');
        $needle = "canManageActionRoom(\$mission['responsible_user_id'] ? (int) \$mission['responsible_user_id'] : null,";
        $this->assertStringContainsString($needle, $endpoint);
        $this->assertStringContainsString($needle, $this->source('mission-teams-view.php'));
        $gate = strpos($endpoint, 'canManageActionRoom(');
        $get = strpos($endpoint, 'if (!isPost()) {');
        $csrf = strpos($endpoint, "hash_equals(\$_SESSION['csrf_token'] ?? ''");
        $firstAction = strpos($endpoint, "if (\$action === '");
        $this->assertTrue($gate < $get && $get < $csrf && $csrf < $firstAction, 'the gate, then GET, then the token, then the actions');
        $this->assertStringNotContainsString('isApprovedParticipant', $endpoint);
        // Every action the script sends is one the endpoint knows, and the other way round.
        preg_match_all("/call\\('([a-z_]+)'/", $this->source('assets/js/mission-teams.js'), $sent);
        preg_match_all("/\\\$action === '([a-z_]+)'/", $endpoint, $known);
        $this->assertEqualsCanonicalizing(array_values(array_unique($known[1])), array_values(array_unique($sent[1])));
        $this->assertCount(9, array_unique($known[1]));
    }

    public function testEveryKeyTheScriptAsksForIsInThePageTableInBothLanguages(): void
    {
        $js = $this->source('assets/js/mission-teams.js');
        preg_match_all("/t\\('([a-z_]+\\.[a-z_0-9.]+)'/", $js, $m);
        $keys = array_values(array_filter(array_unique($m[1]), fn($key) => !str_ends_with($key, '.')));
        $this->assertGreaterThan(50, count($keys), 'the script asks for a lot of words: the regex found them');
        // The one key built from pieces is the transport's, whose three endings are spelled out in the script.
        $this->assertSame(1, preg_match_all("/t\\('[a-z_.]+\\.'\\s*\\+/", $js));
        $this->assertStringContainsString("const TRANSPORTS = ['foot', 'motorbike', 'car'];", $js);
        $keys = array_merge($keys, ['teams.transport.foot', 'teams.transport.motorbike', 'teams.transport.car']);
        foreach (['el', 'en'] as $lang) {
            $table = missionTeamsPageStrings($lang);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $table['strings'], "$key ($lang) is missing from the page's table");
                $this->assertNotSame('', trim((string) $table['strings'][$key]), "$key ($lang)");
            }
            foreach (array_keys($table['strings']) as $key) {
                $this->assertTrue(str_starts_with($key, 'teamsp.') || in_array($key, missionTeamsPageExtraStringKeys(), true), $key);
            }
        }
        $all = loadLangStrings('war-room');
        $this->assertSame(
            array_values(array_filter(array_keys($all['el']), fn($key) => str_starts_with($key, 'teamsp.'))),
            array_values(array_filter(array_keys($all['en']), fn($key) => str_starts_with($key, 'teamsp.'))),
            'the same keys in the same order in both languages'
        );
        // The words the PHP side says are there too.
        foreach (['hero.btn_teams', 'teams.manage_page_btn', 'teams.manage_page_title', 'teamsp.page_title', 'teamsp.no_permission',
                  'teamsp.auto_size_invalid', 'teamsp.auto_nobody', 'teamsp.auto_done'] as $key) {
            $this->assertNotSame('', trim((string) ($all['el'][$key] ?? '')), "$key (el)");
            $this->assertNotSame('', trim((string) ($all['en'][$key] ?? '')), "$key (en)");
        }
    }

    // ── the Action Room ────────────────────────────────────────────────────────────────────────────────────────

    public function testTheButtonIsInTheCommandBlockOfTheHeaderAndOnTheTeamsCard(): void
    {
        $war = $this->source('war-room.php');
        $button = strpos($war, 'id="teamsHeroBtn"');
        $this->assertNotFalse($button);
        $this->assertSame(1, substr_count($war, 'id="teamsHeroBtn"'));
        $blockStart = strrpos(substr($war, 0, $button), '<?php if ($canManageWarRoom && !$volunteerTabs): ?>');
        $this->assertNotFalse($blockStart);
        $this->assertStringNotContainsString('<?php endif; ?>', substr($war, $blockStart, $button - $blockStart), 'inside the command block, not after it');
        $tag = substr($war, strrpos(substr($war, 0, $button), '<a '), 400);
        $this->assertStringContainsString('href="mission-teams-view.php?id=<?= $missionId ?>"', $tag);
        $this->assertStringContainsString('target="wrTeamsPage<?= $missionId ?>"', $tag);
        $this->assertStringContainsString("t('hero.btn_teams')", $tag);

        $card = strpos($war, 'id="teamsPageBtn"');
        $this->assertNotFalse($card);
        $guard = strrpos(substr($war, 0, $card), '<?php if ($canManageWarRoom): ?>');
        $this->assertLessThan(700, $card - $guard, 'the card link is for command only');
    }

    public function testTheActionRoomsOwnWindowsGoThroughTheSameFunctions(): void
    {
        $war = $this->source('war-room.php');
        $create = strpos($war, "post('action') === 'create_team'");
        $update = strpos($war, "post('action') === 'update_team'");
        $delete = strpos($war, "post('action') === 'delete_team'");
        $this->assertTrue($create !== false && $update !== false && $delete !== false);
        $this->assertStringContainsString('createMissionTeam($mission,', substr($war, $create, $update - $create));
        $this->assertStringNotContainsString('INSERT INTO mission_teams', substr($war, $create, $update - $create));
        $this->assertStringContainsString('deleteMissionTeam($mission,', substr($war, $delete, 900));
        // The two things that moved out of the page live in one place, and the real bootstrap loads it (the tests'
        // own bootstrap would hide a missing require).
        $this->assertStringNotContainsString('function notifyMissionTeamMembers', $war);
        $this->assertStringNotContainsString("define('MISSION_TEAM_CODENAMES'", $war);
        $this->assertStringContainsString("require_once __DIR__ . '/includes/functions-mission-teams.php';", $this->source('bootstrap.php'));
        $this->assertTrue(function_exists('notifyMissionTeamMembers') && defined('MISSION_TEAM_CODENAMES'));
    }
}
