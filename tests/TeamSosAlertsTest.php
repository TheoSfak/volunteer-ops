<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * loadOpenSosAlertsForTeammates() (includes/functions-warroom.php) — the
 * "buddy system" feed the AR screen's arrow (war-room-ar.js, v3.468.0) points
 * toward. Narrower than loadOpenSosAlertsForMission(): one team, never the
 * caller's own alert, never a resolved one.
 */
final class TeamSosAlertsTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private static int $nextTeamNumber = 1;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('SOS Test Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['SOS Test Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'sos-test-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeTeam(): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'Team', self::$nextTeamNumber++, '#000000', $this->adminId]
        );
    }

    private function raiseSos(int $userId, ?int $teamId, ?float $lat = 35.2, ?float $lng = 24.9, bool $resolved = false): int
    {
        $id = (int) dbInsert(
            "INSERT INTO mission_sos_alerts (mission_id, user_id, team_id, lat, lng, accuracy_m) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $userId, $teamId, $lat, $lng, 12.0]
        );
        if ($resolved) {
            dbExecute("UPDATE mission_sos_alerts SET resolved_at = NOW(), resolved_by = ? WHERE id = ?", [$this->adminId, $id]);
        }
        return $id;
    }

    public function testATeammatesOpenAlertComesBackWithItsLocation(): void
    {
        $teamId = $this->makeTeam();
        $caller = $this->makeUser('Caller');
        $viewer = $this->makeUser('Viewer');
        $this->raiseSos($caller, $teamId, 35.21, 24.91);

        $result = loadOpenSosAlertsForTeammates($this->missionId, $teamId, $viewer);

        $this->assertCount(1, $result);
        $this->assertSame($caller, $result[0]['user_id']);
        $this->assertSame('Caller', $result[0]['user_name']);
        $this->assertEqualsWithDelta(35.21, $result[0]['lat'], 0.0001);
        $this->assertEqualsWithDelta(24.91, $result[0]['lng'], 0.0001);
        $this->assertSame(12, $result[0]['accuracy_m']);
    }

    public function testMyOwnAlertIsNeverInMyOwnList(): void
    {
        $teamId = $this->makeTeam();
        $userId = $this->makeUser('Lone Caller');
        $this->raiseSos($userId, $teamId);

        $result = loadOpenSosAlertsForTeammates($this->missionId, $teamId, $userId);

        $this->assertSame([], $result);
    }

    public function testAnotherTeamsAlertNeverShowsUpHere(): void
    {
        $myTeamId = $this->makeTeam();
        $otherTeamId = $this->makeTeam();
        $viewer = $this->makeUser('Viewer');
        $caller = $this->makeUser('Other Team Caller');
        $this->raiseSos($caller, $otherTeamId);

        $result = loadOpenSosAlertsForTeammates($this->missionId, $myTeamId, $viewer);

        $this->assertSame([], $result);
    }

    public function testAResolvedAlertIsNotIncluded(): void
    {
        $teamId = $this->makeTeam();
        $viewer = $this->makeUser('Viewer');
        $caller = $this->makeUser('Caller');
        $this->raiseSos($caller, $teamId, 35.2, 24.9, true);

        $result = loadOpenSosAlertsForTeammates($this->missionId, $teamId, $viewer);

        $this->assertSame([], $result);
    }
}
