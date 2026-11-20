<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level checks for page scripts whose logic is inline (not callable from a
 * test). Each assertion names the behaviour it protects.
 */
final class TrainingMissionSourceTest extends TestCase
{
    private function src(string $file): string
    {
        $s = file_get_contents(__DIR__ . '/../' . $file);
        $this->assertNotFalse($s, "cannot read {$file}");
        return $s;
    }

    public function test_mission_form_writes_the_flag_on_both_inserts_but_never_on_update(): void
    {
        $s = $this->src('mission-form.php');

        // INSERT statements (recurring + single) include the column.
        $this->assertSame(2, preg_match_all('/INSERT INTO missions[^"]*is_training/s', $s));

        // The UPDATE statement must not touch it: the flag is locked after creation.
        $this->assertSame(1, preg_match('/UPDATE missions SET(.*?)WHERE id = \?/s', $s, $m));
        $this->assertStringNotContainsString('is_training', $m[1]);

        // The value comes from the helper, not straight from $_POST.
        $this->assertStringContainsString("'is_training' => trainingMissionFlagForSave(", $s);
        // The checkbox is only offered when creating.
        $this->assertMatchesRegularExpression('/name="is_training"/', $s);
    }

    public function test_duplicating_a_mission_copies_its_training_flag(): void
    {
        $s = $this->src('missions.php');
        $this->assertMatchesRegularExpression('/INSERT INTO missions[^"]*is_training/s', $s);
        $this->assertStringContainsString("\$src['is_training']", $s);
    }
}
