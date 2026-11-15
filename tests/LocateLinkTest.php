<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Locate link (v3.499.0): a one-use consent link for a missing person/caller's
 * own phone (locate.php). Same self-expiring token idiom as
 * missions.visitor_join_token / mission_teams.briefing_token - the token IS
 * the access control, re-checked at read time, no distinction between "never
 * existed" and "no longer valid" on the public side.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class LocateLinkTest extends TestCase
{
    private int $missionId;
    private int $closedMissionId;
    private int $userId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'DEPARTMENT_ADMIN')",
            ['Συντονιστής Τεστ', 'll-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $make = fn(string $status) => (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['LL Mission', 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, $status, $this->userId]
        );
        $this->missionId = $make(STATUS_OPEN);
        $this->closedMissionId = $make('CLOSED');
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function testCreateReturnsA64CharHexToken(): void
    {
        $link = createMissionLocateLink($this->missionId, 'Καλών', $this->userId, null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $link['token']);
    }

    public function testTwoLinksNeverShareAToken(): void
    {
        $a = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $b = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $this->assertNotSame($a['token'], $b['token']);
    }

    public function testAnActiveTokenResolves(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $found = findActiveMissionLocateLink($link['token']);
        $this->assertNotNull($found);
        $this->assertSame($this->missionId, $found['mission_id']);
    }

    public function testTheListedUrlIsBuiltServerSideFromBaseUrlNotLocationOrigin(): void
    {
        $link = createMissionLocateLink($this->missionId, 'A', $this->userId, null);
        $listed = loadMissionLocateLinks($this->missionId)[0];
        $this->assertSame(rtrim(BASE_URL, '/') . '/locate.php?token=' . $link['token'], $listed['url']);
    }

    public function testAnUnknownTokenDoesNotResolve(): void
    {
        $this->assertNull(findActiveMissionLocateLink(bin2hex(random_bytes(32))));
    }

    public function testAnEmptyTokenDoesNotResolve(): void
    {
        $this->assertNull(findActiveMissionLocateLink(''));
    }

    public function testARevokedTokenDoesNotResolve(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $this->assertTrue(revokeMissionLocateLink($link['id'], $this->missionId));
        $this->assertNull(findActiveMissionLocateLink($link['token']));
    }

    public function testRevokingWithTheWrongMissionIdDoesNothing(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $this->assertFalse(revokeMissionLocateLink($link['id'], $this->closedMissionId));
        $this->assertNotNull(findActiveMissionLocateLink($link['token']));
    }

    public function testRevokingTwiceTheSecondTimeDoesNothing(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $this->assertTrue(revokeMissionLocateLink($link['id'], $this->missionId));
        $this->assertFalse(revokeMissionLocateLink($link['id'], $this->missionId));
    }

    public function testAnExpiredTokenDoesNotResolve(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        dbExecute("UPDATE mission_locate_links SET expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 60), $link['id']]);
        $this->assertNull(findActiveMissionLocateLink($link['token']));
    }

    public function testADurationInHoursSetsAFutureExpiry(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, 4);
        $row = dbFetchOne("SELECT expires_at FROM mission_locate_links WHERE id = ?", [$link['id']]);
        $this->assertGreaterThan(time() + 3 * 3600, strtotime($row['expires_at']));
        $this->assertLessThan(time() + 5 * 3600, strtotime($row['expires_at']));
    }

    public function testATokenOnAClosedMissionDoesNotResolve(): void
    {
        $link = createMissionLocateLink($this->closedMissionId, null, $this->userId, null);
        $this->assertNull(findActiveMissionLocateLink($link['token']));
    }

    public function testFirstPositionSetsConsentedAt(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $before = dbFetchOne("SELECT consented_at FROM mission_locate_links WHERE id = ?", [$link['id']]);
        $this->assertNull($before['consented_at']);
        recordLocateLinkPosition((int) $link['id'], 35.3, 24.5, 12);
        $after = dbFetchOne("SELECT consented_at, last_lat, last_lng, last_accuracy_m, update_count FROM mission_locate_links WHERE id = ?", [$link['id']]);
        $this->assertNotNull($after['consented_at']);
        $this->assertSame(35.3, (float) $after['last_lat']);
        $this->assertSame(24.5, (float) $after['last_lng']);
        $this->assertSame(12, (int) $after['last_accuracy_m']);
        $this->assertSame(1, (int) $after['update_count']);
    }

    public function testASecondPositionBumpsTheCounterAndDoesNotResetConsent(): void
    {
        $link = createMissionLocateLink($this->missionId, null, $this->userId, null);
        recordLocateLinkPosition((int) $link['id'], 35.3, 24.5, 12);
        $firstConsent = dbFetchValue("SELECT consented_at FROM mission_locate_links WHERE id = ?", [$link['id']]);
        recordLocateLinkPosition((int) $link['id'], 35.31, 24.51, 20);
        $row = dbFetchOne("SELECT consented_at, update_count, last_lat FROM mission_locate_links WHERE id = ?", [$link['id']]);
        $this->assertSame($firstConsent, $row['consented_at']);
        $this->assertSame(2, (int) $row['update_count']);
        $this->assertSame(35.31, (float) $row['last_lat']);
    }

    public function testActivePositionsOnlyIncludeConsentedLinksWithAPosition(): void
    {
        $noConsent = createMissionLocateLink($this->missionId, null, $this->userId, null);
        $consented = createMissionLocateLink($this->missionId, 'Αγνοούμενος', $this->userId, null);
        recordLocateLinkPosition((int) $consented['id'], 35.3, 24.5, null);
        $revoked = createMissionLocateLink($this->missionId, null, $this->userId, null);
        recordLocateLinkPosition((int) $revoked['id'], 35.3, 24.5, null);
        revokeMissionLocateLink($revoked['id'], $this->missionId);

        $positions = loadActiveLocateLinkPositions($this->missionId);
        $this->assertCount(1, $positions);
        $this->assertSame('Αγνοούμενος', $positions[0]['label']);
    }

    public function testLoadLinksReportsEachStatus(): void
    {
        $active = createMissionLocateLink($this->missionId, 'A', $this->userId, null);
        $revoked = createMissionLocateLink($this->missionId, 'R', $this->userId, null);
        revokeMissionLocateLink($revoked['id'], $this->missionId);
        $expired = createMissionLocateLink($this->missionId, 'E', $this->userId, null);
        dbExecute("UPDATE mission_locate_links SET expires_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 60), $expired['id']]);

        $byLabel = [];
        foreach (loadMissionLocateLinks($this->missionId) as $l) {
            $byLabel[$l['label']] = $l['status'];
        }
        $this->assertSame('active', $byLabel['A']);
        $this->assertSame('revoked', $byLabel['R']);
        $this->assertSame('expired', $byLabel['E']);
    }
}
