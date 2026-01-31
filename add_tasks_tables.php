<?php
/**
 * Migration Script - Add Tasks Tables
 * Τρέξε αυτό για να προσθέσεις τους πίνακες tasks στη βάση
 */

require_once __DIR__ . '/bootstrap.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Add Tasks Tables</title>";
echo "<style>body{font-family:Arial;margin:40px;} .success{color:green;} .error{color:red;}</style></head><body>";
echo "<h1>🔧 Προσθήκη Tasks Tables</h1>";

try {
    $pdo = getDbConnection();
    
    echo "<h2>Έλεγχος υπαρχόντων πινάκων...</h2>";
    
    // Check if tables exist
    $tables = ['tasks', 'task_assignments', 'task_comments', 'subtasks'];
    $existing = [];
    foreach ($tables as $table) {
        $result = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
        if ($result) {
            $existing[] = $table;
        }
    }
    
    if (count($existing) > 0) {
        echo "<p class='error'>⚠️ Οι ακόλουθοι πίνακες υπάρχουν ήδη: " . implode(', ', $existing) . "</p>";
        echo "<p>Αν θέλεις να τους ξανα-δημιουργήσεις, τρέξε πρώτα:</p>";
        echo "<pre>DROP TABLE IF EXISTS task_comments, task_assignments, subtasks, tasks;</pre>";
        exit;
    }
    
    echo "<p class='success'>✓ Καμία σύγκρουση - ξεκινάμε δημιουργία πινάκων</p>";
    echo "<h2>Δημιουργία πινάκων...</h2>";
    
    // Create tasks table
    $pdo->exec("
        CREATE TABLE tasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            status ENUM('TODO', 'IN_PROGRESS', 'COMPLETED', 'CANCELED') DEFAULT 'TODO',
            priority ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') DEFAULT 'MEDIUM',
            progress INT UNSIGNED DEFAULT 0 COMMENT 'Progress percentage 0-100',
            start_date DATE NULL,
            due_date DATE NULL,
            completed_at DATETIME NULL,
            mission_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_status (status),
            INDEX idx_priority (priority),
            INDEX idx_due_date (due_date),
            INDEX idx_mission (mission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✓ Πίνακας tasks δημιουργήθηκε</p>";
    
    // Create subtasks table
    $pdo->exec("
        CREATE TABLE subtasks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            status ENUM('TODO', 'IN_PROGRESS', 'COMPLETED') DEFAULT 'TODO',
            completed_at DATETIME NULL,
            sort_order INT UNSIGNED DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            INDEX idx_task (task_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✓ Πίνακας subtasks δημιουργήθηκε</p>";
    
    // Create task_assignments table
    $pdo->exec("
        CREATE TABLE task_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            assigned_by INT UNSIGNED NOT NULL,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_assignment (task_id, user_id),
            INDEX idx_task (task_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✓ Πίνακας task_assignments δημιουργήθηκε</p>";
    
    // Create task_comments table
    $pdo->exec("
        CREATE TABLE task_comments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_task (task_id),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p class='success'>✓ Πίνακας task_comments δημιουργήθηκε</p>";
    
    echo "<hr>";
    echo "<h2 class='success'>✅ Ολοκληρώθηκε Επιτυχώς!</h2>";
    echo "<p>✓ 4 πίνακες δημιουργήθηκαν (tasks, subtasks, task_assignments, task_comments)</p>";
    echo "<p><a href='tasks.php'>Πήγαινε στις Εργασίες</a> | <a href='dashboard.php'>Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<h2 class='error'>❌ Σφάλμα!</h2>";
    echo "<p class='error'>" . h($e->getMessage()) . "</p>";
    echo "<pre>" . h($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
