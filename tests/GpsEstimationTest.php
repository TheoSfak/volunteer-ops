<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions-gps-quality.php';
require_once __DIR__ . '/../includes/ai-live.php'; // aiLiveCompassLabel(), as the page uses it

/**
 * Phases 3 and 4 of the position review (v3.321.0): the filter that turns a
 * stream of noisy fixes into a steadier position, and the report that
 * measures whether it did.
 *
 * The filter tests are simulations with a fixed random seed: a known true
 * track, fixes scattered around it the way a phone scatters them, and a
 * comparison of how far the raw fixes and the estimates each land from the
 * truth. The two properties that matter pull against each other and both are
 * pinned: standing still, the estimate must be clearly closer than the raw
 * fixes; walking, it must not fall behind.
 *
 * The database tests run inside a transaction that is always rolled back.
 */
final class GpsEstimationTest extends TestCase
{
    private const STALE = 540;

    // ── Simulation helpers ──────────────────────────────────────────────────

    /** Standard normal deviate (Box-Muller) from PHP's seeded generator. */
    private function gauss(): float
    {
        $u = max(mt_rand() / mt_getrandmax(), 1e-12);
        $v = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    /**
     * Runs the filter over a track. $truth(i) gives the true [north, east]
     * metres at fix i; each fix is the truth plus noise of $sigma per axis,
     * optionally correlated fix to fix ($rho). Returns [rawErrors, estErrors].
     */
    private function simulate(int $n, callable $truth, float $sigma, ?callable $speed, int $dt = 30, float $rho = 0.0, float $reportedAcc = 0.0): array
    {
        $lat0 = 35.33; $lng0 = 25.13;
        $mLat = 111320.0; $mLng = 111320.0 * cos(deg2rad($lat0));
        $prev = null; $raw = []; $est = [];
        $nN = 0.0; $nE = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $nN = $rho * $nN + sqrt(1 - $rho * $rho) * $sigma * $this->gauss();
            $nE = $rho * $nE + sqrt(1 - $rho * $rho) * $sigma * $this->gauss();
            [$tN, $tE] = $truth($i);
            $lat = $lat0 + ($tN + $nN) / $mLat;
            $lng = $lng0 + ($tE + $nE) / $mLng;
            $acc = $reportedAcc > 0 ? $reportedAcc : $sigma * 1.2;
            $e = gpsFilterStep($prev, $lat, $lng, $acc, $speed ? $speed($i) : null, $prev ? $dt : 0, self::STALE);
            $prev = $e;
            $raw[] = hypot($tN + $nN - $tN, $tE + $nE - $tE);
            $est[] = hypot(($e['lat'] - $lat0) * $mLat - $tN, ($e['lng'] - $lng0) * $mLng - $tE);
        }
        return [$raw, $est];
    }

    private function median(array $v): float
    {
        sort($v);
        return (float) gpsPercentile($v, 50);
    }

    // ── The filter ──────────────────────────────────────────────────────────

    public function testStandingStillTheEstimateIsClearlyCloserThanTheRawFixes(): void
    {
        mt_srand(20260924);
        [$raw, $est] = $this->simulate(80, fn($i) => [0.0, 0.0], 12.0, fn($i) => 0.1);
        $this->assertLessThan(0.75 * $this->median($raw), $this->median($est),
            sprintf('raw median %.1fm, estimate median %.1fm', $this->median($raw), $this->median($est)));
    }

    public function testStandingStillWithCorrelatedNoiseItIsNeverWorse(): void
    {
        // Real GNSS error drifts rather than jumping independently — the same
        // wall reflecting for minutes. Averaging helps far less there; it must
        // at least never hurt.
        mt_srand(424242);
        [$raw, $est] = $this->simulate(120, fn($i) => [0.0, 0.0], 12.0, fn($i) => 0.1, 30, 0.85);
        $this->assertLessThanOrEqual($this->median($raw) * 1.02, $this->median($est));
    }

    public function testWalkingWithADopplerSpeedTheEstimateDoesNotFallBehind(): void
    {
        mt_srand(777);
        // 1.3 m/s north, fix every 30s: 39m between fixes.
        [$raw, $est] = $this->simulate(60, fn($i) => [$i * 39.0, 0.0], 8.0, fn($i) => 1.3);
        $this->assertLessThan($this->median($raw) + 2.0, $this->median($est),
            sprintf('raw %.1fm, estimate %.1fm', $this->median($raw), $this->median($est)));
    }

    public function testWalkingWithoutASpeedTheLagStaysBounded(): void
    {
        // A browser that reports no speed: the filter has only the jumps to go
        // on. A slow walk is where it is most tempted to average — and lag.
        mt_srand(99);
        [$raw, $est] = $this->simulate(60, fn($i) => [$i * 0.9 * 20, 0.0], 8.0, null, 20);
        $this->assertLessThan($this->median($raw) * 1.6, $this->median($est),
            sprintf('raw %.1fm, estimate %.1fm', $this->median($raw), $this->median($est)));
    }

    public function testStoppingAfterAWalkSettlesQuickly(): void
    {
        mt_srand(5150);
        $truth = fn($i) => $i < 20 ? [$i * 39.0, 0.0] : [19 * 39.0, 0.0];
        $speed = fn($i) => $i < 20 ? 1.3 : 0.0;
        [, $est] = $this->simulate(40, $truth, 8.0, $speed);
        // Three fixes after stopping, the estimate is where they stopped.
        $this->assertLessThan(20.0, max(array_slice($est, 23, 17)));
    }

    public function testAJumpBeyondThreeSigmaResetsToTheNewFix(): void
    {
        $prev = ['lat' => 35.33, 'lng' => 25.13, 'acc' => 5.0];
        $far = 35.33 + 300 / 111320.0;
        $e = gpsFilterStep($prev, $far, 25.13, 8.0, 0.0, 20, self::STALE);
        $this->assertSame($far, $e['lat']);
        $this->assertSame(8.0, $e['acc']);
    }

    public function testAFirstFixOrAStaleGapIsTakenAsIs(): void
    {
        $this->assertSame(35.4, gpsFilterStep(null, 35.4, 25.1, 9.0, null, 0, self::STALE)['lat']);
        $prev = ['lat' => 35.33, 'lng' => 25.13, 'acc' => 5.0];
        $this->assertSame(35.3301, gpsFilterStep($prev, 35.3301, 25.13, 9.0, 0.0, self::STALE, self::STALE)['lat']);
    }

    public function testTheEstimateNeverClaimsMoreThanHalvingTheDevicesAccuracy(): void
    {
        $prev = ['lat' => 35.33, 'lng' => 25.13, 'acc' => 2.0];
        for ($i = 0; $i < 30; $i++) {
            $prev = gpsFilterStep($prev, 35.33, 25.13, 20.0, 0.0, 30, self::STALE);
        }
        $this->assertGreaterThanOrEqual(10.0, $prev['acc']);
    }

    public function testSpeedParsing(): void
    {
        $this->assertNull(parseSpeedMps(''));
        $this->assertNull(parseSpeedMps('-1'));
        $this->assertNull(parseSpeedMps('abc'));
        $this->assertSame(1.25, parseSpeedMps('1.25'));
        $this->assertSame(150.0, parseSpeedMps('9999'));
    }

    // ── Database: the write path and the report ─────────────────────────────

    private int $missionId;
    private int $shiftId;
    private int $adminId;

    private function fixture(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('GQ Admin');
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['GPS Quality Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $typeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        forgetActionRoomParticipantIds($this->missionId);
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if (isset($this->missionId)) {
            forgetActionRoomParticipantIds($this->missionId);
        }
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", [$name, 'gq-' . uniqid('', true) . '@example.invalid', 'x']);
    }

    private function volunteer(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $id, PARTICIPATION_APPROVED]);
        setActionRoomParticipation($this->missionId, $id, true, $this->adminId);
        return $id;
    }

    private function user(int $id): array
    {
        return dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    }

    public function testTheRawFixIsKeptAndTheEstimateIsWhatEveryoneReads(): void
    {
        $this->fixture();
        $v = $this->volunteer('Στάσιμη Σ.');
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33, 25.13, 10.0, 80, 'auto', 'native', 20000, false, 0.0);
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33 + 12 / 111320.0, 25.13, 10.0, 80, 'auto', 'native', 0, false, 0.0);
        $row = dbFetchOne("SELECT * FROM volunteer_pings WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$v]);
        $this->assertEqualsWithDelta(35.33 + 12 / 111320.0, (float) $row['raw_lat'], 1e-8, 'raw kept exactly');
        $this->assertLessThan((float) $row['raw_lat'], (float) $row['lat'], 'estimate pulled toward the earlier fix');
        $this->assertGreaterThan(35.33, (float) $row['lat']);
        $this->assertSame('10.00', $row['raw_accuracy_m']);
        $this->assertSame('0.00', $row['speed_mps']);
    }

    public function testAFixWithNoAccuracyIsStoredExactlyAsSent(): void
    {
        // Nothing to weigh it by, so an estimate would invent a confidence the
        // device never claimed. (The Settings switch that turns the filter off
        // takes the same branch; getSetting() caches for the whole process, so
        // that one is verified in the browser rather than here.)
        $this->fixture();
        $v = $this->volunteer('Χωρίς Ακρίβεια');
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33, 25.13, 10.0, 80, 'auto', 'native', 20000, false, 0.0);
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33 + 12 / 111320.0, 25.13, null, 80, 'auto', 'native', 0, false, 0.0);
        $row = dbFetchOne("SELECT lat, raw_lat, accuracy_meters FROM volunteer_pings WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$v]);
        $this->assertSame($row['raw_lat'], $row['lat']);
        $this->assertNull($row['accuracy_meters']);
    }

    public function testRefusalsAreCountedPerReason(): void
    {
        $this->fixture();
        $v = $this->volunteer('Κακό Σήμα');
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33, 25.13, 900.0, 80, 'auto', 'browser');
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33, 25.13, 800.0, 80, 'auto', 'browser');
        recordVolunteerPing($this->user($v), $this->shiftId, 35.33, 25.13, 3.0, 80, 'auto', 'native', 0, true);
        $counts = array_column(dbFetchAll(
            "SELECT reason, refused_count FROM volunteer_ping_refusals WHERE mission_id = ? AND user_id = ?",
            [$this->missionId, $v]
        ), 'refused_count', 'reason');
        $this->assertSame(['imprecise' => 2, 'mock' => 1], array_map('intval', $counts));
    }

    public function testTheOverviewListsEveryParticipantEvenOneWhoNeverReported(): void
    {
        $this->fixture();
        $a = $this->volunteer('Άλφα');
        $this->volunteer('Βήτα Σιωπηλή');
        foreach ([6.0, 8.0, 10.0, 40.0] as $i => $acc) {
            dbExecute(
                "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at, raw_lat, raw_lng, raw_accuracy_m)
                 VALUES (?, ?, 35.33, 25.13, ?, 'auto', 'native', DATE_SUB(NOW(), INTERVAL ? SECOND), ?, 25.13, ?)",
                [$a, $this->shiftId, $acc, 300 - $i * 30, 35.33 + 5 / 111320.0, $acc]
            );
        }
        dbInsert("INSERT INTO mobile_api_tokens (user_id, token_hash, device_label, last_used_at) VALUES (?, ?, ?, NOW())",
            [$a, hash('sha256', uniqid('', true)), 'samsung SM-A525F · Android 14']);

        $byName = array_column(loadMissionGpsQuality($this->missionId), null, 'name');
        $this->assertSame(4, $byName['Άλφα']['fixes']);
        $this->assertSame(4, $byName['Άλφα']['native']);
        $this->assertSame(8.0, $byName['Άλφα']['acc_median']);
        $this->assertSame(40.0, $byName['Άλφα']['acc_p90']);
        $this->assertEqualsWithDelta(5.0, $byName['Άλφα']['shift_median'], 0.1);
        $this->assertSame('samsung SM-A525F · Android 14', $byName['Άλφα']['device']);
        $this->assertSame(0, $byName['Βήτα Σιωπηλή']['fixes'], 'somebody who never reported is still listed');
    }

    public function testCalibrationMeasuresRealErrorHonestyAndBias(): void
    {
        $this->fixture();
        $north = $this->volunteer('Βόρεια Β.');   // always 10m north, claims ±12m: honest
        $east  = $this->volunteer('Ανατολικός Α.'); // always 30m east, claims ±5m: overconfident
        $refLat = 35.33; $refLng = 25.13;
        $mLng = 111320.0 * cos(deg2rad($refLat));
        for ($i = 0; $i < 10; $i++) {
            dbExecute(
                "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at, raw_lat, raw_lng, raw_accuracy_m)
                 VALUES (?, ?, ?, ?, 8, 'auto', 'native', DATE_SUB(NOW(), INTERVAL ? SECOND), ?, ?, 12)",
                [$north, $this->shiftId, $refLat + 4 / 111320.0, $refLng, 600 - $i * 30, $refLat + 10 / 111320.0, $refLng]
            );
            dbExecute(
                "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at, raw_lat, raw_lng, raw_accuracy_m)
                 VALUES (?, ?, ?, ?, 5, 'auto', 'native', DATE_SUB(NOW(), INTERVAL ? SECOND), ?, ?, 5)",
                [$east, $this->shiftId, $refLat, $refLng + 30 / $mLng, 600 - $i * 30, $refLat, $refLng + 30 / $mLng]
            );
        }
        $result = computeGpsCalibration($this->missionId, $refLat, $refLng, date('Y-m-d H:i:s', time() - 900), date('Y-m-d H:i:s', time() + 60));
        $byName = array_column($result['people'], null, 'name');

        $n = $byName['Βόρεια Β.'];
        $this->assertSame(10, $n['fixes']);
        $this->assertEqualsWithDelta(10.0, $n['raw_median'], 0.2);
        $this->assertEqualsWithDelta(4.0, $n['est_median'], 0.2, 'the estimate column is measured separately');
        $this->assertSame(100, $n['honest_pct']);
        $this->assertEqualsWithDelta(10.0, $n['bias_m'], 0.2);
        $this->assertSame('Β', aiLiveCompassLabel($n['bias_deg']));

        $e = $byName['Ανατολικός Α.'];
        $this->assertSame(0, $e['honest_pct'], '30m off while claiming ±5m is never honest');
        $this->assertEqualsWithDelta(30.0, $e['bias_m'], 0.3);
        $this->assertEqualsWithDelta(90.0, $e['bias_deg'], 1.0);

        $this->assertSame(20, $result['all']['fixes']);
        $this->assertSame(50, $result['all']['honest_pct']);
    }

    public function testCalibrationOnlyCountsFixesInsideTheWindow(): void
    {
        $this->fixture();
        $v = $this->volunteer('Εκτός Χρόνου');
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, 35.33, 25.13, 8, 'auto', 'native', DATE_SUB(NOW(), INTERVAL 2 HOUR))",
            [$v, $this->shiftId]
        );
        $result = computeGpsCalibration($this->missionId, 35.33, 25.13, date('Y-m-d H:i:s', time() - 900), date('Y-m-d H:i:s', time() + 60));
        $this->assertSame([], $result['people']);
        $this->assertNull($result['all']);
    }
}

