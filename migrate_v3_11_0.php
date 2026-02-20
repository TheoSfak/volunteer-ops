<?php
/**
 * Database Migration - v3.11.0
 *
 * Run once: http://yoursite.com/migrate_v3_11_0.php
 * Then DELETE this file from the server.
 *
 * Changes:
 *  1. exam_attempts:  rename completed_at → submitted_at (if not already done)
 *  2. quiz_attempts:  rename completed_at → submitted_at (if not already done)
 *  3. users:          add profile_photo column (if missing)
 */

require_once __DIR__ . '/bootstrap.php';

// Only system admins (or run from CLI)
if (php_sapi_name() !== 'cli') {
    requireLogin();
    if (!isSystemAdmin()) {
        die('Απαιτούνται δικαιώματα System Admin.');
    }
}

$pdo = getDb();
$results = [];

function hasColumn(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

function migrate(PDO $pdo, string $label, string $sql, array &$results): void {
    try {
        $pdo->exec($sql);
        $results[] = ['ok', $label];
    } catch (PDOException $e) {
        $results[] = ['err', $label . ' — ' . $e->getMessage()];
    }
}

// 1. exam_attempts: completed_at → submitted_at
if (hasColumn($pdo, 'exam_attempts', 'completed_at') && !hasColumn($pdo, 'exam_attempts', 'submitted_at')) {
    migrate($pdo, 'exam_attempts: rename completed_at → submitted_at',
        "ALTER TABLE exam_attempts CHANGE COLUMN completed_at submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        $results);
} elseif (hasColumn($pdo, 'exam_attempts', 'submitted_at')) {
    $results[] = ['skip', 'exam_attempts.submitted_at already exists'];
} else {
    $results[] = ['skip', 'exam_attempts: neither column found — check schema'];
}

// 2. quiz_attempts: completed_at → submitted_at
if (hasColumn($pdo, 'quiz_attempts', 'completed_at') && !hasColumn($pdo, 'quiz_attempts', 'submitted_at')) {
    migrate($pdo, 'quiz_attempts: rename completed_at → submitted_at',
        "ALTER TABLE quiz_attempts CHANGE COLUMN completed_at submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        $results);
} elseif (hasColumn($pdo, 'quiz_attempts', 'submitted_at')) {
    $results[] = ['skip', 'quiz_attempts.submitted_at already exists'];
} else {
    $results[] = ['skip', 'quiz_attempts: neither column found — check schema'];
}

// 3. users: add profile_photo
if (!hasColumn($pdo, 'users', 'profile_photo')) {
    migrate($pdo, 'users: add profile_photo column',
        "ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER phone",
        $results);
} else {
    $results[] = ['skip', 'users.profile_photo already exists'];
}

// Output
if (php_sapi_name() === 'cli') {
    foreach ($results as [$status, $msg]) {
        echo strtoupper($status) . ": $msg\n";
    }
    exit(0);
}

$pageTitle = 'Migration v3.11.0';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-4" style="max-width:700px">
    <h2 class="mb-4"><i class="bi bi-database-gear me-2"></i>Migration v3.11.0</h2>
    <div class="list-group">
        <?php foreach ($results as [$status, $msg]): ?>
            <?php
            $icon  = match($status) { 'ok' => 'check-circle-fill', 'skip' => 'dash-circle', default => 'x-circle-fill' };
            $color = match($status) { 'ok' => 'success', 'skip' => 'secondary', default => 'danger' };
            ?>
            <div class="list-group-item d-flex align-items-center gap-2">
                <i class="bi bi-<?= $icon ?> text-<?= $color ?>"></i>
                <span><?= h($msg) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="alert alert-warning mt-4">
        <strong>Σημαντικό:</strong> Διαγράψτε αυτό το αρχείο από τον server μετά την εκτέλεση.
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
