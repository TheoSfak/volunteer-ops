<?php
/**
 * VolunteerOps - Skills & Categories Management
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

    // ════ CATEGORY ACTIONS ══════════════════════════════════════════════════

    if ($action === 'create_category') {
        $name = trim(post('cat_name'));
        if (!$name) {
            setFlash('error', 'Το όνομα κατηγορίας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skill_categories WHERE name = ?", [$name])) {
            setFlash('error', 'Υπάρχει ήδη κατηγορία με αυτό το όνομα.');
        } else {
            $newId = dbInsert("INSERT INTO skill_categories (name) VALUES (?)", [$name]);
            logAudit('create_skill_category', 'skill_categories', $newId);
            setFlash('success', 'Η κατηγορία <strong>' . h($name) . '</strong> προστέθηκε.');
        }
        redirect('skills.php');
    }

    if ($action === 'update_category') {
        $id      = (int) post('cat_id');
        $newName = trim(post('cat_name'));
        $cat     = dbFetchOne("SELECT * FROM skill_categories WHERE id = ?", [$id]);
        if (!$cat) {
            setFlash('error', 'Η κατηγορία δεν βρέθηκε.');
        } elseif (!$newName) {
            setFlash('error', 'Το όνομα κατηγορίας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skill_categories WHERE name = ? AND id != ?", [$newName, $id])) {
            setFlash('error', 'Υπάρχει ήδη κατηγορία με αυτό το όνομα.');
        } else {
            dbExecute("UPDATE skill_categories SET name = ? WHERE id = ?", [$newName, $id]);
            dbExecute("UPDATE skills SET category = ? WHERE category = ?", [$newName, $cat['name']]);
            logAudit('update_skill_category', 'skill_categories', $id);
            setFlash('success', 'Η κατηγορία μετονομάστηκε σε <strong>' . h($newName) . '</strong>.');
        }
        redirect('skills.php');
    }

    if ($action === 'delete_category') {
        $id  = (int) post('cat_id');
        $cat = dbFetchOne("SELECT * FROM skill_categories WHERE id = ?", [$id]);
        if (!$cat) {
            setFlash('error', 'Η κατηγορία δεν βρέθηκε.');
        } else {
            dbExecute("UPDATE skills SET category = NULL WHERE category = ?", [$cat['name']]);
            dbExecute("DELETE FROM skill_categories WHERE id = ?", [$id]);
            logAudit('delete_skill_category', 'skill_categories', $id);
            setFlash('success', 'Η κατηγορία <strong>' . h($cat['name']) . '</strong> διαγράφηκε.');
        }
        redirect('skills.php');
    }

    // ════ SKILL ACTIONS ══════════════════════════════════════════════════════

    if ($action === 'create_skill') {
        $name    = trim(post('name'));
        $catId   = (int) post('category_id') ?: null;
        $catName = null;
        if ($catId) {
            $catRow  = dbFetchOne("SELECT name FROM skill_categories WHERE id = ?", [$catId]);
            $catName = $catRow ? $catRow['name'] : null;
        }
        if (!$name) {
            setFlash('error', 'Το όνομα δεξιότητας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skills WHERE name = ?", [$name])) {
            setFlash('error', 'Υπάρχει ήδη δεξιότητα με αυτό το όνομα.');
        } else {
            $newId = dbInsert("INSERT INTO skills (name, category) VALUES (?, ?)", [$name, $catName]);
            logAudit('create_skill', 'skills', $newId);
            setFlash('success', 'Η δεξιότητα <strong>' . h($name) . '</strong> προστέθηκε.');
        }
        redirect('skills.php');
    }

    if ($action === 'update_skill') {
        $id      = (int) post('skill_id');
        $name    = trim(post('name'));
        $catId   = (int) post('category_id') ?: null;
        $catName = null;
        if ($catId) {
            $catRow  = dbFetchOne("SELECT name FROM skill_categories WHERE id = ?", [$catId]);
            $catName = $catRow ? $catRow['name'] : null;
        }
        $skill = dbFetchOne("SELECT * FROM skills WHERE id = ?", [$id]);
        if (!$skill) {
            setFlash('error', 'Η δεξιότητα δεν βρέθηκε.');
        } elseif (!$name) {
            setFlash('error', 'Το όνομα δεξιότητας είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM skills WHERE name = ? AND id != ?", [$name, $id])) {
            setFlash('error', 'Υπάρχει ήδη δεξιότητα με αυτό το όνομα.');
        } else {
            dbExecute("UPDATE skills SET name = ?, category = ? WHERE id = ?", [$name, $catName, $id]);
            logAudit('update_skill', 'skills', $id);
            setFlash('success', 'Η δεξιότητα ενημερώθηκε.');
        }
        redirect('skills.php');
    }

    if ($action === 'delete_skill') {
        $id    = (int) post('skill_id');
        $skill = dbFetchOne("SELECT * FROM skills WHERE id = ?", [$id]);
        if (!$skill) {
            setFlash('error', 'Η δεξιότητα δεν βρέθηκε.');
        } else {
            dbExecute("DELETE FROM skills WHERE id = ?", [$id]);
            logAudit('delete_skill', 'skills', $id);
            setFlash('success', 'Η δεξιότητα <strong>' . h($skill['name']) . '</strong> διαγράφηκε.');
        }
        redirect('skills.php');
    }
}

// ─── Data ──────────────────────────────────────────────────────────────────────
$categories = dbFetchAll(
    "SELECT sc.*, COUNT(s.id) AS skill_count
     FROM skill_categories sc
     LEFT JOIN skills s ON s.category = sc.name
     GROUP BY sc.id
     ORDER BY sc.name"
);

$skills = dbFetchAll(
    "SELECT s.*, COUNT(us.user_id) AS volunteer_count
     FROM skills s
     LEFT JOIN user_skills us ON s.id = us.skill_id
     GROUP BY s.id
     ORDER BY s.category, s.name"
);

// Group skills by category for display
$grouped = [];
foreach ($skills as $sk) {
    $cat = $sk['category'] ?: '— Χωρίς κατηγορία';
    $grouped[$cat][] = $sk;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-stars me-2"></i>Δεξιότητες</h1>
        <p class="text-muted mb-0">Διαχείριση κατηγοριών και δεξιοτήτων εθελοντών</p>
    </div>
    <?php if (isAdmin()): ?>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
            <i class="bi bi-folder-plus me-1"></i>Νέα Κατηγορία
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSkillModal">
            <i class="bi bi-plus-circle me-1"></i>Νέα Δεξιότητα
        </button>
    </div>
    <?php endif; ?>
</div>

<?= showFlash() ?>

<!-- ─── Categories Card ─────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-folder2-open text-secondary"></i>
        <strong>Κατηγορίες Δεξιοτήτων</strong>
        <span class="badge bg-secondary ms-1"><?= count($categories) ?></span>
    </div>
    <?php if (empty($categories)): ?>
    <div class="card-body text-muted">Δεν υπάρχουν κατηγορίες ακόμα.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Κατηγορία</th>
                    <th class="text-center">Δεξιότητες</th>
                    <?php if (isAdmin()): ?><th class="text-end pe-3">Ενέργειες</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr>
                    <td class="fw-semibold"><i class="bi bi-tag me-1 text-muted"></i><?= h($cat['name']) ?></td>
                    <td class="text-center"><span class="badge bg-primary"><?= $cat['skill_count'] ?></span></td>
                    <?php if (isAdmin()): ?>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                data-bs-target="#editCatModal<?= $cat['id'] ?>" title="Μετονομασία">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#deleteCatModal<?= $cat['id'] ?>" title="Διαγραφή">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ─── Skills grouped by category ────────────────────────────────────────── -->
<?php if (empty($skills)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν δεξιότητες. Προσθέστε την πρώτη!
    </div>
<?php else: ?>

<div class="d-flex gap-3 mb-3">
    <div class="card border-0 bg-primary bg-opacity-10 px-3 py-2">
        <small class="text-muted">Δεξιότητες</small>
        <div class="fw-bold fs-5"><?= count($skills) ?></div>
    </div>
    <div class="card border-0 bg-success bg-opacity-10 px-3 py-2">
        <small class="text-muted">Κατηγορίες</small>
        <div class="fw-bold fs-5"><?= count($categories) ?></div>
    </div>
    <div class="card border-0 bg-info bg-opacity-10 px-3 py-2">
        <small class="text-muted">Αναθέσεις σε εθελοντές</small>
        <div class="fw-bold fs-5"><?= array_sum(array_column($skills, 'volunteer_count')) ?></div>
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
                    <?php if (isAdmin()): ?><th class="text-end pe-3">Ενέργειες</th><?php endif; ?>
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
                    <?php if (isAdmin()): ?>
                    <td class="text-end pe-3">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                data-bs-target="#editSkillModal<?= $sk['id'] ?>" title="Επεξεργασία">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#deleteSkillModal<?= $sk['id'] ?>" title="Διαγραφή">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>


<?php if (isAdmin()): ?>
<!-- ════ CATEGORY MODALS ════════════════════════════════════════════════════ -->

<!-- Create Category -->
<div class="modal fade" id="createCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_category">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Νέα Κατηγορία</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                    <input type="text" name="cat_name" class="form-control" required maxlength="100"
                           placeholder="π.χ. Υγεία, Τεχνικά, Γενικά">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Προσθήκη</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit / Delete Category (per category) -->
<?php foreach ($categories as $cat): ?>
<div class="modal fade" id="editCatModal<?= $cat['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Επεξεργασία Κατηγορίας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label fw-semibold">Νέο Όνομα <span class="text-danger">*</span></label>
                    <input type="text" name="cat_name" class="form-control" required maxlength="100"
                           value="<?= h($cat['name']) ?>">
                    <div class="form-text">Θα ενημερωθούν αυτόματα και οι <?= $cat['skill_count'] ?> δεξιότητες που ανήκουν εδώ.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteCatModal<?= $cat['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="cat_id" value="<?= $cat['id'] ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Κατηγορίας</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Πρόκειται να διαγράψετε την κατηγορία <strong><?= h($cat['name']) ?></strong>.</p>
                    <?php if ($cat['skill_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Οι <strong><?= $cat['skill_count'] ?> δεξιότητες</strong> που ανήκουν εδώ θα μείνουν χωρίς κατηγορία.
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Δεν ανήκουν δεξιότητες σε αυτή την κατηγορία.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ════ SKILL MODALS ════════════════════════════════════════════════════════ -->

<!-- Create Skill -->
<div class="modal fade" id="createSkillModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_skill">
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
                        <select name="category_id" class="form-select">
                            <option value="">— Χωρίς κατηγορία —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($categories)): ?>
                        <div class="form-text text-warning">
                            <i class="bi bi-info-circle me-1"></i>Δεν υπάρχουν κατηγορίες.
                            <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#createCategoryModal">Δημιουργήστε πρώτα μία.</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Προσθήκη</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit & Delete Skill (per skill) -->
<?php foreach ($skills as $sk):
    $currentCatId = null;
    foreach ($categories as $cat) {
        if ($cat['name'] === $sk['category']) { $currentCatId = $cat['id']; break; }
    }
?>
<div class="modal fade" id="editSkillModal<?= $sk['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_skill">
                <input type="hidden" name="skill_id" value="<?= $sk['id'] ?>">
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
                        <select name="category_id" class="form-select">
                            <option value="">— Χωρίς κατηγορία —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $currentCatId === $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteSkillModal<?= $sk['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_skill">
                <input type="hidden" name="skill_id" value="<?= $sk['id'] ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Δεξιότητας</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Πρόκειται να διαγράψετε τη δεξιότητα <strong><?= h($sk['name']) ?></strong>.</p>
                    <?php if ($sk['volunteer_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong><?= $sk['volunteer_count'] ?> εθελοντές</strong> έχουν αυτή τη δεξιότητα. Η ανάθεσή τους θα αφαιρεθεί.
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Δεν έχει ανατεθεί σε κανέναν εθελοντή.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; // isAdmin ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

