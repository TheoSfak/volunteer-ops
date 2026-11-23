<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A file from a refused save is resolved only from the session by its random token, never from a client-sent name. */
final class FinancePendingUploadTest extends TestCase
{
    private array $made = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
    }

    protected function setUp(): void
    {
        $_SESSION['finance_pending_upload'] = [];
        $dir = financeUploadDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $p) @unlink($p);
        $_SESSION['finance_pending_upload'] = [];
    }

    private function storedFile(): string
    {
        $name = 'doc-20260101-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents(financeUploadDir() . $name, '%PDF-1.4 test');
        $this->made[] = financeUploadDir() . $name;
        return $name;
    }

    public function test_a_remembered_upload_resolves_by_its_token_only(): void
    {
        $name = $this->storedFile();
        $token = financePendingUploadRemember(['original' => 'a.pdf', 'stored' => $name, 'mime' => 'application/pdf']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertSame(['original' => 'a.pdf', 'stored' => $name, 'mime' => 'application/pdf'], financePendingUploadResolve($token));
        $this->assertNull(financePendingUploadResolve($name), 'a file name is not a token');
        $this->assertNull(financePendingUploadResolve(str_repeat('a', 32)), 'unknown token');
        $this->assertNull(financePendingUploadResolve('../../' . $token));
        $this->assertNull(financePendingUploadResolve(''));
    }

    public function test_a_tampered_session_entry_or_missing_file_is_refused(): void
    {
        $token = str_repeat('b', 32);
        $_SESSION['finance_pending_upload'][$token] = ['original' => 'x', 'stored' => '../../config.php', 'mime' => 'application/pdf'];
        $this->assertNull(financePendingUploadResolve($token));
        $_SESSION['finance_pending_upload'][$token] = ['original' => 'x', 'stored' => 'doc-20260101-' . str_repeat('0', 16) . '.pdf', 'mime' => 'application/pdf'];
        $this->assertNull(financePendingUploadResolve($token), 'file does not exist');
    }

    public function test_forgetting_can_delete_a_replaced_file_or_keep_an_attached_one(): void
    {
        $a = $this->storedFile();
        $b = $this->storedFile();
        $ta = financePendingUploadRemember(['original' => 'a', 'stored' => $a, 'mime' => 'application/pdf']);
        $tb = financePendingUploadRemember(['original' => 'b', 'stored' => $b, 'mime' => 'application/pdf']);
        financePendingUploadForget($ta, true);
        $this->assertFileDoesNotExist(financeUploadDir() . $a);
        $this->assertNull(financePendingUploadResolve($ta));
        financePendingUploadForget($tb, false);
        $this->assertFileExists(financeUploadDir() . $b, 'an attached file stays');
        $this->assertNull(financePendingUploadResolve($tb));
    }

    public function test_the_form_refusal_path_rerenders_and_reads_the_pending_file_from_the_session(): void
    {
        $s = (string) file_get_contents(dirname(__DIR__) . '/finance-document-form.php');
        $this->assertStringContainsString('$refused = $e->getMessage();', $s);
        $catch = substr($s, (int) strpos($s, 'catch (InvalidArgumentException'));
        $branch = substr($catch, 0, (int) strpos($catch, '} else {'));
        $this->assertStringNotContainsString('redirect(', $branch, 'a refused save no longer redirects');
        $this->assertStringContainsString("redirect(\$failUrl)", substr($catch, (int) strpos($catch, '} else {')), 'other actions keep the redirect');
        $this->assertStringContainsString("post('pending_upload')", $s);
        $this->assertStringContainsString('financePendingUploadResolve($pendingToken)', $s);
        $this->assertStringNotContainsString("\$_FILES['pending", $s);
        $this->assertStringNotContainsString("post('pending_file", $s);
        $this->assertStringContainsString('δεν χρειάζεται να το ανεβάσετε ξανά.', $s);
        $this->assertStringContainsString('name="pending_upload"', $s);
        foreach (['counterparty_name', 'counterparty_vat', 'doc_number', 'due_date', 'gross_typed', 'total_confirmed', 'pay_now', 'pay_account_id', 'scan_provider'] as $k) {
            $this->assertStringContainsString($k, $s);
        }
        $this->assertStringContainsString("\$_POST['vat_net'][\$i]", $s, 'VAT rows are rebuilt from the posted values');
        $this->assertStringContainsString('PDOException', substr($s, 0, (int) strpos($s, 'catch (InvalidArgumentException')));
    }
}
