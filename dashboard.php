<?php
/**
 * VolunteerOps - Dashboard
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Πίνακας Ελέγχου';
$user = getCurrentUser();
$year = get('year', date('Y'));
$currentMonth = date('Y-m');
$previousMonth = date('Y-m', strtotime('-1 month'));

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
            "SELECT COUNT(*) FROM missions m WHERE status = '" . STATUS_OPEN . "' AND YEAR(start_datetime) = ? $departmentFilter",
            array_merge([$year], $params)
        ),
        'missions_completed' => dbFetchValue(
            "SELECT COUNT(*) FROM missions m WHERE status = '" . STATUS_COMPLETED . "' AND YEAR(start_datetime) = ? $departmentFilter",
            array_merge([$year], $params)
        ),
        'volunteers_total' => dbFetchValue(
            "SELECT COUNT(*) FROM users WHERE role = ? AND deleted_at IS NULL",
            [ROLE_VOLUNTEER]
        ),
        'volunteers_active' => dbFetchValue(
            "SELECT COUNT(*) FROM users WHERE role = ? AND is_active = 1 AND deleted_at IS NULL",
            [ROLE_VOLUNTEER]
        ),
        'pending_requests' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests pr 
             JOIN shifts s ON pr.shift_id = s.id 
             JOIN missions m ON s.mission_id = m.id 
             WHERE pr.status = '" . PARTICIPATION_PENDING . "' $departmentFilter",
            $params
        ),
        'total_hours_this_month' => dbFetchValue(
            "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             JOIN missions m ON s.mission_id = m.id
             WHERE pr.attended = 1 AND DATE_FORMAT(s.start_time, '%Y-%m') = ? $departmentFilter",
            array_merge([$currentMonth], $params)
        ),
        'total_hours_last_month' => dbFetchValue(
            "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             JOIN missions m ON s.mission_id = m.id
             WHERE pr.attended = 1 AND DATE_FORMAT(s.start_time, '%Y-%m') = ? $departmentFilter",
            array_merge([$previousMonth], $params)
        ),
        'active_volunteers_this_month' => dbFetchValue(
            "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             JOIN missions m ON s.mission_id = m.id
             WHERE pr.attended = 1 AND DATE_FORMAT(s.start_time, '%Y-%m') = ? $departmentFilter",
            array_merge([$currentMonth], $params)
        ),
    ];
    
    // Calculate completion rate
    $completedThisMonth = dbFetchValue(
        "SELECT COUNT(*) FROM missions m 
         WHERE status = '" . STATUS_COMPLETED . "' AND DATE_FORMAT(start_datetime, '%Y-%m') = ? $departmentFilter",
        array_merge([$currentMonth], $params)
    );
    $totalThisMonth = dbFetchValue(
        "SELECT COUNT(*) FROM missions m 
         WHERE DATE_FORMAT(start_datetime, '%Y-%m') = ? AND status != '" . STATUS_DRAFT . "' $departmentFilter",
        array_merge([$currentMonth], $params)
    );
    $stats['completion_rate'] = $totalThisMonth > 0 ? round(($completedThisMonth / $totalThisMonth) * 100) : 0;
    
    // Get monthly stats for chart (last 6 months) — 2 queries instead of 18
    $sixMonthsAgo = date('Y-m-01', strtotime('-5 months'));
    $nextMonth    = date('Y-m-01', strtotime('+1 month'));

    $missionRows = dbFetchAll(
        "SELECT DATE_FORMAT(start_datetime, '%Y-%m') as month, COUNT(*) as missions
         FROM missions m
         WHERE start_datetime >= ? AND start_datetime < ? $departmentFilter
         GROUP BY DATE_FORMAT(start_datetime, '%Y-%m')",
        array_merge([$sixMonthsAgo, $nextMonth], $params)
    );
    $missionsMap = array_column($missionRows, 'missions', 'month');

    $prRows = dbFetchAll(
        "SELECT DATE_FORMAT(s.start_time, '%Y-%m') as month,
                COUNT(DISTINCT pr.volunteer_id) as volunteers,
                COALESCE(SUM(pr.actual_hours), 0) as hours
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND s.start_time >= ? AND s.start_time < ? $departmentFilter
         GROUP BY DATE_FORMAT(s.start_time, '%Y-%m')",
        array_merge([$sixMonthsAgo, $nextMonth], $params)
    );
    $volunteersMap = array_column($prRows, 'volunteers', 'month');
    $hoursMap      = array_column($prRows, 'hours', 'month');

    $monthlyStats = [];
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $monthlyStats[] = [
            'month'      => date('M Y', strtotime("-$i months")),
            'missions'   => (int)($missionsMap[$month]   ?? 0),
            'volunteers' => (int)($volunteersMap[$month] ?? 0),
            'hours'      => (float)($hoursMap[$month]    ?? 0),
        ];
    }

    // Top volunteers this month
    $topVolunteers = dbFetchAll(
        "SELECT u.id, u.name, u.total_points, 
                COUNT(DISTINCT pr.id) as shifts_count,
                COALESCE(SUM(pr.actual_hours), 0) as total_hours
         FROM users u
         LEFT JOIN participation_requests pr ON u.id = pr.volunteer_id 
             AND pr.attended = 1 
             AND DATE_FORMAT((SELECT start_time FROM shifts WHERE id = pr.shift_id), '%Y-%m') = ?
         WHERE u.role = ? AND u.is_active = 1
         GROUP BY u.id
         ORDER BY u.total_points DESC
         LIMIT 5",
        [$currentMonth, ROLE_VOLUNTEER]
    );
    
    // Recent missions
    $recentMissions = dbFetchAll(
        "SELECT m.*, d.name as department_name,
                (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count
         FROM missions m
         LEFT JOIN departments d ON m.department_id = d.id
         WHERE m.deleted_at IS NULL 
           AND LOWER(m.title) NOT LIKE '%test%'
           AND LOWER(m.title) NOT LIKE '%δοκιμ%'
           AND LOWER(m.title) NOT LIKE '%δοκή%'
           $departmentFilter
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
         WHERE pr.status = '" . PARTICIPATION_PENDING . "' $departmentFilter
         ORDER BY pr.created_at DESC
         LIMIT 10",
        $params
    );
} else {
    // Volunteer statistics
    $stats = [
        'my_shifts' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = ?",
            [$user['id'], PARTICIPATION_APPROVED]
        ),
        'pending_requests' => dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = ?",
            [$user['id'], PARTICIPATION_PENDING]
        ),
        'total_hours' => dbFetchValue(
            "SELECT COALESCE(SUM(
                CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END
            ), 0)
            FROM participation_requests pr
            JOIN shifts s ON pr.shift_id = s.id
            WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1",
            [$user['id'], PARTICIPATION_APPROVED]
        ),
        'total_points' => $user['total_points'] ?? 0,
    ];

    // My upcoming shifts
    $myShifts = dbFetchAll(
        "SELECT s.*, m.title as mission_title, m.location, pr.status as participation_status
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.volunteer_id = ? AND pr.status = ? AND s.start_time >= NOW()
         ORDER BY s.start_time ASC
         LIMIT 5",
        [$user['id'], PARTICIPATION_APPROVED]
    );

    // Available missions to join
    $availableMissions = dbFetchAll(
        "SELECT m.*, d.name as department_name,
                (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count
         FROM missions m
         LEFT JOIN departments d ON m.department_id = d.id
         WHERE m.status = ? AND m.start_datetime >= NOW()
         ORDER BY m.start_datetime ASC
         LIMIT 5",
        [STATUS_OPEN]
    );
}

include __DIR__ . '/includes/header.php';

// Check for active (LIVE) exams - shown to all users
$liveExams = dbFetchAll("
    SELECT te.*, tc.name as category_name, tc.icon as category_icon
    FROM training_exams te
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE te.is_active = 1 
      AND (te.available_from IS NULL OR te.available_from <= NOW())
      AND (te.available_until IS NULL OR te.available_until > NOW())
    ORDER BY te.available_from DESC
");
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-speedometer2 me-2"></i>Πίνακας Ελέγχου
    </h1>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" id="customizeBtn" title="Προσαρμογή Dashboard">
            <i class="bi bi-gear"></i><span class="d-none d-sm-inline"> Προσαρμογή</span>
        </button>
        <select class="form-select form-select-sm" onchange="location.href='?year='+this.value" style="width: auto;">
            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
</div>

<?php if (!empty($liveExams)): ?>
    <?php foreach ($liveExams as $liveExam): ?>
        <?php
        $remainingMin = '';
        if (!empty($liveExam['available_until'])) {
            $secsLeft = strtotime($liveExam['available_until']) - time();
            if ($secsLeft > 0) {
                $remainingMin = ceil($secsLeft / 60);
            }
        }
        // Check if current user (volunteer) has already taken it
        $alreadyTaken = false;
        if (!isAdmin()) {
            $alreadyTaken = !canUserTakeExam($liveExam['id'], $user['id']);
        }
        ?>
        <div class="alert alert-warning border-warning shadow-sm mb-4" style="border-left: 5px solid #f59e0b !important; animation: pulse 2s infinite;">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">
                        <i class="bi bi-broadcast text-danger"></i>
                        <span class="badge bg-danger me-2">LIVE</span>
                        <?= h($liveExam['category_icon']) ?> <?= h($liveExam['title']) ?>
                    </h5>
                    <p class="mb-0 text-muted">
                        Κατηγορία: <?= h($liveExam['category_name']) ?>
                        | <?= $liveExam['questions_per_attempt'] ?> ερωτήσεις
                        | Όριο: <?= $liveExam['passing_percentage'] ?>%
                        <?php if ($remainingMin): ?>
                            | <strong class="text-danger">Απομένουν <?= $remainingMin ?> λεπτά</strong>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if (isAdmin()): ?>
                        <span class="badge bg-success fs-6"><i class="bi bi-broadcast"></i> Σε Εξέλιξη</span>
                    <?php elseif ($alreadyTaken): ?>
                        <span class="badge bg-secondary fs-6">Ολοκληρώθηκε</span>
                    <?php elseif (!isTraineeRescuer()): ?>
                        <span class="badge bg-info fs-6">Μόνο για Δόκιμους</span>
                    <?php else: ?>
                        <a href="exam-take.php?id=<?= $liveExam['id'] ?>" class="btn btn-danger btn-lg">
                            <i class="bi bi-play-fill"></i> Ξεκινήστε Τώρα!
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Stats Cards with Comparison -->
<div class="row g-3 mb-4" id="statsCards">
    <?php if (isAdmin()): ?>
        <div class="col-md-6 col-xl-3">
            <div class="card stats-card primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Αποστολές</h6>
                            <h3 class="mb-0"><?= $stats['missions_total'] ?></h3>
                            <?php 
                            $comparison = $stats['missions_total'] - ($stats['total_hours_last_month'] > 0 ? 1 : 0);
                            $trend = $comparison >= 0 ? 'up' : 'down';
                            $trendClass = $comparison >= 0 ? 'success' : 'danger';
                            ?>
                            <small class="text-<?= $trendClass ?>">
                                <i class="bi bi-arrow-<?= $trend ?>"></i> 
                                <?= $stats['missions_open'] ?> ανοιχτές
                            </small>
                        </div>
                        <div class="text-primary opacity-50">
                            <i class="bi bi-flag fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card stats-card success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Ολοκληρωμένες</h6>
                            <h3 class="mb-0"><?= $stats['missions_completed'] ?></h3>
                            <small class="text-success">
                                <i class="bi bi-check-circle"></i> 
                                <?= $stats['completion_rate'] ?>% ποσοστό
                            </small>
                        </div>
                        <div class="text-success opacity-50">
                            <i class="bi bi-check-circle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card stats-card warning h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Ενεργοί Εθελοντές</h6>
                            <h3 class="mb-0"><?= $stats['active_volunteers_this_month'] ?></h3>
                            <small class="text-muted">
                                <?= $stats['volunteers_active'] ?> από <?= $stats['volunteers_total'] ?> συνολικά
                            </small>
                        </div>
                        <div class="text-warning opacity-50">
                            <i class="bi bi-people fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card stats-card danger h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="text-muted mb-1">Ώρες Μήνα</h6>
                            <h3 class="mb-0"><?= number_format($stats['total_hours_this_month'], 0) ?></h3>
                            <?php 
                            $hoursDiff = $stats['total_hours_this_month'] - $stats['total_hours_last_month'];
                            $hoursPercent = $stats['total_hours_last_month'] > 0 
                                ? round(($hoursDiff / $stats['total_hours_last_month']) * 100) 
                                : 0;
                            $hoursTrend = $hoursDiff >= 0 ? 'up' : 'down';
                            $hoursTrendClass = $hoursDiff >= 0 ? 'success' : 'danger';
                            ?>
                            <small class="text-<?= $hoursTrendClass ?>">
                                <i class="bi bi-arrow-<?= $hoursTrend ?>"></i> 
                                <?= abs($hoursPercent) ?>% vs προηγ. μήνα
                            </small>
                        </div>
                        <div class="text-danger opacity-50">
                            <i class="bi bi-clock-history fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-6 col-md-6 col-lg-3">
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
        <div class="col-6 col-md-6 col-lg-3">
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
        <div class="col-6 col-md-6 col-lg-3">
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
        <div class="col-6 col-md-6 col-lg-3">
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

<?php if (isAdmin()): ?>
<!-- Interactive Charts & Widgets Section -->
<div class="mt-4 mb-3">
    <h5 class="text-muted"><i class="bi bi-grip-vertical me-2"></i>Widgets - Σύρετε για Αναδιάταξη</h5>
    <small class="text-muted">Κάντε κλικ και σύρετε τις κάρτες για να αλλάξετε τη σειρά τους</small>
</div>
<div id="draggableWidgets" class="widgets-container">
    <!-- Monthly Trends Chart -->
    <div class="widget-item mb-4" data-widget-id="monthly-trends">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-graph-up me-2"></i>Τάσεις Μηνός
                </h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this)">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="card-body">
                <canvas id="monthlyTrendsChart" height="80"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Top Volunteers Widget -->
    <div class="widget-item mb-4" data-widget-id="top-volunteers">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-trophy me-2"></i>Κορυφαίοι Εθελοντές
                </h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this)">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($topVolunteers)): ?>
                    <p class="text-muted text-center">Δεν υπάρχουν δεδομένα.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topVolunteers as $idx => $vol): ?>
                            <div class="list-group-item px-0 d-flex align-items-center">
                                <span class="badge bg-primary me-3">#<?= $idx + 1 ?></span>
                                <div class="flex-grow-1">
                                    <strong><?= h($vol['name']) ?></strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= number_format($vol['total_hours'], 1) ?>ω · 
                                        <?= $vol['shifts_count'] ?> βάρδιες
                                    </small>
                                </div>
                                <span class="text-warning">
                                    <i class="bi bi-star-fill"></i> <?= number_format($vol['total_points']) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Completion Rate Chart -->
    <div class="widget-item mb-4" data-widget-id="completion-rate">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-pie-chart me-2"></i>Ποσοστό Ολοκλήρωσης
                </h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this)">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="card-body d-flex justify-content-center">
                <canvas id="completionRateChart" width="300" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Recent Activity Timeline -->
    <div class="widget-item mb-4" data-widget-id="recent-activity">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-activity me-2"></i>Πρόσφατη Δραστηριότητα
                </h5>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this)">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="card-body" style="max-height: 350px; overflow-y: auto;">
                <?php
                // Fetch recent activity
                $recentActivity = dbFetchAll("
                    SELECT 
                        'mission' as type,
                        title as description,
                        created_at as timestamp,
                        status
                    FROM missions
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    UNION ALL
                    SELECT 
                        'participation' as type,
                        CONCAT(u.name, ' αιτήθηκε για βάρδια') as description,
                        pr.created_at as timestamp,
                        pr.status
                    FROM participation_requests pr
                    JOIN users u ON pr.volunteer_id = u.id
                    WHERE pr.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY timestamp DESC
                    LIMIT 10
                ");
                ?>
                <?php if (empty($recentActivity)): ?>
                    <p class="text-muted text-center">Δεν υπάρχει πρόσφατη δραστηριότητα.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="d-flex mb-3">
                                <div class="me-3">
                                    <?php if ($activity['type'] === 'mission'): ?>
                                        <i class="bi bi-flag-fill text-primary"></i>
                                    <?php else: ?>
                                        <i class="bi bi-person-check-fill text-success"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-grow-1">
                                    <small class="text-muted"><?= formatDateTime($activity['timestamp']) ?></small>
                                    <p class="mb-1"><?= h($activity['description']) ?></p>
                                    <?= statusBadge($activity['status']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Missions -->
    <div class="widget-item mb-4" data-widget-id="recent-missions">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-flag me-2"></i>Πρόσφατες Αποστολές</h5>
                <div>
                    <a href="missions.php" class="btn btn-sm btn-outline-light me-2">Όλες</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this, event)">
                        <i class="bi bi-eye-slash"></i>
                    </button>
                </div>
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
    <div class="widget-item mb-4" data-widget-id="pending-requests">
        <div class="card h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-check me-2"></i>Εκκρεμείς Αιτήσεις</h5>
                <div>
                    <a href="participations.php" class="btn btn-sm btn-outline-light me-2">Όλες</a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleWidget(this, event)">
                        <i class="bi bi-eye-slash"></i>
                    </button>
                </div>
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
                                            <form method="post" action="participation-action.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= $request['id'] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-sm btn-success" title="Έγκριση">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="participation-action.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= $request['id'] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Απόρριψη">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </form>
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
</div>

<style>
.widgets-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1rem;
}

.widget-item {
    min-height: 200px;
}

.draggable-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.draggable-card .card-header {
    cursor: move;
    cursor: grab;
    user-select: none;
}

.draggable-card .card-header:active {
    cursor: grabbing;
}

.draggable-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.sortable-ghost {
    opacity: 0.4;
    background: #f8f9fa;
}

.sortable-drag {
    opacity: 0.8;
}

@media (max-width: 768px) {
    .widgets-container {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
// Initialize Chart.js charts
document.addEventListener('DOMContentLoaded', function() {
    // Monthly Trends Chart
    const monthlyTrendsCtx = document.getElementById('monthlyTrendsChart');
    if (monthlyTrendsCtx) {
        new Chart(monthlyTrendsCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($monthlyStats, 'month_label')) ?>,
                datasets: [
                    {
                        label: 'Αποστολές',
                        data: <?= json_encode(array_column($monthlyStats, 'missions')) ?>,
                        borderColor: 'rgb(54, 162, 235)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Εθελοντές',
                        data: <?= json_encode(array_column($monthlyStats, 'volunteers')) ?>,
                        borderColor: 'rgb(255, 159, 64)',
                        backgroundColor: 'rgba(255, 159, 64, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Ώρες (x10)',
                        data: <?= json_encode(array_map(function($h) { return round($h/10); }, array_column($monthlyStats, 'hours'))) ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    // Completion Rate Doughnut Chart
    const completionRateCtx = document.getElementById('completionRateChart');
    if (completionRateCtx) {
        const total = <?= $stats['missions_total'] ?>;
        const completed = <?= $stats['missions_completed'] ?>;
        const open = <?= $stats['missions_open'] ?>;
        const other = total - completed - open;
        
        new Chart(completionRateCtx, {
            type: 'doughnut',
            data: {
                labels: ['Ολοκληρωμένες', 'Ανοιχτές', 'Άλλες'],
                datasets: [{
                    data: [completed, open, other],
                    backgroundColor: [
                        'rgb(40, 167, 69)',
                        'rgb(0, 123, 255)',
                        'rgb(108, 117, 125)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
    
    // Initialize Sortable.js for draggable widgets
    const draggableContainer = document.getElementById('draggableWidgets');
    console.log('Draggable container:', draggableContainer);
    console.log('Sortable available:', typeof Sortable);
    
    if (draggableContainer && typeof Sortable !== 'undefined') {
        console.log('Initializing Sortable on widgets...');
        const sortable = new Sortable(draggableContainer, {
            animation: 200,
            handle: '.card-header',
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag',
            forceFallback: false,
            onStart: function(evt) {
                console.log('Drag started');
                evt.item.style.opacity = '0.8';
            },
            onEnd: function(evt) {
                console.log('Drag ended');
                evt.item.style.opacity = '1';
                // Save widget order to localStorage
                const order = Array.from(draggableContainer.children).map(el => 
                    el.getAttribute('data-widget-id')
                );
                localStorage.setItem('dashboardWidgetOrder', JSON.stringify(order));
            }
        });
        
        // Restore saved widget order
        const savedOrder = localStorage.getItem('dashboardWidgetOrder');
        if (savedOrder) {
            try {
                const order = JSON.parse(savedOrder);
                order.forEach(widgetId => {
                    const widget = draggableContainer.querySelector(`[data-widget-id="${widgetId}"]`);
                    if (widget) {
                        draggableContainer.appendChild(widget);
                    }
                });
            } catch (e) {
                console.error('Error restoring widget order:', e);
            }
        }
    }
});

// Toggle widget visibility
function toggleWidget(btn, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    console.log('Toggle clicked');
    const card = btn.closest('.card');
    const body = card.querySelector('.card-body');
    const icon = btn.querySelector('i');
    
    if (body.style.display === 'none') {
        body.style.display = 'block';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        body.style.display = 'none';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
    
    // Save visibility state
    const widgetId = card.closest('[data-widget-id]').getAttribute('data-widget-id');
    const hidden = body.style.display === 'none';
    const hiddenWidgets = JSON.parse(localStorage.getItem('hiddenWidgets') || '[]');
    
    if (hidden && !hiddenWidgets.includes(widgetId)) {
        hiddenWidgets.push(widgetId);
    } else if (!hidden) {
        const index = hiddenWidgets.indexOf(widgetId);
        if (index > -1) hiddenWidgets.splice(index, 1);
    }
    
    localStorage.setItem('hiddenWidgets', JSON.stringify(hiddenWidgets));
}

// Restore hidden widgets on page load
document.addEventListener('DOMContentLoaded', function() {
    const hiddenWidgets = JSON.parse(localStorage.getItem('hiddenWidgets') || '[]');
    hiddenWidgets.forEach(widgetId => {
        const widget = document.querySelector(`[data-widget-id="${widgetId}"]`);
        if (widget) {
            const body = widget.querySelector('.card-body');
            const btn = widget.querySelector('.card-header button');
            const icon = btn.querySelector('i');
            
            body.style.display = 'none';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    });
});
</script>
<?php endif; ?>

<!-- Customization Modal -->
<div class="modal fade" id="customizeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Προσαρμογή Dashboard</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6 class="mb-3">Ορατότητα Widgets</h6>
                <div class="list-group mb-4">
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="monthly-trends" checked>
                        <div>
                            <i class="bi bi-graph-up text-primary me-2"></i>
                            <strong>Τάσεις Μηνός</strong>
                        </div>
                    </label>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="top-volunteers" checked>
                        <div>
                            <i class="bi bi-trophy text-warning me-2"></i>
                            <strong>Κορυφαίοι Εθελοντές</strong>
                        </div>
                    </label>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="completion-rate" checked>
                        <div>
                            <i class="bi bi-pie-chart text-success me-2"></i>
                            <strong>Ποσοστό Ολοκλήρωσης</strong>
                        </div>
                    </label>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="recent-activity" checked>
                        <div>
                            <i class="bi bi-activity text-info me-2"></i>
                            <strong>Πρόσφατη Δραστηριότητα</strong>
                        </div>
                    </label>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="recent-missions" checked>
                        <div>
                            <i class="bi bi-flag text-primary me-2"></i>
                            <strong>Πρόσφατες Αποστολές</strong>
                        </div>
                    </label>
                    <label class="list-group-item d-flex align-items-center">
                        <input type="checkbox" class="form-check-input me-3" data-widget="pending-requests" checked>
                        <div>
                            <i class="bi bi-person-check text-danger me-2"></i>
                            <strong>Εκκρεμείς Αιτήσεις</strong>
                        </div>
                    </label>
                </div>
                
                <h6 class="mb-3">Ρυθμίσεις</h6>
                <button class="btn btn-outline-secondary w-100 mb-2" id="resetLayoutBtn">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Επαναφορά Διάταξης
                </button>
                <button class="btn btn-outline-danger w-100" id="clearPreferencesBtn">
                    <i class="bi bi-trash me-2"></i>Καθαρισμός Όλων των Προτιμήσεων
                </button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
                <button type="button" class="btn btn-primary" id="saveCustomizationBtn">
                    <i class="bi bi-check-lg me-2"></i>Αποθήκευση
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Open customization modal
document.getElementById('customizeBtn')?.addEventListener('click', function() {
    // Load current visibility preferences
    const hiddenWidgets = JSON.parse(localStorage.getItem('hiddenWidgets') || '[]');
    document.querySelectorAll('[data-widget]').forEach(checkbox => {
        const widgetId = checkbox.getAttribute('data-widget');
        checkbox.checked = !hiddenWidgets.includes(widgetId);
    });
    
    new bootstrap.Modal(document.getElementById('customizeModal')).show();
});

// Save customization
document.getElementById('saveCustomizationBtn')?.addEventListener('click', function() {
    const hiddenWidgets = [];
    document.querySelectorAll('[data-widget]').forEach(checkbox => {
        const widgetId = checkbox.getAttribute('data-widget');
        const widget = document.querySelector(`[data-widget-id="${widgetId}"]`);
        
        if (!checkbox.checked) {
            hiddenWidgets.push(widgetId);
            if (widget) {
                widget.style.display = 'none';
            }
        } else {
            if (widget) {
                widget.style.display = 'block';
            }
        }
    });
    
    localStorage.setItem('hiddenWidgets', JSON.stringify(hiddenWidgets));
    bootstrap.Modal.getInstance(document.getElementById('customizeModal')).hide();
    
    // Show success message
    const alert = document.createElement('div');
    alert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
    alert.style.zIndex = '9999';
    alert.innerHTML = '<i class="bi bi-check-circle me-2"></i>Οι προτιμήσεις αποθηκεύτηκαν! <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 3000);
});

// Reset layout
document.getElementById('resetLayoutBtn')?.addEventListener('click', function() {
    localStorage.removeItem('dashboardWidgetOrder');
    location.reload();
});

// Clear all preferences
document.getElementById('clearPreferencesBtn')?.addEventListener('click', function() {
    if (confirm('Θέλετε σίγουρα να διαγράψετε όλες τις προτιμήσεις σας;')) {
        localStorage.removeItem('dashboardWidgetOrder');
        localStorage.removeItem('hiddenWidgets');
        location.reload();
    }
});
</script>

<div class="row g-4 mt-4">
    <?php if (!isAdmin()): ?>
        <!-- My Upcoming Shifts -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Οι Επόμενες Βάρδιες μου</h5>
                    <a href="my-participations.php" class="btn btn-sm btn-outline-primary">Όλες</a>
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
