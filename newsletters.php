<?php
/**
 * VolunteerOps - Newsletters (Campaign List)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ενημερωτικά Δελτία';

// Handle save template
if (isPost() && post('action') === 'save_template') {
    verifyCsrf();
    $header = post('newsletter_template_header');
    $footer = post('newsletter_template_footer');
    dbExecute("INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
               VALUES ('newsletter_template_header', ?, NOW(), NOW())
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$header]);
    dbExecute("INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
               VALUES ('newsletter_template_footer', ?, NOW(), NOW())
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$footer]);
    logAudit('newsletter_template_update', 'settings', 0);
    setFlash('success', 'Το πρότυπο email αποθηκεύτηκε.');
    redirect('newsletters.php');
}

// Handle delete draft
if (isPost() && post('action') === 'delete') {
    verifyCsrf();
    $id = (int)post('id');
    $nl = dbFetchOne("SELECT * FROM newsletters WHERE id = ? AND status = 'draft'", [$id]);
    if ($nl) {
        dbExecute("DELETE FROM newsletter_sends WHERE newsletter_id = ?", [$id]);
        dbExecute("DELETE FROM newsletters WHERE id = ?", [$id]);
        logAudit('newsletter_delete', 'newsletters', $id);
        setFlash('success', 'Το πρόχειρο διαγράφηκε.');
    } else {
        setFlash('error', 'Δεν βρέθηκε ή δεν είναι πρόχειρο.');
    }
    redirect('newsletters.php');
}

// Fetch campaigns with stats
$newsletters = dbFetchAll("
    SELECT n.*,
           u.name AS creator_name,
           d.name AS dept_name
    FROM newsletters n
    LEFT JOIN users u ON u.id = n.created_by
    LEFT JOIN departments d ON d.id = n.filter_dept_id
    ORDER BY n.created_at DESC
");

// Global stats
$totalCampaigns   = dbFetchValue("SELECT COUNT(*) FROM newsletters WHERE status = 'sent'");
$totalEmailsSent  = dbFetchValue("SELECT COALESCE(SUM(sent_count),0) FROM newsletters WHERE status = 'sent'");
$totalUnsubs      = dbFetchValue("SELECT COUNT(*) FROM newsletter_unsubscribes WHERE unsubscribed_at IS NOT NULL");
$totalFailed      = dbFetchValue("SELECT COALESCE(SUM(failed_count),0) FROM newsletters WHERE status = 'sent'");

// Newsletter template defaults
$defaultHeader = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.hdr{background:#c0392b;padding:24px 32px;color:#fff}.hdr h2{margin:0;font-size:22px}
.body{padding:32px;color:#333;line-height:1.6}.ftr{background:#f8f8f8;padding:16px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h2>{from_name}</h2></div>
  <div class="body">';
$defaultFooter = '</div>
  <div class="ftr"><p>Αυτό το email στάλθηκε από {from_name}.</p></div>
</div></body></html>';
$tplHeader = getSetting('newsletter_template_header', $defaultHeader);
$tplFooter = getSetting('newsletter_template_footer', $defaultFooter);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-envelope-paper me-2"></i><?= h($pageTitle) ?></h1>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-info" type="button" data-bs-toggle="collapse" data-bs-target="#templateEditor">
            <i class="bi bi-palette me-1"></i>Πρότυπο Email
        </button>
        <a href="newsletter-log.php" class="btn btn-outline-secondary">
            <i class="bi bi-bar-chart me-1"></i>Στατιστικά
        </a>
        <a href="newsletter-form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέο Δελτίο
        </a>
    </div>
</div>

<?= displayFlash() ?>

<!-- Newsletter email template editor (collapsible) -->
<div class="collapse mb-4" id="templateEditor">
    <div class="card shadow-sm border-info">
        <div class="card-header bg-info bg-opacity-10 d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-palette me-2"></i>Πρότυπο Email Newsletter</strong>
            <small class="text-muted">Χρησιμοποιήστε <code>{from_name}</code> για το όνομα αποστολέα</small>
        </div>
        <div class="card-body">
            <form method="post" id="templateForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_template">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold">Header HTML <small class="text-muted">(πριν το περιεχόμενο)</small></label>
                        <textarea class="form-control font-monospace" name="newsletter_template_header" id="tplHeader" rows="10" style="font-size:0.8rem;"><?= h($tplHeader) ?></textarea>
                    </div>
                    <div class="col-lg-6">
                        <label class="form-label fw-semibold">Footer HTML <small class="text-muted">(μετά το περιεχόμενο)</small></label>
                        <textarea class="form-control font-monospace" name="newsletter_template_footer" id="tplFooter" rows="10" style="font-size:0.8rem;"><?= h($tplFooter) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Προεπισκόπηση</label>
                        <iframe id="tplPreview" style="width:100%;height:300px;border:1px solid #dee2e6;border-radius:6px;" sandbox="allow-same-origin"></iframe>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#templateEditor">Κλείσιμο</button>
                        <button type="submit" class="btn btn-info"><i class="bi bi-floppy me-1"></i>Αποθήκευση Προτύπου</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= $totalCampaigns ?></div>
            <small class="text-muted">Αποσταλθέντα δελτία</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= number_format($totalEmailsSent) ?></div>
            <small class="text-muted">Emails αποστάλθηκαν</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-danger"><?= number_format($totalFailed) ?></div>
            <small class="text-muted">Αποτυχίες</small>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= $totalUnsubs ?></div>
            <small class="text-muted">Διαγραφές</small>
        </div>
    </div>
</div>

<!-- Campaign list -->
<div class="card shadow-sm">
    <div class="card-header bg-white"><strong>Ιστορικό Εκστρατειών</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Τίτλος</th>
                    <th>Θέμα</th>
                    <th>Αποδέκτες</th>
                    <th>Κατάσταση</th>
                    <th>Ημ/νία</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($newsletters)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Δεν υπάρχουν εκστρατείες ακόμα.</td></tr>
            <?php else: ?>
                <?php foreach ($newsletters as $nl): ?>
                <tr>
                    <td>
                        <a href="newsletter-view.php?id=<?= $nl['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= h($nl['title']) ?>
                        </a>
                        <?php if ($nl['dept_name']): ?>
                            <br><small class="text-muted"><i class="bi bi-building me-1"></i><?= h($nl['dept_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= h(mb_strimwidth($nl['subject'], 0, 60, '…')) ?></td>
                    <td>
                        <?php if ($nl['status'] === 'sent'): ?>
                            <span class="text-success fw-semibold"><?= $nl['sent_count'] ?></span>
                            <?php if ($nl['failed_count']): ?>
                                / <span class="text-danger"><?= $nl['failed_count'] ?> αποτ.</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $badges = [
                            'draft'   => 'secondary',
                            'sending' => 'warning',
                            'sent'    => 'success',
                            'failed'  => 'danger',
                        ];
                        $labels = [
                            'draft'   => 'Πρόχειρο',
                            'sending' => 'Αποστολή…',
                            'sent'    => 'Εστάλη',
                            'failed'  => 'Αποτυχία',
                        ];
                        $b = $badges[$nl['status']] ?? 'secondary';
                        $l = $labels[$nl['status']] ?? $nl['status'];
                        ?>
                        <span class="badge bg-<?= $b ?>"><?= $l ?></span>
                    </td>
                    <td class="text-muted small">
                        <?= $nl['sent_at'] ? formatDateTime($nl['sent_at']) : formatDateTime($nl['created_at']) ?>
                        <br><small><?= h($nl['creator_name'] ?? '—') ?></small>
                    </td>
                    <td class="text-end">
                        <a href="newsletter-view.php?id=<?= $nl['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if ($nl['status'] === 'draft'): ?>
                        <a href="newsletter-form.php?id=<?= $nl['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-danger" 
                                data-bs-toggle="modal" data-bs-target="#deleteModal"
                                data-id="<?= $nl['id'] ?>" data-title="<?= h($nl['title']) ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete Draft Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Διαγραφή Πρόχειρου</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <div class="modal-body">
                    Θέλετε σίγουρα να διαγράψετε το πρόχειρο "<strong id="deleteTitle"></strong>";
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Template preview
(function() {
    var fromName = <?= json_encode(getSetting('smtp_from_name', 'VolunteerOps')) ?>;
    var headerEl = document.getElementById('tplHeader');
    var footerEl = document.getElementById('tplFooter');
    var preview  = document.getElementById('tplPreview');
    function updatePreview() {
        var h = (headerEl.value || '').replace(/\{from_name\}/g, fromName);
        var f = (footerEl.value || '').replace(/\{from_name\}/g, fromName);
        var html = h + '<p style="color:#999;font-style:italic;">Εδώ εμφανίζεται το περιεχόμενο του newsletter…</p>' + f;
        preview.srcdoc = html;
    }
    headerEl.addEventListener('input', updatePreview);
    footerEl.addEventListener('input', updatePreview);
    // Initial render when collapse opens
    document.getElementById('templateEditor').addEventListener('shown.bs.collapse', updatePreview);
    // Also render immediately if already visible
    updatePreview();
})();

document.getElementById('deleteModal').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('deleteId').value    = btn.dataset.id;
    document.getElementById('deleteTitle').textContent = btn.dataset.title;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
