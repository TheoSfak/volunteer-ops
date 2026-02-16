<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Εκπαίδευση';
$user = getCurrentUser();
$userId = $user['id'];

// Fetch all active categories with counts
$categories = dbFetchAll("
    SELECT 
        tc.*,
        (SELECT COUNT(*) FROM training_materials WHERE category_id = tc.id) as materials_count,
        (SELECT COUNT(*) FROM training_quizzes WHERE category_id = tc.id AND is_active = 1) as quizzes_count,
        (SELECT COUNT(*) FROM training_exams WHERE category_id = tc.id AND is_active = 1) as exams_count
    FROM training_categories tc
    WHERE tc.is_active = 1
    ORDER BY tc.display_order, tc.name
");

// Get user's overall progress
$overallStats = [
    'materials_viewed' => 0,
    'quizzes_completed' => 0,
    'exams_passed' => 0,
    'exams_total' => 0
];

$progressData = dbFetchAll("SELECT * FROM training_user_progress WHERE user_id = ?", [$userId]);
foreach ($progressData as $p) {
    $viewed = json_decode($p['materials_viewed'] ?? '[]', true);
    $overallStats['materials_viewed'] += count($viewed);
    $overallStats['quizzes_completed'] += (int)$p['quizzes_completed'];
    $overallStats['exams_passed'] += (int)$p['exams_passed'];
}

// Count total exams available
$overallStats['exams_total'] = dbFetchValue("SELECT COUNT(*) FROM training_exams WHERE is_active = 1") ?? 0;

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-book me-2"></i>Εκπαίδευση
        </h1>
    </div>
    
    <!-- Overall Stats -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="bi bi-file-earmark-pdf text-primary" style="font-size: 2rem;"></i>
                    <h3 class="mt-2"><?= $overallStats['materials_viewed'] ?></h3>
                    <p class="text-muted mb-0">Υλικά που Διαβάσατε</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="bi bi-puzzle text-info" style="font-size: 2rem;"></i>
                    <h3 class="mt-2"><?= $overallStats['quizzes_completed'] ?></h3>
                    <p class="text-muted mb-0">Κουίζ που Ολοκληρώσατε</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="bi bi-award text-success" style="font-size: 2rem;"></i>
                    <h3 class="mt-2"><?= $overallStats['exams_passed'] ?> / <?= $overallStats['exams_total'] ?></h3>
                    <p class="text-muted mb-0">Διαγωνίσματα που Περάσατε</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="bi bi-graph-up text-warning" style="font-size: 2rem;"></i>
                    <h3 class="mt-2">
                        <?= $overallStats['exams_total'] > 0 ? round(($overallStats['exams_passed'] / $overallStats['exams_total']) * 100) : 0 ?>%
                    </h3>
                    <p class="text-muted mb-0">Ποσοστό Επιτυχίας</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Categories -->
    <h4 class="mb-3">Κατηγορίες Εκπαίδευσης</h4>
    
    <?php if (empty($categories)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Δεν υπάρχουν διαθέσιμες κατηγορίες εκπαίδευσης αυτή τη στιγμή.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($categories as $category): ?>
                <?php
                $progress = getUserCategoryProgress($userId, $category['id']);
                $completionRate = 0;
                if ($category['exams_count'] > 0) {
                    $completionRate = round(($progress['exams_passed'] / $category['exams_count']) * 100);
                }
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex align-items-start mb-3">
                                <div style="font-size: 2.5rem; margin-right: 1rem;">
                                    <?= h($category['icon']) ?>
                                </div>
                                <div class="flex-grow-1">
                                    <h5 class="card-title mb-1"><?= h($category['name']) ?></h5>
                                    <?php if (!empty($category['description'])): ?>
                                        <p class="text-muted small mb-2"><?= h($category['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Progress bar -->
                            <?php if ($category['exams_count'] > 0): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between text-sm mb-1">
                                        <span class="text-muted">Πρόοδος</span>
                                        <span class="fw-bold"><?= $completionRate ?>%</span>
                                    </div>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?= $completionRate ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Stats -->
                            <div class="d-flex justify-content-between text-sm mb-3">
                                <span><i class="bi bi-file-earmark-pdf text-primary"></i> <?= $category['materials_count'] ?> Υλικά</span>
                                <span><i class="bi bi-puzzle text-info"></i> <?= $category['quizzes_count'] ?> Κουίζ</span>
                                <span><i class="bi bi-award text-warning"></i> <?= $category['exams_count'] ?> Διαγωνίσματα</span>
                            </div>
                            
                            <!-- Action buttons -->
                            <div class="d-flex gap-2">
                                <a href="training-materials.php?category=<?= $category['id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
                                    <i class="bi bi-file-earmark-pdf"></i> Υλικά
                                </a>
                                <a href="training-quizzes.php?category=<?= $category['id'] ?>" class="btn btn-sm btn-outline-info flex-grow-1">
                                    <i class="bi bi-puzzle"></i> Κουίζ
                                </a>
                                <a href="training-exams.php?category=<?= $category['id'] ?>" class="btn btn-sm btn-outline-warning flex-grow-1">
                                    <i class="bi bi-award"></i> Διαγωνίσματα
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
