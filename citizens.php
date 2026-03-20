<?php
/**
 * VolunteerOps - Citizens Management (Πολίτες)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Λίστα Πολιτών';

// Check if timestamp columns exist — if not, create them directly
$_hasTsCols = !empty(dbFetchAll("SHOW COLUMNS FROM citizens LIKE 'contact_done_at'"));
if (!$_hasTsCols) {
    try {
        dbExecute("ALTER TABLE citizens
            ADD COLUMN contact_done_at DATETIME NULL AFTER contact_done,
            ADD COLUMN payment_done_at DATETIME NULL AFTER payment_done,
            ADD COLUMN completed_at DATETIME NULL AFTER completed");
        dbExecute("UPDATE citizens SET contact_done_at = updated_at WHERE contact_done = 1");
        dbExecute("UPDATE citizens SET payment_done_at = updated_at WHERE payment_done = 1");
        dbExecute("UPDATE citizens SET completed_at = updated_at WHERE completed = 1");
        // Mark migration 37 as done so it doesn't re-run
        dbExecute("INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES ('db_schema_version', '37', NOW())
            ON DUPLICATE KEY UPDATE setting_value = GREATEST(setting_value, '37'), updated_at = NOW()");
        dbExecute("DELETE FROM settings WHERE setting_key = 'migration_last_failure'");
        $_hasTsCols = true;
    } catch (Exception $e) {
        // Columns might already partially exist or other issue — continue gracefully
    }
}

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
                $tsUpdates = '';
                if ($_hasTsCols) {
                    $old = dbFetchOne("SELECT contact_done, payment_done, completed FROM citizens WHERE id = ?", [$id]);
                    if ($old) {
                        $newContact = $data[7]; $newPayment = $data[8]; $newCompleted = $data[9];
                        if ($newContact && !$old['contact_done']) $tsUpdates .= ', contact_done_at=NOW()';
                        if (!$newContact && $old['contact_done']) $tsUpdates .= ', contact_done_at=NULL';
                        if ($newPayment && !$old['payment_done']) $tsUpdates .= ', payment_done_at=NOW()';
                        if (!$newPayment && $old['payment_done']) $tsUpdates .= ', payment_done_at=NULL';
                        if ($newCompleted && !$old['completed']) $tsUpdates .= ', completed_at=NOW()';
                        if (!$newCompleted && $old['completed']) $tsUpdates .= ', completed_at=NULL';
                    }
                }
                dbExecute(
                    "UPDATE citizens SET first_name_gr=?, last_name_gr=?, first_name_lat=?, last_name_lat=?,
                     birth_date=?, email=?, phone=?, contact_done=?, payment_done=?, completed=?, notes=?
                     {$tsUpdates}, updated_at=NOW() WHERE id=?",
                    array_merge($data, [$id])
                );
                logAudit('update', 'citizens', $id);
                setFlash('success', 'Ο πολίτης ενημερώθηκε επιτυχώς.');
            } else {
                if ($_hasTsCols) {
                    $contactAt = $data[7] ? date('Y-m-d H:i:s') : null;
                    $paymentAt = $data[8] ? date('Y-m-d H:i:s') : null;
                    $completedAt = $data[9] ? date('Y-m-d H:i:s') : null;
                    $data[] = $contactAt;
                    $data[] = $paymentAt;
                    $data[] = $completedAt;
                }
                $data[] = getCurrentUserId();
                if ($_hasTsCols) {
                    $newId = dbInsert(
                        "INSERT INTO citizens (first_name_gr, last_name_gr, first_name_lat, last_name_lat,
                         birth_date, email, phone, contact_done, payment_done, completed, notes,
                         contact_done_at, payment_done_at, completed_at, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        $data
                    );
                } else {
                    $newId = dbInsert(
                        "INSERT INTO citizens (first_name_gr, last_name_gr, first_name_lat, last_name_lat,
                         birth_date, email, phone, contact_done, payment_done, completed, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        $data
                    );
                }
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
            $tsMap = [
                'contact' => 'contact_done_at',
                'payment' => 'payment_done_at',
                'completed' => 'completed_at',
            ];
            if ($id > 0 && isset($fieldMap[$field])) {
                $col = $fieldMap[$field];
                if ($_hasTsCols) {
                    $tsCol = $tsMap[$field];
                    // Read current value first — MySQL SET evaluates left-to-right
                    $current = (int) dbFetchValue("SELECT {$col} FROM citizens WHERE id = ?", [$id]);
                    if ($current) {
                        // Currently checked → uncheck and clear timestamp
                        dbExecute("UPDATE citizens SET {$col} = 0, {$tsCol} = NULL, updated_at=NOW() WHERE id = ?", [$id]);
                    } else {
                        // Currently unchecked → check and set timestamp
                        dbExecute("UPDATE citizens SET {$col} = 1, {$tsCol} = NOW(), updated_at=NOW() WHERE id = ?", [$id]);
                    }
                } else {
                    dbExecute("UPDATE citizens SET {$col} = IF({$col}=1, 0, 1), updated_at=NOW() WHERE id = ?", [$id]);
                }
                logAudit('update', 'citizens', $id);
            }
            redirect('citizens.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            break;
    }
}

// CSV Export
if (get('export') === 'csv') {
    $expWhere = ['1=1'];
    $expParams = [];
    $expSearch = get('search', '');
    if ($expSearch) {
        $expWhere[] = "(first_name_gr LIKE ? OR last_name_gr LIKE ? OR first_name_lat LIKE ? OR last_name_lat LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $expParams = array_merge($expParams, array_fill(0, 6, '%' . dbEscape($expSearch) . '%'));
    }
    if (get('contact', '') !== '') { $expWhere[] = "contact_done = ?"; $expParams[] = (int) get('contact'); }
    if (get('payment', '') !== '') { $expWhere[] = "payment_done = ?"; $expParams[] = (int) get('payment'); }
    if (get('completed', '') !== '') { $expWhere[] = "completed = ?"; $expParams[] = (int) get('completed'); }
    $expWhereClause = implode(' AND ', $expWhere);
    $rows = dbFetchAll("SELECT * FROM citizens WHERE $expWhereClause ORDER BY last_name_gr, first_name_gr", $expParams);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="citizens_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['#', 'Επίθετο (GR)', 'Όνομα (GR)', 'Επίθετο (LAT)', 'Όνομα (LAT)', 'Ημ. Γέννησης', 'Email', 'Τηλέφωνο', 'Επικοινωνία', 'Ημ/νία Επικοινωνίας', 'Πληρωμή', 'Ημ/νία Πληρωμής', 'Ολοκλήρωση', 'Ημ/νία Ολοκλήρωσης', 'Σημειώσεις'], ';', '"', '\\');
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['last_name_gr'],
            $r['first_name_gr'],
            $r['last_name_lat'] ?? '',
            $r['first_name_lat'] ?? '',
            $r['birth_date'] ? formatDate($r['birth_date']) : '',
            $r['email'] ?? '',
            $r['phone'] ?? '',
            $r['contact_done'] ? 'Ναι' : 'Όχι',
            ($r['contact_done_at'] ?? null) ? formatDateTime($r['contact_done_at']) : '',
            $r['payment_done'] ? 'Ναι' : 'Όχι',
            ($r['payment_done_at'] ?? null) ? formatDateTime($r['payment_done_at']) : '',
            $r['completed'] ? 'Ναι' : 'Όχι',
            ($r['completed_at'] ?? null) ? formatDateTime($r['completed_at']) : '',
            $r['notes'] ?? '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
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
    $params = array_merge($params, array_fill(0, 6, '%' . dbEscape($search) . '%'));
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
    <div>
        <a href="citizens.php?export=csv&search=<?= urlencode($search) ?>&contact=<?= urlencode($filterContact) ?>&payment=<?= urlencode($filterPayment) ?>&completed=<?= urlencode($filterCompleted) ?>" class="btn btn-success me-2">
            <i class="bi bi-filetype-csv"></i> Εξαγωγή CSV
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#citizenModal" onclick="resetForm()">
            <i class="bi bi-plus-lg"></i> Νέος Πολίτης
        </button>
    </div>
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
                                <button type="submit" class="btn btn-sm btn-link p-0" title="<?= $c['contact_done'] && ($c['contact_done_at'] ?? null) ? 'Επικοινωνία: ' . formatDateTime($c['contact_done_at']) : 'Εναλλαγή' ?>">
                                    <i class="bi <?= $c['contact_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                                <?php if ($c['contact_done'] && ($c['contact_done_at'] ?? null)): ?>
                                <div class="small text-muted" style="font-size:0.7rem"><?= formatDateTime($c['contact_done_at']) ?></div>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_payment">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0" title="<?= $c['payment_done'] && ($c['payment_done_at'] ?? null) ? 'Πληρωμή: ' . formatDateTime($c['payment_done_at']) : 'Εναλλαγή' ?>">
                                    <i class="bi <?= $c['payment_done'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                                <?php if ($c['payment_done'] && ($c['payment_done_at'] ?? null)): ?>
                                <div class="small text-muted" style="font-size:0.7rem"><?= formatDateTime($c['payment_done_at']) ?></div>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_completed">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0" title="<?= $c['completed'] && ($c['completed_at'] ?? null) ? 'Ολοκλήρωση: ' . formatDateTime($c['completed_at']) : 'Εναλλαγή' ?>">
                                    <i class="bi <?= $c['completed'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-secondary' ?> fs-5"></i>
                                </button>
                                <?php if ($c['completed'] && ($c['completed_at'] ?? null)): ?>
                                <div class="small text-muted" style="font-size:0.7rem"><?= formatDateTime($c['completed_at']) ?></div>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick="editCitizen(<?= h(json_encode($c)) ?>)" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $c['id'] ?>, '<?= h($c['last_name_gr'] . ' ' . $c['first_name_gr']) ?>')" title="Διαγραφή">
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
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer">
        <?= paginationLinks($pagination) ?>
    </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Διαγραφή Πολίτη</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Είστε σίγουροι ότι θέλετε να διαγράψετε τον πολίτη
                <strong id="deleteNameLabel"></strong>;
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="citizen_id" id="deleteIdInput" value="0">
                    <button type="submit" class="btn btn-danger">Διαγραφή</button>
                </form>
            </div>
        </div>
    </div>
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
function confirmDelete(id, name) {
    document.getElementById('deleteIdInput').value = id;
    document.getElementById('deleteNameLabel').textContent = name;
    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

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
