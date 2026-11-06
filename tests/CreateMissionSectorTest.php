<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * createMissionSector() (v3.454.0) is the body of mission-sector.php's `create`, moved into a function so that
 * something other than the form - a step of a scenario - can make the very same sector. These tests pin what the
 * form always got: the checks (and the order they run in), the rows, the audit entry, who is told, and the answer.
 * The move itself was proved against the REAL endpoint, in-process, before and after (14 requests, answer and
 * every row written byte-identical); these tests keep it that way.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class CreateMissionSectorTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private int $areaId;
    private int $shiftId;
    /** @var int[] Two volunteers on $teamId, both on the Action Room. */
    private array $members = [];
    /** A volunteer on no team, also on the Action Room. */
    private int $bystander;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Sector Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Create Sector Mission');
        $this->otherMissionId = $mk('Other Sector Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Create Sector Mission'];
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        foreach (['Άννα Α.', 'Βασίλης Β.'] as $name) {
            $id = $this->makeVolunteer($name);
            dbInsert(
                "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
                [$this->teamId, $this->missionId, $id]
            );
            $this->members[] = $id;
        }
        $this->bystander = $this->makeVolunteer('Χωρίς ομάδα');
        $this->areaId = $this->makeArea($this->missionId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'cs-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)",
            [$this->missionId, $id]
        );
        return $id;
    }

    private function makeTeam(string $codename, int $number, ?int $missionId = null): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId ?? $this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function makeArea(int $missionId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$missionId, 'Περιοχή', json_encode([[35.0, 24.8], [35.0, 25.0], [35.2, 25.0]]), $this->adminId]
        );
    }

    /** A form posting a sector, the way the sector window does. */
    private function form(array $over = []): array
    {
        return $over + [
            'label' => 'Τομέας Α',
            'area_id' => (string) $this->areaId,
            'team_id' => (string) $this->teamId,
            'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]),
        ];
    }

    private function make(array $input): array
    {
        return createMissionSector($this->mission, $this->adminId, $input);
    }

    private function rowCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_sectors WHERE mission_id = ?", [$this->missionId]);
    }

    private function notificationCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM notifications");
    }

    private function auditCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_sector'");
    }

    /** @return int[] ids that got a notification about $sectorId. */
    private function notifiedAbout(int $sectorId): array
    {
        $ids = [];
        foreach (dbFetchAll("SELECT user_id FROM notifications WHERE data LIKE ?", ['%"sectorId":' . $sectorId . '}%']) as $row) {
            $ids[] = (int) $row['user_id'];
        }
        sort($ids);
        return $ids;
    }

    public function testASectorForATeamIsBornAssignedAndTheTeamIsTold(): void
    {
        $r = $this->make($this->form());

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $row = dbFetchOne("SELECT * FROM mission_search_sectors WHERE id = ?", [$r['id']]);
        $this->assertSame($this->missionId, (int) $row['mission_id']);
        $this->assertSame($this->areaId, (int) $row['area_id']);
        $this->assertSame($this->teamId, (int) $row['team_id']);
        $this->assertSame('Τομέας Α', $row['label']);
        $this->assertSame('assigned', $row['status']);
        $this->assertSame($this->adminId, (int) $row['created_by']);
        $this->assertNotNull($row['status_updated_at']);
        $this->assertSame($this->adminId, (int) $row['status_updated_by']);
        $this->assertNull($row['acknowledged_at'], 'the team still has to press «Ελήφθη»');

        $log = dbFetchAll("SELECT from_status, to_status, team_id, user_id FROM mission_sector_status_log WHERE sector_id = ?", [$r['id']]);
        $this->assertCount(1, $log);
        $this->assertSame('not_started', $log[0]['from_status']);
        $this->assertSame('assigned', $log[0]['to_status']);
        $this->assertSame($this->teamId, (int) $log[0]['team_id']);
        $this->assertSame($this->adminId, (int) $log[0]['user_id']);

        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_sector' AND record_id = ?", [$r['id']]));
        // Its team - the two volunteers on it - and nobody else: not the sender, not a volunteer on no team.
        $this->assertSame($this->members, $this->notifiedAbout((int) $r['id']));
        $this->assertNotContains($this->bystander, $this->notifiedAbout((int) $r['id']));
        $this->assertNotContains($this->adminId, $this->notifiedAbout((int) $r['id']));
        $note = dbFetchOne("SELECT title, message, type FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$this->members[0]]);
        $this->assertSame(t('sector.assigned_notify_title', ['mission' => 'Create Sector Mission']), $note['title']);
        $this->assertSame(t('sector.assigned_notify_message', ['label' => 'Τομέας Α']), $note['message']);
    }

    public function testASectorWithNoTeamIsBornNotStartedAndTellsNobody(): void
    {
        $notes = $this->notificationCount();

        $r = $this->make($this->form(['team_id' => '']));

        $this->assertTrue($r['ok']);
        $row = dbFetchOne("SELECT * FROM mission_search_sectors WHERE id = ?", [$r['id']]);
        $this->assertNull($row['team_id']);
        $this->assertSame('not_started', $row['status']);
        $this->assertNull($row['status_updated_at']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_sector_status_log WHERE sector_id = ?", [$r['id']]));
        $this->assertSame($notes, $this->notificationCount(), 'laying out sectors before assigning teams must not push once per sector');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_sector' AND record_id = ?", [$r['id']]));
        // The same with the team left out of the form altogether.
        $form = $this->form();
        unset($form['team_id']);
        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_search_sectors WHERE id = ?", [$this->make($form)['id']]));
    }

    public function testTheLabelIsTrimmedAndCutAt255AndTheGeometryStoredAsNumbers(): void
    {
        $r = $this->make($this->form([
            'label' => '  ' . str_repeat('Ω', 300) . '  ',
            'geo' => json_encode([['35.0', '24.8'], ['35.0', '24.9'], ['35.1', '24.9'], ['35.05', '24.85']]),
        ]));

        $row = dbFetchOne("SELECT label, geo FROM mission_search_sectors WHERE id = ?", [$r['id']]);
        $this->assertSame(str_repeat('Ω', 255), $row['label']);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9], [35.05, 24.85]], json_decode($row['geo'], true));
        $this->assertSame('padded', dbFetchValue("SELECT label FROM mission_search_sectors WHERE id = ?", [$this->make($this->form(['label' => '  padded  ']))['id']]));
    }

    public function testEachMistakeIsRefusedWithItsOwnMessageAndWritesNothing(): void
    {
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);
        $foreignArea = $this->makeArea($this->otherMissionId);
        $notes = $this->notificationCount();
        $audit = $this->auditCount();

        foreach ([
            'no name' => [$this->form(['label' => '   ']), t('sector.invalid_label')],
            'no label at all' => [array_diff_key($this->form(), ['label' => 1]), t('sector.invalid_label')],
            'an area of another mission' => [$this->form(['area_id' => (string) $foreignArea]), t('sector.area_not_found')],
            'an area that is not there' => [$this->form(['area_id' => '987654321']), t('sector.area_not_found')],
            'no area' => [array_diff_key($this->form(), ['area_id' => 1]), t('sector.area_not_found')],
            'a team of another mission' => [$this->form(['team_id' => (string) $foreignTeam]), t('common.team_not_found')],
            'two points' => [$this->form(['geo' => json_encode([[35.0, 24.8], [35.0, 24.9]])]), t('dispatch.polygon_needs_3_points')],
            'not json' => [$this->form(['geo' => 'not json']), t('dispatch.polygon_needs_3_points')],
            'no shape' => [array_diff_key($this->form(), ['geo' => 1]), t('dispatch.polygon_needs_3_points')],
            'a point at 0,0' => [$this->form(['geo' => json_encode([[35.0, 24.8], [0, 0], [35.1, 24.9]])]), t('dispatch.invalid_point')],
            'a latitude of 91' => [$this->form(['geo' => json_encode([[91, 24.8], [35.0, 24.9], [35.1, 24.9]])]), t('dispatch.invalid_point')],
            'a longitude of 181' => [$this->form(['geo' => json_encode([[35.0, 181], [35.0, 24.9], [35.1, 24.9]])]), t('dispatch.invalid_point')],
            'a point that is not numbers' => [$this->form(['geo' => json_encode([[35.0, 24.8], ['a', 'b'], [35.1, 24.9]])]), t('dispatch.invalid_point')],
            'a point that is not a pair' => [$this->form(['geo' => json_encode([[35.0, 24.8], [35.0], [35.1, 24.9]])]), t('dispatch.invalid_point')],
        ] as $why => [$input, $message]) {
            $r = $this->make($input);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame($message, $r['error'], $why);
            $this->assertSame(['ok', 'error'], array_keys($r), "$why: the answer the endpoint always gave");
        }
        $this->assertSame(0, $this->rowCount());
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame($audit, $this->auditCount());
    }

    public function testTheChecksRunInTheOrderTheEndpointAlwaysRanThem(): void
    {
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);
        $bad = ['label' => '', 'area_id' => '987654321', 'team_id' => (string) $foreignTeam, 'geo' => 'x'];

        // A form with several mistakes gets the message of the first, as it always did: name, area, team, shape.
        $this->assertSame(t('sector.invalid_label'), $this->make($bad)['error']);
        $this->assertSame(t('sector.area_not_found'), $this->make(['label' => 'Α'] + $bad)['error']);
        $this->assertSame(t('common.team_not_found'), $this->make(['label' => 'Α', 'area_id' => (string) $this->areaId] + $bad)['error']);
        $this->assertSame(t('dispatch.polygon_needs_3_points'), $this->make(['label' => 'Α', 'area_id' => (string) $this->areaId, 'team_id' => (string) $this->teamId] + $bad)['error']);
    }

    public function testPrepareChecksAndWritesNothing(): void
    {
        $notes = $this->notificationCount();
        $audit = $this->auditCount();

        $ok = prepareMissionSector($this->mission, $this->form(['label' => '  Τομέας Β  ']));

        $this->assertTrue($ok['ok']);
        $this->assertSame('Τομέας Β', $ok['label']);
        $this->assertSame($this->areaId, $ok['area_id']);
        $this->assertSame('Περιοχή', $ok['area_label']);
        $this->assertSame($this->teamId, $ok['team_id']);
        $this->assertSame('ΑΛΦΑ 1', $ok['team_label']);
        $this->assertSame([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $ok['geo']);
        $this->assertFalse(prepareMissionSector($this->mission, $this->form(['label' => '']))['ok']);

        $this->assertSame(0, $this->rowCount());
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame($audit, $this->auditCount());
    }

    public function testTheEndpointOnlyHandsOverWhatTheFormPostedAndAnswersWhatTheFunctionAnswers(): void
    {
        $src = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../mission-sector.php'));
        $start = strpos($src, "if (\$action === 'create') {");
        $this->assertNotFalse($start);
        $block = substr($src, $start, strpos($src, "\n}\n", $start) - $start);
        $this->assertStringContainsString('createMissionSector($mission, (int) $userId, [', $block);
        foreach (['label', 'area_id', 'team_id', 'geo'] as $field) {
            $this->assertStringContainsString("'$field'", $block);
            $this->assertStringContainsString("post('$field')", $block);
        }
        // A refusal is answered as it is; a success adds the pair of lists every sector action answers with.
        $this->assertStringContainsString('echo json_encode($result);', $block);
        $this->assertStringContainsString('echo json_encode($result + loadSectorPollPayload(', $block);
        $this->assertStringNotContainsString('INSERT INTO', $block);
        $this->assertStringNotContainsString('notifySectorAssigned', $block);
        // The login, the CSRF token and the command gate come before any action, as they did.
        $this->assertLessThan($start, strpos($src, 'hash_equals($_SESSION[\'csrf_token\']'));
        $this->assertLessThan($start, strpos($src, "if (!\$canManageWarRoom) {\n    echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);"));
        // assign() still tells the team, through the function that now lives with createMissionSector().
        $this->assertStringContainsString('notifySectorAssigned($missionId, $mission[\'title\'], $newTeamId', $src);
        $this->assertTrue(function_exists('notifySectorAssigned'));
    }
}
