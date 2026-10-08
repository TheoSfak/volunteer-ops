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

    public function testHeavyBleedingIsRedAndAnOldOfflineAnswerSetIsStillAccepted(): void
    {
        // Breathes normally, obeys, but bleeds heavily: START alone said yellow.
        $r = $this->assess($this->anna, ['answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'bleeding' => true]]);
        $this->assertTrue($r['ok']);
        $this->assertSame('red', $r['category']);
        $this->assertSame('major_bleeding', $r['reason']);

        // Queued on a phone before the bleeding question existed: no answer for
        // it. Accepted as «no», and the stored path shows it was not asked.
        $old = $this->assess($this->anna, ['answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true]]);
        $this->assertTrue($old['ok']);
        $this->assertSame('yellow', $old['category']);
        $path = json_decode((string) dbFetchValue("SELECT answers FROM mission_triage_assessments WHERE victim_id = ?", [$old['victim_id']]), true);
        $this->assertArrayNotHasKey('bleeding', $path);
    }

    public function testSecondaryTriageScoresTheVitalsAndTheRescuerCanOverrideIt(): void
    {
        $first = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0001', 'answers' => ['walk' => false, 'breathing' => true, 'rr_over_30' => true]]);
        $this->assertSame('red', $first['category']);

        // Normal vitals: the score says green, the rescuer accepts (no category chosen).
        $r = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0001', 'protocol' => 'secondary', 'answers' => ['rr' => 18, 'sbp' => 125, 'gcs' => 15]]);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['created']);
        $this->assertSame('green', $r['category']);
        $this->assertSame('trts', $r['reason']);
        $this->assertSame('red', $r['previous_category']);
        $row = dbFetchOne("SELECT protocol, answers FROM mission_triage_assessments WHERE victim_id = ? ORDER BY id DESC LIMIT 1", [$first['victim_id']]);
        $this->assertSame('secondary', $row['protocol']);
        $this->assertSame(['rr' => 18, 'sbp' => 125, 'gcs' => 15, 'rts' => 12], json_decode($row['answers'], true));

        // The rescuer keeps it red against the score: stored as an override.
        $o = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0001', 'protocol' => 'secondary', 'category' => 'red', 'answers' => ['rr' => 18, 'sbp' => 125, 'gcs' => 15]]);
        $this->assertSame('red', $o['category']);
        $this->assertSame('secondary_override', $o['reason']);

        // Accepting the score's own colour explicitly is not an override.
        $same = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0001', 'protocol' => 'secondary', 'category' => 'yellow', 'answers' => ['rr' => 18, 'sbp' => 125, 'gcs' => 12]]);
        $this->assertSame('trts', $same['reason']);
    }

    public function testSecondaryTriageWithoutAScoreNeedsAColourAndNeverCreatesACasualty(): void
    {
        $first = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0002']);

        // Pressure not measured: no score, so the colour has to come from the rescuer.
        $noColour = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0002', 'protocol' => 'secondary', 'answers' => ['rr' => 18, 'gcs' => 15]]);
        $this->assertSame('triage.err_answers', $noColour['error']);

        $manual = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0002', 'protocol' => 'secondary', 'category' => 'yellow', 'answers' => ['rr' => 18, 'gcs' => 15]]);
        $this->assertTrue($manual['ok']);
        $this->assertSame('yellow', $manual['category']);
        $this->assertSame('secondary_manual', $manual['reason']);
        $path = json_decode((string) dbFetchValue("SELECT answers FROM mission_triage_assessments WHERE victim_id = ? ORDER BY id DESC LIMIT 1", [$first['victim_id']]), true);
        $this->assertSame(['rr' => 18, 'gcs' => 15], $path);

        // A casualty nobody has triaged is not created by a second look.
        $before = $this->victimCount();
        $unknown = $this->assess($this->anna, ['protocol' => 'secondary', 'category' => 'red', 'answers' => ['rr' => 18, 'sbp' => 90, 'gcs' => 15]]);
        $this->assertSame('triage.err_secondary_unknown', $unknown['error']);
        $this->assertSame($before, $this->victimCount());
    }

    public function testSecondaryTriageGivesAChildNoScore(): void
    {
        $this->assess($this->anna, ['victim_uuid' => 'v-sec-0003', 'protocol' => 'jumpstart', 'age_group' => 'child',
            'answers' => ['walk' => false, 'breathing' => true, 'rr_child' => true]]);
        $vitals = ['rr' => 18, 'sbp' => 110, 'gcs' => 15];
        // Normal adult vitals would say green; for a child the score is not used.
        $this->assertSame('triage.err_answers', $this->assess($this->anna, ['victim_uuid' => 'v-sec-0003', 'protocol' => 'secondary', 'age_group' => 'child', 'answers' => $vitals])['error']);
        $r = $this->assess($this->anna, ['victim_uuid' => 'v-sec-0003', 'protocol' => 'secondary', 'age_group' => 'child', 'category' => 'yellow', 'answers' => $vitals]);
        $this->assertSame('yellow', $r['category']);
        $this->assertSame('secondary_manual', $r['reason']);
    }

    public function testTheSizeupIsSavedSeenByEveryoneAndOnlyNewHazardsAreNews(): void
    {
        $this->assertNull(loadTriageStateForMission($this->missionId, false, $this->anna)['sizeup'], 'Nothing yet.');

        $r = saveMissionMciSizeup($this->missionId, ['hazards' => ['fire'], 'casualties_estimate' => '8', 'resources' => ['helicopter']], $this->adminId);
        $this->assertTrue($r['ok']);
        $this->assertSame(['fire'], $r['hazards_added']);

        // A volunteer (masked view) reads the same size-up.
        $state = loadTriageStateForMission($this->missionId, false, $this->anna);
        $this->assertSame(['fire'], $state['sizeup']['hazards']);
        $this->assertSame(8, $state['sizeup']['casualties_estimate']);
        $this->assertNotNull($state['sizeup_at']);
        $this->assertSame('Triage Admin', $state['sizeup_by']);

        // Same hazards again plus one new one: only the new one is news.
        $again = saveMissionMciSizeup($this->missionId, ['hazards' => ['fire', 'rockfall'], 'casualties_estimate' => 8, 'resources' => ['helicopter']], $this->adminId);
        $this->assertSame(['rockfall'], $again['hazards_added']);
        // Saving the identical form again is not an event and not news.
        $same = saveMissionMciSizeup($this->missionId, ['hazards' => ['fire', 'rockfall'], 'casualties_estimate' => 8, 'resources' => ['helicopter']], $this->adminId);
        $this->assertSame([], $same['hazards_added']);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_mci_log WHERE mission_id = ? AND action = 'sizeup'", [$this->missionId]), 'Two real edits, three saves.');

        $kinds = array_column(loadTriageActivityEvents($this->missionId), 'kind');
        $this->assertContains('mci_sizeup', $kinds);
        foreach (loadTriageActivityEvents($this->missionId) as $event) {
            $this->assertStringNotContainsString('triage.', triageActivityText($event, 'en'), $event['kind']);
        }

        // Emptying the form clears it.
        $this->assertTrue(saveMissionMciSizeup($this->missionId, [], $this->adminId)['ok']);
        $this->assertNull(loadTriageStateForMission($this->missionId, false, $this->anna)['sizeup']);
    }

    public function testASizeupNeedsAMissionThatHadAMassCasualtyIncident(): void
    {
        $r = saveMissionMciSizeup(999999999, ['hazards' => ['fire']], $this->adminId);
        $this->assertFalse($r['ok']);
        $this->assertSame('triage.err_inactive', $r['error']);
    }

    public function testOneVehicleCanTakeSeveralCasualtiesAtOnce(): void
    {
        $a = $this->assess($this->anna);
        $b = $this->assess($this->anna);
        $c = $this->assess($this->anna);
        // c already left earlier, in another vehicle: it must keep that.
        setTriageVictimStatus($this->missionId, $c['victim_id'], 'transported', 'ΕΚΑΒ 1', 'ΠΑΓΝΗ', $this->adminId);

        $r = setTriageVictimsTransported($this->missionId, [$a['victim_id'], $b['victim_id'], $c['victim_id'], 999999], 'Ελικόπτερο', 'Βενιζέλειο', $this->adminId);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['count'], 'Only the two still waiting are marked.');

        foreach ([$a, $b] as $x) {
            $row = dbFetchOne("SELECT status, transport_vehicle, transport_destination FROM mission_triage_victims WHERE id = ?", [$x['victim_id']]);
            $this->assertSame('transported', $row['status']);
            $this->assertSame('Ελικόπτερο', $row['transport_vehicle']);
            $this->assertSame('Βενιζέλειο', $row['transport_destination']);
        }
        $kept = dbFetchOne("SELECT transport_vehicle FROM mission_triage_victims WHERE id = ?", [$c['victim_id']]);
        $this->assertSame('ΕΚΑΒ 1', $kept['transport_vehicle']);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_status_log WHERE vehicle = 'Ελικόπτερο'"));
    }

    public function testABatchTransportNeedsRealCasualtiesAndStaysWithinTheLimit(): void
    {
        $this->assertSame('triage.err_invalid', setTriageVictimsTransported($this->missionId, [], 'x', 'y', $this->adminId)['error']);
        $this->assertSame('triage.err_invalid', setTriageVictimsTransported($this->missionId, range(1, TRIAGE_BATCH_MAX + 1), 'x', 'y', $this->adminId)['error']);
        $this->assertSame('triage.err_not_found', setTriageVictimsTransported($this->missionId, [999999], 'x', 'y', $this->adminId)['error']);
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

        // The quality figures: the one casualty went yellow -> red on its first
        // re-look, then left in a vehicle.
        $q = $report['quality'];
        $this->assertSame(1, $q['victims']);
        $this->assertSame(1, $q['reassessed']);
        $this->assertSame(1, $q['under']);
        $this->assertSame(1, $q['under_to_red']);
        $this->assertSame(0, $q['over']);
        $this->assertSame(1, $q['transport']['red']['n']);
        $this->assertGreaterThanOrEqual(19, $q['transport']['red']['median'], 'Triaged twenty minutes before it left.');
    }
}
