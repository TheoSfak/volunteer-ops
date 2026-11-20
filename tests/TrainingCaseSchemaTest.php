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
}
