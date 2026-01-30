<?php
/**
 * VolunteerOps - Dashboard
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Πίνακας Ελέγχου';
$user = getCurrentUser();
$year = get('year', date('Y'));

// Get statistics based on role
if (isAdmin()) {
    // Admin statistics
    $departmentFilter = '';
    $params = [];
    
    if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $user['department_id']) {
        $departmentFilter = 'AND m.department_id = ?';
        $params = [$user['department_id']];
    }
    
    $stats = [
        'missions_total' => dbFetchValue(
            "SELECT COUNT(*) FROM missions m WHERE YEAR(start_datetime) = ? $departmentFilter",
            array_merge([$year], $params)
        ),
        'missions_open' => dbFetchValue(
            "SELECT COUNT(*) FROM missions m WHERE status = 'OPEN' AND YEAR(start_datetime) = ? $departmentFilter",
            array_merge([$year], $params)
        ),
        'missions_completed' => dbFetchValue(
            "SELECT COUNT(*) FROM missions m WHERE status = 'COMPLETED' AND YEAR(start_datetime) = ? $departmentFilter",
            array_merge([$year], $params)
        ),
        'volunteers_total' => dbFetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'VOLUNTEER'"
        ),
        'volunteers_active' => dbFetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'VOLUNTEER' AND is_active = 1"
        ),
        'pending_requests' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests pr 
             JOIN shifts s ON pr.shift_id = s.id 
             JOIN missions m ON s.mission_id = m.id 
             WHERE pr.status = 'PENDING' $departmentFilter",
            $params
        ),
    ];
    
    // Recent missions
    $recentMissions = dbFetchAll(
        "SELECT m.*, d.name as department_name,
                (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count
         FROM missions m
         LEFT JOIN departments d ON m.department_id = d.id
         WHERE 1=1 $departmentFilter
         ORDER BY m.created_at DESC
         LIMIT 5",
        $params
    );
    
    // Pending participation requests
    $pendingRequests = dbFetchAll(
        "SELECT pr.*, u.name as volunteer_name, s.start_time, s.end_time, m.title as mission_title
         FROM participation_requests pr
         JOIN users u ON pr.volunteer_id = u.id
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.status = 'PENDING' $departmentFilter
         ORDER BY pr.created_at DESC
         LIMIT 10",
        $params
    );
} else {
    // Volunteer statistics
    $stats = [
        'my_shifts' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = 'APPROVED'",
            [$user['id']]
        ),
        'pending_requests' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = 'PENDING'",
            [$user['id']]
        ),
        'total_hours' => dbFetchValue(
            "SELECT COALESCE(SUM(
                CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END
            ), 0)
            FROM participation_requests pr
            JOIN shifts s ON pr.shift_id = s.id
            WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND pr.attended = 1",
            [$user['id']]
        ),
        'total_points' => $user['total_points'] ?? 0,
    ];
    
    // My upcoming shifts
    $myShifts = dbFetchAll(
        "SELECT s.*, m.title as mission_title, m.location, pr.status as participation_status
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND s.start_time >= NOW()
         ORDER BY s.start_time ASC
         LIMIT 5",
        [$user['id']]
    );
    
    // Available missions to join
    $availableMissions = dbFetchAll(
        "SELECT m.*, d.name as department_name,
                (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count
         FROM missions m
         LEFT JOIN departments d ON m.department_id = d.id
         WHERE m.status = 'OPEN' AND m.start_datetime >= NOW()
         ORDER BY m.start_datetime ASC
         LIMIT 5"
    );
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-speedometer2 me-2"></i>Πίνακας Ελέγχου
    </h1>
    <div>
        <select class="form-select form-select-sm" onchange="location.href='?year='+this.value" style="width: auto;">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <?php if (isAdmin()): ?>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Αποστολές</h6>
                            <h3 class="mb-0"><?= $stats['missions_total'] ?></h3>
                        </div>
                        <div class="text-primary opacity-50">
                            <i class="bi bi-flag fs-1"></i>
                        </div>
                    </div>
                    <small class="text-success"><?= $stats['missions_open'] ?> ανοιχτές</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Ολοκληρωμένες</h6>
                            <h3 class="mb-0"><?= $stats['missions_completed'] ?></h3>
                        </div>
                        <div class="text-success opacity-50">
                            <i class="bi bi-check-circle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Εθελοντές</h6>
                            <h3 class="mb-0"><?= $stats['volunteers_active'] ?></h3>
                        </div>
                        <div class="text-warning opacity-50">
                            <i class="bi bi-people fs-1"></i>
                        </div>
                    </div>
                    <small class="text-muted"><?= $stats['volunteers_total'] ?> συνολικά</small>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Εκκρεμείς Αιτήσεις</h6>
                            <h3 class="mb-0"><?= $stats['pending_requests'] ?></h3>
                        </div>
                        <div class="text-danger opacity-50">
                            <i class="bi bi-hourglass-split fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Οι Βάρδιες μου</h6>
                            <h3 class="mb-0"><?= $stats['my_shifts'] ?></h3>
                        </div>
                        <div class="text-primary opacity-50">
                            <i class="bi bi-calendar-check fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Εκκρεμείς</h6>
                            <h3 class="mb-0"><?= $stats['pending_requests'] ?></h3>
                        </div>
                        <div class="text-warning opacity-50">
                            <i class="bi bi-hourglass-split fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card success">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Ώρες Εθελοντισμού</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_hours'], 1) ?></h3>
                        </div>
                        <div class="text-success opacity-50">
                            <i class="bi bi-clock-history fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-3">
            <div class="card stats-card danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Πόντοι</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_points']) ?></h3>
                        </div>
                        <div class="text-danger opacity-50">
                            <i class="bi bi-star fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <?php if (isAdmin()): ?>
        <!-- Recent Missions -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-flag me-2"></i>Πρόσφατες Αποστολές</h5>
                    <a href="missions.php" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentMissions)): ?>
                        <p class="text-muted text-center py-4">Δεν υπάρχουν αποστολές.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <tbody>
                                    <?php foreach ($recentMissions as $mission): ?>
                                        <tr>
                                            <td>
                                                <a href="mission-view.php?id=<?= $mission['id'] ?>" class="text-decoration-none">
                                                    <strong><?= h($mission['title']) ?></strong>
                                                </a>
                                                <br>
                                                <small class="text-muted">
                                                    <?= h($mission['department_name'] ?? '-') ?> · 
                                                    <?= formatDate($mission['start_datetime']) ?>
                                                </small>
                                            </td>
                                            <td class="text-end">
                                                <?= statusBadge($mission['status']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Pending Requests -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>Εκκρεμείς Αιτήσεις</h5>
                    <a href="participations.php" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($pendingRequests)): ?>
                        <p class="text-muted text-center py-4">Δεν υπάρχουν εκκρεμείς αιτήσεις.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <tbody>
                                    <?php foreach ($pendingRequests as $request): ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($request['volunteer_name']) ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?= h($request['mission_title']) ?> · 
                                                    <?= formatDateTime($request['start_time']) ?>
                                                </small>
                                            </td>
                                            <td class="text-end">
                                                <a href="participation-action.php?id=<?= $request['id'] ?>&action=approve" 
                                                   class="btn btn-sm btn-success" title="Έγκριση">
                                                    <i class="bi bi-check"></i>
                                                </a>
                                                <a href="participation-action.php?id=<?= $request['id'] ?>&action=reject" 
                                                   class="btn btn-sm btn-danger" title="Απόρριψη">
                                                    <i class="bi bi-x"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- My Upcoming Shifts -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Οι Επόμενες Βάρδιες μου</h5>
                    <a href="my-shifts.php" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($myShifts)): ?>
                        <p class="text-muted text-center py-4">Δεν έχετε προγραμματισμένες βάρδιες.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($myShifts as $shift): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= h($shift['mission_title']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <i class="bi bi-geo-alt"></i> <?= h($shift['location']) ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-primary">
                                                <?= formatDateTime($shift['start_time'], 'd/m H:i') ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Available Missions -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-flag me-2"></i>Διαθέσιμες Αποστολές</h5>
                    <a href="missions.php" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($availableMissions)): ?>
                        <p class="text-muted text-center py-4">Δεν υπάρχουν διαθέσιμες αποστολές.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($availableMissions as $mission): ?>
                                <a href="mission-view.php?id=<?= $mission['id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= h($mission['title']) ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= h($mission['department_name'] ?? '-') ?> · 
                                                <?= $mission['shift_count'] ?> βάρδιες
                                            </small>
                                        </div>
                                        <span class="badge bg-success">
                                            <?= formatDate($mission['start_datetime']) ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
