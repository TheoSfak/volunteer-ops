<?php
/**
 * Cleanup Script - Remove Incomplete Exam Attempts
 * This fixes the bug where users see "You have already completed this exam"
 * even though they haven't finished it.
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Καθαρισμός Μη-Ολοκληρωμένων Προσπαθειών';

// Handle cleanup action
if (isPost()) {
    verifyCsrf();
    
    // Delete all incomplete attempts
    $deleted = dbExecute("DELETE FROM exam_attempts WHERE submitted_at IS NULL");
    
    setFlash('success', "Διαγράφηκαν {$deleted} μη-ολοκληρωμένες προσπάθειες.");
    redirect('cleanup_incomplete_attempts.php');
}

// Get incomplete attempts
$incompleteAttempts = dbFetchAll("
    SELECT 
        ea.*,
        u.name as user_name,
        u.email as user_email,
        te.title as exam_title
    FROM exam_attempts ea
    INNER JOIN users u ON ea.user_id = u.id
    INNER JOIN training_exams te ON ea.exam_id = te.id
    WHERE ea.submitted_at IS NULL
    ORDER BY ea.created_at DESC
");

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1><i class="bi bi-trash me-2"></i><?= h($pageTitle) ?></h1>
            <p class="text-muted">Διαγραφή μη-ολοκληρωμένων προσπαθειών διαγωνισμάτων που μπλοκάρουν τους χρήστες</p>
        </div>
    </div>

    <?= displayFlash() ?>

    <?php if (empty($incompleteAttempts)): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-2"></i>
            Δεν υπάρχουν μη-ολοκληρωμένες προσπάθειες. Όλα καθαρά!
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-exclamation-triangle me-2 text-warning"></i>
                    Μη-Ολοκληρωμένες Προσπάθειες (<?= count($incompleteAttempts) ?>)
                </h5>
                <form method="post" onsubmit="return confirm('Είστε σίγουροι ότι θέλετε να διαγράψετε όλες τις μη-ολοκληρωμένες προσπάθειες;');">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-2"></i>Διαγραφή Όλων
                    </button>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Χρήστης</th>
                                <th>Email</th>
                                <th>Διαγώνισμα</th>
                                <th>Ημερομηνία Έναρξης</th>
                                <th>Επιλεγμένες Ερωτήσεις</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incompleteAttempts as $attempt): ?>
                                <tr>
                                    <td><?= h($attempt['id']) ?></td>
                                    <td><?= h($attempt['user_name']) ?></td>
                                    <td><?= h($attempt['user_email']) ?></td>
                                    <td><?= h($attempt['exam_title']) ?></td>
                                    <td><?= formatDateTime($attempt['created_at']) ?></td>
                                    <td>
                                        <?php 
                                        $questions = json_decode($attempt['selected_questions_json'], true);
                                        echo count($questions) . ' ερωτήσεις';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-info mt-3">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Τι κάνει αυτό το script:</strong>
            <ul class="mb-0 mt-2">
                <li>Διαγράφει προσπάθειες διαγωνισμάτων που ξεκίνησαν αλλά δεν ολοκληρώθηκαν</li>
                <li>Επιτρέπει στους χρήστες να ξαναπάρουν τα διαγωνίσματα</li>
                <li>Λύνει το bug "Έχετε ήδη ολοκληρώσει αυτό το διαγώνισμα"</li>
            </ul>
        </div>
    <?php endif; ?>

    <div class="mt-3">
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left me-2"></i>Επιστροφή
        </a>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
