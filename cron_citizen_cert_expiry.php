<?php
/**
 * VolunteerOps - Citizen Certificate Expiry Reminders (Run daily via cron)
 * Sends email reminders when citizen certificates are about to expire.
 * Intervals: 3 months, 1 month, 1 week, and on expiry day.
 * Each interval is configurable via Settings → Πολίτες tab.
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Check master switch
$enabled = getSetting('citizen_cert_notify_enabled', '0');
if ($enabled !== '1') {
    echo "Citizen certificate expiry notifications are disabled.\n";
    return;
}

$sentCount = 0;
$processedCount = 0;

// Define intervals: setting_key => [days_before, reminder_column, email_template_code, label]
$intervals = [];

if (getSetting('citizen_cert_notify_3months', '1') === '1') {
    $intervals[] = [
        'days'     => 90,
        'col'      => 'reminder_sent_3m',
        'template' => 'citizen_cert_expiry_3months',
        'label'    => '3 μήνες',
    ];
}
if (getSetting('citizen_cert_notify_1month', '1') === '1') {
    $intervals[] = [
        'days'     => 30,
        'col'      => 'reminder_sent_1m',
        'template' => 'citizen_cert_expiry_1month',
        'label'    => '1 μήνα',
    ];
}
if (getSetting('citizen_cert_notify_1week', '1') === '1') {
    $intervals[] = [
        'days'     => 7,
        'col'      => 'reminder_sent_1w',
        'template' => 'citizen_cert_expiry_1week',
        'label'    => '1 εβδομάδα',
    ];
}
if (getSetting('citizen_cert_notify_expired', '1') === '1') {
    $intervals[] = [
        'days'     => 0,
        'col'      => 'reminder_sent_expired',
        'template' => 'citizen_cert_expiry_expired',
        'label'    => 'Ληγμένο',
    ];
}

if (empty($intervals)) {
    echo "No notification intervals enabled.\n";
    return;
}

try {
    foreach ($intervals as $interval) {
        $days  = $interval['days'];
        $col   = $interval['col'];
        $tpl   = $interval['template'];
        $label = $interval['label'];

        if ($days > 0) {
            // Certificates expiring within N days but not yet expired
            $certs = dbFetchAll("
                SELECT cc.*, cct.name as type_name
                FROM citizen_certificates cc
                LEFT JOIN citizen_certificate_types cct ON cc.certificate_type_id = cct.id
                WHERE cc.expiry_date IS NOT NULL
                  AND cc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  AND cc.email IS NOT NULL AND cc.email != ''
                  AND cc.{$col} = 0
            ", [$days]);
        } else {
            // Expired today or before (only those not already notified)
            $certs = dbFetchAll("
                SELECT cc.*, cct.name as type_name
                FROM citizen_certificates cc
                LEFT JOIN citizen_certificate_types cct ON cc.certificate_type_id = cct.id
                WHERE cc.expiry_date IS NOT NULL
                  AND cc.expiry_date <= CURDATE()
                  AND cc.email IS NOT NULL AND cc.email != ''
                  AND cc.{$col} = 0
            ");
        }

        foreach ($certs as $cert) {
            $processedCount++;
            $daysLeft = max(0, (int)((strtotime($cert['expiry_date']) - time()) / 86400));
            $certType = $cert['type_name'] ?? 'Πιστοποιητικό';

            // Check if there's a notification setting + template
            if (isNotificationEnabled($tpl)) {
                $result = sendNotificationEmail($tpl, $cert['email'], [
                    'citizen_name'     => $cert['first_name'] . ' ' . $cert['last_name'],
                    'certificate_type' => $certType,
                    'expiry_date'      => formatDate($cert['expiry_date']),
                    'days_remaining'   => $daysLeft,
                ]);
                if ($result['success']) {
                    $sentCount++;
                }
            } else {
                // Fallback: send simple email if SMTP is configured
                if (isEmailConfigured()) {
                    $subject = "Λήξη Πιστοποιητικού - {$certType}";
                    if ($days === 0) {
                        $body = "<p>Αγαπητέ/ή {$cert['first_name']} {$cert['last_name']},</p>"
                            . "<p>Το πιστοποιητικό σας <strong>{$certType}</strong> έληξε στις <strong>" . formatDate($cert['expiry_date']) . "</strong>.</p>"
                            . "<p>Παρακαλούμε φροντίστε για την ανανέωσή του.</p>";
                    } else {
                        $body = "<p>Αγαπητέ/ή {$cert['first_name']} {$cert['last_name']},</p>"
                            . "<p>Το πιστοποιητικό σας <strong>{$certType}</strong> λήγει σε <strong>{$daysLeft} ημέρες</strong> (στις " . formatDate($cert['expiry_date']) . ").</p>"
                            . "<p>Παρακαλούμε φροντίστε για την ανανέωσή του εγκαίρως.</p>";
                    }
                    $r = sendEmail($cert['email'], $subject, $body);
                    if ($r['success']) {
                        $sentCount++;
                    }
                }
            }

            // Mark as sent
            dbExecute("UPDATE citizen_certificates SET {$col} = 1 WHERE id = ?", [$cert['id']]);
        }

        echo "{$label} reminders processed: " . count($certs) . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "Total emails sent: $sentCount\n";
echo "Total certificates processed: $processedCount\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
