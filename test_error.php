<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Step 1: Starting<br>";

try {
    require_once __DIR__ . '/bootstrap.php';
    echo "Step 2: Bootstrap loaded<br>";
    
    // Test DB
    $count = dbFetchValue("SELECT COUNT(*) FROM participation_requests");
    echo "Step 3: DB works - participation_requests count: $count<br>";
    
    // Test if PARTICIPATION_LABELS exists
    if (defined('PARTICIPATION_LABELS') || isset($GLOBALS['PARTICIPATION_LABELS'])) {
        echo "Step 4: PARTICIPATION_LABELS exists<br>";
    } else {
        echo "Step 4: PARTICIPATION_LABELS MISSING!<br>";
    }
    
    echo "Step 5: All OK!<br>";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
}
