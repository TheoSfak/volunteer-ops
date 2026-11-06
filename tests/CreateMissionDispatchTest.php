<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * createMissionDispatch() (v3.451.0) is the body of mission-dispatch.php's `create`, moved into a function so
 * that something other than the form - a step of a scenario - can send the very same order. These tests pin what
 * the form always got: the checks, the row, the audit entry, who is told, and the answer.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class CreateMissionDispatchTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;
    private int $shiftId;
    /** @var int[] Two volunteers on $teamId, both on the Action Room. */
    private array $members = [];
    /** A volunteer on no team, also on the Action Room. */
    private int $bystander;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Create Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Create Dispatch Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $this->mission = ['id' => $this->missionId, 'title' => 'Create Dispatch Mission'];
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
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'cd-' . uniqid('', true) . '@example.invalid', 'x']
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

    /** A form posting a point, the way the Action Room's window does. */
    private function point(array $over = []): array
    {
        return $over + [
            'team_id' => (string) $this->teamId,
            'ring_index' => '',
            'incident_id' => '',
            'type' => 'point',
            'label' => 'Ρέμα',
            'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]),
            'travel_mode' => '',
        ];
    }

    private function send(array $input): array
    {
        return createMissionDispatch($this->mission, $this->adminId, 'Create Admin', $input);
    }

    private function rowCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ?", [$this->missionId]);
    }

    /** @return int[] ids that got a notification about $dispatchId. */
    private function notifiedAbout(int $dispatchId): array
    {
        $ids = [];
        foreach (dbFetchAll("SELECT user_id, data FROM notifications WHERE data LIKE ?", ['%"dispatchId":' . $dispatchId . '}%']) as $row) {
            $ids[] = (int) $row['user_id'];
        }
        sort($ids);
        return $ids;
    }

    public function testAPointToATeamIsStoredAndTellsThatTeamOnly(): void
    {
        $result = $this->send($this->point(['travel_mode' => 'foot']));

        $this->assertTrue($result['ok']);
        $this->assertSame(['ok', 'id'], array_keys($result), 'the answer keeps the shape the Action Room reads');
        $row = dbFetchOne("SELECT * FROM mission_dispatch_points WHERE id = ?", [$result['id']]);
        $this->assertSame($this->missionId, (int) $row['mission_id']);
        $this->assertSame($this->teamId, (int) $row['team_id']);
        $this->assertSame('point', $row['type']);
        $this->assertSame('Ρέμα', $row['label']);
        $this->assertSame(['lat' => 35.1, 'lng' => 25.1], json_decode($row['geo'], true));
        $this->assertSame('foot', $row['travel_mode']);
        $this->assertNull($row['ring_index']);
        $this->assertNull($row['incident_id']);
        $this->assertSame($this->adminId, (int) $row['created_by']);

        $expected = $this->members;
        sort($expected);
        $this->assertSame($expected, $this->notifiedAbout($result['id']), 'only the targeted team hears it, not the volunteer on no team');

        $this->assertSame(1, (int) dbFetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_dispatch' AND table_name = 'mission_dispatch_points' AND record_id = ?",
            [$result['id']]
        ));
    }

    public function testAnAreaForEveryTeamTellsEverybodyAndHasNoTravelMode(): void
    {
        $area = [[35.0, 25.0], [35.0, 25.1], [35.1, 25.1]];
        $result = $this->send($this->point([
            'team_id' => '', 'type' => 'polygon', 'geo' => json_encode($area), 'travel_mode' => 'vehicle', 'label' => '',
        ]));

        $this->assertTrue($result['ok']);
        $row = dbFetchOne("SELECT * FROM mission_dispatch_points WHERE id = ?", [$result['id']]);
        $this->assertNull($row['team_id'], 'no team = every team');
        $this->assertSame('polygon', $row['type']);
        $this->assertEquals($area, json_decode($row['geo'], true), 'the corners, as numbers (JSON writes 35.0 as 35)');
        $this->assertNull($row['label'], 'an empty label is stored as NULL');
        $this->assertNull($row['travel_mode'], 'an order to every team has no one team to travel, so no mode');

        $expected = array_merge($this->members, [$this->bystander]);
        sort($expected);
        $this->assertSame($expected, $this->notifiedAbout($result['id']));
    }

    public function testTheModeIsOnlyEverFootOrVehicle(): void
    {
        $this->assertSame('vehicle', dbFetchValue("SELECT travel_mode FROM mission_dispatch_points WHERE id = ?", [$this->send($this->point(['travel_mode' => 'vehicle']))['id']]));
        $this->assertNull(dbFetchValue("SELECT travel_mode FROM mission_dispatch_points WHERE id = ?", [$this->send($this->point(['travel_mode' => 'helicopter']))['id']]));
        $this->assertNull(dbFetchValue("SELECT travel_mode FROM mission_dispatch_points WHERE id = ?", [$this->send($this->point())['id']]));
    }

    public function testARingIndexIsKeptOnlyInsideTheFourRings(): void
    {
        $this->assertSame(2, (int) dbFetchValue("SELECT ring_index FROM mission_dispatch_points WHERE id = ?", [$this->send($this->point(['ring_index' => '2']))['id']]));

        $before = $this->rowCount();
        $this->assertSame(['ok' => false, 'error' => t('common.invalid_request')], $this->send($this->point(['ring_index' => '4'])));
        $this->assertSame(['ok' => false, 'error' => t('common.invalid_request')], $this->send($this->point(['ring_index' => '-1'])));
        $this->assertSame($before, $this->rowCount());
    }

    public function testABadOrderIsRefusedWithTheSameWordsAndNothingIsWritten(): void
    {
        $before = $this->rowCount();
        $notificationsBefore = (int) dbFetchValue("SELECT COUNT(*) FROM notifications");

        $otherMission = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Other Mission', 'Χανιά', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600), (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1")]
        );
        $foreignTeam = $this->makeTeam('ΒΡΑΒΟ', 2, $otherMission);

        $cases = [
            'a team of another mission' => [$this->point(['team_id' => (string) $foreignTeam]), t('common.team_not_found')],
            'an incident that is not there' => [$this->point(['incident_id' => '999999999']), t('incident.report_not_found')],
            'a point at 0,0' => [$this->point(['geo' => json_encode(['lat' => 0, 'lng' => 0])]), t('dispatch.invalid_point')],
            'a point out of range' => [$this->point(['geo' => json_encode(['lat' => 95, 'lng' => 25])]), t('dispatch.invalid_point')],
            'a point with no coordinates' => [$this->point(['geo' => '']), t('dispatch.invalid_point')],
            'an area of two points' => [$this->point(['type' => 'polygon', 'geo' => json_encode([[35.0, 25.0], [35.1, 25.1]])]), t('dispatch.polygon_needs_3_points')],
            'an area with a bad corner' => [$this->point(['type' => 'polygon', 'geo' => json_encode([[35.0, 25.0], [35.1, 25.1], [999, 25.2]])]), t('dispatch.invalid_point')],
            'a type that does not exist' => [$this->point(['type' => 'circle']), t('dispatch.unknown_point_type')],
            'no type at all' => [['team_id' => (string) $this->teamId, 'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1])], t('dispatch.unknown_point_type')],
        ];
        foreach ($cases as $what => [$input, $error]) {
            $this->assertSame(['ok' => false, 'error' => $error], $this->send($input), $what);
        }

        $this->assertSame($before, $this->rowCount(), 'a refused order leaves no row');
        $this->assertSame($notificationsBefore, (int) dbFetchValue("SELECT COUNT(*) FROM notifications"), 'and tells no one');
    }

    public function testSomeoneWatchingWhoIsNotOnTheTeamGetsTheOutsidersLine(): void
    {
        // A system administrator who is not a recipient still hears about every order.
        $watcher = $this->makeUser('Watching Admin');
        dbExecute("UPDATE users SET role = ?, is_active = 1 WHERE id = ?", [ROLE_SYSTEM_ADMIN, $watcher]);

        $result = $this->send($this->point(['label' => 'Γκρεμός']));

        $row = dbFetchOne("SELECT message FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$watcher]);
        $this->assertNotNull($row, 'the watching administrator is told');
        $this->assertStringContainsString('Create Admin', $row['message'], 'in the words that name who sent it');
        $this->assertStringContainsString('Γκρεμός', $row['message']);
        $this->assertNotContains($watcher, $this->notifiedAbout($result['id']), 'but with no dispatchId: it is not an order for them to open');
    }

    public function testTheFunctionAnswersAndNeverReadsTheRequestOrStops(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../includes/functions-warroom.php');
        $start = strpos($source, 'function createMissionDispatch(');
        $this->assertNotFalse($start);
        $body = substr($source, $start, strpos($source, "\nfunction reopenMissionDispatch(", $start) - $start);

        $this->assertStringNotContainsString('post(', $body, 'it reads $input, so something other than the form can call it');
        $this->assertStringNotContainsString('$_POST', $body);
        $this->assertStringNotContainsString('$_SESSION', $body);
        $this->assertDoesNotMatchRegularExpression('/\bexit\b|\bdie\b|echo json_encode/', $body, 'it returns the answer; the caller decides what to do with it');
    }

    public function testTheEndpointOnlyHandsOverAndAnswers(): void
    {
        $endpoint = (string) file_get_contents(__DIR__ . '/../mission-dispatch.php');
        $this->assertStringNotContainsString('INSERT INTO mission_dispatch_points', $endpoint, 'the one place that writes the order is the function');

        $start = strpos($endpoint, "if (\$action === 'create') {");
        $block = substr($endpoint, $start, strpos($endpoint, "if (\$action === 'reopen')", $start) - $start);
        $this->assertStringContainsString('createMissionDispatch($mission, (int) $userId, (string) $user[\'name\']', $block);
        $this->assertStringContainsString('echo json_encode($result);', $block);

        // And it is still past the command-only gate.
        $this->assertLessThan($start, strpos($endpoint, 'if (!$canManageWarRoom) {'));
    }
}
