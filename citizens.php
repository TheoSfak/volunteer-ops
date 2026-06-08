<?php
/**
 * VolunteerOps - Citizens Management (Πολίτες)
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('citizens_view');

$pageTitle = 'Λίστα Πολιτών';
$seminarTypes = ['BLS ADULT', 'BLS PEDIATRIC', 'TRAUMA', 'FIRST AID'];

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

// Check if registered_at / referral_source columns exist (added in migration 61)
$_hasNewCols = !empty(dbFetchAll("SHOW COLUMNS FROM citizens LIKE 'registered_at'"));
if (!$_hasNewCols) {
    try {
        dbExecute("ALTER TABLE citizens
            ADD COLUMN registered_at DATE NULL AFTER notes,
            ADD COLUMN referral_source VARCHAR(255) NULL AFTER registered_at");
        dbExecute("UPDATE citizens SET registered_at = DATE(created_at) WHERE registered_at IS NULL");
        $_hasNewCols = true;
    } catch (Exception $e) {
        // Continue gracefully
    }
}

// JSON endpoint: return contact history for a citizen (AJAX GET)
if (!isPost() && get('json') === 'contacts') {
    header('Content-Type: application/json; charset=UTF-8');
    $cid = (int) get('citizen_id');
    $contacts = [];
    if ($cid > 0) {
        try {
            $contacts = dbFetchAll(
                "SELECT cc.id, cc.contact_date, cc.quick_note, cc.notes, cc.created_at,
                        u.name as created_by_name
                 FROM citizen_contacts cc
                 LEFT JOIN users u ON cc.created_by = u.id
                 WHERE cc.citizen_id = ?
                 ORDER BY cc.contact_date DESC, cc.created_at DESC",
                [$cid]
            );
        } catch (Exception $e) {
            $contacts = [];
        }
    }
    echo json_encode($contacts);
    exit;
}

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // Contact history: any user with citizens_view (or manage) can add/delete entries
    if (in_array($action, ['add_contact', 'delete_contact'], true)) {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            if ($action === 'add_contact') {
                $cid = (int) post('citizen_id');
                $date = post('contact_date') ?: date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
                $quickNote = trim(post('quick_note')) ?: null;
                $notes     = trim(post('contact_notes')) ?: null;
                if ($cid > 0) {
                    $newId = dbInsert(
                        "INSERT INTO citizen_contacts (citizen_id, contact_date, quick_note, notes, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [$cid, $date, $quickNote, $notes, getCurrentUserId()]
                    );
                    logAudit('add_contact', 'citizen_contacts', $newId);
                    echo json_encode(['ok' => true, 'id' => $newId]);
                } else {
                    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρος πολίτης.']);
                }
            } elseif ($action === 'delete_contact') {
                $contactId = (int) post('contact_id');
                if ($contactId > 0) {
                    dbExecute("DELETE FROM citizen_contacts WHERE id = ?", [$contactId]);
                    logAudit('delete_contact', 'citizen_contacts', $contactId);
                    echo json_encode(['ok' => true]);
                } else {
                    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρη εγγραφή.']);
                }
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Σφάλμα βάσης δεδομένων.']);
        }
        exit;
    }

    if (!hasPagePermission('citizens_manage')) {
        setFlash('error', 'Δεν έχετε δικαίωμα τροποποίησης πολιτών.');
        redirect('citizens.php');
    }

    switch ($action) {
        case 'create':
        case 'update':
            $id = (int) post('citizen_id');
            $seminarType = trim(post('seminar_type'));
            if ($seminarType !== '' && !in_array($seminarType, $seminarTypes, true)) {
                setFlash('error', 'Μη έγκυρο είδος σεμιναρίου.');
                redirect('citizens.php');
            }
            $data = [
                trim(post('first_name_gr')),
                trim(post('last_name_gr')),
                trim(post('first_name_lat')),
                trim(post('last_name_lat')),
                $seminarType !== '' ? $seminarType : null,
                post('birth_date') ?: null,
                trim(post('email')) ?: null,
                trim(post('phone')) ?: null,
                post('contact_done') ? 1 : 0,
                post('payment_done') ? 1 : 0,
                post('completed') ? 1 : 0,
                trim(post('notes')) ?: null,
                $_hasNewCols ? (post('registered_at') ?: date('Y-m-d')) : null, // 12
                $_hasNewCols ? (trim(post('referral_source')) ?: null) : null,  // 13
            ];

            // Strip the two new-col values from $data if columns don't exist yet
            if (!$_hasNewCols) {
                array_splice($data, 12, 2);
            }

            if (empty($data[0]) || empty($data[1])) {
                setFlash('error', 'Τα πεδία Όνομα και Επίθετο (Ελληνικά) είναι υποχρεωτικά.');
                redirect('citizens.php');
            }

            if ($action === 'update' && $id > 0) {
                $tsUpdates = '';
                if ($_hasTsCols) {
                    $old = dbFetchOne("SELECT contact_done, payment_done, completed FROM citizens WHERE id = ?", [$id]);
                    if ($old) {
                        $newContact = $data[8]; $newPayment = $data[9]; $newCompleted = $data[10];
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
                     seminar_type=?, birth_date=?, email=?, phone=?, contact_done=?, payment_done=?, completed=?, notes=?"
                     . ($_hasNewCols ? ', registered_at=?, referral_source=?' : '')
                     . "{$tsUpdates}, updated_at=NOW() WHERE id=?",
                    array_merge($data, [$id])
                );
                logAudit('update', 'citizens', $id);
                setFlash('success', 'Ο πολίτης ενημερώθηκε επιτυχώς.');
            } else {
                if ($_hasTsCols) {
                    $contactAt = $data[8] ? date('Y-m-d H:i:s') : null;
                    $paymentAt = $data[9] ? date('Y-m-d H:i:s') : null;
                    $completedAt = $data[10] ? date('Y-m-d H:i:s') : null;
                    $data[] = $contactAt;
                    $data[] = $paymentAt;
                    $data[] = $completedAt;
                }
                $data[] = getCurrentUserId();
                if ($_hasTsCols) {
                    $colList = $_hasNewCols
                        ? 'first_name_gr, last_name_gr, first_name_lat, last_name_lat, seminar_type, birth_date, email, phone, contact_done, payment_done, completed, notes, registered_at, referral_source, contact_done_at, payment_done_at, completed_at, created_by'
                        : 'first_name_gr, last_name_gr, first_name_lat, last_name_lat, seminar_type, birth_date, email, phone, contact_done, payment_done, completed, notes, contact_done_at, payment_done_at, completed_at, created_by';
                    $newId = dbInsert(
                        "INSERT INTO citizens ($colList) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")",
                        $data
                    );
                } else {
                    $colList = $_hasNewCols
                        ? 'first_name_gr, last_name_gr, first_name_lat, last_name_lat, seminar_type, birth_date, email, phone, contact_done, payment_done, completed, notes, registered_at, referral_source, created_by'
                        : 'first_name_gr, last_name_gr, first_name_lat, last_name_lat, seminar_type, birth_date, email, phone, contact_done, payment_done, completed, notes, created_by';
                    $newId = dbInsert(
                        "INSERT INTO citizens ($colList) VALUES (" . implode(',', array_fill(0, count($data), '?')) . ")",
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

        case 'create_cert_from_citizen':
            $id = (int) post('citizen_id');
            if ($id > 0) {
                $citizen = dbFetchOne("SELECT * FROM citizens WHERE id = ?", [$id]);
                if ($citizen) {
                    // Mark as completed (only if not already)
                    if (!$citizen['completed']) {
                        if ($_hasTsCols) {
                            dbExecute("UPDATE citizens SET completed = 1, completed_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
                        } else {
                            dbExecute("UPDATE citizens SET completed = 1, updated_at = NOW() WHERE id = ?", [$id]);
                        }
                        logAudit('update', 'citizens', $id);
                    }

                    // Duplicate email check (only when citizen has email)
                    $citizenEmail = trim($citizen['email'] ?? '');
                    $isDuplicate = false;
                    if ($citizenEmail !== '') {
                        $dupCount = (int) dbFetchValue(
                            "SELECT COUNT(*) FROM citizen_certificates WHERE email = ?",
                            [$citizenEmail]
                        );
                        $isDuplicate = $dupCount > 0;
                    }

                    if ($isDuplicate) {
                        setFlash('warning', 'Ο πολίτης σημειώθηκε ως ολοκληρωμένος, αλλά δεν δημιουργήθηκε πιστοποιητικό γιατί υπάρχει ήδη εγγραφή με το ίδιο email.');
                    } else {
                        $certTypeId = (int) post('certificate_type_id') ?: null;
                        $issueDate  = post('issue_date')  ?: date('Y-m-d');
                        $expiryDate = post('expiry_date') ?: date('Y-m-d', strtotime('+3 years'));
                        // Validate date format — fallback to defaults if malformed
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate))  $issueDate  = date('Y-m-d');
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) $expiryDate = date('Y-m-d', strtotime('+3 years'));
                        $newCertId  = dbInsert(
                            "INSERT INTO citizen_certificates
                             (certificate_type_id, first_name, last_name, phone, birth_date,
                              issue_date, expiry_date, email, notes, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $certTypeId,
                                $citizen['first_name_gr'],
                                $citizen['last_name_gr'],
                                $citizen['phone'] ?: null,
                                $citizen['birth_date'] ?: null,
                                $issueDate,
                                $expiryDate,
                                $citizenEmail !== '' ? $citizenEmail : null,
                                null,
                                getCurrentUserId(),
                            ]
                        );
                        logAudit('create', 'citizen_certificates', $newCertId);
                        setFlash('success', 'Ο πολίτης σημειώθηκε ως ολοκληρωμένος και το πιστοποιητικό καταχωρήθηκε επιτυχώς (λήξη: ' . date('d/m/Y', strtotime($expiryDate)) . ').');
                    }
                }
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
        $expWhere[] = "(first_name_gr LIKE ? OR last_name_gr LIKE ? OR first_name_lat LIKE ? OR last_name_lat LIKE ? OR seminar_type LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $expParams = array_merge($expParams, array_fill(0, 7, '%' . dbEscape($expSearch) . '%'));
    }
    if (get('contact', '') !== '') { $expWhere[] = "contact_done = ?"; $expParams[] = (int) get('contact'); }
    if (get('payment', '') !== '') { $expWhere[] = "payment_done = ?"; $expParams[] = (int) get('payment'); }
    if (get('completed', '') !== '') { $expWhere[] = "completed = ?"; $expParams[] = (int) get('completed'); }
    $expWhereClause = implode(' AND ', $expWhere);
    $rows = dbFetchAll("SELECT * FROM citizens WHERE $expWhereClause ORDER BY last_name_gr ASC, first_name_gr ASC, id ASC", $expParams);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="citizens_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, ['#', 'Επίθετο (GR)', 'Όνομα (GR)', 'Επίθετο (LAT)', 'Όνομα (LAT)', 'Είδος Σεμιναρίου', 'Ημ. Γέννησης', 'Email', 'Τηλέφωνο', 'Επικοινωνία', 'Ημ/νία Επικοινωνίας', 'Πληρωμή', 'Ημ/νία Πληρωμής', 'Ολοκλήρωση', 'Ημ/νία Ολοκλήρωσης', 'Ημ. Εγγραφής', 'Πηγή', 'Σημειώσεις'], ';', '"', '\\');
    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['last_name_gr'],
            $r['first_name_gr'],
            $r['last_name_lat'] ?? '',
            $r['first_name_lat'] ?? '',
            $r['seminar_type'] ?? '',
            $r['birth_date'] ? formatDate($r['birth_date']) : '',
            $r['email'] ?? '',
            $r['phone'] ?? '',
            $r['contact_done'] ? 'Ναι' : 'Όχι',
            ($r['contact_done_at'] ?? null) ? formatDateTime($r['contact_done_at']) : '',
            $r['payment_done'] ? 'Ναι' : 'Όχι',
            ($r['payment_done_at'] ?? null) ? formatDateTime($r['payment_done_at']) : '',
            $r['completed'] ? 'Ναι' : 'Όχι',
            ($r['completed_at'] ?? null) ? formatDateTime($r['completed_at']) : '',
            !empty($r['registered_at']) ? formatDate($r['registered_at']) : '',
            $r['referral_source'] ?? '',
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

// Sorting
$allowedSorts = [
    'first_name_gr'  => 'first_name_gr',
    'last_name_gr'   => 'last_name_gr',
    'registered_at'  => 'registered_at',
    'seminar_type'   => 'seminar_type',
];
$sortCol = isset($allowedSorts[get('sort')]) ? $allowedSorts[get('sort')] : 'registered_at';
$sortDir = strtolower(get('dir', '')) === 'asc' ? 'ASC' : 'DESC';

$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(first_name_gr LIKE ? OR last_name_gr LIKE ? OR first_name_lat LIKE ? OR last_name_lat LIKE ? OR seminar_type LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params = array_merge($params, array_fill(0, 7, '%' . dbEscape($search) . '%'));
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

// Secondary sort for stable ordering
$secondarySort = ($sortCol === 'last_name_gr') ? ', first_name_gr ASC' : ', last_name_gr ASC, first_name_gr ASC';

$citizens = dbFetchAll(
    "SELECT *, (SELECT COUNT(*) FROM citizen_contacts WHERE citizen_id = citizens.id) as contact_count
     FROM citizens WHERE $whereClause ORDER BY $sortCol $sortDir $secondarySort, id ASC LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// For edit modal
$editCitizen = null;
$editId = (int) get('edit');
if ($editId) {
    $editCitizen = dbFetchOne("SELECT * FROM citizens WHERE id = ?", [$editId]);
}

// Certificate types for the cert-creation modal
$certTypes = dbFetchAll("SELECT * FROM citizen_certificate_types WHERE is_active = 1 ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-person-vcard"></i> Λίστα Πολιτών</h2>
    <div>
        <a href="citizens.php?export=csv&search=<?= urlencode($search) ?>&contact=<?= urlencode($filterContact) ?>&payment=<?= urlencode($filterPayment) ?>&completed=<?= urlencode($filterCompleted) ?>&sort=<?= urlencode($sortCol) ?>&dir=<?= urlencode(strtolower($sortDir)) ?>" class="btn btn-success me-2">
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
            <input type="hidden" name="sort" value="<?= h($sortCol) ?>">
            <input type="hidden" name="dir" value="<?= h(strtolower($sortDir)) ?>">
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
            <table class="table table-hover table-striped table-sm mb-0 align-middle" style="font-size:.82rem">
<?php
// Sort helper: builds href toggling direction, preserving all filters
function sortLink(string $col, string $label, string $currentCol, string $currentDir, array $filters): string {
    $isActive = ($col === $currentCol);
    $newDir   = ($isActive && $currentDir === 'DESC') ? 'asc' : ($isActive ? 'desc' : 'desc');
    $indicator = '';
    if ($isActive) {
        $indicator = ' ' . ($currentDir === 'ASC' ? '&#9650;' : '&#9660;');
    }
    $qs = http_build_query(array_merge($filters, ['sort' => $col, 'dir' => $newDir]));
    return '<a href="citizens.php?' . h($qs) . '" class="text-dark text-decoration-none d-block" style="white-space:nowrap">'
         . $label . $indicator . '</a>';
}
$__f = ['search' => $search, 'contact' => $filterContact, 'payment' => $filterPayment, 'completed' => $filterCompleted];
?>
                <thead class="table-light">
                    <tr>
                        <th><?= sortLink('first_name_gr',  'Όνομα',      $sortCol, $sortDir, $__f) ?></th>
                        <th><?= sortLink('last_name_gr',   'Επίθετο',    $sortCol, $sortDir, $__f) ?></th>
                        <th>Email / Τηλ.</th>
                        <th><?= sortLink('seminar_type',   'Σεμινάριο',  $sortCol, $sortDir, $__f) ?></th>
                        <th>Γέννηση</th>
                        <th class="text-center" style="white-space:nowrap">Επαφή&nbsp;/&nbsp;Πληρ.&nbsp;/&nbsp;Ολοκλ.</th>
                        <th><?= sortLink('registered_at',  'Εγγραφή',    $sortCol, $sortDir, $__f) ?></th>
                        <th style="font-size:.95rem;font-weight:700;letter-spacing:.04em;">ΠΗΓΗ</th>
                        <th class="text-center">Ιστορικό</th>
                        <th class="text-center">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($citizens)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Δεν βρέθηκαν πολίτες.</td></tr>
                    <?php else: ?>
                    <?php foreach ($citizens as $i => $c): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= h($c['first_name_gr']) ?></div>
                            <?php if (!empty($c['first_name_lat'])): ?>
                            <small class="text-muted"><?= h($c['first_name_lat']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= h($c['last_name_gr']) ?></div>
                            <?php if (!empty($c['last_name_lat'])): ?>
                            <small class="text-muted"><?= h($c['last_name_lat']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($c['email'])): ?><div class="text-nowrap"><i class="bi bi-envelope text-muted me-1"></i><?= h($c['email']) ?></div><?php endif; ?>
                            <?php if (!empty($c['phone'])): ?><div class="text-nowrap"><i class="bi bi-telephone text-muted me-1"></i><?= h($c['phone']) ?></div><?php endif; ?>
                            <?php if (empty($c['email']) && empty($c['phone'])): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td><?= !empty($c['seminar_type']) ? '<span class="badge bg-info text-dark">' . h($c['seminar_type']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-nowrap"><?= $c['birth_date'] ? formatDate($c['birth_date']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-center text-nowrap">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_contact">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0 me-1" title="Επαφή<?= $c['contact_done'] && !empty($c['contact_done_at']) ? ': ' . formatDateTime($c['contact_done_at']) : ': Δεν έγινε' ?>">
                                    <i class="bi <?= $c['contact_done'] ? 'bi-telephone-fill text-success' : 'bi-telephone text-secondary' ?>" style="font-size:1.05rem;"></i>
                                </button>
                            </form>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_payment">
                                <input type="hidden" name="citizen_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link p-0 me-1" title="Πληρωμή<?= $c['payment_done'] && !empty($c['payment_done_at']) ? ': ' . formatDateTime($c['payment_done_at']) : ': Δεν έγινε' ?>">
                                    <i class="bi <?= $c['payment_done'] ? 'bi-cash-coin text-success' : 'bi-cash-coin text-secondary' ?>" style="font-size:1.05rem;"></i>
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-link p-0"
                                title="Ολοκλήρωση<?= $c['completed'] && !empty($c['completed_at']) ? ': ' . formatDateTime($c['completed_at']) : ': Δεν έγινε' ?>"
                                onclick="clickToggleCompleted(<?= $c['id'] ?>, <?= (int)$c['completed'] ?>, '<?= h(addslashes($c['last_name_gr'] . ' ' . $c['first_name_gr'])) ?>')">
                                <i class="bi <?= $c['completed'] ? 'bi-award-fill text-success' : 'bi-award text-secondary' ?>" style="font-size:1.05rem;"></i>
                            </button>
                        </td>
                        <td class="text-nowrap"><?= !empty($c['registered_at']) ? formatDate($c['registered_at']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if (!empty($c['referral_source'])): ?>
                                <span class="fw-bold" style="font-size:1rem;"><?= h($c['referral_source']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (($c['contact_count'] ?? 0) > 0): ?>
                                <button class="btn btn-sm btn-info py-0 px-2" style="font-size:.8rem;"
                                    onclick="openContactHistory(<?= $c['id'] ?>, '<?= h(addslashes($c['first_name_gr'] . ' ' . $c['last_name_gr'])) ?>')"
                                    data-citizen-id="<?= $c['id'] ?>"
                                    title="Ιστορικό Επικοινωνιών">
                                    💬 <?= $c['contact_count'] ?>
                                </button>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-info py-0 px-1"
                                    onclick="openContactHistory(<?= $c['id'] ?>, '<?= h(addslashes($c['first_name_gr'] . ' ' . $c['last_name_gr'])) ?>')"
                                    data-citizen-id="<?= $c['id'] ?>"
                                    title="Ιστορικό Επικοινωνιών">
                                <i class="bi bi-chat-text"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="editCitizen(<?= h(json_encode($c)) ?>)" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="confirmDelete(<?= $c['id'] ?>, '<?= h($c['last_name_gr'] . ' ' . $c['first_name_gr']) ?>')" title="Διαγραφή">
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

<!-- Hidden form: plain toggle_completed (no cert) -->
<form method="post" id="toggleCompletedForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="toggle_completed">
    <input type="hidden" name="citizen_id" id="toggleCompletedCitizenId" value="0">
</form>

<!-- Hidden form: toggle completed + create certificate -->
<form method="post" id="certCreationForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_cert_from_citizen">
    <input type="hidden" name="citizen_id" id="certCitizenId" value="0">
    <input type="hidden" name="certificate_type_id" id="certTypeFromModal" value="">
    <input type="hidden" name="issue_date" id="certIssueDateFromModal" value="">
    <input type="hidden" name="expiry_date" id="certExpiryDateFromModal" value="">
</form>

<!-- Completed → Certificate Modal -->
<div class="modal fade" id="completedCertModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-patch-check"></i> Ολοκλήρωση &amp; Πιστοποιητικό</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Ο πολίτης <strong id="certModalCitizenName"></strong> θα σημειωθεί ως <span class="badge bg-success">Ολοκληρωμένος</span>.</p>
                <p class="mb-3">Θέλετε να καταχωρήσετε και πιστοποιητικό στο μητρώο πιστοποιητικών πολιτών;</p>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Τύπος πιστοποιητικού</label>
                        <select class="form-select" id="certModalTypeSelect">
                            <option value="">-- Χωρίς τύπο --</option>
                            <?php foreach ($certTypes as $ct): ?>
                            <option value="<?= $ct['id'] ?>"><?= h($ct['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Ημερομηνία έκδοσης</label>
                        <input type="date" class="form-control" id="certModalIssueDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Ημερομηνία λήξης</label>
                        <input type="date" class="form-control" id="certModalExpiryDate" value="<?= date('Y-m-d', strtotime('+3 years')) ?>">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="submitToggleOnly()">
                    <i class="bi bi-check-circle"></i> Όχι, μόνο ολοκλήρωση
                </button>
                <button type="button" class="btn btn-success" onclick="submitWithCert()">
                    <i class="bi bi-file-earmark-plus"></i> Ναι, καταχώρηση πιστοποιητικού
                </button>
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
                        <div class="col-md-6">
                            <label class="form-label">Είδος σεμιναρίου</label>
                            <select name="seminar_type" id="seminar_type" class="form-select">
                                <option value="">-- Επιλέξτε --</option>
                                <?php foreach ($seminarTypes as $type): ?>
                                <option value="<?= h($type) ?>"><?= h($type) ?></option>
                                <?php endforeach; ?>
                            </select>
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
                        <div class="col-md-4">
                            <label class="form-label">Ημερομηνία Εγγραφής</label>
                            <input type="date" name="registered_at" id="registered_at" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Από πού μας έμαθες</label>
                            <input type="text" name="referral_source" id="referral_source" class="form-control" placeholder="π.χ. Φίλος, Google, Facebook, Εκδήλωση...">
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
    document.getElementById('registered_at').value = new Date().toISOString().split('T')[0];
}

function editCitizen(c) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formCitizenId').value = c.id;
    document.getElementById('modalTitle').textContent = 'Επεξεργασία Πολίτη';
    document.getElementById('first_name_gr').value = c.first_name_gr || '';
    document.getElementById('last_name_gr').value = c.last_name_gr || '';
    document.getElementById('first_name_lat').value = c.first_name_lat || '';
    document.getElementById('last_name_lat').value = c.last_name_lat || '';
    document.getElementById('seminar_type').value = c.seminar_type || '';
    document.getElementById('birth_date').value = c.birth_date || '';
    document.getElementById('citizen_email').value = c.email || '';
    document.getElementById('citizen_phone').value = c.phone || '';
    document.getElementById('registered_at').value = c.registered_at || new Date().toISOString().split('T')[0];
    document.getElementById('referral_source').value = c.referral_source || '';
    document.getElementById('citizen_notes').value = c.notes || '';
    document.getElementById('contact_done').checked = c.contact_done == 1;
    document.getElementById('payment_done').checked = c.payment_done == 1;
    document.getElementById('completed_cb').checked = c.completed == 1;

    var modal = new bootstrap.Modal(document.getElementById('citizenModal'));
    modal.show();
}

// Default dates for the certificate modal (ISO format for <input type="date">)
var _certDefaultIssue  = '<?= date('Y-m-d') ?>';
var _certDefaultExpiry = '<?= date('Y-m-d', strtotime('+3 years')) ?>';

function clickToggleCompleted(citizenId, isCompleted, citizenName) {
    document.getElementById('toggleCompletedCitizenId').value = citizenId;
    if (isCompleted == 1) {
        // Unchecking — no modal needed, just toggle off
        document.getElementById('toggleCompletedForm').submit();
    } else {
        // Checking ON — ask about certificate
        document.getElementById('certCitizenId').value = citizenId;
        document.getElementById('certModalCitizenName').textContent = citizenName;
        document.getElementById('certModalTypeSelect').value = '';
        document.getElementById('certModalIssueDate').value  = _certDefaultIssue;
        document.getElementById('certModalExpiryDate').value = _certDefaultExpiry;
        var modal = new bootstrap.Modal(document.getElementById('completedCertModal'));
        modal.show();
    }
}

function submitToggleOnly() {
    bootstrap.Modal.getInstance(document.getElementById('completedCertModal')).hide();
    document.getElementById('toggleCompletedForm').submit();
}

function submitWithCert() {
    document.getElementById('certTypeFromModal').value    = document.getElementById('certModalTypeSelect').value;
    document.getElementById('certIssueDateFromModal').value  = document.getElementById('certModalIssueDate').value  || _certDefaultIssue;
    document.getElementById('certExpiryDateFromModal').value = document.getElementById('certModalExpiryDate').value || _certDefaultExpiry;
    bootstrap.Modal.getInstance(document.getElementById('completedCertModal')).hide();
    document.getElementById('certCreationForm').submit();
}
</script>

<!-- Contact History Modal -->
<input type="hidden" id="chCsrfToken" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
<div class="modal fade" id="contactHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#0dcaf0;color:#000;">
                <h5 class="modal-title fw-semibold">
                    <i class="bi bi-chat-text me-2"></i>Ιστορικό Επικοινωνιών
                    <span id="chModalName" class="ms-1 fw-normal"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- History list -->
                <div id="chHistoryList" class="mb-3">
                    <div class="text-center text-muted py-3"><i class="bi bi-hourglass-split me-1"></i>Φόρτωση...</div>
                </div>
                <!-- Add new entry -->
                <div class="border-top pt-3">
                    <h6 class="mb-2"><i class="bi bi-plus-circle me-1 text-success"></i>Νέα Εγγραφή</h6>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Ημερομηνία</label>
                            <input type="date" id="chDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Γρήγορο Σχόλιο</label>
                            <select id="chQuickNote" class="form-select form-select-sm">
                                <option value="">— Επιλέξτε —</option>
                                <option value="Δεν το σήκωσε">Δεν το σήκωσε</option>
                                <option value="Μου είπε να ξανακαλέσω">Μου είπε να ξανακαλέσω</option>
                                <option value="Δεν επιθυμεί πλέον">Δεν επιθυμεί πλέον</option>
                                <option value="Να τον καλέσουμε στο επόμενο σεμινάριο">Να τον καλέσουμε στο επόμενο σεμινάριο</option>
                                <option value="Επιβεβαίωσε συμμετοχή">Επιβεβαίωσε συμμετοχή</option>
                                <option value="Ζήτησε πληροφορίες">Ζήτησε πληροφορίες</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold mb-1">Σχόλια (προαιρετικά)</label>
                            <textarea id="chNotes" class="form-control form-control-sm" rows="2" placeholder="Λεπτομέρειες επικοινωνίας..."></textarea>
                        </div>
                    </div>
                    <button class="btn btn-sm btn-success mt-2" onclick="chAddContact()">
                        <i class="bi bi-plus-lg me-1"></i>Αποθήκευση
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var _chCitizenId = 0;

function openContactHistory(citizenId, name) {
    _chCitizenId = citizenId;
    document.getElementById('chModalName').textContent = '— ' + name;
    document.getElementById('chHistoryList').innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-hourglass-split me-1"></i>Φόρτωση...</div>';
    document.getElementById('chDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('chQuickNote').value = '';
    document.getElementById('chNotes').value = '';
    new bootstrap.Modal(document.getElementById('contactHistoryModal')).show();
    chLoadHistory();
}

function chLoadHistory() {
    fetch('citizens.php?json=contacts&citizen_id=' + _chCitizenId)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var list = document.getElementById('chHistoryList');
            if (!data || data.length === 0) {
                list.innerHTML = '<p class="text-muted text-center py-2 mb-0"><i class="bi bi-chat-text me-1"></i>Δεν υπάρχουν εγγραφές επικοινωνίας ακόμα.</p>';
                return;
            }
            var html = '<div class="list-group list-group-flush">';
            data.forEach(function(c) {
                html += '<div class="list-group-item px-0 py-2 d-flex align-items-start gap-2">';
                html += '<div class="flex-shrink-0 fw-semibold text-muted" style="min-width:80px;font-size:.82rem;">' + chFmtDate(c.contact_date) + '</div>';
                html += '<div class="flex-grow-1">';
                if (c.quick_note) {
                    html += '<span class="badge bg-info text-dark me-1 mb-1" style="font-size:.8rem;">' + chEsc(c.quick_note) + '</span>';
                }
                if (c.notes) {
                    html += '<div style="font-size:.85rem;">' + chEsc(c.notes) + '</div>';
                }
                if (!c.quick_note && !c.notes) {
                    html += '<span class="text-muted fst-italic" style="font-size:.82rem;">Χωρίς σχόλια</span>';
                }
                html += '<small class="text-muted d-block mt-1" style="font-size:.75rem;"><i class="bi bi-person me-1"></i>' + chEsc(c.created_by_name || 'Άγνωστος') + '</small>';
                html += '</div>';
                html += '<button class="btn btn-sm btn-link text-danger p-0 flex-shrink-0 ms-1" onclick="chDeleteContact(' + c.id + ')" title="Διαγραφή"><i class="bi bi-trash"></i></button>';
                html += '</div>';
            });
            html += '</div>';
            list.innerHTML = html;
        })
        .catch(function() {
            document.getElementById('chHistoryList').innerHTML = '<div class="alert alert-danger py-2">Σφάλμα φόρτωσης.</div>';
        });
}

function chAddContact() {
    var date      = document.getElementById('chDate').value;
    var quickNote = document.getElementById('chQuickNote').value;
    var notes     = document.getElementById('chNotes').value.trim();
    var csrf      = document.getElementById('chCsrfToken').value;

    if (!date) { alert('Επιλέξτε ημερομηνία.'); return; }
    if (!quickNote && !notes) { alert('Επιλέξτε γρήγορο σχόλιο ή προσθέστε σχόλια.'); return; }

    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'add_contact');
    fd.append('citizen_id', _chCitizenId);
    fd.append('contact_date', date);
    fd.append('quick_note', quickNote);
    fd.append('contact_notes', notes);

    fetch('citizens.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                document.getElementById('chQuickNote').value = '';
                document.getElementById('chNotes').value = '';
                chLoadHistory();
                chUpdateRowIndicator(_chCitizenId, 1);
            } else {
                alert(data.error || 'Σφάλμα αποθήκευσης.');
            }
        })
        .catch(function() { alert('Σφάλμα σύνδεσης.'); });
}

function chDeleteContact(contactId) {
    if (!confirm('Διαγραφή αυτής της εγγραφής;')) return;
    var csrf = document.getElementById('chCsrfToken').value;
    var fd = new FormData();
    fd.append('csrf_token', csrf);
    fd.append('action', 'delete_contact');
    fd.append('contact_id', contactId);

    fetch('citizens.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                chLoadHistory();
                chUpdateRowIndicator(_chCitizenId, -1);
            } else {
                alert(data.error || 'Σφάλμα διαγραφής.');
            }
        })
        .catch(function() { alert('Σφάλμα σύνδεσης.'); });
}

function chUpdateRowIndicator(citizenId, delta) {
    var btn = document.querySelector('[data-citizen-id="' + citizenId + '"][title="Ιστορικό Επικοινωνιών"]');
    if (!btn) return;
    var row = btn.closest('tr');
    var histCell = row.cells[row.cells.length - 2]; // second-to-last (Ιστορικό)
    var countBadge = histCell.querySelector('button');
    var curCount = countBadge ? (parseInt(countBadge.textContent.trim()) || 0) : 0;
    var newCount = curCount + delta;
    if (newCount <= 0) {
        histCell.innerHTML = '<span class="text-muted">—</span>';
    } else {
        histCell.innerHTML = '<button class="btn btn-sm btn-info py-0 px-2" style="font-size:.8rem;" onclick="openContactHistory(' + citizenId + ', \'\')" data-citizen-id="' + citizenId + '" title="Ιστορικό Επικοινωνιών">💬 ' + newCount + '</button>';
    }
}

function chEsc(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

function chFmtDate(d) {
    if (!d) return '';
    var p = d.split('-');
    return p.length === 3 ? p[2]+'/'+p[1]+'/'+p[0] : d;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
