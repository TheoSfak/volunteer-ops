<?php
/**
 * VolunteerOps - Leaderboard
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Κατάταξη';

// Time period filter
$period = get('period', 'all');
$departmentId = get('department_id', '');
$page = max(1, (int) get('page', 1));
$perPage = 50;

// Build query based on period
$periodFilter = '';
$params = [];

switch ($period) {
    case 'month':
        $periodFilter = "AND vp.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        break;
    case 'year':
        $periodFilter = "AND vp.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
    case 'all':
    default:
        $periodFilter = '';
        break;
}

// For all-time, we can use total_points from users table
if ($period === 'all') {
    $whereClause = 'u.is_active = 1 AND u.deleted_at IS NULL';
    if ($departmentId) {
        $whereClause .= " AND u.department_id = ?";
        $params[] = $departmentId;
    }
    
    $total = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $whereClause", $params);
    $pagination = paginate($total, $page, $perPage);
    
    $leaderboard = dbFetchAll(
        "SELECT u.id, u.name, u.total_points, u.department_id, d.name as department_name,
                (SELECT COUNT(*) FROM user_achievements ua WHERE ua.user_id = u.id) as achievements_count,
                (SELECT COUNT(*) FROM participation_requests pr WHERE pr.volunteer_id = u.id AND pr.attended = 1) as shifts_count
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE $whereClause
         ORDER BY u.total_points DESC, u.name ASC
         LIMIT {$pagination['offset']}, {$pagination['per_page']}",
        $params
    );
} else {
    // Calculate from volunteer_points table
    $whereClause = 'u.is_active = 1 AND u.deleted_at IS NULL';
    if ($departmentId) {
        $whereClause .= " AND u.department_id = ?";
        $params[] = $departmentId;
    }
    
    $total = dbFetchValue(
        "SELECT COUNT(DISTINCT u.id) 
         FROM users u 
         JOIN volunteer_points vp ON u.id = vp.user_id 
         WHERE $whereClause $periodFilter",
        $params
    );
    $pagination = paginate($total, $page, $perPage);
    
    $leaderboard = dbFetchAll(
        "SELECT u.id, u.name, u.department_id, d.name as department_name,
                COALESCE(SUM(vp.points), 0) as total_points,
                (SELECT COUNT(*) FROM user_achievements ua WHERE ua.user_id = u.id) as achievements_count,
                COUNT(DISTINCT CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN vp.pointable_id END) as shifts_count
         FROM users u
         LEFT JOIN departments d ON u.department_id = d.id
         LEFT JOIN volunteer_points vp ON u.id = vp.user_id $periodFilter
         WHERE $whereClause
         GROUP BY u.id
         HAVING total_points > 0
         ORDER BY total_points DESC, u.name ASC
         LIMIT {$pagination['offset']}, {$pagination['per_page']}",
        $params
    );
}

// Current user rank
$myRank = null;
$currentUser = getCurrentUser();
if ($period === 'all') {
    $myRank = dbFetchValue(
        "SELECT COUNT(*) + 1 FROM users WHERE total_points > ? AND is_active = 1 AND deleted_at IS NULL",
        [$currentUser['total_points']]
    );
}

// Get departments for filter
$departments = dbFetchAll("SELECT id, name FROM departments ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-trophy me-2"></i>Κατάταξη Εθελοντών
    </h1>
</div>

<!-- My Rank Card -->
<?php if ($period === 'all' && $myRank): ?>
<div class="card mb-4 border-warning">
    <div class="card-body d-flex align-items-center">
        <div class="fs-1 me-3 text-warning">
            <?php if ($myRank == 1): ?>🥇<?php elseif ($myRank == 2): ?>🥈<?php elseif ($myRank == 3): ?>🥉<?php else: ?>#<?= $myRank ?><?php endif; ?>
        </div>
        <div>
            <h5 class="mb-0"><?= h($currentUser['name']) ?></h5>
            <p class="mb-0 text-muted">
                <strong class="text-warning"><?= number_format($currentUser['total_points']) ?></strong> πόντοι
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Περίοδος</label>
                <select name="period" class="form-select">
                    <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Όλος ο χρόνος</option>
                    <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Τελευταίος χρόνος</option>
                    <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Τελευταίος μήνας</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Τμήμα</label>
                <select name="department_id" class="form-select">
                    <option value="">Όλα</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $departmentId == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-funnel me-1"></i>Φιλτράρισμα
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($leaderboard)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν δεδομένα για αυτή την περίοδο.
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width: 60px;">#</th>
                        <th>Εθελοντής</th>
                        <th>Τμήμα</th>
                        <th class="text-center">Βάρδιες</th>
                        <th class="text-center">Επιτεύγματα</th>
                        <th class="text-end">Πόντοι</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = $pagination['offset'] + 1;
                    foreach ($leaderboard as $entry): 
                        $isMe = $entry['id'] == $currentUser['id'];
                    ?>
                        <tr class="<?= $isMe ? 'table-warning' : '' ?>">
                            <td class="text-center">
                                <?php if ($rank == 1): ?>
                                    <span class="fs-4">🥇</span>
                                <?php elseif ($rank == 2): ?>
                                    <span class="fs-4">🥈</span>
                                <?php elseif ($rank == 3): ?>
                                    <span class="fs-4">🥉</span>
                                <?php else: ?>
                                    <strong><?= $rank ?></strong>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= h($entry['name']) ?></strong>
                                <?php if ($isMe): ?>
                                    <span class="badge bg-primary">εσείς</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($entry['department_name'] ?? '-') ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= $entry['shifts_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($entry['achievements_count'] > 0): ?>
                                    <span class="badge bg-info"><?= $entry['achievements_count'] ?> 🏆</span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong class="text-warning fs-5"><?= number_format($entry['total_points']) ?></strong>
                            </td>
                        </tr>
                    <?php 
                        $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?= paginationLinks($pagination) ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
