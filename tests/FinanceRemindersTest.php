<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceRemindersTest extends TestCase
{
    private static int $admin;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/finance-functions.php';
        self::$admin = (int) dbInsert("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, 'x', ?, 1)",
            ['Finance Admin', 'finance-rem-' . uniqid('', true) . '@example.invalid', ROLE_SYSTEM_ADMIN]);
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM users WHERE id = ?", [self::$admin]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM finance_documents WHERE counterparty_name LIKE 'TESTFIN%'");
    }

    private function doc(string $due): int
    {
        return financeSaveDocument(['direction' => 'OUT', 'counterparty_name' => 'TESTFIN Supplier', 'doc_date' => '2026-11-01', 'due_date' => $due],
            [financeSplitGross(5000, 24)], self::$admin);
    }

    public function test_system_admins_are_finance_managers(): void
    {
        $this->assertContains(self::$admin, financeManagerIds());
    }

    public function test_each_stage_is_announced_once_and_a_later_stage_announces_again(): void
    {
        $id = $this->doc('2026-11-25');
        $sent = [];
        $notify = function (int $uid, string $title, string $msg, string $type) use (&$sent, $id) {
            if (str_contains($msg, 'TESTFIN')) $sent[] = [$uid, $title, $type];
        };

        financeSendDueReminders('2026-11-10', $notify);
        $this->assertSame([], $sent, 'more than 7 days away: nothing');

        financeSendDueReminders('2026-11-20', $notify);
        $this->assertNotEmpty($sent);
        $this->assertStringContainsString('λήγει', $sent[0][1]);
        $count = count($sent);
        financeSendDueReminders('2026-11-21', $notify);
        $this->assertCount($count, $sent, 'same stage: not repeated');

        financeSendDueReminders('2026-11-26', $notify);
        $this->assertSame('danger', end($sent)[2]);
        $this->assertSame(3, (int) dbFetchValue("SELECT reminder_stage FROM finance_documents WHERE id = ?", [$id]));
    }

    public function test_moving_the_due_date_resets_the_reminders(): void
    {
        $id = $this->doc('2026-11-25');
        financeSendDueReminders('2026-11-26', fn() => null);
        financeSaveDocument(['direction' => 'OUT', 'counterparty_name' => 'TESTFIN Supplier', 'doc_date' => '2026-11-01', 'due_date' => '2026-12-31'],
            [financeSplitGross(5000, 24)], self::$admin, $id);
        $this->assertSame(0, (int) dbFetchValue("SELECT reminder_stage FROM finance_documents WHERE id = ?", [$id]));
    }

    public function test_an_invalid_today_sends_nothing(): void
    {
        $this->doc('2026-11-25');
        $called = 0;
        $this->assertSame(0, financeSendDueReminders('not-a-date', function () use (&$called) { $called++; }));
        $this->assertSame(0, $called);
    }
}
