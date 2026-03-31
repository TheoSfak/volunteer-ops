<?php
/**
 * VolunteerOps - Αναφορά Δήμου (Municipality Report Generator)
 * Generates stylish printable HTML reports for municipality/δήμο
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

// ---------- PROCESS FILTERS ----------
$selectedTypes = array_map('intval', (array)(get('types') ?: []));
$period = get('period', '');
$customStart = get('start_date', '');
$customEnd = get('end_date', '');
$generate = !empty($selectedTypes) && !empty($period);

// Calculate date range from period
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
    $generate = false; // custom selected but no dates
}

// Department filter for dept admins
$deptFilter = '';
$deptParams = [];
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && !empty($currentUser['department_id'])) {
    $deptFilter = ' AND m.department_id = ?';
    $deptParams = [$currentUser['department_id']];
}

// Greek month names (nominative)
$greekMonths = ['', 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
                'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

// ---------- GATHER REPORT DATA ----------
$reportData = null;
if ($generate) {
    $typePlaceholders = implode(',', array_fill(0, count($selectedTypes), '?'));
    $baseWhere = "m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN ($typePlaceholders)" . $deptFilter;
    $baseParams = array_merge([$startDate, $endDate . ' 23:59:59'], $selectedTypes, $deptParams);

    // KPI totals
    $totalMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere", $baseParams);
    $completedMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.status = ?", array_merge($baseParams, [STATUS_COMPLETED]));
    $canceledMissions = safeVal("SELECT COUNT(*) FROM missions m WHERE $baseWhere AND m.status = ?", array_merge($baseParams, [STATUS_CANCELED]));
    $completionRate = $totalMissions > 0 ? round(($completedMissions / $totalMissions) * 100, 1) : 0;

    $totalHours = safeVal(
        "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND $baseWhere", $baseParams
    );

    $activeVolunteers = safeVal(
        "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.attended = 1 AND $baseWhere", $baseParams
    );

    $totalShifts = safeVal(
        "SELECT COUNT(DISTINCT s.id) FROM shifts s
         JOIN missions m ON s.mission_id = m.id
         WHERE $baseWhere", $baseParams
    );

    // Per mission type breakdown
    $typeBreakdown = safeAll(
        "SELECT mt.name, mt.color, mt.icon,
                COUNT(DISTINCT m.id) as mission_count,
                SUM(m.status = '" . STATUS_COMPLETED . "') as completed,
                SUM(m.status = '" . STATUS_CANCELED . "') as canceled,
                (SELECT COUNT(DISTINCT s2.id) FROM shifts s2 WHERE s2.mission_id IN (
                    SELECT m2.id FROM missions m2 WHERE m2.deleted_at IS NULL AND m2.start_datetime >= ? AND m2.start_datetime <= ? AND m2.mission_type_id = mt.id" . $deptFilter . "
                )) as shift_count,
                COALESCE((SELECT SUM(pr2.actual_hours) FROM participation_requests pr2 
                 JOIN shifts s3 ON pr2.shift_id = s3.id 
                 JOIN missions m3 ON s3.mission_id = m3.id 
                 WHERE pr2.attended = 1 AND m3.deleted_at IS NULL AND m3.start_datetime >= ? AND m3.start_datetime <= ? AND m3.mission_type_id = mt.id" . $deptFilter . "), 0) as hours,
                COALESCE((SELECT COUNT(DISTINCT pr3.volunteer_id) FROM participation_requests pr3 
                 JOIN shifts s4 ON pr3.shift_id = s4.id 
                 JOIN missions m4 ON s4.mission_id = m4.id 
                 WHERE pr3.attended = 1 AND m4.deleted_at IS NULL AND m4.start_datetime >= ? AND m4.start_datetime <= ? AND m4.mission_type_id = mt.id" . $deptFilter . "), 0) as volunteers
         FROM mission_types mt
         JOIN missions m ON m.mission_type_id = mt.id
         WHERE $baseWhere
         GROUP BY mt.id, mt.name, mt.color, mt.icon
         ORDER BY mission_count DESC",
        array_merge(
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            [$startDate, $endDate . ' 23:59:59'], $deptParams,
            $baseParams
        )
    );

    // Monthly trend
    $monthlyTrend = safeAll(
        "SELECT DATE_FORMAT(m.start_datetime, '%Y-%m') as ym,
                COUNT(DISTINCT m.id) as missions,
                COALESCE((SELECT SUM(pr2.actual_hours) FROM participation_requests pr2 
                 JOIN shifts s2 ON pr2.shift_id = s2.id 
                 WHERE pr2.attended = 1 AND s2.mission_id = ANY(
                     SELECT m2.id FROM missions m2 WHERE m2.deleted_at IS NULL 
                     AND DATE_FORMAT(m2.start_datetime, '%Y-%m') = DATE_FORMAT(m.start_datetime, '%Y-%m')
                     AND m2.mission_type_id IN ($typePlaceholders)" . $deptFilter . "
                 )), 0) as hours,
                COALESCE((SELECT COUNT(DISTINCT pr3.volunteer_id) FROM participation_requests pr3 
                 JOIN shifts s3 ON pr3.shift_id = s3.id 
                 WHERE pr3.attended = 1 AND s3.mission_id = ANY(
                     SELECT m3.id FROM missions m3 WHERE m3.deleted_at IS NULL 
                     AND DATE_FORMAT(m3.start_datetime, '%Y-%m') = DATE_FORMAT(m.start_datetime, '%Y-%m')
                     AND m3.mission_type_id IN ($typePlaceholders)" . $deptFilter . "
                 )), 0) as volunteers
         FROM missions m
         WHERE $baseWhere
         GROUP BY ym
         ORDER BY ym ASC",
        array_merge(
            $selectedTypes, $deptParams,
            $selectedTypes, $deptParams,
            $baseParams
        )
    );

    // Top 10 volunteers
    $topVolunteers = safeAll(
        "SELECT u.name,
                SUM(pr.actual_hours) as total_hours,
                COUNT(DISTINCT m.id) as mission_count,
                COALESCE(SUM(vp.points), 0) as total_points
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         JOIN users u ON pr.volunteer_id = u.id
         LEFT JOIN volunteer_points vp ON vp.user_id = u.id AND vp.pointable_type = 'shift' AND vp.pointable_id = s.id
         WHERE pr.attended = 1 AND $baseWhere
         GROUP BY u.id, u.name
         ORDER BY total_hours DESC
         LIMIT 10",
        $baseParams
    );

    // Department breakdown
    $deptBreakdown = safeAll(
        "SELECT d.name as dept_name,
                COUNT(DISTINCT m.id) as missions,
                COALESCE((SELECT SUM(pr2.actual_hours) FROM participation_requests pr2 
                 JOIN shifts s2 ON pr2.shift_id = s2.id 
                 WHERE pr2.attended = 1 AND s2.mission_id IN (
                     SELECT m2.id FROM missions m2 WHERE m2.deleted_at IS NULL 
                     AND m2.start_datetime >= ? AND m2.start_datetime <= ?
                     AND m2.mission_type_id IN ($typePlaceholders)" . $deptFilter . "
                     AND m2.department_id = d.id
                 )), 0) as hours,
                COALESCE((SELECT COUNT(DISTINCT pr3.volunteer_id) FROM participation_requests pr3 
                 JOIN shifts s3 ON pr3.shift_id = s3.id 
                 WHERE pr3.attended = 1 AND s3.mission_id IN (
                     SELECT m3.id FROM missions m3 WHERE m3.deleted_at IS NULL 
                     AND m3.start_datetime >= ? AND m3.start_datetime <= ?
                     AND m3.mission_type_id IN ($typePlaceholders)" . $deptFilter . "
                     AND m3.department_id = d.id
                 )), 0) as volunteers
         FROM departments d
         JOIN missions m ON m.department_id = d.id
         WHERE d.is_active = 1 AND $baseWhere
         GROUP BY d.id, d.name
         ORDER BY missions DESC",
        array_merge(
            [$startDate, $endDate . ' 23:59:59'], $selectedTypes, $deptParams,
            [$startDate, $endDate . ' 23:59:59'], $selectedTypes, $deptParams,
            $baseParams
        )
    );

    // Selected type names for display
    $selectedTypeNames = [];
    foreach ($missionTypes as $mt) {
        if (in_array($mt['id'], $selectedTypes)) {
            $selectedTypeNames[] = $mt['name'];
        }
    }

    $reportData = compact(
        'totalMissions', 'completedMissions', 'canceledMissions', 'completionRate',
        'totalHours', 'activeVolunteers', 'totalShifts',
        'typeBreakdown', 'monthlyTrend', 'topVolunteers', 'deptBreakdown', 'selectedTypeNames'
    );
}

$appName = getSetting('app_name', APP_NAME);

include __DIR__ . '/includes/header.php';
?>

<!-- Filter Form -->
<div class="card shadow-sm mb-4 no-print">
    <div class="card-header bg-primary bg-opacity-10">
        <h4 class="mb-0"><i class="bi bi-file-earmark-pdf me-2"></i>Αναφορά Δήμου</h4>
    </div>
    <div class="card-body">
        <form method="get" id="reportForm">
            <!-- Mission Types -->
            <div class="mb-4">
                <label class="form-label fw-bold">
                    <i class="bi bi-tags me-1"></i>Τύποι Αποστολών
                </label>
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
                                <span class="badge bg-<?= h($mt['color']) ?> me-1">
                                    <i class="bi <?= h($mt['icon']) ?>"></i>
                                </span>
                                <?= h($mt['name']) ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Date Range -->
            <div class="mb-4">
                <label class="form-label fw-bold">
                    <i class="bi bi-calendar-range me-1"></i>Χρονική Περίοδος
                </label>
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php 
                    $periods = [
                        '1m' => 'Μήνας',
                        '3m' => 'Τρίμηνο',
                        '6m' => 'Εξάμηνο',
                        '1y' => 'Έτος',
                        'custom' => 'Προσαρμοσμένο',
                    ];
                    foreach ($periods as $val => $label): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="period" value="<?= $val ?>" 
                               id="period_<?= $val ?>" <?= $period === $val ? 'checked' : '' ?>
                               onchange="toggleCustomDates()">
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

            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-file-earmark-bar-graph me-2"></i>Δημιουργία Αναφοράς
            </button>
        </form>
    </div>
</div>

<?php if ($generate && $reportData): ?>
<!-- ========== PRINTABLE REPORT ========== -->
<div id="reportContent">

    <!-- Print Button -->
    <div class="text-end mb-3 no-print">
        <button class="btn btn-outline-dark" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Εκτύπωση / Αποθήκευση PDF
        </button>
    </div>

    <!-- Report Header -->
    <div class="report-header text-center mb-5">
        <div class="report-logo mb-2">
            <i class="bi bi-shield-check" style="font-size: 3rem; color: #1a3a5c;"></i>
        </div>
        <h1 style="color: #1a3a5c; font-weight: 700; font-size: 1.8rem; margin-bottom: 0.3rem;">
            <?= h($appName) ?>
        </h1>
        <h2 style="color: #2c5f8a; font-weight: 600; font-size: 1.4rem; margin-bottom: 1rem;">
            Αναφορά Δραστηριοτήτων Εθελοντών
        </h2>
        <div class="report-meta" style="color: #555; font-size: 0.95rem;">
            <div><strong>Περίοδος:</strong> <?= formatDate($startDate) ?> — <?= formatDate($endDate) ?></div>
            <div class="mt-1">
                <strong>Τύποι Αποστολών:</strong> <?= h(implode(', ', $reportData['selectedTypeNames'])) ?>
            </div>
        </div>
        <hr style="border-color: #1a3a5c; border-width: 2px; margin-top: 1.5rem;">
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-5">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #1a3a5c !important;">
                <div class="card-body text-center py-4">
                    <div style="font-size: 2.2rem; font-weight: 700; color: #1a3a5c;"><?= number_format($reportData['totalMissions']) ?></div>
                    <div class="text-muted fw-semibold">Σύνολο Αποστολών</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #198754 !important;">
                <div class="card-body text-center py-4">
                    <div style="font-size: 2.2rem; font-weight: 700; color: #198754;"><?= number_format($reportData['completedMissions']) ?></div>
                    <div class="text-muted fw-semibold">Ολοκληρωμένες (<?= $reportData['completionRate'] ?>%)</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #0d6efd !important;">
                <div class="card-body text-center py-4">
                    <div style="font-size: 2.2rem; font-weight: 700; color: #0d6efd;"><?= number_format($reportData['totalHours'], 1) ?></div>
                    <div class="text-muted fw-semibold">Ώρες Εθελοντισμού</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #6f42c1 !important;">
                <div class="card-body text-center py-4">
                    <div style="font-size: 2.2rem; font-weight: 700; color: #6f42c1;"><?= number_format($reportData['activeVolunteers']) ?></div>
                    <div class="text-muted fw-semibold">Ενεργοί Εθελοντές</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mission Type Breakdown -->
    <?php if (!empty($reportData['typeBreakdown'])): ?>
    <div class="card shadow-sm mb-5">
        <div class="card-header" style="background: #1a3a5c; color: #fff;">
            <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Ανάλυση ανά Τύπο Αποστολής</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Τύπος</th>
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
                            <?= h($tb['name']) ?>
                        </td>
                        <td class="text-center fw-bold"><?= (int)$tb['mission_count'] ?></td>
                        <td class="text-center text-success"><?= (int)$tb['completed'] ?></td>
                        <td class="text-center text-danger"><?= (int)$tb['canceled'] ?></td>
                        <td class="text-center"><?= (int)$tb['shift_count'] ?></td>
                        <td class="text-center"><?= number_format((float)$tb['hours'], 1) ?></td>
                        <td class="text-center"><?= (int)$tb['volunteers'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td>Σύνολο</td>
                        <td class="text-center"><?= number_format($reportData['totalMissions']) ?></td>
                        <td class="text-center text-success"><?= number_format($reportData['completedMissions']) ?></td>
                        <td class="text-center text-danger"><?= number_format($reportData['canceledMissions']) ?></td>
                        <td class="text-center"><?= number_format($reportData['totalShifts']) ?></td>
                        <td class="text-center"><?= number_format($reportData['totalHours'], 1) ?></td>
                        <td class="text-center"><?= number_format($reportData['activeVolunteers']) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Monthly Trend -->
    <?php if (!empty($reportData['monthlyTrend'])): ?>
    <div class="card shadow-sm mb-5">
        <div class="card-header" style="background: #1a3a5c; color: #fff;">
            <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Μηνιαία Εξέλιξη</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Μήνας</th>
                        <th class="text-center">Αποστολές</th>
                        <th class="text-center">Ώρες</th>
                        <th class="text-center">Εθελοντές</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['monthlyTrend'] as $mt): 
                        $ym = explode('-', $mt['ym']);
                        $monthLabel = ($greekMonths[(int)$ym[1]] ?? $mt['ym']) . ' ' . $ym[0];
                    ?>
                    <tr>
                        <td><?= h($monthLabel) ?></td>
                        <td class="text-center"><?= (int)$mt['missions'] ?></td>
                        <td class="text-center"><?= number_format((float)$mt['hours'], 1) ?></td>
                        <td class="text-center"><?= (int)$mt['volunteers'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Top 10 Volunteers -->
    <?php if (!empty($reportData['topVolunteers'])): ?>
    <div class="card shadow-sm mb-5">
        <div class="card-header" style="background: #1a3a5c; color: #fff;">
            <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Κορυφαίοι 10 Εθελοντές</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="text-center" width="50">#</th>
                        <th>Εθελοντής</th>
                        <th class="text-center">Ώρες</th>
                        <th class="text-center">Αποστολές</th>
                        <th class="text-center">Πόντοι</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['topVolunteers'] as $rank => $v): ?>
                    <tr>
                        <td class="text-center">
                            <?php if ($rank < 3): ?>
                                <span style="font-size: 1.3rem;">
                                    <?= ['🥇','🥈','🥉'][$rank] ?>
                                </span>
                            <?php else: ?>
                                <?= $rank + 1 ?>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= h($v['name']) ?></td>
                        <td class="text-center"><?= number_format((float)$v['total_hours'], 1) ?></td>
                        <td class="text-center"><?= (int)$v['mission_count'] ?></td>
                        <td class="text-center"><?= number_format((int)$v['total_points']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Department Breakdown -->
    <?php if (!empty($reportData['deptBreakdown']) && count($reportData['deptBreakdown']) > 1): ?>
    <div class="card shadow-sm mb-5">
        <div class="card-header" style="background: #1a3a5c; color: #fff;">
            <h5 class="mb-0"><i class="bi bi-building me-2"></i>Ανάλυση ανά Τμήμα</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Τμήμα</th>
                        <th class="text-center">Αποστολές</th>
                        <th class="text-center">Ώρες</th>
                        <th class="text-center">Εθελοντές</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reportData['deptBreakdown'] as $d): ?>
                    <tr>
                        <td><?= h($d['dept_name']) ?></td>
                        <td class="text-center"><?= (int)$d['missions'] ?></td>
                        <td class="text-center"><?= number_format((float)$d['hours'], 1) ?></td>
                        <td class="text-center"><?= (int)$d['volunteers'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Report Footer -->
    <div class="text-center mt-5 mb-4 report-footer" style="color: #888; font-size: 0.85rem;">
        <hr style="border-color: #1a3a5c; border-width: 2px;">
        <p class="mb-1">Παράχθηκε από <strong><?= h($appName) ?></strong> v<?= h(APP_VERSION) ?></p>
        <p class="mb-0">Ημερομηνία δημιουργίας: <?= formatDateGreek(date('Y-m-d')) ?>, <?= date('H:i') ?></p>
    </div>

</div>

<?php elseif (!empty($period) && empty($selectedTypes)): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>Παρακαλώ επιλέξτε τουλάχιστον έναν τύπο αποστολής.
    </div>
<?php endif; ?>

<!-- Print Styles -->
<style>
@media print {
    /* Hide non-printable elements */
    .no-print, .sidebar, .navbar, #sidebar, .offcanvas, 
    nav, .btn-close, .breadcrumb, footer,
    .sidebar-section, .nav-item { display: none !important; }
    
    /* Full width */
    .main-content, .container-fluid, .col-lg-10, [class*="col-"] {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    body { 
        background: #fff !important; 
        font-size: 11pt;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .card { 
        border: 1px solid #ddd !important; 
        box-shadow: none !important;
        break-inside: avoid;
    }
    .card-header {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .report-header { margin-top: 0; }
    .badge {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    table { font-size: 10pt; }
    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    /* Page margins */
    @page { margin: 1.5cm; }
}

/* Screen styles for report */
#reportContent {
    max-width: 900px;
    margin: 0 auto;
}
.report-header h1 { letter-spacing: 1px; }
.report-header h2 { letter-spacing: 0.5px; }
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
