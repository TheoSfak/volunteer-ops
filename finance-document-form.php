<?php
/** VolunteerOps — Οικονομικά: new/edit document, with AI scan and payments (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
require_once __DIR__ . '/includes/ai-vision.php';
requirePermission('finance_manage');

$uid = (int) getCurrentUserId();
$id = (int) (get('id') ?: post('id'));
$existing = $id > 0 ? financeDocumentStatus($id) : null;
if ($id > 0 && !$existing) { setFlash('error', 'Το παραστατικό δεν βρέθηκε.'); redirect('finance-documents.php'); }
$direction = $existing['direction'] ?? (in_array(get('direction') ?: post('direction'), FINANCE_DIRECTIONS, true) ? (get('direction') ?: post('direction')) : 'OUT');
$back = 'finance-documents.php?direction=' . $direction;

if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $failUrl = $existing ? 'finance-document-form.php?id=' . $id . ($action === 'pay' ? '#pay' : '') : 'finance-document-form.php?direction=' . $direction;
    try {
        if ($action === 'delete' && $existing) {
            $row = financeDeleteDocument($id);
            logAudit('finance_delete_document', 'finance_documents', $id, $row);
            setFlash('success', 'Το παραστατικό διαγράφηκε. Οι πληρωμές του παραμένουν στις κινήσεις ως «χωρίς παραστατικό».');
            redirect($back);
        }
        if ($action === 'pay' && $existing) {
            $mov = financePayDocument($id, (int) post('pay_account_id'), post('pay_date'), financeToCents(post('pay_amount')), $uid);
            logAudit('finance_pay_document', 'finance_movements', $mov, null, ['document_id' => $id]);
            setFlash('success', 'Η πληρωμή καταχωρήθηκε.');
            redirect('finance-document-form.php?id=' . $id . '#pay');
        }
        if ($action === 'save') {
            $lines = [];
            foreach ((array) ($_POST['vat_rate'] ?? []) as $i => $rate) {
                $lines[] = ['rate' => (int) $rate, 'net' => financeToCents($_POST['vat_net'][$i] ?? ''), 'vat' => financeToCents($_POST['vat_vat'][$i] ?? '')];
            }
            $linesGross = financeDocumentTotals(array_filter($lines, fn($l) => $l['net'] >= 0 && $l['vat'] >= 0))['gross'];
            $typed = financeToCents(post('gross_typed'));
            if (trim(post('gross_typed')) !== '' && financeTotalsMismatch($typed, $linesGross) && post('total_confirmed') !== '1') {
                throw new InvalidArgumentException('Το σύνολο του παραστατικού (' . financeFormat($typed) . ') διαφέρει από το άθροισμα των γραμμών ΦΠΑ (' . financeFormat($linesGross) . '). Διορθώστε τα ποσά ή επιβεβαιώστε την απόκλιση.');
            }
            $upload = financeStoreUpload($_FILES['file'] ?? []);
            $doc = [
                'direction' => $direction, 'doc_type' => post('doc_type'), 'counterparty_name' => post('counterparty_name'),
                'counterparty_vat' => post('counterparty_vat'), 'doc_number' => post('doc_number'), 'doc_date' => post('doc_date'),
                'due_date' => post('due_date'), 'category_id' => post('category_id'), 'mission_id' => post('mission_id'),
                'vehicle_id' => post('vehicle_id'), 'notes' => post('notes'),
                'scan_source' => post('scan_source'), 'scan_provider' => post('scan_provider'),
            ];
            if ($upload) $doc += ['file_original_name' => $upload['original'], 'file_stored_name' => $upload['stored'], 'file_mime' => $upload['mime']];
            try {
                $payMov = null;
                $savedId = financeTransaction(function () use ($doc, $lines, $uid, $existing, $id, &$payMov) {
                    $savedId = financeSaveDocument($doc, $lines, $uid, $existing ? $id : null);
                    if (!$existing && post('pay_now') === '1') {
                        $st = financeDocumentStatus($savedId);
                        $payAccount = (int) post('pay_account_id');
                        $payDate = post('pay_date') ?: $doc['doc_date'];
                        $payMov = ['id' => financePayDocument($savedId, $payAccount, $payDate, $st['remaining'], $uid), 'account_id' => $payAccount, 'amount' => $st['remaining'], 'date' => $payDate];
                    }
                    return $savedId;
                });
            } catch (Throwable $e) {
                if ($upload) @unlink(financeUploadDir() . $upload['stored']);
                throw $e;
            }
            if ($upload && $existing && !empty($existing['file_stored_name'])) @unlink(financeUploadDir() . basename($existing['file_stored_name']));
            logAudit($existing ? 'finance_update_document' : 'finance_create_document', 'finance_documents', $savedId, $existing ?: null, $doc + ['vat_lines' => $lines]);
            if ($payMov) logAudit('finance_pay_document', 'finance_movements', $payMov['id'], null, ['document_id' => $savedId, 'account_id' => $payMov['account_id'], 'amount' => $payMov['amount'], 'date' => $payMov['date']]);
            setFlash('success', $existing ? 'Το παραστατικό ενημερώθηκε.' : 'Το παραστατικό καταχωρήθηκε.');
            redirect($existing ? 'finance-document-form.php?id=' . $savedId : $back);
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        setFlash('error', $e->getMessage());
        redirect($failUrl);
    } catch (PDOException $e) {
        error_log('finance-document-form: ' . $e->getMessage());
        setFlash('error', 'Παρουσιάστηκε σφάλμα βάσης δεδομένων. Η ενέργεια δεν ολοκληρώθηκε.');
        redirect($failUrl);
    }
}

$pageTitle = $existing ? 'Παραστατικό' : ($direction === 'IN' ? 'Νέο έσοδο' : 'Νέο έξοδο');
$categories = financeCategories($direction);
$accounts = financeAccounts();
if (!empty($existing['category_id']) && !in_array((int) $existing['category_id'], array_map('intval', array_column($categories, 'id')), true)) {
    $cur = dbFetchOne("SELECT id, name, direction FROM finance_categories WHERE id = ?", [(int) $existing['category_id']]);
    if ($cur) { $cur['name'] .= ' (ανενεργή)'; $categories[] = $cur; }
}
$missions = dbFetchAll("SELECT id, title FROM missions WHERE (deleted_at IS NULL OR id = ?) ORDER BY id DESC LIMIT 100", [(int) ($existing['mission_id'] ?? 0)]);
// Keep a saved mission that fell outside the newest 100 (or was soft-deleted) so an edit does not drop the link.
if (!empty($existing['mission_id']) && !in_array((int) $existing['mission_id'], array_map('intval', array_column($missions, 'id')), true)) {
    $m = dbFetchOne("SELECT id, title FROM missions WHERE id = ?", [(int) $existing['mission_id']]);
    if ($m) $missions[] = $m;
}
$vehicles = dbFetchAll("SELECT id, plate_number, label FROM vehicles ORDER BY plate_number");
$vatLines = $existing ? dbFetchAll("SELECT rate, net, vat FROM finance_document_vat WHERE document_id = ? ORDER BY id", [$id]) : [];
$payments = $existing ? dbFetchAll("SELECT m.*, a.name AS account_name FROM finance_movements m JOIN finance_accounts a ON a.id = m.account_id WHERE m.document_id = ? ORDER BY m.movement_date, m.id", [$id]) : [];
$defaultRate = (int) financeSetting('finance_default_vat_rate', '24');
$canScan = aiVisionAvailable();
$val = fn(string $k) => h((string) ($existing[$k] ?? ''));
$docTypes = ['INVOICE' => 'Τιμολόγιο', 'RECEIPT' => 'Απόδειξη', 'DONATION' => 'Απόδειξη δωρεάς', 'DEPOSIT' => 'Αποδεικτικό κατάθεσης', 'OTHER' => 'Άλλο'];
$rateOptions = function (int $selected): string {
    $o = '';
    foreach (FINANCE_VAT_RATES as $r) $o .= '<option value="' . $r . '"' . ($r === $selected ? ' selected' : '') . '>' . ($r === 0 ? '0% / Απαλλαγή' : $r . '%') . '</option>';
    return $o;
};
$lineRow = function (int $rate, string $net, string $vat) use ($rateOptions): string {
    return '<tr class="vat-line"><td><select class="form-select form-select-sm" name="vat_rate[]">' . $rateOptions($rate) . '</select></td>'
        . '<td><input class="form-control form-control-sm text-end" name="vat_net[]" inputmode="decimal" value="' . h($net) . '"></td>'
        . '<td><input class="form-control form-control-sm text-end" name="vat_vat[]" inputmode="decimal" value="' . h($vat) . '"></td>'
        . '<td><input class="form-control form-control-sm text-end vat-gross" inputmode="decimal"></td>'
        . '<td><button type="button" class="btn btn-sm btn-outline-danger vat-remove" title="Αφαίρεση"><i class="bi bi-x"></i></button></td></tr>';
};
$dec = fn($v) => str_replace('.', ',', (string) $v);

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-receipt me-2"></i><?= h($pageTitle) ?>
        <span class="badge <?= $direction === 'IN' ? 'bg-success' : 'bg-danger' ?> align-middle"><?= $direction === 'IN' ? 'Έσοδο' : 'Έξοδο' ?></span>
        <?php if ($existing): ?><?= financeStatusBadge($existing['status'], $direction) ?><?php endif; ?></h1>
    <a class="btn btn-outline-secondary" href="<?= h($back) ?>"><i class="bi bi-arrow-left me-1"></i>Πίσω</a>
</div>
<?php displayFlash(); ?>

<form id="financeDocumentForm" method="post" enctype="multipart/form-data"
      data-scan-url="finance-scan.php" data-csrf="<?= h(csrfToken()) ?>" data-default-rate="<?= $defaultRate ?>" data-direction="<?= h($direction) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="direction" value="<?= h($direction) ?>">
    <input type="hidden" name="scan_source" id="scanSource" value="MANUAL">
    <input type="hidden" name="scan_provider" id="scanProvider" value="">

    <div class="card mb-3"><div class="card-body">
        <h2 class="h6 mb-2"><i class="bi bi-camera me-1"></i>Αρχείο παραστατικού</h2>
        <input type="file" class="form-control" name="file" id="docFile" accept="image/jpeg,image/png,image/webp,application/pdf">
        <div class="form-text">Φωτογραφία ή PDF έως 10MB.<?php if ($existing && $existing['file_stored_name']): ?> Υπάρχει ήδη <a href="finance-file.php?id=<?= (int) $id ?>" target="_blank">αρχείο</a>· ένα νέο το αντικαθιστά.<?php endif; ?></div>
        <div class="d-flex flex-wrap gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary" id="btnCamera"><i class="bi bi-camera me-1"></i>Φωτογραφία</button>
            <?php if ($canScan): ?>
                <button type="button" class="btn btn-primary" id="btnScan" disabled><i class="bi bi-magic me-1"></i>Ανάγνωση με AI</button>
            <?php endif; ?>
        </div>
        <?php if ($canScan): ?>
            <div class="small text-muted mt-2"><i class="bi bi-shield-exclamation me-1"></i>Η εικόνα αποστέλλεται στον πάροχο AI (π.χ. Google) για ανάγνωση. Δεν αποθηκεύεται τίποτα πριν πατήσετε «Αποθήκευση».</div>
        <?php else: ?>
            <div class="small text-muted mt-2">Η ανάγνωση με AI χρειάζεται ενεργό AI με κλειδί Gemini, Groq ή Grok στις Ρυθμίσεις. Μπορείτε να συμπληρώσετε τα στοιχεία χειροκίνητα.</div>
        <?php endif; ?>
        <div id="scanStatus" class="small mt-2"></div>
    </div></div>

    <div class="card mb-3"><div class="card-body"><div class="row g-3">
        <div class="col-md-3"><label class="form-label">Τύπος</label><select class="form-select" name="doc_type" id="docType">
            <?php foreach ($docTypes as $k => $label): ?><option value="<?= $k ?>" <?= ($existing['doc_type'] ?? ($direction === 'IN' ? 'DONATION' : 'RECEIPT')) === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label"><?= $direction === 'IN' ? 'Από (δωρητής / πληρωτής)' : 'Εκδότης' ?> *</label><input class="form-control" name="counterparty_name" id="counterpartyName" maxlength="200" required value="<?= $val('counterparty_name') ?>"></div>
        <div class="col-md-4"><label class="form-label">ΑΦΜ</label><input class="form-control" name="counterparty_vat" id="counterpartyVat" inputmode="numeric" maxlength="9" value="<?= $val('counterparty_vat') ?>"></div>
        <div class="col-md-3"><label class="form-label">Αριθμός</label><input class="form-control" name="doc_number" id="docNumber" maxlength="50" value="<?= $val('doc_number') ?>"></div>
        <div class="col-md-3"><label class="form-label">Ημερομηνία *</label><input type="date" class="form-control" name="doc_date" id="docDate" required value="<?= $existing ? $val('doc_date') : date('Y-m-d') ?>"></div>
        <div class="col-md-3"><label class="form-label">Προθεσμία πληρωμής</label><input type="date" class="form-control" name="due_date" id="dueDate" value="<?= $val('due_date') ?>"></div>
        <div class="col-md-3"><label class="form-label">Κατηγορία</label><select class="form-select" name="category_id" id="categoryId"><option value="">—</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($existing['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Αποστολή (προαιρετικά)</label><select class="form-select" name="mission_id"><option value="">—</option>
            <?php foreach ($missions as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) ($existing['mission_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= h($m['title']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Όχημα (προαιρετικά)</label><select class="form-select" name="vehicle_id"><option value="">—</option>
            <?php foreach ($vehicles as $v): ?><option value="<?= (int) $v['id'] ?>" <?= (int) ($existing['vehicle_id'] ?? 0) === (int) $v['id'] ? 'selected' : '' ?>><?= h($v['plate_number'] . ' ' . $v['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"><?= $val('notes') ?></textarea></div>
    </div></div></div>

    <div class="card mb-3"><div class="card-body">
        <h2 class="h6">Ποσά και ΦΠΑ</h2>
        <p class="small text-muted mb-2">Συμπληρώστε το σύνολο ή το καθαρό ανά συντελεστή· τα υπόλοιπα υπολογίζονται αυτόματα.</p>
        <div class="table-responsive"><table class="table table-sm align-middle" id="vatLines">
            <thead><tr><th style="min-width:130px">Συντελεστής</th><th class="text-end">Καθαρό</th><th class="text-end">ΦΠΑ</th><th class="text-end">Σύνολο</th><th></th></tr></thead>
            <tbody>
            <?php if ($vatLines): foreach ($vatLines as $l): ?><?= $lineRow((int) round((float) $l['rate']), $dec($l['net']), $dec($l['vat'])) ?><?php endforeach; else: ?><?= $lineRow($defaultRate, '', '') ?><?php endif; ?>
            </tbody>
            <tfoot><tr class="fw-semibold"><td>Σύνολα</td><td class="text-end" id="totNet">0,00</td><td class="text-end" id="totVat">0,00</td><td class="text-end" id="totGross">0,00</td><td></td></tr></tfoot>
        </table></div>
        <template id="vatLineTemplate"><?= $lineRow($defaultRate, '', '') ?></template>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addVatLine"><i class="bi bi-plus"></i> Συντελεστής</button>
        <div class="row mt-3"><div class="col-md-4">
            <label class="form-label">Σύνολο όπως είναι τυπωμένο (έλεγχος)</label>
            <input class="form-control text-end" name="gross_typed" id="grossTyped" inputmode="decimal" value="<?= $existing ? h($dec($existing['gross_total'])) : '' ?>">
        </div></div>
        <div class="alert alert-warning mt-2 d-none" id="totalMismatch">
            Το τυπωμένο σύνολο διαφέρει από το άθροισμα των γραμμών πάνω από 0,02 €.
            <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="total_confirmed" value="1" id="totalConfirmed"><label class="form-check-label" for="totalConfirmed">Το έλεγξα, αποθήκευση με τις γραμμές ΦΠΑ.</label></div>
        </div>
    </div></div>

    <?php if (!$existing): ?>
    <div class="card mb-3"><div class="card-body">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pay_now" value="1" id="payNow" <?= $accounts ? 'checked' : 'disabled' ?>>
            <label class="form-check-label" for="payNow"><?= $direction === 'IN' ? 'Εισπράχθηκε ήδη' : 'Πληρώθηκε ήδη' ?></label></div>
        <div class="row g-2 mt-1">
            <div class="col-md-4"><select class="form-select" name="pay_account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><input type="date" class="form-control" name="pay_date" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="form-text">Ξετσεκάρετε για τιμολόγιο που οφείλεται ακόμα: θα εμφανιστεί στις Υποχρεώσεις.</div>
    </div></div>
    <?php endif; ?>

    <button class="btn btn-primary btn-lg"><i class="bi bi-check2 me-1"></i>Αποθήκευση</button>
</form>

<?php if ($existing): ?>
<div class="card mt-4" id="pay"><div class="card-body">
    <h2 class="h6"><?= $direction === 'IN' ? 'Εισπράξεις' : 'Πληρωμές' ?></h2>
    <p class="mb-2">Σύνολο <?= financeFormat($existing['gross']) ?> · <?= $direction === 'IN' ? 'εισπράχθηκαν' : 'πληρώθηκαν' ?> <?= financeFormat($existing['paid']) ?> · <strong>υπόλοιπο <?= financeFormat($existing['remaining']) ?></strong></p>
    <?php if ($payments): ?><ul class="list-group mb-3"><?php foreach ($payments as $p): ?>
        <li class="list-group-item d-flex justify-content-between"><span><?= h(formatDate($p['movement_date'])) ?> · <?= h($p['account_name']) ?></span><span><?= financeFormat(financeToCents($p['amount'])) ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <?php if ($existing['remaining'] > 0): ?>
    <form method="post" class="row g-2 align-items-end">
        <?= csrfField() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="col-md-4"><label class="form-label">Λογαριασμός</label><select class="form-select" name="pay_account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Ημερομηνία</label><input type="date" class="form-control" name="pay_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-3"><label class="form-label">Ποσό</label><input class="form-control text-end" name="pay_amount" inputmode="decimal" value="<?= h($dec(financeFromCents($existing['remaining']))) ?>"></div>
        <div class="col-md-2"><button class="btn btn-success w-100"><?= $direction === 'IN' ? 'Είσπραξη' : 'Πληρωμή' ?></button></div>
    </form>
    <?php endif; ?>
</div></div>
<form method="post" class="mt-3" onsubmit="return confirm('Διαγραφή του παραστατικού και του αρχείου του; Οι πληρωμές του θα μείνουν στις κινήσεις.');">
    <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $id ?>">
    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Διαγραφή παραστατικού</button>
</form>
<?php endif; ?>

<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/finance-form.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . '/assets/js/finance-form.js') ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
