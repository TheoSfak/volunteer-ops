<?php
/**
 * Delete test mission
 */
require_once __DIR__ . '/bootstrap.php';

echo "Searching for test missions...\n\n";

// Find missions with TEST in title
$testMissions = dbFetchAll(
    "SELECT * FROM missions WHERE title LIKE '%TEST%' OR title LIKE '%δοκιμαστική%' OR title LIKE '%dokimastiki%'",
    []
);

if (empty($testMissions)) {
    echo "No test missions found.\n";
    exit(0);
}

echo "Found " . count($testMissions) . " test mission(s):\n\n";

foreach ($testMissions as $mission) {
    echo "ID: {$mission['id']}\n";
    echo "Title: {$mission['title']}\n";
    echo "Status: {$mission['status']}\n";
    echo "Created: {$mission['created_at']}\n";
    
    // Check for shifts
    $shiftCount = dbFetchValue("SELECT COUNT(*) FROM shifts WHERE mission_id = ?", [$mission['id']]);
    echo "Shifts: $shiftCount\n";
    
    // Check for participations
    $participationCount = dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr 
         JOIN shifts s ON pr.shift_id = s.id 
         WHERE s.mission_id = ?",
        [$mission['id']]
    );
    echo "Participations: $participationCount\n";
    echo str_repeat("-", 50) . "\n";
}

echo "\nDeleting test missions...\n";

foreach ($testMissions as $mission) {
    try {
        // Delete mission (CASCADE will delete shifts and participation requests)
        dbExecute("DELETE FROM missions WHERE id = ?", [$mission['id']]);
        echo "✓ Deleted mission: {$mission['title']} (ID: {$mission['id']})\n";
    } catch (Exception $e) {
        echo "✗ Error deleting mission {$mission['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n✓ Done!\n";
