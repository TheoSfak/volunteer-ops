<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * includes/session-cleanup.php decides which PHP session files may be deleted.
 * A wrong "yes" signs somebody out (or deletes another site's sessions on a
 * shared folder), so every rule is pinned here against a throwaway folder.
 */
final class SessionCleanupTest extends TestCase
{
    private string $dir;
    private int $now;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../includes/session-cleanup.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vo_sess_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->now = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function session(string $id, string $content, int $ageSeconds): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'sess_' . $id;
        file_put_contents($path, $content);
        touch($path, $this->now - $ageSeconds);
        return $path;
    }

    private function sweep(array $extra = []): array
    {
        return sessionFilesCleanup(array_merge(['dir' => $this->dir, 'now' => $this->now, 'keep_id' => ''], $extra));
    }

    private const STUB   = 'last_activity|i:1799990000;';
    private const LOGGED = 'last_activity|i:1799990000;user_id|i:5;csrf_token|s:64:"abc";';

    public function testStubOlderThanAnHourGoes(): void
    {
        $p = $this->session('stub0000000000000000000001', self::STUB, 2 * 3600);
        $r = $this->sweep();
        $this->assertFileDoesNotExist($p);
        $this->assertSame(1, $r['deleted_stub']);
        $this->assertSame(0, $r['deleted_old']);
    }

    public function testStubInTheOtherSerialisationGoesToo(): void
    {
        $p = $this->session('stub0000000000000000000002', 'a:1:{s:13:"last_activity";i:1799990000;}', 2 * 3600);
        $this->sweep();
        $this->assertFileDoesNotExist($p);
    }

    public function testFreshStubIsKept(): void
    {
        $p = $this->session('stub0000000000000000000003', self::STUB, 600);
        $r = $this->sweep();
        $this->assertFileExists($p);
        $this->assertSame(1, $r['kept_recent']);
    }

    public function testSignedInSessionIsKeptUntilTwoDays(): void
    {
        $day  = $this->session('user000000000000000000001', self::LOGGED, 86400);          // idle a day
        $near = $this->session('user000000000000000000002', self::LOGGED, 172800 - 600);  // just under two days
        $old  = $this->session('user000000000000000000003', self::LOGGED, 172800 + 600);  // over two days
        $r = $this->sweep();
        $this->assertFileExists($day);
        $this->assertFileExists($near);
        $this->assertFileDoesNotExist($old);
        $this->assertSame(1, $r['deleted_old']);
        $this->assertSame(2, $r['kept_recent']);
    }

    public function testAnotherSitesSessionIsNeverTouched(): void
    {
        $foreign = $this->session('wp0000000000000000000000001', 'wordpress_logged_in|s:3:"abc";', 10 * 86400);
        $empty   = $this->session('empty00000000000000000001', '', 10 * 86400);
        $r = $this->sweep();
        $this->assertFileExists($foreign);
        $this->assertFileExists($empty);
        $this->assertSame(2, $r['kept_foreign']);
        $this->assertSame(0, $r['deleted_old'] + $r['deleted_stub']);
    }

    public function testTheRunningRequestsOwnSessionIsNeverDeleted(): void
    {
        $mine = $this->session('mine00000000000000000000001', self::STUB, 10 * 86400);
        $this->sweep(['keep_id' => 'mine00000000000000000000001']);
        $this->assertFileExists($mine);
    }

    public function testOnlySessionFilesAreConsidered(): void
    {
        $other = $this->dir . DIRECTORY_SEPARATOR . 'cache_last_activity.tmp';
        file_put_contents($other, self::STUB);
        touch($other, $this->now - 10 * 86400);
        $short = $this->session('short', self::STUB, 10 * 86400);   // not a plausible session id
        $this->sweep();
        $this->assertFileExists($other);
        $this->assertFileExists($short);
    }

    public function testDryRunCountsButDeletesNothing(): void
    {
        $a = $this->session('stub0000000000000000000004', self::STUB, 7200);
        $b = $this->session('user000000000000000000004', self::LOGGED, 5 * 86400);
        $r = $this->sweep(['dry_run' => true]);
        $this->assertFileExists($a);
        $this->assertFileExists($b);
        $this->assertSame(1, $r['deleted_stub']);
        $this->assertSame(1, $r['deleted_old']);
        $this->assertTrue($r['dry_run']);
    }

    public function testMissingFolderIsReportedNotFatal(): void
    {
        $r = sessionFilesCleanup(['dir' => $this->dir . DIRECTORY_SEPARATOR . 'nope', 'keep_id' => '']);
        $this->assertFalse($r['readable']);
        $this->assertSame(0, $r['scanned']);
    }

    public function testTimeBudgetStopsAndSaysSo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->session('stub000000000000000000010' . $i, self::STUB, 7200);
        }
        $r = $this->sweep(['time_budget' => -1]);
        $this->assertFalse($r['complete']);
        $this->assertSame(5, $r['remaining']);
        $this->assertSame(0, $r['deleted_stub']);
    }

    public function testSavePathPrefixIsStripped(): void
    {
        $old = ini_get('session.save_path');
        try {
            ini_set('session.save_path', '2;0700;/var/lib/php/sessions');
            $this->assertSame('/var/lib/php/sessions', sessionSavePathForCleanup());
            ini_set('session.save_path', '/srv/sessions');
            $this->assertSame('/srv/sessions', sessionSavePathForCleanup());
        } finally {
            ini_set('session.save_path', (string) $old);
        }
    }
}
