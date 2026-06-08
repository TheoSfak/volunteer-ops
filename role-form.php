<?php
/**
 * VolunteerOps - Custom Role Create/Edit Form
 * System Admin only.
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ρόλος';

$id     = (int) get('id');
$isEdit = $id > 0;
$role   = null;

if ($isEdit) {
    $role = dbFetchOne("SELECT * FROM custom_roles WHERE id = ?", [$id]);
    if (!$role) {
        setFlash('error', 'Ο ρόλος δεν βρέθηκε.');
        redirect('roles.php');
    }
    $pageTitle = 'Επεξεργασία Ρόλου: ' . $role['name'];

    // Load current permissions
    $currentPerms = dbFetchAll(
        "SELECT page_slug FROM custom_role_permissions WHERE role_id = ?",
        [$id]
    );
    $currentPerms = array_column($currentPerms, 'page_slug');
} else {
    $pageTitle    = 'Νέος Ρόλος';
    $currentPerms = [];
}

$permissionMap  = getPermissionMap();
$allSlugs       = getAllPermissionSlugs();

// Default form values
$form = [
    'name'        => $isEdit ? $role['name']        : '',
    'description' => $isEdit ? $role['description'] : '',
    'color'       => $isEdit ? $role['color']       : '#0d6efd',
    'permissions' => $isEdit ? $currentPerms        : [],
];

// ── Handle POST ──────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();

    $form['name']        = trim(post('name'));
    $form['description'] = trim(post('description'));
    $form['color']       = post('color', '#0d6efd');
    $form['permissions'] = array_filter(
        (array) ($_POST['permissions'] ?? []),
        fn($s) => in_array($s, $allSlugs, true)
    );

    $errors = [];

    if ($form['name'] === '') {
        $errors[] = 'Το όνομα ρόλου είναι υποχρεωτικό.';
    } elseif (mb_strlen($form['name']) > 100) {
        $errors[] = 'Το όνομα ρόλου δεν μπορεί να υπερβαίνει τους 100 χαρακτήρες.';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $form['color'])) {
        $form['color'] = '#0d6efd';
    }

    // Check for duplicate name (excluding self on edit)
    $existingId = dbFetchValue(
        "SELECT id FROM custom_roles WHERE name = ? AND id != ?",
        [$form['name'], $isEdit ? $id : 0]
    );
    if ($existingId) {
        $errors[] = 'Υπάρχει ήδη ρόλος με αυτό το όνομα.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            dbExecute(
                "UPDATE custom_roles SET name = ?, description = ?, color = ?, updated_at = NOW() WHERE id = ?",
                [$form['name'], $form['description'] ?: null, $form['color'], $id]
            );
            // Replace permissions
            dbExecute("DELETE FROM custom_role_permissions WHERE role_id = ?", [$id]);
            foreach ($form['permissions'] as $slug) {
                dbExecute(
                    "INSERT IGNORE INTO custom_role_permissions (role_id, page_slug) VALUES (?, ?)",
                    [$id, $slug]
                );
            }
            logAudit('update_custom_role', 'custom_roles', $id);
            setFlash('success', 'Ο ρόλος «' . $form['name'] . '» ενημερώθηκε.');
        } else {
            $newId = dbInsert(
                "INSERT INTO custom_roles (name, description, color, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                [$form['name'], $form['description'] ?: null, $form['color'], getCurrentUserId()]
            );
            foreach ($form['permissions'] as $slug) {
                dbExecute(
                    "INSERT IGNORE INTO custom_role_permissions (role_id, page_slug) VALUES (?, ?)",
                    [$newId, $slug]
                );
            }
            logAudit('create_custom_role', 'custom_roles', $newId);
            setFlash('success', 'Ο ρόλος «' . $form['name'] . '» δημιουργήθηκε.');
        }
        redirect('roles.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="roles.php" class="btn btn-light btn-sm">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h1 class="h3 mb-0"><i class="bi bi-shield-lock me-2"></i><?= h($pageTitle) ?></h1>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" id="roleForm">
        <?= csrfField() ?>

        <div class="row g-4">
            <!-- Left: Role details -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent fw-semibold">
                        <i class="bi bi-tag me-2"></i>Στοιχεία Ρόλου
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Όνομα Ρόλου <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name"
                                   value="<?= h($form['name']) ?>"
                                   placeholder="π.χ. Επικεφαλής Βάρδιας" required maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Περιγραφή</label>
                            <textarea class="form-control" name="description" rows="3"
                                      placeholder="Σύντομη περιγραφή αρμοδιοτήτων..."><?= h($form['description']) ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Χρώμα Badge</label>
                            <div class="d-flex align-items-center gap-3">
                                <input type="color" class="form-control form-control-color"
                                       id="colorPicker" name="color"
                                       value="<?= h($form['color']) ?>" style="width:60px;height:38px;">
                                <span class="badge rounded-pill px-3 py-2 fs-6" id="badgePreview"
                                      style="background-color:<?= h($form['color']) ?>;color:#fff;">
                                    <?= $form['name'] !== '' ? h($form['name']) : 'Προεπισκόπηση' ?>
                                </span>
                            </div>
                        </div>

                        <!-- Summary of selected permissions -->
                        <div class="border-top pt-3 mt-3">
                            <div class="text-muted small mb-1">Επιλεγμένα δικαιώματα:</div>
                            <div class="fw-bold fs-5" id="permCount">
                                <?= count($form['permissions']) ?> / <?= count($allSlugs) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Permissions -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-semibold"><i class="bi bi-key me-2"></i>Δικαιώματα Πρόσβασης</span>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-success" id="selectAll">
                                <i class="bi bi-check2-all me-1"></i>Επιλογή Όλων
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAll">
                                <i class="bi bi-x-lg me-1"></i>Αποεπιλογή Όλων
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Ο <strong>Διαχειριστής Συστήματος</strong> έχει πάντα πλήρη πρόσβαση ανεξαρτήτως ρόλου.
                            Αυτές οι ρυθμίσεις ισχύουν για <strong>εθελοντές</strong> με τον επιλεγμένο ρόλο.
                        </div>

                        <?php foreach ($permissionMap as $section => $perms): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0 text-uppercase text-muted small fw-bold tracking-wide">
                                        <?= h($section) ?>
                                    </h6>
                                    <button type="button" class="btn btn-xs btn-link text-muted p-0 section-toggle"
                                            data-section="<?= h($section) ?>">
                                        Επιλογή ενότητας
                                    </button>
                                </div>
                                <div class="row g-2">
                                    <?php foreach ($perms as $perm): ?>
                                        <?php $checked = in_array($perm['slug'], $form['permissions'], true); ?>
                                        <div class="col-sm-6 col-xl-4">
                                            <label class="d-flex align-items-center gap-2 p-2 rounded border perm-item <?= $checked ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                                                   style="cursor:pointer;" data-section="<?= h($section) ?>">
                                                <input type="checkbox"
                                                       class="form-check-input perm-checkbox mt-0 flex-shrink-0"
                                                       name="permissions[]"
                                                       value="<?= h($perm['slug']) ?>"
                                                       <?= $checked ? 'checked' : '' ?>>
                                                <div class="flex-grow-1 min-w-0">
                                                    <div class="small fw-semibold text-truncate">
                                                        <i class="bi <?= h($perm['icon']) ?> me-1"></i>
                                                        <?= h($perm['label']) ?>
                                                    </div>
                                                    <div class="text-muted" style="font-size:0.7rem;"><?= h($perm['slug']) ?></div>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-lg me-1"></i>
                <?= $isEdit ? 'Αποθήκευση Αλλαγών' : 'Δημιουργία Ρόλου' ?>
            </button>
            <a href="roles.php" class="btn btn-secondary px-4">Ακύρωση</a>
        </div>
    </form>
</div>

<script>
(function () {
    const colorPicker   = document.getElementById('colorPicker');
    const badgePreview  = document.getElementById('badgePreview');
    const nameInput     = document.querySelector('input[name="name"]');
    const permCount     = document.getElementById('permCount');
    const checkboxes    = document.querySelectorAll('.perm-checkbox');
    const totalPerms    = <?= count($allSlugs) ?>;

    // Live badge preview
    colorPicker.addEventListener('input', () => {
        badgePreview.style.backgroundColor = colorPicker.value;
    });
    nameInput.addEventListener('input', () => {
        badgePreview.textContent = nameInput.value || 'Προεπισκόπηση';
    });

    // Update permission counter + highlight
    function updateCount() {
        const checked = document.querySelectorAll('.perm-checkbox:checked').length;
        permCount.textContent = checked + ' / ' + totalPerms;
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            const label = cb.closest('label');
            if (cb.checked) {
                label.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
            } else {
                label.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
            }
            updateCount();
        });
    });

    // Select all / deselect all
    document.getElementById('selectAll').addEventListener('click', () => {
        checkboxes.forEach(cb => {
            cb.checked = true;
            cb.closest('label').classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        updateCount();
    });
    document.getElementById('deselectAll').addEventListener('click', () => {
        checkboxes.forEach(cb => {
            cb.checked = false;
            cb.closest('label').classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
        });
        updateCount();
    });

    // Section toggles
    document.querySelectorAll('.section-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const section   = btn.dataset.section;
            const sectionCbs = document.querySelectorAll(`.perm-item[data-section="${section}"] .perm-checkbox`);
            const allChecked = Array.from(sectionCbs).every(cb => cb.checked);
            sectionCbs.forEach(cb => {
                cb.checked = !allChecked;
                const label = cb.closest('label');
                if (cb.checked) {
                    label.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
                } else {
                    label.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
                }
            });
            updateCount();
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
