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
