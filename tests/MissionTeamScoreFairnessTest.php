<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The post-mission team leaderboard must not reward a team for having been
 * handed less work.
 *
 * A team that received ONE order and did it holds a perfect 100%; a team that
 * received nine and did eight holds 89%. The plain mean ranked the first above
 * the second, so the busiest, most reliable crew of the night came out below a
 * crew that was barely used. computeMissionScore() now weighs every pillar by
 * how many observations stand behind it (missionScoreEvidenceAdjust()), and
 * the narrative only names a team the best or the worst at one thing when it
 * has enough observations in that thing.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class MissionTeamScoreFairnessTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $order = 0;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Fairness Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Fairness Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 3600), $missionTypeId, STATUS_CLOSED]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 3600)]
        );
        forgetActionRoomParticipantIds($this->missionId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActionRoomParticipantIds($this->missionId);
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'fair-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** A team with one approved, Action-Room-ticked volunteer. Returns [teamId, userId]. */
    private function makeTeam(string $codename, int $number): array
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $this->adminId]
        );
        $userId = $this->makeUser('Μέλος ' . $codename);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $userId, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$teamId, $this->missionId, $userId]
        );
        setActionRoomParticipation($this->missionId, $userId, true, $this->adminId);
        return [$teamId, $userId];
    }

    /** One order to one person. $ack / $done are minutes after sending, null = never. */
    private function order(int $teamId, int $userId, ?int $ack, ?int $done, string $type = 'task', ?int $missionId = null): void
    {
        $sent = time() - 20 * 3600 + (++$this->order) * 60;
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by, created_at) VALUES (?, ?, ?, ?, ?)",
            [$missionId ?? $this->missionId, $type, 'Εντολή ' . $this->order, $this->adminId, date('Y-m-d H:i:s', $sent)]
        );
        dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id, team_id, acknowledged_at, fulfilled_at) VALUES (?, ?, ?, ?, ?)",
            [
                $orderId, $userId, $teamId,
                $ack !== null ? date('Y-m-d H:i:s', $sent + $ack * 60) : null,
                $done !== null ? date('Y-m-d H:i:s', $sent + $done * 60) : null,
            ]
        );
    }

    private function teamsByCodename(): array
    {
        $score = computeMissionScore($this->missionId);
        $out = [];
        foreach ($score['teams'] as $t) {
            $out[$t['codename']] = $t;
        }
        return $out;
    }

    // ── the arithmetic ───────────────────────────────────────────────────

    public function testTheWeightOfARecordGrowsWithItsLengthAndNeverReachesOne(): void
    {
        $this->assertSame(0.0, missionScoreEvidenceWeight(0));
        $this->assertEqualsWithDelta(0.25, missionScoreEvidenceWeight(1), 1e-9);
        $this->assertEqualsWithDelta(0.50, missionScoreEvidenceWeight(3), 1e-9);
        $this->assertEqualsWithDelta(0.75, missionScoreEvidenceWeight(9), 1e-9);
        $previous = 0.0;
        foreach ([1, 2, 5, 10, 50, 500] as $n) {
            $w = missionScoreEvidenceWeight($n);
            $this->assertGreaterThan($previous, $w);
            $this->assertLessThan(1.0, $w);
            $previous = $w;
        }
    }

    public function testOneForOneDoesNotBeatEightForNine(): void
    {
        $oneOfOne   = missionScoreEvidenceAdjust(1 / 1 * 100, 1);
        $eightOfNine = missionScoreEvidenceAdjust(8 / 9 * 100, 9);
        $this->assertGreaterThan($oneOfOne['adjusted'], $eightOfNine['adjusted'],
            'the team that kept 8/9 over nine orders must outrank the team that did its single order');
        // And the single success is not thrown away: it still beats nothing at all.
        $this->assertGreaterThan(MISSION_SCORE_EVIDENCE_BASELINE, $oneOfOne['adjusted']);
    }

    public function testTheAdjustmentIsSymmetricAFailureIsNotPunishedHarderThanASuccessIsRewarded(): void
    {
        $b = MISSION_SCORE_EVIDENCE_BASELINE;
        $win  = missionScoreEvidenceAdjust(100.0, 2);
        $loss = missionScoreEvidenceAdjust(0.0, 2);
        $w = missionScoreEvidenceWeight(2);
        // Each moves exactly the same fraction of the way to what it actually did.
        $this->assertEqualsWithDelta($w * (100.0 - $b), $win['adjusted'] - $b, 1e-9);
        $this->assertEqualsWithDelta($w * (0.0 - $b), $loss['adjusted'] - $b, 1e-9);
    }

    public function testAnExcellentRatingNeedsARecordNotJustAPerfectScore(): void
    {
        // 100 on one observation is only a "good", never an "excellent".
        $this->assertSame('warning', missionScoreTierMeta(missionScoreEvidenceAdjust(100.0, 1)['adjusted'])[0]);
        // 100 sustained over enough observations is.
        $this->assertSame('good', missionScoreTierMeta(missionScoreEvidenceAdjust(100.0, 12)['adjusted'])[0]);
    }

    public function testEvidenceLevelsFollowTheWeight(): void
    {
        $this->assertSame('low', missionScoreEvidenceLevel(missionScoreEvidenceWeight(1))[0]);
        $this->assertSame('medium', missionScoreEvidenceLevel(missionScoreEvidenceWeight(3))[0]);
        $this->assertSame('high', missionScoreEvidenceLevel(missionScoreEvidenceWeight(9))[0]);
    }

    // ── the leaderboard ──────────────────────────────────────────────────

    public function testTheTeamThatDidOneOrderDoesNotOutrankTheTeamThatKeptEightOfNine(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);

        $this->order($aTeam, $aUser, 2, 6);          // 1 / 1, answered in 2 minutes
        for ($i = 0; $i < 8; $i++) {
            $this->order($bTeam, $bUser, 3, 15);     // 8 done
        }
        $this->order($bTeam, $bUser, 3, null);       // 1 acknowledged, never completed

        $teams = $this->teamsByCodename();
        $alfa = $teams['ΑΛΦΑ'];
        $beta = $teams['ΒΗΤΑ'];

        // The mean alone says ΑΛΦΑ is the better team…
        $this->assertGreaterThan($beta['raw_score'], $alfa['raw_score']);
        $this->assertSame(1, $alfa['raw_rank']);
        // …the leaderboard does not.
        $this->assertSame(1, $beta['rank'], 'nine orders, eight kept, must come first');
        $this->assertSame(2, $alfa['rank']);
        $this->assertGreaterThan($alfa['score'], $beta['score']);

        $this->assertSame('low', $alfa['evidence_level']);
        $this->assertSame('high', $beta['evidence_level']);
        $this->assertSame(1, $alfa['evidence_n']);
        $this->assertSame(9, $beta['evidence_n']);
        $this->assertLessThan($alfa['raw_score'], $alfa['score'], 'the single success is weighed down');
        $this->assertSame(1, $alfa['pillars']['completion']['n']);
        $this->assertSame(9, $beta['pillars']['completion']['n']);
    }

    public function testTheRawNumbersAreKeptAlongsideTheAdjustedOnes(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        $this->order($aTeam, $aUser, 2, 6);

        $alfa = $this->teamsByCodename()['ΑΛΦΑ'];
        $completion = $alfa['pillars']['completion'];
        $this->assertEqualsWithDelta(100.0, $completion['score'], 1e-9, 'raw score untouched');
        $this->assertEqualsWithDelta(
            missionScoreEvidenceAdjust(100.0, 1)['adjusted'], $completion['adjusted'], 1e-9
        );
        $this->assertEqualsWithDelta(0.25, $completion['credibility'], 1e-9);
        $this->assertEqualsWithDelta($alfa['score'] - $alfa['raw_score'], $alfa['adjustment'], 0.011);
    }

    public function testAnUnansweredOrderStillCostsATeamEvenWhenItIsTheOnlyOne(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        $this->order($aTeam, $aUser, null, null);    // one order, never touched
        for ($i = 0; $i < 4; $i++) {
            $this->order($bTeam, $bUser, 5, 20);
        }
        $teams = $this->teamsByCodename();
        $this->assertLessThan($teams['ΒΗΤΑ']['score'], $teams['ΑΛΦΑ']['score']);
        // But it is not crushed to zero by a single miss either.
        $this->assertGreaterThan(30.0, $teams['ΑΛΦΑ']['score']);
    }

    public function testTheMissionWideScoreIsNotAffectedByTheWeighting(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        $this->order($aTeam, $aUser, 2, 6);
        $score = computeMissionScore($this->missionId);
        // One order, answered in 2 minutes and completed: the mission-wide
        // completion pillar is a plain pooled rate, exactly as before.
        $this->assertEqualsWithDelta(100.0, $score['pillars']['completion']['score'], 1e-9);
    }

    // ── what the report says ─────────────────────────────────────────────

    public function testTheNarrativeExplainsWhyTheOrderIsNotTheOneThePercentagesSuggest(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        $this->order($aTeam, $aUser, 2, 6);
        for ($i = 0; $i < 8; $i++) {
            $this->order($bTeam, $bUser, 3, 15);
        }
        $this->order($bTeam, $bUser, 3, null);

        $score = computeMissionScore($this->missionId);
        $text = generateTeamComparisonNarrative($score['teams']);

        $this->assertStringContainsString('Η κατάταξη ζυγίζει', $text);
        $this->assertStringContainsString('μία μόνο παρατήρηση', $text);
        // The one-order team is never held up as the best completion rate.
        $this->assertStringNotContainsString('(1/1)', $text);
        // And the caveat about thin records is the evidence-level one.
        $this->assertStringContainsString('σε ελάχιστα στοιχεία', $text);
    }

    // ── orders that have nothing to complete ─────────────────────────────

    public function testABroadcastMessageCannotLowerACompletionRate(): void
    {
        // Nothing in the app ever stamps fulfilled_at on a message: acknowledging
        // it IS the whole task. Counting it as "not completed" capped this team
        // at 50% for something it had no way to do.
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        for ($i = 0; $i < 3; $i++) {
            $this->order($aTeam, $aUser, 2, 10);                 // three tasks, all done
            $this->order($aTeam, $aUser, 2, null, 'message');    // three messages, all acknowledged
            $this->order($bTeam, $bUser, 2, 10);                 // same tasks, no messages
        }

        $score = computeMissionScore($this->missionId);
        $teams = [];
        foreach ($score['teams'] as $t) {
            $teams[$t['codename']] = $t;
        }
        $this->assertEqualsWithDelta(100.0, $teams['ΑΛΦΑ']['pillars']['completion']['score'], 1e-9);
        $this->assertSame(3, $teams['ΑΛΦΑ']['pillars']['completion']['raw']['total'], 'only the three tasks can be completed');
        $this->assertSame(3, $teams['ΑΛΦΑ']['pillars']['completion']['n']);
        // The messages still count where they belong: the acknowledgment is graded by response speed.
        $this->assertSame(6, $teams['ΑΛΦΑ']['pillars']['response']['n']);
        $this->assertSame(6, $teams['ΑΛΦΑ']['order_count']);
        $this->assertEqualsWithDelta($teams['ΒΗΤΑ']['pillars']['completion']['score'], $teams['ΑΛΦΑ']['pillars']['completion']['score'], 1e-9);
        // And so does the mission-wide pillar.
        $this->assertEqualsWithDelta(100.0, $score['pillars']['completion']['score'], 1e-9);
        // 3 + 3 tasks across the two teams; the three messages are not in it.
        $this->assertSame(6, $score['pillars']['completion']['raw']['total']);
    }

    public function testAMessageThatWasNeverAcknowledgedStillCostsTheTeamItsResponseScore(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        for ($i = 0; $i < 4; $i++) {
            $this->order($aTeam, $aUser, 2, 10);
            $this->order($bTeam, $bUser, 2, 10);
        }
        $this->order($aTeam, $aUser, null, null, 'message');     // never even opened
        $teams = $this->teamsByCodename();
        $this->assertLessThan(
            $teams['ΒΗΤΑ']['pillars']['response']['score'], $teams['ΑΛΦΑ']['pillars']['response']['score'],
            'leaving completion alone must not let a missed message go unpunished'
        );
        $this->assertEqualsWithDelta(100.0, $teams['ΑΛΦΑ']['pillars']['completion']['score'], 1e-9);
    }

    public function testATeamThatOnlyReceivedMessagesHasNoCompletionScoreAtAll(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        $this->order($aTeam, $aUser, 2, null, 'message');
        $this->order($aTeam, $aUser, 3, null, 'speak');
        $alfa = $this->teamsByCodename()['ΑΛΦΑ'];
        $this->assertFalse($alfa['pillars']['completion']['available'], 'nothing to complete is not a 0%');
        $this->assertTrue($alfa['pillars']['response']['available']);
    }

    public function testTheHistoricalBaselineAndTheTrendsTabUseTheSameRule(): void
    {
        // Another closed mission of the same type: three tasks done, six messages that can never be «done».
        $typeId = (int) dbFetchValue("SELECT mission_type_id FROM missions WHERE id = ?", [$this->missionId]);
        $past = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Past Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 90 * 86400), date('Y-m-d H:i:s', time() - 89 * 86400), $typeId, STATUS_CLOSED]
        );
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        for ($i = 0; $i < 3; $i++) {
            $this->order($aTeam, $aUser, 2, 8, 'task', $past);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->order($aTeam, $aUser, 2, null, 'message', $past);
        }
        $this->order($aTeam, $aUser, 2, 8);   // something for the current mission to score

        $historical = computeMissionScore($this->missionId)['historical'];
        $this->assertEquals(100, $historical['completion_rate'], 'three of three completable orders, not three of nine');
        $this->assertSame(3, $historical['completion_sample']);

        // Trends tab: same pooled total, counted by hand with the same exclusion.
        $day = date('Y-m-d', time() - 90 * 86400);
        $agg = computeWarRoomTypeAggregates($day, $day);
        $expected = (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_order_recipients r JOIN mission_orders o ON o.id = r.order_id JOIN missions m ON m.id = o.mission_id
             WHERE m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY
               AND COALESCE(m.mission_type_id, 0) = ? AND o.order_type NOT IN ('message','return_to_base','speak','charge_phone')",
            [$day, $day, $typeId]
        );
        $this->assertSame($expected, $agg[$typeId]['pillars']['completion']['raw']['total']);
    }

    public function testTheReportPagesCountCompletionTheSameWay(): void
    {
        foreach (['mission-stats.php', 'mission-report-print.php'] as $file) {
            $page = (string) file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString('missionOrderCanBeCompleted(', $page, $file);
            $this->assertStringContainsString('$completableOrders', $page, $file);
        }
        $this->assertFalse(missionOrderCanBeCompleted('speak'));
        $this->assertFalse(missionOrderCanBeCompleted('message'));
        $this->assertTrue(missionOrderCanBeCompleted('task'));
        $this->assertTrue(missionOrderCanBeCompleted('route'), 'a route is completed when it closes');
        $this->assertTrue(missionOrderCanBeCompleted('some_future_type'), 'a new type defaults to "can be completed", as before');
    }

    // ── the explanation printed beside the leaderboard ───────────────────

    /** ΑΛΦΑ 1/1, ΒΗΤΑ 8/9 — the case the whole feature exists for. */
    private function oneOfOneVersusEightOfNine(): array
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        $this->order($aTeam, $aUser, 2, 6);
        for ($i = 0; $i < 8; $i++) {
            $this->order($bTeam, $bUser, 3, 15);
        }
        $this->order($bTeam, $bUser, 3, null);
        return computeMissionScore($this->missionId)['teams'];
    }

    public function testThePointsColumnAddsUpToTheTeamsScore(): void
    {
        foreach ($this->oneOfOneVersusEightOfNine() as $team) {
            $sum = 0.0;
            $share = 0.0;
            foreach (missionTeamScoreRows($team) as $row) {
                if (!$row['available']) continue;
                $sum += $row['points'];
                $share += $row['share'];
            }
            $this->assertEqualsWithDelta($team['score'], $sum, 0.01, 'a reader must be able to add the column up and get the score');
            $this->assertEqualsWithDelta(1.0, $share, 1e-9);
        }
    }

    public function testAPillarWithNoDataIsListedAsNotCountingNotAsAZero(): void
    {
        $teams = $this->oneOfOneVersusEightOfNine();
        $rows = array_column(missionTeamScoreRows($teams[0]), null, 'key');
        $this->assertFalse($rows['shortage']['available']);
        $this->assertStringContainsString('μετράει ούτε υπέρ ούτε εναντίον', $rows['shortage']['reason']);
        $html = renderMissionTeamExplain($teams[0], 2, true);
        $this->assertStringContainsString('Διαχείριση Ελλείψεων', $html);
    }

    public function testTheBeforeAndAfterBoxShowsTheOrderTheWeightingChanged(): void
    {
        $html = renderMissionRankingShift($this->oneOfOneVersusEightOfNine());
        $this->assertStringContainsString('Αν μετρούσε μόνο το ποσοστό', $html);
        $this->assertStringContainsString('Η στάθμιση άλλαξε τη σειρά', $html);
        // ΑΛΦΑ leads the "percentage only" column, ΒΗΤΑ leads the real one.
        $this->assertLessThan(strpos($html, 'ΒΗΤΑ'), strpos($html, 'ΑΛΦΑ'));
        $this->assertStringContainsString('▼', $html);
        $this->assertStringContainsString('▲', $html);
    }

    public function testTheBeforeAndAfterBoxSaysSoWhenNothingMoved(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        for ($i = 0; $i < 6; $i++) {
            $this->order($aTeam, $aUser, 2, 10);
            $this->order($bTeam, $bUser, 15, null);
        }
        $html = renderMissionRankingShift(computeMissionScore($this->missionId)['teams']);
        $this->assertStringContainsString('Η στάθμιση δεν άλλαξε τη σειρά', $html);
    }

    public function testEveryTeamLineCarriesTheEvidenceTheRawScoreAndTheReason(): void
    {
        $alfa = $this->oneOfOneVersusEightOfNine()[1]; // ranked second
        $screen = renderMissionTeamExplain($alfa, 2, false);
        $print  = renderMissionTeamExplain($alfa, 2, true);

        foreach ([$screen, $print] as $html) {
            $this->assertStringContainsString('Βεβαιότητα', $html);
            $this->assertStringContainsString('μία μόνο παρατήρηση', $html);
            $this->assertStringContainsString('ακατέργαστος', $html);
            $this->assertStringContainsString('Αν μετρούσε μόνο το ποσοστό', $html);
        }
        $this->assertStringContainsString('<details', $screen, 'on screen the table folds away');
        $this->assertStringNotContainsString('<details', $print, 'paper cannot be clicked, so print is always open');
    }

    public function testTheGuideQuotesTheLiveConstantsAndTheWorkedExample(): void
    {
        $guide = renderMissionScoreGuide([], true);
        $this->assertStringContainsString('1/1 ή 8/9', $guide);
        $this->assertStringContainsString(number_format(missionScoreEvidenceAdjust(100.0, 1)['adjusted'], 1), $guide);
        $this->assertStringContainsString(number_format(missionScoreEvidenceAdjust(8 / 9 * 100, 9)['adjusted'], 1), $guide);
        $this->assertStringContainsString('n ÷ (n + ' . MISSION_SCORE_EVIDENCE_PRIOR_N . ')', $guide);
        $this->assertStringContainsString('SOS', $guide, 'it must say what is never scored');
        $this->assertStringContainsString('Ποιες εντολές μετράνε στην Ολοκλήρωση', $guide, 'and which orders can be completed at all');
        $this->assertStringNotContainsString('<details', $guide);
        $this->assertStringContainsString('<details', renderMissionScoreGuide([], false));
    }

    public function testAPerfectRecordNeedsFourObservationsForAnExcellentRating(): void
    {
        $this->assertSame(4, missionScoreMinObservationsFor(100.0, 85.0));
        $this->assertNull(missionScoreMinObservationsFor(80.0, 85.0), 'a record under the bar never gets there');
    }

    public function testTheTeamSheetHasBothLanguagesForTheWeightingCard(): void
    {
        $page = (string) file_get_contents(__DIR__ . '/../mission-team-debrief-print.php');
        foreach (['how_built', 'how_intro', 'adj_down', 'adj_up', 'adj_same', 'ev_low', 'ev_medium', 'ev_high',
                  'p_response', 'p_completion', 'p_shortage', 'p_discipline', 'area', 'raw', 'obs', 'weighted', 'weight', 'total', 'evidence'] as $key) {
            // Slice out exactly this key's entry (up to the next key of $L), so a
            // missing 'en' here cannot be satisfied by some other key's.
            $this->assertSame(1, preg_match("~^    '" . $key . "'\\s*=>~m", $page, $m, PREG_OFFSET_CAPTURE), "$key is not in \$L");
            $from = $m[0][1] + 1;
            $to = preg_match("~^    '[a-z_]+'\\s*=>~m", $page, $next, PREG_OFFSET_CAPTURE, $from) ? $next[0][1] : $from + 4000;
            $entry = substr($page, $from, $to - $from);
            $this->assertStringContainsString("'el' =>", $entry, "$key has no el text");
            $this->assertStringContainsString("'en' =>", $entry, "$key has no en text");
        }
        // The sheet must still never compare against, or rank among, other crews.
        $this->assertStringNotContainsString("\$teamScore['rank']", $page);
    }

    public function testBothReportPagesPrintTheSameExplanation(): void
    {
        foreach (['mission-stats.php', 'mission-report-print.php'] as $file) {
            $page = (string) file_get_contents(__DIR__ . '/../' . $file);
            $this->assertStringContainsString('renderMissionTeamExplain(', $page, $file);
            $this->assertStringContainsString('renderMissionRankingShift(', $page, $file);
            $this->assertStringContainsString('renderMissionScoreGuide(', $page, $file);
            $this->assertStringContainsString('missionScoreExplainStyles(', $page, $file);
        }
    }

    public function testTheAiDigestSeesTheWeightingAndIsToldNotToPraiseAThinRecord(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../includes/ai-context.php');
        $this->assertStringContainsString("'βαθμος_χωρις_σταθμιση'", $src);
        $this->assertStringContainsString('ενα 1/1 δεν ειναι καλυτερο απο ενα 8/9', $src);
    }

    public function testTeamsWithARealRecordAreStillComparedOnEachDimension(): void
    {
        [$aTeam, $aUser] = $this->makeTeam('ΑΛΦΑ', 1);
        [$bTeam, $bUser] = $this->makeTeam('ΒΗΤΑ', 2);
        for ($i = 0; $i < 6; $i++) {
            $this->order($aTeam, $aUser, 2, 10);
            $this->order($bTeam, $bUser, 3, $i < 3 ? 12 : null);
        }
        $score = computeMissionScore($this->missionId);
        $text = generateTeamComparisonNarrative($score['teams']);
        $this->assertStringContainsString('ποσοστό ολοκλήρωσης', $text);
        $this->assertStringContainsString('6/6', $text);
        $this->assertStringContainsString('3/6', $text);
        $this->assertStringNotContainsString('Η κατάταξη ζυγίζει', $text, 'nothing was reordered, so nothing to explain');
    }
}
