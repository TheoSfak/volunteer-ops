<?php
/**
 * Debug Script - Check canUserTakeExam() function version
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Debug: canUserTakeExam()';

// Get the actual function code
$functionsFile = file_get_contents(__DIR__ . '/includes/functions.php');

// Find the canUserTakeExam function
preg_match('/function canUserTakeExam\(.*?\}(?=\s*\/\*\*|\s*function|\s*$)/s', $functionsFile, $matches);

$functionCode = $matches[0] ?? 'Function not found';

// Check if it has the fix
$hasFix = strpos($functionCode, 'completed_at IS NOT NULL') !== false;
$hasOldBug = strpos($functionCode, 'completed_at IS NOT NULL') === false && strpos($functionCode, 'exam_attempts') !== false;

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1><i class="bi bi-bug me-2"></i><?= h($pageTitle) ?></h1>
            <p class="text-muted">Έλεγχος αν το production έχει το fix για το exam restriction bug</p>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Status</h5>
        </div>
        <div class="card-body">
            <?php if ($hasFix): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <strong>✅ FIX INSTALLED:</strong> Η function έχει τη διόρθωση (ελέγχει για completed_at IS NOT NULL)
                </div>
            <?php elseif ($hasOldBug): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle me-2"></i>
                    <strong>❌ OLD BUG VERSION:</strong> Η function ΔΕΝ έχει τη διόρθωση. Χρειάζεται update!
                </div>
                <div class="alert alert-warning">
                    <strong>Λύση:</strong> Κάνε update στο production site στην v2.2.9 ή νεότερη
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="bi bi-question-circle me-2"></i>
                    Could not determine status
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">Current Function Code</h5>
        </div>
        <div class="card-body">
            <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto;"><code><?= h($functionCode) ?></code></pre>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-0">App Version</h5>
        </div>
        <div class="card-body">
            <p><strong>Version:</strong> <?= h(APP_VERSION) ?></p>
            <p class="mb-0"><strong>Required version for fix:</strong> 2.2.9 or newer</p>
            <?php if (version_compare(APP_VERSION, '2.2.9', '<')): ?>
                <div class="alert alert-danger mt-3">
                    ❌ Outdated version! Update to v2.2.9 or newer
                </div>
            <?php else: ?>
                <div class="alert alert-success mt-3">
                    ✅ Version is up to date
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Database Check</h5>
        </div>
        <div class="card-body">
            <?php
            $userId = getCurrentUserId();
            $incompleteCount = dbFetchValue("SELECT COUNT(*) FROM exam_attempts WHERE user_id = ? AND completed_at IS NULL", [$userId]);
            $completedCount = dbFetchValue("SELECT COUNT(*) FROM exam_attempts WHERE user_id = ? AND completed_at IS NOT NULL", [$userId]);
            ?>
            <p><strong>Your Incomplete Attempts:</strong> <?= $incompleteCount ?></p>
            <p><strong>Your Completed Attempts:</strong> <?= $completedCount ?></p>
            
            <?php if ($incompleteCount > 0): ?>
                <div class="alert alert-warning mt-3">
                    You have <?= $incompleteCount ?> incomplete attempt(s). 
                    <a href="cleanup_incomplete_attempts.php">Clean them up</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
