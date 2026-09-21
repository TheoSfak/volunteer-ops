<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The push-to-talk emergency voice channel, server side.
 *
 * Two halves, and they answer different questions. The loader decides what a
 * coordinator is shown and, crucially, what leaves the list once it has been
 * heard — a card that never empties is a card people stop reading. The
 * assistant half proves an unheard call also reaches «Τι μου ξέφυγε», because
 * the siren and the ticker are both dismissable and the panel is what remains.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class VoiceMessagesTest extends TestCase
{
    private int $missionId;
    private int $userId;
    private int $prId;
    private int $teamId;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Γιώργος Παπαδάκης', 'voice-test-' . uniqid('', true) . '@example.invalid', 'x']
        );
        // missions.mission_type_id is NOT NULL with a hardcoded default of 1 and
        // a foreign key — take whichever type this database really has.
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Voice Test Mission', 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->prId = (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $this->userId, PARTICIPATION_APPROVED]
        );
        // created_by is NOT NULL with a foreign key and no default.
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Alpha', 1, $this->userId]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function sendVoice(?int $durationMs = 4200, ?int $teamId = null, ?float $lat = 35.15, ?float $lng = 24.89, ?string $createdAt = null): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_voice_messages
                (mission_id, user_id, pr_id, team_id, stored_name, mime_type, file_size, duration_ms, lat, lng, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->missionId, $this->userId, $this->prId, $teamId,
                'mvoice_test_' . uniqid('', true) . '.m4a', 'audio/mp4', 28883,
                $durationMs, $lat, $lng, $createdAt ?? date('Y-m-d H:i:s'),
            ]
        );
    }

    // ── What the coordinator's card shows ───────────────────────────────────

    public function testAnUnheardMessageReachesTheCoordinator(): void
    {
        $id = $this->sendVoice(4200, $this->teamId);

        $rows = loadUnacknowledgedVoiceMessagesForMission($this->missionId);

        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
        $this->assertSame('Γιώργος Παπαδάκης', $rows[0]['user_name']);
        $this->assertStringContainsString('Alpha', $rows[0]['team_label']);
    }

    public function testAcknowledgingItTakesItOffTheCard(): void
    {
        // The card is a queue, not a log: something already heard must leave,
        // or the siren and the list stop meaning "somebody is waiting".
        $id = $this->sendVoice();
        dbExecute("UPDATE mission_voice_messages SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$this->userId, $id]);

        $this->assertCount(0, loadUnacknowledgedVoiceMessagesForMission($this->missionId));
    }

    public function testTheOldestCallIsFirst(): void
    {
        // Whoever has been waiting longest gets answered first, and the ticker
        // names row zero — so this ordering is what a coordinator reads.
        $older = $this->sendVoice(3000, $this->teamId, 35.1, 24.8, date('Y-m-d H:i:s', time() - 600));
        $newer = $this->sendVoice(3000, $this->teamId, 35.1, 24.8, date('Y-m-d H:i:s', time() - 60));

        $ids = array_column(loadUnacknowledgedVoiceMessagesForMission($this->missionId), 'id');
        $this->assertSame([$older, $newer], $ids);
    }

    public function testAVolunteerOnNoTeamIsLabelledRatherThanBlank(): void
    {
        // An empty team badge in an emergency list reads as a rendering bug.
        $this->sendVoice(2000, null);

        $this->assertNotSame('', trim(loadUnacknowledgedVoiceMessagesForMission($this->missionId)[0]['team_label']));
    }

    public function testDurationIsRoundedUpSoNothingReadsAsZero(): void
    {
        // 1.4s displayed as "1s" looks like truncation; 0 would look broken.
        $this->sendVoice(1400);
        $this->assertSame(2, loadUnacknowledgedVoiceMessagesForMission($this->missionId)[0]['duration_s']);
    }

    public function testAClipWhoseDurationTheBrowserNeverReportedStillShows(): void
    {
        // MediaRecorder does not always report a duration. That is a missing
        // label, never a reason to hide an emergency call.
        $this->sendVoice(null);

        $rows = loadUnacknowledgedVoiceMessagesForMission($this->missionId);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['duration_s']);
    }

    public function testAStuckButtonCannotFloodThePollPayload(): void
    {
        // This list ships inside the 5s poll. A button pressed repeatedly in a
        // pocket must not make that payload unbounded.
        for ($i = 0; $i < 25; $i++) {
            $this->sendVoice(1500, $this->teamId);
        }
        $this->assertCount(20, loadUnacknowledgedVoiceMessagesForMission($this->missionId));
        $this->assertCount(5, loadUnacknowledgedVoiceMessagesForMission($this->missionId, 5));
    }

    public function testMessagesFromAnotherMissionNeverLeakIn(): void
    {
        $otherMissionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Other', 'Αλλού', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600),
             (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1")]
        );
        dbInsert(
            "INSERT INTO mission_voice_messages (mission_id, user_id, stored_name, mime_type, file_size)
             VALUES (?, ?, ?, ?, ?)",
            [$otherMissionId, $this->userId, 'x.m4a', 'audio/mp4', 100]
        );

        $this->assertCount(0, loadUnacknowledgedVoiceMessagesForMission($this->missionId));
    }

    // ── And it reaches «Τι μου ξέφυγε» ──────────────────────────────────────

    public function testAnUnheardCallIsRaisedInTheAssistantPanel(): void
    {
        // The siren can be muted and the ticker row dismissed. The panel is
        // what is left, so a call that never reached it could be lost for good.
        $out = assembleMissionAssistantItems(
            ['voice' => [[
                'id' => 7, 'ts' => time() - 120, 'duration_ms' => 4200,
                'who' => 'Γιώργος', 'codename' => 'Alpha', 'team_number' => 1,
            ]]],
            null, time(), 'el', 540
        );

        $this->assertCount(1, $out['pending']);
        $this->assertSame('voice', $out['pending'][0]['kind']);
        $this->assertSame('high', $out['pending'][0]['sev'], 'a rescuer speaking is never merely a warning');
        $this->assertSame('voiceMessagesCard', $out['pending'][0]['target']);
    }

    public function testAVoiceRowOffersNoExplainButtonBecauseTheAiCannotHearIt(): void
    {
        // war-room.php hides «Εξήγησέ μου» exactly when a row carries no ref,
        // and the digest neither reads this table nor could describe audio.
        $out = assembleMissionAssistantItems(
            ['voice' => [['id' => 7, 'ts' => time() - 120, 'duration_ms' => null,
                          'who' => 'Γιώργος', 'codename' => null, 'team_number' => null]]],
            null, time(), 'el', 540
        );

        $this->assertArrayNotHasKey('ref', $out['pending'][0]);
    }

    public function testAVoiceCallDoesNotSoundTheOverdueOrdersAlarm(): void
    {
        // It has a siren of its own. Folding it into counts.overdue would make
        // that number mean two different things at once.
        $out = assembleMissionAssistantItems(
            ['voice' => [['id' => 7, 'ts' => time() - 7200, 'duration_ms' => 3000,
                          'who' => 'Γιώργος', 'codename' => 'Alpha', 'team_number' => 1]]],
            null, time(), 'el', 540
        );

        $this->assertNotEmpty($out['pending']);
        $this->assertSame(0, $out['counts']['overdue']);
    }
}
