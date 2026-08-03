<?php

declare(strict_types=1);

namespace Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Proves sql/schema.sql + sql/inventory_schema.sql load cleanly, end to end,
 * against a genuinely empty database - the "true zero-to-118 install" case
 * tests/README.md flags as not covered by MigrationsRunnerTest (that one
 * starts from an already-fully-migrated fixture, never from nothing).
 *
 * Uses the exact same statement-splitting logic as the real install.php web
 * installer (includes/sql-statement-splitter.php) rather than reimplementing
 * it, so a regression in that shared logic fails here too.
 *
 * Runs against its own disposable database (TEST_DB_NAME + '_freshinstall'),
 * created and dropped around the whole class - never the shared TEST_DB_NAME
 * fixture other tests depend on already being fully migrated.
 */
final class FreshInstallTest extends TestCase
{
    private static PDO $pdo;
    private static string $dbName;

    /** @var string[] */
    private static array $errors = [];

    private static int $schemaExecuted = 0;
    private static int $inventoryExecuted = 0;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../includes/sql-statement-splitter.php';

        self::$dbName = DB_NAME . '_freshinstall';

        $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
        self::$pdo->exec('CREATE DATABASE `' . self::$dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$pdo->exec('USE `' . self::$dbName . '`');

        self::$schemaExecuted = self::loadSqlFile(__DIR__ . '/../sql/schema.sql');
        self::$inventoryExecuted = self::loadSqlFile(__DIR__ . '/../sql/inventory_schema.sql');
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::$dbName . '`');
    }

    public function testNoStatementFailedOutsideTheTolerableDuplicateCase(): void
    {
        $this->assertSame(
            [],
            self::$errors,
            "every statement in schema.sql + inventory_schema.sql should either succeed or be a tolerated already-exists/Duplicate error:\n" . implode("\n---\n", self::$errors)
        );
    }

    public function testASubstantialNumberOfStatementsActuallyRan(): void
    {
        // Guards against a vacuously-"passing" run where the files failed to
        // even be read (e.g. a bad path) and zero statements were attempted.
        $this->assertGreaterThan(80, self::$schemaExecuted, 'expected the bulk of schema.sql to execute');
        $this->assertGreaterThan(10, self::$inventoryExecuted, 'expected the bulk of inventory_schema.sql to execute');
    }

    public function testEveryInventoryTableWasCreated(): void
    {
        $tables = [
            'inventory_categories', 'inventory_locations', 'inventory_items',
            'inventory_bookings', 'inventory_notes', 'inventory_fixed_assets',
            'inventory_department_access', 'inventory_shelf_items',
            'inventory_kits', 'inventory_kit_items',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(self::tableExists($table), "expected `{$table}` to exist after a fresh install");
        }
    }

    public function testInventorySeedDataLoadsWithoutDuplication(): void
    {
        // Regression guard for the bug this exposed: inventory_locations had
        // no UNIQUE KEY on `name`, so once the ordering bug was fixed, both
        // schema.sql's and inventory_schema.sql's identical seed INSERTs
        // landed as real duplicate rows instead of the second being a no-op.
        $categories = (int) self::$pdo->query('SELECT COUNT(*) FROM inventory_categories')->fetchColumn();
        $locations = (int) self::$pdo->query('SELECT COUNT(*) FROM inventory_locations')->fetchColumn();

        $this->assertSame(8, $categories, 'default inventory categories should be seeded exactly once');
        $this->assertSame(3, $locations, 'default inventory locations should be seeded exactly once, not duplicated');
    }

    public function testMissionPhotosPoiForeignKeyWasAdded(): void
    {
        // Regression guard for the MariaDB errno-1823 issue: ADD COLUMN +
        // ADD FOREIGN KEY on that same new column in one ALTER TABLE.
        $column = self::$pdo->query("SHOW COLUMNS FROM `mission_photos` LIKE 'poi_id'")->fetch();
        $this->assertNotFalse($column, 'mission_photos.poi_id should exist');

        $fk = self::$pdo->query(
            "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_photos'
               AND COLUMN_NAME = 'poi_id' AND REFERENCED_TABLE_NAME = 'mission_points_of_interest'"
        )->fetchColumn();
        $this->assertSame(1, (int) $fk, 'mission_photos.poi_id should have its foreign key to mission_points_of_interest');
    }

    public function testInventoryBookingTriggersWereCreated(): void
    {
        $triggers = self::$pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_COLUMN, 0);
        $this->assertContains('trg_booking_insert', $triggers);
        $this->assertContains('trg_booking_return', $triggers);
    }

    private static function tableExists(string $table): bool
    {
        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return ((int) $stmt->fetchColumn()) === 1;
    }

    /**
     * Mirrors install.php's own loader (SET FOREIGN_KEY_CHECKS=0, split into
     * statements, tolerate "already exists"/"Duplicate", split out and run
     * any DELIMITER $$ trigger blocks separately) so this test fails exactly
     * when a real fresh install through install.php would fail.
     */
    private static function loadSqlFile(string $path): int
    {
        $sql = file_get_contents($path);
        $sql = preg_replace('/^--.*$/m', '', $sql);

        $triggerSql = '';
        if (preg_match('/DELIMITER\s+\$\$(.*?)DELIMITER\s+;/s', $sql, $m)) {
            $triggerSql = $m[1];
        }
        $sql = preg_replace('/DELIMITER\s+\$\$.*?DELIMITER\s+;/s', '', $sql);

        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $executed = 0;
        $statements = array_filter(array_map('trim', splitSqlStatements($sql)));
        foreach ($statements as $statement) {
            if ($statement === '' || $statement === 'SET FOREIGN_KEY_CHECKS = 0' || $statement === 'SET FOREIGN_KEY_CHECKS = 1') {
                continue;
            }
            try {
                self::$pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                    self::$errors[] = $e->getMessage() . "\nstatement: " . substr($statement, 0, 300);
                }
            }
        }

        if ($triggerSql !== '') {
            $triggerStatements = array_filter(array_map('trim', explode('$$', $triggerSql)));
            foreach ($triggerStatements as $trigger) {
                if ($trigger !== '' && stripos($trigger, 'CREATE TRIGGER') !== false) {
                    try {
                        self::$pdo->exec($trigger);
                        $executed++;
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'already exists') === false) {
                            self::$errors[] = $e->getMessage() . "\ntrigger: " . substr($trigger, 0, 300);
                        }
                    }
                }
            }
        }

        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $executed;
    }
}
