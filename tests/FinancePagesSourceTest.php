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
                ['finance-movements.php'], ['finance-payables.php'], ['finance-settings.php']];
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
        $this->assertStringContainsString("\$accounts ? 'checked' : 'disabled'", $s);
    }
}
