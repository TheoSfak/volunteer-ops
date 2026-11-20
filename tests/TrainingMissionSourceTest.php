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

    public function test_attendance_page_never_selects_training_rows_for_points(): void
    {
        $s = $this->src('attendance.php');
        $this->assertStringContainsString('AND pr.attended = 1 AND pr.points_awarded = 0 AND pr.is_training = 0', $s);
    }

    public function test_dashboard_auto_award_skips_training_rows(): void
    {
        $s = $this->src('dashboard.php');
        $this->assertMatchesRegularExpression('/AND pr\.points_awarded = 0\s+AND pr\.is_training = 0/', $s);
    }

    public function test_manual_attendance_in_shift_view_awards_no_points_for_training(): void
    {
        $s = $this->src('shift-view.php');
        $this->assertStringContainsString("if (getSetting('points_enabled', '1') === '1' && empty(\$pr['is_training'])) {", $s);
    }

    public function test_dashboard_admin_filter_and_personal_stats_exclude_training(): void
    {
        $s = $this->src('dashboard.php');

        // One shared filter feeds every admin query (missions_total, hours, volunteers, monthly chart...).
        $this->assertStringContainsString("\$departmentFilter = 'AND m.is_training = 0';", $s);
        $this->assertStringContainsString("\$departmentFilter = 'AND m.department_id = ? AND m.is_training = 0';", $s);
        $this->assertStringContainsString("\$departmentFilter = 'AND m.branch_id = ? AND m.is_training = 0';", $s);

        // Personal totals: hours, yearly attendance, TEP hours, shift count.
        $this->assertGreaterThanOrEqual(3, substr_count($s, 'pr.attended = 1 AND pr.is_training = 0'));
    }

    public function test_reports_and_exports_exclude_training(): void
    {
        $r = $this->src('reports.php');
        $this->assertStringContainsString('m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY', $r);
        $this->assertSame(2, substr_count($r, 'ON pr.volunteer_id=u.id AND pr.attended=1 AND pr.is_training=0'));
        $this->assertStringContainsString('WHERE m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)', $r);

        $m = $this->src('report-municipality.php');
        $this->assertStringContainsString('m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN', $m);
        foreach (['m2', 'm3', 'm4'] as $alias) {
            $this->assertStringContainsString("{$alias}.deleted_at IS NULL AND {$alias}.is_training = 0 AND", $m);
        }

        $e = $this->src('includes/export-functions.php');
        $this->assertSame(2, substr_count($e, 'WHERE m.is_training = 0'));
        $this->assertStringNotContainsString('pr.is_training', $e); // the volunteer export's participation count is unfiltered (drills keep their real headcount)
    }
}
