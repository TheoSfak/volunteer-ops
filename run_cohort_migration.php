<?php
/**
 * Migration Script: Add cohort_year to users table
 * Run this once to add cohort tracking for trainee rescuer statistics
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

// Check if already migrated
$columns = dbFetchAll("SHOW COLUMNS FROM users LIKE 'cohort_year'");
if (!empty($columns)) {
    echo "✅ Migration already applied - cohort_year column exists.\n";
    exit(0);
}

echo "Starting migration: Adding cohort_year column to users table...\n";

try {
    // Read and execute migration SQL
    $sql = file_get_contents(__DIR__ . '/sql/migrations/add_cohort_year.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        dbExecute($statement);
    }
    
    echo "✅ Migration completed successfully!\n";
    echo "📊 Cohort year tracking is now available.\n";
    echo "⚠️  Admins must manually set cohort_year for existing trainee rescuers.\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
