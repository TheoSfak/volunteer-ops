<?php
/**
 * VolunteerOps - Shelf Item Expiry Reminders (Run daily via cron)
 * Notifies admins about shelf items that are expiring soon or already expired.
 * 
 * Default threshold: 30 days before expiry
 * 
 * Usage: php cron_shelf_expiry.php
 * Cron:  0 8 * * * php /path/to/cron_shelf_expiry.php
 */

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Check if inventory tables exist
try {
    dbFetchValue("SELECT 1 FROM inventory_shelf_items LIMIT 1");
} catch (\PDOException $e) {
    echo "Shelf items table does not exist yet. Skipping.\n";
    exit(0);
}

// Get threshold from settings (default: 30 days before expiry)
$thresholdDays = (int) getSetting('shelf_expiry_reminder_days', 30);

$today = date('Y-m-d');
$thresholdDate = date('Y-m-d', strtotime("+{$thresholdDays} days"));

// =============================================
// 1. Items that have ALREADY expired
// =============================================
$expiredItems = dbFetchAll(
    "SELECT * FROM inventory_shelf_items WHERE expiry_date IS NOT NULL AND expiry_date < ?",
    [$today]
);

// =============================================
// 2. Items expiring within threshold period
// =============================================
$expiringItems = dbFetchAll(
    "SELECT * FROM inventory_shelf_items WHERE expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ?",
    [$today, $thresholdDate]
);

// If nothing to report, exit
if (empty($expiredItems) && empty($expiringItems)) {
    echo "No expired or expiring shelf items found. Done.\n";
    exit(0);
}

// =============================================
// Build notification message
// =============================================
$message = '';

if (!empty($expiredItems)) {
    $message .= "⚠️ ΛΗΓΜΕΝΑ ΥΛΙΚΑ (" . count($expiredItems) . "):\n";
    foreach ($expiredItems as $item) {
        $message .= "• {$item['name']} — Έληξε: " . date('d/m/Y', strtotime($item['expiry_date']));
        if (!empty($item['shelf'])) {
            $message .= " (Ράφι: {$item['shelf']})";
        }
        $message .= "\n";
    }
    $message .= "\n";
}

if (!empty($expiringItems)) {
    $message .= "⏰ ΛHΓΟΥΝ ΣΥΝΤΟΜΑ (" . count($expiringItems) . "):\n";
    foreach ($expiringItems as $item) {
        $daysLeft = (int) ((strtotime($item['expiry_date']) - strtotime($today)) / 86400);
        $message .= "• {$item['name']} — Λήγει: " . date('d/m/Y', strtotime($item['expiry_date'])) . " ({$daysLeft} ημέρες)";
        if (!empty($item['shelf'])) {
            $message .= " (Ράφι: {$item['shelf']})";
        }
        $message .= "\n";
    }
}

$title = 'Ειδοποίηση Λήξης Υλικών Ραφιού';

// =============================================
// Send notifications to all system admins and department admins
// =============================================
$admins = dbFetchAll(
    "SELECT id, email, name FROM users WHERE role IN (?, ?) AND is_active = 1",
    [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]
);

$sentCount = 0;

foreach ($admins as $admin) {
    // In-app notification
    sendNotification($admin['id'], $title, $message);
    $sentCount++;
    
    // Email notification (if email template exists)
    if (!empty($admin['email'])) {
        try {
            sendNotificationEmail('shelf_expiry_reminder', $admin['email'], [
                'user_name' => $admin['name'],
                'expired_count' => count($expiredItems),
                'expiring_count' => count($expiringItems),
                'details' => $message,
                'threshold_days' => $thresholdDays
            ]);
        } catch (\Exception $e) {
            // Email template may not exist yet, just log it
            echo "Email to {$admin['email']} failed: " . $e->getMessage() . "\n";
        }
    }
}

echo "Shelf expiry check completed.\n";
echo "Expired items: " . count($expiredItems) . "\n";
echo "Expiring items (within {$thresholdDays} days): " . count($expiringItems) . "\n";
echo "Notifications sent to: {$sentCount} admin(s)\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
