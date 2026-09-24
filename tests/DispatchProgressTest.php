<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A dispatch point/area moves as a team: «Ξεκινάω», «Έφτασα» and
 * «Ολοκληρώθηκε» are pressed by whichever member gets there first, and that
 * press moves the whole team on (recordDispatchProgress). «Ελήφθη» stays per
 * person. Before v3.325.0 arrival was per person, so every member of a team
 * pressing it meant one arrival alarm each at the command post.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class DispatchProgressTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;
    private int $shiftId;
    /** @var int[] Three volunteers on $teamId. */
    private array $members = [];

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Dispatch Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Dispatch Progress Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );
        foreach (['Άννα Α.', 'Βασίλης Β.', 'Γιώργος Γ.'] as $name) {
            $id = $this->makeVolunteer($name);
            dbInsert(
                "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
                [$this->teamId, $this->missionId, $id]
            );
            $this->members[] = $id;
        }
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'dp-' . uniqid('', true) . '@example.invalid', 'x']
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

    private function sendDispatch(?int $teamId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => 35.1, 'lng' => 25.1]), 'Ρέμα', $this->adminId]
        );
    }

    /** What $userId's own Action Room shows for $dispatchId. */
    private function viewOf(int $userId, int $dispatchId): array
    {
        foreach (loadMissionDispatchesForUser($this->missionId, $userId, false, true) as $dispatch) {
            if ($dispatch['id'] === $dispatchId) {
                return $dispatch;
            }
        }
        $this->fail("Dispatch $dispatchId is not visible to user $userId");
    }

    public function testTheFirstMemberToPressMovesTheWholeTeam(): void
    {
        $dispatchId = $this->sendDispatch($this->teamId);
        [$anna, $vasilis] = $this->members;

        $this->assertSame(['depart'], recordDispatchProgress($dispatchId, $this->teamId, $anna, 'depart'));
        $this->assertSame([], recordDispatchProgress($dispatchId, $this->teamId, $vasilis, 'depart'),
            'A second member pressing the same step is not news: nothing is recorded, so nothing is announced.');

        $view = $this->viewOf($vasilis, $dispatchId);
        $this->assertNotNull($view['my_departed'], 'Βασίλης sees his team as set off, though Άννα pressed it.');
        $this->assertFalse($view['can_depart']);
        $this->assertTrue($view['can_ack'], 'The next team step is offered.');
        $this->assertNull($view['my_receipt'], '«Ελήφθη» stays per person.');
    }

    public function testASkippedStepIsBackfilledAndTheOrderFinishesAtCompletion(): void
    {
        $dispatchId = $this->sendDispatch($this->teamId);
        [$anna, , $giorgos] = $this->members;

        $this->assertSame(['depart', 'arrive', 'complete'], recordDispatchProgress($dispatchId, $this->teamId, $anna, 'complete'),
            'A team that only ever pressed «Ολοκληρώθηκε» has still set off and arrived.');

        $view = $this->viewOf($giorgos, $dispatchId);
        $this->assertNotNull($view['my_departed']);
        $this->assertNotNull($view['my_ack']);
        $this->assertNotNull($view['my_completed']);
        $this->assertFalse($view['can_depart']);
        $this->assertFalse($view['can_ack']);
        $this->assertFalse($view['can_complete']);
    }

    public function testAnArrivalFromBeforeTheProgressTableStillCountsForTheTeam(): void
    {
        $dispatchId = $this->sendDispatch($this->teamId);
        [$anna, $vasilis] = $this->members;
        dbInsert(
            "INSERT INTO mission_dispatch_acks (dispatch_id, team_id, user_id) VALUES (?, ?, ?)",
            [$dispatchId, $this->teamId, $anna]
        );

        $view = $this->viewOf($vasilis, $dispatchId);
        $this->assertNotNull($view['my_ack'], 'A per-person arrival row from before v3.325.0 is the team arriving.');
        $this->assertFalse($view['can_ack'], 'Nobody is asked to arrive again.');
        $this->assertFalse($view['can_depart']);
        $this->assertTrue($view['can_complete']);
    }

    public function testAVolunteerWithNoTeamMovesAlone(): void
    {
        $dispatchId = $this->sendDispatch(null);
        $loneA = $this->makeVolunteer('Χωρίς ομάδα Α');
        $loneB = $this->makeVolunteer('Χωρίς ομάδα Β');

        $this->assertSame(['depart'], recordDispatchProgress($dispatchId, null, $loneA, 'depart'));

        $this->assertNotNull($this->viewOf($loneA, $dispatchId)['my_departed']);
        $this->assertNull($this->viewOf($loneB, $dispatchId)['my_departed'],
            'Two volunteers with no team are not one team.');
        $this->assertNull($this->viewOf($this->members[0], $dispatchId)['my_departed'],
            'Nor does it move a team that was also sent the same dispatch.');
    }

    public function testTheAcknowledgementCardCarriesTheTeamLine(): void
    {
        $dispatchId = $this->sendDispatch($this->teamId);
        recordDispatchProgress($dispatchId, $this->teamId, $this->members[0], 'arrive');

        $card = null;
        foreach (loadAckTrackerCardsForMission($this->missionId) as $candidate) {
            if ($candidate['key'] === 'dispatch:' . $dispatchId) {
                $card = $candidate;
            }
        }
        $this->assertNotNull($card);
        $this->assertCount(1, $card['progress']);
        $this->assertSame(teamLabel('ΑΛΦΑ', 1), $card['progress'][0]['label']);
        $this->assertNotNull($card['progress'][0]['arrived']);
        $this->assertNull($card['progress'][0]['completed']);
    }

    public function testTheReportCountsEveryMemberAsArrivedWhenTheTeamDid(): void
    {
        $dispatchId = $this->sendDispatch($this->teamId);
        [$anna, $vasilis] = $this->members;
        foreach ([$anna, $vasilis] as $id) {
            dbInsert(
                "INSERT INTO mission_dispatch_receipts (dispatch_id, team_id, user_id) VALUES (?, ?, ?)",
                [$dispatchId, $this->teamId, $id]
            );
        }
        // What mission-dispatch.php does for «Έφτασα»: the team step, plus the
        // presser's own arrival row.
        recordDispatchProgress($dispatchId, $this->teamId, $anna, 'arrive');
        dbInsert(
            "INSERT INTO mission_dispatch_acks (dispatch_id, team_id, user_id) VALUES (?, ?, ?)",
            [$dispatchId, $this->teamId, $anna]
        );

        $report = computeMissionResponseReport($this->missionId);
        $rows = array_values(array_filter($report['detail'], fn($r) => $r['order_type'] === 'dispatch' && $r['user_id'] === $vasilis));
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['fulfill_at'],
            'Βασίλης was not offered «Έφτασα» once Άννα pressed it, so he must not read as never having arrived.');
    }
}
