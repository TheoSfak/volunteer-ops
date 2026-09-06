<?php
/**
 * VolunteerOps - Άμεση Κινητοποίηση
 * Admin broadcasts a free-text message over Telegram to every volunteer who
 * has linked their account (notification-preferences.php). Org-wide, not
 * tied to a mission - for calling volunteers in before/outside a specific
 * mission exists, same "system admin only" scope as announcements.php.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Άμεση Κινητοποίηση';
$currentPage = 'mobilization';

if (isPost()) {
    verifyCsrf();

    if (post('action') === 'send') {
        $message = trim(post('message'));

        if (!isTelegramConfigured()) {
            setFlash('error', 'Δεν έχει ρυθμιστεί bot Telegram ακόμα.');
        } elseif ($message === '') {
            setFlash('error', 'Το μήνυμα είναι υποχρεωτικό.');
        } else {
            // A few hundred sequential Telegram API calls can run past PHP's
            // default 30s limit, and this must finish even if the admin's
            // browser tab is closed right after confirming - same reasoning
            // as newsletter-view.php's bulk send.
            set_time_limit(0);
            ignore_user_abort(true);

            $result = sendTelegramBroadcast($message);
            $newId = dbInsert(
                "INSERT INTO mobilization_broadcasts (message, sent_by, recipients_count, delivered_count, failed_count, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$message, getCurrentUserId(), $result['recipients'], $result['delivered'], $result['failed']]
            );
            logAudit('mobilization_send', 'mobilization_broadcasts', $newId, null, ['recipients' => $result['recipients'], 'delivered' => $result['delivered']]);

            if ($result['recipients'] === 0) {
                setFlash('warning', 'Το μήνυμα καταγράφηκε, αλλά κανένας εθελοντής δεν έχει συνδέσει ακόμα το Telegram του.');
            } else {
                $extra = $result['failed'] > 0 ? " ({$result['failed']} απέτυχαν)" : '';
                setFlash('success', "Στάλθηκε σε {$result['delivered']} από {$result['recipients']} συνδεδεμένους εθελοντές.{$extra}");
            }
        }
    } elseif (post('action') === 'delete') {
        $id = (int) post('id');
        dbExecute("DELETE FROM mobilization_broadcasts WHERE id = ?", [$id]);
        logAudit('mobilization_delete', 'mobilization_broadcasts', $id);
        setFlash('success', 'Η κινητοποίηση διαγράφηκε από το ιστορικό.');
    }

    redirect('mobilization.php');
}

$telegramReady = isTelegramConfigured();
$linkedCount = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE telegram_chat_id IS NOT NULL AND is_active = 1 AND deleted_at IS NULL");
$totalActiveUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL");

$history = dbFetchAll(
    "SELECT b.*, u.name AS sender_name
       FROM mobilization_broadcasts b
       LEFT JOIN users u ON u.id = b.sent_by
   ORDER BY b.created_at DESC, b.id DESC
      LIMIT 50"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-broadcast me-2"></i><?= h($pageTitle) ?></h1>
</div>

<?= displayFlash() ?>

<?php if (!$telegramReady): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Δεν έχει ρυθμιστεί bot Telegram ακόμα. Πηγαίνετε στις
    <a href="settings.php?tab=general">Ρυθμίσεις</a> για να συνδέσετε ένα δωρεάν bot πριν χρησιμοποιήσετε αυτή τη λειτουργία.
</div>
<?php else: ?>

<div class="card shadow-sm mb-4 border-danger border-opacity-50">
    <div class="card-body text-center py-4">
        <p class="text-muted mb-3">
            <strong><?= $linkedCount ?></strong> από <?= $totalActiveUsers ?> ενεργούς εθελοντές έχουν συνδέσει το Telegram τους.
        </p>
        <button type="button" class="btn btn-danger btn-lg px-5 py-3 fw-bold" data-bs-toggle="modal" data-bs-target="#mobilizeModal" <?= $linkedCount === 0 ? 'disabled' : '' ?>>
            <i class="bi bi-broadcast me-2"></i>🚨 Άμεση Κινητοποίηση
        </button>
        <?php if ($linkedCount === 0): ?>
        <div class="form-text mt-2">Κανένας εθελοντής δεν έχει συνδέσει ακόμα το Telegram του — το κάνουν μόνοι τους από τις Ρυθμίσεις Ειδοποιήσεων.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white"><strong>Ιστορικό Κινητοποιήσεων</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 admin-mobile-cards">
            <thead class="table-light">
                <tr>
                    <th>Μήνυμα</th>
                    <th>Παραλήπτες</th>
                    <th>Από</th>
                    <th>Ημ/νία</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Δεν έχει σταλεί ακόμα καμία κινητοποίηση.</td></tr>
            <?php else: ?>
                <?php foreach ($history as $b): ?>
                <tr>
                    <td data-label="Μήνυμα"><?= h(mb_strimwidth($b['message'], 0, 120, '…')) ?></td>
                    <td data-label="Παραλήπτες">
                        <span class="badge bg-success"><?= (int) $b['delivered_count'] ?> εστάλησαν</span>
                        <?php if ($b['failed_count'] > 0): ?><span class="badge bg-danger"><?= (int) $b['failed_count'] ?> απέτυχαν</span><?php endif; ?>
                        <span class="text-muted small">/ <?= (int) $b['recipients_count'] ?> συνδεδεμένοι</span>
                    </td>
                    <td data-label="Από"><?= h($b['sender_name'] ?? '—') ?></td>
                    <td data-label="Ημ/νία" class="text-muted small"><?= formatDateTime($b['created_at']) ?></td>
                    <td data-label="Ενέργειες" class="text-end mobile-card-actions">
                        <form method="post" class="d-inline" onsubmit="return confirm('Οριστική διαγραφή αυτής της κινητοποίησης από το ιστορικό;');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
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

<?php if ($telegramReady): ?>
<!-- Mobilize Modal -->
<div class="modal fade" id="mobilizeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" onsubmit="return confirm('Θα σταλεί ΑΜΕΣΑ μέσω Telegram σε <?= $linkedCount ?> εθελοντές. Συνέχεια;');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-broadcast me-2"></i>Άμεση Κινητοποίηση</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label fw-semibold">Μήνυμα <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="message" rows="6" placeholder="π.χ. Χρειαζόμαστε άμεσα εθελοντές για επιχείρηση σε εξέλιξη. Όσοι είστε διαθέσιμοι, συνδεθείτε στην εφαρμογή." required></textarea>
                    </div>
                    <small class="text-muted">Θα σταλεί ως μήνυμα Telegram σε <strong><?= $linkedCount ?></strong> εθελοντές που έχουν συνδέσει τον λογαριασμό τους.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-send-fill me-1"></i>Αποστολή Τώρα
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr:not(:has(td[colspan])) { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards tr:not(:has(td[colspan])) td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards tr:not(:has(td[colspan])) td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
