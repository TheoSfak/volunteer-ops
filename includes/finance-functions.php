<?php
/**
 * VolunteerOps — Οικονομικά (v3.510.0): money, VAT, the cash book, dues sync, reports, reminders.
 * Spec: docs/superpowers/specs/2026-11-22-finance-design.md
 *
 * Money is integer cents in every PHP computation and DECIMAL(12,2) strings in the database. Never add or
 * multiply floats of euros: 0.1 + 0.2 is not 0.3, and a cash book that is a cent out is a cash book nobody trusts.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const FINANCE_VAT_RATES = [24, 13, 6, 17, 9, 4, 0];
const FINANCE_TOTAL_TOLERANCE_CENTS = 2;
const FINANCE_DIRECTIONS = ['IN', 'OUT'];
const FINANCE_DOC_TYPES = ['INVOICE', 'RECEIPT', 'DONATION', 'DEPOSIT', 'OTHER'];
const FINANCE_ACCOUNT_KINDS = ['CASH', 'BANK', 'CARD', 'OTHER'];
const FINANCE_DUES_CATEGORY = 'Συνδρομές μελών';

// ─── Pure helpers ────────────────────────────────────────────────────────────

/** Plain decimal ("12.5", "1500.00") → cents, dot as the only separator; null when it is not that. Rounds half up. */
function financeParseDecimalCents(string $s): ?int {
    if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d+))?$/D', $s, $m)) return null;
    $frac = str_pad($m[3] ?? '', 3, '0');
    $cents = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);
    return $m[1] === '-' ? -$cents : $cents;
}

/**
 * Greek-style amount typed by a person → cents; null when it does not parse (so callers can refuse it instead of
 * silently booking 0). «1.234,56», «1.500», «12,40», «1500.00», «12.5». With a comma, the dots are thousands
 * separators and the comma is the decimal mark. Without one, a single dot followed by exactly three digits after a
 * 1–3 digit integer part (not 0) is a thousands separator («1.500»); several dots must be valid groups («1.000.000»);
 * any other single dot is the decimal mark. Mirrored by parseCents() in assets/js/finance-form.js.
 */
function financeParseCents(string $value): ?int {
    $s = preg_replace('/[\s\x{00A0}]+/u', '', $value);
    if ($s === null || $s === '') return null;
    $sign = '';
    if ($s[0] === '-') { $sign = '-'; $s = substr($s, 1); }
    $group = '/^\d{1,3}(?:\.\d{3})+$/D';
    if (str_contains($s, ',')) {
        if (substr_count($s, ',') !== 1) return null;
        [$int, $frac] = explode(',', $s);
        if (!preg_match('/^\d+$/D', $frac)) return null;
        if (str_contains($int, '.')) {
            if (!preg_match($group, $int)) return null;
            $int = str_replace('.', '', $int);
        }
        return financeParseDecimalCents($sign . $int . '.' . $frac);
    }
    $dots = substr_count($s, '.');
    if ($dots >= 2) {
        return preg_match($group, $s) ? financeParseDecimalCents($sign . str_replace('.', '', $s)) : null;
    }
    if ($dots === 1 && preg_match('/^([1-9]\d{0,2})\.(\d{3})$/D', $s, $m)) {
        return financeParseDecimalCents($sign . $m[1] . $m[2]);
    }
    return financeParseDecimalCents($sign . $s);
}

/** Existing callers (database values, computed numbers): same parser, 0 for anything invalid. Pages that read what a person typed use financeParseCents()/financeRequireCents() instead. */
function financeToCents($value): int {
    if ($value === null || is_bool($value) || is_array($value)) return 0;
    if (is_int($value) || is_float($value)) return financeParseDecimalCents(trim((string) $value)) ?? 0;
    return financeParseCents((string) $value) ?? 0;
}

/** Amount typed on a page: empty → 0 (the usual "must be positive" checks then speak); non-empty and unparseable → refused in Greek. */
function financeRequireCents($value): int {
    $raw = trim((string) $value);
    if ($raw === '') return 0;
    $cents = financeParseCents($raw);
    if ($cents === null) throw new InvalidArgumentException('Μη έγκυρο ποσό «' . mb_substr($raw, 0, 30) . '». Γράψτε π.χ. 1.234,56');
    return $cents;
}

function financeFromCents(int $cents): string {
    $abs = abs($cents);
    return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($abs, 100), $abs % 100);
}

function financeFormat(int $cents): string {
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

/** Gross → net + VAT; net is rounded, VAT is the remainder, so net + VAT is always exactly the gross. */
function financeSplitGross(int $grossCents, int $rate): array {
    $net = (int) round($grossCents * 100 / (100 + $rate));
    return ['rate' => $rate, 'net' => $net, 'vat' => $grossCents - $net, 'gross' => $grossCents];
}

function financeSplitNet(int $netCents, int $rate): array {
    $vat = (int) round($netCents * $rate / 100);
    return ['rate' => $rate, 'net' => $netCents, 'vat' => $vat, 'gross' => $netCents + $vat];
}

function financeDocumentTotals(array $lines): array {
    $net = 0;
    $vat = 0;
    foreach ($lines as $l) {
        $net += (int) $l['net'];
        $vat += (int) $l['vat'];
    }
    return ['net' => $net, 'vat' => $vat, 'gross' => $net + $vat];
}

/** Real receipts round per line, so a couple of cents between the printed total and the lines is normal. */
function financeTotalsMismatch(int $typedGross, int $linesGross): bool {
    return abs($typedGross - $linesGross) > FINANCE_TOTAL_TOLERANCE_CENTS;
}

function financePaidStatus(int $gross, int $paid): string {
    if ($paid >= $gross) return 'PAID';
    return $paid <= 0 ? 'OPEN' : 'PARTIAL';
}

function financeDueUrgency(?string $due, string $today): string {
    if ($due === null || $due === '') return 'none';
    if ($due < $today) return 'overdue';
    return $due <= date('Y-m-d', strtotime($today . ' +7 days')) ? 'soon' : 'later';
}

function financeReminderStageFor(string $due, string $today): int {
    if ($due < $today) return 3;
    if ($due === $today) return 2;
    return $due <= date('Y-m-d', strtotime($today . ' +7 days')) ? 1 : 0;
}

/** Greek ΑΦΜ: 9 digits, weights 2^8..2^1 on the first eight, (sum mod 11) mod 10 = the ninth. */
function financeValidVatNumber(string $afm): bool {
    if (!preg_match('/^\d{9}$/D', $afm) || $afm === '000000000') return false;
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $sum += (int) $afm[$i] * (2 ** (8 - $i));
    }
    return ($sum % 11) % 10 === (int) $afm[8];
}

function financeValidDate($value): bool {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// ─── Infrastructure ──────────────────────────────────────────────────────────

/** Run $fn in a transaction, or inside the caller's when one is already open (PDO has no nesting). */
function financeTransaction(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Read straight from the table: getSetting() caches for the whole process, which would hide a value saved a moment ago. */
function financeSetting(string $key, string $default = ''): string {
    $v = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $v === null || $v === false ? $default : (string) $v;
}

function financeSaveSetting(string $key, string $value): void {
    dbExecute("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$key, $value]);
}

function financeUploadDir(): string {
    return dirname(__DIR__) . '/uploads/finance/';
}

function financeAccounts(bool $activeOnly = true): array {
    return dbFetchAll("SELECT * FROM finance_accounts" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, id");
}

function financeCategories(?string $direction = null, bool $activeOnly = true): array {
    $where = [];
    $params = [];
    if ($direction !== null) { $where[] = "direction = ?"; $params[] = $direction; }
    if ($activeOnly) $where[] = "is_active = 1";
    return dbFetchAll("SELECT id, name, direction, is_active, sort_order FROM finance_categories"
        . ($where ? " WHERE " . implode(' AND ', $where) : "") . " ORDER BY direction, sort_order, name", $params);
}

function financeStatusBadge(string $status, string $direction): string {
    $in = $direction === 'IN';
    return match ($status) {
        'PAID'    => '<span class="badge bg-success">' . ($in ? 'Εισπράχθηκε' : 'Εξοφλημένο') . '</span>',
        'PARTIAL' => '<span class="badge bg-warning text-dark">Μερικώς</span>',
        default   => '<span class="badge bg-danger">' . ($in ? 'Ανείσπρακτο' : 'Απλήρωτο') . '</span>',
    };
}

// ─── Documents ───────────────────────────────────────────────────────────────

function financeDocumentPaidCents(int $documentId): int {
    return financeToCents(dbFetchValue("SELECT COALESCE(SUM(amount), 0) FROM finance_movements WHERE document_id = ?", [$documentId]));
}

function financeDocumentStatus(int $documentId): ?array {
    $doc = dbFetchOne("SELECT * FROM finance_documents WHERE id = ?", [$documentId]);
    if (!$doc) return null;
    $gross = financeToCents($doc['gross_total']);
    $paid = financeDocumentPaidCents($documentId);
    return $doc + ['gross' => $gross, 'paid' => $paid, 'remaining' => max(0, $gross - $paid), 'status' => financePaidStatus($gross, $paid)];
}

/**
 * Create ($id null) or update a document and replace its VAT lines. File fields are written only when
 * $doc['file_stored_name'] is given, so an edit without a new file keeps the old one.
 */
function financeSaveDocument(array $doc, array $vatLines, int $userId, ?int $id = null): int {
    $direction = (string) ($doc['direction'] ?? '');
    if (!in_array($direction, FINANCE_DIRECTIONS, true)) throw new InvalidArgumentException('Μη έγκυρο είδος παραστατικού (έσοδο/έξοδο).');
    $docType = in_array($doc['doc_type'] ?? '', FINANCE_DOC_TYPES, true) ? $doc['doc_type'] : 'OTHER';
    $name = mb_substr(trim((string) ($doc['counterparty_name'] ?? '')), 0, 200);
    if ($name === '') throw new InvalidArgumentException('Συμπληρώστε τον εκδότη / αντισυμβαλλόμενο.');
    if (!financeValidDate($doc['doc_date'] ?? null)) throw new InvalidArgumentException('Μη έγκυρη ημερομηνία παραστατικού.');
    $due = (string) ($doc['due_date'] ?? '') !== '' ? (string) $doc['due_date'] : null;
    if ($due !== null && !financeValidDate($due)) throw new InvalidArgumentException('Μη έγκυρη προθεσμία πληρωμής.');
    $vatNo = preg_replace('/\D/', '', (string) ($doc['counterparty_vat'] ?? ''));
    if ($vatNo !== '' && !financeValidVatNumber($vatNo)) throw new InvalidArgumentException('Ο ΑΦΜ δεν είναι έγκυρος.');

    $lines = [];
    foreach ($vatLines as $l) {
        $rate = (int) ($l['rate'] ?? -1);
        if (!in_array($rate, FINANCE_VAT_RATES, true)) throw new InvalidArgumentException('Μη έγκυρος συντελεστής ΦΠΑ.');
        $net = (int) ($l['net'] ?? 0);
        $vat = (int) ($l['vat'] ?? 0);
        if ($net < 0 || $vat < 0) throw new InvalidArgumentException('Τα ποσά του ΦΠΑ δεν μπορούν να είναι αρνητικά.');
        if ($net + $vat === 0) continue; // an empty row of the form
        $lines[] = ['rate' => $rate, 'net' => $net, 'vat' => $vat];
    }
    if (!$lines) throw new InvalidArgumentException('Συμπληρώστε τουλάχιστον ένα ποσό.');
    $t = financeDocumentTotals($lines);

    $categoryId = !empty($doc['category_id']) ? (int) $doc['category_id'] : null;
    if ($categoryId !== null && !dbFetchOne("SELECT id FROM finance_categories WHERE id = ? AND direction = ?", [$categoryId, $direction])) {
        throw new InvalidArgumentException('Η κατηγορία δεν ταιριάζει με το είδος του παραστατικού.');
    }

    $values = [
        $direction, $docType, $name, $vatNo !== '' ? $vatNo : null,
        ($n = mb_substr(trim((string) ($doc['doc_number'] ?? '')), 0, 50)) !== '' ? $n : null,
        $doc['doc_date'], $due,
        financeFromCents($t['net']), financeFromCents($t['vat']), financeFromCents($t['gross']),
        $categoryId,
        !empty($doc['mission_id']) ? (int) $doc['mission_id'] : null,
        !empty($doc['vehicle_id']) ? (int) $doc['vehicle_id'] : null,
        ($notes = trim((string) ($doc['notes'] ?? ''))) !== '' ? $notes : null,
    ];

    return financeTransaction(function () use ($doc, $values, $lines, $userId, $id, $due, $direction, $t) {
        if ($id !== null) {
            // Lock the row so a payment arriving at the same moment cannot slip past these checks.
            $existing = dbFetchOne("SELECT direction FROM finance_documents WHERE id = ? FOR UPDATE", [$id]);
            if (!$existing) throw new InvalidArgumentException('Το παραστατικό δεν βρέθηκε.');
            $paid = financeDocumentPaidCents($id);
            if ($paid > 0 && $existing['direction'] !== $direction) throw new InvalidArgumentException('Δεν αλλάζει το είδος ενός παραστατικού που έχει πληρωμές.');
            if ($paid > $t['gross']) throw new InvalidArgumentException('Το σύνολο δεν μπορεί να είναι μικρότερο από όσα έχουν ήδη πληρωθεί (' . financeFormat($paid) . ').');
        }
        if ($id === null) {
            $id = (int) dbInsert("INSERT INTO finance_documents
                (direction, doc_type, counterparty_name, counterparty_vat, doc_number, doc_date, due_date, net_total, vat_total, gross_total,
                 category_id, mission_id, vehicle_id, notes, file_original_name, file_stored_name, file_mime, scan_source, scan_provider, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", array_merge($values, [
                $doc['file_original_name'] ?? null, $doc['file_stored_name'] ?? null, $doc['file_mime'] ?? null,
                ($doc['scan_source'] ?? '') === 'AI' ? 'AI' : 'MANUAL',
                ($p = trim((string) ($doc['scan_provider'] ?? ''))) !== '' ? mb_substr($p, 0, 50) : null,
                $userId,
            ]));
        } else {
            // reminder_stage FIRST: MySQL evaluates SET left to right on the new values, so the due_date comparison
            // must run before due_date is overwritten. A moved due date earns its reminders again.
            dbExecute("UPDATE finance_documents SET reminder_stage = IF(due_date <=> ?, reminder_stage, 0),
                direction = ?, doc_type = ?, counterparty_name = ?, counterparty_vat = ?, doc_number = ?, doc_date = ?, due_date = ?,
                net_total = ?, vat_total = ?, gross_total = ?, category_id = ?, mission_id = ?, vehicle_id = ?, notes = ?, updated_by = ?
                WHERE id = ?", array_merge([$due], $values, [$userId, $id]));
            if (!empty($doc['file_stored_name'])) {
                dbExecute("UPDATE finance_documents SET file_original_name = ?, file_stored_name = ?, file_mime = ? WHERE id = ?",
                    [$doc['file_original_name'] ?? null, $doc['file_stored_name'], $doc['file_mime'] ?? null, $id]);
            }
            dbExecute("DELETE FROM finance_document_vat WHERE document_id = ?", [$id]);
        }
        foreach ($lines as $l) {
            dbExecute("INSERT INTO finance_document_vat (document_id, rate, net, vat) VALUES (?, ?, ?, ?)",
                [$id, $l['rate'], financeFromCents($l['net']), financeFromCents($l['vat'])]);
        }
        return $id;
    });
}

/**
 * Deletes the document, its VAT lines (FK cascade) and its file; its movements stay, unlinked (FK set null) — and
 * first take over the document's category, so past category reports keep the money where it was.
 */
function financeDeleteDocument(int $id): ?array {
    $row = dbFetchOne("SELECT * FROM finance_documents WHERE id = ?", [$id]);
    if (!$row) return null;
    financeTransaction(function () use ($id, $row) {
        if (!empty($row['category_id'])) {
            dbExecute("UPDATE finance_movements SET category_id = ? WHERE document_id = ? AND category_id IS NULL", [(int) $row['category_id'], $id]);
        }
        dbExecute("DELETE FROM finance_documents WHERE id = ?", [$id]);
    });
    if (!empty($row['file_stored_name'])) {
        $path = financeUploadDir() . basename((string) $row['file_stored_name']);
        if (is_file($path)) @unlink($path);
    }
    return $row;
}

// ─── Movements ───────────────────────────────────────────────────────────────

function financeAddMovement(array $m, int $userId): int {
    $accountId = (int) ($m['account_id'] ?? 0);
    if (!dbFetchOne("SELECT id FROM finance_accounts WHERE id = ? AND is_active = 1", [$accountId])) throw new InvalidArgumentException('Επιλέξτε ενεργό λογαριασμό.');
    $direction = (string) ($m['direction'] ?? '');
    if (!in_array($direction, FINANCE_DIRECTIONS, true)) throw new InvalidArgumentException('Μη έγκυρο είδος κίνησης.');
    $amount = (int) ($m['amount'] ?? 0);
    if ($amount <= 0) throw new InvalidArgumentException('Το ποσό πρέπει να είναι θετικό.');
    if (!financeValidDate($m['movement_date'] ?? null)) throw new InvalidArgumentException('Μη έγκυρη ημερομηνία κίνησης.');
    $documentId = !empty($m['document_id']) ? (int) $m['document_id'] : null;
    $categoryId = null;
    if ($documentId === null && !empty($m['category_id'])) {
        $categoryId = (int) $m['category_id'];
        if (!dbFetchOne("SELECT id FROM finance_categories WHERE id = ? AND direction = ?", [$categoryId, $direction])) {
            throw new InvalidArgumentException('Η κατηγορία δεν ταιριάζει με το είδος της κίνησης.');
        }
    }
    return financeTransaction(function () use ($accountId, $direction, $amount, $m, $documentId, $categoryId, $userId) {
        if ($documentId !== null) {
            // Lock the document row: concurrent payments of the same document queue up here, so the remaining amount
            // read below is the real one.
            $doc = dbFetchOne("SELECT direction, gross_total FROM finance_documents WHERE id = ? FOR UPDATE", [$documentId]);
            if (!$doc) throw new InvalidArgumentException('Το παραστατικό δεν βρέθηκε.');
            if ($doc['direction'] !== $direction) throw new InvalidArgumentException('Η κίνηση πρέπει να έχει το ίδιο είδος (έσοδο/έξοδο) με το παραστατικό.');
            // Locking read: sees every committed payment even when the caller's outer transaction already holds a snapshot.
            $paid = financeToCents(dbFetchValue("SELECT COALESCE(SUM(amount), 0) FROM finance_movements WHERE document_id = ? FOR UPDATE", [$documentId]));
            $remaining = max(0, financeToCents($doc['gross_total']) - $paid);
            if ($amount > $remaining) throw new InvalidArgumentException('Το ποσό ξεπερνά το υπόλοιπο (' . financeFormat($remaining) . ').');
        }
        return (int) dbInsert("INSERT INTO finance_movements
            (account_id, direction, amount, movement_date, document_id, category_id, transfer_group, subscription_id, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            $accountId, $direction, financeFromCents($amount), $m['movement_date'], $documentId, $categoryId,
            $m['transfer_group'] ?? null, !empty($m['subscription_id']) ? (int) $m['subscription_id'] : null,
            ($d = mb_substr(trim((string) ($m['description'] ?? '')), 0, 255)) !== '' ? $d : null, $userId,
        ]);
    });
}

function financePayDocument(int $documentId, int $accountId, string $date, int $amountCents, int $userId): int {
    $doc = dbFetchOne("SELECT direction, counterparty_name FROM finance_documents WHERE id = ?", [$documentId]);
    if (!$doc) throw new InvalidArgumentException('Το παραστατικό δεν βρέθηκε.');
    // The remaining-amount check (under a row lock) lives in financeAddMovement.
    return financeAddMovement([
        'account_id' => $accountId, 'direction' => $doc['direction'], 'amount' => $amountCents, 'movement_date' => $date,
        'document_id' => $documentId, 'description' => ($doc['direction'] === 'OUT' ? 'Πληρωμή ' : 'Είσπραξη ') . $doc['counterparty_name'],
    ], $userId);
}

function financeTransfer(int $fromId, int $toId, int $amountCents, string $date, string $description, int $userId): string {
    if ($fromId === $toId) throw new InvalidArgumentException('Επιλέξτε δύο διαφορετικούς λογαριασμούς.');
    $group = bin2hex(random_bytes(8));
    $desc = trim($description) !== '' ? trim($description) : 'Μεταφορά';
    financeTransaction(function () use ($fromId, $toId, $amountCents, $date, $desc, $userId, $group) {
        financeAddMovement(['account_id' => $fromId, 'direction' => 'OUT', 'amount' => $amountCents, 'movement_date' => $date, 'transfer_group' => $group, 'description' => $desc], $userId);
        financeAddMovement(['account_id' => $toId, 'direction' => 'IN', 'amount' => $amountCents, 'movement_date' => $date, 'transfer_group' => $group, 'description' => $desc], $userId);
    });
    return $group;
}

/** Deletes a movement (both halves of a transfer). A dues movement belongs to Συνδρομές and is refused here. */
function financeDeleteMovement(int $id): array {
    $m = dbFetchOne("SELECT * FROM finance_movements WHERE id = ?", [$id]);
    if (!$m) throw new InvalidArgumentException('Η κίνηση δεν βρέθηκε.');
    if (!empty($m['subscription_id'])) throw new InvalidArgumentException('Οι κινήσεις συνδρομών αλλάζουν μόνο από τη σελίδα Ετήσιες Συνδρομές.');
    if (!empty($m['transfer_group'])) {
        $rows = dbFetchAll("SELECT * FROM finance_movements WHERE transfer_group = ?", [$m['transfer_group']]);
        dbExecute("DELETE FROM finance_movements WHERE transfer_group = ?", [$m['transfer_group']]);
        return $rows;
    }
    dbExecute("DELETE FROM finance_movements WHERE id = ?", [$id]);
    return [$m];
}

// ─── Balances, payables, reports ─────────────────────────────────────────────

function financeAccountBalances(): array {
    $rows = dbFetchAll("SELECT a.*,
            COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN m.direction = 'OUT' THEN m.amount END), 0) AS total_out
        FROM finance_accounts a LEFT JOIN finance_movements m ON m.account_id = a.id
        GROUP BY a.id ORDER BY a.is_active DESC, a.sort_order, a.id");
    foreach ($rows as &$r) {
        $r['balance'] = financeToCents($r['opening_balance']) + financeToCents($r['total_in']) - financeToCents($r['total_out']);
    }
    return $rows;
}

const FINANCE_PAID_JOIN = "LEFT JOIN (SELECT document_id, SUM(amount) AS paid FROM finance_movements WHERE document_id IS NOT NULL GROUP BY document_id) p ON p.document_id = d.id";

function financeOpenPayables(string $today): array {
    $rows = dbFetchAll("SELECT d.*, c.name AS category_name, COALESCE(p.paid, 0) AS paid_sum
        FROM finance_documents d LEFT JOIN finance_categories c ON c.id = d.category_id " . FINANCE_PAID_JOIN . "
        WHERE d.direction = 'OUT' AND COALESCE(p.paid, 0) < d.gross_total
        ORDER BY d.due_date IS NULL, d.due_date, d.doc_date, d.id");
    foreach ($rows as &$r) {
        $r['paid'] = financeToCents($r['paid_sum']);
        $r['remaining'] = financeToCents($r['gross_total']) - $r['paid'];
        $r['urgency'] = financeDueUrgency($r['due_date'], $today);
    }
    return $rows;
}

function financePeriodTotals(string $from, string $to): array {
    $r = dbFetchOne("SELECT COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount END), 0) AS tin,
                            COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount END), 0) AS tout
                     FROM finance_movements WHERE movement_date BETWEEN ? AND ? AND transfer_group IS NULL", [$from, $to]);
    return ['in' => financeToCents($r['tin']), 'out' => financeToCents($r['tout'])];
}

/** Cash basis: what actually moved in the period, by the document's category (or the movement's own). */
function financeReportByCategory(string $from, string $to): array {
    $rows = dbFetchAll("SELECT m.direction, COALESCE(c1.id, c2.id) AS cat_id, COALESCE(c1.name, c2.name, 'Χωρίς κατηγορία') AS cat_name, SUM(m.amount) AS total
        FROM finance_movements m
        LEFT JOIN finance_documents d ON d.id = m.document_id
        LEFT JOIN finance_categories c1 ON c1.id = d.category_id
        LEFT JOIN finance_categories c2 ON c2.id = m.category_id
        WHERE m.movement_date BETWEEN ? AND ? AND m.transfer_group IS NULL
        GROUP BY m.direction, cat_id, cat_name ORDER BY m.direction, total DESC", [$from, $to]);
    return array_map(fn($r) => ['direction' => $r['direction'], 'category_id' => $r['cat_id'] !== null ? (int) $r['cat_id'] : null, 'category' => $r['cat_name'], 'total' => financeToCents($r['total'])], $rows);
}

function financeReportByAccount(string $from, string $to): array {
    $rows = dbFetchAll("SELECT a.id, a.name,
            COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount END), 0) AS tin,
            COALESCE(SUM(CASE WHEN m.direction = 'OUT' THEN m.amount END), 0) AS tout
        FROM finance_accounts a LEFT JOIN finance_movements m ON m.account_id = a.id AND m.movement_date BETWEEN ? AND ?
        GROUP BY a.id ORDER BY a.sort_order, a.id", [$from, $to]);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'total_in' => financeToCents($r['tin']), 'total_out' => financeToCents($r['tout'])], $rows);
}

/** Document basis (by document date): the VAT printed on the papers of the period, per rate. */
function financeVatSummary(string $from, string $to): array {
    $rows = dbFetchAll("SELECT d.direction, v.rate, SUM(v.net) AS net, SUM(v.vat) AS vat
        FROM finance_document_vat v JOIN finance_documents d ON d.id = v.document_id
        WHERE d.doc_date BETWEEN ? AND ? GROUP BY d.direction, v.rate ORDER BY d.direction, v.rate DESC", [$from, $to]);
    return array_map(function ($r) {
        $net = financeToCents($r['net']);
        $vat = financeToCents($r['vat']);
        return ['direction' => $r['direction'], 'rate' => (int) round((float) $r['rate']), 'net' => $net, 'vat' => $vat, 'gross' => $net + $vat];
    }, $rows);
}

// ─── Dues (Ετήσιες Συνδρομές) → cash book ────────────────────────────────────

const FINANCE_DUES_METHOD_KEYS = ['Μετρητά' => 'cash', 'Τραπεζική κατάθεση' => 'bank', 'IRIS' => 'bank', 'Κάρτα' => 'card'];

/** True when $id is the only active account — deactivating or deleting it would leave nowhere to book into. */
function financeIsLastActiveAccount(int $id): bool {
    if (!dbFetchOne("SELECT id FROM finance_accounts WHERE id = ? AND is_active = 1", [$id])) return false;
    return (int) dbFetchValue("SELECT COUNT(*) FROM finance_accounts WHERE is_active = 1 AND id <> ?", [$id]) === 0;
}

function financeDuesMethodKey(?string $method): string {
    return FINANCE_DUES_METHOD_KEYS[trim((string) $method)] ?? 'cash';
}

function financeCashAccountId(): int {
    return (int) (dbFetchValue("SELECT id FROM finance_accounts WHERE is_active = 1 ORDER BY kind = 'CASH' DESC, sort_order, id LIMIT 1") ?: 0);
}

/** An unmapped or deactivated mapping falls back to cash, so a dues payment is never silently left out of the books. */
function financeDuesAccountFor(?string $method): int {
    $id = (int) financeSetting('finance_dues_account_' . financeDuesMethodKey($method), '0');
    if ($id > 0 && dbFetchOne("SELECT id FROM finance_accounts WHERE id = ? AND is_active = 1", [$id])) return $id;
    return financeCashAccountId();
}

function financeDuesCategoryId(): ?int {
    $id = dbFetchValue("SELECT id FROM finance_categories WHERE direction = 'IN' AND name = ?", [FINANCE_DUES_CATEGORY]);
    return $id ? (int) $id : null;
}

/**
 * Make the cash book agree with one dues row: a paid amount → exactly one IN movement; no amount, or the row is
 * gone → no movement. Called after every insert/update/delete in subscriptions.php. Safe before migration 227 ran.
 */
function financeSyncSubscriptionMovement(int $subscriptionId, int $userId, ?string $previousMethod = null): void {
    if (!dbTableExists('finance_movements')) return;
    $sub = dbFetchOne("SELECT vs.id, vs.amount, vs.payment_date, vs.payment_method, u.name
                       FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [$subscriptionId]);
    $existing = dbFetchOne("SELECT id, account_id FROM finance_movements WHERE subscription_id = ?", [$subscriptionId]);
    $cents = $sub ? financeToCents($sub['amount']) : 0;
    if ($cents <= 0) {
        if ($existing) dbExecute("DELETE FROM finance_movements WHERE id = ?", [$existing['id']]);
        return;
    }
    // An existing movement keeps the account the treasurer left it in; only a changed payment method re-resolves it.
    $methodChanged = $previousMethod !== null && trim($previousMethod) !== trim((string) $sub['payment_method']);
    $accountId = ($existing && !$methodChanged) ? (int) $existing['account_id'] : financeDuesAccountFor($sub['payment_method']);
    if ($accountId === 0) return; // no active account at all: nothing to book into
    $desc = mb_substr('Συνδρομή — ' . $sub['name'], 0, 255);
    $params = [$accountId, financeFromCents($cents), $sub['payment_date'], financeDuesCategoryId(), $desc];
    if ($existing) {
        dbExecute("UPDATE finance_movements SET account_id = ?, amount = ?, movement_date = ?, category_id = ?, description = ? WHERE id = ?",
            array_merge($params, [$existing['id']]));
    } else {
        dbExecute("INSERT INTO finance_movements (account_id, amount, movement_date, category_id, description, direction, subscription_id, created_by)
                   VALUES (?, ?, ?, ?, ?, 'IN', ?, ?)", array_merge($params, [$subscriptionId, $userId]));
    }
}

/** One-off: book the dues paid on/after $fromDate that have no movement yet. Returns how many were booked. */
function financeImportPastDues(string $fromDate, int $userId): int {
    if (!financeValidDate($fromDate)) throw new InvalidArgumentException('Μη έγκυρη ημερομηνία.');
    $ids = dbFetchAll("SELECT vs.id FROM volunteer_subscriptions vs LEFT JOIN finance_movements m ON m.subscription_id = vs.id
                       WHERE vs.payment_date >= ? AND vs.amount > 0 AND m.id IS NULL ORDER BY vs.payment_date", [$fromDate]);
    if (financeCashAccountId() === 0) return 0; // no active account: nothing can be booked
    $booked = 0;
    foreach ($ids as $r) {
        financeSyncSubscriptionMovement((int) $r['id'], $userId);
        if (dbFetchOne("SELECT id FROM finance_movements WHERE subscription_id = ?", [$r['id']])) $booked++;
    }
    return $booked;
}

// ─── Uploads ─────────────────────────────────────────────────────────────────

/** Store an uploaded document file under a random name. null when no file was submitted. */
function financeStoreUpload(array $file): ?array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = !empty($file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : false;
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !isset($allowed[$mime])) {
        throw new RuntimeException('Το αρχείο πρέπει να είναι PDF, JPG, PNG ή WebP έως 10MB.');
    }
    $dir = financeUploadDir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία του φακέλου παραστατικών.');
    ensurePrivateUploadDir($dir);
    $stored = 'doc-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση του αρχείου.');
    return ['original' => mb_substr(basename((string) ($file['name'] ?? $stored)), 0, 255), 'stored' => $stored, 'mime' => $mime];
}

/**
 * A file uploaded in a request the server then refused stays in the private folder, remembered only in the session
 * under a random token. The client sends the token back, never a file name; these helpers are the only way it is resolved.
 */
function financePendingUploadResolve(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $e = $_SESSION['finance_pending_upload'][$token] ?? null;
    if (!is_array($e) || !preg_match('/^doc-\d{8}-[a-f0-9]{16}\.(pdf|jpg|png|webp)$/', (string) ($e['stored'] ?? ''))) return null;
    if (!is_file(financeUploadDir() . $e['stored'])) return null;
    return ['original' => (string) ($e['original'] ?? $e['stored']), 'stored' => $e['stored'], 'mime' => (string) ($e['mime'] ?? '')];
}

/** Remember a stored upload under a fresh random token and return the token. */
function financePendingUploadRemember(array $upload): string {
    $token = bin2hex(random_bytes(16));
    $_SESSION['finance_pending_upload'][$token] = ['original' => $upload['original'], 'stored' => $upload['stored'], 'mime' => $upload['mime']];
    return $token;
}

/** Forget a pending upload; $deleteFile also removes the file (a replaced one), not when it was attached to a document. */
function financePendingUploadForget(string $token, bool $deleteFile): void {
    $e = financePendingUploadResolve($token);
    if ($e && $deleteFile) @unlink(financeUploadDir() . $e['stored']);
    unset($_SESSION['finance_pending_upload'][$token]);
}

// ─── Reminders ───────────────────────────────────────────────────────────────

/** Active system admins plus active users whose custom role grants finance_manage. */
function financeManagerIds(): array {
    $rows = dbFetchAll("SELECT DISTINCT u.id FROM users u
        LEFT JOIN custom_role_permissions p ON p.role_id = u.custom_role_id AND p.page_slug = 'finance_manage'
        WHERE u.is_active = 1 AND u.deleted_at IS NULL AND (u.role = ? OR p.page_slug IS NOT NULL)", [ROLE_SYSTEM_ADMIN]);
    return array_map('intval', array_column($rows, 'id'));
}

/**
 * Announce each unpaid document once per stage (7 days before, the due date, overdue). Returns documents announced.
 * The stage is recorded only after at least one manager was notified without error, so a document
 * nobody could be reached about is retried on the next run; a failure for one recipient never stops the rest.
 */
function financeSendDueReminders(string $today, ?callable $notify = null): int {
    if (!financeValidDate($today)) return 0;
    $notify = $notify ?? fn(int $uid, string $title, string $msg, string $type, array $pushData = []) => sendNotification($uid, $title, $msg, $type, '', $pushData);
    $recipients = financeManagerIds();
    $sent = 0;
    foreach (financeOpenPayables($today) as $doc) {
        if (empty($doc['due_date'])) continue;
        $stage = financeReminderStageFor($doc['due_date'], $today);
        if ($stage === 0 || $stage <= (int) $doc['reminder_stage']) continue;
        $title = [1 => 'Τιμολόγιο λήγει σύντομα', 2 => 'Τιμολόγιο λήγει σήμερα', 3 => 'Ληγμένο τιμολόγιο'][$stage];
        $msg = $doc['counterparty_name'] . ($doc['doc_number'] ? ' (' . $doc['doc_number'] . ')' : '') . ': υπόλοιπο '
            . financeFormat($doc['remaining']) . ', προθεσμία ' . formatDate($doc['due_date']) . '.';
        $delivered = 0;
        foreach ($recipients as $uid) {
            try {
                $notify($uid, $title, $msg, $stage === 3 ? 'danger' : 'warning', ['url' => 'finance-document-form.php?id=' . (int) $doc['id']]);
                $delivered++;
            } catch (Throwable $e) {
                error_log('financeSendDueReminders: notify failed for user ' . $uid . ': ' . $e->getMessage());
            }
        }
        if ($delivered === 0) continue;
        dbExecute("UPDATE finance_documents SET reminder_stage = ? WHERE id = ?", [$stage, $doc['id']]);
        $sent++;
    }
    return $sent;
}
