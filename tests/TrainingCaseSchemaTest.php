<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Migration 225: the training-case tables and the wider scenario payload column. */
final class TrainingCaseSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_the_four_tables_exist(): void
    {
        foreach (['training_cases', 'mission_case_placements', 'mission_case_runs', 'mission_case_answers'] as $table) {
            $this->assertNotNull(dbFetchOne("SHOW TABLES LIKE '{$table}'"), "{$table} is missing");
        }
    }

    public function test_slug_is_unique(): void
    {
        $this->expectException(PDOException::class);
        dbInsert("INSERT INTO training_cases (slug, title, document) VALUES ('dup-slug', 'A', '{}')");
        dbInsert("INSERT INTO training_cases (slug, title, document) VALUES ('dup-slug', 'B', '{}')");
    }

    public function test_a_run_is_unique_per_placement_and_user_and_an_answer_per_step(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')",
            [$drill['mission']]
        );
        $run = (int) dbInsert("INSERT INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]));

        dbExecute("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'c1', 'best')", [$run]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'c2', 'harmful')", [$run]));
    }

    public function test_deleting_the_order_keeps_the_placement_and_its_runs(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $admin = TrainingFixtures::user('Admin');
        $dispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, type, geo, created_by) VALUES (?, 'point', '{\"lat\":35,\"lng\":25}', ?)",
            [$drill['mission'], $admin]
        );
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, dispatch_id, title, snapshot, audience) VALUES (?, ?, 'T', '{}', 'all')",
            [$drill['mission'], $dispatch]
        );
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]);

        dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$dispatch]);

        $this->assertNull(dbFetchValue("SELECT dispatch_id FROM mission_case_placements WHERE id = ?", [$placement]));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_runs WHERE placement_id = ?", [$placement]));
    }

    public function test_the_scenario_payload_column_holds_a_whole_document(): void
    {
        $col = dbFetchOne("SHOW COLUMNS FROM mission_scenario_items LIKE 'payload'");
        $this->assertSame('mediumtext', strtolower((string) $col['Type']));
    }

    /**
     * A MODIFY COLUMN drops the column's COMMENT unless it is restated, so migration 225 must carry the same comment as
     * sql/schema.sql. Compared by source (a database that already ran the migration would not be re-altered).
     */
    public function test_the_migration_keeps_the_payload_comment_of_the_schema(): void
    {
        $root = dirname(__DIR__);
        $schema = (string) file_get_contents($root . '/sql/schema.sql');
        $migrations = (string) file_get_contents($root . '/includes/migrations.php');

        $this->assertSame(1, preg_match("/`payload` MEDIUMTEXT NOT NULL COMMENT '([^']*)'/", substr($schema, (int) strpos($schema, 'CREATE TABLE IF NOT EXISTS `mission_scenario_items`')), $fromSchema));
        $this->assertSame(1, preg_match("/ALTER TABLE `mission_scenario_items` MODIFY COLUMN `payload` MEDIUMTEXT NOT NULL COMMENT '([^']*)'/", $migrations, $fromMigration));
        $this->assertNotSame('', $fromSchema[1]);
        $this->assertSame($fromSchema[1], $fromMigration[1]);
    }

    public function test_a_placement_holds_a_spot_and_a_team_finds_it_once(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, title, snapshot, audience, spot_lat, spot_lng, spot_hidden) VALUES (?, 'T', '{}', 'all', 35.300000, 25.100000, 1)",
            [$drill['mission']]
        );
        $row = dbFetchOne("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertEqualsWithDelta(35.3, (float) $row['spot_lat'], 0.000001);
        $this->assertEqualsWithDelta(25.1, (float) $row['spot_lng'], 0.000001);
        $this->assertSame(1, (int) $row['spot_hidden']);

        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key, found_by, lat, lng, accuracy_m) VALUES (?, 't7', ?, 35.3, 25.1, 8)", [$placement, $user]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_finds (placement_id, scope_key, found_by) VALUES (?, 't7', ?)", [$placement, $user]));
        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key, found_by) VALUES (?, 't8', ?)", [$placement, $user]);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$placement]));
    }

    public function test_a_placement_with_no_spot_is_valid_and_deleting_it_deletes_its_finds(): void
    {
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$drill['mission']]);
        $row = dbFetchOne("SELECT spot_lat, spot_hidden FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertNull($row['spot_lat']);
        $this->assertSame(0, (int) $row['spot_hidden']);
        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key) VALUES (?, 'u1')", [$placement]);
        dbExecute("DELETE FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$placement]));
    }

    public function test_the_migration_and_the_schema_agree_on_the_spot_columns(): void
    {
        $root = dirname(__DIR__);
        $schema = (string) file_get_contents($root . '/sql/schema.sql');
        $migrations = (string) file_get_contents($root . '/includes/migrations.php');
        foreach (['spot_lat', 'spot_lng', 'spot_hidden', 'mission_case_finds', 'uq_case_find'] as $needle) {
            $this->assertStringContainsString($needle, $schema, "schema.sql: $needle");
            $this->assertStringContainsString($needle, $migrations, "migrations.php: $needle");
        }
        $this->assertSame(227, DB_SCHEMA_VERSION);
    }
}
