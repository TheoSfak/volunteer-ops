<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * trainingCaseTransaction()'s own begin / commit / rollback path. The other case tests run inside a transaction of
 * their own, so this one does not open one: it works on a TEMPORARY table (no implicit commit, gone with the
 * connection, nothing of the real data touched).
 */
final class TrainingCaseTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        $this->assertFalse(db()->inTransaction(), 'this test needs a connection with no transaction open');
        db()->exec("DROP TEMPORARY TABLE IF EXISTS tc_tx_probe");
        db()->exec("CREATE TEMPORARY TABLE tc_tx_probe (n INT NOT NULL) ENGINE=InnoDB");
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        db()->exec("DROP TEMPORARY TABLE IF EXISTS tc_tx_probe");
    }

    private function rows(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM tc_tx_probe");
    }

    public function test_it_opens_commits_and_returns_the_result_when_no_transaction_is_open(): void
    {
        $seen = null;
        $result = trainingCaseTransaction(function () use (&$seen) {
            $seen = db()->inTransaction();
            dbExecute("INSERT INTO tc_tx_probe (n) VALUES (1)");
            return 'done';
        });
        $this->assertTrue($seen, 'the work ran inside a transaction of its own');
        $this->assertSame('done', $result);
        $this->assertFalse(db()->inTransaction(), 'and committed it');
        $this->assertSame(1, $this->rows());
    }

    public function test_it_rolls_back_what_it_opened_and_rethrows(): void
    {
        try {
            trainingCaseTransaction(function () {
                dbExecute("INSERT INTO tc_tx_probe (n) VALUES (1)");
                throw new RuntimeException('boom');
            });
            $this->fail('the exception goes on up');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse(db()->inTransaction());
        $this->assertSame(0, $this->rows(), 'the insert was undone');
    }

    public function test_inside_a_callers_transaction_it_neither_commits_nor_rolls_back(): void
    {
        db()->beginTransaction();
        trainingCaseTransaction(function () {
            dbExecute("INSERT INTO tc_tx_probe (n) VALUES (1)");
        });
        $this->assertTrue(db()->inTransaction(), 'the caller\'s transaction is still open');
        try {
            trainingCaseTransaction(function () {
                dbExecute("INSERT INTO tc_tx_probe (n) VALUES (2)");
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
        }
        $this->assertTrue(db()->inTransaction(), 'a failure inside it leaves the decision to the caller');
        $this->assertSame(2, $this->rows());
        db()->rollBack();
        $this->assertSame(0, $this->rows(), 'the caller\'s rollback undoes both');
    }
}
