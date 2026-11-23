<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceMoneyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
    }

    public function test_money_parses_greek_and_dot_decimals_into_cents(): void
    {
        $this->assertSame(1250, financeToCents('12,50'));
        $this->assertSame(1250, financeToCents('12.5'));
        $this->assertSame(1250, financeToCents(12.5));
        $this->assertSame(1200, financeToCents(' 12 '));
        $this->assertSame(1, financeToCents('0.005'));
        $this->assertSame(0, financeToCents('0.004'));
        $this->assertSame(-505, financeToCents('-5.05'));
        $this->assertSame(0, financeToCents(''));
        $this->assertSame(0, financeToCents('abc'));
        $this->assertSame(123456, financeToCents('1.234,56'));
        $this->assertSame(0, financeToCents(null));
        $this->assertSame(0, financeToCents('99999999999999999999'));
    }


    /** Same table as tests/js/finance-form.test.js (parseCents): keep the two in step. */
    public static function amountExamples(): array
    {
        return [
            ['1.500', 150000], ['1.234,56', 123456], ['12,40', 1240], ['1500.00', 150000], ['12.5', 1250],
            ['12.000', 1200000], ['1.000.000', 100000000], ['1500', 150000], ['0,5', 50], ['0.500', 50],
            ['1000.500', 100050], ['-1.500,25', -150025], [' 1 234,50 ', 123450],
            ['1.500 €', 150000], ['€ 12,40', 1240], ["12,40\u{00A0}€", 1240], ['€', null],
            ['1.23.4', null], ['abc', null], ['1,2,3', null], ['', null], ['1.2345,6', null], ['1,5.0', null], ['--5', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('amountExamples')]
    public function test_greek_amounts_parse_and_garbage_is_null(string $in, ?int $cents): void
    {
        $this->assertSame($cents, financeParseCents($in), $in);
        $this->assertSame($cents ?? 0, financeToCents($in), $in);
    }

    public function test_require_cents_refuses_garbage_in_greek_but_lets_empty_through(): void
    {
        $this->assertSame(150000, financeRequireCents('1.500'));
        $this->assertSame(0, financeRequireCents('  '));
        try {
            financeRequireCents('1.23.4');
            $this->fail('should refuse');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Μη έγκυρο ποσό «1.23.4»', $e->getMessage());
        }
    }

    public function test_the_scan_amount_reader_keeps_a_dot_as_decimal(): void
    {
        require_once __DIR__ . '/../includes/finance-scan.php';
        $this->assertSame(150, financeScanAmount('1.500'));
        $this->assertSame(1240, financeScanAmount('12.40'));
        $this->assertSame(1240, financeScanAmount('12,40'));
    }

    public function test_cents_format_back(): void
    {
        $this->assertSame('12.50', financeFromCents(1250));
        $this->assertSame('0.05', financeFromCents(5));
        $this->assertSame('-0.05', financeFromCents(-5));
        $this->assertSame('1.234,50 €', financeFormat(123450));
    }

    public function test_splitting_a_gross_amount_keeps_net_plus_vat_equal_to_gross(): void
    {
        $this->assertSame(['rate' => 24, 'net' => 1000, 'vat' => 240, 'gross' => 1240], financeSplitGross(1240, 24));
        $this->assertSame(['rate' => 13, 'net' => 885, 'vat' => 115, 'gross' => 1000], financeSplitGross(1000, 13));
        $this->assertSame(['rate' => 0, 'net' => 999, 'vat' => 0, 'gross' => 999], financeSplitGross(999, 0));
        foreach (FINANCE_VAT_RATES as $rate) {
            foreach ([1, 99, 1001, 123456] as $g) {
                $s = financeSplitGross($g, $rate);
                $this->assertSame($g, $s['net'] + $s['vat'], "rate $rate gross $g");
            }
        }
    }

    public function test_splitting_a_net_amount(): void
    {
        $this->assertSame(['rate' => 24, 'net' => 1000, 'vat' => 240, 'gross' => 1240], financeSplitNet(1000, 24));
        $this->assertSame(['rate' => 6, 'net' => 333, 'vat' => 20, 'gross' => 353], financeSplitNet(333, 6));
    }

    public function test_document_totals_and_the_two_cent_tolerance(): void
    {
        $t = financeDocumentTotals([['net' => 1000, 'vat' => 240], ['net' => 500, 'vat' => 65]]);
        $this->assertSame(['net' => 1500, 'vat' => 305, 'gross' => 1805], $t);
        $this->assertFalse(financeTotalsMismatch(1807, 1805));
        $this->assertTrue(financeTotalsMismatch(1808, 1805));
        $this->assertFalse(financeTotalsMismatch(1803, 1805));
    }

    public function test_paid_status(): void
    {
        $this->assertSame('OPEN', financePaidStatus(1000, 0));
        $this->assertSame('PARTIAL', financePaidStatus(1000, 400));
        $this->assertSame('PAID', financePaidStatus(1000, 1000));
        $this->assertSame('PAID', financePaidStatus(0, 0));
    }

    public function test_due_urgency_and_reminder_stage(): void
    {
        $this->assertSame('none', financeDueUrgency(null, '2026-11-22'));
        $this->assertSame('overdue', financeDueUrgency('2026-11-21', '2026-11-22'));
        $this->assertSame('soon', financeDueUrgency('2026-11-22', '2026-11-22'));
        $this->assertSame('soon', financeDueUrgency('2026-11-29', '2026-11-22'));
        $this->assertSame('later', financeDueUrgency('2026-11-30', '2026-11-22'));

        $this->assertSame(0, financeReminderStageFor('2026-11-30', '2026-11-22'));
        $this->assertSame(1, financeReminderStageFor('2026-11-29', '2026-11-22'));
        $this->assertSame(1, financeReminderStageFor('2026-11-23', '2026-11-22'));
        $this->assertSame(2, financeReminderStageFor('2026-11-22', '2026-11-22'));
        $this->assertSame(3, financeReminderStageFor('2026-11-01', '2026-11-22'));
    }

    public function test_afm_check_digit(): void
    {
        $this->assertTrue(financeValidVatNumber('090000045'));
        $this->assertFalse(financeValidVatNumber('090000046'));
        $this->assertFalse(financeValidVatNumber('000000000'));
        $this->assertFalse(financeValidVatNumber('12345'));
        $this->assertFalse(financeValidVatNumber('09000004A'));
        $this->assertFalse(financeValidVatNumber("090000045\n"));
    }

    public function test_dates_are_strict(): void
    {
        $this->assertTrue(financeValidDate('2026-02-28'));
        $this->assertFalse(financeValidDate('2026-02-30'));
        $this->assertFalse(financeValidDate('28/02/2026'));
        $this->assertFalse(financeValidDate(''));
        $this->assertFalse(financeValidDate("2026-02-28\n"));
        $this->assertFalse(financeValidDate(null));
    }
}
