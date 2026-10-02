<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The migration runner skips every closure whose version is <= the recorded
 * db_schema_version, so a version number used twice means the second closure
 * is silently skipped on any database that already passed the first one.
 *
 * Versions 36, 37 and 38 were each used twice long ago. They stay as they are:
 * renumbering a migration that production has already recorded as applied is
 * the one change here that could re-run or skip real work, and every one of
 * those closures is idempotent and already ran in the same pass on databases
 * that went through them. What this test does is stop it happening again.
 *
 * Reads the file as text on purpose: requiring includes/migrations.php would
 * run the migrations.
 */
final class MigrationVersionsTest extends TestCase
{
    private const KNOWN_DUPLICATES = [36, 37, 38];

    /** @return int[] */
    private function versions(): array
    {
        $src = file_get_contents(__DIR__ . '/../includes/migrations.php');
        preg_match_all("/'version'\s*=>\s*(\d+)\s*,/", (string) $src, $m);
        return array_map('intval', $m[1]);
    }

    public function testNoNewVersionNumberIsUsedTwice(): void
    {
        $counts = array_count_values($this->versions());
        $duplicated = array_keys(array_filter($counts, fn($n) => $n > 1));
        sort($duplicated);
        $this->assertSame(
            self::KNOWN_DUPLICATES,
            $duplicated,
            'A migration version number is used more than once (only 36, 37 and 38 are allowed, historically). '
            . 'Give the new migration the next unused number.'
        );
        foreach (self::KNOWN_DUPLICATES as $v) {
            $this->assertSame(2, $counts[$v], "Version $v must stay exactly twice, not more.");
        }
    }

    public function testVersionsNeverGoBackwards(): void
    {
        $versions = $this->versions();
        $this->assertNotEmpty($versions);
        // The second 37 and 38 were appended later, after 40: that stays as it
        // is, so they are not part of the ordering rule.
        $previous = 0;
        $seen = [];
        foreach ($versions as $v) {
            $seen[$v] = ($seen[$v] ?? 0) + 1;
            if ($seen[$v] > 1 && in_array($v, self::KNOWN_DUPLICATES, true)) {
                continue;
            }
            $this->assertGreaterThanOrEqual($previous, $v, "Migration $v is listed after $previous.");
            $previous = $v;
        }
    }

    public function testNewestMigrationMatchesTheSchemaVersionConstant(): void
    {
        $this->assertSame(DB_SCHEMA_VERSION, max($this->versions()));
    }
}
