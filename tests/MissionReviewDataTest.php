<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * loadMissionReviewData(): every incident and shortage report of a finished
 * mission, for the archive (command staff, unmasked), the debrief page and the
 * PDF report (masked). The privacy split is the thing worth pinning: the
 * archive may show the real patient name, phone and the staff-only notes, the
 * other two must never receive them from this loader at all.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class MissionReviewDataTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Review Admin', 'review-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Review Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600), $missionTypeId, STATUS_CLOSED, $this->adminId]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
    }

    private function addIncident(array $over = []): int
    {
        $row = array_merge([
            'severity' => 'high', 'unknown' => 0, 'name' => 'Γιώργος Παπαδάκης', 'phone' => '6971234567',
            'notes' => 'Κάταγμα αστραγάλου', 'lat' => 35.3042, 'lng' => 25.1063,
            'resolved' => '2026-01-01 11:35:00', 'outcome' => 'transported', 'where' => 'ΠΑΓΝΗ',
        ], $over);
        return (int) dbInsert(
            "INSERT INTO mission_incidents (mission_id, reporter_id, team_id, lat, lng, incident_type, severity, is_unknown_patient, patient_name, phone, notes, resolved_at, resolved_by, outcome, outcome_location, created_at)
             VALUES (?, ?, ?, ?, ?, 'trauma', ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026-01-01 10:40:00')",
            [$this->missionId, $this->adminId, $this->teamId, $row['lat'], $row['lng'], $row['severity'], $row['unknown'],
             $row['name'], $row['phone'], $row['notes'], $row['resolved'], $row['resolved'] ? $this->adminId : null, $row['outcome'], $row['where']]
        );
    }

    private function addShortage(array $over = []): int
    {
        $row = array_merge([
            'severity' => 'high', 'title' => 'Νάρθηκες', 'description' => 'Έχουμε μόνο έναν νάρθηκα.',
            'resolved' => null, 'not_resolved' => null, 'note' => null, 'seen' => null,
        ], $over);
        return (int) dbInsert(
            "INSERT INTO mission_shortage_reports (mission_id, reporter_id, team_id, shortage_type, severity, title, description, acknowledged_at, resolved_at, not_resolved_at, outcome_note, created_at)
             VALUES (?, ?, ?, 'equipment', ?, ?, ?, ?, ?, ?, ?, '2026-01-01 10:38:00')",
            [$this->missionId, $this->adminId, $this->teamId, $row['severity'], $row['title'], $row['description'],
             $row['seen'], $row['resolved'], $row['not_resolved'], $row['note']]
        );
    }

    public function testCommandStaffViewCarriesTheRealPatientAndTheStaffNotes(): void
    {
        $this->addIncident();
        $incident = loadMissionReviewData($this->missionId, true)['incidents'][0];

        $this->assertSame('Γιώργος Παπαδάκης', $incident['patient']);
        $this->assertSame('6971234567', $incident['phone']);
        $this->assertSame('Κάταγμα αστραγάλου', $incident['notes']);
    }

    public function testMaskedViewNeverCarriesTheRealPatientOrTheStaffNotes(): void
    {
        $this->addIncident();
        $incident = loadMissionReviewData($this->missionId, false)['incidents'][0];

        $this->assertSame('Γ. Παπαδάκης', $incident['patient']);
        $this->assertStringNotContainsString('697123', (string) $incident['phone']);
        $this->assertStringEndsWith('4567', (string) $incident['phone']);
        $this->assertNull($incident['notes']);
        // Nothing in the whole row, under any key, may still hold the real values.
        $everything = json_encode($incident, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Γιώργος', $everything);
        $this->assertStringNotContainsString('Κάταγμα', $everything);
    }

    public function testAnUnknownPatientIsLabelledNotNamed(): void
    {
        $this->addIncident(['unknown' => 1, 'name' => null, 'phone' => null]);
        $incident = loadMissionReviewData($this->missionId, true, 'el')['incidents'][0];

        $this->assertSame(t('incident.unknown_patient_label', [], 'el'), $incident['patient']);
        $this->assertNull($incident['phone']);
    }

    public function testAnUnresolvedIncidentIsStillListedWithItsPosition(): void
    {
        $this->addIncident(['resolved' => null, 'outcome' => null, 'where' => null]);
        $incident = loadMissionReviewData($this->missionId, true)['incidents'][0];

        $this->assertNull($incident['resolved_at']);
        $this->assertNull($incident['outcome_label']);
        $this->assertEqualsWithDelta(35.3042, $incident['lat'], 0.00001);
    }

    public function testShortageKeepsDescriptionNoteAndFinalStatus(): void
    {
        $this->addShortage(['title' => 'Λύθηκε', 'resolved' => '2026-01-01 11:00:00', 'seen' => '2026-01-01 10:41:00', 'note' => 'Στάλθηκε νάρθηκας.']);
        $this->addShortage(['title' => 'Δεν λύθηκε', 'not_resolved' => '2026-01-01 11:10:00', 'severity' => 'medium']);
        $this->addShortage(['title' => 'Εκκρεμεί', 'severity' => 'low']);
        $this->addShortage(['title' => 'Το είδε', 'severity' => 'low', 'seen' => '2026-01-01 10:50:00']);

        $byTitle = [];
        foreach (loadMissionReviewData($this->missionId, false)['shortages'] as $s) {
            $byTitle[$s['title']] = $s;
        }

        $this->assertSame('resolved', $byTitle['Λύθηκε']['status']);
        $this->assertSame('Στάλθηκε νάρθηκας.', $byTitle['Λύθηκε']['outcome_note']);
        $this->assertSame('Έχουμε μόνο έναν νάρθηκα.', $byTitle['Λύθηκε']['description']);
        $this->assertEquals(22.0, $byTitle['Λύθηκε']['resolved_minutes']);
        $this->assertSame('not_resolved', $byTitle['Δεν λύθηκε']['status']);
        $this->assertNotNull($byTitle['Δεν λύθηκε']['not_resolved_at']);
        $this->assertSame('open', $byTitle['Εκκρεμεί']['status']);
        $this->assertSame('seen', $byTitle['Το είδε']['status']);
    }

    public function testWorstSeverityComesFirstAndOtherMissionsAreNotMixedIn(): void
    {
        $this->addIncident(['severity' => 'low']);
        $this->addIncident(['severity' => 'critical']);
        $this->addShortage(['severity' => 'low', 'title' => 'Χαμηλή']);
        $this->addShortage(['severity' => 'critical', 'title' => 'Κρίσιμη']);

        $otherMission = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
            ['Other', 'Ηράκλειο', '2026-01-01 09:00:00', '2026-01-01 10:00:00', (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1"), STATUS_CLOSED, $this->adminId]
        );
        dbInsert(
            "INSERT INTO mission_shortage_reports (mission_id, reporter_id, shortage_type, severity, title, description) VALUES (?, ?, 'other', 'high', 'Ξένη', 'x')",
            [$otherMission, $this->adminId]
        );

        $data = loadMissionReviewData($this->missionId, false);
        $this->assertSame(['critical', 'low'], array_column($data['incidents'], 'severity'));
        $this->assertSame(['Κρίσιμη', 'Χαμηλή'], array_column($data['shortages'], 'title'));
    }

    public function testTheRenderedCardsHonourTheSameSplit(): void
    {
        require_once __DIR__ . '/../includes/mission-review-render.php';
        $this->addIncident();
        $this->addShortage(['note' => 'Σημείωση επίλυσης Χ', 'resolved' => '2026-01-01 11:00:00']);

        $full   = renderMissionReviewCards(loadMissionReviewData($this->missionId, true), 'el');
        $masked = renderMissionReviewCards(loadMissionReviewData($this->missionId, false), 'el');

        $this->assertStringContainsString('Γιώργος Παπαδάκης', $full['incidents']);
        $this->assertStringContainsString('Κάταγμα αστραγάλου', $full['incidents']);
        $this->assertStringNotContainsString('Γιώργος', $masked['incidents']);
        $this->assertStringNotContainsString('Κάταγμα', $masked['incidents']);
        $this->assertStringContainsString('Γ. Παπαδάκης', $masked['incidents']);
        // Shortage detail is the same for both audiences.
        $this->assertStringContainsString('Έχουμε μόνο έναν νάρθηκα.', $masked['shortages']);
        $this->assertStringContainsString('Σημείωση επίλυσης Χ', $masked['shortages']);
    }
}
