<?php
/**
 * VolunteerOps - Announcements (admin-authored "what's new" popups)
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ανακοινώσεις';

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
            $title   = trim(post('title'));
            $version = trim(post('version'));
            $body    = trim(post('body'));

            if ($title === '' || $body === '') {
                setFlash('error', 'Ο τίτλος και το κείμενο είναι υποχρεωτικά.');
            } else {
                $newId = dbInsert(
                    "INSERT INTO announcements (version, title, body, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
                    [$version !== '' ? $version : null, $title, $body, getCurrentUserId()]
                );
                logAudit('announcement_create', 'announcements', $newId);
                setFlash('success', 'Η ανακοίνωση δημοσιεύτηκε — θα εμφανιστεί σε όλους τους χρήστες.');
            }
            break;

        case 'toggle_active':
            $id = (int) post('id');
            dbExecute("UPDATE announcements SET is_active = NOT is_active WHERE id = ?", [$id]);
            logAudit('announcement_toggle', 'announcements', $id);
            setFlash('success', 'Η κατάσταση ενημερώθηκε.');
            break;

        case 'delete':
            $id = (int) post('id');
            dbExecute("DELETE FROM announcements WHERE id = ?", [$id]);
            logAudit('announcement_delete', 'announcements', $id);
            setFlash('success', 'Η ανακοίνωση διαγράφηκε.');
            break;
    }

    redirect('announcements.php');
}

// Denominator for the "seen X / Y" column — same active-user filter used app-wide.
$totalActiveUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL");

$announcements = dbFetchAll(
    "SELECT a.*, u.name AS creator_name,
            (SELECT COUNT(*) FROM announcement_dismissals d WHERE d.announcement_id = a.id) AS seen_count
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.created_at DESC, a.id DESC"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-megaphone me-2"></i><?= h($pageTitle) ?></h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#announcementModal">
        <i class="bi bi-plus-lg me-1"></i>Νέα Ανακοίνωση
    </button>
</div>

<?= displayFlash() ?>

<div class="card shadow-sm">
    <div class="card-header bg-white"><strong>Ιστορικό Ανακοινώσεων</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 admin-mobile-cards">
            <thead class="table-light">
                <tr>
                    <th>Έκδοση</th>
                    <th>Τίτλος</th>
                    <th>Είδαν</th>
                    <th>Κατάσταση</th>
                    <th>Ημ/νία</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($announcements)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Δεν υπάρχουν ανακοινώσεις ακόμα.</td></tr>
            <?php else: ?>
                <?php foreach ($announcements as $a): ?>
                <tr class="<?= !$a['is_active'] ? 'table-secondary' : '' ?>">
                    <td data-label="Έκδοση"><?= $a['version'] ? '<span class="badge bg-secondary">v' . h($a['version']) . '</span>' : '—' ?></td>
                    <td data-label="Τίτλος">
                        <strong><?= h($a['title']) ?></strong>
                        <br><small class="text-muted"><?= h(mb_strimwidth($a['body'], 0, 80, '…')) ?></small>
                    </td>
                    <td data-label="Είδαν">
                        <span class="fw-semibold"><?= (int) $a['seen_count'] ?></span>
                        <span class="text-muted">/ <?= $totalActiveUsers ?></span>
                    </td>
                    <td data-label="Κατάσταση">
                        <?php if ($a['is_active']): ?>
                            <span class="badge bg-success">Ενεργή</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Ανενεργή</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Ημ/νία" class="text-muted small">
                        <?= formatDateTime($a['created_at']) ?>
                        <br><small><?= h($a['creator_name'] ?? '—') ?></small>
                    </td>
                    <td data-label="Ενέργειες" class="text-end mobile-card-actions">
                        <form method="post" class="d-inline" onsubmit="return confirm('<?= $a['is_active'] ? 'Απόκρυψη' : 'Επανενεργοποίηση' ?> αυτής της ανακοίνωσης;')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $a['is_active'] ? 'Απόκρυψη' : 'Επανενεργοποίηση' ?>">
                                <i class="bi <?= $a['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                            </button>
                        </form>
                        <form method="post" class="d-inline" onsubmit="return confirm('Οριστική διαγραφή αυτής της ανακοίνωσης;')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr:not(:has(td[colspan])) { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards tr:not(:has(td[colspan])) td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards tr:not(:has(td[colspan])) td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; padding-top: .75rem !important; border-top: 1px solid var(--bs-border-color) !important; }
}
</style>

<!-- Create Modal -->
<div class="modal fade" id="announcementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Νέα Ανακοίνωση</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Τίτλος <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" placeholder="π.χ. Νέες δυνατότητες" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Έκδοση</label>
                            <input type="text" class="form-control" name="version" value="<?= h(APP_VERSION) ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Κείμενο <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="body" rows="10" placeholder="Γράψτε ελεύθερα -- οι αλλαγές γραμμής διατηρούνται όπως τις πληκτρολογείτε." required></textarea>
                    </div>
                    <small class="text-muted">Θα εμφανιστεί ως αναδυόμενο παράθυρο σε όλους τους ενεργούς χρήστες με την επόμενη είσοδό τους στην εφαρμογή, μέχρι να το κλείσουν.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Δημοσίευση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
