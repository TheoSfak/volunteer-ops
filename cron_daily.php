<?php
/**
 * VolunteerOps - Daily Reminders (All-in-One)
 * Run this script once daily via Windows Task Scheduler or cron
 * 
 * Usage:
 * php C:\xampp\htdocs\volunteerops\cron_daily.php
 */

// CLI only - prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

require_once __DIR__ . '/bootstrap.php';

echo "==============================================\n";
echo "VolunteerOps - Daily Reminders\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

// 1. Task Deadline Reminders
echo "[1/5] Processing Task Deadline Reminders...\n";
include __DIR__ . '/cron_task_reminders.php';
echo "\n";

// 2. Shift Reminders
echo "[2/5] Processing Shift Reminders...\n";
include __DIR__ . '/cron_shift_reminders.php';
echo "\n";

// 3. Incomplete Mission Alerts
echo "[3/5] Processing Incomplete Mission Alerts...\n";
include __DIR__ . '/cron_incomplete_missions.php';
echo "\n";

// 4. Certificate Expiry Reminders
echo "[4/5] Processing Certificate Expiry Reminders...\n";
include __DIR__ . '/cron_certificate_expiry.php';
echo "\n";

// 5. Shelf Item Expiry Reminders
echo "[5/5] Processing Shelf Item Expiry Reminders...\n";
include __DIR__ . '/cron_shelf_expiry.php';
echo "\n";

// 6. Citizen Certificate Expiry Reminders
echo "[6/6] Processing Citizen Certificate Expiry Reminders...\n";
include __DIR__ . '/cron_citizen_cert_expiry.php';
echo "\n";

echo "==============================================\n";
echo "All reminders completed successfully!\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n";
