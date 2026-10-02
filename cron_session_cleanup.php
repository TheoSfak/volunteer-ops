<?php
/**
 * VolunteerOps - PHP session file sweep
 *
 * Deletes session files that can no longer matter: files untouched for 2 days,
 * and "stub" files (nothing but last_activity - the Android app's token-based
 * polls create a fresh one per request) untouched for an hour. Only files that
 * carry this app's own last_activity key are ever touched, so a session folder
 * shared with other sites on the same host is safe. Rules and reasoning:
 * includes/session-cleanup.php.
 *
 * Run from cron_daily.php. Can also be run on its own, more often (stubs pile
 * up at ~120 per phone per hour):
 *
 *   0 * * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_session_cleanup.php
 *
 * The same sweep is the "Καθαρισμός αρχείων συνεδριών" button on
 * Ρυθμίσεις → Υγεία Εφαρμογής.
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/includes/session-cleanup.php';

$sweep = sessionFilesCleanup();

if (!$sweep['readable']) {
    echo "Session folder not readable: {$sweep['dir']} - nothing done.\n";
} else {
    $deleted = $sweep['deleted_old'] + $sweep['deleted_stub'];
    echo "Session folder: {$sweep['dir']}\n";
    echo "Checked {$sweep['scanned']} file(s); deleted {$deleted} "
       . "({$sweep['deleted_old']} expired, {$sweep['deleted_stub']} empty), "
       . round($sweep['freed_bytes'] / 1024, 1) . " KB freed.\n";
    echo "Kept {$sweep['kept_recent']} recent, {$sweep['kept_foreign']} not ours";
    if ($sweep['failed'] > 0) echo ", {$sweep['failed']} could not be deleted";
    echo ".\n";
    if (!$sweep['complete']) {
        echo "Stopped at the time limit; {$sweep['remaining']} file(s) left for the next run.\n";
    }
}
