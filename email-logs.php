<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Email Logs & Αναφορές';
$currentPage = 'email-logs';

// ─── Auto-create table if it doesn't exist (first visit after deploy) ───────
db()->exec("CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `notification_code` VARCHAR(100) NULL,
    `status` ENUM('SUCCESS','FAILED') NOT NULL DEFAULT 'FAILED',
    `error_message` TEXT NULL,
    `smtp_log` TEXT NULL,
    `smtp_host` VARCHAR(255) NULL,
    `from_email` VARCHAR(255) NULL,
    `sent_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_logs_recipient` (`recipient_email`),
    INDEX `idx_email_logs_status` (`status`),
    INDEX `idx_email_logs_created` (`created_at`),
    INDEX `idx_email_logs_notification` (`notification_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─── Filters ────────────────────────────────────────────────────────────────
$filterStatus = get('status', '');
$filterEmail  = get('email', '');
$filterCode   = get('code', '');
$filterFrom   = get('from', date('Y-m-d', strtotime('-30 days')));
$filterTo     = get('to', date('Y-m-d'));
$page         = max(1, (int)get('page', 1));
$perPage      = 25;

// ─── Handle POST: purge old logs ────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'purge') {
        $days = (int) post('days', 90);
        $deleted = dbExecute(
            "DELETE FROM email_logs WHERE created_at < NOW() - INTERVAL ? DAY",
            [$days]
        );
        logAudit('purge', 'email_logs', 0, "Διαγράφηκαν $deleted εγγραφές παλαιότερες από $days ημέρες");
        setFlash('success', "Διαγράφηκαν $deleted εγγραφές παλαιότερες από $days ημέρες.");
        redirect('email-logs.php');
    }
    
    if ($action === 'resend') {
        $logId = (int) post('log_id');
        $logEntry = dbFetchOne("SELECT * FROM email_logs WHERE id = ?", [$logId]);
        if ($logEntry && $logEntry['status'] === 'FAILED') {
            // We can only resend if we know the notification code
            if ($logEntry['notification_code']) {
                $result = sendNotificationEmail($logEntry['notification_code'], $logEntry['recipient_email']);
                if ($result['success']) {
                    setFlash('success', 'Το email στάλθηκε ξανά επιτυχώς στο ' . h($logEntry['recipient_email']));
                } else {
                    setFlash('error', 'Αποτυχία επαναποστολής: ' . h($result['message']));
                }
            } else {
                setFlash('warning', 'Δεν είναι δυνατή η επαναποστολή χωρίς κωδικό ειδοποίησης.');
            }
        }
        redirect('email-logs.php?' . http_build_query(array_filter([
            'status' => $filterStatus, 'email' => $filterEmail, 'code' => $filterCode,
            'from' => $filterFrom, 'to' => $filterTo, 'page' => $page
        ])));
    }
}

// ─── Stats Cards ────────────────────────────────────────────────────────────
$statsTotal   = (int)dbFetchValue("SELECT COUNT(*) FROM email_logs WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY", [$filterFrom, $filterTo]);
$statsSuccess = (int)dbFetchValue("SELECT COUNT(*) FROM email_logs WHERE status = 'SUCCESS' AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY", [$filterFrom, $filterTo]);
$statsFailed  = (int)dbFetchValue("SELECT COUNT(*) FROM email_logs WHERE status = 'FAILED' AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY", [$filterFrom, $filterTo]);
$statsRate    = $statsTotal > 0 ? round(($statsSuccess / $statsTotal) * 100, 1) : 0;

// Top failure reasons
$topErrors = dbFetchAll(
    "SELECT error_message, COUNT(*) as cnt 
     FROM email_logs 
     WHERE status = 'FAILED' AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY
     GROUP BY error_message ORDER BY cnt DESC LIMIT 5",
    [$filterFrom, $filterTo]
);

// Per-domain breakdown
$domainStats = dbFetchAll(
    "SELECT 
        SUBSTRING_INDEX(recipient_email, '@', -1) as domain,
        COUNT(*) as total,
        SUM(status = 'SUCCESS') as success_cnt,
        SUM(status = 'FAILED') as failed_cnt
     FROM email_logs 
     WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY
     GROUP BY domain ORDER BY total DESC LIMIT 10",
    [$filterFrom, $filterTo]
);

// Daily chart data (last 30 days)
$dailyStats = dbFetchAll(
    "SELECT DATE(created_at) as day, 
            SUM(status = 'SUCCESS') as success_cnt,
            SUM(status = 'FAILED') as failed_cnt
     FROM email_logs 
     WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY
     GROUP BY day ORDER BY day",
    [$filterFrom, $filterTo]
);

// Notification code breakdown
$codeStats = dbFetchAll(
    "SELECT COALESCE(notification_code, 'manual/test') as code,
            COUNT(*) as total,
            SUM(status = 'SUCCESS') as success_cnt,
            SUM(status = 'FAILED') as failed_cnt
     FROM email_logs 
     WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY
     GROUP BY notification_code ORDER BY total DESC",
    [$filterFrom, $filterTo]
);

// Available notification codes for filter
$availableCodes = dbFetchAll(
    "SELECT DISTINCT COALESCE(notification_code, 'manual/test') as code FROM email_logs ORDER BY code"
);

// ─── Build filtered query ───────────────────────────────────────────────────
$where = ['el.created_at >= ?', 'el.created_at < ? + INTERVAL 1 DAY'];
$params = [$filterFrom, $filterTo];

if ($filterStatus) {
    $where[] = 'el.status = ?';
    $params[] = $filterStatus;
}
if ($filterEmail) {
    $where[] = 'el.recipient_email LIKE ?';
    $params[] = "%$filterEmail%";
}
if ($filterCode) {
    if ($filterCode === 'manual/test') {
        $where[] = 'el.notification_code IS NULL';
    } else {
        $where[] = 'el.notification_code = ?';
        $params[] = $filterCode;
    }
}

$whereClause = implode(' AND ', $where);

$total = (int)dbFetchValue("SELECT COUNT(*) FROM email_logs el WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

$logs = dbFetchAll(
    "SELECT el.*, u.name as sent_by_name
     FROM email_logs el
     LEFT JOIN users u ON el.sent_by = u.id
     WHERE $whereClause
     ORDER BY el.created_at DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-envelope-check me-2"></i>Email Logs & Αναφορές</h2>
    <div>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#purgeModal">
            <i class="bi bi-trash me-1"></i>Εκκαθάριση
        </button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Σύνολο</div>
                <div class="fs-3 fw-bold text-primary"><?= number_format($statsTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Επιτυχημένα</div>
                <div class="fs-3 fw-bold text-success"><?= number_format($statsSuccess) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Αποτυχημένα</div>
                <div class="fs-3 fw-bold text-danger"><?= number_format($statsFailed) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Ποσοστό Επιτυχίας</div>
                <div class="fs-3 fw-bold <?= $statsRate >= 95 ? 'text-success' : ($statsRate >= 80 ? 'text-warning' : 'text-danger') ?>">
                    <?= $statsRate ?>%
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Analytics Row -->
<div class="row g-3 mb-4">
    <!-- Daily Chart -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-graph-up me-1"></i>Ημερήσια Αποστολή</div>
            <div class="card-body">
                <canvas id="dailyChart" height="200"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Domain Breakdown -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-globe me-1"></i>Ανά Domain</div>
            <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                <?php if (empty($domainStats)): ?>
                    <p class="text-muted text-center">Δεν υπάρχουν δεδομένα</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Domain</th><th class="text-center">OK</th><th class="text-center">Fail</th><th class="text-center">%</th></tr></thead>
                        <tbody>
                        <?php foreach ($domainStats as $ds): 
                            $dRate = $ds['total'] > 0 ? round(($ds['success_cnt'] / $ds['total']) * 100) : 0;
                        ?>
                            <tr>
                                <td><code><?= h($ds['domain']) ?></code></td>
                                <td class="text-center text-success"><?= $ds['success_cnt'] ?></td>
                                <td class="text-center text-danger"><?= $ds['failed_cnt'] ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $dRate >= 95 ? 'bg-success' : ($dRate >= 80 ? 'bg-warning' : 'bg-danger') ?>">
                                        <?= $dRate ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Notification Code Stats + Top Errors Row -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-tag me-1"></i>Ανά Τύπο Ειδοποίησης</div>
            <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                <?php if (empty($codeStats)): ?>
                    <p class="text-muted text-center">Δεν υπάρχουν δεδομένα</p>
                <?php else: ?>
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Κωδικός</th><th class="text-center">Σύνολο</th><th class="text-center">OK</th><th class="text-center">Fail</th></tr></thead>
                        <tbody>
                        <?php foreach ($codeStats as $cs): ?>
                            <tr>
                                <td><code><?= h($cs['code']) ?></code></td>
                                <td class="text-center"><?= $cs['total'] ?></td>
                                <td class="text-center text-success"><?= $cs['success_cnt'] ?></td>
                                <td class="text-center text-danger"><?= $cs['failed_cnt'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><i class="bi bi-exclamation-triangle me-1"></i>Top Σφάλματα</div>
            <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                <?php if (empty($topErrors)): ?>
                    <p class="text-muted text-center">Κανένα σφάλμα!</p>
                <?php else: ?>
                    <?php foreach ($topErrors as $err): ?>
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <small class="text-danger flex-grow-1"><?= h($err['error_message'] ?: 'Unknown') ?></small>
                            <span class="badge bg-danger ms-2"><?= $err['cnt'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small">Από</label>
                <input type="date" name="from" value="<?= h($filterFrom) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Έως</label>
                <input type="date" name="to" value="<?= h($filterTo) ?>" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Κατάσταση</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Όλα</option>
                    <option value="SUCCESS" <?= $filterStatus === 'SUCCESS' ? 'selected' : '' ?>>Επιτυχημένα</option>
                    <option value="FAILED" <?= $filterStatus === 'FAILED' ? 'selected' : '' ?>>Αποτυχημένα</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Email</label>
                <input type="text" name="email" value="<?= h($filterEmail) ?>" class="form-control form-control-sm" placeholder="Αναζήτηση...">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Τύπος</label>
                <select name="code" class="form-select form-select-sm">
                    <option value="">Όλοι</option>
                    <?php foreach ($availableCodes as $ac): ?>
                        <option value="<?= h($ac['code']) ?>" <?= $filterCode === $ac['code'] ? 'selected' : '' ?>><?= h($ac['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Φίλτρο</button>
            </div>
        </form>
    </div>
</div>

<!-- Log Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-1"></i>Αρχείο αποστολών (<?= number_format($total) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-2">Δεν βρέθηκαν εγγραφές</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:140px">Ημερομηνία</th>
                            <th>Παραλήπτης</th>
                            <th>Θέμα</th>
                            <th>Τύπος</th>
                            <th class="text-center">Κατάσταση</th>
                            <th>Σφάλμα</th>
                            <th class="text-center" style="width:90px">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="small"><?= formatDateTime($l['created_at']) ?></td>
                            <td>
                                <code class="small"><?= h($l['recipient_email']) ?></code>
                            </td>
                            <td class="small"><?= h(mb_substr($l['subject'], 0, 60)) ?><?= mb_strlen($l['subject']) > 60 ? '...' : '' ?></td>
                            <td>
                                <?php if ($l['notification_code']): ?>
                                    <span class="badge bg-info text-dark"><?= h($l['notification_code']) ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">manual</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($l['status'] === 'SUCCESS'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>FAIL</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-danger">
                                <?= $l['error_message'] ? h(mb_substr($l['error_message'], 0, 80)) : '-' ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-outline-secondary btn-sm" 
                                        data-bs-toggle="modal" data-bs-target="#logModal<?= $l['id'] ?>"
                                        title="Λεπτομέρειες">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if ($l['status'] === 'FAILED' && $l['notification_code']): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="resend">
                                        <input type="hidden" name="log_id" value="<?= $l['id'] ?>">
                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Επαναποστολή"
                                                onclick="return confirm('Επαναποστολή email στο <?= h($l['recipient_email']) ?>;')">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer bg-white">
            <?= paginationLinks($pagination, array_filter([
                'status' => $filterStatus, 'email' => $filterEmail, 'code' => $filterCode,
                'from' => $filterFrom, 'to' => $filterTo
            ])) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Detail Modals -->
<?php foreach ($logs as $l): ?>
<div class="modal fade" id="logModal<?= $l['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header <?= $l['status'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?> text-white">
                <h5 class="modal-title">
                    <i class="bi bi-<?= $l['status'] === 'SUCCESS' ? 'check-circle' : 'x-circle' ?> me-1"></i>
                    Email Log #<?= $l['id'] ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Παραλήπτης:</strong><br>
                        <code><?= h($l['recipient_email']) ?></code>
                    </div>
                    <div class="col-md-6">
                        <strong>Ημερομηνία:</strong><br>
                        <?= formatDateTime($l['created_at']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Θέμα:</strong><br>
                        <?= h($l['subject']) ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Τύπος:</strong><br>
                        <?= $l['notification_code'] ? h($l['notification_code']) : 'manual/test' ?>
                    </div>
                    <div class="col-md-6">
                        <strong>SMTP Host:</strong><br>
                        <?= h($l['smtp_host'] ?: '-') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Αποστολέας:</strong><br>
                        <?= h($l['from_email'] ?: '-') ?>
                    </div>
                    <?php if ($l['sent_by_name']): ?>
                    <div class="col-md-6">
                        <strong>Αποστολή από:</strong><br>
                        <?= h($l['sent_by_name']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <strong>Κατάσταση:</strong><br>
                        <span class="badge <?= $l['status'] === 'SUCCESS' ? 'bg-success' : 'bg-danger' ?>">
                            <?= $l['status'] ?>
                        </span>
                    </div>
                    <?php if ($l['error_message']): ?>
                    <div class="col-12">
                        <strong class="text-danger">Σφάλμα:</strong><br>
                        <div class="alert alert-danger small"><?= h($l['error_message']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($l['smtp_log']): ?>
                    <div class="col-12">
                        <strong>SMTP Log:</strong><br>
                        <pre class="bg-dark text-light p-3 rounded small" style="max-height: 300px; overflow-y: auto;"><?= h($l['smtp_log']) ?></pre>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Purge Modal -->
<div class="modal fade" id="purgeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="purge">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-1"></i>Εκκαθάριση Παλαιών Logs</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Διαγραφή email logs παλαιότερων από:</p>
                    <select name="days" class="form-select">
                        <option value="30">30 ημέρες</option>
                        <option value="60">60 ημέρες</option>
                        <option value="90" selected>90 ημέρες</option>
                        <option value="180">6 μήνες</option>
                        <option value="365">1 χρόνο</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">Εκκαθάριση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const dailyData = <?= json_encode($dailyStats) ?>;
if (dailyData.length > 0) {
    new Chart(document.getElementById('dailyChart'), {
        type: 'bar',
        data: {
            labels: dailyData.map(d => d.day),
            datasets: [
                {
                    label: 'Επιτυχημένα',
                    data: dailyData.map(d => d.success_cnt),
                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                    borderRadius: 4
                },
                {
                    label: 'Αποτυχημένα',
                    data: dailyData.map(d => d.failed_cnt),
                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { stacked: true, ticks: { maxRotation: 45 } },
                y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
} else {
    document.getElementById('dailyChart').parentElement.innerHTML = '<p class="text-muted text-center py-4">Δεν υπάρχουν δεδομένα για γράφημα</p>';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
