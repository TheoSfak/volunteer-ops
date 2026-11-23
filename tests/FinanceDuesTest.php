<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceDuesTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
        self::$userId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, 'x')",
            ['Μαρία Δοκιμή', 'finance-dues-' . uniqid('', true) . '@example.invalid']);
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM users WHERE id = ?", [self::$userId]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM finance_movements WHERE subscription_id IN (SELECT id FROM volunteer_subscriptions WHERE user_id = ?)", [self::$userId]);
        dbExecute("DELETE FROM volunteer_subscriptions WHERE user_id = ?", [self::$userId]);
        dbExecute("DELETE FROM finance_accounts WHERE name LIKE 'TESTFIN%'");
        dbExecute("DELETE FROM settings WHERE setting_key LIKE 'finance_dues_account_%' OR setting_key = 'finance_books_start'");
    }

    private function sub(string $date, ?string $amount, ?string $method): int
    {
        return (int) dbInsert("INSERT INTO volunteer_subscriptions (user_id, payment_date, expiry_date, amount, payment_method) VALUES (?, ?, ?, ?, ?)",
            [self::$userId, $date, date('Y-m-d', strtotime($date . ' +1 year')), $amount, $method]);
    }

    private function movement(int $subId): ?array
    {
        return dbFetchOne("SELECT * FROM finance_movements WHERE subscription_id = ?", [$subId]);
    }

    public function test_method_keys_and_the_cash_fallback(): void
    {
        $this->assertSame('cash', financeDuesMethodKey('Μετρητά'));
        $this->assertSame('bank', financeDuesMethodKey('Τραπεζική κατάθεση'));
        $this->assertSame('bank', financeDuesMethodKey('IRIS'));
        $this->assertSame('card', financeDuesMethodKey('Κάρτα'));
        $this->assertSame('cash', financeDuesMethodKey('Άλλο'));
        $this->assertSame('cash', financeDuesMethodKey(null));

        $this->assertSame(financeCashAccountId(), financeDuesAccountFor('Τραπεζική κατάθεση'));
        $bank = (int) dbInsert("INSERT INTO finance_accounts (name, kind) VALUES ('TESTFIN bank', 'BANK')");
        financeSaveSetting('finance_dues_account_bank', (string) $bank);
        $this->assertSame($bank, financeDuesAccountFor('IRIS'));
        dbExecute("UPDATE finance_accounts SET is_active = 0 WHERE id = ?", [$bank]);
        $this->assertSame(financeCashAccountId(), financeDuesAccountFor('IRIS'));
    }

    public function test_a_paid_dues_row_keeps_exactly_one_movement_through_create_edit_and_delete(): void
    {
        $sub = $this->sub('2026-03-01', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($sub, self::$userId);
        $m = $this->movement($sub);
        $this->assertSame('IN', $m['direction']);
        $this->assertSame('30.00', $m['amount']);
        $this->assertSame('2026-03-01', $m['movement_date']);
        $this->assertSame(financeCashAccountId(), (int) $m['account_id']);
        $this->assertSame(financeDuesCategoryId(), (int) $m['category_id']);
        $this->assertSame('Συνδρομή — Μαρία Δοκιμή', $m['description']);

        dbExecute("UPDATE volunteer_subscriptions SET amount = '35.50', payment_date = '2026-03-05' WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId);
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM finance_movements WHERE subscription_id = ?", [$sub]));
        $this->assertSame('35.50', $this->movement($sub)['amount']);
        $this->assertSame('2026-03-05', $this->movement($sub)['movement_date']);

        dbExecute("UPDATE volunteer_subscriptions SET amount = NULL WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertNull($this->movement($sub));

        dbExecute("UPDATE volunteer_subscriptions SET amount = '30.00' WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId);
        dbExecute("DELETE FROM volunteer_subscriptions WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertNull($this->movement($sub));
    }

    public function test_editing_a_dues_row_keeps_a_moved_account_unless_the_payment_method_changed(): void
    {
        $bank = (int) dbInsert("INSERT INTO finance_accounts (name, kind) VALUES ('TESTFIN bank', 'BANK')");
        $other = (int) dbInsert("INSERT INTO finance_accounts (name, kind) VALUES ('TESTFIN other', 'BANK')");
        financeSaveSetting('finance_dues_account_bank', (string) $bank);
        $sub = $this->sub('2026-03-01', '30.00', 'Τραπεζική κατάθεση');
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertSame($bank, (int) $this->movement($sub)['account_id']);

        // The treasurer moved the money elsewhere; an amount edit must not undo that.
        dbExecute("UPDATE finance_movements SET account_id = ? WHERE subscription_id = ?", [$other, $sub]);
        dbExecute("UPDATE volunteer_subscriptions SET amount = '40.00' WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId, 'Τραπεζική κατάθεση');
        $this->assertSame($other, (int) $this->movement($sub)['account_id']);
        $this->assertSame('40.00', $this->movement($sub)['amount']);

        // Same with the method unknown to the caller.
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertSame($other, (int) $this->movement($sub)['account_id']);

        // A changed method re-resolves from the mapping.
        dbExecute("UPDATE volunteer_subscriptions SET payment_method = 'Μετρητά' WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId, 'Τραπεζική κατάθεση');
        $this->assertSame(financeDuesAccountFor('Μετρητά'), (int) $this->movement($sub)['account_id']);
    }

    public function test_dues_paid_before_the_books_start_get_no_new_movement(): void
    {
        financeSaveSetting('finance_books_start', '2026-01-01');
        $old = $this->sub('2025-03-10', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($old, self::$userId);
        $this->assertNull($this->movement($old), 'a 2025 row must not be booked behind a 2026 opening balance');

        $new = $this->sub('2026-01-01', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($new, self::$userId);
        $this->assertNotNull($this->movement($new), 'the start day itself is booked');

        $later = $this->sub('2026-02-01', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($later, self::$userId);
        $this->assertNotNull($this->movement($later));
    }

    public function test_an_existing_movement_is_still_updated_and_deleted_even_when_dated_before_the_books_start(): void
    {
        $sub = $this->sub('2026-03-01', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($sub, self::$userId);
        financeSaveSetting('finance_books_start', '2026-06-01');
        dbExecute("UPDATE volunteer_subscriptions SET amount = '45.00', payment_date = '2026-06-02' WHERE id = ?", [$sub]);
        $this->assertSame('updated', financeSyncSubscriptionMovement($sub, self::$userId));
        $this->assertSame('45.00', $this->movement($sub)['amount']);
        dbExecute("UPDATE volunteer_subscriptions SET amount = NULL WHERE id = ?", [$sub]);
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->assertNull($this->movement($sub));
    }

    public function test_editing_a_booked_row_to_a_date_before_the_books_start_deletes_its_movement(): void
    {
        financeSaveSetting('finance_books_start', '2026-01-01');
        $sub = $this->sub('2026-03-01', '30.00', 'Μετρητά');
        $this->assertSame('booked', financeSyncSubscriptionMovement($sub, self::$userId));
        dbExecute("UPDATE volunteer_subscriptions SET payment_date = '2025-12-31' WHERE id = ?", [$sub]);
        $this->assertSame('skipped', financeSyncSubscriptionMovement($sub, self::$userId));
        $this->assertNull($this->movement($sub), 'it must not be moved behind the opening balance');
        $old = $this->sub('2025-05-05', '30.00', 'Μετρητά');
        $this->assertSame('skipped', financeSyncSubscriptionMovement($old, self::$userId));
        dbExecute("UPDATE volunteer_subscriptions SET amount = NULL WHERE id = ?", [$old]);
        $this->assertSame('none', financeSyncSubscriptionMovement($old, self::$userId));
    }

    public function test_the_books_start_warning_shows_only_while_no_start_can_be_derived(): void
    {
        $hidden = array_column(dbFetchAll("SELECT id FROM finance_accounts WHERE is_active = 1 AND name NOT LIKE 'TESTFIN%'"), 'id');
        dbExecute("UPDATE finance_accounts SET is_active = 0 WHERE name NOT LIKE 'TESTFIN%'"); // hide the seeded accounts for this test
        try {
            financeSaveSetting('finance_books_start', '');
            $this->assertStringContainsString('Έναρξη βιβλίων', (string) financeBooksStartWarning());
            financeSaveSetting('finance_books_start', '2026-01-01');
            $this->assertNull(financeBooksStartWarning());
        } finally {
            foreach ($hidden as $id) dbExecute("UPDATE finance_accounts SET is_active = 1 WHERE id = ?", [$id]); // only those it hid
        }
    }

    public function test_without_a_books_start_setting_the_earliest_active_opening_date_guards(): void
    {
        $acc = (int) dbInsert("INSERT INTO finance_accounts (name, kind, opening_date) VALUES ('TESTFIN dated', 'CASH', '2000-01-01')");
        $this->assertNotNull(financeBooksStart());
        $this->assertLessThanOrEqual('2000-01-01', financeBooksStart());
        $before = $this->sub('1999-05-05', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($before, self::$userId);
        $this->assertNull($this->movement($before));
        dbExecute("UPDATE finance_accounts SET is_active = 0 WHERE id = ?", [$acc]);
        financeSaveSetting('finance_books_start', '1990-01-01');
        $this->assertSame('1990-01-01', financeBooksStart(), 'the explicit setting wins');
    }

    public function test_the_import_sets_the_books_start_when_empty_and_keeps_an_existing_one(): void
    {
        financeSaveSetting('finance_books_start', '');
        financeImportPastDues('2026-02-01', self::$userId);
        $this->assertSame('2026-02-01', financeSetting('finance_books_start'));
        financeImportPastDues('2026-05-01', self::$userId);
        $this->assertSame('2026-02-01', financeSetting('finance_books_start'));
    }

    public function test_a_dues_movement_cannot_be_deleted_from_the_cash_book(): void
    {
        $sub = $this->sub('2026-03-01', '30.00', 'Μετρητά');
        financeSyncSubscriptionMovement($sub, self::$userId);
        $this->expectException(InvalidArgumentException::class);
        financeDeleteMovement((int) $this->movement($sub)['id']);
    }

    public function test_importing_past_dues_is_idempotent_and_respects_the_from_date(): void
    {
        $old = $this->sub('2025-01-10', '30.00', 'Μετρητά');
        $new1 = $this->sub('2026-01-10', '30.00', 'Κάρτα');
        $new2 = $this->sub('2026-06-10', null, 'Μετρητά');

        $this->assertSame(1, financeImportPastDues('2026-01-01', self::$userId));
        $this->assertSame(0, financeImportPastDues('2026-01-01', self::$userId));
        $this->assertNull($this->movement($old));
        $this->assertNotNull($this->movement($new1));
        $this->assertNull($this->movement($new2));
    }

    public function test_importing_books_nothing_and_reports_zero_when_no_account_is_active(): void
    {
        $this->sub('2026-01-10', '30.00', 'Μετρητά');
        $active = array_column(dbFetchAll("SELECT id FROM finance_accounts WHERE is_active = 1"), "id");
        dbExecute("UPDATE finance_accounts SET is_active = 0");
        try {
            $this->assertSame(0, financeImportPastDues('2026-01-01', self::$userId));
        } finally {
            foreach ($active as $id) dbExecute("UPDATE finance_accounts SET is_active = 1 WHERE id = ?", [$id]);
        }
    }

    public function test_a_dues_method_is_recognised_whatever_its_case_accents_or_spelling(): void
    {
        foreach (['τράπεζα', 'Τράπεζα', ' ΤΡΑΠΕΖΑ ', 'κατάθεση', 'Κατάθεση', 'τραπεζική κατάθεση', 'iris', ' IRIS '] as $m) $this->assertSame('bank', financeDuesMethodKey($m), $m);
        foreach (['κάρτα', 'ΚΑΡΤΑ', 'pos', 'POS'] as $m) $this->assertSame('card', financeDuesMethodKey($m), $m);
        foreach (['μετρητά', 'ΜΕΤΡΗΤΑ', 'Μετρητά', 'τίποτα', ''] as $m) $this->assertSame('cash', financeDuesMethodKey($m), $m);
    }
}
