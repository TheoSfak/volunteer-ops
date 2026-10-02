<?php
/**
 * VolunteerOps - PHP session file sweep.
 *
 * bootstrap.php sets session.gc_probability to 0 (PHP's own collector caused
 * 5-7 s pauses on shared hosting), so nothing ever deletes a session file.
 * Two kinds pile up:
 *
 *   - ordinary files of people who simply stopped using the app;
 *   - "stub" files: every request that arrives without a session cookie gets
 *     a brand-new file, and the bearer-token endpoints the Android app polls
 *     (mobile-alerts.php every 30 s per phone, mobile-ping-location.php)
 *     never send one. Each of those files holds nothing but last_activity.
 *     Locally 690 of 2.006 files were stubs.
 *
 * A host with an inode quota that fills up fails every session write, which
 * logs everybody out at once - the failure this app has already had four
 * rounds of.
 *
 * What may be deleted is deliberately narrow, because the session folder on
 * shared hosting is often a temp folder shared with other sites:
 *
 *   - only files named sess_<id>;
 *   - only files that contain this app's own `last_activity` key. A file
 *     without it belongs to somebody else (or is empty) and is left alone;
 *   - never the session of the request that is running the sweep;
 *   - ordinary files only once untouched for MAX_AGE (2 days: the longest
 *     cookie this app issues lives 24 h, so such a file can no longer be
 *     presented by any browser and can no longer be a valid login);
 *   - stub files (nothing but last_activity - nobody was ever signed in) once
 *     untouched for STUB_AGE (1 hour).
 *
 * PHP keeps a session file's mtime current on every request (lazy_write still
 * touches it), so the mtime is the last time the session was used.
 */

const SESSION_CLEANUP_MAX_AGE  = 172800; // 2 days
const SESSION_CLEANUP_STUB_AGE = 3600;   // 1 hour

/**
 * The folder PHP writes session files to. session.save_path may carry a
 * "N;MODE;" prefix (hashed sub-folders), which is dropped - files in
 * sub-folders are not touched.
 */
function sessionSavePathForCleanup(): string
{
    $path = (string) ini_get('session.save_path');
    if ($path === '') {
        return sys_get_temp_dir();
    }
    if (strpos($path, ';') !== false) {
        $path = substr($path, strrpos($path, ';') + 1);
    }
    return $path === '' ? sys_get_temp_dir() : $path;
}

/**
 * True when a session file's content is nothing but the last_activity stamp,
 * in either of PHP's two serialisations.
 */
function sessionContentIsStub(string $content): bool
{
    return preg_match('/\Alast_activity\|i:\d+;\z/', $content) === 1
        || preg_match('/\Aa:1:\{s:13:"last_activity";i:\d+;\}\z/', $content) === 1;
}

/**
 * Sweep the session folder.
 *
 * Options (all optional):
 *   dir          folder to sweep (default: the real session folder)
 *   now          unix time to measure ages against (default: time())
 *   max_age      seconds an ordinary file must be untouched (default 2 days)
 *   stub_age     seconds a stub file must be untouched (default 1 hour)
 *   time_budget  stop after this many seconds (default 20); 'complete' says
 *                whether the whole folder was covered
 *   dry_run      count what would go without deleting anything
 *   keep_id      session id whose file must never be deleted (default: the
 *                active session of this request, if any)
 *
 * @return array{dir:string,readable:bool,scanned:int,deleted_old:int,deleted_stub:int,
 *               kept_recent:int,kept_foreign:int,failed:int,freed_bytes:int,
 *               remaining:int,complete:bool,dry_run:bool}
 */
function sessionFilesCleanup(array $opts = []): array
{
    $dir        = $opts['dir'] ?? sessionSavePathForCleanup();
    $now        = $opts['now'] ?? time();
    $maxAge     = $opts['max_age'] ?? SESSION_CLEANUP_MAX_AGE;
    $stubAge    = $opts['stub_age'] ?? SESSION_CLEANUP_STUB_AGE;
    $budget     = $opts['time_budget'] ?? 20;
    $dryRun     = !empty($opts['dry_run']);
    $keepId     = $opts['keep_id'] ?? (session_status() === PHP_SESSION_ACTIVE ? session_id() : '');

    $r = [
        'dir' => $dir, 'readable' => false, 'scanned' => 0,
        'deleted_old' => 0, 'deleted_stub' => 0, 'kept_recent' => 0,
        'kept_foreign' => 0, 'failed' => 0, 'freed_bytes' => 0,
        'remaining' => 0, 'complete' => true, 'dry_run' => $dryRun,
    ];

    $h = is_dir($dir) ? @opendir($dir) : false;
    if ($h === false) {
        return $r;
    }
    $r['readable'] = true;
    $started = microtime(true);
    $keepName = $keepId !== '' ? 'sess_' . $keepId : '';

    while (($name = readdir($h)) !== false) {
        if (strncmp($name, 'sess_', 5) !== 0 || !preg_match('/\Asess_[A-Za-z0-9,-]{16,256}\z/', $name)) {
            continue;
        }
        if ($name === $keepName) {
            continue;
        }
        if ((microtime(true) - $started) > $budget) {
            $r['complete'] = false;
            $r['remaining']++;
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path) || is_link($path)) {
            continue;
        }
        $r['scanned']++;

        $mtime = @filemtime($path);
        if ($mtime === false) {
            continue;
        }
        $age = $now - $mtime;
        // Cheapest test first: nothing younger than the stub age is ever touched.
        if ($age < $stubAge) {
            $r['kept_recent']++;
            continue;
        }

        $content = @file_get_contents($path, false, null, 0, 8192);
        if ($content === false || strpos($content, 'last_activity') === false) {
            $r['kept_foreign']++;   // not this app's file (or empty / unreadable)
            continue;
        }

        $isStub = sessionContentIsStub($content);
        if (!$isStub && $age < $maxAge) {
            $r['kept_recent']++;
            continue;
        }

        $size = (int) @filesize($path);
        if ($dryRun || @unlink($path)) {
            $r[$isStub ? 'deleted_stub' : 'deleted_old']++;
            $r['freed_bytes'] += $size;
        } else {
            $r['failed']++;
        }
    }
    closedir($h);
    return $r;
}
