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
        return [['finance-documents.php'], ['finance-document-form.php'], ['finance-file.php']];
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
}
