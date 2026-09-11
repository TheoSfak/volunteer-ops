<?php
/**
 * VolunteerOps - Υποψήφιοι Εθελοντές (candidate new-member applications)
 *
 * Review queue for public submissions from aithsh.php. Modeled on
 * citizens.php: single list page, one details/status modal per row, plain
 * POST forms (no fetch/AJAX). Approving a candidate hands off to
 * volunteer-form.php?from_application=<id>, which is the only place a real
 * `users` row actually gets created — see the from_application handling
 * there for how status flips to CONVERTED.
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('volunteers_view');

$pageTitle = 'Υποψήφιοι Εθελοντές';
$canManage = isSystemAdmin() || hasPagePermission('volunteers_manage');

// Handle POST actions
if (isPost()) {
    verifyCsrf();

    if (!$canManage) {
        setFlash('error', 'Δεν έχετε δικαίωμα τροποποίησης αιτήσεων.');
        redirect('volunteer-applications.php');
    }

    $action = post('action');
    $id = (int) post('application_id');
    $application = $id ? dbFetchOne("SELECT * FROM volunteer_applications WHERE id = ?", [$id]) : null;

    if ($action === 'update_status' && $application) {
        $newStatus = post('new_status');
        $notes = trim(post('admin_notes')) ?: null;

        // CONVERTED is never hand-settable in either direction — it's only
        // ever set by the conversion flow in volunteer-form.php, alongside
        // converted_user_id, so the two can never go out of sync. Once a row
        // is CONVERTED, this handler only allows the no-op "save notes"
        // resubmit (see the modal's last button below); the status-changing
        // buttons are themselves hidden for a CONVERTED row (see the modal
        // markup), this is the server-side backstop for that.
        $statusChanged = $newStatus !== $application['status'];
        $validTransition = $application['status'] !== VOL_APP_CONVERTED
            && in_array($newStatus, [VOL_APP_NEW, VOL_APP_CONTACTED, VOL_APP_REJECTED], true);

        if (!$statusChanged || $validTransition) {
            $finalStatus = $statusChanged ? $newStatus : $application['status'];
            $tsUpdate = '';
            if ($finalStatus === VOL_APP_CONTACTED && $application['status'] !== VOL_APP_CONTACTED) {
                $tsUpdate = ', contacted_at = NOW()';
            } elseif ($finalStatus === VOL_APP_REJECTED && $application['status'] !== VOL_APP_REJECTED) {
                $tsUpdate = ', rejected_at = NOW()';
            }
            // The candidate's own details are editable from this same modal, so
            // a save that carries a status also carries any correction made to
            // the card. Staff take these applications down over the phone, and a
            // mistyped mobile or email is the difference between reaching
            // someone and losing them.
            //
            // Validated with exactly the rules aithsh.php enforces on the public
            // form: name, mobile and a well-formed email required, birth date
            // either empty or a real Y-m-d. Whatever the public form would have
            // refused must not become reachable by editing afterwards.
            $details = [
                'full_name'    => trim((string) post('full_name')),
                'patronymic'   => trim((string) post('patronymic')) ?: null,
                'birth_date'   => trim((string) post('birth_date')) ?: null,
                'address'      => trim((string) post('address')) ?: null,
                'postal_code'  => trim((string) post('postal_code')) ?: null,
                'city'         => trim((string) post('city')) ?: null,
                'home_phone'   => trim((string) post('home_phone')) ?: null,
                'mobile_phone' => trim((string) post('mobile_phone')),
                'email'        => trim((string) post('email')),
                'occupation'   => trim((string) post('occupation')) ?: null,
            ];

            $detailErrors = [];
            if ($details['full_name'] === '')    { $detailErrors[] = 'Το ονοματεπώνυμο είναι υποχρεωτικό.'; }
            if ($details['mobile_phone'] === '') { $detailErrors[] = 'Το κινητό τηλέφωνο είναι υποχρεωτικό.'; }
            if ($details['email'] === '') {
                $detailErrors[] = 'Το email είναι υποχρεωτικό.';
            } elseif (!filter_var($details['email'], FILTER_VALIDATE_EMAIL)) {
                $detailErrors[] = 'Το email δεν είναι έγκυρο.';
            }
            if ($details['birth_date'] !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $details['birth_date'])) {
                $detailErrors[] = 'Μη έγκυρη ημερομηνία γέννησης.';
            }

            // Refuse the whole save rather than store a half-corrected card: the
            // status buttons and the details share one form, so a partial write
            // would leave the row saying something nobody chose.
            if ($detailErrors) {
                setFlash('error', implode(' ', $detailErrors));
                redirect('volunteer-applications.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
            }

            dbExecute(
                "UPDATE volunteer_applications
                    SET status = ?, admin_notes = ?,
                        full_name = ?, patronymic = ?, birth_date = ?, address = ?, postal_code = ?,
                        city = ?, home_phone = ?, mobile_phone = ?, email = ?, occupation = ?{$tsUpdate},
                        updated_at = NOW()
                  WHERE id = ?",
                [
                    $finalStatus, $notes,
                    $details['full_name'], $details['patronymic'], $details['birth_date'], $details['address'], $details['postal_code'],
                    $details['city'], $details['home_phone'], $details['mobile_phone'], $details['email'], $details['occupation'],
                    $id,
                ]
            );
            logAudit('update_status', 'volunteer_applications', $id, ['status' => $application['status']], ['status' => $finalStatus]);
            setFlash('success', 'Η αίτηση ενημερώθηκε.');
        }
    } elseif ($action === 'delete' && $application) {
        dbExecute("DELETE FROM volunteer_applications WHERE id = ?", [$id]);
        logAudit('delete', 'volunteer_applications', $id, ['full_name' => $application['full_name']]);
        setFlash('success', 'Η αίτηση διαγράφηκε.');
    }

    redirect('volunteer-applications.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// Filters
$statusFilter = get('status', '');
$search = get('search', '');
$page = max(1, (int) get('page', 1));
$perPage = 20;

$where = ['1=1'];
$params = [];
if ($statusFilter !== '' && array_key_exists($statusFilter, VOL_APP_STATUS_LABELS)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR mobile_phone LIKE ? OR home_phone LIKE ?)';
    $params = array_merge($params, array_fill(0, 4, '%' . dbEscape($search) . '%'));
}
$whereClause = implode(' AND ', $where);

// CSV Export — reuses the exact $whereClause/$params the list below queries
// with (just without the LIMIT/OFFSET), so the export can never drift from
// whatever status tab/search is currently applied on screen.
if (get('export') === 'csv') {
    $exportRows = dbFetchAll("SELECT * FROM volunteer_applications WHERE $whereClause ORDER BY created_at DESC", $params);

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="volunteer_applications_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsvSafe($out, ['#', 'Ονοματεπώνυμο', 'Πατρώνυμο', 'Ημ. Γέννησης', 'Διεύθυνση', 'Τ.Κ.', 'Πόλη', 'Τηλ. Οικίας', 'Κινητό', 'Email', 'Επάγγελμα', 'Κατάσταση', 'Ημερομηνία Αίτησης', 'Ημ/νία Επικοινωνίας', 'Ημ/νία Απόρριψης', 'Ημ/νία Έγκρισης', 'Σημειώσεις Προσωπικού'], ';', '"', '\\');
    foreach ($exportRows as $i => $r) {
        fputcsvSafe($out, [
            $i + 1,
            $r['full_name'],
            $r['patronymic'] ?? '',
            $r['birth_date'] ? formatDate($r['birth_date']) : '',
            $r['address'] ?? '',
            $r['postal_code'] ?? '',
            $r['city'] ?? '',
            $r['home_phone'] ?? '',
            $r['mobile_phone'],
            $r['email'],
            $r['occupation'] ?? '',
            VOL_APP_STATUS_LABELS[$r['status']] ?? $r['status'],
            formatDateTime($r['created_at']),
            $r['contacted_at'] ? formatDateTime($r['contacted_at']) : '',
            $r['rejected_at'] ? formatDateTime($r['rejected_at']) : '',
            $r['converted_at'] ? formatDateTime($r['converted_at']) : '',
            $r['admin_notes'] ?? '',
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

$total = dbFetchValue("SELECT COUNT(*) FROM volunteer_applications WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

$applications = dbFetchAll(
    "SELECT * FROM volunteer_applications WHERE $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// Status tab counts (always over the unfiltered set, independent of $search)
$statusCounts = ['' => 0, VOL_APP_NEW => 0, VOL_APP_CONTACTED => 0, VOL_APP_CONVERTED => 0, VOL_APP_REJECTED => 0];
foreach (dbFetchAll("SELECT status, COUNT(*) as c FROM volunteer_applications GROUP BY status") as $row) {
    $statusCounts[$row['status']] = (int) $row['c'];
    $statusCounts[''] += (int) $row['c'];
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-person-plus me-2"></i><?= h($pageTitle) ?></h1>
    <a href="volunteer-applications.php?export=csv&status=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="btn btn-success">
        <i class="bi bi-filetype-csv"></i> Εξαγωγή CSV
    </a>
</div>

<?= showFlash() ?>

<ul class="nav nav-pills mb-3">
    <?php
    $tabs = [
        '' => 'Όλες',
        VOL_APP_NEW => 'Νέες',
        VOL_APP_CONTACTED => 'Σε Επικοινωνία',
        VOL_APP_CONVERTED => 'Εγκεκριμένες',
        VOL_APP_REJECTED => 'Απορριφθείσες',
    ];
    foreach ($tabs as $value => $label):
        $params2 = $_GET; $params2['status'] = $value; unset($params2['page']);
        $qs = http_build_query($params2);
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === $value ? 'active' : '' ?>" href="?<?= h($qs) ?>">
            <?= h($label) ?> <span class="badge bg-light text-dark ms-1"><?= $statusCounts[$value] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
            <div class="col-md-6">
                <input type="text" name="search" class="form-control" placeholder="Αναζήτηση με όνομα, email ή τηλέφωνο..." value="<?= h($search) ?>">
            </div>
            <div class="col-md-auto">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i> Αναζήτηση</button>
                <?php if ($search !== ''): ?>
                <a href="?status=<?= h($statusFilter) ?>" class="btn btn-outline-secondary">Καθαρισμός</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Ονοματεπώνυμο</th>
                    <th>Κινητό</th>
                    <th>Email</th>
                    <th>Ημερομηνία Αίτησης</th>
                    <th>Κατάσταση</th>
                    <th class="text-end">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($applications)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Δεν βρέθηκαν αιτήσεις.</td></tr>
                <?php endif; ?>
                <?php $modalsHtml = []; ?>
                <?php foreach ($applications as $app): ?>
                <tr>
                    <td><?= h($app['full_name']) ?></td>
                    <td><a href="tel:<?= h($app['mobile_phone']) ?>" class="text-decoration-none"><?= h($app['mobile_phone']) ?></a></td>
                    <td><?= h($app['email']) ?></td>
                    <td><?= formatDateTime($app['created_at']) ?></td>
                    <td><span class="badge bg-<?= VOL_APP_STATUS_COLORS[$app['status']] ?>"><?= h(VOL_APP_STATUS_LABELS[$app['status']]) ?></span></td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#appModal<?= $app['id'] ?>">
                            <i class="bi bi-eye"></i> Λεπτομέρειες
                        </button>
                        <?php if (in_array($app['status'], [VOL_APP_NEW, VOL_APP_CONTACTED], true) && $canManage): ?>
                        <a href="volunteer-form.php?from_application=<?= $app['id'] ?>" class="btn btn-sm btn-success">
                            <i class="bi bi-person-check"></i> Έγκριση &amp; Δημιουργία Εθελοντή
                        </a>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Οριστική διαγραφή αυτής της αίτησης;');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php ob_start(); ?>
                <!-- Buffered and rendered after </table> below — a <div> directly inside
                     <tbody> is invalid HTML and gets foster-parented out during parsing,
                     breaking this modal's stacking/backdrop. -->
                <div class="modal fade" id="appModal<?= $app['id'] ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?= h($app['full_name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3 mb-3">
                                        <?php if ($canManage): ?>
                                        <?php
                                        // Editable in place rather than behind a
                                        // separate edit screen: staff correct these
                                        // while on the phone with the candidate, and
                                        // a second page to open is a second reason
                                        // not to bother. Saved by any of the footer
                                        // buttons — see the handler at the top, which
                                        // validates them exactly as aithsh.php does.
                                        $appFields = [
                                            ['full_name',    'Ονοματεπώνυμο', 'col-md-6', 'text',  true],
                                            ['patronymic',   'Πατρώνυμο',     'col-md-6', 'text',  false],
                                            ['birth_date',   'Ημ. Γέννησης',  'col-md-6', 'date',  false],
                                            ['address',      'Διεύθυνση',     'col-md-6', 'text',  false],
                                            ['postal_code',  'Τ.Κ.',          'col-md-3', 'text',  false],
                                            ['city',         'Πόλη',          'col-md-3', 'text',  false],
                                            ['home_phone',   'Τηλ. Οικίας',   'col-md-6', 'tel',   false],
                                            ['mobile_phone', 'Τηλ. Κινητό',   'col-md-6', 'tel',   true],
                                            ['email',        'Email',         'col-md-6', 'email', true],
                                            ['occupation',   'Επάγγελμα',     'col-md-6', 'text',  false],
                                        ];
                                        foreach ($appFields as [$fname, $flabel, $fcol, $ftype, $freq]):
                                        ?>
                                        <div class="<?= $fcol ?>">
                                            <label class="form-label fw-semibold mb-1"><?= h($flabel) ?><?= $freq ? ' *' : '' ?></label>
                                            <input type="<?= $ftype ?>" name="<?= $fname ?>" class="form-control form-control-sm"
                                                   value="<?= h($app[$fname] ?? '') ?>"<?= $freq ? ' required' : '' ?>>
                                        </div>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                        <div class="col-md-6"><strong>Πατρώνυμο:</strong> <?= h($app['patronymic'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Ημ. Γέννησης:</strong> <?= $app['birth_date'] ? formatDate($app['birth_date']) : '—' ?></div>
                                        <div class="col-md-6"><strong>Διεύθυνση:</strong> <?= h($app['address'] ?: '—') ?></div>
                                        <div class="col-md-3"><strong>Τ.Κ.:</strong> <?= h($app['postal_code'] ?: '—') ?></div>
                                        <div class="col-md-3"><strong>Πόλη:</strong> <?= h($app['city'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Τηλ. Οικίας:</strong> <?= h($app['home_phone'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Τηλ. Κινητό:</strong> <?= h($app['mobile_phone']) ?></div>
                                        <div class="col-md-6"><strong>Email:</strong> <?= h($app['email']) ?></div>
                                        <div class="col-md-6"><strong>Επάγγελμα:</strong> <?= h($app['occupation'] ?: '—') ?></div>
                                        <?php endif; ?>
                                        <?php if ($app['status'] === VOL_APP_CONVERTED && $app['converted_user_id']): ?>
                                        <div class="col-12">
                                            <strong>Δημιουργήθηκε εθελοντής:</strong>
                                            <a href="volunteer-view.php?id=<?= $app['converted_user_id'] ?>">Προβολή προφίλ</a>
                                            (<?= $app['converted_at'] ? formatDateTime($app['converted_at']) : '' ?>)
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-semibold">Σημειώσεις Προσωπικού</label>
                                        <textarea name="admin_notes" class="form-control" rows="3" <?= $canManage ? '' : 'readonly' ?>><?= h($app['admin_notes'] ?? '') ?></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <a href="volunteer-application-print.php?id=<?= (int) $app['id'] ?>" target="_blank" rel="noopener"
                                       class="btn btn-outline-secondary me-auto">
                                        <i class="bi bi-printer"></i> Εκτύπωση / Εξαγωγή
                                    </a>
                                    <?php if ($canManage): ?>
                                    <?php if ($app['status'] !== VOL_APP_CONVERTED): ?>
                                    <?php if ($app['status'] !== VOL_APP_CONTACTED): ?>
                                    <button type="submit" name="new_status" value="<?= VOL_APP_CONTACTED ?>" class="btn btn-warning">Σε Επικοινωνία</button>
                                    <?php endif; ?>
                                    <?php if ($app['status'] !== VOL_APP_REJECTED): ?>
                                    <button type="submit" name="new_status" value="<?= VOL_APP_REJECTED ?>" class="btn btn-danger">Απόρριψη</button>
                                    <?php endif; ?>
                                    <?php if (in_array($app['status'], [VOL_APP_CONTACTED, VOL_APP_REJECTED], true)): ?>
                                    <button type="submit" name="new_status" value="<?= VOL_APP_NEW ?>" class="btn btn-outline-secondary">Επαναφορά σε Νέα</button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (in_array($app['status'], [VOL_APP_NEW, VOL_APP_CONTACTED], true)): ?>
                                    <a href="volunteer-form.php?from_application=<?= $app['id'] ?>" class="btn btn-success">
                                        <i class="bi bi-person-check"></i> Έγκριση &amp; Δημιουργία Εθελοντή
                                    </a>
                                    <?php endif; ?>
                                    <button type="submit" name="new_status" value="<?= h($app['status']) ?>" class="btn btn-outline-primary">Αποθήκευση Σημειώσεων</button>
                                <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php $modalsHtml[] = ob_get_clean(); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= implode('', $modalsHtml) ?>

<div class="mt-3">
    <?= paginationLinks($pagination) ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
