<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** What the AI says about a receipt is a suggestion: financeValidateScan() keeps only what checks out. */
final class FinanceScanTest extends TestCase
{
    private const CATS = [
        ['id' => 1, 'name' => 'Καύσιμα', 'direction' => 'OUT'],
        ['id' => 2, 'name' => 'Δωρεές', 'direction' => 'IN'],
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
        require_once __DIR__ . '/../includes/finance-scan.php';
    }

    public function test_a_clean_fuel_receipt_comes_through_whole(): void
    {
        $v = financeValidateScan([
            'direction' => 'OUT', 'doc_type' => 'RECEIPT', 'counterparty_name' => ' ΠΡΑΤΗΡΙΟ ΚΑΥΣΙΜΩΝ ΑΕ ',
            'counterparty_vat' => '090000045', 'doc_number' => 'Α-123', 'doc_date' => '2026-11-20', 'due_date' => null,
            'vat_lines' => [['rate' => 24, 'net' => 40.32, 'vat' => 9.68]], 'gross_total' => 50.00,
            'category' => 'καύσιμα', 'uncertain' => ['doc_number', 'bogus_field'],
        ], self::CATS, '2026-11-22', 24);

        $this->assertSame('OUT', $v['direction']);
        $this->assertSame('RECEIPT', $v['doc_type']);
        $this->assertSame('ΠΡΑΤΗΡΙΟ ΚΑΥΣΙΜΩΝ ΑΕ', $v['counterparty_name']);
        $this->assertSame('090000045', $v['counterparty_vat']);
        $this->assertSame('2026-11-20', $v['doc_date']);
        $this->assertSame([['rate' => 24, 'net' => '40.32', 'vat' => '9.68']], $v['vat_lines']);
        $this->assertSame('50.00', $v['gross_total']);
        $this->assertSame(1, $v['category_id']);
        $this->assertSame(['doc_number'], $v['uncertain']);
        $this->assertTrue($v['totals_ok']);
    }

    public function test_bad_fields_are_dropped_and_marked_uncertain(): void
    {
        $v = financeValidateScan([
            'direction' => 'SIDEWAYS', 'doc_type' => 'POEM', 'counterparty_vat' => '123456789',
            'doc_date' => '2026-12-25', 'due_date' => '31/12/2026',
            'vat_lines' => [['rate' => 23, 'net' => 10, 'vat' => 2.3], ['rate' => 13, 'net' => -5, 'vat' => 1]],
            'gross_total' => 'lots', 'category' => 'Δωρεές',
        ], self::CATS, '2026-11-22', 24);

        $this->assertNull($v['direction']);
        $this->assertNull($v['doc_type']);
        $this->assertNull($v['counterparty_vat']);
        $this->assertNull($v['doc_date'], 'a document dated in the future is not believed');
        $this->assertNull($v['due_date']);
        $this->assertSame([], $v['vat_lines']);
        $this->assertNull($v['gross_total']);
        $this->assertSame(2, $v['category_id'], 'no valid direction: a category is matched by name alone');
        foreach (['counterparty_vat', 'doc_date', 'due_date', 'vat_lines'] as $f) {
            $this->assertContains($f, $v['uncertain'], $f);
        }
    }

    public function test_a_total_with_no_vat_breakdown_becomes_one_line_at_the_default_rate(): void
    {
        $v = financeValidateScan(['direction' => 'OUT', 'gross_total' => '12,40', 'vat_lines' => []], self::CATS, '2026-11-22', 24);
        $this->assertSame([['rate' => 24, 'net' => '10.00', 'vat' => '2.40']], $v['vat_lines']);
        $this->assertContains('vat_lines', $v['uncertain']);
        $this->assertTrue($v['totals_ok']);
    }

    public function test_a_line_with_only_net_gets_its_vat_computed_and_a_total_mismatch_is_flagged(): void
    {
        $v = financeValidateScan(['vat_lines' => [['rate' => 24, 'net' => 10, 'vat' => null]], 'gross_total' => 20], self::CATS, '2026-11-22', 24);
        $this->assertSame([['rate' => 24, 'net' => '10.00', 'vat' => '2.40']], $v['vat_lines']);
        $this->assertFalse($v['totals_ok']);
    }

    public function test_a_missing_total_is_taken_from_the_lines(): void
    {
        $v = financeValidateScan(['vat_lines' => [['rate' => 13, 'net' => 10, 'vat' => 1.3]]], self::CATS, '2026-11-22', 24);
        $this->assertSame('11.30', $v['gross_total']);
        $this->assertTrue($v['totals_ok']);
    }

    public function test_only_real_images_are_sent(): void
    {
        $png = 'data:image/png;base64,' . base64_encode(str_repeat("\x89PNG", 50));
        $this->assertTrue(financeScanImageOk($png));
        $this->assertFalse(financeScanImageOk('data:image/gif;base64,' . base64_encode(str_repeat('x', 200))));
        $this->assertFalse(financeScanImageOk('data:image/png;base64,***not base64***'));
        $this->assertFalse(financeScanImageOk('https://example.com/a.png'));
    }

    public function test_the_messages_carry_the_image_and_ask_for_json(): void
    {
        $msgs = financeScanMessages('data:image/jpeg;base64,AAAA', ['Καύσιμα', 'Δωρεές']);
        $this->assertSame('system', $msgs[0]['role']);
        $this->assertStringContainsString('JSON', $msgs[0]['content']);
        $parts = $msgs[1]['content'];
        $this->assertSame('text', $parts[0]['type']);
        $this->assertStringContainsString('Καύσιμα', $parts[0]['text']);
        $this->assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,AAAA']], $parts[1]);
    }
}
