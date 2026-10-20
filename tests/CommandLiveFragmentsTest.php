<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The shift list, the visitors lists and the open-items warning on «Κλείσιμο
 * αποστολής» ride the five-second poll as ready-made markup (v3.393.0).
 *
 * They used to be drawn once by PHP at page load. The one that mattered was
 * the warning: an admin who left the Action Room open for an hour was shown,
 * at the very moment they pressed «Κλείσιμο», a list of open incidents,
 * shortages and SOS that was an hour old - and closing is the only door out,
 * since nothing can resolve those rows afterwards.
 *
 * What is pinned, and why:
 *  - the page and the poll produce the SAME markup and the same signature, or
 *    every first poll would redraw every card for nothing;
 *  - the warning and the confirm() text are one thing, both escaped for the
 *    context they land in, and they change when an item is resolved;
 *  - the loaders count and classify correctly (against the test database, in
 *    a transaction that is always rolled back);
 *  - the page and the poll agree on the fragment keys, and a volunteer's poll
 *    carries none of this.
 */
final class CommandLiveFragmentsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test-token';
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    private function incident(string $team = 'ΑΛΦΑ'): array
    {
        return ['is_unknown_patient' => 0, 'patient_name' => 'Μαρία', 'type_label' => 'Πτώση', 'team_label' => $team];
    }

    /** The raw confirm() text a rendered close panel carries, as the browser would read it. */
    private function confirmText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-confirm="([^"]*)"/', $html, $m), 'the form carries its confirm text');
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    // --- the fragment wrapper ---------------------------------------------

    public function testPageAndPollAgreeOnTheSignature(): void
    {
        $html = '<p>ένα κομμάτι</p>';
        $wrapper = warRoomLiveFragment('missionShifts', $html, 'list-group list-group-flush');
        $payload = warRoomLiveFragmentPayload($html);

        $this->assertStringContainsString('data-live-fragment="missionShifts"', $wrapper);
        $this->assertStringContainsString('class="list-group list-group-flush"', $wrapper);
        $this->assertStringContainsString('data-sig="' . $payload['sig'] . '"', $wrapper);
        $this->assertSame(md5($html), $payload['sig']);
        $this->assertSame($html, $payload['html']);
        $this->assertStringContainsString($html, $wrapper);
    }

    public function testWrapperWithoutAClassHasNoClassAttribute(): void
    {
        $this->assertStringNotContainsString('class=', warRoomLiveFragment('k', 'x'));
    }

    // --- «Κλείσιμο αποστολής» --------------------------------------------

    public function testNothingOpenMeansNoWarningAndAPlainConfirm(): void
    {
        $html = renderMissionClosePanel([], [], []);

        $this->assertStringNotContainsString('alert-warning', $html);
        $this->assertSame(t('admin.close_confirm'), $this->confirmText($html));
        $this->assertStringContainsString('name="action" value="close_mission"', $html);
        $this->assertStringContainsString('value="test-token"', $html);
        $this->assertStringContainsString('onsubmit="return confirm(this.dataset.confirm)"', $html);
    }

    public function testEveryOpenItemIsListedInTheWarningAndInTheConfirm(): void
    {
        $incidents = [$this->incident()];
        $shortages = [['type_label' => 'Νερό', 'title' => 'Τελείωσε', 'team_label' => 'ΒΗΤΑ']];
        $sos = [['user_name' => 'Γιώργος', 'team_label' => 'ΓΑΜΜΑ']];

        $html = renderMissionClosePanel($incidents, $shortages, $sos);
        $confirm = $this->confirmText($html);

        $this->assertStringContainsString('alert-warning', $html);
        foreach (['Πτώση — Μαρία (ΑΛΦΑ)', 'Νερό: Τελείωσε (ΒΗΤΑ)', 'SOS — Γιώργος (ΓΑΜΜΑ)'] as $line) {
            $this->assertStringContainsString('<li>' . $line . '</li>', $html);
            $this->assertStringContainsString("\n- " . $line, $confirm);
        }
        $warning = t('admin.close_open_items_warning', ['incidents' => 1, 'shortages' => 1, 'sos' => 1]);
        $this->assertStringStartsWith($warning, $confirm);
        $this->assertStringEndsWith(t('admin.close_confirm'), $confirm);
    }

    public function testAnUnknownPatientIsNamedAsSuch(): void
    {
        $unknown = $this->incident();
        $unknown['is_unknown_patient'] = 1;

        $html = renderMissionClosePanel([$unknown], [], []);

        $this->assertStringContainsString(t('incident.unknown_patient_label'), $html);
        $this->assertStringNotContainsString('Μαρία', $html);
    }

    public function testNamesAreEscapedForTheListAndForTheAttribute(): void
    {
        $html = renderMissionClosePanel([$this->incident('<b>"x"</b>')], [], []);

        $this->assertStringNotContainsString('<b>"x"</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', $html);
        // The attribute must not be cut short by the quote inside the name,
        // and the browser must read the original text back out of it.
        $this->assertStringContainsString('<b>"x"</b>', $this->confirmText($html));
    }

    public function testTheSignatureMovesWhenAnItemIsResolved(): void
    {
        $withItem = warRoomLiveFragmentPayload(renderMissionClosePanel([$this->incident()], [], []));
        $resolved = warRoomLiveFragmentPayload(renderMissionClosePanel([], [], []));
        $same = warRoomLiveFragmentPayload(renderMissionClosePanel([$this->incident()], [], []));

        $this->assertNotSame($withItem['sig'], $resolved['sig']);
        $this->assertSame($withItem['sig'], $same['sig'], 'an unchanged mission must not redraw');
    }

    // --- shifts -----------------------------------------------------------

    public function testShiftRowsShowTheApprovedCount(): void
    {
        $html = renderMissionShiftRows([
            ['start_time' => '2026-10-20 08:00:00', 'end_time' => '2026-10-20 12:30:00', 'approved_count' => 3, 'max_volunteers' => 10],
            ['start_time' => '2026-10-20 13:00:00', 'end_time' => '2026-10-20 17:00:00', 'approved_count' => 0, 'max_volunteers' => 5],
        ]);

        $this->assertSame(2, substr_count($html, 'list-group-item'));
        $this->assertStringContainsString(t('hero.until') . ' 12:30 · 3/10 ' . t('shifts.approved_count_suffix'), $html);
        $this->assertStringContainsString(t('hero.until') . ' 17:00 · 0/5 ' . t('shifts.approved_count_suffix'), $html);
        $this->assertSame('', renderMissionShiftRows([]));
    }

    // --- visitors ---------------------------------------------------------

    public function testPendingVisitorsGetOneButtonPerTag(): void
    {
        $html = renderMissionVisitorLists(
            [['id' => 11, 'name' => '<i>Νίκος</i>', 'phone' => '6900000000']],
            [],
            [
                ['id' => 1, 'label' => 'ΕΜΑΚ', 'color' => '#112233', 'icon' => 'bi-shield'],
                ['id' => 2, 'label' => 'Δήμος', 'color' => '#445566', 'icon' => 'bi-building'],
            ]
        );

        $this->assertSame(2, substr_count($html, 'value="classify_visitor"'));
        $this->assertStringContainsString('name="user_id" value="11"', $html);
        $this->assertStringContainsString('name="tag_id" value="1"', $html);
        $this->assertStringContainsString('name="tag_id" value="2"', $html);
        $this->assertStringNotContainsString('<i>Νίκος</i>', $html);
        $this->assertStringContainsString('&lt;i&gt;Νίκος&lt;/i&gt;', $html);
        $this->assertStringContainsString(t('visitor.active_empty'), $html);
        $this->assertStringNotContainsString(t('visitor.pending_empty'), $html);
    }

    public function testActiveVisitorsCanBeRemoved(): void
    {
        $html = renderMissionVisitorLists(
            [],
            [['id' => 21, 'name' => 'Ελένη', 'phone' => '6911111111', 'tag_label' => 'ΕΜΑΚ', 'tag_color' => '#112233']],
            []
        );

        $this->assertStringContainsString(t('visitor.pending_empty'), $html);
        $this->assertStringContainsString('value="remove_visitor"', $html);
        $this->assertStringContainsString('name="user_id" value="21"', $html);
        $this->assertStringContainsString('ΕΜΑΚ', $html);
    }

    // --- the loaders, against the test database ---------------------------

    private function makeMission(): int
    {
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        return (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Live fragments mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
    }

    private function makeUser(string $name, array $extra = []): int
    {
        $columns = array_merge(['name' => $name, 'email' => 'clf-' . uniqid('', true) . '@example.invalid', 'password' => 'x'], $extra);
        return (int) dbInsert(
            "INSERT INTO users (" . implode(', ', array_keys($columns)) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
            array_values($columns)
        );
    }

    public function testShiftCountsFollowApprovalsAsTheyHappen(): void
    {
        $missionId = $this->makeMission();
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers) VALUES (?, ?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), 8]
        );

        $this->assertSame(0, (int) loadMissionShiftsWithCounts($missionId)[0]['approved_count']);

        $volunteerId = $this->makeUser('Βάρδια Α.');
        $requestId = (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $volunteerId, PARTICIPATION_PENDING]
        );
        $row = loadMissionShiftsWithCounts($missionId)[0];
        $this->assertSame(0, (int) $row['approved_count']);
        $this->assertSame(1, (int) $row['pending_count']);

        dbExecute("UPDATE participation_requests SET status = ? WHERE id = ?", [PARTICIPATION_APPROVED, $requestId]);
        $row = loadMissionShiftsWithCounts($missionId)[0];
        $this->assertSame(1, (int) $row['approved_count']);
        $this->assertStringContainsString('1/8', renderMissionShiftRows([$row]));
    }

    public function testVisitorsAreSplitIntoWaitingAndClassified(): void
    {
        $missionId = $this->makeMission();
        $otherMissionId = $this->makeMission();
        $tagId = (int) dbFetchValue("SELECT id FROM mission_visitor_tags WHERE is_active = 1 ORDER BY sort_order LIMIT 1");
        $this->assertGreaterThan(0, $tagId, 'the schema seeds at least one visitor tag');

        $visitor = fn(string $name, int $mission, ?int $tag, int $active = 1) => $this->makeUser($name, [
            'is_mission_visitor' => 1, 'mission_visitor_mission_id' => $mission,
            'mission_visitor_tag_id' => $tag, 'is_active' => $active,
        ]);
        $waiting = $visitor('Αναμονή', $missionId, null);
        $classified = $visitor('Ταξινομημένος', $missionId, $tagId);
        $visitor('Άλλη αποστολή', $otherMissionId, null);
        $visitor('Ανενεργός', $missionId, null, 0);

        $loaded = loadMissionVisitorsForCard($missionId);

        $this->assertSame([$waiting], array_map(fn($r) => (int) $r['id'], $loaded['pending']));
        $this->assertSame([$classified], array_map(fn($r) => (int) $r['id'], $loaded['active']));
        $this->assertNotEmpty($loaded['tags'], 'somebody is waiting, so the tags to give them are loaded');

        $other = loadMissionVisitorsForCard($otherMissionId);
        $this->assertCount(1, $other['pending'], 'a visitor waiting on another mission belongs to that mission');

        $none = loadMissionVisitorsForCard($this->makeMission());
        $this->assertSame([], $none['pending']);
        $this->assertSame([], $none['tags'], 'nobody is waiting, so the tags are not read');
    }

    public function testThePollCarriesTheVisitorsFragmentOnlyWhileThereIsALink(): void
    {
        $missionId = $this->makeMission();

        $without = buildCommandLiveFragments($missionId, false, [], [], []);
        $with = buildCommandLiveFragments($missionId, true, [], [], []);

        $this->assertSame(['missionClose', 'missionShifts'], array_keys($without));
        $this->assertSame(['missionClose', 'missionShifts', 'missionVisitors'], array_keys($with));
        foreach ($with as $fragment) {
            $this->assertSame(md5($fragment['html']), $fragment['sig']);
        }
    }

    // --- briefing links -----------------------------------------------------

    private function team(int $id, string $codename, int $number, ?string $token, ?string $color = '#2563eb'): array
    {
        return ['id' => $id, 'codename' => $codename, 'team_number' => $number, 'color' => $color, 'briefing_token' => $token];
    }

    public function testBriefingLinksSayWhenThereAreNoTeamsYet(): void
    {
        $html = renderBriefingTeamLinks([]);

        $this->assertStringContainsString(t('briefing.no_teams_yet'), $html);
        $this->assertStringNotContainsString('wr-briefing-copy-btn', $html);
    }

    public function testBriefingLinksCarryOneLinkAndCopyButtonPerTeam(): void
    {
        $html = renderBriefingTeamLinks([
            $this->team(1, 'ΑΛΦΑ', 1, str_repeat('a', 64)),
            $this->team(2, 'ΒΗΤΑ', 2, str_repeat('b', 64)),
        ]);

        $this->assertSame(2, substr_count($html, 'wr-briefing-copy-btn'));
        $this->assertStringContainsString('/briefing-view.php?token=' . str_repeat('a', 64) . '"', $html);
        $this->assertStringContainsString('data-copy-text="' . h(rtrim(BASE_URL, '/') . '/briefing-view.php?token=' . str_repeat('b', 64)) . '"', $html);
        $this->assertStringContainsString(h(teamLabel('ΑΛΦΑ', 1)), $html);
        $this->assertStringNotContainsString(t('briefing.no_teams_yet'), $html);
    }

    public function testBriefingLinksEscapeTheTeamName(): void
    {
        $html = renderBriefingTeamLinks([$this->team(1, '<b>X</b>', 1, str_repeat('c', 64))]);

        $this->assertStringNotContainsString('<b>X</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $html);
    }

    public function testANewTeamChangesTheBriefingFragment(): void
    {
        $one = [$this->team(1, 'ΑΛΦΑ', 1, str_repeat('a', 64))];
        $two = array_merge($one, [$this->team(2, 'ΒΗΤΑ', 2, str_repeat('b', 64))]);

        $this->assertNotSame(
            warRoomLiveFragmentPayload(renderBriefingTeamLinks($one))['sig'],
            warRoomLiveFragmentPayload(renderBriefingTeamLinks($two))['sig']
        );
    }

    private function makeTeam(int $missionId, int $userId, int $number, ?string $token): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by, briefing_token) VALUES (?, ?, ?, ?, ?)",
            [$missionId, 'ΤΕΣΤ', $number, $userId, $token]
        );
    }

    public function testATeamWithoutATokenGetsOneAndKeepsIt(): void
    {
        $missionId = $this->makeMission();
        $userId = $this->makeUser('Briefing Admin');
        $teamId = $this->makeTeam($missionId, $userId, 1, null);

        $filled = ensureTeamBriefingTokens(loadMissionTeamsForMission($missionId));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $filled[$teamId]['briefing_token'], 'the page\'s id-keyed list keeps its keys');
        $this->assertSame($filled[$teamId]['briefing_token'], dbFetchValue("SELECT briefing_token FROM mission_teams WHERE id = ?", [$teamId]));

        $again = ensureTeamBriefingTokens(array_values(loadMissionTeamsForMission($missionId)));
        $this->assertSame($filled[$teamId]['briefing_token'], $again[0]['briefing_token'], 'a team that already has a token is left alone');
    }

    public function testALosingPollAdoptsTheWinnersToken(): void
    {
        $missionId = $this->makeMission();
        $userId = $this->makeUser('Briefing Admin 2');
        $teamId = $this->makeTeam($missionId, $userId, 1, null);
        $stale = array_values(loadMissionTeamsForMission($missionId));
        $this->assertEmpty($stale[0]['briefing_token']);

        // Another admin's poll fills the gap between this one's read and write.
        $winner = str_repeat('f', 64);
        dbExecute("UPDATE mission_teams SET briefing_token = ? WHERE id = ?", [$winner, $teamId]);

        $result = ensureTeamBriefingTokens($stale);

        $this->assertSame($winner, $result[0]['briefing_token']);
        $this->assertSame($winner, dbFetchValue("SELECT briefing_token FROM mission_teams WHERE id = ?", [$teamId]));
    }

    public function testThePollCarriesTheBriefingFragmentOnlyOnASpecialMission(): void
    {
        $missionId = $this->makeMission();

        $normal = buildCommandLiveFragments($missionId, false, [], [], [], null);
        $special = buildCommandLiveFragments($missionId, false, [], [], [], []);

        $this->assertArrayNotHasKey('missionBriefing', $normal);
        $this->assertArrayHasKey('missionBriefing', $special);
        $this->assertSame(md5($special['missionBriefing']['html']), $special['missionBriefing']['sig']);
    }

    // --- wiring (source pins; the gate needs a signed-in session) ----------

    public function testThePolledTeamsNoLongerCarryTheBriefingToken(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringContainsString("'teams' => array_map(fn(\$team) => array_diff_key(\$team, ['briefing_token' => true]), \$teams),", $page);
        // And nothing in the browser was reading it from there.
        $this->assertStringNotContainsString('.briefing_token', $page);
    }

    public function testTheCopyButtonSurvivesTheFragmentBeingReplaced(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringNotContainsString("querySelectorAll('.wr-briefing-copy-btn')", $page, 'per-button listeners die when the poll replaces the list');
        $this->assertStringContainsString("e.target.closest('.wr-briefing-copy-btn')", $page);
    }


    public function testThePageAndThePollAgreeOnTheFragmentKeys(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');
        $builder = file_get_contents(__DIR__ . '/../includes/functions-warroom.php');

        preg_match_all("/warRoomLiveFragment\\('(\\w+)'/", $page, $onPage);
        // Both spellings the builder uses: inside the array literal, and the
        // conditional `$fragments['key'] = ...` one.
        preg_match_all("/'(\\w+)'(?:\\]\\s*=|\\s*=>)\\s*warRoomLiveFragmentPayload\\(/", $builder, $inPoll);
        $pageKeys = array_unique($onPage[1]);
        $pollKeys = array_unique($inPoll[1]);
        sort($pageKeys);
        sort($pollKeys);

        $this->assertSame(['missionBriefing', 'missionClose', 'missionShifts', 'missionVisitors'], $pageKeys);
        $this->assertSame($pageKeys, $pollKeys, 'a key on only one side is a card that is never redrawn, or a payload nobody reads');
    }

    public function testOnlyCommandStaffReceiveTheFragmentsAndTheBrowserAppliesThem(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertMatchesRegularExpression(
            "/'liveFragments' => \\\$canManageWarRoom\\s*\\?\\s*buildCommandLiveFragments\\(.*?\\)\\s*:\\s*null,/s",
            $page
        );
        $this->assertStringContainsString('function applyLiveFragments(fragments)', $page);
        $this->assertStringContainsString('if (data.liveFragments) applyLiveFragments(data.liveFragments);', $page);
    }
}
