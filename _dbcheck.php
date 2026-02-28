<?php
/**
 * VolunteerOps - Database Diagnostic Script
 * Checks schema version, table existence, and migration status.
 */

define('VOLUNTEEROPS', true);
require __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';
require __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== VolunteerOps DB Diagnostic ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max execution time: " . ini_get('max_execution_time') . "s\n";
echo "Memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";
echo "Peak memory: " . round(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
echo "DB: " . DB_NAME . " @ " . DB_HOST . "\n\n";

// Schema version
try {
    $ver = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
    echo "DB schema version: " . ($ver ?? 'NOT SET') . " (expected: 35)\n";
    if ((int)$ver < 35) {
        echo "⚠ MIGRATIONS PENDING — schema is behind. This causes heavy processing on every request.\n";
    } else {
        echo "✓ Schema is up-to-date.\n";
    }
} catch (Exception $e) {
    echo "✗ Cannot read schema version: " . $e->getMessage() . "\n";
}

echo "\n--- Critical Tables ---\n";
$tables = [
    'users', 'missions', 'shifts', 'participation_requests',
    'settings', 'email_templates', 'notification_settings', 'audit_log',
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
        echo "  Last failure: {$failTime}\n";
    } else {
        echo "  No failures recorded.\n";
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
