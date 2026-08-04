<?php
/**
 * VolunteerOps - Ομάδες Εθελοντών (Home Teams) Management
 * A volunteer's home rescue-team (e.g. "Επίδραση" for regular members, or a
 * partner org for guests) — the color+flag badge shown next to their name
 * app-wide. NOT the same as the per-mission Action Room squads managed
 * inside war-room.php.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ομάδες Εθελοντών';

$teams = dbFetchAll(
    "SELECT vt.*, (SELECT COUNT(*) FROM users u WHERE u.volunteer_team_id = vt.id) as users_count
     FROM volunteer_teams vt
     ORDER BY vt.is_default DESC, vt.name"
);

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
        case 'update':
            $id = post('id');
            $name = trim((string) post('name'));
            $color = post('color', '#6c757d');
            $existing = $id ? dbFetchOne("SELECT * FROM volunteer_teams WHERE id = ?", [$id]) : null;
            // The seeded default team is always active — never let it be
            // switched off, even if the request tampered with the field.
            $isActive = ($existing && $existing['is_default']) ? 1 : (isset($_POST['is_active']) ? 1 : 0);

            if ($name === '') {
                setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                setFlash('error', 'Μη έγκυρο χρώμα.');
            } else {
                $dupe = dbFetchOne("SELECT id FROM volunteer_teams WHERE name = ? AND id != ?", [$name, $id ?: 0]);
                if ($dupe) {
                    setFlash('error', 'Υπάρχει ήδη ομάδα με αυτό το όνομα.');
                } elseif ($id) {
                    dbExecute(
                        "UPDATE volunteer_teams SET name = ?, color = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                        [$name, $color, $isActive, $id]
                    );
                    logAudit('update', 'volunteer_teams', $id);
                    setFlash('success', 'Η ομάδα ενημερώθηκε.');
                } else {
                    $newId = dbInsert(
                        "INSERT INTO volunteer_teams (name, color, is_default, is_active, created_at, updated_at) VALUES (?, ?, 0, ?, NOW(), NOW())",
                        [$name, $color, $isActive]
                    );
                    logAudit('create', 'volunteer_teams', $newId);
                    setFlash('success', 'Η ομάδα δημιουργήθηκε.');
                }
            }
            break;

        case 'delete':
            $id = post('id');
            $team = dbFetchOne("SELECT * FROM volunteer_teams WHERE id = ?", [$id]);

            if ($team) {
                if ($team['is_default']) {
                    setFlash('error', 'Η προεπιλεγμένη ομάδα δεν μπορεί να διαγραφεί.');
                } else {
                    $usersCount = dbFetchValue("SELECT COUNT(*) FROM users WHERE volunteer_team_id = ?", [$id]);
                    if ($usersCount > 0) {
                        setFlash('error', 'Δεν μπορείτε να διαγράψετε ομάδα με εθελοντές.');
                    } else {
                        dbExecute("DELETE FROM volunteer_teams WHERE id = ?", [$id]);
                        logAudit('delete', 'volunteer_teams', $id);
                        setFlash('success', 'Η ομάδα διαγράφηκε.');
                    }
                }
            }
            break;
    }

    redirect('volunteer-teams.php');
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="bi bi-flag me-2"></i>Ομάδες Εθελοντών
        </h1>
        <p class="text-muted mb-0 mt-1">Η ομάδα-«σπίτι» κάθε εθελοντή (π.χ. «Επίδραση» για τα τακτικά μέλη, ή μία ομάδα-συνεργάτης για guest λογαριασμούς) — καθορίζει το έγχρωμο badge δίπλα στο όνομά του παντού στην εφαρμογή. Δεν έχει σχέση με τις ομάδες αποστολής μέσα στο Action Room.</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teamModal">
        <i class="bi bi-plus-lg me-1"></i>Νέα Ομάδα
    </button>
</div>

<?= showFlash() ?>

<?php if (empty($teams)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν ομάδες.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle admin-mobile-cards">
            <thead>
                <tr>
                    <th>Χρώμα</th>
                    <th>Όνομα</th>
                    <th class="text-center">Εθελοντές</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teams as $t): ?>
                    <tr class="<?= !$t['is_active'] ? 'table-secondary' : '' ?>">
                        <td data-label="Χρώμα">
                            <span class="d-inline-block rounded-circle border" style="width:22px;height:22px;background:<?= h($t['color']) ?>;"></span>
                        </td>
                        <td data-label="Όνομα">
                            <strong><?= h($t['name']) ?></strong>
                            <?php if ($t['is_default']): ?><span class="badge bg-secondary ms-1">Προεπιλογή</span><?php endif; ?>
                        </td>
                        <td data-label="Εθελοντές" class="text-center">
                            <span class="badge bg-secondary"><?= $t['users_count'] ?></span>
                        </td>
                        <td data-label="Κατάσταση">
                            <?php if ($t['is_active']): ?>
                                <span class="badge bg-success">Ενεργή</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργή</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Ενέργειες" class="mobile-card-actions">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="editTeam(<?= htmlspecialchars(json_encode($t)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if (!$t['is_default'] && $t['users_count'] == 0): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή ομάδας;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
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

<!-- Team Modal -->
<div class="modal fade" id="teamModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="teamForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create" id="teamAction">
                <input type="hidden" name="id" id="teamId">

                <div class="modal-header">
                    <h5 class="modal-title" id="teamModalTitle">Νέα Ομάδα</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα *</label>
                        <input type="text" class="form-control" name="name" id="teamName" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Χρώμα Badge</label>
                        <div class="d-flex align-items-center gap-3">
                            <input type="color" class="form-control form-control-color" id="teamColorPicker" name="color" value="#6c757d" style="width:60px;height:38px;">
                            <span class="badge rounded-pill px-3 py-2 fs-6" id="teamColorPreview" style="background-color:#6c757d;color:#fff;">
                                Προεπισκόπηση
                            </span>
                        </div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="teamActive" checked>
                        <label class="form-check-label" for="teamActive">Ενεργή</label>
                    </div>
                    <div class="form-text" id="teamDefaultNote" style="display:none;">Η προεπιλεγμένη ομάδα παραμένει πάντα ενεργή.</div>
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
const teamColorPicker  = document.getElementById('teamColorPicker');
const teamColorPreview = document.getElementById('teamColorPreview');
const teamNameInput    = document.getElementById('teamName');
const teamActiveInput  = document.getElementById('teamActive');
const teamDefaultNote  = document.getElementById('teamDefaultNote');

teamColorPicker.addEventListener('input', () => {
    teamColorPreview.style.backgroundColor = teamColorPicker.value;
});
teamNameInput.addEventListener('input', () => {
    teamColorPreview.textContent = teamNameInput.value || 'Προεπισκόπηση';
});

function editTeam(team) {
    document.getElementById('teamAction').value = 'update';
    document.getElementById('teamId').value = team.id;
    teamNameInput.value = team.name;
    teamColorPicker.value = team.color;
    teamColorPreview.style.backgroundColor = team.color;
    teamColorPreview.textContent = team.name;
    teamActiveInput.checked = team.is_active == 1;

    const isDefault = team.is_default == 1;
    teamActiveInput.disabled = isDefault;
    teamDefaultNote.style.display = isDefault ? '' : 'none';

    document.getElementById('teamModalTitle').textContent = 'Επεξεργασία Ομάδας';
    new bootstrap.Modal(document.getElementById('teamModal')).show();
}

// Reset modal on close
document.getElementById('teamModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('teamForm').reset();
    document.getElementById('teamAction').value = 'create';
    document.getElementById('teamId').value = '';
    teamColorPicker.value = '#6c757d';
    teamColorPreview.style.backgroundColor = '#6c757d';
    teamColorPreview.textContent = 'Προεπισκόπηση';
    teamActiveInput.disabled = false;
    teamDefaultNote.style.display = 'none';
    document.getElementById('teamModalTitle').textContent = 'Νέα Ομάδα';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
