<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Finding the victim (v3.506.0): the proximity rule, the gate, the found report, the card, the overview. */
final class TrainingCaseFindTest extends TestCase
{
    public function test_the_distances_are_the_agreed_ones(): void
    {
        $this->assertSame(10, TRAINING_CASE_FOUND_M);
        $this->assertSame(50, TRAINING_CASE_CLOSE_M);
        $this->assertSame(15, TRAINING_CASE_ACCURACY_CREDIT_M);
    }

    public function test_the_boundaries(): void
    {
        $this->assertSame('found', trainingCaseProximity(10.0, null));
        $this->assertSame('close', trainingCaseProximity(10.1, null));
        $this->assertSame('close', trainingCaseProximity(50.0, null));
        $this->assertSame('far', trainingCaseProximity(50.1, null));
    }

    public function test_gps_error_is_credited_but_capped(): void
    {
        $this->assertSame('found', trainingCaseProximity(14.0, 10.0));   // 14 - 10 = 4
        $this->assertSame('found', trainingCaseProximity(25.0, 15.0));   // credit 15 -> 10
        $this->assertSame('close', trainingCaseProximity(25.1, 100.0));  // credit capped at 15 -> 10.1
        $this->assertSame('found', trainingCaseProximity(9.0, -5.0));    // a negative accuracy credits nothing
        $this->assertSame('close', trainingCaseProximity(64.9, 15.0));   // 49.9
        $this->assertSame('far', trainingCaseProximity(65.1, 15.0));     // 50.1
    }
}
