<?php
/**
 * VolunteerOps - Newsletter Templates (CRUD List)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Πρότυπα Email Newsletter';

// ── POST actions ──
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // Delete template
    if ($action === 'delete') {
        $id = (int)post('id');
        $tpl = dbFetchOne("SELECT * FROM newsletter_templates WHERE id = ?", [$id]);
        if ($tpl) {
            if ($tpl['is_default']) {
                setFlash('error', 'Δεν μπορείτε να διαγράψετε το προεπιλεγμένο πρότυπο.');
            } else {
                // Unlink newsletters using this template
                dbExecute("UPDATE newsletters SET template_id = NULL WHERE template_id = ?", [$id]);
                dbExecute("DELETE FROM newsletter_templates WHERE id = ?", [$id]);
                logAudit('newsletter_template_delete', 'newsletter_templates', $id);
                setFlash('success', 'Το πρότυπο διαγράφηκε.');
            }
        }
        redirect('newsletter-templates.php');
    }

    // Set as default
    if ($action === 'set_default') {
        $id = (int)post('id');
        $tpl = dbFetchOne("SELECT * FROM newsletter_templates WHERE id = ?", [$id]);
        if ($tpl) {
            dbExecute("UPDATE newsletter_templates SET is_default = 0");
            dbExecute("UPDATE newsletter_templates SET is_default = 1 WHERE id = ?", [$id]);
            logAudit('newsletter_template_set_default', 'newsletter_templates', $id);
            setFlash('success', 'Το πρότυπο "' . h($tpl['name']) . '" ορίστηκε ως προεπιλογή.');
        }
        redirect('newsletter-templates.php');
    }
}

// Fetch all templates
$templates = dbFetchAll("
    SELECT nt.*,
           (SELECT COUNT(*) FROM newsletters n WHERE n.template_id = nt.id) AS usage_count
    FROM newsletter_templates nt
    ORDER BY nt.is_default DESC, nt.name ASC
");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="newsletters.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Ενημερωτικά</a>
        <h1 class="h3 mb-0 mt-1"><i class="bi bi-palette me-2 text-info"></i><?= h($pageTitle) ?></h1>
    </div>
    <a href="newsletter-template-form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Νέο Πρότυπο
    </a>
</div>

<?= displayFlash() ?>

<?php if (empty($templates)): ?>
    <div class="alert alert-info">Δεν υπάρχουν πρότυπα ακόμα. Δημιουργήστε ένα πρότυπο για να ξεκινήσετε.</div>
<?php else: ?>

<div class="row g-4">
    <?php foreach ($templates as $tpl): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100 <?= $tpl['is_default'] ? 'border-info' : '' ?>">
            <div class="card-header d-flex justify-content-between align-items-center <?= $tpl['is_default'] ? 'bg-info bg-opacity-10' : 'bg-white' ?>">
                <strong><?= h($tpl['name']) ?></strong>
                <?php if ($tpl['is_default']): ?>
                    <span class="badge bg-info"><i class="bi bi-check-circle me-1"></i>Προεπιλογή</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0" style="height:220px;overflow:hidden;">
                <iframe class="tpl-preview" style="width:200%;height:200%;border:0;pointer-events:none;transform:scale(0.5);transform-origin:top left;" 
                        data-body="<?= h($tpl['body_html']) ?>"
                        sandbox="allow-same-origin"></iframe>
            </div>
            <div class="card-footer bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        <i class="bi bi-envelope me-1"></i><?= $tpl['usage_count'] ?> χρήσεις
                        · <?= formatDate($tpl['created_at']) ?>
                    </small>
                </div>
                <div class="d-flex gap-1">
                    <a href="newsletter-template-form.php?id=<?= $tpl['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
                        <i class="bi bi-pencil me-1"></i>Επεξεργασία
                    </a>
                    <?php if (!$tpl['is_default']): ?>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-info" title="Ορισμός ως προεπιλογή">
                            <i class="bi bi-check-circle"></i>
                        </button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή του προτύπου \'<?= h($tpl['name']) ?>\';\nΤα ενημερωτικά που το χρησιμοποιούν θα χρησιμοποιήσουν το προεπιλεγμένο.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Διαγραφή">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<script>
// Render template previews in iframes
(function() {
    var fromName = <?= json_encode(getSetting('smtp_from_name', 'VolunteerOps')) ?>;
    var logoUrl = <?= json_encode(getSetting('app_logo', '')) ?>;
    var logoHtml = logoUrl ? '<img src="uploads/logos/' + logoUrl + '" alt="" style="max-height:50px;margin-bottom:10px;">' : '';

    document.querySelectorAll('.tpl-preview').forEach(function(frame) {
        var tplBody = (frame.dataset.body || '{content}');
        var html = tplBody.replace(/\{from_name\}/g, fromName)
                          .replace(/\{logo_url\}/g, logoHtml)
                          .replace(/\{content\}/g, '<p style="color:#999;font-style:italic;padding:0 10px;">Δείγμα περιεχομένου newsletter…</p>');
        frame.srcdoc = html;
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
