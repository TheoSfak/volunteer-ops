<?php
/**
 * VolunteerOps - Skills Management
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Διαχείριση Δεξιοτήτων';
$user = getCurrentUser();

// ─── POST Actions ──────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // ── Create ──
    if ($action === 'create') {
        $name     = trim(post('name'));
        $category = trim(post('category'));

        if (!$name) {
            setFlash('error', 'Το όνομα δεξιότητας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skills WHERE name = ?", [$name])) {
            setFlash('error', 'Υπάρχει ήδη δεξιότητα με αυτό το όνομα.');
        } else {
            $newId = dbInsert("INSERT INTO skills (name, category) VALUES (?, ?)", [$name, $category ?: null]);
            logAudit('create_skill', 'skills', $newId);
            setFlash('success', 'Η δεξιότητα <strong>' . h($name) . '</strong> προστέθηκε.');
        }
        redirect('skills.php');
    }

    // ── Update ──
    if ($action === 'update') {
        $id       = (int) post('id');
        $name     = trim(post('name'));
        $category = trim(post('category'));
        $skill    = dbFetchOne("SELECT * FROM skills WHERE id = ?", [$id]);

        if (!$skill) {
            setFlash('error', 'Η δεξιότητα δεν βρέθηκε.');
        } elseif (!$name) {
            setFlash('error', 'Το όνομα δεξιότητας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skills WHERE name = ? AND id != ?", [$name, $id])) {
            setFlash('error', 'Υπάρχει ήδη δεξιότητα με αυτό το όνομα.');
        } else {
            dbExecute("UPDATE skills SET name = ?, category = ? WHERE id = ?", [$name, $category ?: null, $id]);
            logAudit('update_skill', 'skills', $id);
            setFlash('success', 'Η δεξιότητα ενημερώθηκε.');
        }
        redirect('skills.php');
    }

    // ── Delete ──
    if ($action === 'delete') {
        $id    = (int) post('id');
        $skill = dbFetchOne("SELECT * FROM skills WHERE id = ?", [$id]);

        if (!$skill) {
            setFlash('error', 'Η δεξιότητα δεν βρέθηκε.');
        } else {
            // user_skills cascade deletes via FK
            dbExecute("DELETE FROM skills WHERE id = ?", [$id]);
            logAudit('delete_skill', 'skills', $id);
            setFlash('success', 'Η δεξιότητα <strong>' . h($skill['name']) . '</strong> διαγράφηκε.');
        }
        redirect('skills.php');
    }
}

// ─── Data ──────────────────────────────────────────────────────────────────────
$skills = dbFetchAll(
    "SELECT s.*,
            COUNT(us.user_id) AS volunteer_count
     FROM skills s
     LEFT JOIN user_skills us ON s.id = us.skill_id
     GROUP BY s.id
     ORDER BY s.category, s.name"
);

// Distinct categories for datalist
$categories = dbFetchAll("SELECT DISTINCT category FROM skills WHERE category IS NOT NULL ORDER BY category");

// Group by category for display
$grouped = [];
foreach ($skills as $sk) {
    $cat = $sk['category'] ?: 'Χωρίς κατηγορία';
    $grouped[$cat][] = $sk;
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-stars me-2"></i>Δεξιότητες</h1>
        <p class="text-muted mb-0">Διαχείριση δεξιοτήτων που μπορούν να αποδοθούν σε εθελοντές</p>
    </div>
    <?php if (isAdmin()): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-circle me-1"></i>Νέα Δεξιότητα
    </button>
    <?php endif; ?>
</div>

<?= showFlash() ?>

<?php if (empty($skills)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν δεξιότητες. Προσθέστε την πρώτη!
    </div>
<?php else: ?>

<!-- Stats bar -->
<div class="row g-3 mb-4">
    <div class="col-auto">
        <div class="card border-0 bg-primary bg-opacity-10 px-3 py-2">
            <small class="text-muted">Σύνολο δεξιοτήτων</small>
            <div class="fw-bold fs-5"><?= count($skills) ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 bg-success bg-opacity-10 px-3 py-2">
            <small class="text-muted">Κατηγορίες</small>
            <div class="fw-bold fs-5"><?= count($grouped) ?></div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 bg-info bg-opacity-10 px-3 py-2">
            <small class="text-muted">Αναθέσεις σε εθελοντές</small>
            <div class="fw-bold fs-5"><?= array_sum(array_column($skills, 'volunteer_count')) ?></div>
        </div>
    </div>
</div>

<?php foreach ($grouped as $catName => $catSkills): ?>
<div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-tag text-primary"></i>
        <strong><?= h($catName) ?></strong>
        <span class="badge bg-secondary ms-1"><?= count($catSkills) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Όνομα Δεξιότητας</th>
                    <th class="text-center">Εθελοντές</th>
                    <th class="text-end pe-3"><?= isAdmin() ? 'Ενέργειες' : '' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catSkills as $sk): ?>
                <tr>
                    <td class="fw-semibold"><?= h($sk['name']) ?></td>
                    <td class="text-center">
                        <?php if ($sk['volunteer_count'] > 0): ?>
                            <span class="badge bg-primary"><?= $sk['volunteer_count'] ?></span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <?php if (isAdmin()): ?>
                        <button class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal"
                                data-bs-target="#editModal<?= $sk['id'] ?>"
                                title="Επεξεργασία">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteModal<?= $sk['id'] ?>"
                                title="Διαγραφή">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<?php if (isAdmin()): ?>
<!-- ─── Create Modal ──────────────────────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Νέα Δεξιότητα</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               placeholder="π.χ. Πρώτες Βοήθειες">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Κατηγορία</label>
                        <input type="text" name="category" class="form-control" maxlength="50"
                               list="categoryList" placeholder="π.χ. Υγεία, Τεχνικά, Γενικά">
                        <datalist id="categoryList">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat['category']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Προαιρετική κατηγορία ομαδοποίησης</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Προσθήκη
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── Edit & Delete Modals (per skill) ─────────────────────────────────────── -->
<?php foreach ($skills as $sk): ?>

<!-- Edit Modal -->
<div class="modal fade" id="editModal<?= $sk['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $sk['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Επεξεργασία Δεξιότητας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100"
                               value="<?= h($sk['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Κατηγορία</label>
                        <input type="text" name="category" class="form-control" maxlength="50"
                               list="categoryList"
                               value="<?= h($sk['category'] ?? '') ?>">
                        <div class="form-text">Αφήστε κενό για «Χωρίς κατηγορία»</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Αποθήκευση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal<?= $sk['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $sk['id'] ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Δεξιότητας</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Πρόκειται να διαγράψετε τη δεξιότητα <strong><?= h($sk['name']) ?></strong>.</p>
                    <?php if ($sk['volunteer_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong><?= $sk['volunteer_count'] ?> εθελοντές</strong> έχουν αυτή τη δεξιότητα.
                        Η ανάθεσή τους θα διαγραφεί αυτόματα.
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Δεν έχει ανατεθεί σε κανέναν εθελοντή.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Διαγραφή
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
