<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Renaming a team and sending one person straight to another team (v3.436.0).
 *
 * Before it, a name was fixed at creation and a person could only be moved by
 * unticking them in one team and ticking them in the other team's own edit -
 * two saves, or a lock for a leader / a team's only member.
 *
 * Since v3.464.0 both are done on the «Ομάδες» page (tests/MissionTeamsPageTest.php)
 * and the Action Room's edit window is gone; what is kept here are the two rules
 * the page stands on: what a new name does (teamRenameDecision()) and how a
 * person changes team (applyTeamMemberMoves()).
 *
 * The first block needs no database. The database block runs inside a
 * transaction that is always rolled back.
 */
final class TeamEditRenameMoveTest extends TestCase
{
    private int $missionId = 0;
    private int $adminId = 0;
    private int $teamA = 0;
    private int $teamB = 0;
    private int $anna = 0;
    private int $bob = 0;
    private int $cleo = 0;

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($this->missionId) {
            forgetActionRoomParticipantIds($this->missionId);
        }
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'teamedit-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function seedTwoTeams(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Edit Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Team Edit Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->anna = $this->makeUser('Anna A.');
        $this->bob = $this->makeUser('Bob B.');
        $this->cleo = $this->makeUser('Cleo C.');
        $this->teamA = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, leader_id, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'Alpha', 41, $this->anna, $this->adminId]
        );
        $this->teamB = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, leader_id, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'Bravo', 42, $this->cleo, $this->adminId]
        );
        foreach ([[$this->teamA, $this->anna], [$this->teamA, $this->bob], [$this->teamB, $this->cleo]] as [$team, $user]) {
            dbInsert(
                "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
                [$team, $this->missionId, $user]
            );
        }
    }

    private function teamOf(int $userId): ?int
    {
        $id = dbFetchValue(
            "SELECT team_id FROM mission_team_members WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $userId]
        );
        return $id === null || $id === false ? null : (int) $id;
    }

    // ── What happens to the name ────────────────────────────────────────────

    public function testABlankOrUnchangedNameLeavesTheTeamAlone(): void
    {
        foreach ([null, '', '   ', 'Bravo', '  Bravo  '] as $submitted) {
            $d = teamRenameDecision($submitted, 'Bravo', []);
            $this->assertFalse($d['renamed'], var_export($submitted, true));
            $this->assertSame('Bravo', $d['codename']);
            $this->assertNull($d['clash']);
        }
    }

    public function testANewNameIsUsedAsTyped(): void
    {
        $d = teamRenameDecision('  Ομάδα Νότου  ', 'Bravo', []);
        $this->assertTrue($d['renamed']);
        $this->assertSame('Ομάδα Νότου', $d['codename']);
    }

    public function testANameIsCutAtTwentyCharactersLikeOnCreate(): void
    {
        $d = teamRenameDecision(str_repeat('Ω', 30), 'Bravo', []);
        $this->assertTrue($d['renamed']);
        $this->assertSame(20, mb_strlen($d['codename']));
    }

    public function testANameAnotherTeamAlreadyAnswersToIsRefused(): void
    {
        $others = [
            ['codename' => 'Alpha', 'team_number' => 41],
            ['codename' => 'Ομάδα Νότου', 'team_number' => null],
        ];
        $d = teamRenameDecision('ομάδα νότου', 'Bravo', $others);
        $this->assertFalse($d['renamed']);
        $this->assertSame('Ομάδα Νότου', $d['clash'], 'case does not make two teams different');
        $this->assertSame('Bravo', $d['codename']);

        // "Alpha" alone is not "Alpha 41": the other team's number is part of what it is called.
        $this->assertTrue(teamRenameDecision('Alpha', 'Bravo', $others)['renamed']);
        $this->assertSame('Alpha 41', teamRenameDecision('alpha 41', 'Bravo', $others)['clash']);
    }

    public function testANonTextFieldIsIgnored(): void
    {
        $this->assertFalse(teamRenameDecision(['x'], 'Bravo', [])['renamed']);
    }

    // ── Moving a person across, in the database ─────────────────────────────

    public function testAPersonIsMovedStraightToTheOtherTeamAndIsOnOnlyOne(): void
    {
        $this->seedTwoTeams();
        $this->assertSame($this->teamA, $this->teamOf($this->bob));

        applyTeamMemberMoves($this->missionId, [$this->bob => $this->teamB]);

        $this->assertSame($this->teamB, $this->teamOf($this->bob));
        $this->assertSame(
            1,
            (int) dbFetchValue(
                "SELECT COUNT(*) FROM mission_team_members WHERE mission_id = ? AND user_id = ?",
                [$this->missionId, $this->bob]
            ),
            'never on two teams (uniq_mission_user) and never on none'
        );
        $this->assertSame($this->teamA, $this->teamOf($this->anna), 'the others stay where they were');
        $this->assertSame($this->teamB, $this->teamOf($this->cleo));
    }

    public function testSeveralPeopleCanGoToDifferentTeamsInOneCall(): void
    {
        $this->seedTwoTeams();
        $teamC = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, leader_id, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'Charlie', 43, $this->cleo, $this->adminId]
        );
        applyTeamMemberMoves($this->missionId, [$this->bob => $this->teamB, $this->anna => $teamC]);
        $this->assertSame($this->teamB, $this->teamOf($this->bob));
        $this->assertSame($teamC, $this->teamOf($this->anna));
    }

    public function testTheTeamsListShowsTheMovedPersonOnTheNewTeamOnly(): void
    {
        $this->seedTwoTeams();
        applyTeamMemberMoves($this->missionId, [$this->bob => $this->teamB]);
        $teams = loadMissionTeamsForMission($this->missionId);
        $this->assertSame([$this->anna], array_column($teams[$this->teamA]['members'], 'user_id'));
        $this->assertEqualsCanonicalizing([$this->bob, $this->cleo], array_column($teams[$this->teamB]['members'], 'user_id'));
    }

    public function testARenameIsSeenEverywhereTheTeamLabelIsBuilt(): void
    {
        $this->seedTwoTeams();
        // The same statement update_team runs for a rename: the number goes.
        dbExecute("UPDATE mission_teams SET codename = ?, team_number = NULL WHERE id = ?", ['Ομάδα Νότου', $this->teamA]);
        $team = loadMissionTeamsForMission($this->missionId)[$this->teamA];
        $this->assertSame('Ομάδα Νότου', teamLabel($team['codename'], $team['team_number']));
    }

    // ── The rename and the move are the page's now ──────────────────────────

    public function testTheRenameAndTheMoveAreDoneByThePagesFunctions(): void
    {
        $functions = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../includes/functions-mission-teams.php'));
        // A rename asks the same rule and, when the name is new, drops the number.
        $this->assertStringContainsString('teamRenameDecision($name, (string) $team[\'codename\'], $others)', $functions);
        $this->assertMatchesRegularExpression('/SET codename = \?, team_number = NULL, updated_at = NOW\(\)/', $functions);
        // A move goes through the one function that never leaves a person on two teams.
        $this->assertStringContainsString('applyTeamMemberMoves($missionId, [$userId => $toTeamId]);', $functions);
        // The Action Room page no longer saves a team at all.
        $page = (string) WarRoomSource::full();
        $this->assertStringNotContainsString('editTeamModal', $page);
        $this->assertStringNotContainsString("post('action') === 'update_team'", $page);
    }

    public function testEveryNewTextIsTranslatedInBothLanguages(): void
    {
        $lang = require __DIR__ . '/../includes/lang/war-room.php';
        foreach ([
            'team.rename.duplicate', 'teamsp.rename_btn', 'teamsp.rename_placeholder', 'teamsp.move_placeholder',
            'teamsp.move_title', 'teamsp.move_locked_leader', 'teamsp.move_locked_only',
        ] as $key) {
            $this->assertNotEmpty($lang['el'][$key] ?? '', "el: $key");
            $this->assertNotEmpty($lang['en'][$key] ?? '', "en: $key");
            $this->assertNotSame($lang['el'][$key], $lang['en'][$key], "$key is the same in both languages");
        }
        $this->assertStringContainsString('{name}', $lang['el']['team.rename.duplicate']);
    }
}
