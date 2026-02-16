<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$attemptId = get('attempt_id');
$user = getCurrentUser();

if (empty($attemptId)) {
    setFlash('error', 'Μη έγκυρη προσπάθεια.');
    redirect('training-quizzes.php');
}

// Fetch attempt
$attempt = dbFetchOne("
    SELECT qa.*, tq.title as quiz_title, tq.category_id,
           tc.name as category_name
    FROM quiz_attempts qa
    INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE qa.id = ? AND qa.user_id = ?
", [$attemptId, $user['id']]);

if (!$attempt) {
    setFlash('error', 'Η προσπάθεια δεν βρέθηκε.');
    redirect('training-quizzes.php');
}

// Fetch user answers with questions
$answers = dbFetchAll("
    SELECT ua.*, tqq.*,
           ua.selected_option as user_answer,
           ua.answer_text as user_answer_text,
           ua.is_correct
    FROM user_answers ua
    INNER JOIN training_quiz_questions tqq ON ua.question_id = tqq.id
    WHERE ua.attempt_id = ? AND ua.attempt_type = 'QUIZ'
    ORDER BY tqq.display_order, tqq.id
", [$attemptId]);

$percentage = $attempt['total_questions'] > 0 
    ? round(($attempt['score'] / $attempt['total_questions']) * 100, 1) 
    : 0;

$pageTitle = 'Αποτελέσματα Κουίζ';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Results Header -->
            <div class="card mb-4 bg-<?= $percentage >= 70 ? 'success' : 'warning' ?> text-white">
                <div class="card-body text-center">
                    <h2 class="mb-3"><?= h($attempt['quiz_title']) ?></h2>
                    <h1 class="display-3 mb-3"><?= $percentage ?>%</h1>
                    <h4><?= $attempt['score'] ?> / <?= $attempt['total_questions'] ?> Σωστές Απαντήσεις</h4>
                    <?php if ($attempt['time_taken_seconds']): ?>
                        <p class="mb-0">
                            <i class="bi bi-clock"></i> Χρόνος: <?= formatDuration($attempt['time_taken_seconds']) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <a href="training-quizzes.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Επιστροφή στα Κουίζ
                    </a>
                    <a href="quiz-take.php?id=<?= $attempt['quiz_id'] ?>" class="btn btn-outline-info">
                        <i class="bi bi-arrow-repeat"></i> Νέα Προσπάθεια
                    </a>
                </div>
            </div>
            
            <!-- Answers Review -->
            <h4 class="mb-3">Ανασκόπηση Απαντήσεων</h4>
            
            <?php foreach ($answers as $index => $answer): ?>
                <div class="card mb-4 border-<?= $answer['is_correct'] ? 'success' : 'danger' ?>">
                    <div class="card-header bg-light">
                        <div class="d-flex align-items-center">
                            <span class="badge bg-<?= $answer['is_correct'] ? 'success' : 'danger' ?> me-2">
                                <?= $answer['is_correct'] ? '✓ Σωστό' : '✗ Λάθος' ?>
                            </span>
                            <?= questionTypeBadge($answer['question_type']) ?>
                            <span class="ms-auto text-muted">Ερώτηση <?= $index + 1 ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <h5 class="mb-3"><?= nl2br(h($answer['question_text'])) ?></h5>
                        
                        <?php if ($answer['question_type'] === QUESTION_TYPE_MC): ?>
                            <!-- Multiple Choice Review -->
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'A' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'A' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>A.</strong> <?= h($answer['option_a']) ?>
                                <?php if ($answer['user_answer'] === 'A'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'A'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'B' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'B' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>B.</strong> <?= h($answer['option_b']) ?>
                                <?php if ($answer['user_answer'] === 'B'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'B'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'C' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'C' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>C.</strong> <?= h($answer['option_c']) ?>
                                <?php if ($answer['user_answer'] === 'C'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'C'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'D' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'D' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>D.</strong> <?= h($answer['option_d']) ?>
                                <?php if ($answer['user_answer'] === 'D'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'D'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                        
                        <?php elseif ($answer['question_type'] === QUESTION_TYPE_TF): ?>
                            <!-- True/False Review -->
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'T' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'T' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>Σωστό</strong>
                                <?php if ($answer['user_answer'] === 'T'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'T'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                            <div class="mb-2 p-2 rounded <?= $answer['user_answer'] === 'F' && !$answer['is_correct'] ? 'bg-danger bg-opacity-10' : '' ?> <?= $answer['correct_option'] === 'F' ? 'bg-success bg-opacity-10' : '' ?>">
                                <strong>Λάθος</strong>
                                <?php if ($answer['user_answer'] === 'F'): ?>
                                    <span class="badge bg-secondary ms-2">Η απάντησή σας</span>
                                <?php endif; ?>
                                <?php if ($answer['correct_option'] === 'F'): ?>
                                    <span class="badge bg-success ms-2">✓ Σωστή Απάντηση</span>
                                <?php endif; ?>
                            </div>
                        
                        <?php elseif ($answer['question_type'] === QUESTION_TYPE_OPEN): ?>
                            <!-- Open Ended Review -->
                            <div class="alert alert-info">
                                <strong>Η απάντησή σας:</strong>
                                <p class="mb-0 mt-2"><?= nl2br(h($answer['user_answer_text'])) ?></p>
                            </div>
                            <div class="alert alert-secondary">
                                <i class="bi bi-info-circle"></i> Οι ανοιχτές ερωτήσεις απαιτούν χειροκίνητη αξιολόγηση
                            </div>
                        <?php endif; ?>
                        
                        <!-- Explanation -->
                        <?php if (!empty($answer['explanation'])): ?>
                            <div class="alert alert-light border mt-3">
                                <strong><i class="bi bi-lightbulb"></i> Επεξήγηση:</strong>
                                <p class="mb-0 mt-2"><?= nl2br(h($answer['explanation'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <!-- Bottom Action Buttons -->
            <div class="card mb-4">
                <div class="card-body text-center">
                    <a href="training-quizzes.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Επιστροφή στα Κουίζ
                    </a>
                    <a href="quiz-take.php?id=<?= $attempt['quiz_id'] ?>" class="btn btn-outline-info">
                        <i class="bi bi-arrow-repeat"></i> Νέα Προσπάθεια
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
