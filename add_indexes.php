<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

try {
    $sql = file_get_contents(__DIR__ . '/sql/add_indexes.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        try {
            db()->exec($statement);
            $success++;
            echo "✓ " . substr($statement, 0, 60) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $skipped++;
                echo "⊘ Index already exists: " . substr($statement, 0, 40) . "...\n";
            } else {
                $errors++;
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Created: $success indexes\n";
    echo "Skipped: $skipped (already exist)\n";
    echo "Errors: $errors\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
