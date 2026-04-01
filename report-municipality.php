<?php
/**
 * VolunteerOps - Αναφορά Δήμου (Municipality Report Generator)
 * Professional HTML report with rich statistics for municipality presentations
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Αναφορά Δήμου';
$currentPage = 'report-municipality';
$currentUser = getCurrentUser();

// Safe DB helpers
function safeVal($sql, $params = []) {
    try { $v = dbFetchValue($sql, $params); return $v !== false && $v !== null ? $v : 0; } catch (Exception $e) { return 0; }
}
function safeAll($sql, $params = []) {
    try { return dbFetchAll($sql, $params); } catch (Exception $e) { return []; }
}

// Fetch mission types
$missionTypes = dbFetchAll("SELECT * FROM mission_types WHERE is_active = 1 ORDER BY sort_order, name");

// Greek month names
$greekMonths = ['', 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
                'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

// ---------- PROCESS FILTERS ----------
$selectedTypes = array_map('intval', (array)(get('types') ?: []));
$period = get('period', '');
$customStart = get('start_date', '');
$customEnd = get('end_date', '');
$showIncidents = get('show_incidents', '') === '1';
$generate = !empty($selectedTypes) && !empty($period);

$startDate = '';
$endDate = date('Y-m-d');
if ($period === '1m') {
    $startDate = date('Y-m-d', strtotime('-1 month'));
} elseif ($period === '3m') {
    $startDate = date('Y-m-d', strtotime('-3 months'));
} elseif ($period === '6m') {
    $startDate = date('Y-m-d', strtotime('-6 months'));
} elseif ($period === '1y') {
    $startDate = date('Y-m-d', strtotime('-1 year'));
} elseif ($period === 'custom' && $customStart && $customEnd) {
    $startDate = $customStart;
    $endDate = $customEnd;
} elseif ($period === 'custom') {
    $generate = false;
}

// Department filter for dept admins
$deptFilter = '';
$deptParams = [];
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && !empty($currentUser['department_id'])) {
    $deptFilter = ' AND m.department_id = ?';
    $deptParams = [$currentUser['department_id']];
}

$appName = getSetting('app_name', APP_NAME);

// ---------- GATHER REPORT DATA ----------
$reportData = null;
if ($generate) {
    $typePlaceholders = implode(',', array_fill(0, count($selectedTypes), '?'));
    $baseWhere = "m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN ($typePlaceholders)" . $deptFilter;
    $baseParams = array_merge([$startDate, $endDate . ' 23:59:59'], $selectedTypes, $deptParams);

    // --- KPI: Missions ---
    $totalMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere", $baseParams);
    $completedMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.status = ?", array_merge($baseParams, [STATUS_COMPLETED]));
    $canceledMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.status = ?", array_merge($baseParams, [STATUS_CANCELED]));
    $openMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.status = ?", array_merge($baseParams, [STATUS_OPEN]));
    $urgentMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.is_urgent = 1", $baseParams);
    $completionRate = $totalMissions > 0 ? round(($completedMissions / $totalMissions) * 100, 1) : 0;

    // --- KPI: Hours & Volunteers ---
    $totalHours = safeVal(
        "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND $baseWhere", $baseParams
    );
    $activeVolunteers = safeVal(
        "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND $baseWhere", $baseParams
    );
    $totalShifts = safeVal(
        "SELECT COUNT(DISTINCT s.id) FROM shifts s JOIN missions m ON s.mission_id = m.id
         WHERE $baseWhere", $baseParams
    );

    // --- KPI: Participation ---
    $totalApplications = safeVal(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE $baseWhere", $baseParams
    );
    $approvedApplications = safeVal(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE pr.status = ? AND $baseWhere", array_merge([PARTICIPATION_APPROVED], $baseParams)
    );
    $attendedCount = safeVal(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND $baseWhere", $baseParams
    );
    $attendanceRate = $approvedApplications > 0 ? round(($attendedCount / $approvedApplications) * 100, 1) : 0;
    $noShows = $approvedApplications - $attendedCount;
    $noShowRate = $approvedApplications > 0 ? round(($noShows / $approvedApplications) * 100, 1) : 0;

    // --- KPI: Fill Rate ---
    $avgFillRate = safeVal(
        "SELECT ROUND(AVG(CASE WHEN s.max_volunteers > 0 THEN
            (SELECT COUNT(*) FROM participation_requests pr2 WHERE pr2.shift_id = s.id AND pr2.status = 'APPROVED') / s.max_volunteers * 100
         ELSE 0 END), 1)
         FROM shifts s JOIN missions m ON s.mission_id = m.id WHERE $baseWhere", $baseParams
    );

    // --- KPI: Response Time ---
    $avgResponseHours = safeVal(
        "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, pr.created_at, pr.decided_at)), 1)
         FROM participation_requests pr JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         WHERE pr.decided_at IS NOT NULL AND $baseWhere", $baseParams
    );

    // --- KPI: Points ---
    $totalPoints = safeVal(
        "SELECT COALESCE(SUM(vp.points), 0) FROM volunteer_points vp
         JOIN users u ON vp.user_id = u.id
         WHERE vp.created_at >= ? AND vp.created_at <= ?",
        [$startDate, $endDate . ' 23:59:59']
    );

    // --- KPI: Debrief Ratings ---
    $avgRating = safeVal(
        "SELECT ROUND(AVG(md.rating), 1) FROM mission_debriefs md
         JOIN missions m ON md.mission_id = m.id WHERE md.rating IS NOT NULL AND $baseWhere", $baseParams
    );
    $debriefCount = safeVal(
        "SELECT COUNT(*) FROM mission_debriefs md
         JOIN missions m ON md.mission_id = m.id WHERE $baseWhere", $baseParams
    );
    $objectivesMetFull = safeVal(
        "SELECT COUNT(*) FROM mission_debriefs md
         JOIN missions m ON md.mission_id = m.id WHERE md.objectives_met = 'YES' AND $baseWhere", $baseParams
    );
    $objectivesPartial = safeVal(
        "SELECT COUNT(*) FROM mission_debriefs md
         JOIN missions m ON md.mission_id = m.id WHERE md.objectives_met = 'PARTIAL' AND $baseWhere", $baseParams
    );
    $incidentCount = safeVal(
        "SELECT COUNT(*) FROM mission_debriefs md
         JOIN missions m ON md.mission_id = m.id WHERE md.incidents IS NOT NULL AND md.incidents != '' AND $baseWhere", $baseParams
    );

    // --- Per mission type breakdown ---
    $typeBreakdown = safeAll(
        "SELECT mt.name, mt.color, mt.icon,
                COUNT(DISTINCT m.id) as mission_count,
                SUM(m.status = '" . STATUS_COMPLETED . "') as completed,
                SUM(m.status = '" . STATUS_CANCELED . "') as canceled,
                COALESCE((SELECT COUNT(DISTINCT s2.id) FROM shifts s2 JOIN missions m2 ON s2.mission_id = m2.id
                 WHERE m2.deleted_at IS NULL AND m2.start_datetime >= ? AND m2.start_datetime <= ? AND m2.mission_type_id = mt.id" . $deptFilter . "), 0) as shift_count,
                COALESCE((SELECT SUM(pr2.actual_hours) FROM participation_requests pr2 
                 JOIN shifts s3 ON pr2.shift_id = s3.id JOIN missions m3 ON s3.mission_id = m3.id 
                 WHERE pr2.attended = 1 AND m3.deleted_at IS NULL AND m3.start_datetime >= ? AND m3.start_datetime <= ? AND m3.mission_type_id = mt.id" . $deptFilter . "), 0) as hours,
                COALESCE((SELECT COUNT(DISTINCT pr3.volunteer_id) FROM participation_requests pr3 
                 JOIN shifts s4 ON pr3.shift_id = s4.id JOIN missions m4 ON s4.mission_id = m4.id 
                 WHERE pr3.attended = 1 AND m4.deleted_at IS NULL AND m4.start_datetime >= ? AND m4.start_datetime <= ? AND m4.mission_type_id = mt.id" . $deptFilter . "), 0) as volunteers
         FROM mission_types mt JOIN missions m ON m.mission_type_id = mt.id
         WHERE $baseWhere GROUP BY mt.id, mt.name, mt.color, mt.icon ORDER BY mission_count DESC",
        array_merge(
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            $baseParams
        )
    );

    // --- Monthly trend ---
    $monthlyTrend = safeAll(
        "SELECT DATE_FORMAT(m.start_datetime, '%Y-%m') as ym,
                COUNT(DISTINCT m.id) as missions,
                COALESCE(SUM(CASE WHEN pr.attended = 1 THEN pr.actual_hours ELSE 0 END), 0) as hours,
                COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.volunteer_id END) as volunteers,
                COUNT(DISTINCT CASE WHEN pr.status = 'APPROVED' THEN pr.id END) as approved,
                COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.id END) as attended_count
         FROM missions m
         LEFT JOIN shifts s ON s.mission_id = m.id
         LEFT JOIN participation_requests pr ON pr.shift_id = s.id
         WHERE $baseWhere
         GROUP BY ym ORDER BY ym ASC",
        $baseParams
    );

    // --- Top 10 volunteers ---
    $topVolunteers = safeAll(
        "SELECT u.name, d.name as dept_name,
                SUM(pr.actual_hours) as total_hours,
                COUNT(DISTINCT m.id) as mission_count,
                COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.id END) as shifts_attended,
                u.total_points
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id JOIN missions m ON s.mission_id = m.id
         JOIN users u ON pr.volunteer_id = u.id
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE pr.attended = 1 AND $baseWhere
         GROUP BY u.id, u.name, d.name, u.total_points
         ORDER BY total_hours DESC LIMIT 10",
        $baseParams
    );

    // --- Department breakdown ---
    $deptBreakdown = safeAll(
        "SELECT d.name as dept_name,
                COUNT(DISTINCT m.id) as missions,
                COUNT(DISTINCT CASE WHEN m.status = 'COMPLETED' THEN m.id END) as completed,
                COALESCE(SUM(CASE WHEN pr.attended = 1 THEN pr.actual_hours ELSE 0 END), 0) as hours,
                COUNT(DISTINCT CASE WHEN pr.attended = 1 THEN pr.volunteer_id END) as volunteers,
                COUNT(DISTINCT s.id) as shifts
         FROM departments d
         JOIN missions m ON m.department_id = d.id
         LEFT JOIN shifts s ON s.mission_id = m.id
         LEFT JOIN participation_requests pr ON pr.shift_id = s.id
         WHERE d.is_active = 1 AND $baseWhere
         GROUP BY d.id, d.name ORDER BY missions DESC",
        $baseParams
    );

    // --- Mission status distribution ---
    $statusDist = safeAll(
        "SELECT m.status, COUNT(*) as cnt FROM missions m WHERE $baseWhere GROUP BY m.status ORDER BY cnt DESC",
        $baseParams
    );

    // --- Debrief details (top rated / incidents) ---
    $debriefDetails = safeAll(
        "SELECT m.title, md.rating, md.objectives_met, 
                CASE WHEN md.incidents IS NOT NULL AND md.incidents != '' THEN 1 ELSE 0 END as has_incident,
                m.start_datetime
         FROM mission_debriefs md JOIN missions m ON md.mission_id = m.id
         WHERE $baseWhere ORDER BY md.rating DESC, m.start_datetime DESC LIMIT 10",
        $baseParams
    );

    // --- Full incident list (when checkbox enabled) ---
    $incidentList = [];
    if ($showIncidents) {
        $incidentList = safeAll(
            "SELECT m.title, m.start_datetime, md.incidents, md.created_at as debrief_date
             FROM mission_debriefs md JOIN missions m ON md.mission_id = m.id
             WHERE md.incidents IS NOT NULL AND md.incidents != '' AND $baseWhere
             ORDER BY m.start_datetime DESC",
            $baseParams
        );
    }

    // Selected type names
    $selectedTypeNames = [];
    foreach ($missionTypes as $mt) {
        if (in_array($mt['id'], $selectedTypes)) {
            $selectedTypeNames[] = $mt['name'];
        }
    }

    // Average hours per volunteer
    $avgHoursPerVolunteer = $activeVolunteers > 0 ? round($totalHours / $activeVolunteers, 1) : 0;
    // Average volunteers per mission
    $avgVolPerMission = $totalMissions > 0 ? round($activeVolunteers / $totalMissions, 1) : 0;

    $reportData = compact(
        'totalMissions', 'completedMissions', 'canceledMissions', 'openMissions', 'urgentMissions',
        'completionRate', 'totalHours', 'activeVolunteers', 'totalShifts',
        'totalApplications', 'approvedApplications', 'attendedCount', 'attendanceRate',
        'noShows', 'noShowRate', 'avgFillRate', 'avgResponseHours', 'totalPoints',
        'avgRating', 'debriefCount', 'objectivesMetFull', 'objectivesPartial', 'incidentCount',
        'typeBreakdown', 'monthlyTrend', 'topVolunteers', 'deptBreakdown',
        'statusDist', 'debriefDetails', 'selectedTypeNames',
        'avgHoursPerVolunteer', 'avgVolPerMission', 'incidentList'
    );
}

include __DIR__ . '/includes/header.php';
?>

<!-- Filter Form -->
<div class="card shadow-sm mb-4 no-print">
    <div class="card-header bg-primary bg-opacity-10">
        <h4 class="mb-0"><i class="bi bi-file-earmark-pdf me-2"></i>Αναφορά Δήμου</h4>
    </div>
    <div class="card-body">
        <form method="get" id="reportForm">
            <div class="mb-4">
                <label class="form-label fw-bold"><i class="bi bi-tags me-1"></i>Τύποι Αποστολών</label>
                <div class="mb-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllTypes(true)">
                        <i class="bi bi-check-all me-1"></i>Επιλογή Όλων
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" onclick="toggleAllTypes(false)">
                        <i class="bi bi-x-lg me-1"></i>Αποεπιλογή
                    </button>
                </div>
                <div class="row g-2">
                    <?php foreach ($missionTypes as $mt): ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="form-check">
                            <input class="form-check-input type-checkbox" type="checkbox" name="types[]" 
                                   value="<?= $mt['id'] ?>" id="type_<?= $mt['id'] ?>"
                                   <?= in_array($mt['id'], $selectedTypes) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="type_<?= $mt['id'] ?>">
                                <span class="badge bg-<?= h($mt['color']) ?> me-1"><i class="bi <?= h($mt['icon']) ?>"></i></span>
                                <?= h($mt['name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold"><i class="bi bi-calendar-range me-1"></i>Χρονική Περίοδος</label>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach (['1m'=>'Μήνας','3m'=>'Τρίμηνο','6m'=>'Εξάμηνο','1y'=>'Έτος','custom'=>'Προσαρμοσμένο'] as $val => $label): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="period" value="<?= $val ?>" 
                               id="period_<?= $val ?>" <?= $period === $val ? 'checked' : '' ?> onchange="toggleCustomDates()">
                        <label class="form-check-label" for="period_<?= $val ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="row g-3" id="customDates" style="<?= $period === 'custom' ? '' : 'display:none' ?>">
                    <div class="col-md-3">
                        <label class="form-label">Από</label>
                        <input type="date" class="form-control" name="start_date" value="<?= h($customStart) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Έως</label>
                        <input type="date" class="form-control" name="end_date" value="<?= h($customEnd) ?>">
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-bold"><i class="bi bi-sliders me-1"></i>Επιλογές Αναφοράς</label>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_incidents" value="1" 
                           id="show_incidents" <?= $showIncidents ? 'checked' : '' ?>>
                    <label class="form-check-label" for="show_incidents">
                        <i class="bi bi-exclamation-triangle text-danger me-1"></i>Συμπερίληψη Αναφοράς Συμβάντων
                    </label>
                    <div class="form-text">Εμφανίζει αναλυτικά όλα τα συμβάντα από τα debrief αποστολών</div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-file-earmark-bar-graph me-2"></i>Δημιουργία Αναφοράς
            </button>
        </form>
    </div>
</div>

<?php if ($generate && $reportData): ?>
<!-- ==================== PRINTABLE REPORT ==================== -->
<div id="reportContent">

    <!-- Print / Actions Bar -->
    <div class="text-end mb-3 no-print">
        <button class="btn btn-dark btn-lg" onclick="window.print()">
            <i class="bi bi-printer me-2"></i>Εκτύπωση / Αποθήκευση PDF
        </button>
    </div>

    <!-- ===== REPORT COVER HEADER ===== -->
    <div class="report-cover">
        <div class="report-cover-inner">
            <div class="report-emblem">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="report-org"><?= h($appName) ?></div>
            <h1 class="report-title">Αναφορά Δραστηριοτήτων<br>Εθελοντών</h1>
            <div class="report-period">
                <i class="bi bi-calendar3 me-2"></i>
                <?= formatDate($startDate) ?> — <?= formatDate($endDate) ?>
            </div>
            <div class="report-types mt-3">
                <?php foreach ($reportData['selectedTypeNames'] as $tn): ?>
                    <span class="report-type-pill"><?= h($tn) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ===== EXECUTIVE SUMMARY (8 KPIs) ===== -->
    <div class="section-title">
        <i class="bi bi-speedometer2"></i>
        <span>Συνοπτική Επισκόπηση</span>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-navy">
                <div class="kpi-icon"><i class="bi bi-flag"></i></div>
                <div class="kpi-value"><?= number_format($reportData['totalMissions']) ?></div>
                <div class="kpi-label">Σύνολο Αποστολών</div>
                <div class="kpi-sub"><?= $reportData['totalShifts'] ?> βάρδιες</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-green">
                <div class="kpi-icon"><i class="bi bi-check-circle"></i></div>
                <div class="kpi-value"><?= $reportData['completionRate'] ?>%</div>
                <div class="kpi-label">Ολοκλήρωση</div>
                <div class="kpi-sub"><?= $reportData['completedMissions'] ?> από <?= $reportData['totalMissions'] ?></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="bi bi-clock-history"></i></div>
                <div class="kpi-value"><?= number_format($reportData['totalHours'], 0) ?></div>
                <div class="kpi-label">Ώρες Εθελοντισμού</div>
                <div class="kpi-sub">~<?= $reportData['avgHoursPerVolunteer'] ?> ώρες/εθελοντή</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-purple">
                <div class="kpi-icon"><i class="bi bi-people"></i></div>
                <div class="kpi-value"><?= number_format($reportData['activeVolunteers']) ?></div>
                <div class="kpi-label">Ενεργοί Εθελοντές</div>
                <div class="kpi-sub">~<?= $reportData['avgVolPerMission'] ?> ανά αποστολή</div>
            </div>
        </div>
    </div>

    <!-- Second KPI Row -->
    <div class="row g-3 mb-5">
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-teal">
                <div class="kpi-icon"><i class="bi bi-person-check"></i></div>
                <div class="kpi-value"><?= $reportData['attendanceRate'] ?>%</div>
                <div class="kpi-label">Προσέλευση</div>
                <div class="kpi-sub"><?= $reportData['attendedCount'] ?> παρόντες</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-orange">
                <div class="kpi-icon"><i class="bi bi-bar-chart-line"></i></div>
                <div class="kpi-value"><?= $reportData['avgFillRate'] ?>%</div>
                <div class="kpi-label">Μέση Πληρότητα</div>
                <div class="kpi-sub"><?= $reportData['totalApplications'] ?> αιτήσεις</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-gold">
                <div class="kpi-icon"><i class="bi bi-star"></i></div>
                <div class="kpi-value"><?= $reportData['avgRating'] ?: '—' ?><small>/5</small></div>
                <div class="kpi-label">Μέση Αξιολόγηση</div>
                <div class="kpi-sub"><?= $reportData['debriefCount'] ?> αξιολογήσεις</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="kpi-card kpi-red">
                <div class="kpi-icon"><i class="bi bi-lightning"></i></div>
                <div class="kpi-value"><?= $reportData['avgResponseHours'] ?: '—' ?></div>
                <div class="kpi-label">Μ.Ο. Απόκρισης (ώρες)</div>
                <div class="kpi-sub"><?= $reportData['urgentMissions'] ?> επείγουσες</div>
            </div>
        </div>
    </div>

    <!-- ===== MISSION STATUS DISTRIBUTION ===== -->
    <?php if (!empty($reportData['statusDist'])): ?>
    <div class="section-title">
        <i class="bi bi-pie-chart"></i>
        <span>Κατανομή Κατάστασης Αποστολών</span>
    </div>
    <div class="row mb-5">
        <div class="col-md-8 mx-auto">
            <div class="status-bar-container">
                <?php 
                $statusColors = [
                    'COMPLETED' => ['#198754', 'Ολοκληρωμένες'],
                    'OPEN'      => ['#0d6efd', 'Ανοιχτές'],
                    'CLOSED'    => ['#6c757d', 'Κλειστές'],
                    'DRAFT'     => ['#adb5bd', 'Πρόχειρες'],
                    'CANCELED'  => ['#dc3545', 'Ακυρωμένες'],
                ];
                foreach ($reportData['statusDist'] as $sd):
                    $pct = $totalMissions > 0 ? round(($sd['cnt'] / $totalMissions) * 100, 1) : 0;
                    $color = $statusColors[$sd['status']][0] ?? '#6c757d';
                    $label = $statusColors[$sd['status']][1] ?? $sd['status'];
                ?>
                <div class="status-bar-segment" style="width: <?= max($pct, 3) ?>%; background: <?= $color ?>;" 
                     title="<?= h($label) ?>: <?= $sd['cnt'] ?> (<?= $pct ?>%)">
                    <?php if ($pct >= 8): ?><?= $pct ?>%<?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap justify-content-center gap-3 mt-3">
                <?php foreach ($reportData['statusDist'] as $sd):
                    $color = $statusColors[$sd['status']][0] ?? '#6c757d';
                    $label = $statusColors[$sd['status']][1] ?? $sd['status'];
                ?>
                <div class="status-legend-item">
                    <span class="status-dot" style="background: <?= $color ?>;"></span>
                    <?= h($label) ?>: <strong><?= $sd['cnt'] ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== MISSION TYPE BREAKDOWN ===== -->
    <?php if (!empty($reportData['typeBreakdown'])): ?>
    <div class="section-title">
        <i class="bi bi-bar-chart"></i>
        <span>Ανάλυση ανά Τύπο Αποστολής</span>
    </div>
    <div class="report-table-wrap mb-5">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Τύπος Αποστολής</th>
                    <th class="text-center">Αποστολές</th>
                    <th class="text-center">Ολοκλ.</th>
                    <th class="text-center">Ακυρ.</th>
                    <th class="text-center">Βάρδιες</th>
                    <th class="text-center">Ώρες</th>
                    <th class="text-center">Εθελοντές</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['typeBreakdown'] as $tb): ?>
                <tr>
                    <td>
                        <span class="badge bg-<?= h($tb['color']) ?> me-1"><i class="bi <?= h($tb['icon']) ?>"></i></span>
                        <strong><?= h($tb['name']) ?></strong>
                    </td>
                    <td class="text-center fw-bold"><?= (int)$tb['mission_count'] ?></td>
                    <td class="text-center"><span class="text-success fw-bold"><?= (int)$tb['completed'] ?></span></td>
                    <td class="text-center"><span class="text-danger"><?= (int)$tb['canceled'] ?></span></td>
                    <td class="text-center"><?= (int)$tb['shift_count'] ?></td>
                    <td class="text-center fw-bold"><?= number_format((float)$tb['hours'], 1) ?></td>
                    <td class="text-center"><?= (int)$tb['volunteers'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>Σύνολο</strong></td>
                    <td class="text-center"><strong><?= $reportData['totalMissions'] ?></strong></td>
                    <td class="text-center"><strong class="text-success"><?= $reportData['completedMissions'] ?></strong></td>
                    <td class="text-center"><strong class="text-danger"><?= $reportData['canceledMissions'] ?></strong></td>
                    <td class="text-center"><strong><?= $reportData['totalShifts'] ?></strong></td>
                    <td class="text-center"><strong><?= number_format($reportData['totalHours'], 1) ?></strong></td>
                    <td class="text-center"><strong><?= $reportData['activeVolunteers'] ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== MONTHLY TREND ===== -->
    <?php if (!empty($reportData['monthlyTrend'])): ?>
    <div class="section-title page-break-before">
        <i class="bi bi-graph-up"></i>
        <span>Μηνιαία Εξέλιξη Δραστηριοτήτων</span>
    </div>
    <div class="report-table-wrap mb-5">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Μήνας</th>
                    <th class="text-center">Αποστολές</th>
                    <th class="text-center">Ώρες</th>
                    <th class="text-center">Εθελοντές</th>
                    <th class="text-center">Εγκεκριμένοι</th>
                    <th class="text-center">Παρόντες</th>
                    <th class="text-center">Προσέλευση</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['monthlyTrend'] as $mt): 
                    $ym = explode('-', $mt['ym']);
                    $monthLabel = ($greekMonths[(int)$ym[1]] ?? $mt['ym']) . ' ' . $ym[0];
                    $mAttRate = $mt['approved'] > 0 ? round(($mt['attended_count'] / $mt['approved']) * 100, 1) : 0;
                ?>
                <tr>
                    <td><strong><?= h($monthLabel) ?></strong></td>
                    <td class="text-center"><?= (int)$mt['missions'] ?></td>
                    <td class="text-center fw-bold"><?= number_format((float)$mt['hours'], 1) ?></td>
                    <td class="text-center"><?= (int)$mt['volunteers'] ?></td>
                    <td class="text-center"><?= (int)$mt['approved'] ?></td>
                    <td class="text-center"><?= (int)$mt['attended_count'] ?></td>
                    <td class="text-center">
                        <span class="attendance-pill <?= $mAttRate >= 80 ? 'att-good' : ($mAttRate >= 60 ? 'att-ok' : 'att-low') ?>">
                            <?= $mAttRate ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== PARTICIPATION FUNNEL ===== -->
    <div class="section-title">
        <i class="bi bi-funnel"></i>
        <span>Διαδρομή Συμμετοχής Εθελοντών</span>
    </div>
    <div class="row mb-5">
        <div class="col-md-10 mx-auto">
            <div class="funnel-container">
                <?php
                $funnelSteps = [
                    ['label' => 'Αιτήσεις Συμμετοχής', 'value' => $reportData['totalApplications'], 'color' => '#4e73df', 'icon' => 'bi-envelope'],
                    ['label' => 'Εγκεκριμένες', 'value' => $reportData['approvedApplications'], 'color' => '#1cc88a', 'icon' => 'bi-check2-circle'],
                    ['label' => 'Παρόντες στη Βάρδια', 'value' => $reportData['attendedCount'], 'color' => '#36b9cc', 'icon' => 'bi-person-check'],
                ];
                $maxVal = max($reportData['totalApplications'], 1);
                foreach ($funnelSteps as $i => $step):
                    $fPct = round(($step['value'] / $maxVal) * 100);
                ?>
                <div class="funnel-step">
                    <div class="funnel-bar" style="width: <?= max($fPct, 15) ?>%; background: <?= $step['color'] ?>;">
                        <i class="bi <?= $step['icon'] ?> me-2"></i>
                        <strong><?= number_format($step['value']) ?></strong>
                    </div>
                    <div class="funnel-label"><?= $step['label'] ?></div>
                    <?php if ($i < count($funnelSteps) - 1): ?>
                        <div class="funnel-arrow"><i class="bi bi-chevron-down"></i></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if ($reportData['noShows'] > 0): ?>
                <div class="text-center mt-2">
                    <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2">
                        <i class="bi bi-person-x me-1"></i>Μη Εμφανίσεις: <?= $reportData['noShows'] ?> (<?= $reportData['noShowRate'] ?>%)
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== TOP 10 VOLUNTEERS ===== -->
    <?php if (!empty($reportData['topVolunteers'])): ?>
    <div class="section-title">
        <i class="bi bi-trophy"></i>
        <span>Κορυφαίοι 10 Εθελοντές</span>
    </div>
    <div class="report-table-wrap mb-5">
        <table class="report-table">
            <thead>
                <tr>
                    <th class="text-center" width="60">#</th>
                    <th>Εθελοντής</th>
                    <th>Τμήμα</th>
                    <th class="text-center">Ώρες</th>
                    <th class="text-center">Αποστολές</th>
                    <th class="text-center">Βάρδιες</th>
                    <?php if (getSetting('points_enabled', '1') === '1'): ?>
                    <th class="text-center">Πόντοι</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['topVolunteers'] as $rank => $v): ?>
                <tr class="<?= $rank < 3 ? 'top-three' : '' ?>">
                    <td class="text-center">
                        <?php if ($rank === 0): ?>
                            <span class="medal medal-gold">🥇</span>
                        <?php elseif ($rank === 1): ?>
                            <span class="medal medal-silver">🥈</span>
                        <?php elseif ($rank === 2): ?>
                            <span class="medal medal-bronze">🥉</span>
                        <?php else: ?>
                            <span class="rank-num"><?= $rank + 1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= h($v['name']) ?></strong></td>
                    <td class="text-muted"><?= h($v['dept_name'] ?? '—') ?></td>
                    <td class="text-center fw-bold"><?= number_format((float)$v['total_hours'], 1) ?></td>
                    <td class="text-center"><?= (int)$v['mission_count'] ?></td>
                    <td class="text-center"><?= (int)$v['shifts_attended'] ?></td>
                    <?php if (getSetting('points_enabled', '1') === '1'): ?>
                    <td class="text-center"><span class="points-badge"><?= number_format((int)$v['total_points']) ?></span></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== DEPARTMENT BREAKDOWN ===== -->
    <?php if (!empty($reportData['deptBreakdown']) && count($reportData['deptBreakdown']) > 0): ?>
    <div class="section-title page-break-before">
        <i class="bi bi-building"></i>
        <span>Ανάλυση ανά Τμήμα</span>
    </div>
    <div class="report-table-wrap mb-5">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Τμήμα</th>
                    <th class="text-center">Αποστολές</th>
                    <th class="text-center">Ολοκλ.</th>
                    <th class="text-center">Βάρδιες</th>
                    <th class="text-center">Ώρες</th>
                    <th class="text-center">Εθελοντές</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['deptBreakdown'] as $d): ?>
                <tr>
                    <td><strong><?= h($d['dept_name']) ?></strong></td>
                    <td class="text-center"><?= (int)$d['missions'] ?></td>
                    <td class="text-center text-success fw-bold"><?= (int)$d['completed'] ?></td>
                    <td class="text-center"><?= (int)$d['shifts'] ?></td>
                    <td class="text-center fw-bold"><?= number_format((float)$d['hours'], 1) ?></td>
                    <td class="text-center"><?= (int)$d['volunteers'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ===== DEBRIEF / EVALUATION SUMMARY ===== -->
    <?php if ($reportData['debriefCount'] > 0): ?>
    <div class="section-title">
        <i class="bi bi-clipboard-data"></i>
        <span>Αξιολόγηση Αποστολών (Debrief)</span>
    </div>

    <!-- Debrief KPI strip -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="debrief-stat">
                <div class="debrief-stat-value text-warning">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <i class="bi <?= $s <= round($reportData['avgRating']) ? 'bi-star-fill' : 'bi-star' ?>"></i>
                    <?php endfor; ?>
                </div>
                <div class="debrief-stat-label">Μ.Ο. Αξιολόγησης: <?= $reportData['avgRating'] ?>/5</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="debrief-stat">
                <div class="debrief-stat-value text-success"><?= $reportData['objectivesMetFull'] ?></div>
                <div class="debrief-stat-label">Στόχοι Επιτεύχθηκαν πλήρως</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="debrief-stat">
                <div class="debrief-stat-value text-primary"><?= $reportData['objectivesPartial'] ?></div>
                <div class="debrief-stat-label">Μερική Επίτευξη Στόχων</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="debrief-stat">
                <div class="debrief-stat-value text-danger"><?= $reportData['incidentCount'] ?></div>
                <div class="debrief-stat-label">Αναφερθέντα Συμβάντα</div>
            </div>
        </div>
    </div>

    <!-- Recent Debriefs Table -->
    <?php if (!empty($reportData['debriefDetails'])): ?>
    <div class="report-table-wrap mb-5">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Αποστολή</th>
                    <th class="text-center">Ημ/νία</th>
                    <th class="text-center">Αξιολόγηση</th>
                    <th class="text-center">Στόχοι</th>
                    <th class="text-center">Συμβάν</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reportData['debriefDetails'] as $db): 
                    $objLabel = ['YES' => 'Ναι', 'PARTIAL' => 'Μερικώς', 'NO' => 'Όχι'][$db['objectives_met']] ?? '—';
                    $objClass = ['YES' => 'text-success', 'PARTIAL' => 'text-warning', 'NO' => 'text-danger'][$db['objectives_met']] ?? '';
                ?>
                <tr>
                    <td><?= h($db['title']) ?></td>
                    <td class="text-center text-nowrap"><?= formatDate($db['start_datetime']) ?></td>
                    <td class="text-center text-warning">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <i class="bi <?= $s <= (int)$db['rating'] ? 'bi-star-fill' : 'bi-star' ?>" style="font-size: 0.85rem;"></i>
                        <?php endfor; ?>
                    </td>
                    <td class="text-center <?= $objClass ?> fw-bold"><?= $objLabel ?></td>
                    <td class="text-center"><?= $db['has_incident'] ? '<i class="bi bi-exclamation-triangle text-danger"></i>' : '<i class="bi bi-check-lg text-success"></i>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ===== INCIDENT REPORT SECTION ===== -->
    <?php if ($showIncidents && !empty($reportData['incidentList'])): ?>
    <div class="page-break-before"></div>
    <div class="section-title" style="color: #dc3545;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>Αναφορά Συμβάντων</span>
    </div>
    <p class="text-muted mb-3" style="font-size: 0.9rem;">
        Καταγραφή <?= count($reportData['incidentList']) ?> συμβάντων που αναφέρθηκαν κατά τη διάρκεια αποστολών στην επιλεγμένη περίοδο.
    </p>
    <?php foreach ($reportData['incidentList'] as $idx => $inc): ?>
    <div class="incident-card mb-3">
        <div class="incident-header">
            <div>
                <span class="badge bg-danger me-2">#<?= $idx + 1 ?></span>
                <strong><?= h($inc['title']) ?></strong>
            </div>
            <div class="text-muted" style="font-size: 0.85rem;">
                <i class="bi bi-calendar3 me-1"></i><?= formatDate($inc['start_datetime']) ?>
            </div>
        </div>
        <div class="incident-body">
            <?= nl2br(h($inc['incidents'])) ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ===== REPORT FOOTER ===== -->
    <div class="report-footer">
        <div class="report-footer-line"></div>
        <div class="report-footer-content">
            <div class="report-footer-left">
                <i class="bi bi-shield-check me-1"></i>
                <strong><?= h($appName) ?></strong> v<?= h(APP_VERSION) ?>
            </div>
            <div class="report-footer-right">
                Δημιουργία: <?= formatDateGreek(date('Y-m-d')) ?>, <?= date('H:i') ?>
            </div>
        </div>
        <div class="text-center mt-2" style="font-size: 0.75rem; color: #aaa;">
            Η παρούσα αναφορά παράχθηκε αυτόματα. Τα δεδομένα αντανακλούν την κατάσταση κατά τη στιγμή δημιουργίας.
        </div>
    </div>

</div>
<?php elseif (!empty($period) && empty($selectedTypes)): ?>
    <div class="alert alert-warning no-print">
        <i class="bi bi-exclamation-triangle me-2"></i>Παρακαλώ επιλέξτε τουλάχιστον έναν τύπο αποστολής.
    </div>
<?php endif; ?>

<!-- ==================== STYLES ==================== -->
<style>
/* ===== Report Container ===== */
#reportContent { max-width: 960px; margin: 0 auto; }

/* ===== Cover Header ===== */
.report-cover {
    background: linear-gradient(135deg, #0f2744 0%, #1a4a7a 50%, #2c6faa 100%);
    border-radius: 16px;
    padding: 3px;
    margin-bottom: 2.5rem;
}
.report-cover-inner {
    background: linear-gradient(135deg, #0f2744 0%, #1a4a7a 50%, #2c6faa 100%);
    border-radius: 14px;
    padding: 3rem 2rem;
    text-align: center;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.report-cover-inner::before {
    content: '';
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 70%);
    animation: coverPulse 8s ease-in-out infinite;
}
@keyframes coverPulse {
    0%, 100% { transform: scale(1); opacity: 0.5; }
    50% { transform: scale(1.1); opacity: 1; }
}
.report-emblem { font-size: 3.5rem; margin-bottom: 0.5rem; opacity: 0.9; }
.report-org { font-size: 1rem; text-transform: uppercase; letter-spacing: 4px; opacity: 0.7; margin-bottom: 0.5rem; }
.report-title { font-size: 2rem; font-weight: 700; letter-spacing: 1px; margin-bottom: 1rem; line-height: 1.3; }
.report-period { font-size: 1.1rem; opacity: 0.85; }
.report-type-pill {
    display: inline-block;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 0.85rem;
    margin: 3px 2px;
}

/* ===== Section Titles ===== */
.section-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a3a5c;
    margin-bottom: 1.2rem;
    padding-bottom: 0.5rem;
    border-bottom: 3px solid #1a3a5c;
}
.section-title i { font-size: 1.4rem; }

/* ===== KPI Cards ===== */
.kpi-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.2rem 1rem;
    text-align: center;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    position: relative;
    overflow: hidden;
    height: 100%;
}
.kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
}
.kpi-navy::before   { background: #1a3a5c; }
.kpi-green::before  { background: #198754; }
.kpi-blue::before   { background: #0d6efd; }
.kpi-purple::before { background: #6f42c1; }
.kpi-teal::before   { background: #20c997; }
.kpi-orange::before { background: #fd7e14; }
.kpi-gold::before   { background: #ffc107; }
.kpi-red::before    { background: #dc3545; }

.kpi-icon { font-size: 1.6rem; margin-bottom: 0.3rem; opacity: 0.4; }
.kpi-navy .kpi-icon   { color: #1a3a5c; }
.kpi-green .kpi-icon  { color: #198754; }
.kpi-blue .kpi-icon   { color: #0d6efd; }
.kpi-purple .kpi-icon { color: #6f42c1; }
.kpi-teal .kpi-icon   { color: #20c997; }
.kpi-orange .kpi-icon { color: #fd7e14; }
.kpi-gold .kpi-icon   { color: #ffc107; }
.kpi-red .kpi-icon    { color: #dc3545; }

.kpi-value { font-size: 2rem; font-weight: 800; color: #1a1a2e; line-height: 1.1; }
.kpi-value small { font-size: 0.65em; font-weight: 400; opacity: 0.6; }
.kpi-label { font-size: 0.82rem; font-weight: 600; color: #555; margin-top: 0.2rem; }
.kpi-sub { font-size: 0.75rem; color: #999; margin-top: 0.1rem; }

/* ===== Status Bar ===== */
.status-bar-container {
    display: flex;
    border-radius: 10px;
    overflow: hidden;
    height: 40px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
.status-bar-segment {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 0.8rem;
    font-weight: 700;
    transition: all 0.3s;
}
.status-legend-item { display: flex; align-items: center; gap: 5px; font-size: 0.9rem; }
.status-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }

/* ===== Tables ===== */
.report-table-wrap {
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    border: 1px solid #e2e8f0;
}
.report-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.report-table thead {
    background: linear-gradient(135deg, #1a3a5c, #2c5f8a);
    color: #fff;
}
.report-table thead th {
    padding: 12px 16px;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.report-table tbody tr { border-bottom: 1px solid #f0f0f0; }
.report-table tbody tr:nth-child(even) { background: #f8fafc; }
.report-table tbody tr:hover { background: #eef2f7; }
.report-table tbody td { padding: 10px 16px; }
.report-table tfoot { background: #eef2f7; }
.report-table tfoot td { padding: 12px 16px; border-top: 2px solid #1a3a5c; }

/* Top 3 highlight */
.top-three { background: linear-gradient(90deg, #fff9e6, #fff) !important; }
.medal { font-size: 1.5rem; }
.rank-num { font-size: 1rem; font-weight: 600; color: #666; }
.points-badge {
    background: linear-gradient(135deg, #ffd700, #ffaa00);
    color: #5a3e00;
    padding: 2px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.85rem;
}

/* Attendance pills */
.attendance-pill {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.8rem;
}
.att-good { background: #d1fae5; color: #065f46; }
.att-ok   { background: #fef3c7; color: #92400e; }
.att-low  { background: #fee2e2; color: #991b1b; }

/* ===== Funnel ===== */
.funnel-container { padding: 1rem 0; }
.funnel-step { text-align: center; margin-bottom: 0.3rem; }
.funnel-bar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 1.1rem;
    min-width: 120px;
    transition: width 0.5s;
}
.funnel-label { font-size: 0.85rem; color: #666; margin-top: 2px; font-weight: 600; }
.funnel-arrow { color: #ccc; font-size: 1.2rem; margin: 2px 0; }

/* ===== Debrief Stats ===== */
.debrief-stat {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
}
.debrief-stat-value { font-size: 1.5rem; font-weight: 700; }
.debrief-stat-label { font-size: 0.8rem; color: #666; margin-top: 0.3rem; }

/* ===== Incident Cards ===== */
.incident-card {
    border: 1px solid #f5c6cb;
    border-left: 4px solid #dc3545;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}
.incident-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1rem;
    background: #fff5f5;
    border-bottom: 1px solid #f5c6cb;
}
.incident-body {
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    color: #333;
    line-height: 1.6;
}

/* ===== Footer ===== */
.report-footer { margin-top: 3rem; padding-top: 1rem; }
.report-footer-line { height: 3px; background: linear-gradient(90deg, #1a3a5c, #2c6faa, #1a3a5c); border-radius: 2px; }
.report-footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 0 0.2rem;
    font-size: 0.85rem;
    color: #666;
}

/* ===== PRINT STYLES ===== */
@media print {
    /* Hide EVERYTHING except report */
    .no-print,
    .sidebar, #sidebar, .sidebar-overlay, #sidebarOverlay,
    .top-navbar, nav, .navbar,
    .offcanvas, .breadcrumb, footer,
    .sidebar-section, .nav-item, .sidebar-brand,
    .btn-close, .form-check, .form-label { display: none !important; }
    
    /* Reset layout */
    body {
        background: #fff !important;
        font-size: 10pt;
        margin: 0 !important;
        padding: 0 !important;
    }
    .main-content {
        margin-left: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    .content-wrapper {
        padding: 0 !important;
        margin: 0 !important;
    }
    #reportContent {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .container-fluid, [class*="col-"] {
        padding: 0 !important;
    }
    
    /* Print color preservation */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    /* Cover */
    .report-cover { border-radius: 0; margin: 0 0 1.5rem 0; }
    .report-cover-inner { border-radius: 0; padding: 2rem 1.5rem; }
    .report-cover-inner::before { display: none; }
    
    /* Cards & tables */
    .card, .kpi-card, .report-table-wrap, .debrief-stat {
        box-shadow: none !important;
        break-inside: avoid;
        page-break-inside: avoid;
    }
    .kpi-card { border: 1px solid #ddd; }
    
    /* Page breaks */
    .page-break-before { page-break-before: always; }
    .section-title { page-break-after: avoid; }
    .report-table { page-break-inside: auto; }
    .report-table tr { page-break-inside: avoid; }
    
    /* Incident cards */
    .incident-card { page-break-inside: avoid; border-left: 4px solid #dc3545 !important; }
    .incident-header { background: #fff5f5 !important; }
    
    /* Funnel bars */
    .funnel-bar { min-width: 80px; }
    
    @page { margin: 1.5cm; size: A4; }
}
</style>

<script>
function toggleAllTypes(checked) {
    document.querySelectorAll('.type-checkbox').forEach(cb => cb.checked = checked);
}
function toggleCustomDates() {
    const custom = document.getElementById('period_custom');
    document.getElementById('customDates').style.display = custom.checked ? '' : 'none';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
