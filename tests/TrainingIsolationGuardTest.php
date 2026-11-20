<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fails when a PHP file reads participation hours/attendance/points but neither
 * filters on is_training nor sits on the reviewed allow-list below. This is a
 * per-FILE check (a file with the filter in one query and a missed second query
 * would pass); the aggregate and source tests cover the per-query cases.
 *
 * Every allow-list entry must keep a reason. Adding a file here is a decision,
 * not a way to silence the test.
 */
final class TrainingIsolationGuardTest extends TestCase
{
    /** Files that legitimately read participation numbers without filtering, and why. */
    private const ALLOWED = [
        'includes/functions-warroom.php'      => 'check-in UPDATEs write attended/actual_hours for the mission being run (a drill must record them too); the certificate pick list reads attended scoped by s.mission_id = ?',
        'mission-stats.php'                   => 'per-mission page: SUM(actual_hours) and per-volunteer hours are both scoped by s.mission_id = ?, a drill shows its own stats',
        'mission-report-print.php'            => 'per-mission report: SUM(actual_hours) and per-volunteer hours are both scoped by s.mission_id = ?',
        'shift-participants-print.php'        => 'per-shift roster print: lists one shift\'s participants with their own attended/actual_hours, no aggregate',
        'ops-dashboard.php'                   => 'live operations board: attended count per shift of an open mission, no cross-mission total; drills appear with their badge',
        'inventory-view.php'                  => 'only displays inventory_bookings.actual_hours, a different table from participation hours',
        'includes/inventory-functions.php'    => 'writes inventory_bookings.actual_hours on return, a different table from participation hours',
    ];

    /** Pages that are reporting surfaces and must filter (checked by name, not by pattern). */
    private const MUST_FILTER = [
        'dashboard.php', 'reports.php', 'report-municipality.php', 'includes/export-functions.php',
        'includes/functions-core.php', 'includes/achievements-functions.php', 'leaderboard.php',
        'volunteers.php', 'inactive-volunteers.php', 'profile.php', 'volunteer-view.php',
        'volunteer-report.php', 'attendance.php', 'shift-view.php',
    ];

    private function projectFiles(): array
    {
        $root = realpath(__DIR__ . '/..');
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            if (substr($rel, -4) !== '.php') {
                continue;
            }
            if (preg_match('#^(tests|vendor|node_modules|\.git|\.phpunit\.cache|\.superpowers)/#', $rel)) {
                continue;
            }
            $out[$rel] = $path;
        }
        return $out;
    }

    public function test_every_reader_of_participation_numbers_filters_training_or_is_allow_listed(): void
    {
        $offenders = [];
        foreach ($this->projectFiles() as $rel => $path) {
            $src = file_get_contents($path);
            $reads = preg_match('/actual_hours|attended\s*=\s*1|attended=1|points_awarded/', $src) === 1;
            if (!$reads) {
                continue;
            }
            if (strpos($src, 'is_training') !== false || isset(self::ALLOWED[$rel])) {
                continue;
            }
            $offenders[] = $rel;
        }
        $this->assertSame(
            [],
            $offenders,
            "These files read hours/attendance/points but neither filter is_training nor are allow-listed with a reason:\n  "
            . implode("\n  ", $offenders)
        );
    }

    public function test_the_reporting_surfaces_all_filter(): void
    {
        $root = realpath(__DIR__ . '/..');
        foreach (self::MUST_FILTER as $rel) {
            $src = file_get_contents($root . '/' . $rel);
            $this->assertNotFalse($src, "cannot read {$rel}");
            $this->assertStringContainsString('is_training', $src, "{$rel} is a reporting surface and must filter training");
        }
    }

    public function test_the_allow_list_has_no_stale_entries(): void
    {
        $root = realpath(__DIR__ . '/..');
        foreach (array_keys(self::ALLOWED) as $rel) {
            $this->assertFileExists($root . '/' . $rel, "allow-list names a file that no longer exists: {$rel}");
        }
    }
}
