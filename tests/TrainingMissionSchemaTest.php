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
}
