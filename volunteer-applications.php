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
            dbExecute(
                "UPDATE volunteer_applications SET status = ?, admin_notes = ?{$tsUpdate}, updated_at = NOW() WHERE id = ?",
                [$finalStatus, $notes, $id]
            );
            logAudit('update_status', 'volunteer_applications', $id, ['status' => $application['status']], ['status' => $finalStatus]);
            setFlash('success', 'Η αίτηση ενημερώθηκε.');
        }
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
                    <td><?= h($app['mobile_phone']) ?></td>
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
                                        <div class="col-md-6"><strong>Πατρώνυμο:</strong> <?= h($app['patronymic'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Ημ. Γέννησης:</strong> <?= $app['birth_date'] ? formatDate($app['birth_date']) : '—' ?></div>
                                        <div class="col-md-6"><strong>Διεύθυνση:</strong> <?= h($app['address'] ?: '—') ?></div>
                                        <div class="col-md-3"><strong>Τ.Κ.:</strong> <?= h($app['postal_code'] ?: '—') ?></div>
                                        <div class="col-md-3"><strong>Πόλη:</strong> <?= h($app['city'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Τηλ. Οικίας:</strong> <?= h($app['home_phone'] ?: '—') ?></div>
                                        <div class="col-md-6"><strong>Τηλ. Κινητό:</strong> <?= h($app['mobile_phone']) ?></div>
                                        <div class="col-md-6"><strong>Email:</strong> <?= h($app['email']) ?></div>
                                        <div class="col-md-6"><strong>Επάγγελμα:</strong> <?= h($app['occupation'] ?: '—') ?></div>
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
                                <?php if ($canManage): ?>
                                <div class="modal-footer">
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
                                </div>
                                <?php endif; ?>
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
