<?php
/**
 * VolunteerOps - Volunteer Activity Report (Printable / Save as PDF)
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    redirect('dashboard.php');
}

// Access control: volunteers can only see their own report
if (!isAdmin() && !hasRole(ROLE_SHIFT_LEADER) && getCurrentUserId() !== $id) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή την αναφορά.');
    redirect('dashboard.php');
}

// ── Volunteer info ────────────────────────────────────────────────────────────
$volunteer = dbFetchOne(
    "SELECT u.*, d.name AS department_name
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE u.id = ?",
    [$id]
);
if (!$volunteer) {
    setFlash('error', 'Ο εθελοντής δεν βρέθηκε.');
    redirect('volunteers.php');
}

// ── Attended shifts (full detail) ────────────────────────────────────────────
$attendedShifts = dbFetchAll(
    "SELECT pr.actual_hours, pr.actual_start_time, pr.actual_end_time,
            s.start_time, s.end_time,
            m.title AS mission_title, m.location
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.volunteer_id = ?
       AND pr.attended = 1
     ORDER BY s.start_time DESC",
    [$id]
);

// ── Monthly breakdown ─────────────────────────────────────────────────────────
$monthlyBreakdown = dbFetchAll(
    "SELECT YEAR(s.start_time) AS yr, MONTH(s.start_time) AS mo,
            COUNT(*) AS shifts_count,
            COALESCE(SUM(pr.actual_hours), 0) AS total_hours
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     WHERE pr.volunteer_id = ?
       AND pr.attended = 1
     GROUP BY YEAR(s.start_time), MONTH(s.start_time)
     ORDER BY yr DESC, mo DESC",
    [$id]
);

// ── Achievements ──────────────────────────────────────────────────────────────
$achievements = dbFetchAll(
    "SELECT a.name, a.description, a.icon, a.category, ua.earned_at
     FROM achievements a
     JOIN user_achievements ua ON a.id = ua.achievement_id
     WHERE ua.user_id = ?
     ORDER BY ua.earned_at ASC",
    [$id]
);

// ── Totals ────────────────────────────────────────────────────────────────────
$totalHours  = array_sum(array_column($attendedShifts, 'actual_hours'));
$totalShifts = count($attendedShifts);
$totalPoints = (int) $volunteer['total_points'];
$totalAchievements = count($achievements);

// ── Organization logo ─────────────────────────────────────────────────────────
$logoFile = getSetting('app_logo', '');
$logoUrl  = '';
if (!empty($logoFile)) {
    $logoUrl = rtrim(BASE_URL, '/') . '/uploads/logos/' . $logoFile;
}

$appName   = getSetting('app_name', APP_NAME);
$printDate = date('d/m/Y H:i');

$greekMonths = [
    1  => 'Ιανουάριος', 2  => 'Φεβρουάριος', 3  => 'Μάρτιος',
    4  => 'Απρίλιος',   5  => 'Μάιος',        6  => 'Ιούνιος',
    7  => 'Ιούλιος',    8  => 'Αύγουστος',    9  => 'Σεπτέμβριος',
    10 => 'Οκτώβριος',  11 => 'Νοέμβριος',   12 => 'Δεκέμβριος',
];
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Αναφορά Εθελοντή — <?= h($volunteer['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
        }
        .report-wrapper {
            max-width: 900px;
            margin: 32px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        /* ── Header band ── */
        .report-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: #fff;
            padding: 36px 48px 30px;
        }
        .report-header h1 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
        .report-header .subtitle { opacity: .75; font-size: 13px; }
        .report-logo { max-height: 52px; max-width: 160px; object-fit: contain; margin-bottom: 14px; }
        .org-name { font-size: 11px; letter-spacing: 2px; text-transform: uppercase; opacity: .65; margin-bottom: 6px; }

        /* ── Stat cards ── */
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-card .num { font-size: 32px; font-weight: 700; line-height: 1; }
        .stat-card .lbl { font-size: 12px; opacity: .75; margin-top: 4px; }

        /* ── Section titles ── */
        .section-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 6px;
            margin: 28px 0 16px;
        }

        /* ── Table ── */
        .report-table th { background: #f9fafb; font-size: 13px; color: #374151; }
        .report-table td { font-size: 13px; vertical-align: middle; }

        /* ── Achievement badge ── */
        .ach-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fef9c3;
            border: 1px solid #fde68a;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 13px;
            margin: 3px;
        }
        .ach-badge .icon { font-size: 16px; }

        /* ── Toolbar ── */
        .toolbar {
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 48px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        /* ── Print styles ── */
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .report-wrapper {
                max-width: 100%;
                margin: 0;
                box-shadow: none;
                border-radius: 0;
            }
            .report-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .stat-card     { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-break    { page-break-before: always; }
            a { text-decoration: none !important; color: inherit !important; }
        }
    </style>
</head>
<body>

<!-- Print toolbar (hidden on print) -->
<div class="toolbar no-print">
    <button class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Εκτύπωση / Αποθήκευση ως PDF
    </button>
    <?php if (isAdmin() || hasRole(ROLE_SHIFT_LEADER)): ?>
        <a href="volunteer-view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Πίσω στο Προφίλ
        </a>
    <?php else: ?>
        <a href="my-participations.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    <?php endif; ?>
    <span class="text-muted small ms-2">
        <i class="bi bi-info-circle me-1"></i>
        Στο παράθυρο εκτύπωσης επιλέξτε «Αποθήκευση ως PDF» για να αποθηκεύσετε.
    </span>
</div>

<div class="report-wrapper">

    <!-- ── Header ── -->
    <div class="report-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <?php if ($logoUrl): ?>
                    <img src="<?= h($logoUrl) ?>" alt="<?= h($appName) ?>" class="report-logo d-block">
                <?php endif; ?>
                <div class="org-name"><?= h($appName) ?></div>
                <h1><?= h($volunteer['name']) ?></h1>
                <div class="subtitle">
                    <?php if ($volunteer['department_name']): ?>
                        <i class="bi bi-building me-1"></i><?= h($volunteer['department_name']) ?> &nbsp;&bull;&nbsp;
                    <?php endif; ?>
                    <i class="bi bi-envelope me-1"></i><?= h($volunteer['email']) ?>
                    <?php if ($volunteer['phone']): ?>
                        &nbsp;&bull;&nbsp;<i class="bi bi-telephone me-1"></i><?= h($volunteer['phone']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="text-end" style="opacity:.7; font-size:12px; min-width:130px;">
                <div><strong>Αναφορά Δραστηριότητας</strong></div>
                <div>Εκτυπώθηκε: <?= $printDate ?></div>
            </div>
        </div>
    </div>

    <div class="px-4 px-md-5 pb-5">

        <!-- ── Summary stats ── -->
        <div class="row g-3 mt-2">
            <div class="col-6 col-md-3">
                <div class="stat-card" style="background:#eff6ff;">
                    <div class="num" style="color:#1d4ed8;"><?= $totalShifts ?></div>
                    <div class="lbl">Βάρδιες</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="background:#f0fdf4;">
                    <div class="num" style="color:#15803d;"><?= number_format($totalHours, 1) ?></div>
                    <div class="lbl">Ώρες Προσφοράς</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="background:#fefce8;">
                    <div class="num" style="color:#b45309;"><?= number_format($totalPoints) ?></div>
                    <div class="lbl">Πόντοι</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card" style="background:#fdf4ff;">
                    <div class="num" style="color:#7e22ce;"><?= $totalAchievements ?></div>
                    <div class="lbl">Επιτεύγματα</div>
                </div>
            </div>
        </div>

        <?php if (!empty($monthlyBreakdown)): ?>
        <!-- ── Monthly breakdown ── -->
        <div class="section-title"><i class="bi bi-bar-chart me-1"></i>Ανάλυση ανά Μήνα</div>
        <table class="table table-sm table-hover report-table">
            <thead>
                <tr>
                    <th>Μήνας</th>
                    <th class="text-center">Βάρδιες</th>
                    <th class="text-center">Ώρες</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyBreakdown as $row): ?>
                <tr>
                    <td><?= h($greekMonths[(int)$row['mo']] ?? $row['mo']) ?> <?= $row['yr'] ?></td>
                    <td class="text-center"><?= $row['shifts_count'] ?></td>
                    <td class="text-center"><?= number_format($row['total_hours'], 1) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td>Σύνολο</td>
                    <td class="text-center"><?= $totalShifts ?></td>
                    <td class="text-center"><?= number_format($totalHours, 1) ?></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>

        <?php if (!empty($attendedShifts)): ?>
        <!-- ── Detailed shift history ── -->
        <div class="section-title page-break"><i class="bi bi-list-check me-1"></i>Αναλυτικό Ιστορικό Βαρδιών</div>
        <table class="table table-sm table-hover report-table">
            <thead>
                <tr>
                    <th>Αποστολή</th>
                    <th>Ημερομηνία</th>
                    <th>Ώρες Βάρδιας</th>
                    <th class="text-center">Πραγμ. Ώρες</th>
                    <th>Τοποθεσία</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendedShifts as $s): ?>
                <tr>
                    <td><strong><?= h($s['mission_title']) ?></strong></td>
                    <td><?= formatDate($s['start_time']) ?></td>
                    <td style="white-space:nowrap;">
                        <?= date('H:i', strtotime($s['start_time'])) ?>
                        &ndash;
                        <?= date('H:i', strtotime($s['end_time'])) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($s['actual_hours']): ?>
                            <strong><?= number_format((float)$s['actual_hours'], 1) ?>h</strong>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:12px;"><?= h($s['location'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-muted text-center py-4">
            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
            Δεν υπάρχουν καταγεγραμμένες παρουσίες ακόμα.
        </div>
        <?php endif; ?>

        <?php if (!empty($achievements)): ?>
        <!-- ── Achievements ── -->
        <div class="section-title"><i class="bi bi-trophy me-1"></i>Επιτεύγματα</div>
        <div class="mb-3">
            <?php foreach ($achievements as $a): ?>
                <span class="ach-badge" title="<?= h($a['description']) ?> — <?= formatDate($a['earned_at']) ?>">
                    <span class="icon"><?= h($a['icon']) ?></span>
                    <span><?= h($a['name']) ?></span>
                    <span class="text-muted" style="font-size:11px;"><?= formatDate($a['earned_at']) ?></span>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Footer ── -->
        <div class="mt-5 pt-3 border-top text-muted" style="font-size:11px;">
            <div class="d-flex justify-content-between">
                <span><?= h($appName) ?> &bull; Σύστημα Διαχείρισης Εθελοντών</span>
                <span>Αυτόματη αναφορά &bull; <?= $printDate ?></span>
            </div>
        </div>

    </div><!-- /px-4 -->
</div><!-- /report-wrapper -->

</body>
</html>
