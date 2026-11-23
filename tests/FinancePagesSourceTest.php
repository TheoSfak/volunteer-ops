<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every finance page is gated and every write is CSRF-checked (source checks; the pages need a live session). */
final class FinancePagesSourceTest extends TestCase
{
    private static function src(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/' . $file);
    }

    public static function pages(): array
    {
        return [['finance-documents.php'], ['finance-document-form.php'], ['finance-file.php'],
                ['finance-movements.php'], ['finance-payables.php'], ['finance-settings.php'],
                ['finance.php'], ['finance-reports.php']];
    }

    /** @dataProvider pages */
    public function test_each_page_requires_the_finance_permission(string $file): void
    {
        $s = self::src($file);
        $this->assertTrue(
            str_contains($s, "requirePermission('finance_manage')") || str_contains($s, "hasPagePermission('finance_manage')"),
            "$file must check finance_manage"
        );
    }

    /** @dataProvider pages */
    public function test_each_page_that_posts_verifies_csrf(string $file): void
    {
        $s = self::src($file);
        if (str_contains($s, 'isPost()')) {
            $this->assertStringContainsString('verifyCsrf()', $s, $file);
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_form_carries_the_scan_contract_and_the_privacy_notice(): void
    {
        $s = self::src('finance-document-form.php');
        foreach (['id="financeDocumentForm"', 'data-scan-url', 'data-csrf', 'id="vatLines"', 'id="vatLineTemplate"', 'id="docFile"',
                  'id="btnScan"', 'id="grossTyped"', 'name="total_confirmed"', 'assets/js/finance-form.js',
                  'Η εικόνα αποστέλλεται στον πάροχο AI'] as $needle) {
            $this->assertStringContainsString($needle, $s, $needle);
        }
    }

    public function test_files_are_streamed_from_the_private_folder_only(): void
    {
        $s = self::src('finance-file.php');
        $this->assertStringContainsString('ensurePrivateUploadDir(', $s);
        $this->assertStringContainsString('basename(', $s);
    }

    public function test_dues_movements_are_not_editable_from_the_cash_book(): void
    {
        $s = self::src('finance-movements.php');
        $this->assertStringContainsString('subscription_id', $s);
        $this->assertStringContainsString('subscriptions.php', $s);
    }

    public function test_settings_offer_the_dues_mapping_and_the_import(): void
    {
        $s = self::src('finance-settings.php');
        foreach (['finance_dues_account_', 'import_dues', 'financeImportPastDues(', 'finance_default_vat_rate'] as $needle) {
            $this->assertStringContainsString($needle, $s, $needle);
        }
    }

    public function test_movements_and_settings_audit_and_hide_database_errors(): void
    {
        foreach (['finance-movements.php', 'finance-settings.php'] as $file) {
            $s = self::src($file);
            $this->assertStringContainsString('catch (PDOException', $s, $file);
            $this->assertStringContainsString('error_log(', $s, $file);
            $this->assertLessThan(strpos($s, 'catch (InvalidArgumentException'), strpos($s, 'catch (PDOException'), "$file: PDOException first");
        }
        foreach (['finance_create_movement', 'finance_transfer', 'finance_delete_movement'] as $a) {
            $this->assertStringContainsString("logAudit('$a'", self::src('finance-movements.php'), $a);
        }
        foreach (['create_account', 'update_account', 'delete_account', 'create_category', 'update_category', 'delete_category', 'update_settings', 'import_dues'] as $a) {
            $this->assertStringContainsString("logAudit('finance_$a'", self::src('finance-settings.php'), $a);
        }
    }

    public function test_settings_keep_an_inactive_mapped_dues_account_selected(): void
    {
        $this->assertStringContainsString('(ανενεργός)', self::src('finance-settings.php'));
    }

    public function test_fix_round_1_totals_opening_balance_categories_and_missing_ids(): void
    {
        $m = self::src('finance-movements.php');
        $this->assertStringContainsString('m.transfer_group IS NULL GROUP BY m.direction', $m);
        $this->assertStringContainsString('Εμφανίζονται οι 500 πιο πρόσφατες κινήσεις', $m);
        $this->assertStringContainsString("'direction' => get('direction')", $m);
        $this->assertStringNotContainsString("\$tot[\$r['direction']] +=", $m);
        $s = self::src('finance-settings.php');
        foreach (['financeRequireCents($rawOpening)','AND id <> ?', "\$old['direction'] === 'IN'", "\$cat['direction'] === 'IN'",
                  'Ο λογαριασμός δεν βρέθηκε.', 'Η κατηγορία δεν βρέθηκε.', '$before, $keys'] as $needle) {
            $this->assertStringContainsString($needle, $s, $needle);
        }
    }

    public function test_the_cash_book_totals_aggregate_ignores_transfers_and_the_row_limit(): void
    {
        require_once __DIR__ . '/../includes/finance-functions.php';
        $acc = (int) dbFetchValue("SELECT id FROM finance_accounts WHERE is_active = 1 ORDER BY id LIMIT 1");
        if ($acc === 0) { $this->markTestSkipped('no active account'); }
        $day = '2031-03-15';
        dbExecute("DELETE FROM finance_movements WHERE movement_date = ?", [$day]);
        $uid = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, 'x')", ['Δοκιμή', 'finance-pages-' . uniqid('', true) . '@example.invalid']);
        try {
            financeAddMovement(['account_id' => $acc, 'direction' => 'IN', 'amount' => 1050, 'movement_date' => $day, 'description' => 't'], $uid);
            financeAddMovement(['account_id' => $acc, 'direction' => 'OUT', 'amount' => 250, 'movement_date' => $day, 'description' => 't'], $uid);
            $rows = dbFetchAll("SELECT m.direction, ROUND(SUM(m.amount) * 100) AS cents FROM finance_movements m WHERE m.movement_date BETWEEN ? AND ? AND m.transfer_group IS NULL GROUP BY m.direction", [$day, $day]);
            $by = array_column($rows, 'cents', 'direction');
            $this->assertSame(1050, (int) $by['IN']);
            $this->assertSame(250, (int) $by['OUT']);
        } finally {
            dbExecute("DELETE FROM finance_movements WHERE movement_date = ?", [$day]);
            dbExecute("DELETE FROM users WHERE id = ?", [$uid]);
        }
    }

    public function test_the_form_audits_pay_now_keeps_existing_links_and_hides_database_errors(): void
    {
        $s = self::src('finance-document-form.php');
        $this->assertSame(2, substr_count($s, "logAudit('finance_pay_document'"), 'pay form and pay-now both audit');
        $this->assertStringContainsString('(ανενεργή)', $s);
        $this->assertStringContainsString('deleted_at IS NULL OR id = ?', $s);
        $this->assertStringContainsString('catch (PDOException', $s);
        $this->assertStringContainsString('error_log(', $s);
        $this->assertLessThan(strpos($s, 'catch (InvalidArgumentException'), strpos($s, 'catch (PDOException'), 'PDOException must be caught first');
        $this->assertStringContainsString("'#pay'", $s);
        $this->assertStringContainsString("\$existing ?: null, \$doc + ['vat_lines'", $s);
        $this->assertStringContainsString("\$accounts ? (!\$sticky || post('pay_now') === '1' ? 'checked' : '') : 'disabled'", $s);
    }
    public function test_the_menu_has_an_oikonomika_section_holding_the_dues(): void
    {
        $h = self::src('includes/header.php');
        $this->assertStringContainsString('data-sec="finance">Οικονομικά</div>', $h);
        $start = strpos($h, 'data-sec="finance"');
        $end = strpos($h, '// Οικονομικά section', $start);
        $this->assertNotFalse($end);
        $section = substr($h, $start, $end - $start);
        foreach (['finance.php', 'finance-movements.php', 'finance-documents.php', 'finance-payables.php', 'subscriptions.php', 'finance-reports.php', 'finance-settings.php'] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $section, $href);
        }
        $this->assertSame(1, substr_count($h, 'href="subscriptions.php"'), 'Συνδρομές appears once, under Οικονομικά');
        $this->assertSame(1, substr_count($h, "hasPagePermission('subscriptions_manage')"), 'the dues permission is evaluated once, into $canDues');
        $this->assertStringContainsString('<?php if ($canDues): ?>', $section, 'the Συνδρομές item reuses $canDues');

        $theme = self::src('includes/sidebar-theme.php');
        $this->assertStringContainsString("'finance'", $theme);
    }

    public function test_final_review_hardening_is_in_place(): void
    {
        $this->assertStringContainsString("if (\$mime !== 'application/pdf') header('Content-Security-Policy: sandbox');", self::src('finance-file.php'));

        $form = self::src('finance-document-form.php');
        $this->assertStringContainsString('aiVisionChain()', $form);
        $this->assertStringContainsString('για ανάγνωση.', $form);
        $this->assertStringContainsString("'π.χ. Google'", $form, 'falls back to the original text for an empty chain');

        $settings = self::src('finance-settings.php');
        $this->assertSame(2, substr_count($settings, 'financeIsLastActiveAccount('), 'update-to-inactive and delete are both guarded');
        $this->assertStringContainsString("logAudit('finance_update_account', 'finance_accounts', \$id, \$oldAccount, \$newAccount)", $settings);
        $this->assertStringContainsString("logAudit('finance_create_account', 'finance_accounts', \$id, null, \$newAccount)", $settings);
        $this->assertStringContainsString("logAudit('finance_update_category', 'finance_categories', \$id, \$old, [", $settings);
        $this->assertStringContainsString("logAudit('finance_create_category', 'finance_categories', \$id, null, [", $settings);

        $mov = self::src('finance-movements.php');
        $this->assertStringContainsString("logAudit('finance_create_movement', 'finance_movements', \$mid, null, \$new)", $mov);
        $this->assertStringContainsString("'from_account_id' => \$from, 'to_account_id' => \$to, 'amount' => \$cents", $mov);

        $subs = self::src('subscriptions.php');
        $this->assertSame(3, substr_count($subs, 'catch (Throwable $e)'), 'record, edit and delete each survive a failed transaction');
        $this->assertStringContainsString("financeSyncSubscriptionMovement(\$subscriptionId, (int) getCurrentUserId(), \$old['payment_method'])", $subs);
    }

    public function test_reports_export_csv_with_a_bom(): void
    {
        $s = self::src('finance-reports.php');
        $this->assertStringContainsString("export') === 'csv'", $s);
        $this->assertStringContainsString('fputcsvSafe(', $s);
        $this->assertStringContainsString('"\xEF\xBB\xBF"', $s);
    }

    public function test_payment_forms_carry_the_opening_date_for_the_live_warning(): void
    {
        $s = self::src('finance-document-form.php');
        $this->assertSame(2, substr_count($s, 'data-opening-date="'), 'pay-now and the payments form options');
        $this->assertSame(2, substr_count($s, 'class="alert alert-warning py-1 px-2'), 'one warning box per pay block, besides the totals alert');
        $this->assertSame(2, substr_count($s, 'opening-warning"'));
        $this->assertSame(2, substr_count($s, 'data-opening-warning'));
    }
}
