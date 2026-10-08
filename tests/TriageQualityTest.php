<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * triageQualityStats() (includes/functions-triage.php): the figures behind
 * "how did the sorting hold up" in the mission report. Pure function, no
 * database. What it must NOT do is call a change of colour an error: it counts
 * first-assessment vs first-re-assessment, and the tests pin exactly that.
 */
final class TriageQualityTest extends TestCase
{
    /** One assessment row; $min minutes after a fixed start. */
    private static function a(int $victim, string $category, int $min, string $protocol = 'start', string $reason = 'x'): array
    {
        return ['victim_id' => $victim, 'category' => $category, 'protocol' => $protocol, 'reason' => $reason, 'ts' => 1_000_000 + $min * 60];
    }

    public function testNoAssessmentsGivesEmptyFigures(): void
    {
        $s = triageQualityStats([], []);
        $this->assertSame(0, $s['victims']);
        $this->assertSame(0, $s['reassessed']);
        $this->assertNull($s['reassess_median_minutes']);
        $this->assertSame(['n' => 0, 'median' => null, 'max' => null], $s['transport']['red']);
    }

    public function testUnderOverAndSameAreCountedAgainstTheFirstReassessment(): void
    {
        $rows = [
            self::a(1, 'green', 0), self::a(1, 'red', 10),       // under, found red
            self::a(2, 'yellow', 0), self::a(2, 'red', 5),       // under, found red
            self::a(3, 'green', 0), self::a(3, 'yellow', 8),     // under, not red
            self::a(4, 'red', 0), self::a(4, 'yellow', 20),      // over
            self::a(5, 'yellow', 0), self::a(5, 'yellow', 30),   // same
            self::a(6, 'red', 0),                                // never re-assessed
        ];
        $s = triageQualityStats($rows, []);
        $this->assertSame(6, $s['victims']);
        $this->assertSame(5, $s['reassessed']);
        $this->assertSame(3, $s['under']);
        $this->assertSame(2, $s['under_to_red']);
        $this->assertSame(1, $s['over']);
        $this->assertSame(1, $s['same']);
        $this->assertSame(0, $s['black_changed']);
        $this->assertSame(10, $s['reassess_median_minutes'], 'Medians of 10, 5, 8, 20, 30.');
    }

    public function testOnlyTheFirstReassessmentCounts(): void
    {
        // green -> yellow -> red: one "under" (first vs second), not two changes.
        $s = triageQualityStats([self::a(1, 'green', 0), self::a(1, 'yellow', 5), self::a(1, 'red', 30)], []);
        $this->assertSame(1, $s['under']);
        $this->assertSame(0, $s['under_to_red'], 'The first re-look found yellow, not red.');
    }

    public function testRowOrderDoesNotMatterOnlyFieldTime(): void
    {
        $s = triageQualityStats([self::a(1, 'red', 10), self::a(1, 'green', 0)], []);
        $this->assertSame(1, $s['under'], 'green at minute 0, red at minute 10, however the rows arrive.');
    }

    public function testBlackIsKeptApartFromTheScale(): void
    {
        $s = triageQualityStats([
            self::a(1, 'black', 0), self::a(1, 'red', 5),        // declared dead, then alive
            self::a(2, 'yellow', 0), self::a(2, 'black', 9),     // died
            self::a(3, 'black', 0), self::a(3, 'black', 9),      // unchanged
        ], []);
        $this->assertSame(2, $s['black_changed']);
        $this->assertSame(0, $s['under']);
        $this->assertSame(0, $s['over']);
        $this->assertSame(1, $s['same']);
    }

    public function testSecondaryAssessmentsAreSplitByWhatTheRescuerDid(): void
    {
        $s = triageQualityStats([
            self::a(1, 'yellow', 0), self::a(1, 'green', 5, 'secondary', 'trts'),
            self::a(2, 'yellow', 0), self::a(2, 'red', 5, 'secondary', 'secondary_override'),
            self::a(3, 'red', 0), self::a(3, 'red', 5, 'secondary', 'secondary_manual'),
            self::a(4, 'red', 0), self::a(4, 'red', 5, 'start'),
        ], []);
        $this->assertSame(['total' => 3, 'accepted' => 1, 'overridden' => 1, 'manual' => 1], $s['secondary']);
    }

    public function testTheWordsStateTheFiguresAndNeverCallAChangeAnError(): void
    {
        $this->assertSame([], triageQualityLines(triageQualityStats([], [])));

        $rows = [
            self::a(1, 'green', 0), self::a(1, 'red', 10),
            self::a(2, 'red', 0), self::a(2, 'yellow', 20),
            self::a(3, 'yellow', 0), self::a(3, 'yellow', 30, 'secondary', 'trts'),
            self::a(4, 'red', 0),
        ];
        $text = implode("\n", triageQualityLines(triageQualityStats($rows, [4 => 1_000_000 + 45 * 60])));
        $this->assertStringContainsString('Επανεκτιμήθηκαν 3 από 4 θύματα με κάρτα (75%)', $text);
        $this->assertStringContainsString('πιθανό υπο-triage) σε 1 θύμα (33% των επανεκτιμηθέντων, 25% όλων), από τα οποία 1 βρέθηκε κόκκινο', $text);
        $this->assertStringContainsString('πιθανό υπερ-triage) σε 1', $text);
        $this->assertStringContainsString('Δευτερογενείς εκτιμήσεις (ζωτικά): 1', $text);
        $this->assertStringContainsString('Κόκκινα 45′ / 45′ (1 θύμα)', $text);

        // Plural agreement: several of everything.
        $many = triageQualityLines(triageQualityStats([
            self::a(1, 'green', 0), self::a(1, 'red', 5), self::a(2, 'yellow', 0), self::a(2, 'red', 5),
        ], [1 => 1_000_300, 2 => 1_000_300]));
        $this->assertStringContainsString('Επανεκτιμήθηκαν 2 από 2 θύματα', $many[0]);
        $this->assertStringContainsString('σε 2 θύματα', $many[1]);
        $this->assertStringContainsString('2 βρέθηκαν κόκκινα', $many[1]);
        $single = triageQualityLines(triageQualityStats([self::a(1, 'red', 0), self::a(1, 'red', 4)], []));
        $this->assertStringContainsString('Επανεκτιμήθηκε 1 από 1 θύμα', $single[0]);
        $this->assertStringNotContainsString('λάθος:', $text);

        $this->assertStringContainsString('δεν δείχνει λάθος', TRIAGE_QUALITY_NOTE);
        $this->assertStringContainsString('βελτίωση', TRIAGE_QUALITY_NOTE);
    }

    public function testTimeToLeaveIsPerFinalColourWithMedianAndMax(): void
    {
        $t0 = 1_000_000;
        $rows = [
            self::a(1, 'red', 0), self::a(2, 'red', 0), self::a(3, 'red', 0),
            self::a(4, 'yellow', 0), self::a(5, 'green', 0),
            self::a(6, 'yellow', 0), self::a(6, 'red', 10),  // final colour red
            self::a(7, 'black', 0),
        ];
        $left = [1 => $t0 + 30 * 60, 2 => $t0 + 50 * 60, 3 => $t0 + 100 * 60, 4 => $t0 + 90 * 60, 6 => $t0 + 70 * 60, 7 => $t0 + 5 * 60];
        $s = triageQualityStats($rows, $left);
        // reds: 1 (30), 2 (50), 3 (100), 6 (70) -> sorted 30,50,70,100 -> median 60, max 100
        $this->assertSame(['n' => 4, 'median' => 60, 'max' => 100], $s['transport']['red']);
        $this->assertSame(['n' => 1, 'median' => 90, 'max' => 90], $s['transport']['yellow']);
        $this->assertSame(['n' => 0, 'median' => null, 'max' => null], $s['transport']['green'], 'Still waiting: not counted.');
    }
}
