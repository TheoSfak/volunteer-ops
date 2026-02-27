<?php
/**
 * VolunteerOps - Certificate Overview Dashboard
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$pageTitle = 'Πιστοποιητικά Εθελοντών';

// ─── Filters ───────────────────────────────────────────────────────────────────
$filterStatus = get('status', '');
$filterType = (int) get('type', 0);
$filterSearch = trim(get('q', ''));
$tab = get('tab', 'all'); // 'all' or 'missing'

// ─── Export CSV ────────────────────────────────────────────────────────────────
if (get('export') === 'csv') {
    $csvRows = dbFetchAll("
        SELECT u.name as volunteer_name, u.email, ct.name as certificate_type,
               vc.issue_date, vc.expiry_date, vc.issuing_body, vc.certificate_number,
               CASE
                   WHEN vc.expiry_date IS NULL THEN 'Αόριστη'
                   WHEN vc.expiry_date < CURDATE() THEN 'Ληγμένο'
                   WHEN vc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Λήγει σύντομα'
                   ELSE 'Ενεργό'
               END as status
        FROM volunteer_certificates vc
        JOIN users u ON vc.user_id = u.id
        JOIN certificate_types ct ON vc.certificate_type_id = ct.id
        WHERE u.is_active = 1
        ORDER BY u.name, ct.name
    ");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="certificates_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8
    fputcsv($out, ['Εθελοντής', 'Email', 'Τύπος', 'Ημ. Έκδοσης', 'Ημ. Λήξης', 'Φορέας', 'Αρ. Πιστοποιητικού', 'Κατάσταση']);
    foreach ($csvRows as $row) {
        fputcsv($out, [
            $row['volunteer_name'], $row['email'], $row['certificate_type'],
            $row['issue_date'], $row['expiry_date'] ?? '', $row['issuing_body'] ?? '',
            $row['certificate_number'] ?? '', $row['status']
        ]);
    }
    fclose($out);
    exit;
}

// ─── Stats ─────────────────────────────────────────────────────────────────────
$statsTotal = dbFetchValue("SELECT COUNT(*) FROM volunteer_certificates vc JOIN users u ON vc.user_id = u.id WHERE u.is_active = 1");
$statsActive = dbFetchValue("SELECT COUNT(*) FROM volunteer_certificates vc JOIN users u ON vc.user_id = u.id WHERE u.is_active = 1 AND (vc.expiry_date IS NULL OR vc.expiry_date > CURDATE())");
$statsExpiring = dbFetchValue("SELECT COUNT(*) FROM volunteer_certificates vc JOIN users u ON vc.user_id = u.id WHERE u.is_active = 1 AND vc.expiry_date IS NOT NULL AND vc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$statsExpired = dbFetchValue("SELECT COUNT(*) FROM volunteer_certificates vc JOIN users u ON vc.user_id = u.id WHERE u.is_active = 1 AND vc.expiry_date IS NOT NULL AND vc.expiry_date < CURDATE()");

// ─── Certificate Types for filter ──────────────────────────────────────────────
$certTypes = dbFetchAll("SELECT * FROM certificate_types WHERE is_active = 1 ORDER BY name");

// ─── Build query ───────────────────────────────────────────────────────────────
if ($tab === 'missing') {
    // Missing required certificates
    $requiredTypes = dbFetchAll("SELECT * FROM certificate_types WHERE is_required = 1 AND is_active = 1 ORDER BY name");
    $missingData = [];
    if (!empty($requiredTypes)) {
        $rtIds = array_column($requiredTypes, 'id');
        $placeholders = implode(',', array_fill(0, count($rtIds), '?'));
        $searchWhere = '';
        $searchParams = [];
        if ($filterSearch) {
            $searchWhere = " AND (u.name LIKE ? OR u.email LIKE ?)";
            $searchParams = ["%{$filterSearch}%", "%{$filterSearch}%"];
        }
        $allMissing = dbFetchAll(
            "SELECT ct.id as type_id, u.id, u.name, u.email, d.name as department_name
             FROM certificate_types ct
             CROSS JOIN users u
             LEFT JOIN departments d ON u.department_id = d.id
             LEFT JOIN volunteer_certificates vc ON vc.user_id = u.id AND vc.certificate_type_id = ct.id
             WHERE ct.id IN ($placeholders) AND u.is_active = 1 AND vc.id IS NULL $searchWhere
             ORDER BY ct.name, u.name",
            array_merge($rtIds, $searchParams)
        );
        $rtMap = array_column($requiredTypes, null, 'id');
        $grouped = [];
        foreach ($allMissing as $row) {
            $grouped[$row['type_id']][] = $row;
        }
        foreach ($grouped as $typeId => $volunteers) {
            $missingData[] = ['type' => $rtMap[$typeId], 'volunteers' => $volunteers];
        }
    }
} else {
    // All certificates
    $where = "u.is_active = 1";
    $params = [];

    if ($filterStatus === 'active') {
        $where .= " AND (vc.expiry_date IS NULL OR vc.expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY))";
    } elseif ($filterStatus === 'expiring') {
        $where .= " AND vc.expiry_date IS NOT NULL AND vc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
    } elseif ($filterStatus === 'expired') {
        $where .= " AND vc.expiry_date IS NOT NULL AND vc.expiry_date < CURDATE()";
    }

    if ($filterType) {
        $where .= " AND vc.certificate_type_id = ?";
        $params[] = $filterType;
    }

    if ($filterSearch) {
        $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR ct.name LIKE ?)";
        $params[] = "%{$filterSearch}%";
        $params[] = "%{$filterSearch}%";
        $params[] = "%{$filterSearch}%";
    }

    // Pagination
    $totalRows = dbFetchValue(
        "SELECT COUNT(*) FROM volunteer_certificates vc
         JOIN users u ON vc.user_id = u.id
         JOIN certificate_types ct ON vc.certificate_type_id = ct.id
         WHERE {$where}",
        $params
    );
    $pagination = paginate($totalRows, (int) get('page', 1), 25);

    $certificates = dbFetchAll(
        "SELECT vc.*, u.name as volunteer_name, u.email as volunteer_email,
                ct.name as type_name, ct.is_required,
                d.name as department_name
         FROM volunteer_certificates vc
         JOIN users u ON vc.user_id = u.id
         JOIN certificate_types ct ON vc.certificate_type_id = ct.id
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE {$where}
         ORDER BY 
            CASE 
                WHEN vc.expiry_date IS NOT NULL AND vc.expiry_date < CURDATE() THEN 0
                WHEN vc.expiry_date IS NOT NULL AND vc.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1
                ELSE 2
            END,
            vc.expiry_date ASC
         LIMIT ? OFFSET ?",
        array_merge($params, [$pagination['per_page'], $pagination['offset']])
    );
}

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-award me-2"></i>Πιστοποιητικά Εθελοντών</h1>
        <p class="text-muted mb-0">Επισκόπηση & παρακολούθηση πιστοποιητικών</p>
    </div>
    <div class="d-flex gap-2">
        <a href="certificates.php?export=csv" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Εξαγωγή CSV
        </a>
        <?php if (isSystemAdmin()): ?>
        <a href="certificate-types.php" class="btn btn-outline-primary">
            <i class="bi bi-gear me-1"></i>Τύποι
        </a>
        <?php endif; ?>
    </div>
</div>

<?= showFlash() ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $statsTotal ?></h3>
                <small class="text-muted">Σύνολο</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $statsActive ?></h3>
                <small class="text-muted">Ενεργά</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $statsExpiring ?></h3>
                <small class="text-muted">Λήγουν σε 30 ημ.</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card danger">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $statsExpired ?></h3>
                <small class="text-muted">Ληγμένα</small>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="certificates.php?tab=all">
            <i class="bi bi-list-ul me-1"></i>Όλα τα Πιστοποιητικά
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'missing' ? 'active' : '' ?>" href="certificates.php?tab=missing">
            <i class="bi bi-exclamation-triangle me-1"></i>Ελλείποντα Υποχρεωτικά
            <?php
            $missingCount = (int)dbFetchValue(
                "SELECT COUNT(*) FROM certificate_types ct
                 CROSS JOIN users u
                 LEFT JOIN volunteer_certificates vc ON vc.user_id = u.id AND vc.certificate_type_id = ct.id
                 WHERE ct.is_required = 1 AND ct.is_active = 1 AND u.is_active = 1 AND vc.id IS NULL"
            );
            if ($missingCount > 0):
            ?>
                <span class="badge bg-danger"><?= $missingCount ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'missing'): ?>
<!-- Missing Required Certificates -->
<?php if (empty($requiredTypes)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>Δεν υπάρχουν υποχρεωτικοί τύποι πιστοποιητικών.
        <?php if (isSystemAdmin()): ?>
            <a href="certificate-types.php">Ρυθμίστε τύπους πιστοποιητικών</a>.
        <?php endif; ?>
    </div>
<?php elseif (empty($missingData)): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i>Όλοι οι ενεργοί εθελοντές έχουν τα υποχρεωτικά πιστοποιητικά!
    </div>
<?php else: ?>
    <!-- Search -->
    <form method="get" class="mb-3">
        <input type="hidden" name="tab" value="missing">
        <div class="input-group" style="max-width: 400px;">
            <input type="text" name="q" class="form-control" placeholder="Αναζήτηση εθελοντή..." value="<?= h($filterSearch) ?>">
            <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
        </div>
    </form>

    <?php foreach ($missingData as $md): ?>
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-circle text-danger"></i>
            <strong><?= h($md['type']['name']) ?></strong>
            <span class="badge bg-danger"><?= count($md['volunteers']) ?> ελλείποντα</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Εθελοντής</th>
                        <th>Email</th>
                        <th>Σώμα</th>
                        <th class="text-end pe-3">Ενέργεια</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($md['volunteers'] as $v): ?>
                    <tr>
                        <td>
                            <a href="volunteer-view.php?id=<?= $v['id'] ?>#certificates"><?= h($v['name']) ?></a>
                        </td>
                        <td class="text-muted small"><?= h($v['email']) ?></td>
                        <td class="text-muted small"><?= h($v['department_name'] ?? '—') ?></td>
                        <td class="text-end pe-3">
                            <a href="volunteer-view.php?id=<?= $v['id'] ?>#certificates" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-plus-circle me-1"></i>Προσθήκη
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php else: ?>
<!-- All Certificates Tab -->

<!-- Filters -->
<form method="get" class="card mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="all">
            <div class="col-md-3">
                <label class="form-label small">Κατάσταση</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Όλες</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Ενεργά</option>
                    <option value="expiring" <?= $filterStatus === 'expiring' ? 'selected' : '' ?>>Λήγουν σύντομα</option>
                    <option value="expired" <?= $filterStatus === 'expired' ? 'selected' : '' ?>>Ληγμένα</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Τύπος</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Όλοι</option>
                    <?php foreach ($certTypes as $ct): ?>
                        <option value="<?= $ct['id'] ?>" <?= $filterType == $ct['id'] ? 'selected' : '' ?>>
                            <?= h($ct['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Αναζήτηση</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Εθελοντής, email ή τύπος..."
                       value="<?= h($filterSearch) ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Φίλτρα</button>
            </div>
        </div>
    </div>
</form>

<!-- Results Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Εθελοντής</th>
                    <th>Τύπος</th>
                    <th>Ημ. Έκδοσης</th>
                    <th>Ημ. Λήξης</th>
                    <th>Κατάσταση</th>
                    <th>Φορέας</th>
                    <th class="text-end pe-3">Ενέργεια</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($certificates)): ?>
                    <tr><td colspan="7" class="text-muted text-center py-4">Δεν βρέθηκαν πιστοποιητικά.</td></tr>
                <?php else: ?>
                    <?php foreach ($certificates as $cert):
                        $certBadge = '<span class="badge bg-secondary">Αόριστη</span>';
                        $rowClass = '';
                        if ($cert['expiry_date']) {
                            $daysLeft = (int) ((strtotime($cert['expiry_date']) - time()) / 86400);
                            if ($daysLeft < 0) {
                                $certBadge = '<span class="badge bg-danger">Ληγμένο (' . abs($daysLeft) . ' ημ.)</span>';
                                $rowClass = 'table-danger';
                            } elseif ($daysLeft <= 30) {
                                $certBadge = '<span class="badge bg-warning text-dark">Λήγει σε ' . $daysLeft . ' ημ.</span>';
                                $rowClass = 'table-warning';
                            } else {
                                $certBadge = '<span class="badge bg-success">Ενεργό</span>';
                            }
                        }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td>
                            <a href="volunteer-view.php?id=<?= $cert['user_id'] ?>"><?= h($cert['volunteer_name']) ?></a>
                            <br><small class="text-muted"><?= h($cert['department_name'] ?? '') ?></small>
                        </td>
                        <td class="fw-semibold">
                            <?php if ($cert['is_required']): ?>
                                <i class="bi bi-exclamation-circle text-danger me-1" title="Υποχρεωτικό"></i>
                            <?php endif; ?>
                            <?= h($cert['type_name']) ?>
                        </td>
                        <td><?= formatDate($cert['issue_date']) ?></td>
                        <td><?= $cert['expiry_date'] ? formatDate($cert['expiry_date']) : '—' ?></td>
                        <td><?= $certBadge ?></td>
                        <td class="text-muted small"><?= h($cert['issuing_body'] ?? '—') ?></td>
                        <td class="text-end pe-3">
                            <a href="volunteer-view.php?id=<?= $cert['user_id'] ?>#certificates" class="btn btn-sm btn-outline-primary me-1" title="Προβολή">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($cert['expiry_date']):
                                $gcDate = date('Ymd', strtotime($cert['expiry_date']));
                                $gcDateEnd = date('Ymd', strtotime($cert['expiry_date'] . ' +1 day'));
                                $gcTitle = urlencode('Λήξη Πιστοποιητικού: ' . $cert['type_name'] . ' – ' . $cert['volunteer_name']);
                                $gcDetails = urlencode('Εθελοντής: ' . $cert['volunteer_name'] . '\nΤύπος Πιστοποιητικού: ' . $cert['type_name']);
                                $gcUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$gcTitle}&dates={$gcDate}/{$gcDateEnd}&details={$gcDetails}";
                            ?>
                            <a href="<?= $gcUrl ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Προσθήκη στο Google Calendar">
                                <i class="bi bi-calendar-plus"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (isset($pagination) && $pagination['total_pages'] > 1): ?>
<div class="mt-3">
    <?= paginationLinks($pagination) ?>
</div>
<?php endif; ?>

<?php endif; // tab ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
