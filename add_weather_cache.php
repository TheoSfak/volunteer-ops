<?php
/**
 * Migration utility: creates the weather_cache table for existing installs.
 * Run once from the browser (admin only) or via CLI.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$sql = "
CREATE TABLE IF NOT EXISTS `weather_cache` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `weather_json` TEXT NOT NULL,
    `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_weather_mission` (`mission_id`),
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec($sql);
    $exists = dbFetchValue("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'weather_cache'");
    if ($exists) {
        echo '<p style="color:green;font-family:monospace;">✓ Ο πίνακας weather_cache δημιουργήθηκε/υπάρχει ήδη.</p>';
    } else {
        echo '<p style="color:red;font-family:monospace;">✗ Αποτυχία δημιουργίας πίνακα.</p>';
    }
} catch (Exception $e) {
    echo '<p style="color:red;font-family:monospace;">Σφάλμα: ' . h($e->getMessage()) . '</p>';
}

echo '<p><a href="settings.php">← Επιστροφή στις Ρυθμίσεις</a></p>';
