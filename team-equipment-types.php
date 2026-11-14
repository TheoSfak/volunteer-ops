<?php
/**
 * VolunteerOps - Team Equipment Types Management (v3.488.0)
 *
 * The catalog an Action Room team's equipment chips are drawn from (AED, a stretcher, ...) - add, edit, delete.
 * System admin only: the catalog is shared by every mission, not a mission's own setting. Used by the Teams card
 * (war-room.php), the «Ομάδες» page (mission-teams-view.php) and mission-team-equipment.php.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Υλικό Ομάδων';

// ─── POST Actions ──────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create') {
        $name = trim(post('name'));
        $icon = trim(post('icon')) ?: '🎒';

        if (!$name) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM team_equipment_types WHERE name = ?", [$name])) {
            setFlash('error', 'Υπάρχει ήδη τύπος υλικού με αυτό το όνομα.');
        } else {
            $nextOrder = (int) dbFetchValue("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM team_equipment_types");
            $newId = dbInsert(
                "INSERT INTO team_equipment_types (name, icon, sort_order, created_by) VALUES (?, ?, ?, ?)",
                [$name, $icon, $nextOrder, getCurrentUserId()]
            );
            logAudit('create_team_equipment_type', 'team_equipment_types', $newId);
            setFlash('success', 'Ο τύπος υλικού <strong>' . h($name) . '</strong> προστέθηκε.');
        }
        redirect('team-equipment-types.php');
    }

    if ($action === 'update') {
        $id = (int) post('type_id');
        $name = trim(post('name'));
        $icon = trim(post('icon')) ?: '🎒';
        $sortOrder = (int) post('sort_order');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $type = dbFetchOne("SELECT * FROM team_equipment_types WHERE id = ?", [$id]);
        if (!$type) {
            setFlash('error', 'Ο τύπος δεν βρέθηκε.');
        } elseif (!$name) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM team_equipment_types WHERE name = ? AND id != ?", [$name, $id])) {
            setFlash('error', 'Υπάρχει ήδη τύπος υλικού με αυτό το όνομα.');
        } else {
            dbExecute(
                "UPDATE team_equipment_types SET name = ?, icon = ?, sort_order = ?, is_active = ? WHERE id = ?",
                [$name, $icon, $sortOrder, $isActive, $id]
            );
            logAudit('update_team_equipment_type', 'team_equipment_types', $id);
            setFlash('success', 'Ο τύπος υλικού ενημερώθηκε.');
        }
        redirect('team-equipment-types.php');
    }

    if ($action === 'delete') {
        $id = (int) post('type_id');
        $type = dbFetchOne("SELECT * FROM team_equipment_types WHERE id = ?", [$id]);
        if (!$type) {
            setFlash('error', 'Ο τύπος δεν βρέθηκε.');
        } else {
            // No "in use" block, unlike certificate-types.php: this is a live field tag, not an audit record, and
            // the pivot's ON DELETE CASCADE already drops it off every team cleanly.
            $teamCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_team_equipment WHERE equipment_type_id = ?", [$id]);
            dbExecute("DELETE FROM team_equipment_types WHERE id = ?", [$id]);
            logAudit('delete_team_equipment_type', 'team_equipment_types', $id, ['teams_affected' => $teamCount]);
            setFlash('success', 'Ο τύπος υλικού <strong>' . h($type['name']) . '</strong> διαγράφηκε.' . ($teamCount > 0 ? ' Αφαιρέθηκε από ' . $teamCount . ' ομάδ' . ($teamCount === 1 ? 'α' : 'ες') . '.' : ''));
        }
        redirect('team-equipment-types.php');
    }
}

// ─── Data ──────────────────────────────────────────────────────────────────────
$types = dbFetchAll(
    "SELECT tet.*, COUNT(mte.team_id) AS teams_count
       FROM team_equipment_types tet
       LEFT JOIN mission_team_equipment mte ON mte.equipment_type_id = tet.id
      GROUP BY tet.id
      ORDER BY tet.is_active DESC, tet.sort_order, tet.name"
);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-bag-heart-fill me-2"></i>Υλικό Ομάδων</h1>
        <p class="text-muted mb-0">Ο κατάλογος εξοπλισμού (AED, φορείο, ...) που μπορεί να έχει μια ομάδα στο Action Room</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-circle me-1"></i>Νέος Τύπος
    </button>
</div>

<?= showFlash() ?>

<!-- Types Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 admin-mobile-cards">
            <thead class="table-light">
                <tr>
                    <th>Υλικό</th>
                    <th class="text-center">Σειρά</th>
                    <th class="text-center">Κατάσταση</th>
                    <th class="text-center">Ομάδες που το έχουν τώρα</th>
                    <th class="text-end pe-3">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($types)): ?>
                    <tr><td colspan="5" class="text-muted text-center py-4">Δεν υπάρχουν τύποι υλικού.</td></tr>
                <?php else: ?>
                    <?php foreach ($types as $t): ?>
                    <tr class="<?= !$t['is_active'] ? 'table-secondary' : '' ?>">
                        <td data-label="Υλικό" class="fw-semibold"><?= h($t['icon']) ?> <?= h($t['name']) ?></td>
                        <td data-label="Σειρά" class="text-center text-muted"><?= (int) $t['sort_order'] ?></td>
                        <td data-label="Κατάσταση" class="text-center">
                            <?php if ($t['is_active']): ?>
                                <span class="badge bg-success">Ενεργό</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργό</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ομάδες" class="text-center">
                            <?php if ($t['teams_count'] > 0): ?>
                                <span class="badge bg-primary"><?= (int) $t['teams_count'] ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ενέργειες" class="text-end pe-3 mobile-card-actions">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                                    data-bs-target="#editModal<?= $t['id'] ?>" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                    data-bs-target="#deleteModal<?= $t['id'] ?>" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
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

<!-- ════ CREATE MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Νέος Τύπος Υλικού</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-3">
                            <label class="form-label fw-semibold">Εικονίδιο</label>
                            <input type="text" name="icon" class="form-control text-center" maxlength="10" value="🎒" placeholder="🎒">
                        </div>
                        <div class="col-9">
                            <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="100"
                                   placeholder="π.χ. Φορείο - Scoop">
                        </div>
                    </div>
                    <div class="form-text mt-2">Το εικονίδιο μπορεί να είναι ένα emoji (π.χ. ⚡, 🛏️, 📏) - εμφανίζεται δίπλα στο όνομα παντού.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Προσθήκη</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════ EDIT & DELETE MODALS (per type) ════════════════════════════════════ -->
<?php foreach ($types as $t): ?>
<div class="modal fade" id="editModal<?= $t['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Επεξεργασία Τύπου Υλικού</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-3">
                            <label class="form-label fw-semibold">Εικονίδιο</label>
                            <input type="text" name="icon" class="form-control text-center" maxlength="10" value="<?= h($t['icon']) ?>">
                        </div>
                        <div class="col-9">
                            <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="100" value="<?= h($t['name']) ?>">
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label fw-semibold">Σειρά εμφάνισης</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= (int) $t['sort_order'] ?>">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editActive<?= $t['id'] ?>"
                               <?= $t['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="editActive<?= $t['id'] ?>">Ενεργό</label>
                        <div class="form-text">Ανενεργό = δεν εμφανίζεται πλέον σαν επιλογή, αλλά οι ομάδες που το έχουν ήδη δεν αλλάζουν.</div>
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

<div class="modal fade" id="deleteModal<?= $t['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Τύπου Υλικού</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Πρόκειται να διαγράψετε τον τύπο <strong><?= h($t['icon']) ?> <?= h($t['name']) ?></strong>.</p>
                    <?php if ($t['teams_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Θα αφαιρεθεί αμέσως από <strong><?= (int) $t['teams_count'] ?> ομάδ<?= (int) $t['teams_count'] === 1 ? 'α' : 'ες' ?></strong> που το έχουν τώρα.
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Καμία ομάδα δεν το έχει τώρα.</p>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
