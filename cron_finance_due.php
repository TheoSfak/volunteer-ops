<?php
/** Daily: remind finance managers about unpaid invoices nearing or past their due date (v3.510.0). */
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) die('CLI only.');
if (!defined('VOLUNTEEROPS')) require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';

if (!dbTableExists('finance_documents')) {
    echo "Finance tables not present yet - skipped.\n";
    return;
}
try {
    $n = financeSendDueReminders(date('Y-m-d'));
    echo "Finance due-date reminders: {$n} document(s) announced.\n";
} catch (Throwable $e) {
    error_log('cron_finance_due: ' . $e->getMessage());
    echo "Finance due-date reminders failed: " . $e->getMessage() . "\n";
}
