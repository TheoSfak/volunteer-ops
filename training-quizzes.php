<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Κουίζ Εκπ

';
$user = getCurrentUser();

// Get filter
$categoryFilter = get('category', '');

// Build query
$where = ['tq.is_active = 1'];
$params = [];

if (!empty($categoryFilter)) {
    $where[] = 'tq.category_id = ?';
    $params[] = $categoryFilter;
}

// Fetch quizzes with question count
$quizzes = dbFetchAll("
    SELECT tq.*, tc.name as category_name, tc.icon as category_icon,
           (SELECT COUNT(*) FROM training_quiz_questions WHERE quiz_id = tq.id) as question_count,
           (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = tq.id AND user_id = ?) as attempt_count
    FROM training_quizzes tq
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY tq.created_at DESC
", array_merge([$user['id']], $params));

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
            <i class="bi bi-puzzle me-2"></i>Κουίζ Εκπαίδευσης
        </h1>
        <a href="training.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Επιστροφή
        </a>
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
                        <a href="training-quizzes.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Καθαρισμός
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Quizzes List -->
    <?php if (empty($quizzes)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Δεν βρέθηκαν διαθέσιμα κουίζ <?= $categoryFilter ? 'για την επιλεγμένη κατηγορία' : '' ?>.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($quizzes as $quiz): ?>
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <!-- Header -->
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <span class="badge bg-primary">
                                    <?= h($quiz['category_icon']) ?> <?= h($quiz['category_name']) ?>
                                </span>
                                <?php if ($quiz['attempt_count'] > 0): ?>
                                    <span class="badge bg-info">
                                        <?= $quiz['attempt_count'] ?> Προσπάθειες
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Title -->
                            <h5 class="card-title mb-2"><?= h($quiz['title']) ?></h5>
                            
                            <!-- Description -->
                            <?php if (!empty($quiz['description'])): ?>
                                <p class="text-muted mb-3"><?= nl2br(h($quiz['description'])) ?></p>
                            <?php endif; ?>
                            
                            <!-- Info -->
                            <div class="d-flex justify-content-between text-sm mb-3">
                                <span><i class="bi bi-question-circle"></i> <?= $quiz['question_count'] ?> Ερωτήσεις</span>
                                <?php if ($quiz['time_limit_minutes']): ?>
                                    <span><i class="bi bi-clock"></i> <?= $quiz['time_limit_minutes'] ?> Λεπτά</span>
                                <?php else: ?>
                                    <span><i class="bi bi-clock"></i> Χωρίς Όριο</span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Start Button -->
                            <a href="quiz-take.php?id=<?= $quiz['id'] ?>" class="btn btn-primary w-100">
                                <i class="bi bi-play-fill"></i> Έναρξη Κουίζ
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
