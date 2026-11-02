<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A shift takes up to 200 volunteers (v3.420.1); it used to stop at 100.
 *
 * The limit lives in one place: the server-side check in shift-form.php, which
 * every shift (a mission's rota slot) is created and edited through. The
 * database column is a plain INT and the number input carries no max of its
 * own, so raising the check is the whole change. The minimum is held to the
 * same ceiling so a shift can still say "at least 150 of the 200".
 */
final class ShiftCapacityLimitTest extends TestCase
{
    private const SHIFT_CAPACITY_LIMIT = 200;

    private static function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testTheShiftFormAcceptsUpToTwoHundredVolunteers(): void
    {
        $page = self::source('shift-form.php');

        $this->assertSame(1, preg_match(
            '/\[\'validateNumber\',\s*\$maxVolunteers,\s*1,\s*(\d+),/',
            $page,
            $max
        ), 'the maximum volunteers check is still there');
        $this->assertSame(1, preg_match(
            '/\[\'validateNumber\',\s*\$minVolunteers,\s*1,\s*(\d+),/',
            $page,
            $min
        ), 'the minimum volunteers check is still there');

        $this->assertSame(self::SHIFT_CAPACITY_LIMIT, (int) $max[1], 'a shift takes 200 volunteers');
        $this->assertSame(self::SHIFT_CAPACITY_LIMIT, (int) $min[1], 'the minimum shares the ceiling');
    }

    public function testTheLimitBehavesAsTheFormUsesIt(): void
    {
        require_once __DIR__ . '/../includes/functions-core.php';

        $this->assertNull(validateNumber(200, 1, self::SHIFT_CAPACITY_LIMIT, 'Μέγιστος αριθμός εθελοντών'));
        $this->assertNull(validateNumber('150', 1, self::SHIFT_CAPACITY_LIMIT, 'Ελάχιστος αριθμός εθελοντών'));
        $this->assertNotNull(validateNumber(201, 1, self::SHIFT_CAPACITY_LIMIT, 'Μέγιστος αριθμός εθελοντών'));
        $this->assertNotNull(validateNumber(0, 1, self::SHIFT_CAPACITY_LIMIT, 'Μέγιστος αριθμός εθελοντών'));
    }

    public function testNoInputOrColumnHoldsTheOldHundred(): void
    {
        $page = self::source('shift-form.php');

        $this->assertSame(0, preg_match('/name="(?:max|min)_volunteers"[^>]*\smax="/s', $page), 'the number inputs have no max of their own');
        $this->assertStringContainsString('`max_volunteers` INT', self::source('sql/schema.sql'), 'the column is a plain INT');
    }
}
