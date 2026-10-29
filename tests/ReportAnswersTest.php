<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A guest filed a shortage report, command answered it with a comment and
 * marked it resolved, and the guest was told nothing. Two things were behind
 * it, and these tests pin both:
 *
 *  1. The answer went to the team the reporter had WHEN THEY FILED, and to the
 *     reporter alone only if that report had no team. A reporter who had since
 *     been moved to another team, or taken out of theirs, was the one person
 *     not told while their old teammates were. Shortage and incident reports.
 *  2. The only trace of the answer was a notification - a ticker line the
 *     «Κρυφά» ticker mode hides, a bell entry, a push. Now the Action Room keeps
 *     «Οι αναφορές μου» under each report form, with the status and command's
 *     comment, redrawn by the 5 s poll.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ReportAnswersTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;
    private int $otherTeamId;
    private int $reporter;
    private int $teammate;
    private int $bystander;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Answer Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Answer Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->otherTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        $this->reporter = $this->makeUser('Ξένος Αναφέρων', true);
        $this->teammate = $this->makeUser('Συνάδελφος');
        $this->bystander = $this->makeUser('Άλλη ομάδα');
        $this->join($this->reporter, $this->teamId);
        $this->join($this->teammate, $this->teamId);
        $this->join($this->bystander, $this->otherTeamId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name, bool $guest = false): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, is_external) VALUES (?, ?, ?, ?)",
            [$name, 'ans-' . uniqid('', true) . '@example.invalid', 'x', $guest ? 1 : 0]
        );
    }

    private function makeTeam(string $codename, int $number): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function join(int $userId, int $teamId): void
    {
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $userId]);
    }

    private function leaveTeam(int $userId): void
    {
        dbExecute("DELETE FROM mission_team_members WHERE mission_id = ? AND user_id = ?", [$this->missionId, $userId]);
    }

    private function shortage(int $reporter, ?int $teamId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_shortage_reports (mission_id, reporter_id, team_id, shortage_type, severity, title, description)
             VALUES (?, ?, ?, 'equipment', 'medium', 'Φακοί', 'Χρειαζόμαστε φακούς')",
            [$this->missionId, $reporter, $teamId]
        );
    }

    private function incident(int $reporter): int
    {
        $mission = dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$this->missionId]);
        $user = dbFetchOne("SELECT id, name FROM users WHERE id = ?", [$reporter]);
        $result = reportMissionIncident($mission, $user, [
            'incident_type' => 'trauma', 'severity' => 'high', 'patient_name' => 'Μαρία Κ.', 'lat' => '35.2', 'lng' => '24.9',
        ], false);
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        return $result['id'];
    }

    private function lastNotificationId(): int
    {
        return (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");
    }

    /** @return array<int, string> user id => message, for what was sent after $before. */
    private function messagesSince(int $before): array
    {
        $out = [];
        foreach (dbFetchAll("SELECT user_id, message FROM notifications WHERE id > ? ORDER BY id", [$before]) as $row) {
            $out[(int) $row['user_id']] = $row['message'];
        }
        return $out;
    }

    // ── 1. who is told ───────────────────────────────────────────────────────

    public function testTheReporterIsToldEvenAfterLeavingTheTeamTheyFiledFrom(): void
    {
        $ids = reportAnswerRecipientIds($this->teamId, $this->reporter, $this->adminId);
        $this->assertEqualsCanonicalizing([$this->reporter, $this->teammate], $ids);

        // The case that was missed: the reporter is no longer on that team.
        $this->leaveTeam($this->reporter);
        $ids = reportAnswerRecipientIds($this->teamId, $this->reporter, $this->adminId);
        $this->assertContains($this->reporter, $ids, 'the person who asked must hear the answer');
        $this->assertContains($this->teammate, $ids);
        $this->assertNotContains($this->bystander, $ids);
    }

    public function testAReporterWithNoTeamIsToldAlone(): void
    {
        $this->assertSame([$this->reporter], reportAnswerRecipientIds(null, $this->reporter, $this->adminId));
    }

    public function testTheActingAdminAndAnyoneAlreadyToldAreLeftOut(): void
    {
        $this->join($this->adminId, $this->teamId);
        $ids = reportAnswerRecipientIds($this->teamId, $this->reporter, $this->adminId, [$this->teammate]);
        $this->assertSame([$this->reporter], $ids);
        // The reporter commanding their own report is not told about it.
        $this->assertSame([], reportAnswerRecipientIds(null, $this->adminId, $this->adminId));
    }

    public function testAnIncidentClosedAfterTheReporterMovedStillReachesThem(): void
    {
        $incidentId = $this->incident($this->reporter);
        $this->leaveTeam($this->reporter);
        $this->join($this->reporter, $this->otherTeamId);

        $before = $this->lastNotificationId();
        $incident = loadIncidentForAction($incidentId, $this->missionId);
        $closed = ['outcome' => 'transported', 'outcome_location' => 'ΠΑΓΝΗ', 'outcome_note' => 'Ευχαριστούμε για την ενημέρωση'] + $incident;
        notifyIncidentAffectedUsers($closed, 'incident.resolved_notify_title', 'incident.resolved_notify_message', 'mission_incident_resolved', $this->adminId);

        $sent = $this->messagesSince($before);
        $this->assertArrayHasKey($this->reporter, $sent);
        $this->assertArrayHasKey($this->teammate, $sent);
        $this->assertArrayNotHasKey($this->bystander, $sent);
        $this->assertStringContainsString('ΠΑΓΝΗ', $sent[$this->reporter], 'the outcome travels with the closing');
        $this->assertStringContainsString('Ευχαριστούμε για την ενημέρωση', $sent[$this->reporter], "command's comment travels with it");
    }

    public function testSeeingAnIncidentDoesNotRepeatTheOutcome(): void
    {
        $incidentId = $this->incident($this->reporter);
        $before = $this->lastNotificationId();
        notifyIncidentAffectedUsers(
            ['outcome_note' => 'δεν πρέπει να φανεί'] + loadIncidentForAction($incidentId, $this->missionId),
            'incident.seen_notify_title', 'incident.seen_notify_message', 'mission_incident_seen', $this->adminId
        );
        $this->assertStringNotContainsString('δεν πρέπει να φανεί', $this->messagesSince($before)[$this->reporter]);
    }

    public function testBothEndpointsUseTheSharedRecipientList(): void
    {
        $shortage = file_get_contents(__DIR__ . '/../mission-shortage.php');
        $this->assertStringContainsString('reportAnswerRecipientIds(', $shortage);
        $this->assertStringNotContainsString('SELECT user_id FROM mission_team_members', $shortage, 'a second copy of the old recipient rule');

        $warRoom = file_get_contents(__DIR__ . '/../includes/functions-warroom.php');
        $this->assertSame(1, substr_count($warRoom, 'dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?"'), 'one recipient rule for both kinds of report');
    }

    public function testClosingAnIncidentStoresCommandsComment(): void
    {
        $page = file_get_contents(__DIR__ . '/../mission-incident.php');
        $this->assertStringContainsString("post('note')", $page);
        $this->assertStringContainsString('outcome_note = ?', $page);
        // …and the form that sends it.
        $war = file_get_contents(__DIR__ . '/../war-room.php');
        $this->assertStringContainsString('incident-note-input', $war);
        $this->assertStringContainsString("note: noteInput ? noteInput.value : ''", $war);

        $this->assertNotNull(dbFetchOne("SHOW COLUMNS FROM mission_incidents LIKE 'outcome_note'"));
    }

    // ── 2. what the reporter keeps ───────────────────────────────────────────

    public function testTheListHoldsOnlyTheReportersOwnReportsWithStatusAndComment(): void
    {
        $open = $this->shortage($this->reporter, $this->teamId);
        $solved = $this->shortage($this->reporter, $this->teamId);
        $refused = $this->shortage($this->reporter, $this->teamId);
        $this->shortage($this->teammate, $this->teamId);
        dbExecute("UPDATE mission_shortage_reports SET acknowledged_at = NOW() WHERE id = ?", [$solved]);
        dbExecute("UPDATE mission_shortage_reports SET resolved_at = NOW(), outcome_note = ? WHERE id = ?", ['Έρχονται φακοί με το όχημα <b>3</b>', $solved]);
        dbExecute("UPDATE mission_shortage_reports SET not_resolved_at = NOW(), outcome_note = ? WHERE id = ?", ['Δεν υπάρχει διαθέσιμο όχημα', $refused]);

        $mine = loadMyReportsForMission($this->missionId, $this->reporter);
        $this->assertCount(3, $mine['shortage'], "a teammate's report is not mine");
        $this->assertSame([], $mine['incident']);
        $byId = array_column($mine['shortage'], null, 'id');
        $this->assertSame('sent', $byId[$open]['state']);
        $this->assertSame('resolved', $byId[$solved]['state']);
        $this->assertSame('not_resolved', $byId[$refused]['state']);

        $html = renderMyReports($mine['shortage'], 'shortage');
        $this->assertStringContainsString('Λύθηκε', $html);
        $this->assertStringContainsString('Δεν λύθηκε', $html);
        $this->assertStringContainsString('Εστάλη', $html);
        $this->assertStringContainsString('Δεν υπάρχει διαθέσιμο όχημα', $html);
        $this->assertStringContainsString('&lt;b&gt;3&lt;/b&gt;', $html, "command's comment is shown as typed, never as markup");
        $this->assertStringNotContainsString('<b>3</b>', $html);
    }

    public function testAnIncidentShowsItsOutcomeToItsReporterOnly(): void
    {
        $incidentId = $this->incident($this->reporter);
        dbExecute(
            "UPDATE mission_incidents SET acknowledged_at = NOW(), resolved_at = NOW(), outcome = 'transported', outcome_location = 'ΠΑΓΝΗ', outcome_note = ? WHERE id = ?",
            ['Καλή δουλειά', $incidentId]
        );
        $mine = loadMyReportsForMission($this->missionId, $this->reporter);
        $html = renderMyReports($mine['incident'], 'incident');
        $this->assertStringContainsString('Έκλεισε', $html);
        $this->assertStringContainsString('ΠΑΓΝΗ', $html);
        $this->assertStringContainsString('Καλή δουλειά', $html);
        $this->assertStringNotContainsString('Μαρία Κ.', $html, "the patient's name is not part of the reporter's list");

        $theirs = loadMyReportsForMission($this->missionId, $this->teammate);
        $this->assertSame([], $theirs['incident'], 'a teammate does not get the reporter\'s incident outcome');
    }

    public function testAnEmptyListSaysSo(): void
    {
        $html = renderMyReports([], 'shortage');
        $this->assertStringContainsString('Δεν έχετε στείλει αναφορά έλλειψης', $html);
        $this->assertStringContainsString('Οι αναφορές μου', $html);
    }

    public function testTheListChangesItsSignatureWhenCommandAnswers(): void
    {
        $id = $this->shortage($this->reporter, $this->teamId);
        $sig = fn() => warRoomLiveFragmentPayload(renderMyReports(loadMyReportsForMission($this->missionId, $this->reporter)['shortage'], 'shortage'))['sig'];
        $first = $sig();
        $this->assertSame($first, $sig(), 'a quiet mission redraws nothing');
        dbExecute("UPDATE mission_shortage_reports SET acknowledged_at = NOW(), resolved_at = NOW(), outcome_note = 'Λύθηκε με φακούς' WHERE id = ?", [$id]);
        $this->assertNotSame($first, $sig());
    }

    public function testThePollCarriesBothListsOnlyForParticipants(): void
    {
        $fragments = buildMyReportsFragments($this->missionId, $this->reporter);
        $this->assertSame(['myShortageReports', 'myIncidentReports'], array_keys($fragments));
        foreach ($fragments as $fragment) {
            $this->assertArrayHasKey('sig', $fragment);
            $this->assertArrayHasKey('html', $fragment);
        }

        $page = file_get_contents(__DIR__ . '/../war-room.php');
        $this->assertStringContainsString("'myReports' => \$isApprovedParticipant ? buildMyReportsFragments(", $page);
        $this->assertStringContainsString('if (data.myReports) applyLiveFragments(data.myReports);', $page);
        $this->assertStringContainsString("renderMyReportsFragment(\$missionId, (int) \$user['id'], 'shortage')", $page);
        $this->assertStringContainsString("renderMyReportsFragment(\$missionId, (int) \$user['id'], 'incident')", $page);

        // On the page the markup is the same wrapper the poll swaps.
        $html = renderMyReportsFragment($this->missionId, $this->reporter, 'shortage');
        $this->assertStringContainsString('data-live-fragment="myShortageReports"', $html);
    }

    // ── the activity timeline keeps the answer too ───────────────────────────

    public function testTheActivityTimelineShowsCommentsAndTheRefusal(): void
    {
        $page = file_get_contents(__DIR__ . '/../mission-history.php');
        $this->assertStringContainsString("history.shortage_not_resolved", $page);
        $this->assertStringContainsString('r.not_resolved_at, r.outcome_note', $page);
        $this->assertStringContainsString("(int) \$row['reporter_id'] === (int) \$userId", $page, 'outcome and comment of an incident: command and its reporter only');
    }

    public function testEveryNewStringExistsInGreekAndEnglish(): void
    {
        $strings = loadLangStrings('war-room');
        foreach (['incident.note_placeholder', 'incident.outcome_suffix', 'history.shortage_not_resolved',
                  'myreports.title', 'myreports.empty_shortage', 'myreports.empty_incident', 'myreports.sent', 'myreports.seen',
                  'myreports.resolved', 'myreports.closed', 'myreports.not_resolved', 'myreports.sent_at',
                  'myreports.outcome', 'myreports.comment'] as $key) {
            $this->assertNotEmpty($strings['el'][$key] ?? '', "el $key");
            $this->assertNotEmpty($strings['en'][$key] ?? '', "en $key");
            $this->assertNotSame($strings['el'][$key], $strings['en'][$key], "en $key is still the Greek text");
            $this->assertDoesNotMatchRegularExpression('/\p{Greek}/u', $strings['en'][$key], "Greek in en $key");
        }
    }
}
