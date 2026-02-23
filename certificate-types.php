<?php
/**
 * VolunteerOps - Certificate Types Management
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Τύποι Πιστοποιητικών';

// ─── POST Actions ──────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create') {
        $name = trim(post('name'));
        $description = trim(post('description'));
        $validityMonths = post('default_validity_months') !== '' ? (int) post('default_validity_months') : null;
        $isRequired = isset($_POST['is_required']) ? 1 : 0;

        if (!$name) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM certificate_types WHERE name = ?", [$name])) {
            setFlash('error', 'Υπάρχει ήδη τύπος πιστοποιητικού με αυτό το όνομα.');
        } else {
            $newId = dbInsert(
                "INSERT INTO certificate_types (name, description, default_validity_months, is_required) VALUES (?, ?, ?, ?)",
                [$name, $description ?: null, $validityMonths, $isRequired]
            );
            logAudit('create_certificate_type', 'certificate_types', $newId);
            setFlash('success', 'Ο τύπος <strong>' . h($name) . '</strong> προστέθηκε.');
        }
        redirect('certificate-types.php');
    }

    if ($action === 'update') {
        $id = (int) post('type_id');
        $name = trim(post('name'));
        $description = trim(post('description'));
        $validityMonths = post('default_validity_months') !== '' ? (int) post('default_validity_months') : null;
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $type = dbFetchOne("SELECT * FROM certificate_types WHERE id = ?", [$id]);
        if (!$type) {
            setFlash('error', 'Ο τύπος δεν βρέθηκε.');
        } elseif (!$name) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
        } elseif (dbFetchOne("SELECT id FROM certificate_types WHERE name = ? AND id != ?", [$name, $id])) {
            setFlash('error', 'Υπάρχει ήδη τύπος πιστοποιητικού με αυτό το όνομα.');
        } else {
            dbExecute(
                "UPDATE certificate_types SET name = ?, description = ?, default_validity_months = ?, is_required = ?, is_active = ? WHERE id = ?",
                [$name, $description ?: null, $validityMonths, $isRequired, $isActive, $id]
            );
            logAudit('update_certificate_type', 'certificate_types', $id);
            setFlash('success', 'Ο τύπος ενημερώθηκε.');
        }
        redirect('certificate-types.php');
    }

    if ($action === 'delete') {
        $id = (int) post('type_id');
        $type = dbFetchOne("SELECT * FROM certificate_types WHERE id = ?", [$id]);
        if (!$type) {
            setFlash('error', 'Ο τύπος δεν βρέθηκε.');
        } else {
            $certCount = dbFetchValue("SELECT COUNT(*) FROM volunteer_certificates WHERE certificate_type_id = ?", [$id]);
            if ($certCount > 0) {
                setFlash('error', 'Δεν μπορείτε να διαγράψετε αυτόν τον τύπο γιατί υπάρχουν <strong>' . $certCount . '</strong> πιστοποιητικά εθελοντών που τον χρησιμοποιούν. Απενεργοποιήστε τον αντί αυτού.');
            } else {
                dbExecute("DELETE FROM certificate_types WHERE id = ?", [$id]);
                logAudit('delete_certificate_type', 'certificate_types', $id);
                setFlash('success', 'Ο τύπος <strong>' . h($type['name']) . '</strong> διαγράφηκε.');
            }
        }
        redirect('certificate-types.php');
    }
}

// ─── Data ──────────────────────────────────────────────────────────────────────
$types = dbFetchAll(
    "SELECT ct.*, COUNT(vc.id) AS cert_count,
            SUM(CASE WHEN vc.expiry_date IS NOT NULL AND vc.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count
     FROM certificate_types ct
     LEFT JOIN volunteer_certificates vc ON ct.id = vc.certificate_type_id
     GROUP BY ct.id
     ORDER BY ct.is_active DESC, ct.name"
);

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1"><i class="bi bi-award me-2"></i>Τύποι Πιστοποιητικών</h1>
        <p class="text-muted mb-0">Διαχείριση τύπων πιστοποιητικών εθελοντών</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-circle me-1"></i>Νέος Τύπος
    </button>
</div>

<?= showFlash() ?>

<!-- Stats -->
<div class="d-flex gap-3 mb-3">
    <div class="card border-0 bg-primary bg-opacity-10 px-3 py-2">
        <small class="text-muted">Σύνολο Τύπων</small>
        <div class="fw-bold fs-5"><?= count($types) ?></div>
    </div>
    <div class="card border-0 bg-success bg-opacity-10 px-3 py-2">
        <small class="text-muted">Ενεργοί</small>
        <div class="fw-bold fs-5"><?= count(array_filter($types, fn($t) => $t['is_active'])) ?></div>
    </div>
    <div class="card border-0 bg-warning bg-opacity-10 px-3 py-2">
        <small class="text-muted">Υποχρεωτικοί</small>
        <div class="fw-bold fs-5"><?= count(array_filter($types, fn($t) => $t['is_required'])) ?></div>
    </div>
</div>

<!-- Types Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Τύπος</th>
                    <th>Περιγραφή</th>
                    <th class="text-center">Ισχύς (μήνες)</th>
                    <th class="text-center">Υποχρεωτικό</th>
                    <th class="text-center">Κατάσταση</th>
                    <th class="text-center">Πιστοποιητικά</th>
                    <th class="text-end pe-3">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($types)): ?>
                    <tr><td colspan="7" class="text-muted text-center py-4">Δεν υπάρχουν τύποι πιστοποιητικών.</td></tr>
                <?php else: ?>
                    <?php foreach ($types as $t): ?>
                    <tr class="<?= !$t['is_active'] ? 'table-secondary' : '' ?>">
                        <td class="fw-semibold">
                            <i class="bi bi-award me-1 <?= $t['is_required'] ? 'text-danger' : 'text-primary' ?>"></i>
                            <?= h($t['name']) ?>
                        </td>
                        <td class="text-muted small"><?= h($t['description'] ?? '—') ?></td>
                        <td class="text-center">
                            <?php if ($t['default_validity_months']): ?>
                                <span class="badge bg-info"><?= $t['default_validity_months'] ?> μήνες</span>
                            <?php else: ?>
                                <span class="text-muted">Αόριστη</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($t['is_required']): ?>
                                <span class="badge bg-danger">Ναι</span>
                            <?php else: ?>
                                <span class="text-muted">Όχι</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($t['is_active']): ?>
                                <span class="badge bg-success">Ενεργός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργός</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($t['cert_count'] > 0): ?>
                                <span class="badge bg-primary"><?= $t['cert_count'] ?></span>
                                <?php if ($t['expired_count'] > 0): ?>
                                    <span class="badge bg-danger" title="Ληγμένα"><?= $t['expired_count'] ?> ληγμ.</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-3">
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

<!-- ════ CREATE MODAL ═══════════════════════════════════════════════════════ -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Νέος Τύπος Πιστοποιητικού</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               placeholder="π.χ. BLS/AED">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Περιγραφή</label>
                        <textarea name="description" class="form-control" rows="2" 
                                  placeholder="Σύντομη περιγραφή (προαιρετικό)"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ισχύς (μήνες)</label>
                        <input type="number" name="default_validity_months" class="form-control" min="1" max="600"
                               placeholder="Κενό = Αόριστη ισχύς">
                        <div class="form-text">Αφήστε κενό αν το πιστοποιητικό δεν λήγει (π.χ. δίπλωμα οδήγησης).</div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_required" id="createRequired">
                        <label class="form-check-label" for="createRequired">
                            Υποχρεωτικό πιστοποιητικό
                        </label>
                        <div class="form-text">Θα εμφανίζεται ως «ελλείπον» σε εθελοντές που δεν το έχουν.</div>
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
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Επεξεργασία Τύπου</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="150"
                               value="<?= h($t['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Περιγραφή</label>
                        <textarea name="description" class="form-control" rows="2"><?= h($t['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ισχύς (μήνες)</label>
                        <input type="number" name="default_validity_months" class="form-control" min="1" max="600"
                               value="<?= h($t['default_validity_months'] ?? '') ?>">
                        <div class="form-text">Κενό = αόριστη ισχύς</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_required" id="editRequired<?= $t['id'] ?>"
                               <?= $t['is_required'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="editRequired<?= $t['id'] ?>">Υποχρεωτικό πιστοποιητικό</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editActive<?= $t['id'] ?>"
                               <?= $t['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="editActive<?= $t['id'] ?>">Ενεργός</label>
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
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Τύπου</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Πρόκειται να διαγράψετε τον τύπο <strong><?= h($t['name']) ?></strong>.</p>
                    <?php if ($t['cert_count'] > 0): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Υπάρχουν <strong><?= $t['cert_count'] ?> πιστοποιητικά</strong> εθελοντών με αυτόν τον τύπο.
                        Η διαγραφή δεν θα επιτραπεί. Μπορείτε να τον απενεργοποιήσετε.
                    </div>
                    <?php else: ?>
                    <p class="text-muted">Δεν υπάρχουν πιστοποιητικά εθελοντών με αυτόν τον τύπο.</p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger" <?= $t['cert_count'] > 0 ? 'disabled' : '' ?>>
                        <i class="bi bi-trash me-1"></i>Διαγραφή
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
