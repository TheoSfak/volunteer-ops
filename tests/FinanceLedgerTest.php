<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceLedgerTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
        self::$userId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, 'x')",
            ['Finance Ledger Test', 'finance-ledger-' . uniqid('', true) . '@example.invalid']);
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM users WHERE id = ?", [self::$userId]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM finance_movements WHERE account_id IN (SELECT id FROM finance_accounts WHERE name LIKE 'TESTFIN%')");
        dbExecute("DELETE FROM finance_documents WHERE counterparty_name LIKE 'TESTFIN%'");
        dbExecute("DELETE FROM finance_accounts WHERE name LIKE 'TESTFIN%'");
        dbExecute("DELETE FROM finance_categories WHERE name LIKE 'TESTFIN%'");
        dbExecute("DELETE FROM settings WHERE setting_key = 'finance_test_key'");
    }

    private function account(string $name, string $opening = '0.00'): int
    {
        return (int) dbInsert("INSERT INTO finance_accounts (name, kind, opening_balance) VALUES (?, 'CASH', ?)", [$name, $opening]);
    }

    private function outDoc(int $gross = 1240, array $extra = []): int
    {
        return financeSaveDocument($extra + [
            'direction' => 'OUT', 'doc_type' => 'INVOICE', 'counterparty_name' => 'TESTFIN Garage',
            'doc_date' => '2026-11-10', 'due_date' => '2026-11-30',
        ], [financeSplitGross($gross, 24)], self::$userId);
    }

    public function test_a_document_is_saved_with_its_vat_lines_and_totals(): void
    {
        $id = financeSaveDocument([
            'direction' => 'OUT', 'doc_type' => 'INVOICE', 'counterparty_name' => 'TESTFIN Market',
            'counterparty_vat' => '090000045', 'doc_date' => '2026-11-10',
        ], [['rate' => 24, 'net' => 1000, 'vat' => 240], ['rate' => 13, 'net' => 500, 'vat' => 65]], self::$userId);

        $row = dbFetchOne("SELECT * FROM finance_documents WHERE id = ?", [$id]);
        $this->assertSame('15.00', $row['net_total']);
        $this->assertSame('3.05', $row['vat_total']);
        $this->assertSame('18.05', $row['gross_total']);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM finance_document_vat WHERE document_id = ?", [$id]));
    }

    public function test_bad_input_is_refused_in_greek(): void
    {
        $base = ['direction' => 'OUT', 'counterparty_name' => 'TESTFIN X', 'doc_date' => '2026-11-10'];
        foreach ([
            [['direction' => 'SIDEWAYS'] + $base, [financeSplitGross(100, 24)]],
            [['counterparty_name' => ''] + $base, [financeSplitGross(100, 24)]],
            [['doc_date' => '2026-13-01'] + $base, [financeSplitGross(100, 24)]],
            [['counterparty_vat' => '090000046'] + $base, [financeSplitGross(100, 24)]],
            [$base, [['rate' => 23, 'net' => 100, 'vat' => 23]]],
            [$base, [['rate' => 24, 'net' => 0, 'vat' => 0]]],
            [$base, [['rate' => 24, 'net' => -100, 'vat' => 24], financeSplitGross(500, 24)]],
            [$base, [['rate' => 24, 'net' => 100, 'vat' => -5], financeSplitGross(500, 24)]],
        ] as $i => [$doc, $lines]) {
            try {
                financeSaveDocument($doc, $lines, self::$userId);
                $this->fail("case $i was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/\p{Greek}/u', $e->getMessage(), "case $i");
            }
        }
    }

    public function test_partial_then_full_payment_changes_status_and_overpaying_is_refused(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc(1240);

        $this->assertSame('OPEN', financeDocumentStatus($doc)['status']);
        financePayDocument($doc, $acc, '2026-11-12', 400, self::$userId);
        $st = financeDocumentStatus($doc);
        $this->assertSame('PARTIAL', $st['status']);
        $this->assertSame(840, $st['remaining']);

        try {
            financePayDocument($doc, $acc, '2026-11-12', 841, self::$userId);
            $this->fail('overpayment accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('υπόλοιπο', $e->getMessage());
        }

        financePayDocument($doc, $acc, '2026-11-13', 840, self::$userId);
        $this->assertSame('PAID', financeDocumentStatus($doc)['status']);
    }

    public function test_a_direct_movement_cannot_overpay_a_document(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc(1240);
        financePayDocument($doc, $acc, '2026-11-12', 1000, self::$userId);
        try {
            financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 241, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
            $this->fail('overpayment accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('υπόλοιπο', $e->getMessage());
        }
        $this->assertSame(1000, financeDocumentPaidCents($doc));
        financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 240, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
        $this->assertSame('PAID', financeDocumentStatus($doc)['status']);
    }

    public function test_a_direct_movement_inside_an_outer_transaction_still_works_and_rolls_back_with_it(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc(1000);
        try {
            financeTransaction(function () use ($acc, $doc) {
                financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 600, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
                financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 600, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
            });
            $this->fail('second overpaying movement accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('υπόλοιπο', $e->getMessage());
        }
        $this->assertSame(0, financeDocumentPaidCents($doc));
        $this->assertFalse(db()->inTransaction());
    }

    public function test_a_movement_must_match_its_documents_direction(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc();
        $this->expectException(InvalidArgumentException::class);
        financeAddMovement(['account_id' => $acc, 'direction' => 'IN', 'amount' => 100, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
    }

    public function test_a_document_total_cannot_drop_below_what_was_paid(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc(1240);
        financePayDocument($doc, $acc, '2026-11-12', 1000, self::$userId);
        $this->expectException(InvalidArgumentException::class);
        financeSaveDocument(['direction' => 'OUT', 'counterparty_name' => 'TESTFIN Garage', 'doc_date' => '2026-11-10'],
            [financeSplitGross(900, 24)], self::$userId, $doc);
    }

    public function test_balances_are_opening_plus_in_minus_out_and_transfers_move_money_between_accounts(): void
    {
        $cash = $this->account('TESTFIN cash', '100.00');
        $bank = $this->account('TESTFIN bank');
        $before = financePeriodTotals('2026-11-01', '2026-11-30');
        financeAddMovement(['account_id' => $cash, 'direction' => 'IN', 'amount' => 5000, 'movement_date' => '2026-11-01', 'description' => 'TESTFIN donation'], self::$userId);
        financeAddMovement(['account_id' => $cash, 'direction' => 'OUT', 'amount' => 2030, 'movement_date' => '2026-11-02', 'description' => 'TESTFIN fuel'], self::$userId);
        $group = financeTransfer($cash, $bank, 3000, '2026-11-03', 'TESTFIN deposit', self::$userId);

        $bal = array_column(financeAccountBalances(), 'balance', 'id');
        $this->assertSame(10000 + 5000 - 2030 - 3000, $bal[$cash]);
        $this->assertSame(3000, $bal[$bank]);

        $totals = financePeriodTotals('2026-11-01', '2026-11-30');
        // the transfer (3000 out of cash, 3000 into bank) must not show up in either total
        $this->assertSame(5000, $totals['in'] - $before['in']);
        $this->assertSame(2030, $totals['out'] - $before['out']);

        $deleted = financeDeleteMovement((int) dbFetchValue("SELECT id FROM finance_movements WHERE transfer_group = ? LIMIT 1", [$group]));
        $this->assertCount(2, $deleted);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM finance_movements WHERE transfer_group = ?", [$group]));
    }

    public function test_a_transfer_to_the_same_account_is_refused(): void
    {
        $cash = $this->account('TESTFIN cash');
        $this->expectException(InvalidArgumentException::class);
        financeTransfer($cash, $cash, 100, '2026-11-03', 'TESTFIN', self::$userId);
    }

    public function test_deleting_a_document_keeps_its_payments_as_unlinked_movements(): void
    {
        $acc = $this->account('TESTFIN cash');
        $doc = $this->outDoc(1240);
        $mov = financePayDocument($doc, $acc, '2026-11-12', 1240, self::$userId);
        $row = financeDeleteDocument($doc);
        $this->assertSame('TESTFIN Garage', $row['counterparty_name']);
        $this->assertNull(dbFetchValue("SELECT document_id FROM finance_movements WHERE id = ?", [$mov]));
    }

    public function test_open_payables_list_only_unpaid_out_documents_with_urgency(): void
    {
        $acc = $this->account('TESTFIN cash');
        $open = $this->outDoc(1000, ['due_date' => '2026-11-20']);
        $paid = $this->outDoc(1000, ['due_date' => '2026-11-20']);
        financePayDocument($paid, $acc, '2026-11-12', 1000, self::$userId);

        $rows = array_column(financeOpenPayables('2026-11-22'), null, 'id');
        $this->assertArrayHasKey($open, $rows);
        $this->assertArrayNotHasKey($paid, $rows);
        $this->assertSame('overdue', $rows[$open]['urgency']);
        $this->assertSame(1000, $rows[$open]['remaining']);
    }

    public function test_vat_summary_groups_by_direction_and_rate(): void
    {
        financeSaveDocument(['direction' => 'OUT', 'counterparty_name' => 'TESTFIN A', 'doc_date' => '2026-10-05'],
            [['rate' => 24, 'net' => 1000, 'vat' => 240], ['rate' => 6, 'net' => 1000, 'vat' => 60]], self::$userId);
        $rows = array_values(array_filter(financeVatSummary('2026-10-05', '2026-10-05'), fn($r) => $r['direction'] === 'OUT'));
        $byRate = array_column($rows, null, 'rate');
        $this->assertSame(240, $byRate[24]['vat']);
        $this->assertSame(1060, $byRate[6]['gross']);
    }

    public function test_settings_are_read_fresh(): void
    {
        financeSaveSetting('finance_test_key', 'one');
        $this->assertSame('one', financeSetting('finance_test_key'));
        financeSaveSetting('finance_test_key', 'two');
        $this->assertSame('two', financeSetting('finance_test_key'));
        $this->assertSame('dflt', financeSetting('finance_missing_key', 'dflt'));
    }

    private function category(string $name, string $direction = 'OUT'): int
    {
        return (int) dbInsert("INSERT INTO finance_categories (name, direction, sort_order) VALUES (?, ?, 999)", [$name, $direction]);
    }

    public function test_the_category_report_groups_by_document_category_then_movement_category(): void
    {
        $acc = $this->account('TESTFIN cash');
        $catDoc = $this->category('TESTFIN doc cat');
        $catMov = $this->category('TESTFIN mov cat');
        $doc = $this->outDoc(1000, ['category_id' => $catDoc]);
        financePayDocument($doc, $acc, '2026-11-12', 400, self::$userId);
        financePayDocument($doc, $acc, '2026-11-13', 600, self::$userId);
        financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 250, 'movement_date' => '2026-11-14', 'category_id' => $catMov], self::$userId);
        financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 70, 'movement_date' => '2026-11-14'], self::$userId);
        $rows = [];
        foreach (financeReportByCategory('2026-11-01', '2026-11-30') as $r) {
            if (str_starts_with((string) $r['category'], 'TESTFIN') || $r['category_id'] === null) $rows[$r['category_id'] ?? 'none'] = $r;
        }
        $this->assertSame(1000, $rows[$catDoc]['total']);
        $this->assertSame($catDoc, $rows[$catDoc]['category_id']);
        $this->assertSame('TESTFIN doc cat', $rows[$catDoc]['category']);
        $this->assertSame(250, $rows[$catMov]['total']);
        $this->assertGreaterThanOrEqual(70, $rows['none']['total']);
    }

    public function test_deleting_a_document_moves_its_category_onto_its_payments(): void
    {
        $acc = $this->account('TESTFIN cash');
        $cat = $this->category('TESTFIN doc cat');
        $doc = $this->outDoc(1240, ['category_id' => $cat]);
        $mov = financePayDocument($doc, $acc, '2026-11-12', 1240, self::$userId);
        $this->assertNull(dbFetchValue("SELECT category_id FROM finance_movements WHERE id = ?", [$mov]));
        financeDeleteDocument($doc);
        $this->assertSame($cat, (int) dbFetchValue("SELECT category_id FROM finance_movements WHERE id = ?", [$mov]));
        $rows = array_values(array_filter(financeReportByCategory('2026-11-01', '2026-11-30'), fn($r) => $r['category_id'] === $cat));
        $this->assertSame(1240, $rows[0]['total']);
    }

    public function test_the_last_active_account_is_detected(): void
    {
        $active = array_column(dbFetchAll("SELECT id FROM finance_accounts WHERE is_active = 1"), 'id');
        dbExecute("UPDATE finance_accounts SET is_active = 0");
        $a = $this->account('TESTFIN one');
        try {
            $this->assertTrue(financeIsLastActiveAccount($a));
            $b = $this->account('TESTFIN two');
            $this->assertFalse(financeIsLastActiveAccount($a));
            dbExecute("UPDATE finance_accounts SET is_active = 0 WHERE id = ?", [$b]);
            $this->assertTrue(financeIsLastActiveAccount($a));
            $this->assertFalse(financeIsLastActiveAccount($b), 'an inactive account is never "the last active one"');
        } finally {
            dbExecute("DELETE FROM finance_accounts WHERE name LIKE 'TESTFIN%'");
            foreach ($active as $id) dbExecute("UPDATE finance_accounts SET is_active = 1 WHERE id = ?", [$id]);
        }
    }

    public function test_the_direction_of_an_unpaid_document_can_change_but_not_once_it_has_payments(): void
    {
        $inCat = $this->category('TESTFIN donation cat', 'IN');
        $doc = $this->outDoc(1240);
        financeSaveDocument(['direction' => 'IN', 'doc_type' => 'DONATION', 'counterparty_name' => 'TESTFIN Donor', 'doc_date' => '2026-11-10', 'category_id' => $inCat],
            [financeSplitGross(1240, 0)], self::$userId, $doc);
        $this->assertSame('IN', dbFetchValue("SELECT direction FROM finance_documents WHERE id = ?", [$doc]));
        $this->assertSame($inCat, (int) dbFetchValue("SELECT category_id FROM finance_documents WHERE id = ?", [$doc]));

        // The category must belong to the new direction.
        $outCat = $this->category('TESTFIN out cat', 'OUT');
        try {
            financeSaveDocument(['direction' => 'IN', 'counterparty_name' => 'TESTFIN Donor', 'doc_date' => '2026-11-10', 'category_id' => $outCat],
                [financeSplitGross(1240, 0)], self::$userId, $doc);
            $this->fail('an OUT category on an IN document must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('κατηγορία', $e->getMessage());
        }

        $acc = $this->account('TESTFIN cash');
        financeAddMovement(['account_id' => $acc, 'direction' => 'IN', 'amount' => 500, 'movement_date' => '2026-11-12', 'document_id' => $doc], self::$userId);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Δεν αλλάζει το είδος');
        financeSaveDocument(['direction' => 'OUT', 'counterparty_name' => 'TESTFIN Donor', 'doc_date' => '2026-11-10'],
            [financeSplitGross(1240, 0)], self::$userId, $doc);
    }
}
