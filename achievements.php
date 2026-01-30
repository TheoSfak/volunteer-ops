<?php
/**
 * VolunteerOps - Achievements
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Επιτεύγματα';
$user = getCurrentUser();

// Get all achievements
$achievements = dbFetchAll("SELECT * FROM achievements ORDER BY required_points ASC, name ASC");

// Get user's earned achievements
$earnedIds = array_column(
    dbFetchAll("SELECT achievement_id, earned_at FROM user_achievements WHERE user_id = ?", [$user['id']]),
    'earned_at',
    'achievement_id'
);

// Group by category
$categories = [];
foreach ($achievements as $a) {
    $cat = $a['category'] ?: 'Γενικά';
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $a['earned_at'] = $earnedIds[$a['id']] ?? null;
    $a['earned'] = isset($earnedIds[$a['id']]);
    $categories[$cat][] = $a;
}

// Stats
$earnedCount = count($earnedIds);
$totalCount = count($achievements);
$percentage = $totalCount > 0 ? round(($earnedCount / $totalCount) * 100) : 0;

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-trophy me-2"></i>Επιτεύγματα
    </h1>
</div>

<!-- Progress Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-3 text-center">
                <h2 class="text-warning mb-0"><?= $earnedCount ?> / <?= $totalCount ?></h2>
                <small class="text-muted">Επιτεύγματα</small>
            </div>
            <div class="col-md-9">
                <div class="d-flex justify-content-between mb-1">
                    <span>Πρόοδος</span>
                    <strong><?= $percentage ?>%</strong>
                </div>
                <div class="progress" style="height: 20px;">
                    <div class="progress-bar bg-warning" style="width: <?= $percentage ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($categories as $catName => $catAchievements): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><?= h($catName) ?></h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($catAchievements as $ach): ?>
                <div class="col-md-4 col-lg-3">
                    <div class="card h-100 <?= $ach['earned'] ? 'border-warning' : 'border-secondary' ?> <?= !$ach['earned'] ? 'opacity-50' : '' ?>">
                        <div class="card-body text-center">
                            <div class="fs-1 mb-2">
                                <?= $ach['icon'] ?: '🏆' ?>
                            </div>
                            <h6 class="card-title mb-1"><?= h($ach['name']) ?></h6>
                            <p class="card-text small text-muted mb-2">
                                <?= h($ach['description']) ?>
                            </p>
                            <?php if ($ach['earned']): ?>
                                <span class="badge bg-success">
                                    <i class="bi bi-check me-1"></i>Κατακτήθηκε
                                </span>
                                <br><small class="text-muted"><?= formatDate($ach['earned_at']) ?></small>
                            <?php else: ?>
                                <span class="badge bg-secondary">
                                    <i class="bi bi-lock me-1"></i>Κλειδωμένο
                                </span>
                                <?php if ($ach['required_points'] > 0): ?>
                                    <br><small class="text-muted">Απαιτούνται <?= number_format($ach['required_points']) ?> πόντοι</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
