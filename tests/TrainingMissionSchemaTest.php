<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Migration 224: missions.is_training, participation_requests.is_training and
 * the BEFORE INSERT trigger that copies the first onto the second.
 */
final class TrainingMissionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_both_columns_exist_and_default_to_zero(): void
    {
        foreach (['missions', 'participation_requests'] as $table) {
            $col = dbFetchOne("SHOW COLUMNS FROM {$table} LIKE 'is_training'");
            $this->assertNotNull($col, "{$table}.is_training is missing");
            $this->assertSame('0', (string) $col['Default'], "{$table}.is_training must default to 0");
            $this->assertSame('NO', $col['Null']);
        }
    }

    public function test_trigger_copies_the_training_flag_onto_a_new_participation(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $real = TrainingFixtures::mission(false);

        $drillPr = TrainingFixtures::participation($drill['shift'], $user);
        $realPr = TrainingFixtures::participation($real['shift'], $user);

        $this->assertSame(1, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$drillPr]));
        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$realPr]));
    }

    public function test_trigger_ignores_a_value_supplied_on_insert(): void
    {
        $user = TrainingFixtures::user();
        $real = TrainingFixtures::mission(false);

        $id = (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status, is_training) VALUES (?, ?, ?, 1)",
            [$real['shift'], $user, PARTICIPATION_APPROVED]
        );

        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$id]));
    }
    public function test_the_reconcile_sql_repairs_rows_created_while_the_trigger_was_missing(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $real = TrainingFixtures::mission(false);
        $drillPr = TrainingFixtures::participation($drill['shift'], $user);
        $realPr = TrainingFixtures::participation($real['shift'], $user);

        // Simulate the gap: a drill participation inserted while CREATE TRIGGER had failed.
        dbExecute("UPDATE participation_requests SET is_training = 0 WHERE id = ?", [$drillPr]);
        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$drillPr]));

        $changed = dbExecute(trainingReconcileParticipationsSql());

        $this->assertGreaterThanOrEqual(1, $changed);
        $this->assertSame(1, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$drillPr]));
        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$realPr]));
        // Idempotent: a second run has nothing left to repair.
        $this->assertSame(0, dbExecute(trainingReconcileParticipationsSql()));
    }

    public function test_migration_224_reconciles_only_after_the_trigger_is_created(): void
    {
        $src = file_get_contents(__DIR__ . "/../includes/migrations.php");
        $this->assertNotFalse($src);
        $start = strpos($src, "\x27version\x27     => 224,");
        $this->assertNotFalse($start);
        $end = strpos($src, "\x27version\x27     => 225,", $start);
        $body = $end === false ? substr($src, $start) : substr($src, $start, $end - $start);

        $create = strpos($body, "CREATE TRIGGER");
        $reconcile = strpos($body, "dbExecute(trainingReconcileParticipationsSql())");
        $this->assertNotFalse($create);
        $this->assertNotFalse($reconcile, "migration 224 must run the reconciliation UPDATE");
        $this->assertGreaterThan($create, $reconcile, "the reconciliation must run after the trigger exists, so a failed CREATE TRIGGER throws before it");
    }
}