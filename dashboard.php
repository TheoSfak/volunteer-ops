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
    
    // Overdue missions (past end date but still OPEN or CLOSED)
    $overdueMissions = dbFetchAll(
        "SELECT m.*, d.name as department_name
         FROM missions m
         LEFT JOIN departments d ON m.department_id = d.id
         WHERE m.status IN ('" . STATUS_OPEN . "', '" . STATUS_CLOSED . "')
           AND m.end_datetime < NOW()
           AND m.deleted_at IS NULL
           $departmentFilter
         ORDER BY m.end_datetime ASC",
        $params
    );
    
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

<style>
/* ===== Dashboard Beautification ===== */
.dash-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 1rem;
    padding: 1.25rem 1.5rem;
    color: #fff;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 32px rgba(102,126,234,.22);
    position: relative;
    overflow: hidden;
}
.dash-hero::before {
    content: '';
    position: absolute;
    top: -60%;
    right: -15%;
    width: 320px;
    height: 320px;
    background: rgba(255,255,255,.06);
    border-radius: 50%;
    pointer-events: none;
}
.dash-hero::after {
    content: '';
    position: absolute;
    bottom: -40%;
    left: -10%;
    width: 200px;
    height: 200px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
    pointer-events: none;
}
.dash-hero .hero-controls .btn {
    border-color: rgba(255,255,255,.4);
    color: #fff;
    background: rgba(255,255,255,.1);
    backdrop-filter: blur(4px);
    font-size: .82rem;
}
.dash-hero .hero-controls .btn:hover { background: rgba(255,255,255,.25); border-color: rgba(255,255,255,.6); }
.dash-hero .hero-controls select { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.3); }
.dash-hero .hero-controls select option { color: #333; background: #fff; }
.ds-stat {
    border: none;
    border-radius: .85rem;
    box-shadow: 0 3px 15px rgba(0,0,0,.06);
    transition: transform .2s ease, box-shadow .2s ease;
    overflow: hidden;
    position: relative;
}
.ds-stat:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,.12);
}
.ds-stat .stat-icon-box {
    width: 52px;
    height: 52px;
    border-radius: .75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    color: #fff;
    flex-shrink: 0;
}
.ds-stat .stat-number {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
    background: linear-gradient(135deg, #333 0%, #555 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}
.ds-stat .stat-label {
    font-size: .78rem;
    color: #888;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.ds-stat .stat-trend {
    font-size: .75rem;
    padding: .15rem .45rem;
    border-radius: .3rem;
    font-weight: 600;
}
.ds-stat .stat-trend.up { background: rgba(16,185,129,.1); color: #059669; }
.ds-stat .stat-trend.down { background: rgba(239,68,68,.1); color: #dc2626; }
.ds-stat .stat-trend.neutral { background: rgba(107,114,128,.1); color: #6b7280; }
.ds-widget {
    border: none;
    border-radius: .85rem;
    box-shadow: 0 3px 15px rgba(0,0,0,.05);
    transition: box-shadow .25s ease;
    overflow: hidden;
}
.ds-widget:hover { box-shadow: 0 6px 22px rgba(0,0,0,.1); }
.ds-widget .card-header {
    background: #fff;
    border-bottom: 2px solid #eee;
    padding: .75rem 1rem;
}
.ds-widget .card-header h5 { font-size: .92rem; font-weight: 600; margin: 0; }
.ds-widget.accent-blue .card-header { border-bottom-color: #667eea; }
.ds-widget.accent-gold .card-header { border-bottom-color: #f59e0b; }
.ds-widget.accent-green .card-header { border-bottom-color: #10b981; }
.ds-widget.accent-red .card-header { border-bottom-color: #ef4444; }
.ds-widget.accent-cyan .card-header { border-bottom-color: #06b6d4; }
.ds-widget.accent-purple .card-header { border-bottom-color: #8b5cf6; }
.ds-widget .card-header .widget-toggle { opacity: .4; transition: opacity .2s; }
.ds-widget .card-header:hover .widget-toggle { opacity: 1; }
/* Leaderboard list item */
.ds-leader-item {
    transition: background .15s;
    border-left: 3px solid transparent;
}
.ds-leader-item:hover { background: #f8f9ff; border-left-color: #667eea; }
.ds-leader-rank {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: .78rem;
}
.ds-leader-rank.gold { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
.ds-leader-rank.silver { background: linear-gradient(135deg, #d1d5db, #9ca3af); color: #fff; }
.ds-leader-rank.bronze { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; }
.ds-leader-rank.default { background: #e5e7eb; color: #6b7280; }
.volunteer-shift-card {
    border-left: 4px solid #667eea;
    transition: transform .15s, box-shadow .15s;
}
.volunteer-shift-card:hover { transform: translateX(4px); box-shadow: 0 2px 12px rgba(0,0,0,.08); }
.pulse-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #ef4444;
    animation: pulseDot 1.5s infinite;
}
@keyframes pulseDot {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
    50% { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
}
@media (max-width: 768px) {
    .dash-hero { padding: 1rem; text-align: center; }
    .dash-hero .d-flex { flex-direction: column; gap: .5rem; }
    .dash-hero .hero-controls { justify-content: center !important; }
    .ds-stat .stat-number { font-size: 1.35rem; }
    .ds-stat .stat-icon-box { width: 40px; height: 40px; font-size: 1.1rem; }
}
</style>

<!-- Dashboard Hero Header -->
<div class="dash-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1 text-white fw-bold">
                <?php
                $hour = (int)date('H');
                $greeting = $hour < 12 ? 'Καλημέρα' : ($hour < 18 ? 'Καλό απόγευμα' : 'Καλό βράδυ');
                ?>
                <?= $greeting ?>, <?= h(explode(' ', $user['name'])[0]) ?>! 
                <span style="font-size:.85rem;opacity:.7;">
                    <?php if (isAdmin()): ?>
                        <i class="bi bi-shield-check me-1"></i>Διαχειριστής
                    <?php else: ?>
                        <i class="bi bi-heart me-1"></i>Εθελοντής
                    <?php endif; ?>
                </span>
            </h1>
            <div style="opacity:.8;font-size:.88rem">
                <i class="bi bi-speedometer2 me-1"></i>Πίνακας Ελέγχου
                <span class="mx-2">·</span>
                <i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y') ?>
                <?php if (isAdmin() && $stats['pending_requests'] > 0): ?>
                    <span class="ms-2 badge bg-danger" style="font-size:.72rem"><span class="pulse-dot me-1"></span><?= $stats['pending_requests'] ?> εκκρεμείς</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-controls d-flex gap-2 flex-shrink-0">
            <button class="btn btn-sm" id="customizeBtn" title="Προσαρμογή Dashboard">
                <i class="bi bi-gear"></i><span class="d-none d-sm-inline"> Προσαρμογή</span>
            </button>
            <select class="form-select form-select-sm" onchange="location.href='?year='+this.value" style="width: auto;">
                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                    <option value="<?= $y ?>" <?= $year == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
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

<!-- Stats Cards -->
<div class="row g-3 mb-4" id="statsCards">
    <?php if (isAdmin()): ?>
        <div class="col-6 col-xl-3">
            <div class="card ds-stat h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                            <i class="bi bi-flag"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Αποστολές</div>
                            <div class="stat-number"><?= $stats['missions_total'] ?></div>
                            <span class="stat-trend up">
                                <i class="bi bi-arrow-up-short"></i><?= $stats['missions_open'] ?> ανοιχτές
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card ds-stat h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#10b981,#059669)">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Ολοκληρωμένες</div>
                            <div class="stat-number"><?= $stats['missions_completed'] ?></div>
                            <span class="stat-trend up">
                                <i class="bi bi-percent"></i> <?= $stats['completion_rate'] ?>% ποσοστό
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card ds-stat h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Ενεργοί Εθελοντές</div>
                            <div class="stat-number"><?= $stats['active_volunteers_this_month'] ?></div>
                            <span class="stat-trend neutral">
                                <?= $stats['volunteers_active'] ?> / <?= $stats['volunteers_total'] ?> σύνολο
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card ds-stat h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#ef4444,#dc2626)">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="stat-label">Ώρες Μήνα</div>
                            <div class="stat-number"><?= number_format($stats['total_hours_this_month'], 0) ?></div>
                            <?php 
                            $hoursDiff = $stats['total_hours_this_month'] - $stats['total_hours_last_month'];
                            $hoursPercent = $stats['total_hours_last_month'] > 0 
                                ? round(($hoursDiff / $stats['total_hours_last_month']) * 100) 
                                : 0;
                            $hoursTrendCls = $hoursDiff >= 0 ? 'up' : 'down';
                            ?>
                            <span class="stat-trend <?= $hoursTrendCls ?>">
                                <i class="bi bi-arrow-<?= $hoursDiff >= 0 ? 'up' : 'down' ?>-short"></i>
                                <?= abs($hoursPercent) ?>% vs προηγ.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card ds-stat">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#667eea,#764ba2)">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div>
                            <div class="stat-label">Βάρδιες</div>
                            <div class="stat-number"><?= $stats['my_shifts'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card ds-stat">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                        <div>
                            <div class="stat-label">Εκκρεμείς</div>
                            <div class="stat-number"><?= $stats['pending_requests'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card ds-stat">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#10b981,#059669)">
                            <i class="bi bi-clock-history"></i>
                        </div>
                        <div>
                            <div class="stat-label">Ώρες</div>
                            <div class="stat-number"><?= number_format($stats['total_hours'], 1) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg-3">
            <div class="card ds-stat">
                <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="stat-icon-box" style="background:linear-gradient(135deg,#ef4444,#dc2626)">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div>
                            <div class="stat-label">Πόντοι</div>
                            <div class="stat-number"><?= number_format($stats['total_points']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (isAdmin()): ?>

<?php if (!empty($overdueMissions)): ?>
<!-- Overdue Missions Alert -->
<div class="alert alert-danger shadow-sm mb-4" style="border-left: 5px solid #dc3545 !important;">
    <div class="d-flex align-items-center mb-2">
        <i class="bi bi-exclamation-triangle-fill fs-4 text-danger me-2"></i>
        <strong class="fs-5">Εκκρεμείς Αποστολές (<?= count($overdueMissions) ?>)</strong>
    </div>
    <p class="mb-2 text-danger">Οι παρακάτω αποστολές έχουν λήξει αλλά δεν έχουν ολοκληρωθεί. Παρακαλώ κλείστε/ολοκληρώστε τες.</p>
    <div class="list-group">
        <?php foreach ($overdueMissions as $om): ?>
        <a href="mission-view.php?id=<?= $om['id'] ?>" class="list-group-item list-group-item-action list-group-item-danger d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-flag-fill me-1"></i>
                <strong><?= h($om['title']) ?></strong>
                <br>
                <small>
                    <?= h($om['department_name'] ?? '-') ?> &middot; 
                    Λήξη: <?= formatDateTime($om['end_datetime']) ?>
                    <?php
                    $elapsed = time() - strtotime($om['end_datetime']);
                    $days = floor($elapsed / 86400);
                    $hours = floor(($elapsed % 86400) / 3600);
                    $et = '';
                    if ($days > 0) $et .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
                    if ($hours > 0) $et .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
                    if (!$et) $et = 'Μόλις τώρα';
                    ?>
                    &middot; <strong class="text-danger">πριν <?= h($et) ?></strong>
                </small>
            </div>
            <div>
                <?= statusBadge($om['status']) ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Interactive Charts & Widgets Section -->
<div id="draggableWidgets" class="widgets-container">
    <!-- Monthly Trends Chart -->
    <div class="widget-item mb-4" data-widget-id="monthly-trends">
        <div class="card ds-widget accent-blue h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>
                    <i class="bi bi-graph-up text-primary me-2"></i>Τάσεις Μηνός
                </h5>
                <button class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this)">
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
        <div class="card ds-widget accent-gold h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>
                    <i class="bi bi-trophy-fill text-warning me-2"></i>Κορυφαίοι Εθελοντές
                </h5>
                <button class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this)">
                    <i class="bi bi-eye-slash"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topVolunteers)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν δεδομένα.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($topVolunteers as $idx => $vol):
                            $rankClass = $idx === 0 ? 'gold' : ($idx === 1 ? 'silver' : ($idx === 2 ? 'bronze' : 'default'));
                        ?>
                            <div class="list-group-item ds-leader-item d-flex align-items-center px-3 py-2">
                                <span class="ds-leader-rank <?= $rankClass ?> me-3"><?= $idx + 1 ?></span>
                                <div class="flex-grow-1">
                                    <strong class="d-block" style="font-size:.9rem"><?= h($vol['name']) ?></strong>
                                    <small class="text-muted">
                                        <?= number_format($vol['total_hours'], 1) ?>ω · 
                                        <?= $vol['shifts_count'] ?> βάρδιες
                                    </small>
                                </div>
                                <span class="badge bg-warning text-dark" style="font-size:.8rem">
                                    <i class="bi bi-star-fill me-1"></i><?= number_format($vol['total_points']) ?>
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
        <div class="card ds-widget accent-green h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>
                    <i class="bi bi-pie-chart-fill text-success me-2"></i>Ποσοστό Ολοκλήρωσης
                </h5>
                <button class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this)">
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
        <div class="card ds-widget accent-purple h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5>
                    <i class="bi bi-activity text-purple me-2" style="color:#8b5cf6"></i>Πρόσφατη Δραστηριότητα
                </h5>
                <button class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this)">
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
        <div class="card ds-widget accent-cyan h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-flag-fill me-2" style="color:#06b6d4"></i>Πρόσφατες Αποστολές</h5>
                <div>
                    <a href="missions.php" class="btn btn-sm btn-outline-info me-1">Όλες</a>
                    <button type="button" class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this, event)">
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
                                            <?php if (in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($mission['end_datetime']) < time()):
                                                $elapsed = time() - strtotime($mission['end_datetime']);
                                                $days = floor($elapsed / 86400);
                                                $hours = floor(($elapsed % 86400) / 3600);
                                                $et = '';
                                                if ($days > 0) $et .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
                                                if ($hours > 0) $et .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
                                                if (!$et) $et = 'Μόλις τώρα';
                                            ?>
                                                <br><span class="badge bg-danger" title="Έληξε πριν <?= h($et) ?>" data-bs-toggle="tooltip">
                                                    <i class="bi bi-clock-history me-1"></i>ΕΛΗΞΕ
                                                </span>
                                            <?php endif; ?>
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
        <div class="card ds-widget accent-red h-100 draggable-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-person-check-fill text-danger me-2"></i>Εκκρεμείς Αιτήσεις <?php if ($stats['pending_requests'] > 0): ?><span class="badge bg-danger ms-1"><?= $stats['pending_requests'] ?></span><?php endif; ?></h5>
                <div>
                    <a href="participations.php" class="btn btn-sm btn-outline-danger me-1">Όλες</a>
                    <button type="button" class="btn btn-sm btn-link widget-toggle" onclick="toggleWidget(this, event)">
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
    grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
    gap: 1rem;
}
.widget-item {
    min-height: 200px;
}
.draggable-card .card-header {
    cursor: move;
    cursor: grab;
    user-select: none;
}
.draggable-card .card-header:active {
    cursor: grabbing;
}
.sortable-ghost {
    opacity: 0.3;
    background: #f0f4ff;
    border: 2px dashed #667eea;
    border-radius: .85rem;
}
.sortable-drag {
    opacity: 0.85;
    box-shadow: 0 12px 40px rgba(0,0,0,.15);
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
            <div class="card ds-widget accent-blue h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-calendar-event text-primary me-2"></i>Επόμενες Βάρδιες</h5>
                    <a href="my-participations.php" class="btn btn-sm btn-outline-primary">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($myShifts)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-calendar-x text-muted" style="font-size:2.5rem;opacity:.3"></i>
                            <p class="text-muted mt-2 mb-0">Δεν έχετε προγραμματισμένες βάρδιες.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($myShifts as $shift): ?>
                                <div class="list-group-item volunteer-shift-card">
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
            <div class="card ds-widget accent-green h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-flag-fill text-success me-2"></i>Διαθέσιμες Αποστολές</h5>
                    <a href="missions.php" class="btn btn-sm btn-outline-success">Όλες</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($availableMissions)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-flag text-muted" style="font-size:2.5rem;opacity:.3"></i>
                            <p class="text-muted mt-2 mb-0">Δεν υπάρχουν διαθέσιμες αποστολές.</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($availableMissions as $mission): ?>
                                <a href="mission-view.php?id=<?= $mission['id'] ?>" class="list-group-item list-group-item-action volunteer-shift-card" style="border-left-color:#10b981">
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
