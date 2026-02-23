<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

// Exams are only for trainee rescuers and admins
$user = getCurrentUser();
if (!isAdmin() && !isTraineeRescuer($user)) {
    setFlash('error', 'Τα διαγωνίσματα είναι διαθέσιμα μόνο για Δόκιμους Διασώστες.');
    redirect('training.php');
}

$pageTitle = 'Διαγωνίσματα Εκπαίδευσης';
$userId = $user['id'];

// Get filter
$categoryFilter = get('category', '');

// Build query
$where = ['te.is_active = 1'];
$params = [];

if (!empty($categoryFilter)) {
    $where[] = 'te.category_id = ?';
    $params[] = $categoryFilter;
}

// Fetch exams with question count and user attempts
$exams = dbFetchAll("
    SELECT te.*, tc.name as category_name, tc.icon as category_icon,
           (SELECT COUNT(*) FROM training_exam_questions WHERE exam_id = te.id) as question_count
    FROM training_exams te
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY te.created_at DESC
", $params);

// Get user attempts for these exams
$attemptsByExam = [];
$attemptsCountByExam = [];
if (!empty($exams)) {
    $examIds = array_column($exams, 'id');
    $placeholders = str_repeat('?,', count($examIds) - 1) . '?';
    $attempts = dbFetchAll("
        SELECT * FROM exam_attempts 
        WHERE exam_id IN ($placeholders) AND user_id = ?
        ORDER BY completed_at DESC
    ", array_merge($examIds, [$userId]));
    
    foreach ($attempts as $attempt) {
        // Keep the latest (first due to ORDER BY DESC)
        if (!isset($attemptsByExam[$attempt['exam_id']])) {
            $attemptsByExam[$attempt['exam_id']] = $attempt;
        }
        // Count all completed attempts
        if ($attempt['completed_at']) {
            $attemptsCountByExam[$attempt['exam_id']] = ($attemptsCountByExam[$attempt['exam_id']] ?? 0) + 1;
        }
    }
}

// Get categories for filter
$categories = dbFetchAll("
    SELECT id, name, icon 
    FROM training_categories 
    WHERE is_active = 1 
    ORDER BY display_order, name
");

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-award me-2"></i>Επίσημα Διαγωνίσματα
        </h1>
        <a href="training.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Επιστροφή
        </a>
    </div>
    
    <!-- Info Alert -->
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Σημαντικό:</strong> Τα διαγωνίσματα είναι επίσημα τεστ και τα αποτελέσματα καταγράφονται στο ιστορικό σας. Ο αριθμός των επιτρεπόμενων προσπαθειών ορίζεται από τον διαχειριστή.
    </div>
    
    <!-- Filters -->
    <?php if (!empty($categories)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Κατηγορία</label>
                        <select name="category" class="form-select">
                            <option value="">Όλες οι Κατηγορίες</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-funnel"></i> Φιλτράρισμα
                        </button>
                        <a href="training-exams.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Καθαρισμός
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Exams List -->
    <?php if (empty($exams)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Δεν βρέθηκαν διαθέσιμα διαγωνίσματα <?= $categoryFilter ? 'για την επιλεγμένη κατηγορία' : '' ?>.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($exams as $exam): ?>
                <?php 
                $userAttempt = $attemptsByExam[$exam['id']] ?? null;
                $attemptsUsed = $attemptsCountByExam[$exam['id']] ?? 0;
                $maxAttempts = (int) ($exam['max_attempts'] ?? 1);
                $attemptsRemaining = max(0, $maxAttempts - $attemptsUsed);
                $hasAttempt = $userAttempt !== null && $userAttempt['completed_at'];
                $allAttemptsUsed = $attemptsUsed >= $maxAttempts;
                $availability = isExamAvailable($exam);
                $isAvailable = $availability['available'];
                ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm <?= $allAttemptsUsed ? 'border-success' : '' ?>">
                        <div class="card-body">
                            <!-- Header -->
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="badge bg-warning">
                                    <?= h($exam['category_icon']) ?> <?= h($exam['category_name']) ?>
                                </span>
                                <div class="d-flex gap-1 align-items-center">
                                    <?php if ($maxAttempts > 1): ?>
                                        <span class="badge bg-light text-dark border">
                                            <?= $attemptsUsed ?>/<?= $maxAttempts ?> προσπάθειες
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($hasAttempt && $allAttemptsUsed): ?>
                                        <span class="badge <?= $userAttempt['passed'] ? 'bg-success' : 'bg-danger' ?>">
                                            Ολοκληρωμένο
                                        </span>
                                    <?php elseif ($hasAttempt): ?>
                                        <span class="badge bg-info">
                                            Έγινε <?= $attemptsUsed ?>x
                                        </span>
                                    <?php else: ?>
                                        <?= examAvailabilityBadge($exam) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Title -->
                            <h5 class="card-title mb-2"><?= h($exam['title']) ?></h5>
                            
                            <!-- Description -->
                            <?php if (!empty($exam['description'])): ?>
                                <p class="text-muted small mb-3"><?= nl2br(h($exam['description'])) ?></p>
                            <?php endif; ?>
                            
                            <!-- Info -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between text-sm mb-1">
                                    <span><i class="bi bi-question-circle"></i> <?= $exam['questions_per_attempt'] ?> τυχαίες ερωτήσεις από <?= $exam['question_count'] ?></span>
                                </div>
                                <div class="d-flex justify-content-between text-sm mb-1">
                                    <span><i class="bi bi-check-circle"></i> Όριο επιτυχίας: <?= $exam['passing_percentage'] ?>%</span>
                                </div>
                                <?php if ($exam['time_limit_minutes']): ?>
                                    <div class="d-flex justify-content-between text-sm mb-1">
                                        <span><i class="bi bi-clock"></i> Χρόνος: <?= $exam['time_limit_minutes'] ?> λεπτά</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Attempt Result or Start Button -->
                            <?php if ($hasAttempt): ?>
                                <div class="alert alert-<?= $userAttempt['passed'] ? 'success' : 'danger' ?> mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span><strong>Βαθμός:</strong> <?= $userAttempt['score'] ?> / <?= $userAttempt['total_questions'] ?></span>
                                        <span><strong><?= round(($userAttempt['score'] / $userAttempt['total_questions']) * 100, 1) ?>%</strong></span>
                                    </div>
                                    <div><strong>Αποτέλεσμα:</strong> <?= $userAttempt['passed'] ? 'ΕΠΙΤΥΧΙΑ' : 'ΑΠΟΤΥΧΙΑ' ?></div>
                                </div>
                                <a href="exam-results.php?attempt_id=<?= $userAttempt['id'] ?>" class="btn btn-outline-primary w-100 mb-2">
                                    <i class="bi bi-eye"></i> Προβολή Ανασκόπησης
                                </a>
                                <?php if (!$allAttemptsUsed && $isAvailable): ?>
                                    <a href="exam-take.php?id=<?= $exam['id'] ?>" class="btn btn-warning w-100 text-white">
                                        <i class="bi bi-arrow-repeat"></i> Ξαναπροσπάθεια (<?= $attemptsRemaining ?> ακόμα)
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (!$isAvailable): ?>
                                    <div class="alert alert-<?= $availability['status'] === 'expired' ? 'danger' : 'info' ?> mb-3">
                                        <i class="bi bi-clock-history"></i> <?= h($availability['message']) ?>
                                    </div>
                                    <button class="btn btn-secondary w-100" disabled>
                                        <i class="bi bi-lock"></i> Μη Διαθέσιμο
                                    </button>
                                <?php elseif ($exam['question_count'] < $exam['questions_per_attempt']): ?>
                                    <div class="alert alert-warning small mb-3">
                                        <i class="bi bi-exclamation-triangle"></i> Μη διαθέσιμο: Δεν υπάρχουν αρκετές ερωτήσεις
                                    </div>
                                    <button class="btn btn-secondary w-100" disabled>
                                        <i class="bi bi-lock"></i> Μη Διαθέσιμο
                                    </button>
                                <?php else: ?>
                                    <?php if (!empty($exam['available_until'])): ?>
                                        <div class="alert alert-warning small mb-2">
                                            <i class="bi bi-hourglass-split"></i> Οριακή ημερομηνία: <?= formatDateTime($exam['available_until']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <a href="exam-take.php?id=<?= $exam['id'] ?>" class="btn btn-warning w-100 text-white">
                                        <i class="bi bi-play-fill"></i> Έναρξη Διαγωνίσματος
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
