<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A drill's attendance must not show up in any real number. Every test seeds one
 * real and one training mission for the same volunteer and asserts only the real
 * one is counted.
 */
final class TrainingIsolationTest extends TestCase
{
    private int $user;
    private array $real;
    private array $drill;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->user = TrainingFixtures::user('Isolation Vol');
        $this->real = TrainingFixtures::mission(false);
        $this->drill = TrainingFixtures::mission(true);
        // The test DB's achievements table is only partly seeded; make sure the
        // achievements these tests assert on exist (rolled back with the transaction).
        dbExecute(
            "INSERT IGNORE INTO achievements (code, name, description, category, icon, required_points, threshold)
             VALUES ('first_shift', 'First shift', 'Completed the first shift', 'milestone', '*', 0, 1),
                    ('first_mission', 'First mission', 'Completed the first mission', 'milestone', '*', 0, 1)"
        );
        dbExecute("UPDATE achievements SET is_active = 1 WHERE code IN ('first_shift', 'first_mission')");
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_hours_rank_ignores_training_hours(): void
    {
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $pos = hoursRankPosition($this->user);

        $this->assertNotNull($pos);
        $this->assertEqualsWithDelta(2.0, $pos['hours'], 0.001);
    }

    public function test_a_volunteer_with_only_drills_is_not_on_the_hours_rank(): void
    {
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $this->assertNull(hoursRankPosition($this->user));
    }

    public function test_leaderboard_top_counts_only_real_shifts_and_hours(): void
    {
        dbExecute("UPDATE users SET total_points = 99999999 WHERE id = ?", [$this->user]);
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $row = null;
        foreach (leaderboardTop(100) as $r) {
            if ((int) $r['id'] === $this->user) {
                $row = $r;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['shifts_count']);
        $this->assertEqualsWithDelta(2.0, (float) $row['total_hours'], 0.001);
    }

    public function test_training_attendance_earns_no_achievement(): void
    {
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 2.0);

        $earned = array_column(checkAndAwardAchievements($this->user), 'code');

        $this->assertNotContains('first_shift', $earned);
        $this->assertNotContains('first_mission', $earned);
    }

    public function test_real_attendance_still_earns_the_first_shift_achievement(): void
    {
        // Control: proves the previous test is not passing only because achievements are off.
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);

        $earned = array_column(checkAndAwardAchievements($this->user), 'code');

        $this->assertContains('first_shift', $earned);
    }

    public function test_action_room_type_scorecard_counts_only_real_missions(): void
    {
        // One COMPLETED real mission and one COMPLETED drill of the same type, same period.
        $realDone = TrainingFixtures::mission(false, STATUS_COMPLETED);
        $drillDone = TrainingFixtures::mission(true, STATUS_COMPLETED);
        $typeId = (int) dbFetchValue("SELECT mission_type_id FROM missions WHERE id = ?", [$realDone['mission']]);
        $this->assertSame($typeId, (int) dbFetchValue("SELECT mission_type_id FROM missions WHERE id = ?", [$drillDone['mission']]));

        $from = date('Y-m-d', strtotime('-1 day'));
        $to = date('Y-m-d', strtotime('+1 day'));
        $range = "m.deleted_at IS NULL AND m.mission_type_id = ? AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY";
        $realCount = (int) dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $range AND m.is_training = 0", [$typeId, $from, $to]);
        $allCount = (int) dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $range", [$typeId, $from, $to]);
        $this->assertGreaterThan($realCount, $allCount, 'the fixture must contain at least one drill in the period');

        $aggregates = computeWarRoomTypeAggregates($from, $to);

        $this->assertSame($realCount, $aggregates[$typeId]['mission_count']);
    }
}
