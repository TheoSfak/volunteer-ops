<?php
/**
 * VolunteerOps - Tags Επισκεπτών Αποστολής (Mission Visitor Tags) Management
 * The admin-extensible classification chips (Πολίτης/Αστυνομία/Πυροσβεστική/
 * ΕΜΑΚ/Πολιτική Προστασία/Δήμος/...) an admin taps to classify a walk-up
 * Mission Visitor from war-room.php's Επισκέπτες Αποστολής card. Cloned from
 * volunteer-teams.php's pattern — same lookup-table CRUD shape.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Tags Επισκεπτών Αποστολής';

$tags = dbFetchAll(
    "SELECT mvt.*, (SELECT COUNT(*) FROM users u WHERE u.mission_visitor_tag_id = mvt.id) as users_count
     FROM mission_visitor_tags mvt
     ORDER BY mvt.sort_order, mvt.label"
);

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
        case 'update':
            $id = post('id');
            $label = trim((string) post('label'));
            $color = post('color', '#6c757d');
            $icon = trim((string) post('icon')) ?: 'bi-person-badge';
            $sortOrder = (int) post('sort_order', 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($label === '') {
                setFlash('error', 'Η ονομασία είναι υποχρεωτική.');
            } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                setFlash('error', 'Μη έγκυρο χρώμα.');
            } else {
                $dupe = dbFetchOne("SELECT id FROM mission_visitor_tags WHERE label = ? AND id != ?", [$label, $id ?: 0]);
                if ($dupe) {
                    setFlash('error', 'Υπάρχει ήδη tag με αυτή την ονομασία.');
                } elseif ($id) {
                    dbExecute(
                        "UPDATE mission_visitor_tags SET label = ?, color = ?, icon = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                        [$label, $color, $icon, $sortOrder, $isActive, $id]
                    );
                    logAudit('update', 'mission_visitor_tags', $id);
                    setFlash('success', 'Το tag ενημερώθηκε.');
                } else {
                    $newId = dbInsert(
                        "INSERT INTO mission_visitor_tags (label, color, icon, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                        [$label, $color, $icon, $sortOrder, $isActive]
                    );
                    logAudit('create', 'mission_visitor_tags', $newId);
                    setFlash('success', 'Το tag δημιουργήθηκε.');
                }
            }
            break;

        case 'delete':
            $id = post('id');
            $tag = dbFetchOne("SELECT * FROM mission_visitor_tags WHERE id = ?", [$id]);

            if ($tag) {
                $usersCount = dbFetchValue("SELECT COUNT(*) FROM users WHERE mission_visitor_tag_id = ?", [$id]);
                if ($usersCount > 0) {
                    setFlash('error', 'Δεν μπορείτε να διαγράψετε tag που χρησιμοποιείται από επισκέπτες — απενεργοποιήστε το αντ\' αυτού.');
                } else {
                    dbExecute("DELETE FROM mission_visitor_tags WHERE id = ?", [$id]);
                    logAudit('delete', 'mission_visitor_tags', $id);
                    setFlash('success', 'Το tag διαγράφηκε.');
                }
            }
            break;
    }

    redirect('mission-visitor-tags.php');
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="bi bi-person-badge me-2"></i>Tags Επισκεπτών Αποστολής
        </h1>
        <p class="text-muted mb-0 mt-1">Οι κατηγορίες (Πολίτης, Αστυνομία, Πυροσβεστική, ...) που ένας admin επιλέγει για να ταξινομήσει έναν walk-up επισκέπτη αποστολής μέσα στο Action Room. Δεν έχει σχέση με τις ομάδες-σπίτι των τακτικών εθελοντών.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal">
        <i class="bi bi-plus-lg me-1"></i>Νέο Tag
    </button>
</div>

<?= showFlash() ?>

<?php if (empty($tags)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν tags.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle admin-mobile-cards">
            <thead>
                <tr>
                    <th>Tag</th>
                    <th class="text-center">Επισκέπτες</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tags as $tg): ?>
                    <tr class="<?= !$tg['is_active'] ? 'table-secondary' : '' ?>">
                        <td data-label="Tag">
                            <span class="badge rounded-pill px-3 py-2" style="background-color:<?= h($tg['color']) ?>;color:#fff;">
                                <i class="bi <?= h($tg['icon']) ?> me-1"></i><?= h($tg['label']) ?>
                            </span>
                        </td>
                        <td data-label="Επισκέπτες" class="text-center">
                            <span class="badge bg-secondary"><?= $tg['users_count'] ?></span>
                        </td>
                        <td data-label="Κατάσταση">
                            <?php if ($tg['is_active']): ?>
                                <span class="badge bg-success">Ενεργό</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργό</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ενέργειες" class="mobile-card-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="editTag(<?= htmlspecialchars(json_encode($tg)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($tg['users_count'] == 0): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή tag;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $tg['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
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

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; padding-top: .75rem; border-top: 1px solid var(--bs-border-color); }
}
</style>

<!-- Tag Modal -->
<div class="modal fade" id="tagModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="tagForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create" id="tagAction">
                <input type="hidden" name="id" id="tagId">

                <div class="modal-header">
                    <h5 class="modal-title" id="tagModalTitle">Νέο Tag</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Ονομασία *</label>
                        <input type="text" class="form-control" name="label" id="tagLabel" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Χρώμα</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" class="form-control form-control-color" id="tagColorPicker" name="color" value="#6c757d" style="width:60px;height:38px;">
                            <span class="badge rounded-pill px-3 py-2 fs-6" id="tagColorPreview" style="background-color:#6c757d;color:#fff;">
                                <i class="bi bi-person-badge me-1" id="tagIconPreview"></i><span id="tagLabelPreview">Προεπισκόπηση</span>
                            </span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bootstrap Icon</label>
                        <input type="text" class="form-control" name="icon" id="tagIcon" placeholder="bi-person-badge" value="bi-person-badge">
                        <div class="form-text">Όνομα κλάσης από το <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a>, π.χ. bi-shield-lock.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Σειρά Εμφάνισης</label>
                        <input type="number" class="form-control" name="sort_order" id="tagSortOrder" value="0">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="tagActive" checked>
                        <label class="form-check-label" for="tagActive">Ενεργό</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const tagColorPicker  = document.getElementById('tagColorPicker');
const tagColorPreview = document.getElementById('tagColorPreview');
const tagLabelInput   = document.getElementById('tagLabel');
const tagLabelPreview = document.getElementById('tagLabelPreview');
const tagIconInput    = document.getElementById('tagIcon');
const tagIconPreview  = document.getElementById('tagIconPreview');

tagColorPicker.addEventListener('input', () => {
    tagColorPreview.style.backgroundColor = tagColorPicker.value;
});
tagLabelInput.addEventListener('input', () => {
    tagLabelPreview.textContent = tagLabelInput.value || 'Προεπισκόπηση';
});
tagIconInput.addEventListener('input', () => {
    tagIconPreview.className = 'bi ' + (tagIconInput.value || 'bi-person-badge') + ' me-1';
});

function editTag(tag) {
    document.getElementById('tagAction').value = 'update';
    document.getElementById('tagId').value = tag.id;
    tagLabelInput.value = tag.label;
    tagColorPicker.value = tag.color;
    tagColorPreview.style.backgroundColor = tag.color;
    tagLabelPreview.textContent = tag.label;
    tagIconInput.value = tag.icon;
    tagIconPreview.className = 'bi ' + tag.icon + ' me-1';
    document.getElementById('tagSortOrder').value = tag.sort_order;
    document.getElementById('tagActive').checked = tag.is_active == 1;

    document.getElementById('tagModalTitle').textContent = 'Επεξεργασία Tag';
    new bootstrap.Modal(document.getElementById('tagModal')).show();
}

// Reset modal on close
document.getElementById('tagModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('tagForm').reset();
    document.getElementById('tagAction').value = 'create';
    document.getElementById('tagId').value = '';
    tagColorPicker.value = '#6c757d';
    tagColorPreview.style.backgroundColor = '#6c757d';
    tagLabelPreview.textContent = 'Προεπισκόπηση';
    tagIconInput.value = 'bi-person-badge';
    tagIconPreview.className = 'bi bi-person-badge me-1';
    document.getElementById('tagSortOrder').value = 0;
    document.getElementById('tagModalTitle').textContent = 'Νέο Tag';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
