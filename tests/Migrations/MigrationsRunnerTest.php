<?php

declare(strict_types=1);

namespace Tests\Migrations;

use PHPUnit\Framework\TestCase;

/**
 * Exercises includes/migrations.php's runSchemaMigrations() against a real,
 * disposable database (cloned structure from the dev DB, see tests/README.md)
 * rather than mocks - the whole point is proving the real SQL runs cleanly
 * end to end and that the runner's own control flow (version gate, cooldown,
 * stop-on-first-failure) behaves correctly.
 *
 * includes/migrations.php calls runSchemaMigrations() as a side effect of
 * being require'd, so the file is only ever require_once'd here, the very
 * first time any test needs it - after that, the already-defined function is
 * called directly so each test controls exactly when a run happens.
 */
final class MigrationsRunnerTest extends TestCase
{
    private static bool $migrationsFileLoaded = false;

    protected function setUp(): void
    {
        dbExecute("DELETE FROM settings WHERE setting_key IN ('db_schema_version', 'migration_last_failure')");
    }

    public function testFreshInstallAppliesEveryMigrationUpToLatestVersion(): void
    {
        // First-ever load triggers the real run, starting from version 0
        // (setUp() just deleted the version row) - this is the fresh-install path.
        $this->loadMigrationsFileOnce();

        $this->assertSame(
            DB_SCHEMA_VERSION,
            $this->currentSchemaVersion(),
            'runSchemaMigrations() should bring a version-0 database all the way to DB_SCHEMA_VERSION'
        );
    }

    public function testAlreadyUpToDateIsANoOpQuickReturn(): void
    {
        $this->loadMigrationsFileOnce();
        $this->setSchemaVersion(DB_SCHEMA_VERSION);

        runSchemaMigrations();

        $this->assertSame(
            DB_SCHEMA_VERSION,
            $this->currentSchemaVersion(),
            'a database already at DB_SCHEMA_VERSION should be untouched (quick-return path)'
        );
    }

    public function testRunningTwiceInARowIsIdempotent(): void
    {
        $this->loadMigrationsFileOnce();
        $this->setSchemaVersion(0);

        runSchemaMigrations();
        $firstRun = $this->currentSchemaVersion();
        runSchemaMigrations();
        $secondRun = $this->currentSchemaVersion();

        $this->assertSame(DB_SCHEMA_VERSION, $firstRun);
        $this->assertSame($firstRun, $secondRun, 're-running against an already-migrated schema must not error or change the version');
    }

    public function testRecentFailureCooldownSkipsRetryForFiveMinutes(): void
    {
        $this->loadMigrationsFileOnce();
        $this->setSchemaVersion(0);
        $this->setMigrationLastFailure(time()); // "just failed"

        runSchemaMigrations();

        $this->assertSame(
            0,
            $this->currentSchemaVersion(),
            'a failure recorded seconds ago should block any retry until the 5-minute cooldown elapses'
        );
    }

    public function testExpiredCooldownAllowsRetry(): void
    {
        $this->loadMigrationsFileOnce();
        $this->setSchemaVersion(0);
        $this->setMigrationLastFailure(time() - 301); // just past the 5-minute window

        runSchemaMigrations();

        $this->assertSame(
            DB_SCHEMA_VERSION,
            $this->currentSchemaVersion(),
            'once the cooldown has expired, a version-0 database should migrate normally again'
        );
    }

    /**
     * Regression test for a real production incident (epidrasi.iloveweb.gr,
     * found 2026-08-09): migration v128 originally hardcoded mission_types
     * id 7 for "Αναζήτηση Αγνοουμένου", assuming that id would always be
     * free. On a database where an admin had already created a custom type
     * via mission-types.php that happened to land on id 7 (there: "Τ.Ε.Π.",
     * 61 real missions), v128's INSERT threw a duplicate-PK error - and
     * because the runner stops at the first failure, that silently blocked
     * every migration after v128 forever, not just v128 itself.
     *
     * v128 was later patched to skip-and-log instead of crashing (so it
     * stops holding the queue hostage), but that alone still left the
     * missing-person type permanently uncreated on that database. v134 is
     * the actual fix: it creates the row without pinning an id, so it lands
     * wherever the database's own auto-increment allows - this test proves
     * that self-healing path directly, by manufacturing the exact id-7
     * collision that broke production.
     *
     * Deliberately asserts against the database directly, not against
     * missingPersonMissionTypeId() (functions-warroom.php) - that function
     * caches its result in a function-local static for the life of the PHP
     * process, and PHPUnit runs this whole suite in one process, so a call
     * here could silently observe a value cached by test ordering rather
     * than this test's own scenario. The migration's actual DB effect,
     * asserted below, is what had a real incident behind it.
     */
    public function testMissingPersonTypeIsCreatedEvenWhenIdSevenIsAlreadyTaken(): void
    {
        $this->loadMigrationsFileOnce();

        $realRow = dbFetchOne("SELECT * FROM mission_types WHERE name = ?", ['Αναζήτηση Αγνοουμένου']);
        $fakeConflictInserted = false;

        try {
            dbExecute("DELETE FROM mission_types WHERE name = ?", ['Αναζήτηση Αγνοουμένου']);

            // Simulate the real incident: something else already sits at id 7.
            if (!dbFetchOne("SELECT id FROM mission_types WHERE id = 7")) {
                dbExecute(
                    "INSERT INTO mission_types (id, name, description, color, icon, sort_order, is_active) VALUES (7, ?, ?, ?, ?, ?, ?)",
                    ['Τ.Ε.Π. (test fixture)', 'Fake pre-existing type for MigrationsRunnerTest', 'secondary', 'bi-question', 99, 1]
                );
                $fakeConflictInserted = true;
            }

            $this->setSchemaVersion(133); // one below v134, so only v134 is pending
            runSchemaMigrations();

            $this->assertSame(
                DB_SCHEMA_VERSION,
                $this->currentSchemaVersion(),
                'v134 must reach completion (not get stuck) even when id 7 is taken by something else'
            );

            $newRow = dbFetchOne("SELECT id FROM mission_types WHERE name = ?", ['Αναζήτηση Αγνοουμένου']);
            $this->assertNotNull($newRow, 'v134 must create the missing-person type somewhere, even when id 7 is unavailable');
            $this->assertNotSame(7, (int) $newRow['id'], 'the whole point of this scenario is that it must NOT be forced into the taken id 7');
        } finally {
            // Restore the shared test database to exactly the state every
            // other test expects, regardless of whether an assertion above
            // failed partway through.
            dbExecute("DELETE FROM mission_types WHERE name = ?", ['Αναζήτηση Αγνοουμένου']);
            if ($fakeConflictInserted) {
                dbExecute("DELETE FROM mission_types WHERE id = 7 AND name = ?", ['Τ.Ε.Π. (test fixture)']);
            }
            if ($realRow) {
                dbExecute(
                    "INSERT INTO mission_types (id, name, description, color, icon, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$realRow['id'], $realRow['name'], $realRow['description'], $realRow['color'], $realRow['icon'], $realRow['sort_order'], $realRow['is_active']]
                );
            }
        }
    }

    private function loadMigrationsFileOnce(): void
    {
        if (!self::$migrationsFileLoaded) {
            require_once __DIR__ . '/../../includes/migrations.php';
            self::$migrationsFileLoaded = true;
        }
    }

    private function currentSchemaVersion(): int
    {
        return (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
    }

    private function setSchemaVersion(int $version): void
    {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('db_schema_version', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [(string) $version]
        );
    }

    private function setMigrationLastFailure(int $timestamp): void
    {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('migration_last_failure', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [(string) $timestamp]
        );
    }
}
