<?php
/**
 * VolunteerOps - Certificate Expiry Reminders (Run daily via cron)
 * Sends reminders when certificates are about to expire (30-day and 7-day warnings)
 */

// CLI only - prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Check if notification is enabled globally
if (!isNotificationEnabled('certificate_expiry_reminder')) {
    echo "Certificate expiry reminders are disabled.\n";
    return;
}

// Get settings
$daysFirst = (int) getSetting('certificate_reminder_days_first', 30);
$daysUrgent = (int) getSetting('certificate_reminder_days_urgent', 7);

$sentCount = 0;
$processedCount = 0;

try {
    // ── 30-day (first) reminders ────────────────────────────────────────────
    $firstReminders = dbFetchAll("
        SELECT vc.*, ct.name as type_name, u.id as uid, u.name as user_name, u.email as user_email
        FROM volunteer_certificates vc
        JOIN certificate_types ct ON vc.certificate_type_id = ct.id
        JOIN users u ON vc.user_id = u.id
        WHERE vc.expiry_date IS NOT NULL
          AND vc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND vc.reminder_sent_30 = 0
          AND u.is_active = 1
    ", [$daysFirst]);

    foreach ($firstReminders as $cert) {
        $processedCount++;
        $daysLeft = (int) ((strtotime($cert['expiry_date']) - time()) / 86400);
        if ($daysLeft < 0) $daysLeft = 0;

        // Send email
        if ($cert['user_email']) {
            $result = sendNotificationEmail('certificate_expiry_reminder', $cert['user_email'], [
                'user_name' => $cert['user_name'],
                'certificate_type' => $cert['type_name'],
                'expiry_date' => formatDate($cert['expiry_date']),
                'days_remaining' => $daysLeft,
            ]);
            if ($result['success']) {
                $sentCount++;
            }
        }

        // In-app notification
        sendNotification(
            $cert['uid'],
            'Λήξη Πιστοποιητικού',
            "Το πιστοποιητικό σας «{$cert['type_name']}» λήγει σε {$daysLeft} ημέρες ({$cert['expiry_date']}).",
            'warning',
            'certificate_expiry_reminder'
        );

        // Mark as sent
        dbExecute("UPDATE volunteer_certificates SET reminder_sent_30 = 1 WHERE id = ?", [$cert['id']]);
    }

    echo "30-day reminders processed: " . count($firstReminders) . "\n";

    // ── 7-day (urgent) reminders ────────────────────────────────────────────
    $urgentReminders = dbFetchAll("
        SELECT vc.*, ct.name as type_name, u.id as uid, u.name as user_name, u.email as user_email
        FROM volunteer_certificates vc
        JOIN certificate_types ct ON vc.certificate_type_id = ct.id
        JOIN users u ON vc.user_id = u.id
        WHERE vc.expiry_date IS NOT NULL
          AND vc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
          AND vc.reminder_sent_7 = 0
          AND u.is_active = 1
    ", [$daysUrgent]);

    foreach ($urgentReminders as $cert) {
        $processedCount++;
        $daysLeft = (int) ((strtotime($cert['expiry_date']) - time()) / 86400);
        if ($daysLeft < 0) $daysLeft = 0;

        // Send email
        if ($cert['user_email']) {
            $result = sendNotificationEmail('certificate_expiry_reminder', $cert['user_email'], [
                'user_name' => $cert['user_name'],
                'certificate_type' => $cert['type_name'],
                'expiry_date' => formatDate($cert['expiry_date']),
                'days_remaining' => $daysLeft,
            ]);
            if ($result['success']) {
                $sentCount++;
            }
        }

        // In-app notification (urgent)
        sendNotification(
            $cert['uid'],
            '⚠ Λήξη Πιστοποιητικού — Επείγον',
            "Το πιστοποιητικό σας «{$cert['type_name']}» λήγει σε μόλις {$daysLeft} ημέρες! Φροντίστε για ανανέωση.",
            'danger',
            'certificate_expiry_reminder'
        );

        // Mark as sent
        dbExecute("UPDATE volunteer_certificates SET reminder_sent_7 = 1 WHERE id = ?", [$cert['id']]);
    }

    echo "7-day urgent reminders processed: " . count($urgentReminders) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Total emails sent: $sentCount\n";
echo "Total certificates processed: $processedCount\n";
echo "Settings: first={$daysFirst}d, urgent={$daysUrgent}d\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
