<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The rank a volunteer is told must be the position they hold in the
 * leaderboard's own list (v3.357.3).
 *
 * Reported from the field: 2nd on the leaderboard, 5th on their own page. The
 * three pages that show a rank counted three different crowds — one included
 * people who had been deleted, one left out everyone but volunteers — and a tie
 * gave both people the same number while the list put one under the other.
 *
 * Points are set far above anything else in the test database so the only
 * people who can stand above the user are the ones made here. Rolled back.
 */
final class LeaderboardRankTest extends TestCase
{
    private const BASE = 7000000;

    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name, int $points, array $over = []): int
    {
        $over += ['is_active' => 1, 'deleted_at' => null, 'role' => 'VOLUNTEER'];
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, role, is_active, deleted_at, total_points) VALUES (?, ?, 'x', ?, ?, ?, ?)",
            [$name, 'rank-' . uniqid('', true) . '@example.invalid', $over['role'], $over['is_active'], $over['deleted_at'], $points]
        );
    }

    /** What leaderboard.php's own list says: the order its query sorts into. */
    private function positionInList(int $userId): int
    {
        $ids = array_map('intval', array_column(dbFetchAll(
            "SELECT u.id FROM users u WHERE u.is_active = 1 AND u.deleted_at IS NULL
             ORDER BY u.total_points DESC, u.name ASC"
        ), 'id'));
        return array_search($userId, $ids, true) + 1;
    }

    public function testPeopleWhoHaveLeftDoNotStandAboveAnyone(): void
    {
        $this->makeUser('Aaa First', self::BASE + 300);
        $this->makeUser('Gone One', self::BASE + 290, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->makeUser('Gone Two', self::BASE + 280, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->makeUser('Gone Three', self::BASE + 270, ['deleted_at' => date('Y-m-d H:i:s')]);
        $me = $this->makeUser('Me Second', self::BASE + 200);

        $position = leaderboardPosition($me);
        $this->assertSame(2, $position['rank'], 'three deleted people above must not push this to 5th');
        $this->assertSame($this->positionInList($me), $position['rank']);
    }

    public function testSomebodyDeactivatedDoesNotCountEither(): void
    {
        $this->makeUser('Away', self::BASE + 500, ['is_active' => 0]);
        $me = $this->makeUser('Me', self::BASE + 400);
        $this->assertSame(1, leaderboardPosition($me)['rank']);
    }

    public function testAnAdministratorWithPointsCountsBecauseTheListShowsThem(): void
    {
        // The leaderboard page lists every active user, whatever their role,
        // so the rank has to count them too or it disagrees with the page it
        // is read next to.
        $this->makeUser('Admin Above', self::BASE + 900, ['role' => 'SYSTEM_ADMIN']);
        $me = $this->makeUser('Me', self::BASE + 800);
        $this->assertSame(2, leaderboardPosition($me)['rank']);
        $this->assertSame($this->positionInList($me), 2);
    }

    public function testTiesAreBrokenByNameLikeTheList(): void
    {
        $a = $this->makeUser('Alpha Tie', self::BASE + 100);
        $b = $this->makeUser('Beta Tie', self::BASE + 100);
        $c = $this->makeUser('Gamma Tie', self::BASE + 100);

        $this->assertSame(1, leaderboardPosition($a)['rank']);
        $this->assertSame(2, leaderboardPosition($b)['rank'], 'a tie used to read as the same rank for all three');
        $this->assertSame(3, leaderboardPosition($c)['rank']);
        foreach ([$a, $b, $c] as $id) {
            $this->assertSame($this->positionInList($id), leaderboardPosition($id)['rank']);
        }
    }

    public function testTheTotalIsTheBoardAndNotWhoIsEverRegistered(): void
    {
        $before = leaderboardPosition($this->makeUser('Counted', self::BASE + 50))['total'];
        $this->makeUser('Deleted', self::BASE + 60, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->makeUser('Inactive', self::BASE + 60, ['is_active' => 0]);
        $me = $this->makeUser('Counted Too', self::BASE + 40);
        $this->assertSame($before + 1, leaderboardPosition($me)['total']);
    }

    public function testTheDashboardTopListIsTheLeaderboardsOwnTop(): void
    {
        // The dashboard widget ran its own query for volunteers only, so
        // somebody could be 2nd in it with three shift leaders above them on
        // the leaderboard. Its place for a person must be their rank.
        $this->makeUser('Lead One', self::BASE + 900, ['role' => 'SHIFT_LEADER']);
        $this->makeUser('Lead Two', self::BASE + 800, ['role' => 'SHIFT_LEADER']);
        $this->makeUser('Lead Three', self::BASE + 700, ['role' => 'SHIFT_LEADER']);
        $this->makeUser('Gone', self::BASE + 650, ['deleted_at' => date('Y-m-d H:i:s')]);
        $me = $this->makeUser('Plain Volunteer', self::BASE + 600);

        $top = leaderboardTop(4);
        $this->assertCount(4, $top);
        $names = array_column($top, 'name');
        $this->assertSame(['Lead One', 'Lead Two', 'Lead Three', 'Plain Volunteer'], array_slice($names, 0, 4));
        $this->assertNotContains('Gone', $names);
        $this->assertSame(4, leaderboardPosition($me)['rank']);
        $this->assertSame($me, (int) $top[3]['id'], 'the widget puts them where their rank says');
        $this->assertSame(array_map('intval', array_column($top, 'id')), array_slice(array_map("intval", array_column(dbFetchAll(
            "SELECT u.id FROM users u WHERE u.is_active = 1 AND u.deleted_at IS NULL ORDER BY u.total_points DESC, u.name ASC"
        ), 'id')), 0, 4));
    }

    public function testSomebodyNotOnTheBoardHasNoPosition(): void
    {
        $gone = $this->makeUser('Gone', self::BASE + 1, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->assertNull(leaderboardPosition($gone));
        $this->assertNull(leaderboardPosition(0));
    }
}
