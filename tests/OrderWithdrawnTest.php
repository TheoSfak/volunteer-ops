<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An order command takes back — a dispatched point or area deleted, a sector
 * deleted or taken off its team — used to vanish from the volunteer's screen
 * without a word. Someone already walking to the point had no way to know it
 * no longer stood. They are now told, the way a cancelled route already was.
 *
 * What these tests pin is WHO is told: exactly the people the order went to
 * whose part is still under way. Not the team that already finished it or said
 * «Δεν μπορώ» (command tidying the map is not news to them), not another team,
 * not the person doing the deleting.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class OrderWithdrawnTest extends TestCase
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

        $this->adminId = $this->makeUser('Withdraw Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Withdraw Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
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
            [$name, 'ow-' . uniqid('', true) . '@example.invalid', 'x']
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

    private function sendPoint(?int $teamId, ?string $label = 'Πηγή', string $type = 'point'): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $teamId, $type, json_encode(['lat' => 35.2, 'lng' => 24.9]), $label, $this->adminId]
        );
    }

    private function sector(int $teamId, string $status, string $label = 'Α3'): int
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)",
            [$this->missionId, $areaId, $teamId, $label, json_encode([[35.1, 25.1], [35.15, 25.1], [35.15, 25.15]]), $status, $this->adminId]
        );
    }

    private function completeDispatch(int $dispatchId, int $teamId): void
    {
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, departed_at, arrived_at, completed_at) VALUES (?, ?, ?, NOW(), NOW(), NOW())",
            [$dispatchId, $teamId, dispatchProgressScopeKey($teamId, $this->member)]
        );
    }

    /** The team still on its way is told — both members, nobody else. */
    public function testDeletedPointReachesTheTeamStillOnItsWay(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $lines = withdrawnDispatchLines($this->missionId, [$dispatchId], $this->adminId);

        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], array_keys($lines));
        $this->assertSame([['order.withdrawn.dispatch_point', ['label' => 'Πηγή']]], $lines[$this->member]);
    }

    /** A team already moving still counts: it is exactly who has to stop. */
    public function testATeamOnTheMoveIsStillTold(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, departed_at) VALUES (?, ?, ?, NOW())",
            [$dispatchId, $this->teamId, dispatchProgressScopeKey($this->teamId, $this->member)]
        );

        $this->assertCount(2, withdrawnDispatchLines($this->missionId, [$dispatchId], $this->adminId));
    }

    /** Finished — completed, or «Δεν μπορώ» — has nothing to stop. */
    public function testATeamThatFinishedOrDeclinedIsNotTold(): void
    {
        $completed = $this->sendPoint($this->teamId);
        $this->completeDispatch($completed, $this->teamId);
        $this->assertSame([], withdrawnDispatchLines($this->missionId, [$completed], $this->adminId));

        $declined = $this->sendPoint($this->teamId);
        $name = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$this->member]);
        $this->assertNull(declineMissionOrder($this->mission(), 'dispatch', $declined, $this->member, $name, 'no_access', null, true));
        $this->assertSame([], withdrawnDispatchLines($this->missionId, [$declined], $this->adminId));
    }

    /** A pre-v3.325.0 arrival was the end of the dispatch. */
    public function testALegacyArrivalCountsAsFinished(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);
        dbInsert("INSERT INTO mission_dispatch_acks (dispatch_id, team_id, user_id) VALUES (?, ?, ?)", [$dispatchId, $this->teamId, $this->member]);

        $this->assertSame([], withdrawnDispatchLines($this->missionId, [$dispatchId], $this->adminId));
    }

    /** Sent to every team: each team's part is its own. */
    public function testADispatchToAllTeamsCountsEachTeamOnItsOwn(): void
    {
        $dispatchId = $this->sendPoint(null, null, 'polygon');
        $this->completeDispatch($dispatchId, $this->teamId);

        $lines = withdrawnDispatchLines($this->missionId, [$dispatchId], $this->adminId);

        $this->assertSame([$this->stranger], array_keys($lines), 'ΑΛΦΑ finished its part; only ΒΗΤΑ is still on it.');
        $this->assertSame('order.withdrawn.dispatch_area_nolabel', $lines[$this->stranger][0][0]);
    }

    /** Whoever deletes it knows already. */
    public function testThePersonDeletingItIsNotTold(): void
    {
        $dispatchId = $this->sendPoint($this->teamId);

        $lines = withdrawnDispatchLines($this->missionId, [$dispatchId], $this->member);

        $this->assertSame([$this->teammate], array_keys($lines));
    }

    /** Deleted or taken off the team: the team is told, with the right words. */
    public function testASectorUnderWayIsReportedToItsTeamOnly(): void
    {
        $sectorId = $this->sector($this->teamId, 'in_progress', 'Β7');

        $deleted = withdrawnSectorLines($this->missionId, [$sectorId], $this->adminId);
        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], array_keys($deleted));
        $this->assertSame([['order.withdrawn.sector', ['label' => 'Β7']]], $deleted[$this->member]);

        $unassigned = withdrawnSectorLines($this->missionId, [$sectorId], $this->adminId, true);
        $this->assertSame('order.withdrawn.sector_unassigned', $unassigned[$this->member][0][0]);
    }

    /** Never sent, already done, declined, or on nobody: nobody to tell. */
    public function testASectorWithNothingUnderWayTellsNobody(): void
    {
        $this->assertSame([], withdrawnSectorLines($this->missionId, [$this->sector($this->teamId, 'completed')], $this->adminId));
        $this->assertSame([], withdrawnSectorLines($this->missionId, [$this->sector($this->teamId, 'not_started')], $this->adminId));

        $declined = $this->sector($this->teamId, 'assigned');
        $name = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$this->member]);
        $this->assertNull(declineMissionOrder($this->mission(), 'sector', $declined, $this->member, $name, 'no_access', null, true));
        $this->assertSame([], withdrawnSectorLines($this->missionId, [$declined], $this->adminId));

        $unowned = $this->sector($this->teamId, 'assigned');
        dbExecute("UPDATE mission_search_sectors SET team_id = NULL WHERE id = ?", [$unowned]);
        $this->assertSame([], withdrawnSectorLines($this->missionId, [$unowned], $this->adminId));
    }

    /**
     * One notice per person, however many orders went at once, and it is the
     * order popup's notice — not a ticker line, not an order to acknowledge.
     */
    public function testOneNoticePerPersonThatOpensAsThePopupNotice(): void
    {
        $first = $this->sector($this->teamId, 'assigned', 'Γ1');
        $second = $this->sector($this->teamId, 'en_route', 'Γ2');
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");

        notifyOrdersWithdrawn($this->missionId, 'Withdraw Mission', withdrawnSectorLines($this->missionId, [$first, $second], $this->adminId), 'mission_sector_assigned');

        $rows = dbFetchAll("SELECT user_id, title, message, data FROM notifications WHERE id > ? ORDER BY id", [$before]);
        $this->assertEqualsCanonicalizing([$this->member, $this->teammate], array_map('intval', array_column($rows, 'user_id')));
        $lang = getUserLanguage($this->member);
        $this->assertSame(t('order.withdrawn_title', [], $lang), $rows[0]['title']);
        $this->assertStringContainsString('Γ1', $rows[0]['message']);
        $this->assertStringContainsString('Γ2', $rows[0]['message']);
        $this->assertStringContainsString('Withdraw Mission', $rows[0]['message']);

        $data = json_decode($rows[0]['data'], true);
        $this->assertSame($this->missionId, $data['bannerMission'], 'Operational: a banner on the page and an alert on the phone.');
        $ref = notificationPopupRef($data);
        $this->assertSame('info', $ref['kind']);
        $this->assertSame('mission_order_withdrawn', $ref['info']);
    }

    /** A whole ring cleared at once is a list, cut short rather than a wall. */
    public function testALongListIsCutShort(): void
    {
        $lines = [];
        for ($i = 1; $i <= 7; $i++) {
            $lines[$this->member][] = ['order.withdrawn.sector', ['label' => 'Ζ' . $i]];
        }
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");

        notifyOrdersWithdrawn($this->missionId, 'Withdraw Mission', $lines, 'mission_sector_assigned');

        $message = (string) dbFetchValue("SELECT message FROM notifications WHERE id > ? AND user_id = ?", [$before, $this->member]);
        $this->assertStringContainsString('Ζ5', $message);
        $this->assertStringNotContainsString('Ζ6', $message);
        $this->assertStringContainsString(t('order.withdrawn_more', ['n' => 2], getUserLanguage($this->member)), $message);
    }

    public function testNothingToTellSendsNothing(): void
    {
        $before = (int) dbFetchValue("SELECT COALESCE(MAX(id), 0) FROM notifications");
        notifyOrdersWithdrawn($this->missionId, 'Withdraw Mission', [], 'mission_dispatch_point');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE id > ?", [$before]));
    }
}
