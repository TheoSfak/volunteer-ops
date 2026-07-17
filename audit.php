<?php
/**
 * VolunteerOps - Audit Log
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ιστορικό Ενεργειών';

// Handle clear audit log
if (isPost() && post('action') === 'clear_audit') {
    verifyCsrf();
    dbExecute("DELETE FROM audit_logs");
    logAudit('clear_audit_log', 'audit_logs', null);
    setFlash('success', 'Το ιστορικό ενεργειών διαγράφηκε επιτυχώς.');
    redirect('audit.php');
}

// Filters
$userId = get('user_id', '');
$action = get('action', '');
$table = get('table', '');
$startDate = get('start_date', '');
$endDate = get('end_date', '');
$page = max(1, (int) get('page', 1));
$perPage = 50;

// Build query
$where = ['1=1'];
$params = [];

if ($userId) {
    $where[] = "al.user_id = ?";
    $params[] = $userId;
}

if ($action) {
    $where[] = "al.action = ?";
    $params[] = $action;
}

if ($table) {
    $where[] = "al.table_name = ?";
    $params[] = $table;
}

if ($startDate) {
    $where[] = "al.created_at >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $where[] = "al.created_at < ? + INTERVAL 1 DAY";
    $params[] = $endDate;
}

$whereClause = implode(' AND ', $where);

// Count total
$total = dbFetchValue("SELECT COUNT(*) FROM audit_logs al WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

// Get logs
$logs = dbFetchAll(
    "SELECT al.*, u.name as user_name, u.email as user_email
     FROM audit_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE $whereClause
     ORDER BY al.created_at DESC
     LIMIT {$pagination['offset']}, {$pagination['per_page']}",
    $params
);

// Get users for filter
$users = dbFetchAll("SELECT id, name FROM users ORDER BY name");

// Get unique actions
$actions = dbFetchAll("SELECT DISTINCT action FROM audit_logs ORDER BY action");

// Get unique tables
$tables = dbFetchAll("SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name");

include __DIR__ . '/includes/header.php';
?>

<style>
@media (max-width: 767.98px) {
    .audit-table-wrap { overflow: visible; }
    .audit-table thead { display: none; }
    .audit-table, .audit-table tbody, .audit-table tr, .audit-table td { display: block; width: 100%; }
    .audit-table tbody { padding: .75rem; }
    .audit-table tr { margin-bottom: .75rem; padding: .35rem .8rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .audit-table td { display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border: 0; border-bottom: 1px solid var(--bs-border-color-translucent); text-align: right !important; overflow-wrap: anywhere; white-space: normal !important; }
    .audit-table td:last-child { border: 0; }
    .audit-table td::before { flex: 0 0 34%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .audit-table td:nth-child(1)::before { content: "Ημερομηνία"; }
    .audit-table td:nth-child(2)::before { content: "Χρήστης"; }
    .audit-table td:nth-child(3)::before { content: "Ενέργεια"; }
    .audit-table td:nth-child(4)::before { content: "Πίνακας"; }
    .audit-table td:nth-child(5)::before { content: "ID"; }
    .audit-table td:nth-child(6)::before { content: "Σημειώσεις"; }
    .audit-table td:nth-child(7)::before { content: "IP"; }
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-journal-text me-2"></i>Ιστορικό Ενεργειών
    </h1>
    <?php if ($total > 0): ?>
    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#clearAuditModal">
        <i class="bi bi-trash me-1"></i>Εκκαθάριση Ιστορικού
    </button>
    <?php endif; ?>
</div>

<!-- Clear Audit Log Modal -->
<div class="modal fade" id="clearAuditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="clear_audit">
                <div class="modal-header">
                    <h5 class="modal-title">Εκκαθάριση Ιστορικού</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Προσοχή!</p>
                    <p>Πρόκειται να διαγράψετε <strong><?= number_format($total) ?></strong> εγγραφές από το ιστορικό ενεργειών. Η ενέργεια αυτή δεν μπορεί να αναιρεθεί.</p>
                    <p>Είστε σίγουροι ότι θέλετε να συνεχίσετε;</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Διαγραφή Όλων</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Χρήστης</label>
                <select name="user_id" class="form-select">
                    <option value="">Όλοι</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $userId == $u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Ενέργεια</label>
                <select name="action" class="form-select">
                    <option value="">Όλες</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= h($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
                            <?= h($a['action']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Πίνακας</label>
                <select name="table" class="form-select">
                    <option value="">Όλοι</option>
                    <?php foreach ($tables as $t): ?>
                        <option value="<?= h($t['table_name']) ?>" <?= $table === $t['table_name'] ? 'selected' : '' ?>>
                            <?= h($t['table_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Από</label>
                <input type="date" class="form-control" name="start_date" value="<?= h($startDate) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Έως</label>
                <input type="date" class="form-control" name="end_date" value="<?= h($endDate) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Αναζήτηση
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($logs)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν βρέθηκαν εγγραφές.
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive audit-table-wrap">
            <table class="table table-hover table-sm mb-0 audit-table table-mobile-opt-out">
                <thead class="table-light">
                    <tr>
                        <th>Ημ/ώρα</th>
                        <th>Χρήστης</th>
                        <th>Ενέργεια</th>
                        <th>Πίνακας</th>
                        <th>ID</th>
                        <th>Σημειώσεις</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-nowrap">
                                <?= formatDateTime($log['created_at']) ?>
                            </td>
                            <td>
                                <?php if ($log['user_name']): ?>
                                    <?= h($log['user_name']) ?>
                                    <br><small class="text-muted"><?= h($log['user_email']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= getActionColor($log['action']) ?>">
                                    <?= h($log['action']) ?>
                                </span>
                            </td>
                            <td><?= h($log['table_name'] ?: '-') ?></td>
                            <td><?= $log['record_id'] ?: '-' ?></td>
                            <td>
                                <?php if ($log['notes']): ?>
                                    <small><?= h(mb_substr($log['notes'], 0, 50)) ?><?= mb_strlen($log['notes']) > 50 ? '...' : '' ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?= h($log['ip_address'] ?: '-') ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?= paginationLinks($pagination) ?>
<?php endif; ?>

<?php
function getActionColor($action) {
    $colors = [
        'login' => 'success',
        'logout' => 'secondary',
        'create' => 'primary',
        'update' => 'info',
        'delete' => 'danger',
        'approve' => 'success',
        'reject' => 'warning',
        'apply' => 'primary',
        'cancel' => 'secondary',
    ];
    
    foreach ($colors as $key => $color) {
        if (stripos($action, $key) !== false) {
            return $color;
        }
    }
    return 'secondary';
}
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
