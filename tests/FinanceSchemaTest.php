<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Οικονομικά schema (migration 227, v3.510.0). Spec: docs/superpowers/specs/2026-11-22-finance-design.md
 */
final class FinanceSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
    }

    public function test_the_five_tables_exist_with_their_key_columns(): void
    {
        $expect = [
            'finance_accounts'     => ['name', 'kind', 'iban', 'opening_balance', 'opening_date', 'is_active', 'sort_order'],
            'finance_categories'   => ['name', 'direction', 'is_active', 'sort_order'],
            'finance_documents'    => ['direction', 'doc_type', 'counterparty_name', 'counterparty_vat', 'doc_number', 'doc_date', 'due_date',
                                       'net_total', 'vat_total', 'gross_total', 'category_id', 'mission_id', 'vehicle_id', 'notes',
                                       'file_original_name', 'file_stored_name', 'file_mime', 'scan_source', 'scan_provider', 'reminder_stage',
                                       'created_by', 'updated_by'],
            'finance_document_vat' => ['document_id', 'rate', 'net', 'vat'],
            'finance_movements'    => ['account_id', 'direction', 'amount', 'movement_date', 'document_id', 'category_id',
                                       'transfer_group', 'subscription_id', 'description', 'created_by'],
        ];
        foreach ($expect as $table => $columns) {
            $have = array_column(dbFetchAll("SHOW COLUMNS FROM `$table`"), 'Field');
            foreach ($columns as $c) {
                $this->assertContains($c, $have, "$table.$c");
            }
        }
    }

    public function test_the_seeds_are_there(): void
    {
        $this->assertNotNull(dbFetchOne("SELECT id FROM finance_accounts WHERE name = 'Ταμείο μετρητών' AND kind = 'CASH'"));
        $this->assertNotNull(dbFetchOne("SELECT id FROM finance_categories WHERE name = 'Συνδρομές μελών' AND direction = 'IN'"));
        $this->assertNotNull(dbFetchOne("SELECT id FROM finance_categories WHERE name = 'Καύσιμα' AND direction = 'OUT'"));
    }

    public function test_deleting_a_document_deletes_its_vat_lines_and_unlinks_its_movements(): void
    {
        $account = (int) dbInsert("INSERT INTO finance_accounts (name, kind) VALUES ('TESTFIN schema', 'CASH')");
        $doc = (int) dbInsert("INSERT INTO finance_documents (direction, doc_type, counterparty_name, doc_date, net_total, vat_total, gross_total)
                               VALUES ('OUT', 'RECEIPT', 'TESTFIN shop', '2026-11-01', 10.00, 2.40, 12.40)");
        dbExecute("INSERT INTO finance_document_vat (document_id, rate, net, vat) VALUES (?, 24, 10.00, 2.40)", [$doc]);
        $mov = (int) dbInsert("INSERT INTO finance_movements (account_id, direction, amount, movement_date, document_id, description)
                               VALUES (?, 'OUT', 12.40, '2026-11-01', ?, 'TESTFIN pay')", [$account, $doc]);

        dbExecute("DELETE FROM finance_documents WHERE id = ?", [$doc]);

        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM finance_document_vat WHERE document_id = ?", [$doc]));
        $this->assertNull(dbFetchValue("SELECT document_id FROM finance_movements WHERE id = ?", [$mov]));

        dbExecute("DELETE FROM finance_movements WHERE id = ?", [$mov]);
        dbExecute("DELETE FROM finance_accounts WHERE id = ?", [$account]);
    }

    public function test_the_migration_the_schema_and_the_permission_agree(): void
    {
        $root = dirname(__DIR__);
        $schema = (string) file_get_contents($root . '/sql/schema.sql');
        $migrations = (string) file_get_contents($root . '/includes/migrations.php');
        foreach (['finance_accounts', 'finance_categories', 'finance_documents', 'finance_document_vat', 'finance_movements', 'Συνδρομές μελών'] as $needle) {
            $this->assertStringContainsString($needle, $schema, "schema.sql: $needle");
            $this->assertStringContainsString($needle, $migrations, "migrations.php: $needle");
        }
        $this->assertSame(227, DB_SCHEMA_VERSION);
        $this->assertContains('finance_manage', getAllPermissionSlugs());
    }
}
