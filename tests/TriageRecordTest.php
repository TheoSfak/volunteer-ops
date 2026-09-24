<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The write rules of mass-casualty triage (includes/functions-triage.php).
 * Each test is one thing that must hold on a real incident: the server
 * decides the colour, a replayed tap is not a second casualty, a late
 * delivery does not overwrite a newer look at the same person, and a card
 * already on the mission is that person — never a new one.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class TriageRecordTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $shiftId;
    private int $anna;
    private int $vasilis;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Triage Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Καραμπόλα Ε.Ο.', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->anna = $this->makeVolunteer('Άννα Α.');
        $this->vasilis = $this->makeVolunteer('Βασίλης Β.');
        setMissionMciActive($this->missionId, true, $this->adminId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'tr-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        return $id;
    }

    /** One assessment with sensible defaults; $over replaces any key. */
    private function assess(int $userId, array $over = []): array
    {
        return recordTriageAssessment($this->missionId, $userId, array_merge([
            'victim_uuid'     => 'v-' . bin2hex(random_bytes(6)),
            'assessment_uuid' => 'a-' . bin2hex(random_bytes(6)),
            'protocol'        => 'start',
            // Breathing over 30: red.
            'answers'         => ['walk' => false, 'breathing' => true, 'rr_over_30' => true],
            'age_group'       => 'adult',
            'lat'             => 35.3387,
            'lng'             => 25.1442,
            'accuracy'        => 8,
            'assessed_at'     => date('Y-m-d H:i:s'),
        ], $over));
    }

    private function victimCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_victims WHERE mission_id = ?", [$this->missionId]);
    }

    public function testTheServerDecidesTheColourFromTheAnswers(): void
    {
        // A client claiming green for answers that mean red is ignored: only a
        // direct choice carries its own category.
        $r = $this->assess($this->anna, ['category' => 'green']);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['created']);
        $this->assertSame('red', $r['category']);
        $this->assertSame('rr_over_30', $r['reason']);

        $row = dbFetchOne("SELECT fallback_code, age_group FROM mission_triage_victims WHERE id = ?", [$r['victim_id']]);
        $this->assertMatchesRegularExpression('/^T' . $this->anna . '-\d{2,}$/', $row['fallback_code'], 'No card: the phone-made code stands in.');

        $path = json_decode((string) dbFetchValue("SELECT answers FROM mission_triage_assessments WHERE victim_id = ?", [$r['victim_id']]), true);
        $this->assertSame(['walk' => false, 'breathing' => true, 'rr_over_30' => true], $path);
    }

    public function testIncompleteAnswersAndBadDirectChoicesAreRefused(): void
    {
        $this->assertSame('triage.err_answers', $this->assess($this->anna, ['answers' => ['walk' => false]])['error']);
        $this->assertSame('triage.err_invalid', $this->assess($this->anna, ['protocol' => 'direct', 'category' => 'purple'])['error']);
        $this->assertSame('triage.err_invalid', $this->assess($this->anna, ['victim_uuid' => 'x'])['error']);
        $this->assertSame(0, $this->victimCount());

        $black = $this->assess($this->anna, ['protocol' => 'direct', 'category' => 'black']);
        $this->assertTrue($black['ok'], 'A trained rescuer may choose the colour directly.');
        $this->assertSame('black', $black['category']);
        $this->assertSame('direct', $black['reason']);
    }

    public function testJumpstartMarksTheCasualtyAsAChild(): void
    {
        $r = $this->assess($this->anna, [
            'protocol' => 'jumpstart',
            'age_group' => 'adult',
            'answers' => ['walk' => false, 'breathing' => false, 'airway' => false, 'pulse_apneic' => true, 'rescue_breaths' => true],
        ]);
        $this->assertSame('red', $r['category']);
        $this->assertSame('child', dbFetchValue("SELECT age_group FROM mission_triage_victims WHERE id = ?", [$r['victim_id']]));
    }

    public function testAReplayedAssessmentIsNotASecondCasualty(): void
    {
        $args = ['victim_uuid' => 'v-replay-1', 'assessment_uuid' => 'a-replay-1'];
        $first = $this->assess($this->anna, $args);
        $again = $this->assess($this->anna, $args);
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($again['duplicate'], 'The offline queue delivering the same tap twice changes nothing.');
        $this->assertSame($first['victim_id'], $again['victim_id']);
        $this->assertSame(1, $this->victimCount());
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_assessments WHERE mission_id = ?", [$this->missionId]));
    }

    public function testAReTriageKeepsTheHistoryAndMovesTheColour(): void
    {
        $uuid = 'v-retriage-1';
        $this->assess($this->anna, [
            'victim_uuid' => $uuid,
            'answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true],
            'assessed_at' => date('Y-m-d H:i:s', time() - 900),
        ]);
        $second = $this->assess($this->vasilis, ['victim_uuid' => $uuid]);
        $this->assertFalse($second['created']);
        $this->assertSame('yellow', $second['previous_category']);
        $this->assertSame('red', $second['category']);
        $this->assertSame(1, $this->victimCount());
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_assessments WHERE victim_id = ?", [$second['victim_id']]));
    }

    public function testALateDeliveryDoesNotOverwriteANewerAssessment(): void
    {
        $uuid = 'v-late-1';
        // Βασίλης looked at 10:20 and found red; Άννα's 10:05 look (yellow)
        // only reaches the server afterwards, from a phone that had no signal.
        $this->assess($this->vasilis, ['victim_uuid' => $uuid, 'assessed_at' => date('Y-m-d H:i:s', time() - 60)]);
        $late = $this->assess($this->anna, [
            'victim_uuid' => $uuid,
            'answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true],
            'assessed_at' => date('Y-m-d H:i:s', time() - 960),
        ]);
        $this->assertSame('red', $late['category'], 'The newest look by FIELD time wins, not the last to arrive.');
        $this->assertSame(
            [[ 'yellow', null], ['red', 'yellow']],
            array_map(fn($r) => [$r['category'], $r['previous_category']], dbFetchAll(
                "SELECT category, previous_category FROM mission_triage_assessments WHERE victim_id = ? ORDER BY assessed_at",
                [$late['victim_id']]
            )),
            'The re-triage chain follows field time too: the late yellow came first.'
        );
    }

    public function testACardAlreadyOnTheMissionIsTheSamePerson(): void
    {
        $first = $this->assess($this->anna, ['card_no' => 'ΚΤ 007']);
        $this->assertSame('KT007', dbFetchValue("SELECT card_no FROM mission_triage_victims WHERE id = ?", [$first['victim_id']]));

        // Another phone, which never saw this casualty, scans the same card.
        $second = $this->assess($this->vasilis, [
            'card_no' => 'kt007',
            'answers' => ['walk' => true],
        ]);
        $this->assertFalse($second['created']);
        $this->assertSame($first['victim_id'], $second['victim_id']);
        $this->assertSame('green', $second['category']);
        $this->assertSame(1, $this->victimCount());
    }

    public function testBindingACardThatBelongsToSomeoneElseAsksInsteadOfGuessing(): void
    {
        $owner = $this->assess($this->anna, ['card_no' => '0457']);
        $this->assess($this->vasilis, ['victim_uuid' => 'v-dup-0001']);

        $bind = bindTriageCard($this->missionId, 'v-dup-0001', '0457');
        $this->assertFalse($bind['ok']);
        $this->assertSame('triage.err_card_in_use', $bind['error']);
        $this->assertSame('0457', $bind['owner']['code']);

        $merge = mergeTriageVictimIntoCard($this->missionId, 'v-dup-0001', '0457');
        $this->assertTrue($merge['ok'], 'The rescuer said: same person.');
        $this->assertSame($owner['victim_id'], $merge['victim_id']);
        $this->assertSame(1, $this->victimCount());
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_assessments WHERE victim_id = ?", [$owner['victim_id']]),
            'Both looks at the person survive the merge.');
        $this->assertSame(1, (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_triage_assessments WHERE victim_id = ? AND previous_category IS NOT NULL", [$owner['victim_id']]
        ), 'After a merge the second look is a re-triage of the first, not a second first triage.');

        $free = $this->assess($this->vasilis, ['victim_uuid' => 'v-free-0001']);
        $this->assertTrue(bindTriageCard($this->missionId, 'v-free-0001', '0999')['ok']);
        $this->assertSame('0999', dbFetchValue("SELECT card_no FROM mission_triage_victims WHERE id = ?", [$free['victim_id']]));
    }

    public function testATakenPhoneCodeMovesOnToTheNextNumber(): void
    {
        $code = triageFallbackCode($this->anna, 1);
        $this->assess($this->anna, ['fallback_code' => $code]);
        // Same volunteer, second phone, same counter.
        $this->assess($this->anna, ['fallback_code' => $code]);
        $codes = array_column(dbFetchAll(
            "SELECT fallback_code FROM mission_triage_victims WHERE mission_id = ? ORDER BY id", [$this->missionId]
        ), 'fallback_code');
        $this->assertSame([$code, triageFallbackCode($this->anna, 2)], $codes);
        $this->assertSame(3, nextTriageFallbackSeq($this->missionId, $this->anna));
    }

    public function testWalkingWoundedAreCountedOnce(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->assertFalse(recordTriageBulkGreen($this->missionId, $this->anna, 'bulk-0001', 7, null, null, null, $now)['duplicate']);
        $this->assertTrue(recordTriageBulkGreen($this->missionId, $this->anna, 'bulk-0001', 7, null, null, null, $now)['duplicate']);
        $this->assertFalse(recordTriageBulkGreen($this->missionId, $this->anna, 'bulk-0002', 0, null, null, null, $now)['ok']);
        $this->assertSame(7, triageCategoryCounts($this->missionId)['walking']);
    }

    public function testTheBoardSortsByPriorityAndMasksForVolunteers(): void
    {
        $yellow = $this->assess($this->anna, [
            'answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true],
            'assessed_at' => date('Y-m-d H:i:s', time() - 600),
        ]);
        $redGone = $this->assess($this->anna, ['assessed_at' => date('Y-m-d H:i:s', time() - 500)]);
        $red = $this->assess($this->vasilis, ['assessed_at' => date('Y-m-d H:i:s', time() - 300)]);
        $black = $this->assess($this->vasilis, ['protocol' => 'direct', 'category' => 'black']);
        setTriageVictimStatus($this->missionId, $redGone['victim_id'], 'transported', 'ΕΚΑΒ 12', 'ΠΑΓΝΗ', $this->adminId);
        setTriageVictimDetails($this->missionId, $red['victim_id'], ['patient_name' => 'Γιώργος Παπαδόπουλος', 'phone' => '6912345678', 'notes' => 'κάταγμα']);

        $command = loadTriageStateForMission($this->missionId, true, $this->adminId);
        $this->assertSame(
            [$red['victim_id'], $yellow['victim_id'], $black['victim_id'], $redGone['victim_id']],
            array_column($command['victims'], 'id'),
            'Waiting red first, then yellow, then black; transported last.'
        );
        $this->assertSame(1, $command['waiting_red']);
        $this->assertSame(['red' => 2, 'yellow' => 1, 'green' => 0, 'black' => 1], $command['counts']);
        $this->assertSame('Γιώργος Παπαδόπουλος', $command['victims'][0]['patient_name']);
        $this->assertSame('κάταγμα', $command['victims'][0]['notes']);

        $volunteer = loadTriageStateForMission($this->missionId, false, $this->anna);
        $this->assertSame('Γ. Παπαδόπουλος', $volunteer['victims'][0]['patient_name']);
        $this->assertSame('*** *** 5678', $volunteer['victims'][0]['phone']);
        $this->assertNull($volunteer['victims'][0]['notes']);
        $this->assertSame('black', $volunteer['victims'][2]['category'], 'Black is visible to everyone (the owner\'s decision).');
    }

    public function testTheSwitchOnlyLogsRealChanges(): void
    {
        $this->assertFalse(setMissionMciActive($this->missionId, true, $this->adminId), 'Already on.');
        $this->assertTrue(setMissionMciActive($this->missionId, false, $this->adminId));
        $this->assertFalse(isMissionMciActive($this->missionId));
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_mci_log WHERE mission_id = ?", [$this->missionId]));
    }

    public function testTheTimelineAndTheReportSeeEveryEvent(): void
    {
        $uuid = 'v-timeline-1';
        $this->assess($this->anna, [
            'victim_uuid' => $uuid, 'card_no' => '0100',
            'answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true],
            'assessed_at' => date('Y-m-d H:i:s', time() - 1200),
        ]);
        $re = $this->assess($this->vasilis, ['victim_uuid' => $uuid]);
        recordTriageBulkGreen($this->missionId, $this->anna, 'bulk-time1', 4, null, null, null, date('Y-m-d H:i:s'));
        setTriageVictimStatus($this->missionId, $re['victim_id'], 'transported', 'ΕΚΑΒ 3', 'Βενιζέλειο', $this->adminId);

        $kinds = array_column(loadTriageActivityEvents($this->missionId), 'kind');
        foreach (['mci_activated', 'triaged', 'retriaged', 'bulk_green', 'status'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        foreach (loadTriageActivityEvents($this->missionId) as $event) {
            $this->assertNotSame('', triageActivityText($event, 'el'), $event['kind']);
            $this->assertStringNotContainsString('triage.', triageActivityText($event, 'en'), 'every line is translated: ' . $event['kind']);
        }

        $report = loadTriageReportForMission($this->missionId);
        $this->assertSame(1, $report['retriaged']);
        $this->assertSame(1, $report['deteriorated'], 'Yellow to red is a deterioration.');
        $this->assertSame(1, $report['initial_counts']['yellow']);
        $this->assertSame(1, $report['counts']['red']);
        $this->assertSame(4, $report['walking']);
        $this->assertNotNull($report['last_red_out_at']);
    }
}
