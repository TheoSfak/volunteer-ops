<?php
/**
 * VolunteerOps - Daily Reminders (All-in-One)
 * Run this script once daily via Windows Task Scheduler or cron
 * 
 * Usage:
 * php C:\xampp\htdocs\volunteerops\cron_daily.php
 */

require_once __DIR__ . '/bootstrap.php';

echo "==============================================\n";
echo "VolunteerOps - Daily Reminders\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n\n";

// 1. Task Deadline Reminders
echo "[1/3] Processing Task Deadline Reminders...\n";
include __DIR__ . '/cron_task_reminders.php';
echo "\n";

// 2. Shift Reminders
echo "[2/3] Processing Shift Reminders...\n";
include __DIR__ . '/cron_shift_reminders.php';
echo "\n";

// 3. Incomplete Mission Alerts
echo "[3/3] Processing Incomplete Mission Alerts...\n";
include __DIR__ . '/cron_incomplete_missions.php';
echo "\n";

echo "==============================================\n";
echo "All reminders completed successfully!\n";
echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
echo "==============================================\n";
