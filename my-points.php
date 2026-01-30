<?php
/**
 * VolunteerOps - My Points & History
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Οι Πόντοι μου';
$user = getCurrentUser();

$page = max(1, (int) get('page', 1));
$perPage = 20;

// Get points history
$total = dbFetchValue(
    "SELECT COUNT(*) FROM volunteer_points WHERE user_id = ?",
    [$user['id']]
);
$pagination = paginate($total, $page, $perPage);

$pointsHistory = dbFetchAll(
    "SELECT vp.*, 
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN m.title ELSE NULL END as shift_title,
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN s.start_time ELSE NULL END as start_time,
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN m.title ELSE NULL END as mission_title
     FROM volunteer_points vp
     LEFT JOIN shifts s ON vp.pointable_type = 'App\\\\Models\\\\Shift' AND vp.pointable_id = s.id
     LEFT JOIN missions m ON s.mission_id = m.id
     WHERE vp.user_id = ?
     ORDER BY vp.created_at DESC
     LIMIT {$pagination['offset']}, {$pagination['per_page']}",
    [$user['id']]
);

// Get monthly breakdown
$monthlyPoints = dbFetchAll(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, SUM(points) as total
     FROM volunteer_points
     WHERE user_id = ?
     GROUP BY month
     ORDER BY month DESC
     LIMIT 12",
    [$user['id']]
);

// Get rank
$rank = dbFetchValue(
    "SELECT COUNT(*) + 1 FROM users WHERE total_points > ? AND is_active = 1",
    [$user['total_points']]
);

$totalVolunteers = dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1");

// Stats
$thisMonth = dbFetchValue(
    "SELECT COALESCE(SUM(points), 0) FROM volunteer_points 
     WHERE user_id = ? AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')",
    [$user['id']]
);

$thisYear = dbFetchValue(
    "SELECT COALESCE(SUM(points), 0) FROM volunteer_points 
     WHERE user_id = ? AND YEAR(created_at) = YEAR(CURDATE())",
    [$user['id']]
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-star me-2"></i>Οι Πόντοι μου
    </h1>
    <a href="leaderboard.php" class="btn btn-outline-warning">
        <i class="bi bi-trophy me-1"></i>Κατάταξη
    </a>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h2 class="mb-0"><?= number_format($user['total_points']) ?></h2>
                <small class="text-muted">Συνολικοί Πόντοι</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h2 class="mb-0">#<?= $rank ?></h2>
                <small class="text-muted">Κατάταξη (από <?= $totalVolunteers ?>)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h2 class="mb-0"><?= number_format($thisMonth) ?></h2>
                <small class="text-muted">Αυτόν τον μήνα</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card info">
            <div class="card-body text-center">
                <h2 class="mb-0"><?= number_format($thisYear) ?></h2>
                <small class="text-muted">Φέτος</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Points History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history me-1"></i>Ιστορικό Πόντων</h5>
            </div>
            <div class="card-body">
                <?php if (empty($pointsHistory)): ?>
                    <p class="text-muted">Δεν έχετε κερδίσει πόντους ακόμα.</p>
                    <p>Συμμετέχετε σε βάρδιες για να κερδίσετε πόντους!</p>
                    <a href="missions.php" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Βρείτε Αποστολές
                    </a>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Ημ/νία</th>
                                    <th>Περιγραφή</th>
                                    <th>Λόγος</th>
                                    <th class="text-end">Πόντοι</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pointsHistory as $ph): ?>
                                    <tr>
                                        <td><?= formatDate($ph['created_at']) ?></td>
                                        <td>
                                            <?php if ($ph['pointable_type'] === 'App\\Models\\Shift' && $ph['shift_title']): ?>
                                                <a href="shift-view.php?id=<?= $ph['pointable_id'] ?>">
                                                    <?= h($ph['shift_title']) ?>
                                                </a>
                                                <br><small class="text-muted"><?= h($ph['mission_title']) ?></small>
                                            <?php else: ?>
                                                <?= h($ph['description'] ?: $ph['reason']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($ph['reason'] ?: '-') ?></td>
                                        <td class="text-end">
                                            <strong class="text-success">+<?= $ph['points'] ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?= paginationLinks($pagination) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Points Info -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-1"></i>Πώς κερδίζω πόντους;</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li class="mb-2">
                        <strong><?= POINTS_PER_HOUR ?> πόντοι</strong> ανά ώρα εθελοντισμού
                    </li>
                    <li class="mb-2">
                        <strong>×<?= WEEKEND_MULTIPLIER ?></strong> για Σαββατοκύριακα
                    </li>
                    <li class="mb-2">
                        <strong>×<?= NIGHT_MULTIPLIER ?></strong> για νυχτερινές βάρδιες
                    </li>
                    <li class="mb-2">
                        <strong>×<?= MEDICAL_MULTIPLIER ?></strong> για ιατρικές αποστολές
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Monthly Breakdown -->
        <?php if (!empty($monthlyPoints)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-graph-up me-1"></i>Μηνιαία Ανάλυση</h5>
            </div>
            <div class="card-body">
                <?php 
                $months = [
                    '01' => 'Ιαν', '02' => 'Φεβ', '03' => 'Μαρ', '04' => 'Απρ',
                    '05' => 'Μαϊ', '06' => 'Ιουν', '07' => 'Ιουλ', '08' => 'Αυγ',
                    '09' => 'Σεπ', '10' => 'Οκτ', '11' => 'Νοε', '12' => 'Δεκ'
                ];
                $maxPoints = max(array_column($monthlyPoints, 'total')) ?: 1;
                ?>
                <?php foreach ($monthlyPoints as $mp): ?>
                    <?php 
                    $parts = explode('-', $mp['month']);
                    $monthName = $months[$parts[1]] . ' ' . $parts[0];
                    $percentage = ($mp['total'] / $maxPoints) * 100;
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= $monthName ?></span>
                            <strong><?= number_format($mp['total']) ?></strong>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-warning" style="width: <?= $percentage ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
