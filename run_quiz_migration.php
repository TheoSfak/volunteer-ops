<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$migrationFile = __DIR__ . '/sql/migrations/add_quiz_selection_fields.sql';

if (!file_exists($migrationFile)) {
    die('Migration file not found!');
}

$sql = file_get_contents($migrationFile);

try {
    $db = getDB();
    $db->exec($sql);
    echo "✅ Migration completed successfully!<br>";
    echo "Added quiz selection fields: questions_per_attempt, passing_percentage<br>";
    echo "Added quiz_attempts fields: selected_questions_json, passing_percentage, passed<br>";
    echo "Added training_user_progress field: quizzes_passed<br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
