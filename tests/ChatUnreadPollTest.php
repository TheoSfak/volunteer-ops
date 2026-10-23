<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The unread chat badges of the phone layout ride the main war-room poll (v3.405.0).
 *
 * They used to be one mission-chat.php request per chat room the person was NOT looking at, every 5 seconds,
 * each answered with an empty list: measured on the live host that was about 3.8 KB over the air per request
 * (the response headers alone are 1.4 KB and the server closes an idle connection after ~2 s, so each one also
 * opens a new TLS connection), and a quarter of everything a phone with the screen on exchanges.
 *
 * What is pinned here: which rooms a person may be told about, how a chat_after parameter is read, what the
 * answer says (the newest message id of a room, and how many are newer than the phone's cursor), and that the
 * page no longer asks for the other rooms one by one. The behaviour in a browser was checked on a phone-sized
 * page; these keep an edit from quietly undoing it.
 *
 * The database tests run inside a transaction that is always rolled back.
 */
final class ChatUnreadPollTest extends TestCase
{
    private int $userId;
    private int $missionId;
    private int $otherMissionId;
    private int $alphaId;
    private int $bravoId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Chat Unread User', 'chatunread-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mission = fn(string $title): int => (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $typeId]
        );
        $this->missionId = $mission('Chat Unread Mission');
        $this->otherMissionId = $mission('Chat Unread Other Mission');
        $team = fn(int $missionId, string $name, int $n): int => (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId, $name, $n, $this->userId]
        );
        $this->alphaId = $team($this->missionId, 'Alpha', 1);
        $this->bravoId = $team($this->missionId, 'Bravo', 2);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    /** @return int[] the ids of the messages written, oldest first */
    private function say(int $missionId, ?int $teamId, int $times = 1): array
    {
        $ids = [];
        for ($i = 0; $i < $times; $i++) {
            $ids[] = (int) dbInsert(
                "INSERT INTO mission_chat_messages (mission_id, team_id, user_id, message) VALUES (?, ?, ?, ?)",
                [$missionId, $teamId, $this->userId, 'hello ' . $i]
            );
        }
        return $ids;
    }

    private function teams(): array
    {
        return [
            ['id' => $this->alphaId, 'members' => [['user_id' => 11], ['user_id' => 12]]],
            ['id' => $this->bravoId, 'members' => [['user_id' => 21]]],
        ];
    }

    // ── which rooms a person may be told about ───────────────────────────────

    public function testCommandStaffAreToldAboutEveryTeamRoom(): void
    {
        $this->assertSame([$this->alphaId, $this->bravoId], chatRoomTeamIdsFor($this->teams(), 999, true));
    }

    public function testAMemberIsToldAboutTheirOwnTeamRoomOnly(): void
    {
        $this->assertSame([$this->bravoId], chatRoomTeamIdsFor($this->teams(), 21, false));
        $this->assertSame([$this->alphaId], chatRoomTeamIdsFor($this->teams(), 12, false));
    }

    public function testSomeoneOnNoTeamGetsTheGeneralRoomOnly(): void
    {
        $this->assertSame([], chatRoomTeamIdsFor($this->teams(), 777, false));
    }

    public function testMemberIdsAsStringsStillMatch(): void
    {
        // The roster comes out of the database; the comparison must not depend on the driver's int/string choice.
        $teams = [['id' => (string) $this->alphaId, 'members' => [['user_id' => '12']]]];
        $this->assertSame([$this->alphaId], chatRoomTeamIdsFor($teams, 12, false));
    }

    // ── the chat_after parameter ─────────────────────────────────────────────

    public function testCursorsAreReadPerRoom(): void
    {
        $this->assertSame(['general' => 120, '141' => 300], parseChatCursors('general:120,141:300'));
    }

    public function testAnEmptyOrMissingParameterIsNoCursors(): void
    {
        $this->assertSame([], parseChatCursors(''));
        $this->assertSame([], parseChatCursors(null));
    }

    public function testAnythingThatDoesNotLookLikeACursorIsDropped(): void
    {
        $this->assertSame(['general' => 5], parseChatCursors("general:5,abc:1,7:x,8,:9,'; DROP TABLE users;--:1,141:-3"));
    }

    public function testAHandMadeUrlCannotAskForMoreThanFortyRooms(): void
    {
        $parts = [];
        for ($i = 1; $i <= 100; $i++) {
            $parts[] = $i . ':1';
        }
        $this->assertCount(40, parseChatCursors(implode(',', $parts)));
    }

    // ── what the answer says ─────────────────────────────────────────────────

    public function testWithoutCursorsEachRoomOnlyReportsItsNewestMessage(): void
    {
        $general = $this->say($this->missionId, null, 3);
        $alpha = $this->say($this->missionId, $this->alphaId, 2);

        $rooms = loadChatRoomUnread($this->missionId, [$this->alphaId], []);

        $this->assertSame(['last' => end($general)], $rooms['general']);
        $this->assertSame(['last' => end($alpha)], $rooms[(string) $this->alphaId]);
    }

    public function testACursorTurnsNewerMessagesIntoACount(): void
    {
        $general = $this->say($this->missionId, null, 4);

        $rooms = loadChatRoomUnread($this->missionId, [], ['general' => $general[1]]);

        $this->assertSame(['last' => $general[3], 'new' => 2], $rooms['general']);
    }

    public function testNothingNewMeansNoNewKey(): void
    {
        $general = $this->say($this->missionId, null, 2);

        $rooms = loadChatRoomUnread($this->missionId, [], ['general' => $general[1]]);

        $this->assertSame(['last' => $general[1]], $rooms['general']);
    }

    public function testATeamRoomIsCountedByItsOwnCursor(): void
    {
        $this->say($this->missionId, null, 5);
        $alpha = $this->say($this->missionId, $this->alphaId, 3);

        $rooms = loadChatRoomUnread($this->missionId, [$this->alphaId], [(string) $this->alphaId => $alpha[0]]);

        $this->assertSame(['last' => $alpha[2], 'new' => 2], $rooms[(string) $this->alphaId]);
        $this->assertArrayNotHasKey('new', $rooms['general'], 'no cursor was sent for the general room');
    }

    public function testARoomThePersonCannotReadIsNeverMentioned(): void
    {
        $this->say($this->missionId, $this->alphaId, 2);
        $bravo = $this->say($this->missionId, $this->bravoId, 2);

        // Even when a hand-made URL names Bravo's room, the answer is built from the rooms the person may read.
        $rooms = loadChatRoomUnread($this->missionId, [$this->alphaId], [(string) $this->bravoId => $bravo[0]]);

        $this->assertArrayNotHasKey((string) $this->bravoId, $rooms);
        $this->assertArrayHasKey((string) $this->alphaId, $rooms);
    }

    public function testAnotherMissionsMessagesNeverLeakIn(): void
    {
        $this->say($this->otherMissionId, null, 5);
        $own = $this->say($this->missionId, null, 1);

        $this->assertSame(['general' => ['last' => $own[0]]], loadChatRoomUnread($this->missionId, [], []));
    }

    public function testAMissionWithNoChatHasNoRooms(): void
    {
        $this->assertSame([], loadChatRoomUnread($this->missionId, [$this->alphaId, $this->bravoId], []));
    }

    public function testADeletedNewestMessageDoesNotInventUnread(): void
    {
        $general = $this->say($this->missionId, null, 3);
        dbExecute("DELETE FROM mission_chat_messages WHERE id = ?", [$general[2]]);

        // The phone had counted up to the message that is now gone: nothing is newer than its cursor.
        $rooms = loadChatRoomUnread($this->missionId, [], ['general' => $general[2]]);

        $this->assertSame(['last' => $general[1]], $rooms['general']);
    }

    public function testAnEmptyTeamListStillAnswersForTheGeneralRoom(): void
    {
        $general = $this->say($this->missionId, null, 1);
        $this->say($this->missionId, $this->alphaId, 1);

        $this->assertSame(['general' => ['last' => $general[0]]], loadChatRoomUnread($this->missionId, [], []));
    }

    // ── the page ─────────────────────────────────────────────────────────────

    public function testThePageNoLongerAsksForTheOtherRoomsOneByOne(): void
    {
        $warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringNotContainsString('pollOtherRoomsForUnread()', $warRoom, 'the per-room poll is back');
        $this->assertStringNotContainsString('team_id=${otherTeamId}', $warRoom, 'a request per other room is back');
        $this->assertStringNotContainsString('function baselineRoom(teamId) {', $warRoom, 'the load-time history fetch per room is back');
    }

    public function testTheMainPollCarriesTheCursorsAndAppliesTheAnswer(): void
    {
        $warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringContainsString("'&chat_after=' + encodeURIComponent(chatCursors)", $warRoom);
        $this->assertStringContainsString('window.wrChat.apply(data.chatUnread)', $warRoom);
        // After the roster, so a room that appeared with it is baselined by the same answer.
        $this->assertLessThan(
            strpos($warRoom, 'window.wrChat.apply(data.chatUnread)'),
            strpos($warRoom, "document.dispatchEvent(new CustomEvent('wr-teams-updated'"),
            'chatUnread must be applied after the teams'
        );
    }

    public function testTheServerOnlyAnswersAPageThatAsked(): void
    {
        $warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertMatchesRegularExpression("~'chatUnread' => isset\(\\\$_GET\['chat_after'\]\)\s*\?\s*loadChatRoomUnread\(~", $warRoom);
    }
}
