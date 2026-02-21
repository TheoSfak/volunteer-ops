<?php
/**
 * VolunteerOps - Reports
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Αναφορές';
$user = getCurrentUser();

// Date range filter
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-d'));
$departmentId = get('department_id', '');
$reportType = get('report', 'summary');

// Dept admin sees only their department
if ($user['role'] === ROLE_DEPARTMENT_ADMIN) {
    $departmentId = $user['department_id'];
}

// Build where clauses
$missionWhere = "m.deleted_at IS NULL AND DATE(m.start_datetime) BETWEEN ? AND ?";
$missionParams = [$startDate, $endDate];

$shiftWhere = "DATE(s.start_time) BETWEEN ? AND ?";
$shiftParams = [$startDate, $endDate];

if ($departmentId) {
    $missionWhere .= " AND m.department_id = ?";
    $missionParams[] = $departmentId;
    $shiftWhere .= " AND m.department_id = ?";
    $shiftParams[] = $departmentId;
}

// Summary Stats
$stats = [];

// Missions
$stats['missions_total'] = dbFetchValue(
    "SELECT COUNT(*) FROM missions m WHERE $missionWhere",
    $missionParams
);

$stats['missions_completed'] = dbFetchValue(
    "SELECT COUNT(*) FROM missions m WHERE $missionWhere AND m.status = '" . STATUS_COMPLETED . "'",
    $missionParams
);

// Shifts
$stats['shifts_total'] = dbFetchValue(
    "SELECT COUNT(*) FROM shifts s JOIN missions m ON s.mission_id = m.id WHERE $shiftWhere",
    $shiftParams
);

// Participations
$stats['participations_approved'] = dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr 
     JOIN shifts s ON pr.shift_id = s.id 
     JOIN missions m ON s.mission_id = m.id 
     WHERE $shiftWhere AND pr.status = '" . PARTICIPATION_APPROVED . "'",
    $shiftParams
);

$stats['participations_attended'] = dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr 
     JOIN shifts s ON pr.shift_id = s.id 
     JOIN missions m ON s.mission_id = m.id 
     WHERE $shiftWhere AND pr.attended = 1",
    $shiftParams
);

// Hours
$stats['total_hours'] = dbFetchValue(
    "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr 
     JOIN shifts s ON pr.shift_id = s.id 
     JOIN missions m ON s.mission_id = m.id 
     WHERE $shiftWhere AND pr.attended = 1",
    $shiftParams
);

// Points awarded (uses polymorphic relation - pointable_type/pointable_id)
$stats['total_points'] = dbFetchValue(
    "SELECT COALESCE(SUM(vp.points), 0) FROM volunteer_points vp 
     JOIN shifts s ON vp.pointable_type = 'App\\\\Models\\\\Shift' AND vp.pointable_id = s.id
     JOIN missions m ON s.mission_id = m.id 
     WHERE $shiftWhere",
    $shiftParams
);

// Unique volunteers
$stats['unique_volunteers'] = dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr 
     JOIN shifts s ON pr.shift_id = s.id 
     JOIN missions m ON s.mission_id = m.id 
     WHERE $shiftWhere AND pr.status = '" . PARTICIPATION_APPROVED . "'",
    $shiftParams
);

// Report data
$reportData = [];

switch ($reportType) {
    case 'missions':
        $reportData = dbFetchAll(
            "SELECT m.*, d.name as department_name,
                    (SELECT COUNT(*) FROM shifts s WHERE s.mission_id = m.id) as shifts_count,
                    (SELECT COUNT(*) FROM participation_requests pr 
                     JOIN shifts s ON pr.shift_id = s.id 
                     WHERE s.mission_id = m.id AND pr.attended = 1) as attended_count
             FROM missions m
             LEFT JOIN departments d ON m.department_id = d.id
             WHERE $missionWhere
             ORDER BY m.start_datetime DESC",
            $missionParams
        );
        break;
        
    case 'volunteers':
        $volWhere = "pr.status = '" . PARTICIPATION_APPROVED . "' AND DATE(s.start_time) BETWEEN ? AND ?";
        $volParams = [$startDate, $endDate];
        if ($departmentId) {
            $volWhere .= " AND m.department_id = ?";
            $volParams[] = $departmentId;
        }
        
        $reportData = dbFetchAll(
            "SELECT u.id, u.name, u.email, d.name as department_name,
                    COUNT(DISTINCT pr.id) as shifts_count,
                    COALESCE(SUM(CASE WHEN pr.attended = 1 THEN pr.actual_hours ELSE 0 END), 0) as total_hours,
                    COALESCE(SUM(CASE WHEN pr.attended = 1 THEN 1 ELSE 0 END), 0) as attended_count
             FROM users u
             JOIN participation_requests pr ON u.id = pr.volunteer_id
             JOIN shifts s ON pr.shift_id = s.id
             JOIN missions m ON s.mission_id = m.id
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE $volWhere
             GROUP BY u.id
             ORDER BY total_hours DESC",
            $volParams
        );
        break;
        
    case 'departments':
        $reportData = dbFetchAll(
            "SELECT d.id, d.name,
                    (SELECT COUNT(*) FROM missions m2 WHERE m2.department_id = d.id AND m2.deleted_at IS NULL 
                     AND DATE(m2.start_datetime) BETWEEN ? AND ?) as missions_count,
                    (SELECT COUNT(*) FROM shifts s2 JOIN missions m3 ON s2.mission_id = m3.id 
                     WHERE m3.department_id = d.id AND DATE(s2.start_time) BETWEEN ? AND ?) as shifts_count,
                    (SELECT COALESCE(SUM(pr2.actual_hours), 0) FROM participation_requests pr2 
                     JOIN shifts s3 ON pr2.shift_id = s3.id 
                     JOIN missions m4 ON s3.mission_id = m4.id 
                     WHERE m4.department_id = d.id AND pr2.attended = 1 
                     AND DATE(s3.start_time) BETWEEN ? AND ?) as total_hours
             FROM departments d
             WHERE d.is_active = 1
             ORDER BY total_hours DESC",
            [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]
        );
        break;
}

// Get departments for filter
$departments = dbFetchAll("SELECT id, name FROM departments ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-graph-up me-2"></i>Αναφορές
    </h1>
    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Εκτύπωση
    </button>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Από</label>
                <input type="date" class="form-control" name="start_date" value="<?= h($startDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Έως</label>
                <input type="date" class="form-control" name="end_date" value="<?= h($endDate) ?>">
            </div>
            <?php if ($user['role'] === ROLE_SYSTEM_ADMIN): ?>
            <div class="col-md-3">
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
            <?php endif; ?>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i>Ανανέωση
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['missions_total'] ?></h3>
                <small class="text-muted">Αποστολές</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['shifts_total'] ?></h3>
                <small class="text-muted">Βάρδιες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stats-card info">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($stats['total_hours'], 1) ?></h3>
                <small class="text-muted">Ώρες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['unique_volunteers'] ?></h3>
                <small class="text-muted">Εθελοντές</small>
            </div>
        </div>
    </div>
</div>

<!-- Report Type Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'summary' ? 'active' : '' ?>" 
           href="?<?= http_build_query(array_merge($_GET, ['report' => 'summary'])) ?>">
            Σύνοψη
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'missions' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['report' => 'missions'])) ?>">
            Αποστολές
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'volunteers' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['report' => 'volunteers'])) ?>">
            Εθελοντές
        </a>
    </li>
    <?php if ($user['role'] === ROLE_SYSTEM_ADMIN): ?>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'departments' ? 'active' : '' ?>"
           href="?<?= http_build_query(array_merge($_GET, ['report' => 'departments'])) ?>">
            Τμήματα
        </a>
    </li>
    <?php endif; ?>
</ul>

<!-- Report Content -->
<div class="card">
    <div class="card-body">
        <?php if ($reportType === 'summary'): ?>
            <h5>Περίληψη Περιόδου</h5>
            <p class="text-muted"><?= formatDate($startDate) ?> - <?= formatDate($endDate) ?></p>
            
            <div class="table-responsive">
                <table class="table">
                    <tr><th>Συνολικές Αποστολές</th><td><?= $stats['missions_total'] ?></td></tr>
                    <tr><th>Ολοκληρωμένες Αποστολές</th><td><?= $stats['missions_completed'] ?></td></tr>
                    <tr><th>Συνολικές Βάρδιες</th><td><?= $stats['shifts_total'] ?></td></tr>
                    <tr><th>Εγκεκριμένες Συμμετοχές</th><td><?= $stats['participations_approved'] ?></td></tr>
                    <tr><th>Παρουσίες</th><td><?= $stats['participations_attended'] ?></td></tr>
                    <tr><th>Συνολικές Ώρες</th><td><?= number_format($stats['total_hours'], 1) ?></td></tr>
                    <tr><th>Πόντοι που Απονεμήθηκαν</th><td><?= number_format($stats['total_points']) ?></td></tr>
                    <tr><th>Μοναδικοί Εθελοντές</th><td><?= $stats['unique_volunteers'] ?></td></tr>
                </table>
            </div>
            
        <?php elseif ($reportType === 'missions'): ?>
            <?php if (empty($reportData)): ?>
                <p class="text-muted">Δεν βρέθηκαν αποστολές.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Αποστολή</th>
                                <th>Τμήμα</th>
                                <th>Ημ/νία</th>
                                <th>Κατάσταση</th>
                                <th class="text-center">Βάρδιες</th>
                                <th class="text-center">Παρουσίες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $m): ?>
                                <tr>
                                    <td><?= h($m['title']) ?></td>
                                    <td><?= h($m['department_name'] ?? '-') ?></td>
                                    <td><?= formatDate($m['start_datetime']) ?></td>
                                    <td><?= statusBadge($m['status']) ?></td>
                                    <td class="text-center"><?= $m['shifts_count'] ?></td>
                                    <td class="text-center"><?= $m['attended_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
        <?php elseif ($reportType === 'volunteers'): ?>
            <?php if (empty($reportData)): ?>
                <p class="text-muted">Δεν βρέθηκαν εθελοντές.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Εθελοντής</th>
                                <th>Τμήμα</th>
                                <th class="text-center">Βάρδιες</th>
                                <th class="text-center">Παρουσίες</th>
                                <th class="text-center">Ώρες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $v): ?>
                                <tr>
                                    <td>
                                        <?= h($v['name']) ?>
                                        <br><small class="text-muted"><?= h($v['email']) ?></small>
                                    </td>
                                    <td><?= h($v['department_name'] ?? '-') ?></td>
                                    <td class="text-center"><?= $v['shifts_count'] ?></td>
                                    <td class="text-center"><?= $v['attended_count'] ?></td>
                                    <td class="text-center"><?= number_format($v['total_hours'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
        <?php elseif ($reportType === 'departments'): ?>
            <?php if (empty($reportData)): ?>
                <p class="text-muted">Δεν βρέθηκαν τμήματα.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Τμήμα</th>
                                <th class="text-center">Αποστολές</th>
                                <th class="text-center">Βάρδιες</th>
                                <th class="text-center">Ώρες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $d): ?>
                                <tr>
                                    <td><?= h($d['name']) ?></td>
                                    <td class="text-center"><?= $d['missions_count'] ?></td>
                                    <td class="text-center"><?= $d['shifts_count'] ?></td>
                                    <td class="text-center"><?= number_format($d['total_hours'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar, .btn, form, .nav-tabs { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
