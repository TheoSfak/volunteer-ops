<?php
/**
 * VolunteerOps - Mission Video Retention Sweep
 *
 * Deletes the video FILES of missions that closed more than
 * mission_video_retention_days ago (default 30; 0 = off). Photos stay, and so do
 * the row, poster frame and note of each video. Run from cron_daily.php.
 *
 * Automatic for the same reason the vitals sweep is: a retention window that only
 * holds when somebody remembers to click something is not a retention window.
 * The rules and the reasoning live in purgeExpiredMissionVideos().
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

$mediaPurge = purgeExpiredMissionVideos();

if ($mediaPurge['days'] === 0) {
    echo "Mission video retention: off (mission_video_retention_days = 0)\n";
} else {
    echo "Mission video retention: {$mediaPurge['days']} days after the mission closes\n";
    echo "Deleted {$mediaPurge['deleted']} video file(s), {$mediaPurge['already_gone']} already missing, {$mediaPurge['failed']} could not be removed.\n";
}
echo "\n";
