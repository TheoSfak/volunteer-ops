<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * loadChatRoomSummary(): how many messages each chat room of a mission holds.
 *
 * It feeds the admin console's «Συνομιλία» counter. What is pinned: the general
 * room (team_id NULL) and each team's room are told apart, an empty room is
 * simply absent, another mission's rooms never leak in, and a team id comes out
 * as a string key (a JSON object key, which is what the browser reads it as).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ChatRoomSummaryTest extends TestCase
{
    private int $adminId;
    private int $missionId;
    private int $otherMissionId;
    private int $alphaId;
    private int $bravoId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Chat Summary Admin', 'chat-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mission = fn(string $title): int => (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $typeId]
        );
        $this->missionId = $mission('Chat Summary Mission');
        $this->otherMissionId = $mission('Chat Summary Other Mission');
        $team = fn(int $missionId, string $name, int $n): int => (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId, $name, $n, $this->adminId]
        );
        $this->alphaId = $team($this->missionId, 'Alpha', 1);
        $this->bravoId = $team($this->missionId, 'Bravo', 2);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function say(int $missionId, ?int $teamId, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            dbInsert(
                "INSERT INTO mission_chat_messages (mission_id, team_id, user_id, message) VALUES (?, ?, ?, ?)",
                [$missionId, $teamId, $this->adminId, 'hello ' . $i]
            );
        }
    }

    public function testAMissionWithNoChatHasNoRooms(): void
    {
        $this->assertSame([], loadChatRoomSummary($this->missionId));
    }

    public function testTheGeneralRoomAndEachTeamRoomAreCountedSeparately(): void
    {
        $this->say($this->missionId, null, 3);
        $this->say($this->missionId, $this->alphaId, 2);
        $this->say($this->missionId, $this->bravoId, 1);

        $this->assertSame([
            'general' => ['n' => 3],
            (string) $this->alphaId => ['n' => 2],
            (string) $this->bravoId => ['n' => 1],
        ], loadChatRoomSummary($this->missionId));
    }

    public function testARoomNobodyHasWrittenInIsAbsentRatherThanZero(): void
    {
        $this->say($this->missionId, $this->bravoId, 1);

        $rooms = loadChatRoomSummary($this->missionId);
        $this->assertArrayNotHasKey('general', $rooms);
        $this->assertArrayNotHasKey((string) $this->alphaId, $rooms);
        $this->assertSame(['n' => 1], $rooms[(string) $this->bravoId]);
    }

    public function testAnotherMissionsMessagesNeverLeakIn(): void
    {
        $this->say($this->otherMissionId, null, 5);
        $this->say($this->missionId, null, 1);

        $this->assertSame(['general' => ['n' => 1]], loadChatRoomSummary($this->missionId));
    }

    public function testADeletedMessageLowersTheCount(): void
    {
        $this->say($this->missionId, null, 2);
        dbExecute("DELETE FROM mission_chat_messages WHERE mission_id = ? ORDER BY id LIMIT 1", [$this->missionId]);

        $this->assertSame(['general' => ['n' => 1]], loadChatRoomSummary($this->missionId));
    }
}
