<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A volunteer can apply to a full shift (v3.415.0): the request waits.
 *
 * A full shift used to show the volunteer «Η βάρδια είναι πλήρης» and no way to
 * apply, while the administrator could add people past the limit from the
 * mission page and approve a request over it. Now the apply form and button stay,
 * worded as a wait («Αναμονή»); the request is PENDING, takes no place, and the
 * administrator decides. The dashboard's quick approve used to refuse a full
 * shift outright, which would have made every waiting request a dead end there.
 * It now asks first and sends over_capacity=1. These are page-level decisions,
 * so they are pinned in the source the way the repo's other layout tests are.
 */
final class ShiftWaitlistTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testTheMissionPageOffersTheApplyButtonOnAFullShift(): void
    {
        $page = self::source('mission-view.php');

        $this->assertSame(0, preg_match('/!\$isPast\s*&&\s*!\$isFull/', $page), 'a full shift must still show the apply button');
        $this->assertSame(2, substr_count($page, "data-shift-full=\"<?= \$isFull ? '1' : '0' ?>\""), 'the table and the phone card both mark a full shift on the button');
        $this->assertStringContainsString('id="applyShiftFullNote"', $page, 'the apply dialog explains the waiting list');
        $this->assertStringContainsString("getAttribute('data-shift-full')", $page);
    }

    public function testTheShiftPageKeepsTheApplyFormOnAFullShift(): void
    {
        $page = self::source('shift-view.php');

        $start = strpos($page, '$shiftIsFull = $approvedCount');
        $this->assertNotFalse($start, 'the shift page no longer works out whether the shift is full for the volunteer card');
        $form = strpos($page, 'name="action" value="apply"', $start);
        $this->assertNotFalse($form, 'the apply form is gone');
        $this->assertLessThan(1500, $form - $start, 'the apply form must follow the notice directly, not sit inside the full branch');
        $this->assertStringContainsString('Υποβολή Αίτησης σε Αναμονή', $page);
    }

    public function testTheServerNeverRefusesAnApplicationBecauseTheShiftIsFull(): void
    {
        foreach (['shift-view.php' => "case 'apply':", 'mission-view.php' => "case 'apply':"] as $file => $marker) {
            $page = self::source($file);
            $start = strpos($page, $marker);
            $this->assertNotFalse($start);
            $end = strpos($page, "case '", $start + strlen($marker));
            $handler = substr($page, $start, $end - $start);
            $this->assertStringNotContainsString('max_volunteers', $handler, "$file refuses or counts against the limit in its apply handler");
            $this->assertStringNotContainsString('πλήρης', $handler, "$file refuses a full shift in its apply handler");
        }
    }

    public function testTheDashboardApproveGoesOverTheLimitOnlyWhenTheAdminSaidSo(): void
    {
        $action = self::source('participation-action.php');
        $this->assertMatchesRegularExpression("/\\\$currentCount\s*>=\s*\\\$maxVolunteers\s*&&\s*post\('over_capacity'\)\s*!==\s*'1'/", $action);

        $dashboard = self::source('dashboard.php');
        $this->assertStringContainsString('name="over_capacity" value="1"', $dashboard, 'the dashboard card never sends the consent');
        $this->assertStringContainsString('shift_approved_count', $dashboard);
        $this->assertStringContainsString('Έγκριση πάνω από το όριο θέσεων', $dashboard, 'the dashboard asks before approving over the limit');
    }
}
