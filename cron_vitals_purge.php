<?php
/**
 * VolunteerOps - Rescuer Vitals Retention Sweep
 *
 * Deletes heart-rate samples older than the configured retention window
 * (Ρυθμίσεις → Γενικά → Καρδιακοί Παλμοί Διασώστη). Run from cron_daily.php.
 *
 * Automatic rather than one more button on the health tab, unlike the audit
 * log / email log / notification cleanups: volunteer_vitals is the densest
 * table this app writes — one row per volunteer per sampling window, so a
 * 30-person mission at the default 5-second cadence produces ~130.000 rows in
 * a six-hour deployment — and it holds health data under Article 9 GDPR.
 * A retention promise that only holds when an admin remembers to click
 * something is not a retention promise.
 *
 * Safe to run when the feature is switched off: existing rows still age out,
 * which is exactly what should happen to health data after an org stops
 * collecting it.
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

$days    = vitalsConfig()['retention_days'];
$deleted = purgeOldVitals();

echo "Vitals retention: {$days} days\n";
echo "Deleted {$deleted} sample(s).\n";
