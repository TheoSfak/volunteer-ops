<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The dashboard's rank is by volunteering hours, the measure the municipality
 * report ranks by (v3.368.3). Reported: "53 of 58" in the report and "91 of 98"
 * on the dashboard, because the card ranked everybody by points.
 *
 * Hours are set high (the column caps at 999.99) so only the people made here
 * can stand above the user. Rolled back.
 */
final class HoursRankTest extends TestCase
{
    private int $missionId;
    private int $deletedMissionId;
    private int $shiftId;
    private int $deletedShiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title, ?string $deleted) use ($typeId): array {
            $m = (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, deleted_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600), $typeId, $deleted]
            );
            $s = (int) dbInsert(
                "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
                [$m, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]
            );
            return [$m, $s];
        };
        [$this->missionId, $this->shiftId] = $mk('Hours Rank Mission', null);
        [$this->deletedMissionId, $this->deletedShiftId] = $mk('Hours Rank Deleted Mission', date('Y-m-d H:i:s'));
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name, int $points = 0, array $over = []): int
    {
        $over += ['is_active' => 1, 'deleted_at' => null, 'role' => 'VOLUNTEER'];
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, role, is_active, deleted_at, total_points) VALUES (?, ?, 'x', ?, ?, ?, ?)",
            [$name, 'hours-' . uniqid('', true) . '@example.invalid', $over['role'], $over['is_active'], $over['deleted_at'], $points]
        );
    }

    private function attend(int $userId, float $hours, bool $attended = true, ?int $shiftId = null): void
    {
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status, attended, actual_hours) VALUES (?, ?, ?, ?, ?)",
            [$shiftId ?? $this->shiftId, $userId, PARTICIPATION_APPROVED, $attended ? 1 : 0, $hours]
        );
    }

    public function testRankFollowsHoursNotPoints(): void
    {
        $topHours = $this->makeUser('Zzz Most Hours', 0);
        $this->attend($topHours, 900.0);
        $topPoints = $this->makeUser('Aaa Most Points', 9999999);
        $this->attend($topPoints, 800.0);
        $me = $this->makeUser('Me Third', 5);
        $this->attend($me, 700.0);

        $position = hoursRankPosition($me);
        $this->assertSame(3, $position['rank']);
        $this->assertSame(700.0, $position['hours']);
        $this->assertSame(1, hoursRankPosition($topHours)['rank']);
    }

    public function testOnlyPeopleWithAttendanceAreCounted(): void
    {
        $this->makeUser('Never Came');
        $absent = $this->makeUser('Signed Up But Absent');
        $this->attend($absent, 950.0, false);
        $me = $this->makeUser('Me');
        $this->attend($me, 10.0);

        $this->assertNull(hoursRankPosition($absent), 'a request that was not attended is not on the board');
        $before = hoursRankPosition($me)['total'];
        $this->makeUser('Another Who Never Came');
        $this->assertSame($before, hoursRankPosition($me)['total'], 'people without hours do not enlarge the crowd');
    }

    public function testDeletedMissionsInactiveAndDeletedUsersDoNotCount(): void
    {
        $ghost = $this->makeUser('Ghost Mission Hours');
        $this->attend($ghost, 990.0, true, $this->deletedShiftId);
        $away = $this->makeUser('Deactivated', 0, ['is_active' => 0]);
        $this->attend($away, 980.0);
        $gone = $this->makeUser('Deleted', 0, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->attend($gone, 970.0);
        $me = $this->makeUser('Me First');
        $this->attend($me, 100.0);

        $this->assertNull(hoursRankPosition($ghost));
        $this->assertSame(1, hoursRankPosition($me)['rank']);
    }

    public function testEqualHoursAreBrokenByNameLikeTheReport(): void
    {
        $a = $this->makeUser('Aaa Tie');
        $this->attend($a, 600.0);
        $b = $this->makeUser('Bbb Tie');
        $this->attend($b, 600.0);
        $this->assertSame(hoursRankPosition($a)['rank'] + 1, hoursRankPosition($b)['rank']);
    }

    public function testTopListAgreesWithPosition(): void
    {
        $first = $this->makeUser('Top Person');
        $this->attend($first, 999.5);
        $top = hoursRankTop(3);
        $this->assertSame($first, (int) $top[0]['id']);
        $this->assertEqualsWithDelta(999.5, (float) $top[0]['total_hours'], 0.001);
        $this->assertSame(1, (int) $top[0]['shifts_count']);
        $this->assertSame(1, hoursRankPosition($first)['rank']);
    }
}
