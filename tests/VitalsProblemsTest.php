<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * detectVitalsProblems() — the «Τι χρειάζεται προσοχή» panel on the heart-rate
 * report (mission-vitals-report.php).
 *
 * The page itself answers "what is everyone's heart doing" and leaves the
 * conclusion to the reader. This function draws the conclusion, and it is
 * deliberately DETERMINISTIC rather than an AI reading: a heart-rate problem
 * is a threshold, and the readings are Article 9 health data that must not
 * leave the server to have prose written about them.
 *
 * It is pure — it takes what the page already computed — so everything below
 * runs without a database.
 *
 * The properties worth defending:
 *   1. "Nobody wore a sensor" is said once, and is not confused with
 *      "everybody's signal has gone stale".
 *   2. A zone is not a finding; a zone held too long with no relief is.
 *   3. Nothing is ever implied about people who were never measured.
 */
final class VitalsProblemsTest extends TestCase
{
    private const CONFIG = ['strain_minutes' => 20, 'stale_seconds' => 120];

    /** @param array<int,array<string,mixed>> $volunteers */
    private function now(array $volunteers): array
    {
        $count = fn(string $zone) => count(array_filter($volunteers, fn($v) => $v['zone'] === $zone));
        return [
            'volunteers' => $volunteers,
            'summary'    => [
                'expected'  => count($volunteers),
                // Mirrors loadVitalsNowForMission(): stale does NOT count as wearing.
                'wearing'   => count(array_filter(
                    $volunteers,
                    fn($v) => ($v['bpm'] ?? null) !== null && $v['zone'] !== 'stale'
                )),
                'critical'  => $count('critical'),
                'low'       => $count('low'),
                'elevated'  => $count('elevated'),
                'stale'     => $count('stale'),
                'no_sensor' => $count('none'),
            ],
        ];
    }

    private function person(string $name, string $zone, ?int $bpm, ?int $zoneMinutes = null, ?int $age = null): array
    {
        return ['name' => $name, 'zone' => $zone, 'bpm' => $bpm,
                'zone_minutes' => $zoneMinutes, 'age_seconds' => $age];
    }

    private function detect(array $volunteers, array $episodes = [], array $teamLoad = []): array
    {
        return detectVitalsProblems($this->now($volunteers), $episodes, $teamLoad, self::CONFIG, 140);
    }

    private function titles(array $result): string
    {
        return implode(' | ', array_column($result['findings'], 'title'));
    }

    // ── Nobody is wearing anything ─────────────────────────────────────────

    public function testWhenNobodyEverWoreASensorItSaysSoOnceAndStops(): void
    {
        // The ordinary case for an organisation that owns no straps. Every
        // reading is zero, and a panel full of "no data" rows would be worse
        // than one sentence.
        $result = $this->detect([
            $this->person('Α', 'none', null),
            $this->person('Β', 'none', null),
            $this->person('Γ', 'none', null),
        ]);

        $this->assertTrue($result['nothing_to_assess']);
        $this->assertSame([], $result['findings']);
        $this->assertSame(3, $result['expected']);
    }

    public function testAStaleSignalIsNotTheSameAsNeverHavingWornOne(): void
    {
        // The bug this pins: gating on summary['wearing'] === 0, which also
        // reads zero the moment every signal goes stale — including a finished
        // mission with hours of recorded data behind it, where there is a
        // great deal to assess.
        $result = $this->detect([
            $this->person('Α', 'stale', 96, null, 7200),
            $this->person('Β', 'stale', 88, null, 7200),
        ]);

        $this->assertFalse($result['nothing_to_assess']);
        $this->assertStringContainsString('Έχασαν σήμα', $this->titles($result));
    }

    // ── Someone is in trouble right now ────────────────────────────────────

    public function testTachycardiaAndBradycardiaAreBothRaisedAsUrgent(): void
    {
        $result = $this->detect([
            $this->person('Ταχυκαρδικός', 'critical', 168, 6),
            $this->person('Βραδυκαρδικός', 'low', 41, 9),
            $this->person('Ήρεμος', 'ok', 78, 40),
        ]);

        $high = array_values(array_filter($result['findings'], fn($f) => $f['sev'] === 'high'));
        $this->assertCount(2, $high);
        // Worst first: an urgent finding must not sit below an advisory one.
        $this->assertSame('high', $result['findings'][0]['sev']);
        $this->assertStringContainsString('Ταχυκαρδικός (168)', $this->titles($result) . implode(' ', array_column($result['findings'], 'detail')));
    }

    // ── A zone held too long is the finding, not the zone ──────────────────

    public function testBeingElevatedIsNotAFindingButBeingElevatedForAnHourIs(): void
    {
        // Someone at 145 bpm for four minutes is climbing. The same person at
        // 145 for fifty minutes is a rotation that never happened.
        $climbing = $this->detect([$this->person('Ανηφόρα', 'elevated', 145, 4)]);
        $this->assertStringNotContainsString('ανάπαυλα', $this->titles($climbing));

        $unrelieved = $this->detect([$this->person('Ξεχασμένος', 'elevated', 145, 50)]);
        $this->assertStringContainsString('ανάπαυλα', $this->titles($unrelieved));
        $this->assertSame('high', $unrelieved['findings'][0]['sev']);
    }

    public function testTheReliefThresholdFollowsTheOrgsOwnStrainSetting(): void
    {
        // Derived rather than being a second knob — and floored, so a very
        // short strain setting does not fire this on everyone walking uphill.
        $this->assertSame(40, vitalsUnrelievedMinutes(['strain_minutes' => 20]));
        $this->assertSame(90, vitalsUnrelievedMinutes(['strain_minutes' => 45]));
        $this->assertSame(30, vitalsUnrelievedMinutes(['strain_minutes' => 5]));
        // An absent setting means the documented default of 20, not zero — the
        // floor must never be what an empty config silently falls back to.
        $this->assertSame(40, vitalsUnrelievedMinutes([]));
    }

    // ── Team imbalance, not team effort ────────────────────────────────────

    public function testOnlyAnImbalancedTeamIsFlaggedNotSimplyABusyOne(): void
    {
        // On a mountain callout everybody is elevated most of the time. The
        // finding is about one team carrying more than its share.
        $even = $this->detect(
            [$this->person('Α', 'ok', 80, 10)],
            [],
            [
                ['label' => 'ΑΛΦΑ', 'minutes' => 300, 'elevated_minutes' => 240, 'bpm_avg' => 140, 'episodes' => 0],
                ['label' => 'ΒΡΑΒΟ', 'minutes' => 300, 'elevated_minutes' => 230, 'bpm_avg' => 138, 'episodes' => 0],
            ]
        );
        $this->assertStringNotContainsString('δυσανάλογο', $this->titles($even));

        $lopsided = $this->detect(
            [$this->person('Α', 'ok', 80, 10)],
            [],
            [
                ['label' => 'ΑΛΦΑ', 'minutes' => 300, 'elevated_minutes' => 270, 'bpm_avg' => 150, 'episodes' => 3],
                ['label' => 'ΒΡΑΒΟ', 'minutes' => 300, 'elevated_minutes' => 30, 'bpm_avg' => 95, 'episodes' => 0],
            ]
        );
        $this->assertStringContainsString('ΑΛΦΑ', $this->titles($lopsided));
        $this->assertStringContainsString('δυσανάλογο', $this->titles($lopsided));
    }

    public function testASingleTeamIsNeverImbalancedAgainstItself(): void
    {
        $result = $this->detect(
            [$this->person('Α', 'ok', 80, 10)],
            [],
            [['label' => 'ΑΛΦΑ', 'minutes' => 300, 'elevated_minutes' => 290, 'bpm_avg' => 150, 'episodes' => 0]]
        );
        $this->assertStringNotContainsString('δυσανάλογο', $this->titles($result));
    }

    // ── What the report cannot see ─────────────────────────────────────────

    public function testUnmeasuredPeopleAreReportedAsUnknownNotAsFine(): void
    {
        $result = $this->detect([
            $this->person('Μετρημένος', 'ok', 80, 30),
            $this->person('Αμέτρητος', 'none', null),
            $this->person('Κι άλλος', 'none', null),
        ]);

        $detail = implode(' ', array_column($result['findings'], 'detail'));
        $this->assertStringContainsString('2 από 3', $this->titles($result));
        // The distinction the whole panel exists for: silence is not safety.
        $this->assertStringContainsString('δεν ξέρουμε τίποτα', $detail);
    }

    public function testAnAllClearProducesNoFindingsRatherThanAReassuringOne(): void
    {
        // The page renders its own "nothing to report" line; a finding saying
        // "everything is fine" would be counted as a finding.
        $result = $this->detect([
            $this->person('Α', 'ok', 78, 40),
            $this->person('Β', 'elevated', 142, 5),
        ]);

        $this->assertFalse($result['nothing_to_assess']);
        $this->assertSame([], $result['findings']);
    }

    // ── Episodes that are over ─────────────────────────────────────────────

    public function testEndedClinicalEpisodesAreOneLineAndStrainIsNotCountedAmongThem(): void
    {
        // Strain fires for nearly everyone on a real callout; it has its own
        // section on the page and would drown the clinical events here.
        $result = $this->detect(
            [$this->person('Α', 'ok', 80, 30)],
            [
                ['type' => 'tachycardia', 'active' => false, 'bpm_peak' => 171, 'user_id' => 1],
                ['type' => 'bradycardia', 'active' => false, 'bpm_peak' => 39, 'user_id' => 2],
                ['type' => 'strain', 'active' => false, 'bpm_peak' => 150, 'user_id' => 3],
                ['type' => 'tachycardia', 'active' => true, 'bpm_peak' => 165, 'user_id' => 4],
            ]
        );

        $this->assertStringContainsString('Κλινικά επεισόδια που πέρασαν (2)', $this->titles($result));
        $detail = implode(' ', array_column($result['findings'], 'detail'));
        $this->assertStringContainsString('171', $detail);
    }

    // ── The chart axis skips time nobody recorded ──────────────────────────

    /** Probe-block indices for a run starting at $fromBlock, $count blocks long. */
    private function blocks(int $fromBlock, int $count): array
    {
        return range($fromBlock, $fromBlock + $count - 1);
    }

    public function testAContinuousRunIsOneUninterruptedPeriod(): void
    {
        // The ordinary mission. Nothing about the axis may change for it —
        // this is the regression guard on the whole gap feature.
        $periods = vitalsGroupBlocksIntoPeriods($this->blocks(1000, 12), 1000 * 600, 1012 * 600);

        $this->assertCount(1, $periods);
        $this->assertSame(1000 * 600, $periods[0]['from']);
        $this->assertSame(1012 * 600, $periods[0]['to']);
    }

    public function testAMonthOfNothingBetweenTwoRunsBecomesTwoPeriods(): void
    {
        // The real case: two hours of readings in August and ninety minutes in
        // September spread four hours of data across twenty-seven days, and
        // "Όλη η αποστολή" drew two hairlines with a month of white between.
        $august    = $this->blocks(1000, 12);
        $september = $this->blocks(1000 + 4000, 9);
        $periods   = vitalsGroupBlocksIntoPeriods(
            array_merge($august, $september),
            1000 * 600,
            (1000 + 4000 + 9) * 600
        );

        $this->assertCount(2, $periods);
        $this->assertSame(1012 * 600, $periods[0]['to']);
        $this->assertSame(5000 * 600, $periods[1]['from']);
    }

    public function testAShortGapIsKeptBecauseItIsInformation(): void
    {
        // Under the threshold a gap is a stand-down, a vehicle move or a strap
        // that came off, and the reader should see it rather than have the
        // axis quietly close it up.
        $short = array_merge($this->blocks(1000, 6), $this->blocks(1006 + 6, 6)); // one hour apart
        $this->assertCount(1, vitalsGroupBlocksIntoPeriods($short, 1000 * 600, 1018 * 600));

        // And at the threshold itself it splits.
        $long = array_merge($this->blocks(1000, 6), $this->blocks(1006 + 12, 6)); // two hours apart
        $this->assertCount(2, vitalsGroupBlocksIntoPeriods($long, 1000 * 600, 1024 * 600));
    }

    public function testThePeriodsNeverReachOutsideTheRequestedWindow(): void
    {
        // Probe blocks round outwards; the window the coordinator picked does
        // not, and an axis wider than the window would redraw what they just
        // narrowed away.
        $from = 1000 * 600 + 120;
        $to   = 1012 * 600 - 120;
        $periods = vitalsGroupBlocksIntoPeriods($this->blocks(1000, 12), $from, $to);

        $this->assertSame($from, $periods[0]['from']);
        $this->assertSame($to, $periods[count($periods) - 1]['to']);
    }

    public function testNoReadingsAtAllFallsBackToTheWholeRange(): void
    {
        // Returning no periods would build an empty axis; the caller treats
        // "one period covering everything" as the safe default.
        $periods = vitalsGroupBlocksIntoPeriods([], 500, 900);
        $this->assertSame([['from' => 500, 'to' => 900]], $periods);
    }

    // ── Presentation ───────────────────────────────────────────────────────

    public function testLongDurationsAreReadableRatherThanRawMinutes(): void
    {
        // "3342λ" is a number the reader has to divide before it means
        // anything, and on a closed mission that is every row in the panel.
        $this->assertSame('45λ', vitalsMinutesWords(45));
        $this->assertSame('1ω', vitalsMinutesWords(60));
        $this->assertSame('2ω 15λ', vitalsMinutesWords(135));
        $this->assertSame('2 ημέρες', vitalsMinutesWords(3342));
        $this->assertSame('0λ', vitalsMinutesWords(-5));
    }

    public function testOnlyAFewPeopleAreNamedAndTheRestAreAcknowledged(): void
    {
        // Six names with a duration each is a wall of text in a card meant to
        // be read at a glance — but the count in the title stays truthful.
        $result = $this->detect([
            $this->person('Α', 'stale', 90, null, 600),
            $this->person('Β', 'stale', 91, null, 600),
            $this->person('Γ', 'stale', 92, null, 600),
            $this->person('Δ', 'stale', 93, null, 600),
            $this->person('Ε', 'stale', 94, null, 600),
            $this->person('Ζ', 'stale', 95, null, 600),
        ]);

        $this->assertStringContainsString('(6)', $this->titles($result));
        $this->assertStringContainsString('+2 ακόμη', $result['findings'][0]['detail']);
    }
}
