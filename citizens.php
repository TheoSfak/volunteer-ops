<?php
/**
 * VolunteerOps - Citizens Management (Πολίτες)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Λίστα Πολιτών';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
        case 'update':
            $id = (int) post('citizen_id');
            $data = [
                trim(post('first_name_gr')),
                trim(post('last_name_gr')),
                trim(post('first_name_lat')),
                trim(post('last_name_lat')),
                post('birth_date') ?: null,
                trim(post('email')) ?: null,
                trim(post('phone')) ?: null,
                post('contact_done') ? 1 : 0,
                post('payment_done') ? 1 : 0,
                post('completed') ? 1 : 0,
                trim(post('notes')) ?: null,
            ];

            if (empty($data[0]) || empty($data[1])) {
                setFlash('error', 'Τα πεδία Όνομα και Επίθετο (Ελληνικά) είναι υποχρεωτικά.');
                redirect('citizens.php');
            }

            if ($action === 'update' && $id > 0) {
                dbExecute(
                    "UPDATE citizens SET first_name_gr=?, last_name_gr=?, first_name_lat=?, last_name_lat=?,
                     birth_date=?, email=?, phone=?, contact_done=?, payment_done=?, completed=?, notes=?,
                     updated_at=NOW() WHERE id=?",
                    array_merge($data, [$id])
                );
                logAudit('update', 'citizens', $id);
                setFlash('success', 'Ο πολίτης ενημερώθηκε επιτυχώς.');
            } else {
                $data[] = getCurrentUserId();
                $newId = dbInsert(
                    "INSERT INTO citizens (first_name_gr, last_name_gr, first_name_lat, last_name_lat,
                     birth_date, email, phone, contact_done, payment_done, completed, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $data
                );
                logAudit('create', 'citizens', $newId);
                setFlash('success', 'Ο πολίτης προστέθηκε επιτυχώς.');
            }
            redirect('citizens.php');
            break;

        case 'delete':
            $id = (int) post('citizen_id');
            if ($id > 0) {
                dbExecute("DELETE FROM citizens WHERE id = ?", [$id]);
                logAudit('delete', 'citizens', $id);
                setFlash('success', 'Ο πολίτης διαγράφηκε.');
            }
            redirect('citizens.php');
            break;

        case 'toggle_contact':
        case 'toggle_payment':
        case 'toggle_completed':
            $id = (int) post('citizen_id');
            $field = str_replace('toggle_', '', $action);
            $fieldMap = [
                'contact' => 'contact_done',
                'payment' => 'payment_done',
                'completed' => 'completed',
            ];
            if ($id > 0 && isset($fieldMap[$field])) {
                $col = $fieldMap[$field];
                dbExecute("UPDATE citizens SET {$col} = IF({$col}=1, 0, 1), updated_at=NOW() WHERE id = ?", [$id]);
                logAudit('update', 'citizens', $id);
            }
            redirect('citizens.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            break;
    }
}

// Filters
$search = get('search', '');
$filterContact = get('contact', '');
$filterPayment = get('payment', '');
$filterCompleted = get('completed', '');
$page = max(1, (int) get('page', 1));
$perPage = 20;

$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(first_name_gr LIKE ? OR last_name_gr LIKE ? OR first_name_lat LIKE ? OR last_name_lat LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params = array_merge($params, array_fill(0, 6, "%{$search}%"));
}
if ($filterContact !== '') {
    $where[] = "contact_done = ?";
    $params[] = (int) $filterContact;
}
if ($filterPayment !== '') {
    $where[] = "payment_done = ?";
    $params[] = (int) $filterPayment;
}
if ($filterCompleted !== '') {
    $where[] = "completed = ?";
    $params[] = (int) $filterCompleted;
}

$whereClause = implode(' AND ', $where);
$total = dbFetchValue("SELECT COUNT(*) FROM citizens WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

$citizens = dbFetchAll(
    "SELECT * FROM citizens WHERE $whereClause ORDER BY last_name_gr, first_name_gr LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// For edit modal
$editCitizen = null;
$editId = (int) get('edit');
if ($editId) {
    $editCitizen = dbFetchOne("SELECT * FROM citizens WHERE id = ?", [$editId]);
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-vcard"></i> Λίστα Πολιτών</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#citizenModal" onclick="resetForm()">
        <i class="bi bi-plus-lg"></i> Νέος Πολίτης
    </button>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Αναζήτηση</label>
                <input type="text" name="search" class="form-control" placeholder="Όνομα, email, τηλέφωνο..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Επικοινωνία</label>
                <select name="contact" class="form-select">
                    <option value="">Όλα</option>
                    <option value="1" <?= $filterContact === '1' ? 'selected' : '' ?>>Ναι</option>
                    <option value="0" <?= $filterContact === '0' ? 'selected' : '' ?>>Όχι</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Πληρωμή</label>
                <select name="payment" class="form-select">
                    <option value="">Όλα</option>
                    <option value="1" <?= $filterPayment === '1' ? 'selected' : '' ?>>Ναι</option>
                    <option value="0" <?= $filterPayment === '0' ? 'selected' : '' ?>>Όχι</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Ολοκληρώθηκε</label>
                <select name="completed" class="form-select">
                    <option value="">Όλα</option>
                    <option value="1" <?= $filterCompleted === '1' ? 'selected' : '' ?>>Ναι</option>
                    <option value="0" <?= $filterCompleted === '0' ? 'selected' : '' ?>>Όχι</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Φίλτρο</button>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list"></i> Σύνολο: <strong><?= $total ?></strong> πολίτες</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Όνομα (GR)</th>
                        <th>Επίθετο (GR)</th>
                        <th>Όνομα (LAT)</th>
                        <th>Επίθετο (LAT)</th>
                        <th>Ημ. Γέννησης</th>
                        <th>Email</th>
                        <th>Τηλέφωνο</th>
                        <th class="text-center">Επικοινωνία</th>
                        <th class="text-center">Πληρωμή</th>
                        <th class="text-center">Ολοκλήρωση</th>
                        <th class="text-center">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($citizens)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">Δεν βρέθηκαν πολίτες.</td></tr>
                    <?php else: ?>
                    <?php foreach ($citizens as $i => $c): ?>
                    <tr>
                        <td><?= $pagination['offset'] + $i + 1 ?></td>
                        <td><?= h($c['first_name_gr']) ?></td>
                        <td><?= h($c['last_name_gr']) ?></td>
                        <td><?= h($c['first_name_lat'] ?? '') ?></td>
                        <td><?= h($c['last_name_lat'] ?? '') ?></td>
                        <td><?= $c['birth_date'] ? formatDate($c['birth_date']) : '-' ?></td>
                        <td><?= h($c['email'] ?? '-') ?></td>
                        <td><?= h($c['phone'] ?? '-') ?></td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_contact">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0" title="Εναλλαγή">
                                    <i class="bi <?= $c['contact_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_payment">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0" title="Εναλλαγή">
                                    <i class="bi <?= $c['payment_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_completed">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0" title="Εναλλαγή">
                                    <i class="bi <?= $c['completed'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick="editCitizen(<?= h(json_encode($c)) ?>)" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $c['id'] ?>" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                            <!-- Delete Modal -->
                            <div class="modal fade" id="deleteModal<?= $c['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Διαγραφή Πολίτη</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            Είστε σίγουροι ότι θέλετε να διαγράψετε τον πολίτη
                                            <strong><?= h($c['last_name_gr'] . ' ' . $c['first_name_gr']) ?></strong>;
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                                <button type="submit" class="btn btn-danger">Διαγραφή</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer">
        <?= paginationLinks($pagination) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Create / Edit Modal -->
<div class="modal fade" id="citizenModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="citizenForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="citizen_id" id="formCitizenId" value="0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Νέος Πολίτης</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Όνομα (Ελληνικά) <span class="text-danger">*</span></label>
                            <input type="text" name="first_name_gr" id="first_name_gr" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Επίθετο (Ελληνικά) <span class="text-danger">*</span></label>
                            <input type="text" name="last_name_gr" id="last_name_gr" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Όνομα (Λατινικά)</label>
                            <input type="text" name="first_name_lat" id="first_name_lat" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Επίθετο (Λατινικά)</label>
                            <input type="text" name="last_name_lat" id="last_name_lat" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ημερομηνία Γέννησης</label>
                            <input type="date" name="birth_date" id="birth_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" id="citizen_email" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Τηλέφωνο</label>
                            <input type="text" name="phone" id="citizen_phone" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Σημειώσεις</label>
                            <textarea name="notes" id="citizen_notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="contact_done" id="contact_done" value="1">
                                <label class="form-check-label" for="contact_done">Επικοινωνία</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="payment_done" id="payment_done" value="1">
                                <label class="form-check-label" for="payment_done">Πληρωμή</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="completed" id="completed_cb" value="1">
                                <label class="form-check-label" for="completed_cb">Έχει ολοκληρώσει</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formCitizenId').value = '0';
    document.getElementById('modalTitle').textContent = 'Νέος Πολίτης';
    document.getElementById('citizenForm').reset();
}

function editCitizen(c) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formCitizenId').value = c.id;
    document.getElementById('modalTitle').textContent = 'Επεξεργασία Πολίτη';
    document.getElementById('first_name_gr').value = c.first_name_gr || '';
    document.getElementById('last_name_gr').value = c.last_name_gr || '';
    document.getElementById('first_name_lat').value = c.first_name_lat || '';
    document.getElementById('last_name_lat').value = c.last_name_lat || '';
    document.getElementById('birth_date').value = c.birth_date || '';
    document.getElementById('citizen_email').value = c.email || '';
    document.getElementById('citizen_phone').value = c.phone || '';
    document.getElementById('citizen_notes').value = c.notes || '';
    document.getElementById('contact_done').checked = c.contact_done == 1;
    document.getElementById('payment_done').checked = c.payment_done == 1;
    document.getElementById('completed_cb').checked = c.completed == 1;

    var modal = new bootstrap.Modal(document.getElementById('citizenModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
