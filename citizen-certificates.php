<?php
/**
 * VolunteerOps - Citizen Certificates (Πιστοποιητικά Πολιτών)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Πιστοποιητικά Πολιτών';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
        case 'update':
            $id = (int) post('cert_id');
            $certificateTypeId = (int) post('certificate_type_id') ?: null;
            $firstName = trim(post('first_name'));
            $lastName = trim(post('last_name'));
            $fatherName = trim(post('father_name')) ?: null;
            $birthDate = post('birth_date') ?: null;
            $issueDate = post('issue_date') ?: null;
            $expiryDate = post('expiry_date') ?: null;
            $notes = trim(post('notes')) ?: null;

            if (empty($firstName) || empty($lastName)) {
                setFlash('error', 'Τα πεδία Όνομα και Επίθετο είναι υποχρεωτικά.');
                redirect('citizen-certificates.php');
            }

            $data = [$certificateTypeId, $firstName, $lastName, $fatherName, $birthDate, $issueDate, $expiryDate, $notes];

            if ($action === 'update' && $id > 0) {
                dbExecute(
                    "UPDATE citizen_certificates SET certificate_type_id=?, first_name=?, last_name=?, father_name=?,
                     birth_date=?, issue_date=?, expiry_date=?, notes=?, updated_at=NOW() WHERE id=?",
                    array_merge($data, [$id])
                );
                logAudit('update', 'citizen_certificates', $id);
                setFlash('success', 'Το πιστοποιητικό ενημερώθηκε επιτυχώς.');
            } else {
                $data[] = getCurrentUserId();
                $newId = dbInsert(
                    "INSERT INTO citizen_certificates (certificate_type_id, first_name, last_name, father_name,
                     birth_date, issue_date, expiry_date, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    $data
                );
                logAudit('create', 'citizen_certificates', $newId);
                setFlash('success', 'Το πιστοποιητικό προστέθηκε επιτυχώς.');
            }
            redirect('citizen-certificates.php');
            break;

        case 'delete':
            $id = (int) post('cert_id');
            if ($id > 0) {
                dbExecute("DELETE FROM citizen_certificates WHERE id = ?", [$id]);
                logAudit('delete', 'citizen_certificates', $id);
                setFlash('success', 'Το πιστοποιητικό διαγράφηκε.');
            }
            redirect('citizen-certificates.php');
            break;
    }
}

// Filters
$search = get('search', '');
$filterExpired = get('expired', '');
$page = max(1, (int) get('page', 1));
$perPage = 20;

$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(cc.first_name LIKE ? OR cc.last_name LIKE ? OR cc.father_name LIKE ?)";
    $params = array_merge($params, array_fill(0, 3, "%{$search}%"));
}
if ($filterExpired === '1') {
    $where[] = "cc.expiry_date IS NOT NULL AND cc.expiry_date < CURDATE()";
} elseif ($filterExpired === '0') {
    $where[] = "(cc.expiry_date IS NULL OR cc.expiry_date >= CURDATE())";
}

$filterType = get('type', '');
if ($filterType !== '') {
    $where[] = "cc.certificate_type_id = ?";
    $params[] = (int) $filterType;
}

$whereClause = implode(' AND ', $where);
$total = dbFetchValue("SELECT COUNT(*) FROM citizen_certificates cc WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

$certs = dbFetchAll(
    "SELECT cc.*, cct.name as type_name
     FROM citizen_certificates cc
     LEFT JOIN citizen_certificate_types cct ON cc.certificate_type_id = cct.id
     WHERE $whereClause ORDER BY cc.last_name, cc.first_name LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// Load certificate types for form dropdown and filter
$certTypes = dbFetchAll("SELECT * FROM citizen_certificate_types WHERE is_active = 1 ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-file-earmark-medical"></i> Πιστοποιητικά Πολιτών</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#certModal" onclick="resetForm()">
        <i class="bi bi-plus-lg"></i> Νέο Πιστοποιητικό
    </button>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Αναζήτηση</label>
                <input type="text" name="search" class="form-control" placeholder="Όνομα, Επίθετο, Πατρώνυμο..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Τύπος Πιστοποιητικού</label>
                <select name="type" class="form-select">
                    <option value="">Όλοι</option>
                    <?php foreach ($certTypes as $ct): ?>
                    <option value="<?= $ct['id'] ?>" <?= $filterType == $ct['id'] ? 'selected' : '' ?>><?= h($ct['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Κατάσταση Λήξης</label>
                <select name="expired" class="form-select">
                    <option value="">Όλα</option>
                    <option value="1" <?= $filterExpired === '1' ? 'selected' : '' ?>>Ληγμένα</option>
                    <option value="0" <?= $filterExpired === '0' ? 'selected' : '' ?>>Ενεργά</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Φίλτρο</button>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-list"></i> Σύνολο: <strong><?= $total ?></strong> πιστοποιητικά
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Τύπος</th>
                        <th>Όνομα</th>
                        <th>Επίθετο</th>
                        <th>Όνομα Πατρός</th>
                        <th>Ημ. Γέννησης</th>
                        <th>Ημ. Έκδοσης</th>
                        <th>Ημ. Λήξης</th>
                        <th class="text-center">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($certs)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Δεν βρέθηκαν πιστοποιητικά.</td></tr>
                    <?php else: ?>
                    <?php foreach ($certs as $i => $c): ?>
                    <?php
                        $isExpired = $c['expiry_date'] && $c['expiry_date'] < date('Y-m-d');
                    ?>
                    <tr class="<?= $isExpired ? 'table-warning' : '' ?>">
                        <td><?= $pagination['offset'] + $i + 1 ?></td>
                        <td><?= h($c['type_name'] ?? '-') ?></td>
                        <td><?= h($c['first_name']) ?></td>
                        <td><?= h($c['last_name']) ?></td>
                        <td><?= h($c['father_name'] ?? '-') ?></td>
                        <td><?= $c['birth_date'] ? formatDate($c['birth_date']) : '-' ?></td>
                        <td><?= $c['issue_date'] ? formatDate($c['issue_date']) : '-' ?></td>
                        <td>
                            <?php if ($c['expiry_date']): ?>
                                <?php if ($isExpired): ?>
                                    <span class="text-danger fw-bold"><?= formatDate($c['expiry_date']) ?></span>
                                    <span class="badge bg-danger ms-1">Ληγμένο</span>
                                <?php else: ?>
                                    <?= formatDate($c['expiry_date']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick="editCert(<?= h(json_encode($c)) ?>)" title="Επεξεργασία">
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
                                            <h5 class="modal-title">Διαγραφή Πιστοποιητικού</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            Είστε σίγουροι ότι θέλετε να διαγράψετε το πιστοποιητικό του
                                            <strong><?= h($c['last_name'] . ' ' . $c['first_name']) ?></strong>;
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="cert_id" value="<?= $c['id'] ?>">
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
<div class="modal fade" id="certModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="certForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="cert_id" id="formCertId" value="0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Νέο Πιστοποιητικό</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Τύπος Πιστοποιητικού</label>
                            <select name="certificate_type_id" id="certificate_type_id" class="form-select">
                                <option value="">-- Επιλέξτε --</option>
                                <?php foreach ($certTypes as $ct): ?>
                                <option value="<?= $ct['id'] ?>"><?= h($ct['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6"></div>
                        <div class="col-md-4">
                            <label class="form-label">Όνομα <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Επίθετο <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Όνομα Πατρός</label>
                            <input type="text" name="father_name" id="father_name" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ημερομηνία Γέννησης</label>
                            <input type="date" name="birth_date" id="birth_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ημ. Έκδοσης</label>
                            <input type="date" name="issue_date" id="issue_date" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ημ. Λήξης</label>
                            <input type="date" name="expiry_date" id="expiry_date" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Σημειώσεις</label>
                            <textarea name="notes" id="cert_notes" class="form-control" rows="2"></textarea>
                        </div>
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
function resetForm() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formCertId').value = '0';
    document.getElementById('modalTitle').textContent = 'Νέο Πιστοποιητικό';
    document.getElementById('certForm').reset();
}

function editCert(c) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formCertId').value = c.id;
    document.getElementById('modalTitle').textContent = 'Επεξεργασία Πιστοποιητικού';
    document.getElementById('first_name').value = c.first_name || '';
    document.getElementById('last_name').value = c.last_name || '';
    document.getElementById('father_name').value = c.father_name || '';
    document.getElementById('birth_date').value = c.birth_date || '';
    document.getElementById('issue_date').value = c.issue_date || '';
    document.getElementById('expiry_date').value = c.expiry_date || '';
    document.getElementById('cert_notes').value = c.notes || '';
    document.getElementById('certificate_type_id').value = c.certificate_type_id || '';

    var modal = new bootstrap.Modal(document.getElementById('certModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
