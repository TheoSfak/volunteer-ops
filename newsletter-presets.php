<?php
/**
 * VolunteerOps - Newsletter Content Presets (Πρότυπα Περιεχομένου)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Πρότυπα Περιεχομένου';

// Check if table exists (migration may not have run yet)
$tableReady = (bool)dbFetchOne(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_presets'"
);

// ── POST actions ──
if ($tableReady && isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'delete') {
        $id = (int)post('id');
        $preset = dbFetchOne("SELECT * FROM newsletter_presets WHERE id = ?", [$id]);
        if ($preset) {
            dbExecute("DELETE FROM newsletter_presets WHERE id = ?", [$id]);
            logAudit('newsletter_preset_delete', 'newsletter_presets', $id);
            setFlash('success', 'Το πρότυπο περιεχομένου διαγράφηκε.');
        }
        redirect('newsletter-presets.php');
    }
}

// AJAX: fetch preset body for newsletter-form integration
if (get('action') === 'get_body') {
    header('Content-Type: application/json');
    if ($tableReady) {
        $presetId = (int)get('id');
        $preset = dbFetchOne("SELECT body_html FROM newsletter_presets WHERE id = ?", [$presetId]);
        echo json_encode(['body_html' => $preset ? $preset['body_html'] : '']);
    } else {
        echo json_encode(['body_html' => '']);
    }
    exit;
}

// Fetch all presets
$presets = $tableReady ? dbFetchAll("
    SELECT np.*, u.name AS creator_name
    FROM newsletter_presets np
    LEFT JOIN users u ON u.id = np.created_by
    ORDER BY np.name ASC
") : [];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="newsletters.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Ενημερωτικά</a>
        <h1 class="h3 mb-0 mt-1"><i class="bi bi-file-earmark-text me-2 text-success"></i><?= h($pageTitle) ?></h1>
        <p class="text-muted small mb-0">Έτοιμα κείμενα που φορτώνονται στον editor κατά τη δημιουργία newsletter</p>
    </div>
    <a href="newsletter-preset-form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Νέο Πρότυπο
    </a>
</div>

<?= displayFlash() ?>

<?php if (!$tableReady): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Ο πίνακας <code>newsletter_presets</code> δεν έχει δημιουργηθεί ακόμα. Η αυτόματη μετάβαση βάσης (migration) δεν έχει ολοκληρωθεί. Ελέγξτε τα migrations ή ανανεώστε τη σελίδα.</div>
<?php elseif (empty($presets)): ?>
    <div class="alert alert-info">Δεν υπάρχουν πρότυπα περιεχομένου ακόμα. Δημιουργήστε ένα για να ξεκινήσετε.</div>
<?php else: ?>

<div class="row g-4">
    <?php foreach ($presets as $p): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong><?= h($p['name']) ?></strong>
            </div>
            <div class="card-body">
                <?php if ($p['description']): ?>
                    <p class="text-muted small mb-2"><?= h($p['description']) ?></p>
                <?php endif; ?>
                <div class="border rounded p-2 bg-light" style="max-height:180px;overflow:hidden;font-size:0.8rem;">
                    <?= mb_strimwidth(strip_tags($p['body_html']), 0, 300, '…') ?>
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">
                        <i class="bi bi-calendar me-1"></i><?= formatDate($p['created_at']) ?>
                        <?php if ($p['creator_name']): ?>
                            · <i class="bi bi-person me-1"></i><?= h($p['creator_name']) ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="d-flex gap-1">
                    <a href="newsletter-preset-form.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
                        <i class="bi bi-pencil me-1"></i>Επεξεργασία
                    </a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή του προτύπου \'<?= h($p['name']) ?>\';');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Διαγραφή">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
