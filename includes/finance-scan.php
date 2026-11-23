<?php
/**
 * VolunteerOps — reading a receipt/invoice photo with the AI (v3.510.0).
 * The AI's answer is a SUGGESTION that fills a form the treasurer checks. financeValidateScan() keeps only
 * fields that pass the same rules the form enforces, and lists what it dropped or doubts in 'uncertain'.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const FINANCE_SCAN_FIELDS = ['direction', 'doc_type', 'counterparty_name', 'counterparty_vat', 'doc_number', 'doc_date', 'due_date', 'vat_lines', 'gross_total', 'category'];

function financeScanImageOk(string $dataUrl): bool {
    if (strlen($dataUrl) > 8000000) return false;
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataUrl, $m)) return false;
    $bin = base64_decode(substr($dataUrl, strlen($m[0])), true);
    if ($bin === false || strlen($bin) < 100) return false;
    $info = @getimagesizefromstring($bin);
    if (!$info) return false;
    $expected = ['jpeg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP];
    return $info[2] === $expected[$m[1]];
}

function financeScanMessages(string $imageDataUrl, array $categoryNames): array {
    $system = 'You read Greek receipts and invoices (αποδείξεις, τιμολόγια, αποδείξεις δωρεάς, αποδεικτικά κατάθεσης) from a photo '
        . 'for the treasurer of a Greek volunteer rescue group. Return ONLY one JSON object, no prose. Use null for anything you cannot '
        . 'read clearly. Never guess an ΑΦΜ, a date or an amount.';
    $text = "Return this JSON object:\n"
        . "{\n"
        . "  \"direction\": \"OUT\" if the group paid (a purchase receipt or invoice) or \"IN\" if the group received money (donation receipt, deposit slip),\n"
        . "  \"doc_type\": one of \"INVOICE\" (τιμολόγιο), \"RECEIPT\" (απόδειξη), \"DONATION\", \"DEPOSIT\", \"OTHER\",\n"
        . "  \"counterparty_name\": the issuer's business name for OUT, the payer for IN,\n"
        . "  \"counterparty_vat\": the ISSUER's 9-digit ΑΦΜ (printed near the top; the buyer's ΑΦΜ further down is NOT it), digits only,\n"
        . "  \"doc_number\": the document number/series as printed,\n"
        . "  \"doc_date\": \"YYYY-MM-DD\",\n"
        . "  \"due_date\": \"YYYY-MM-DD\" if a payment due date (λήξη/προθεσμία πληρωμής) is printed, else null,\n"
        . "  \"vat_lines\": one entry per VAT rate printed, e.g. [{\"rate\": 24, \"net\": 40.32, \"vat\": 9.68}]; Greek rates are 24, 13, 6, or 17, 9, 4 on some islands, or 0,\n"
        . "  \"gross_total\": the final amount paid (ΣΥΝΟΛΟ / ΠΛΗΡΩΤΕΟ) as a number,\n"
        . "  \"category\": the best match from this list, or null: " . implode(', ', $categoryNames) . ",\n"
        . "  \"uncertain\": the names of the fields above you are not sure of\n"
        . "}\nAmounts are numbers with a dot as decimal separator (Greek receipts print 12,40: return 12.40).";
    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
        ]],
    ];
}

/** null for anything that is not a non-negative amount; otherwise cents. */
function financeScanAmount($v): ?int {
    if ($v === null || is_array($v) || is_bool($v)) return null;
    $s = trim((string) $v);
    if ($s === '' || !preg_match('/^\d+([.,]\d+)?$/', $s)) return null;
    return financeParseDecimalCents(str_replace(",", ".", $s)); // the AI is told to use a dot decimal: never read a dot as thousands
}

function financeValidateScan(array $raw, array $categories, string $today, int $defaultRate): array {
    $uncertain = array_values(array_intersect(array_map('strval', (array) ($raw['uncertain'] ?? [])), FINANCE_SCAN_FIELDS));
    $doubt = function (string $field) use (&$uncertain) { if (!in_array($field, $uncertain, true)) $uncertain[] = $field; };
    $str = function ($v, int $max): ?string {
        if (!is_string($v)) return null;
        $v = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $v)); // control characters count as word breaks
        return $v !== '' ? mb_substr($v, 0, $max) : null;
    };

    $direction = in_array($raw['direction'] ?? null, FINANCE_DIRECTIONS, true) ? $raw['direction'] : null;
    $docType = in_array($raw['doc_type'] ?? null, FINANCE_DOC_TYPES, true) ? $raw['doc_type'] : null;

    $vat = preg_replace('/\D/', '', (string) ($raw['counterparty_vat'] ?? ''));
    if ($vat !== '' && !financeValidVatNumber($vat)) { $vat = ''; $doubt('counterparty_vat'); }

    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
    $docDate = $raw['doc_date'] ?? null;
    if ($docDate !== null && (!financeValidDate($docDate) || $docDate > $tomorrow || $docDate < '2000-01-01')) { $docDate = null; $doubt('doc_date'); }
    $dueDate = $raw['due_date'] ?? null;
    if ($dueDate !== null && !financeValidDate($dueDate)) { $dueDate = null; $doubt('due_date'); }

    $lines = [];
    foreach ((array) ($raw['vat_lines'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $rate = is_numeric($l['rate'] ?? null) ? (int) round((float) $l['rate']) : -1;
        $net = financeScanAmount($l['net'] ?? null);
        $vatC = financeScanAmount($l['vat'] ?? null);
        if (!in_array($rate, FINANCE_VAT_RATES, true) || $net === null) { $doubt('vat_lines'); continue; }
        if ($vatC === null) {
            if (($l['vat'] ?? null) !== null) $doubt('vat_lines');
            $vatC = financeSplitNet($net, $rate)['vat'];
        }
        if ($net + $vatC <= 0) continue;
        $lines[] = ['rate' => $rate, 'net' => $net, 'vat' => $vatC];
    }

    $gross = financeScanAmount($raw['gross_total'] ?? null);
    if (($raw['gross_total'] ?? null) !== null && ($gross === null || $gross <= 0)) { $gross = null; $doubt('gross_total'); }
    if (!$lines && $gross !== null && $gross > 0) {
        $s = financeSplitGross($gross, in_array($defaultRate, FINANCE_VAT_RATES, true) ? $defaultRate : 24);
        $lines[] = ['rate' => $s['rate'], 'net' => $s['net'], 'vat' => $s['vat']];
        $doubt('vat_lines');
    }
    $linesGross = financeDocumentTotals($lines)['gross'];
    $totalsOk = $gross === null || !financeTotalsMismatch($gross, $linesGross);
    if ($gross === null && $lines) $gross = $linesGross;

    $categoryId = null;
    $wanted = is_string($raw['category'] ?? null) ? mb_strtolower(trim($raw['category'])) : '';
    if ($wanted !== '') {
        foreach ($categories as $c) {
            if (mb_strtolower(trim($c['name'])) === $wanted && ($direction === null || $c['direction'] === $direction)) {
                $categoryId = (int) $c['id'];
                break;
            }
        }
    }

    if ($wanted !== '' && $categoryId === null) $doubt('category');

    return [
        'direction' => $direction,
        'doc_type' => $docType,
        'counterparty_name' => $str($raw['counterparty_name'] ?? null, 200),
        'counterparty_vat' => $vat !== '' ? $vat : null,
        'doc_number' => $str($raw['doc_number'] ?? null, 50),
        'doc_date' => $docDate,
        'due_date' => $dueDate,
        'vat_lines' => array_map(fn($l) => ['rate' => $l['rate'], 'net' => financeFromCents($l['net']), 'vat' => financeFromCents($l['vat'])], $lines),
        'gross_total' => $gross !== null ? financeFromCents($gross) : null,
        'category_id' => $categoryId,
        'uncertain' => array_values($uncertain),
        'totals_ok' => $totalsOk,
    ];
}
