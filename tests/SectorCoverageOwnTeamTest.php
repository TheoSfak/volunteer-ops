<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * canViewOwnSectorCoverage() (includes/functions-warroom.php) — the narrower
 * gate the AR ground-paint layer uses (war-room-ar.js, mission-sector-coverage.php's
 * ?sector_id= path), beside the existing admin-only Verified Coverage gate.
 *
 * "Theirs" is mission_search_sectors.team_id, the same assignment the
 * dispatch/order system already keys off — nothing new is decided here, this
 * just reads that one column.
 */
final class SectorCoverageOwnTeamTest extends TestCase
{
    private int $missionId;
    private int $areaId;
    private int $adminId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Sector Coverage Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Sector Coverage Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Περιοχή', '[]', $this->adminId]
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
            [$name, 'sector-cov-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private static int $nextTeamNumber = 1;

    private function makeTeam(string $codename): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $codename, self::$nextTeamNumber++, '#000000', $this->adminId]
        );
    }

    private function addMember(int $teamId, int $userId): void
    {
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $userId]);
    }

    private function makeSector(?int $teamId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, created_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $this->areaId, $teamId, 'Τομέας', '[[35.2,24.9],[35.2001,24.9],[35.2001,24.9001]]', $this->adminId]
        );
    }

    public function testAMemberOfTheAssignedTeamMaySeeTheirOwnSector(): void
    {
        $teamId = $this->makeTeam('Alpha');
        $userId = $this->makeUser('Field Volunteer');
        $this->addMember($teamId, $userId);
        $sectorId = $this->makeSector($teamId);

        $this->assertTrue(canViewOwnSectorCoverage($this->missionId, $sectorId, $userId));
    }

    public function testAMemberOfADifferentTeamMayNot(): void
    {
        $ownTeamId = $this->makeTeam('Alpha');
        $otherTeamId = $this->makeTeam('Bravo');
        $userId = $this->makeUser('Wrong Team Volunteer');
        $this->addMember($otherTeamId, $userId);
        $sectorId = $this->makeSector($ownTeamId);

        $this->assertFalse(canViewOwnSectorCoverage($this->missionId, $sectorId, $userId));
    }

    public function testSomeoneOnNoTeamAtAllMayNot(): void
    {
        $teamId = $this->makeTeam('Alpha');
        $userId = $this->makeUser('Unassigned Volunteer');
        $sectorId = $this->makeSector($teamId);

        $this->assertFalse(canViewOwnSectorCoverage($this->missionId, $sectorId, $userId));
    }

    public function testASectorWithNoTeamAssignedYetIsNobodysToView(): void
    {
        $teamId = $this->makeTeam('Alpha');
        $userId = $this->makeUser('Field Volunteer');
        $this->addMember($teamId, $userId);
        $sectorId = $this->makeSector(null);

        $this->assertFalse(canViewOwnSectorCoverage($this->missionId, $sectorId, $userId));
    }

    public function testASectorIdFromAnotherMissionDoesNotMatch(): void
    {
        $teamId = $this->makeTeam('Alpha');
        $userId = $this->makeUser('Field Volunteer');
        $this->addMember($teamId, $userId);
        $sectorId = $this->makeSector($teamId);

        $otherMissionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $otherMissionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Other Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $otherMissionTypeId, STATUS_OPEN]
        );

        $this->assertFalse(canViewOwnSectorCoverage($otherMissionId, $sectorId, $userId));
    }
}
