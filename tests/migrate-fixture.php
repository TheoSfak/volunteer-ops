<?php
/**
 * Brings the shared test database up to DB_SCHEMA_VERSION before PHPUnit runs.
 *
 * WHY THIS EXISTS: tests/fixtures/schema-structure.sql is a mysqldump snapshot
 * and it lags — it is refreshed occasionally, not on every migration, and that
 * is fine by design (MigrationsRunnerTest exists precisely to prove a
 * part-migrated database can be brought forward). But FreshInstallTest's own
 * docblock states the contract the rest of the suite relies on: the shared
 * TEST_DB_NAME fixture is "already fully migrated". Nothing was enforcing it.
 *
 * The result was CI's PHPUnit job failing from the moment migration 154 added
 * ai_translation_cache — every run from v3.262.x onward — because
 * AiTranslateTest reads a table the snapshot had never heard of. It went
 * unnoticed for eight releases: the job was red, but so was the lint job for
 * an unrelated reason, so "CI is red" stopped carrying information.
 *
 * It passes locally for the worst possible reason: a developer's test database
 * accumulates tables from earlier runs of MigrationsRunnerTest, so the very
 * environment least like CI is the one that looks healthiest.
 *
 * Requiring includes/migrations.php runs runSchemaMigrations() as a side
 * effect — that is the file's documented behaviour, and the same path every
 * real page load takes.
 *
 * Usage (see .github/workflows/ci.yml): php tests/migrate-fixture.php
 */

require __DIR__ . '/bootstrap.php';

$before = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");

require __DIR__ . '/../includes/migrations.php';

$after = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");

echo "fixture schema: {$before} -> {$after} (target " . DB_SCHEMA_VERSION . ")\n";

if ($after < DB_SCHEMA_VERSION) {
    // Loud, not silent: migrations swallow their own failures by design (a
    // stuck migration must not fatal a live page), so the only way this shows
    // up as a broken build rather than as a confusing test failure later is to
    // check the version ourselves.
    $err = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'migration_last_error'");
    fwrite(STDERR, "migrations did not reach DB_SCHEMA_VERSION" . ($err ? ": {$err}" : '') . "\n");
    exit(1);
}
