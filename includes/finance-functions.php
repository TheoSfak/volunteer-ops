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

/** "12,50" / "12.5" / 12.5 → 1250. Anything else (thousands separators included) → 0. */
function financeToCents($value): int {
    if ($value === null || is_bool($value)) return 0;
    $s = str_replace([' ', ','], ['', '.'], trim((string) $value));
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $s, $m)) return 0;
    $frac = str_pad($m[3] ?? '', 3, '0');
    $cents = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);
    return $m[1] === '-' ? -$cents : $cents;
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
    if (!preg_match('/^\d{9}$/', $afm) || $afm === '000000000') return false;
    $sum = 0;
    for ($i = 0; $i < 8; $i++) {
        $sum += (int) $afm[$i] * (2 ** (8 - $i));
    }
    return ($sum % 11) % 10 === (int) $afm[8];
}

function financeValidDate($value): bool {
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}
