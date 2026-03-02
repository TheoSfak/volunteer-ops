<?php
/**
 * One-time migration: Create complaints table
 * Τρέξτε αυτό το αρχείο μία φορά από το browser, μετά διαγράψτε το.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$sql = "CREATE TABLE IF NOT EXISTS `complaints` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `mission_id` INT UNSIGNED NULL,
    `category` ENUM('MISSION','EQUIPMENT','BEHAVIOR','ADMIN','OTHER') NOT NULL DEFAULT 'OTHER',
    `priority` ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `status` ENUM('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW',
    `admin_response` TEXT NULL,
    `responded_by` INT UNSIGNED NULL,
    `responded_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_complaint_user` (`user_id`),
    INDEX `idx_complaint_status` (`status`),
    INDEX `idx_complaint_category` (`category`),
    INDEX `idx_complaint_mission` (`mission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$error = null;
$success = false;

try {
    dbExecute($sql);
    $success = true;
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"><title>Migration: complaints</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container" style="max-width:600px">
    <h3>Migration: Δημιουργία πίνακα complaints</h3>
    <?php if ($success): ?>
        <div class="alert alert-success">
            <strong>✅ Επιτυχία!</strong> Ο πίνακας <code>complaints</code> δημιουργήθηκε (ή υπήρχε ήδη).<br>
            <strong>Διαγράψτε αμέσως αυτό το αρχείο από τον server!</strong>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <strong>❌ Σφάλμα:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-primary">Επιστροφή</a>
</div>
</body>
</html>
