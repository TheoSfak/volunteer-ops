<?php
/**
 * VolunteerOps - Database Diagnostic Script
 * Checks schema version, table existence, and migration status.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: text/plain; charset=utf-8');

echo "=== VolunteerOps DB Diagnostic ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max execution time: " . ini_get('max_execution_time') . "s\n";
echo "Memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
echo "Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
echo "DB: " . DB_NAME . " @ " . DB_HOST . "\n\n";

// Schema version. Compared against DB_SCHEMA_VERSION rather than a literal:
// this used to hardcode 36, so once the real version passed 36 it printed
// "up-to-date" for every database it would ever see, including one a hundred
// migrations behind — on the one page someone opens precisely because they
// suspect a migration did not apply.
try {
    $ver = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
    echo "DB schema version: " . ($ver ?? 'NOT SET') . " (expected: " . DB_SCHEMA_VERSION . ")\n";
    if ($ver === null) {
        echo "⚠ NOT SET — no migrations have ever been recorded on this database.\n";
    } elseif ((int)$ver < DB_SCHEMA_VERSION) {
        echo "⚠ MIGRATIONS PENDING — schema is " . (DB_SCHEMA_VERSION - (int)$ver) . " version(s) behind. This causes heavy processing on every request.\n";
    } elseif ((int)$ver > DB_SCHEMA_VERSION) {
        // Not academic: it means the files were rolled back (or only half
        // uploaded) while the database kept the newer schema, so the code is
        // older than the data it is running against.
        echo "⚠ SCHEMA AHEAD OF CODE — the database is at " . (int)$ver . " but this deployment's files only know about " . DB_SCHEMA_VERSION . ". Check the upload completed.\n";
    } else {
        echo "✓ Schema version is current.\n";
    }
    // The version is self-reported: runSchemaMigrations() writes it after a
    // migration's callable returns without throwing, which is not the same as
    // the ALTER having actually taken effect. A green line above is a good
    // sign, not proof — the failure block further down, and spot-checking the
    // columns a recent migration was supposed to add, are the real evidence.
    echo "  (version is self-reported — see 'Migration Failure' below before trusting it)\n";
} catch (Exception $e) {
    echo "✗ Cannot read schema version: " . $e->getMessage() . "\n";
}

echo "\n--- Critical Tables ---\n";
$tables = [
    'users', 'missions', 'shifts', 'participation_requests',
    'settings', 'email_templates', 'notification_settings', 'audit_logs',
    'citizens', 'citizen_certificates', 'citizen_certificate_types',
    'volunteer_points', 'notifications',
];

foreach ($tables as $table) {
    try {
        $exists = dbFetchOne(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$table]
        );
        if ($exists) {
            $count = dbFetchValue("SELECT COUNT(*) FROM `{$table}`");
            echo "  ✓ {$table} — {$count} rows\n";
        } else {
            echo "  ✗ {$table} — MISSING!\n";
        }
    } catch (Exception $e) {
        echo "  ✗ {$table} — ERROR: " . $e->getMessage() . "\n";
    }
}

// Check citizen_certificates columns
echo "\n--- citizen_certificates columns ---\n";
try {
    $cols = dbFetchAll(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'citizen_certificates' ORDER BY ORDINAL_POSITION"
    );
    foreach ($cols as $c) {
        echo "  " . $c['COLUMN_NAME'] . "\n";
    }
    if (empty($cols)) echo "  (table not found or no columns)\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

// Check migration failure cooldown
echo "\n--- Migration Failure ---\n";
try {
    $failTime = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'migration_last_failure'");
    if ($failTime) {
        echo "  Last failure: {$failTime} (" . date('Y-m-d H:i:s', (int)$failTime) . ")\n";
    } else {
        echo "  No failures recorded.\n";
    }
    $failMsg = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'migration_last_error'");
    if ($failMsg) {
        echo "  Last error: {$failMsg}\n";
    }
} catch (Exception $e) {
    echo "  (could not check)\n";
}

echo "\n--- Email Templates ---\n";
try {
    $rows = dbFetchAll("SELECT id, code, name FROM email_templates ORDER BY id");
    foreach ($rows as $r) {
        echo "  {$r['id']} | {$r['code']} | {$r['name']}\n";
    }
    echo "  Total: " . count($rows) . "\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\nMemory peak: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
echo "=== Done ===\n";
