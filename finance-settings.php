<?php
/**
 * VolunteerOps — Ρυθμίσεις Οικονομικών (v3.510.0): accounts, categories, default VAT rate, which account each
 * dues payment method lands in, and the one-off import of past dues.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

$uid = (int) getCurrentUserId();
if (isPost()) {
    verifyCsrf();
    try {
        switch (post('action')) {
            case 'save_account':
                $id = (int) post('id');
                $name = mb_substr(trim(post('name')), 0, 100);
                if ($name === '') throw new InvalidArgumentException('Το όνομα λογαριασμού είναι υποχρεωτικό.');
                $kind = in_array(post('kind'), FINANCE_ACCOUNT_KINDS, true) ? post('kind') : 'OTHER';
                $openingDate = post('opening_date') !== '' ? post('opening_date') : null;
                if ($openingDate !== null && !financeValidDate($openingDate)) throw new InvalidArgumentException('Μη έγκυρη ημερομηνία αρχικού υπολοίπου.');
                $rawOpening = str_replace(' ', '', trim(post('opening_balance')));
                $openingCents = financeRequireCents($rawOpening);
                if ($openingCents !== 0 && $openingDate === null) throw new InvalidArgumentException('Συμπληρώστε και την ημερομηνία του αρχικού υπολοίπου: από αυτήν ξεκινούν τα βιβλία και δεν μετρώνται δύο φορές οι παλιές συνδρομές.'); // refuses «1.2.3», «abc»; accepts 1.500 / 1.234,56 / 1500.00
                $oldAccount = $id > 0 ? dbFetchOne("SELECT name, kind, iban, opening_balance, opening_date, is_active FROM finance_accounts WHERE id = ?", [$id]) : null;
                if ($id > 0 && !$oldAccount) throw new InvalidArgumentException('Ο λογαριασμός δεν βρέθηκε.');
                $params = [$name, $kind, strtoupper(preg_replace('/\s+/', '', post('iban'))) ?: null,
                           financeFromCents($openingCents), $openingDate, post('is_active', '1') === '1' ? 1 : 0];
                $newAccount = ['name' => $params[0], 'kind' => $params[1], 'iban' => $params[2], 'opening_balance' => $params[3], 'opening_date' => $params[4], 'is_active' => $params[5]];
                if ($id > 0) {
                    if ($params[5] === 0 && financeIsLastActiveAccount($id)) throw new InvalidArgumentException('Πρέπει να μείνει τουλάχιστον ένας ενεργός λογαριασμός.');
                    dbExecute("UPDATE finance_accounts SET name = ?, kind = ?, iban = ?, opening_balance = ?, opening_date = ?, is_active = ? WHERE id = ?", array_merge($params, [$id]));
                    logAudit('finance_update_account', 'finance_accounts', $id, $oldAccount, $newAccount);
                } else {
                    $next = (int) dbFetchValue("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM finance_accounts");
                    $id = (int) dbInsert("INSERT INTO finance_accounts (name, kind, iban, opening_balance, opening_date, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)", array_merge($params, [$next]));
                    logAudit('finance_create_account', 'finance_accounts', $id, null, $newAccount);
                }
                if ($openingDate !== null && !financeValidDate(financeSetting('finance_books_start', ''))) financeSaveSetting('finance_books_start', $openingDate); // the first opening date starts the books
                setFlash('success', 'Ο λογαριασμός αποθηκεύτηκε' . ($openingCents !== 0 ? ' (αρχικό υπόλοιπο ' . financeFormat($openingCents) . ').' : '.'));
                break;
            case 'delete_account':
                $id = (int) post('id');
                $delAccount = dbFetchOne("SELECT name, kind, iban, opening_balance, opening_date, is_active FROM finance_accounts WHERE id = ?", [$id]);
                if (!$delAccount) throw new InvalidArgumentException('Ο λογαριασμός δεν βρέθηκε.');
                if (financeIsLastActiveAccount($id)) throw new InvalidArgumentException('Πρέπει να μείνει τουλάχιστον ένας ενεργός λογαριασμός.');
                if ((int) dbFetchValue("SELECT COUNT(*) FROM finance_movements WHERE account_id = ?", [$id]) > 0) {
                    throw new InvalidArgumentException('Ο λογαριασμός έχει κινήσεις· μπορείτε μόνο να τον απενεργοποιήσετε.');
                }
                dbExecute("DELETE FROM finance_accounts WHERE id = ?", [$id]);
                logAudit('finance_delete_account', 'finance_accounts', $id, $delAccount, null);
                setFlash('success', 'Ο λογαριασμός διαγράφηκε.');
                break;
            case 'save_category':
                $id = (int) post('id');
                $name = mb_substr(trim(post('name')), 0, 100);
                if ($name === '') throw new InvalidArgumentException('Το όνομα κατηγορίας είναι υποχρεωτικό.');
                $active = post('is_active', '1') === '1' ? 1 : 0;
                if ($id > 0) {
                    $old = dbFetchOne("SELECT name, direction, is_active FROM finance_categories WHERE id = ?", [$id]);
                    if (!$old) throw new InvalidArgumentException('Η κατηγορία δεν βρέθηκε.');
                    if ($old['direction'] === 'IN' && $old['name'] === FINANCE_DUES_CATEGORY && $old['name'] !== $name) throw new InvalidArgumentException('Η κατηγορία «' . FINANCE_DUES_CATEGORY . '» χρησιμοποιείται από τις συνδρομές και δεν μετονομάζεται.');
                    if (dbFetchOne("SELECT id FROM finance_categories WHERE direction = ? AND name = ? AND id <> ?", [$old['direction'], $name, $id])) throw new InvalidArgumentException('Υπάρχει ήδη κατηγορία με αυτό το όνομα.');
                    dbExecute("UPDATE finance_categories SET name = ?, is_active = ? WHERE id = ?", [$name, $active, $id]);
                    logAudit('finance_update_category', 'finance_categories', $id, $old, ['name' => $name, 'direction' => $old['direction'], 'is_active' => $active]);
                } else {
                    $direction = in_array(post('direction'), FINANCE_DIRECTIONS, true) ? post('direction') : 'OUT';
                    if (dbFetchOne("SELECT id FROM finance_categories WHERE direction = ? AND name = ?", [$direction, $name])) throw new InvalidArgumentException('Υπάρχει ήδη κατηγορία με αυτό το όνομα.');
                    $next = (int) dbFetchValue("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM finance_categories WHERE direction = ?", [$direction]);
                    $id = (int) dbInsert("INSERT INTO finance_categories (name, direction, sort_order) VALUES (?, ?, ?)", [$name, $direction, $next]);
                    logAudit('finance_create_category', 'finance_categories', $id, null, ['name' => $name, 'direction' => $direction, 'is_active' => 1]);
                }
                setFlash('success', 'Η κατηγορία αποθηκεύτηκε.');
                break;
            case 'delete_category':
                $id = (int) post('id');
                $used = (int) dbFetchValue("SELECT (SELECT COUNT(*) FROM finance_documents WHERE category_id = ?) + (SELECT COUNT(*) FROM finance_movements WHERE category_id = ?)", [$id, $id]);
                if ($used > 0) throw new InvalidArgumentException('Η κατηγορία χρησιμοποιείται· μπορείτε μόνο να την απενεργοποιήσετε.');
                $cat = dbFetchOne("SELECT name, direction FROM finance_categories WHERE id = ?", [$id]);
                if (!$cat) throw new InvalidArgumentException('Η κατηγορία δεν βρέθηκε.');
                if ($cat['direction'] === 'IN' && $cat['name'] === FINANCE_DUES_CATEGORY) throw new InvalidArgumentException('Η κατηγορία των συνδρομών δεν διαγράφεται.');
                dbExecute("DELETE FROM finance_categories WHERE id = ?", [$id]);
                logAudit('finance_delete_category', 'finance_categories', $id, $cat, null);
                setFlash('success', 'Η κατηγορία διαγράφηκε.');
                break;
            case 'save_general':
                $rate = (int) post('default_vat_rate');
                $keys = ['finance_default_vat_rate' => (string) (in_array($rate, FINANCE_VAT_RATES, true) ? $rate : 24)];
                foreach (['cash', 'bank', 'card'] as $k) $keys['finance_dues_account_' . $k] = (string) (int) post('dues_account_' . $k);
                $start = trim(post('books_start'));
                if ($start !== '' && !financeValidDate($start)) throw new InvalidArgumentException('Μη έγκυρη ημερομηνία έναρξης βιβλίων.');
                $keys['finance_books_start'] = $start;
                $before = [];
                foreach ($keys as $k => $v) { $before[$k] = financeSetting($k, ''); financeSaveSetting($k, $v); }
                logAudit('finance_update_settings', 'settings', null, $before, $keys);
                setFlash('success', 'Οι ρυθμίσεις αποθηκεύτηκαν.');
                break;
            case 'import_dues':
                $n = financeImportPastDues(post('from_date'), $uid);
                logAudit('finance_import_dues', 'finance_movements', null, null, ['from' => post('from_date'), 'count' => $n]);
                setFlash('success', $n > 0 ? "Καταχωρήθηκαν $n συνδρομές ως έσοδα." : 'Δεν βρέθηκαν συνδρομές χωρίς κίνηση από αυτή την ημερομηνία.');
                break;
        }
    } catch (PDOException $e) { // first: PDOException extends RuntimeException
        error_log('finance-settings: ' . $e->getMessage());
        setFlash('error', 'Παρουσιάστηκε σφάλμα βάσης δεδομένων. Η ενέργεια δεν ολοκληρώθηκε.');
    } catch (InvalidArgumentException $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('finance-settings.php');
}

$pageTitle = 'Ρυθμίσεις Οικονομικών';
$accounts = financeAccountBalances();
$allAccountsList = financeAccounts(false);
$categories = financeCategories(null, false);
$editAccount = get('edit_account') ? dbFetchOne("SELECT * FROM finance_accounts WHERE id = ?", [(int) get('edit_account')]) : null;
$editCategory = get('edit_category') ? dbFetchOne("SELECT * FROM finance_categories WHERE id = ?", [(int) get('edit_category')]) : null;
$defaultRate = (int) financeSetting('finance_default_vat_rate', '24');
$kinds = ['CASH' => 'Μετρητά', 'BANK' => 'Τράπεζα', 'CARD' => 'Κάρτα', 'OTHER' => 'Άλλο'];
$duesLabels = ['cash' => 'Μετρητά / Άλλο', 'bank' => 'Τραπεζική κατάθεση / IRIS', 'card' => 'Κάρτα'];

include __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-gear me-2"></i>Ρυθμίσεις Οικονομικών</h1>
<?php displayFlash(); ?>
<?php if ($booksWarning = financeBooksStartWarning()): ?><div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1"></i><?= h($booksWarning) ?></div><?php endif; ?>

<div class="card mb-4"><div class="card-body">
    <h2 class="h5">Λογαριασμοί</h2>
    <div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Όνομα</th><th>Είδος</th><th>IBAN</th><th class="text-end">Αρχικό υπόλοιπο</th><th class="text-end">Υπόλοιπο</th><th></th></tr></thead>
        <tbody><?php foreach ($accounts as $a): ?>
            <tr class="<?= $a['is_active'] ? '' : 'text-muted' ?>">
                <td><?= h($a['name']) ?><?= $a['is_active'] ? '' : ' <span class="badge bg-secondary">ανενεργός</span>' ?></td>
                <td><?= $kinds[$a['kind']] ?></td><td class="small"><?= h($a['iban'] ?? '') ?></td>
                <td class="text-end"><?= financeFormat(financeToCents($a['opening_balance'])) ?><?= $a['opening_date'] ? '<div class="small text-muted">' . h(formatDate($a['opening_date'])) . '</div>' : '' ?></td>
                <td class="text-end fw-semibold"><?= financeFormat($a['balance']) ?></td>
                <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?edit_account=<?= (int) $a['id'] ?>"><i class="bi bi-pencil"></i></a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή λογαριασμού;');"><?= csrfField() ?><input type="hidden" name="action" value="delete_account"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></td>
            </tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <form method="post" class="row g-2 align-items-end border-top pt-3">
        <?= csrfField() ?><input type="hidden" name="action" value="save_account"><input type="hidden" name="id" value="<?= (int) ($editAccount['id'] ?? 0) ?>">
        <div class="col-md-3"><label class="form-label small">Όνομα</label><input class="form-control" name="name" required value="<?= h($editAccount['name'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label small">Είδος</label><select class="form-select" name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>" <?= ($editAccount['kind'] ?? 'BANK') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">IBAN</label><input class="form-control" name="iban" value="<?= h($editAccount['iban'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label small">Αρχικό υπόλοιπο</label><input class="form-control text-end" name="opening_balance" inputmode="decimal" value="<?= h(str_replace('.', ',', $editAccount['opening_balance'] ?? '0.00')) ?>"></div>
        <div class="col-md-2"><label class="form-label small">Ημ/νία αρχικού</label><input type="date" class="form-control" name="opening_date" value="<?= h($editAccount['opening_date'] ?? '') ?>"></div>
        <?php if ($editAccount): ?><div class="col-md-2"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="accActive" <?= $editAccount['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="accActive">Ενεργός</label></div></div><?php endif; ?>
        <div class="col-md-2"><button class="btn btn-primary"><?= $editAccount ? 'Αποθήκευση' : 'Προσθήκη' ?></button><?php if ($editAccount): ?> <a href="finance-settings.php" class="btn btn-link">Άκυρο</a><?php endif; ?></div>
    </form>
</div></div>

<div class="card mb-4"><div class="card-body">
    <h2 class="h5">Κατηγορίες</h2>
    <div class="row">
        <?php foreach (['OUT' => 'Έξοδα', 'IN' => 'Έσοδα'] as $d => $dl): ?>
        <div class="col-md-6"><h3 class="h6"><?= $dl ?></h3><ul class="list-group mb-3">
            <?php foreach (array_filter($categories, fn($c) => $c['direction'] === $d) as $c): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center <?= $c['is_active'] ? '' : 'text-muted' ?>">
                <span><?= h($c['name']) ?><?= $c['is_active'] ? '' : ' <span class="badge bg-secondary">ανενεργή</span>' ?></span>
                <span class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?edit_category=<?= (int) $c['id'] ?>"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή κατηγορίας;');"><?= csrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form></span>
            </li>
            <?php endforeach; ?>
        </ul></div>
        <?php endforeach; ?>
    </div>
    <form method="post" class="row g-2 align-items-end border-top pt-3">
        <?= csrfField() ?><input type="hidden" name="action" value="save_category"><input type="hidden" name="id" value="<?= (int) ($editCategory['id'] ?? 0) ?>">
        <div class="col-md-4"><label class="form-label small">Όνομα</label><input class="form-control" name="name" required value="<?= h($editCategory['name'] ?? '') ?>"></div>
        <?php if (!$editCategory): ?><div class="col-md-3"><label class="form-label small">Είδος</label><select class="form-select" name="direction"><option value="OUT">Έξοδο</option><option value="IN">Έσοδο</option></select></div>
        <?php else: ?><div class="col-md-3"><div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="catActive" <?= $editCategory['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="catActive">Ενεργή</label></div></div><?php endif; ?>
        <div class="col-md-3"><button class="btn btn-primary"><?= $editCategory ? 'Αποθήκευση' : 'Προσθήκη' ?></button><?php if ($editCategory): ?> <a href="finance-settings.php" class="btn btn-link">Άκυρο</a><?php endif; ?></div>
    </form>
</div></div>

<div class="card mb-4"><div class="card-body">
    <h2 class="h5">Γενικά και συνδρομές</h2>
    <form method="post" class="row g-3">
        <?= csrfField() ?><input type="hidden" name="action" value="save_general">
        <div class="col-md-3"><label class="form-label">Προεπιλεγμένος ΦΠΑ</label><select class="form-select" name="default_vat_rate">
            <?php foreach (FINANCE_VAT_RATES as $r): ?><option value="<?= $r ?>" <?= $r === $defaultRate ? 'selected' : '' ?>><?= $r ?>%</option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Έναρξη βιβλίων</label><input type="date" class="form-control" name="books_start" value="<?= h(financeSetting('finance_books_start', '')) ?>">
            <div class="form-text">Συνδρομές που πληρώθηκαν πριν από αυτή την ημερομηνία δεν γράφονται ως νέα έσοδα (το αρχικό υπόλοιπο τις περιλαμβάνει ήδη). Αν μείνει κενή, ισχύει η παλαιότερη ημερομηνία αρχικού υπολοίπου των ενεργών λογαριασμών.</div></div>
        <div class="col-12"><p class="mb-1">Σε ποιον λογαριασμό γράφεται κάθε πληρωμή συνδρομής, ανάλογα με τον τρόπο πληρωμής:</p></div>
        <?php foreach ($duesLabels as $k => $l):
            // Unset (0) or a deleted account falls back to the cash account anyway, so show that account selected
            // instead of a separate «(προεπιλογή)» entry that looked like a second cash account.
            $cur = (int) financeSetting('finance_dues_account_' . $k, '0');
            if (!in_array($cur, array_map('intval', array_column($allAccountsList, 'id')), true)) $cur = financeCashAccountId(); ?>
        <div class="col-md-4"><label class="form-label small"><?= $l ?></label><select class="form-select" name="dues_account_<?= $k ?>">
            <?php foreach ($allAccountsList as $a): if (!$a['is_active'] && $cur !== (int) $a['id']) continue; ?><option value="<?= (int) $a['id'] ?>" <?= $cur === (int) $a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?><?= $a['is_active'] ? '' : ' (ανενεργός)' ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="col-12"><button class="btn btn-primary">Αποθήκευση</button></div>
    </form>
    <hr>
    <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Καταχώρηση των παλαιών συνδρομών ως έσοδα;');">
        <?= csrfField() ?><input type="hidden" name="action" value="import_dues">
        <div class="col-12"><p class="mb-1">Εισαγωγή παλαιών συνδρομών: γράφει ως έσοδα τις συνδρομές που πληρώθηκαν από την ημερομηνία αυτή και μετά και δεν έχουν ήδη κίνηση. Βάλτε την ίδια ημερομηνία με το αρχικό υπόλοιπο των λογαριασμών, για να μη μετρηθούν δύο φορές. Αν δεν έχει οριστεί «Έναρξη βιβλίων», ορίζεται αυτόματα με αυτή την ημερομηνία.</p></div>
        <div class="col-md-3"><input type="date" class="form-control" name="from_date" required></div>
        <div class="col-md-3"><button class="btn btn-outline-primary">Εισαγωγή παλαιών συνδρομών</button></div>
    </form>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
