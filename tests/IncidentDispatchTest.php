<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An incident used to be a record and nothing more: command could declare one
 * from the live map, but a team sent to it got an ordinary dispatch that knew
 * nothing about the casualty, and the incident card never learnt that anyone
 * was on the way. Since v3.344.0 a dispatch can carry incident_id.
 *
 * What these tests pin: the team sent sees the casualty's name and phone in
 * full (and nobody else outside command does), the incident card follows the
 * team's progress, sending a team marks the incident seen, and closing the
 * incident tells exactly the people still on their way — once.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class IncidentDispatchTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $teammate;
    private int $stranger;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = $this->makeUser('Incident Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Incident Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->member = $this->makeVolunteer('Άννα Α.', $this->teamId);
        $this->teammate = $this->makeVolunteer('Γιώργος Γ.', $this->teamId);
        $this->stranger = $this->makeVolunteer('Δήμητρα Δ.', $this->otherTeamId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'inc-' . uniqid('', true) . '@example.invalid', 'x']
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
        dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
        return $id;
    }

    private function mission(): array
    {
        return dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
    }

    private function user(int $id): array
    {
        return dbFetchOne("SELECT id, name FROM users WHERE id = ?", [$id]);
    }

    /** Command's «Νέο συμβάν εδώ»: a casualty called in by phone. */
    private function reportIncident(bool $byCommand = true, int $reporterId = 0): int
    {
        $result = reportMissionIncident($this->mission(), $this->user($reporterId ?: $this->adminId), [
            'incident_type' => 'trauma', 'severity' => 'high',
            'patient_name' => 'Μαρία Κωνσταντίνου', 'phone' => '6912345678',
            'estimated_age' => '45', 'gender' => 'female', 'notes' => 'Πτώση από βράχο',
            'lat' => '35.2', 'lng' => '24.9',
        ], $byCommand);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        return $result['id'];
    }

    private function sendTeam(?int $teamId, int $incidentId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, incident_id, created_by) VALUES (?, ?, 'point', ?, ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => 35.2, 'lng' => 24.9]), '🚑 Περιστατικό', $incidentId, $this->adminId]
        );
    }

    private function dispatchSeenBy(int $userId, int $dispatchId): array
    {
        foreach (loadMissionDispatchesForUser($this->missionId, $userId, false, true) as $dispatch) {
            if ($dispatch['id'] === $dispatchId) {
                return $dispatch;
            }
        }
        $this->fail("Dispatch $dispatchId is not visible to user $userId");
    }

    private function incidentSeenBy(int $userId, int $incidentId, bool $command = false): array
    {
        foreach (loadUnresolvedIncidentsForMission($this->missionId, $command, $userId) as $incident) {
            if ($incident['id'] === $incidentId) {
                return $incident;
            }
        }
        $this->fail("Incident $incidentId is not listed for user $userId");
    }

    public function testCommandsOwnReportIsStoredSeenAndAFieldReportIsNot(): void
    {
        $byCommand = $this->reportIncident(true);
        $fromField = $this->reportIncident(false, $this->member);

        $this->assertNotNull(dbFetchValue("SELECT acknowledged_at FROM mission_incidents WHERE id = ?", [$byCommand]),
            'Command wrote it: «Είδα» on their own report would tell nobody anything.');
        $this->assertSame($this->adminId, (int) dbFetchValue("SELECT acknowledged_by FROM mission_incidents WHERE id = ?", [$byCommand]));
        $this->assertNull(dbFetchValue("SELECT acknowledged_at FROM mission_incidents WHERE id = ?", [$fromField]));
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_incidents WHERE id = ?", [$fromField]));
    }

    public function testAReportWithoutANameIsTurnedBackAsAWarning(): void
    {
        $result = reportMissionIncident($this->mission(), $this->user($this->adminId), [
            'incident_type' => 'trauma', 'severity' => 'high', 'lat' => '35.2', 'lng' => '24.9',
        ], true);
        $this->assertFalse($result['ok']);
        $this->assertSame('warning', $result['level']);

        $result = reportMissionIncident($this->mission(), $this->user($this->adminId), [
            'incident_type' => 'not-a-type', 'severity' => 'high', 'is_unknown_patient' => '1',
        ], true);
        $this->assertSame('error', $result['level']);
    }

    public function testTheTeamSentSeesWhoItIsGoingToAndHowToCallThem(): void
    {
        $incidentId = $this->reportIncident();
        $dispatchId = $this->sendTeam($this->teamId, $incidentId);

        $casualty = $this->dispatchSeenBy($this->teammate, $dispatchId)['incident'];
        $this->assertSame($incidentId, $casualty['id']);
        $this->assertSame('Μαρία Κωνσταντίνου', $casualty['patient_name'], 'The team is going to her: the name in full.');
        $this->assertSame('6912345678', $casualty['phone'], 'And a number they can call.');
        $this->assertSame('high', $casualty['severity']);
        $this->assertFalse($casualty['resolved']);
        $this->assertArrayNotHasKey('notes', $casualty, 'Notes stay command-only.');

        $this->assertNull($this->dispatchSeenBy($this->member, $this->sendPlainPoint())['incident'], 'An ordinary dispatch carries none.');
    }

    private function sendPlainPoint(): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamId, json_encode(['lat' => 35.3, 'lng' => 24.8]), 'Πηγή', $this->adminId]
        );
    }

    public function testTheIncidentListUnmasksThePatientOnlyForTheTeamSent(): void
    {
        $incidentId = $this->reportIncident();
        $this->assertNotSame('Μαρία Κωνσταντίνου', $this->incidentSeenBy($this->member, $incidentId)['patient_name'],
            'Before anyone is sent, a volunteer sees the masked name, as always.');

        $this->sendTeam($this->teamId, $incidentId);

        $sent = $this->incidentSeenBy($this->member, $incidentId);
        $this->assertSame('Μαρία Κωνσταντίνου', $sent['patient_name']);
        $this->assertSame('6912345678', $sent['phone']);
        $this->assertNull($sent['notes']);

        $other = $this->incidentSeenBy($this->stranger, $incidentId);
        $this->assertNotSame('Μαρία Κωνσταντίνου', $other['patient_name'], 'Another team was not sent: still masked.');
        $this->assertNotSame('6912345678', $other['phone']);
    }

    public function testADispatchToEveryTeamUnmasksItForEveryone(): void
    {
        $incidentId = $this->reportIncident();
        $this->sendTeam(null, $incidentId);
        $this->assertSame('Μαρία Κωνσταντίνου', $this->incidentSeenBy($this->stranger, $incidentId)['patient_name']);
    }

    public function testTheIncidentCardFollowsTheTeamSent(): void
    {
        $incidentId = $this->reportIncident();
        $this->assertSame([], $this->incidentSeenBy($this->adminId, $incidentId, true)['responders'], 'Nobody sent yet.');

        $dispatchId = $this->sendTeam($this->teamId, $incidentId);
        [$line] = $this->incidentSeenBy($this->adminId, $incidentId, true)['responders'];
        $this->assertSame(teamLabel('ΑΛΦΑ', 1), $line['label']);
        $this->assertNotNull($line['sent']);
        $this->assertNull($line['departed'], 'Sent, not yet moving.');

        recordDispatchProgress($dispatchId, $this->teamId, $this->member, 'arrive');
        [$line] = $this->incidentSeenBy($this->adminId, $incidentId, true)['responders'];
        $this->assertNotNull($line['departed'], 'Arriving backfills setting off.');
        $this->assertNotNull($line['arrived']);
        $this->assertNull($line['completed']);

        $this->assertSame([], $this->incidentSeenBy($this->member, $incidentId)['responders'], 'The progress lines are command\'s.');
    }

    public function testADispatchToEveryTeamHasALinePerTeamOnceTheyMove(): void
    {
        $incidentId = $this->reportIncident();
        $dispatchId = $this->sendTeam(null, $incidentId);
        $lines = loadIncidentResponders($this->missionId, [$incidentId])[$incidentId];
        $this->assertCount(1, $lines);
        $this->assertSame(t('common.all_teams'), $lines[0]['label']);

        recordDispatchProgress($dispatchId, $this->otherTeamId, $this->stranger, 'depart');
        $lines = loadIncidentResponders($this->missionId, [$incidentId])[$incidentId];
        $this->assertSame([teamLabel('ΒΗΤΑ', 2)], array_column($lines, 'label'));
    }

    public function testSendingATeamMarksTheIncidentSeenAndTellsTheReporter(): void
    {
        $incidentId = $this->reportIncident(false, $this->member);
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");

        markIncidentSeen(loadIncidentForAction($incidentId, $this->missionId), $this->adminId);
        markIncidentSeen(loadIncidentForAction($incidentId, $this->missionId), $this->adminId);

        $this->assertNotNull(dbFetchValue("SELECT acknowledged_at FROM mission_incidents WHERE id = ?", [$incidentId]));
        $told = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM notifications WHERE id > ?", [$before]), 'user_id'));
        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], $told, 'The reporter\'s team, once — the second call finds it already seen.');
    }

    public function testClosingTellsTheTeamStillOnItsWayWithTheOutcome(): void
    {
        $incidentId = $this->reportIncident();
        $this->sendTeam($this->teamId, $incidentId);
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");

        $told = notifyIncidentClosedToResponders(['outcome' => 'transported', 'outcome_location' => 'ΠΑΓΝΗ']
            + loadIncidentForAction($incidentId, $this->missionId), $this->adminId);

        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], $told);
        $rows = dbFetchAll("SELECT user_id, message, data FROM notifications WHERE id > ? ORDER BY id", [$before]);
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('ΠΑΓΝΗ', $rows[0]['message']);
        $ref = notificationPopupRef(json_decode($rows[0]['data'], true));
        $this->assertSame('info', $ref['kind']);
        $this->assertSame('mission_incident_closed', $ref['info']);
    }

    public function testATeamThatFinishedThereIsNotToldItClosed(): void
    {
        $incidentId = $this->reportIncident();
        $dispatchId = $this->sendTeam($this->teamId, $incidentId);
        recordDispatchProgress($dispatchId, $this->teamId, $this->member, 'complete');

        $told = notifyIncidentClosedToResponders(['outcome' => 'stayed_on_site', 'outcome_location' => null]
            + loadIncidentForAction($incidentId, $this->missionId), $this->adminId);
        $this->assertSame([], $told);
    }

    public function testNobodyHearsTwiceThatTheIncidentClosed(): void
    {
        // The team reported it and was then sent back to it.
        $incidentId = $this->reportIncident(false, $this->member);
        $this->sendTeam($this->teamId, $incidentId);
        $incident = ['outcome' => 'declined', 'outcome_location' => null] + loadIncidentForAction($incidentId, $this->missionId);
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");

        $told = notifyIncidentClosedToResponders($incident, $this->adminId);
        notifyIncidentAffectedUsers($incident, 'incident.resolved_notify_title', 'incident.resolved_notify_message', 'mission_incident_resolved', $this->adminId, $told);

        $recipients = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM notifications WHERE id > ?", [$before]), 'user_id'));
        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], $recipients);
    }

    public function testTheReportCarriesTheResponseTime(): void
    {
        $incidentId = $this->reportIncident();
        dbExecute("UPDATE mission_incidents SET created_at = NOW() - INTERVAL 12 MINUTE WHERE id = ?", [$incidentId]);
        $dispatchId = $this->sendTeam($this->teamId, $incidentId);
        recordDispatchProgress($dispatchId, $this->teamId, $this->member, 'arrive');

        $rows = array_values(array_filter(loadIncidentDetailForMissionReport($this->missionId), fn($r) => $r['responders']));
        $this->assertCount(1, $rows);
        $this->assertSame(teamLabel('ΑΛΦΑ', 1), $rows[0]['responders'][0]['label']);
        $this->assertEqualsWithDelta(12, $rows[0]['responders'][0]['arrive_minutes'], 1);
    }
}
