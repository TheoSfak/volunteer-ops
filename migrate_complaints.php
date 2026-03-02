<?php
/**
 * One-time migration: Create complaints table + email templates
 * Τρέξτε αυτό το αρχείο μία φορά από το browser, μετά διαγράψτε το.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$results = [];

// 1. Create complaints table
$sql1 = "CREATE TABLE IF NOT EXISTS `complaints` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `mission_id` INT UNSIGNED NULL,
    `category` ENUM('MISSION','EQUIPMENT','BEHAVIOR','ADMIN','OTHER') NOT NULL DEFAULT 'OTHER',
    `priority` ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `status` ENUM('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW',
    `admin_response` TEXT NULL,
    `responded_by` INT UNSIGNED NULL,
    `responded_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_complaint_user` (`user_id`),
    INDEX `idx_complaint_status` (`status`),
    INDEX `idx_complaint_category` (`category`),
    INDEX `idx_complaint_mission` (`mission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    dbExecute($sql1);
    $results[] = ['ok', 'Πίνακας <code>complaints</code> δημιουργήθηκε (ή υπήρχε ήδη).'];
} catch (Exception $e) {
    $results[] = ['err', 'complaints table: ' . $e->getMessage()];
}

// 2. Insert email template: complaint_submitted
$tplSubmitted = '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#dc2626;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#9888;&#65039;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Νέο Παράπονο Εθελοντή</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{admin_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η εθελοντής <strong>{{volunteer_name}}</strong> υπέβαλε νέο παράπονο και χρειάζεται εξέταση.</p><div style="background:#f9fafb;border-left:4px solid #dc2626;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Εθελοντής:</span><span style="color:#111827;font-weight:600;">{{volunteer_name}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Θέμα:</span><span style="color:#111827;font-weight:600;">{{complaint_subject}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κατηγορία:</span><span style="color:#111827;font-weight:600;">{{complaint_category}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Προτεραιότητα:</span><span style="color:#111827;font-weight:600;">{{complaint_priority}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Σχετική Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div></div><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Κείμενο Παραπόνου:</p><p style="color:#1f2937;font-size:14px;line-height:1.65;margin:0;">{{complaint_body}}</p></div><div style="text-align:center;margin:28px 0 4px;"><a href="{{complaint_url}}" style="background:#dc2626;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε το Παράπονο</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';

// 3. Insert email template: complaint_response
$tplResponse = '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#16a34a;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128172;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Απάντηση στο Παράπονό σας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η διοίκηση εξέτασε το παράπονό σας και σας στέλνει την παρακάτω απάντηση.</p><div style="background:#f9fafb;border-left:4px solid #16a34a;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Θέμα Παραπόνου:</span><span style="color:#111827;font-weight:600;">{{complaint_subject}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Νέα Κατάσταση:</span><span style="color:#111827;font-weight:600;">{{complaint_status}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Απάντηση από:</span><span style="color:#111827;font-weight:600;">{{responder_name}}</span></div></div><div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Απάντηση Διοίκησης:</p><p style="color:#1f2937;font-size:14px;line-height:1.65;margin:0;">{{admin_response}}</p></div><div style="text-align:center;margin:28px 0 4px;"><a href="{{complaint_url}}" style="background:#16a34a;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε το Παράπονο</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';

$templates = [
    [
        'code'      => 'complaint_submitted',
        'name'      => 'Νέο Παράπονο (Admin)',
        'subject'   => 'Νέο παράπονο εθελοντή - {{complaint_subject}}',
        'body_html' => $tplSubmitted,
        'desc'      => 'Αποστέλλεται στους διαχειριστές όταν υποβάλλεται νέο παράπονο',
        'vars'      => '{{app_name}}, {{admin_name}}, {{volunteer_name}}, {{complaint_subject}}, {{complaint_category}}, {{complaint_priority}}, {{complaint_body}}, {{mission_title}}, {{complaint_url}}',
    ],
    [
        'code'      => 'complaint_response',
        'name'      => 'Απάντηση Παραπόνου',
        'subject'   => 'Απάντηση στο παράπονό σας: {{complaint_subject}}',
        'body_html' => $tplResponse,
        'desc'      => 'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής απαντήσει',
        'vars'      => '{{app_name}}, {{user_name}}, {{complaint_subject}}, {{complaint_status}}, {{responder_name}}, {{admin_response}}, {{complaint_url}}',
    ],
];

foreach ($templates as $t) {
    $exists = dbFetchValue("SELECT id FROM email_templates WHERE code = ?", [$t['code']]);
    if ($exists) {
        $results[] = ['warn', "Email template <code>{$t['code']}</code> υπάρχει ήδη — παρελήφθη."];
        continue;
    }
    try {
        $tplId = dbInsert(
            "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables) VALUES (?, ?, ?, ?, ?, ?)",
            [$t['code'], $t['name'], $t['subject'], $t['body_html'], $t['desc'], $t['vars']]
        );
        $results[] = ['ok', "Email template <code>{$t['code']}</code> προστέθηκε (id=$tplId)."];

        // notification_settings entry
        $nsExists = dbFetchValue("SELECT id FROM notification_settings WHERE code = ?", [$t['code']]);
        if (!$nsExists) {
            dbInsert(
                "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id) VALUES (?, ?, ?, 1, ?)",
                [$t['code'], $t['name'], $t['desc'], $tplId]
            );
            $results[] = ['ok', "Notification setting <code>{$t['code']}</code> προστέθηκε."];
        } else {
            $results[] = ['warn', "Notification setting <code>{$t['code']}</code> υπάρχει ήδη."];
        }
    } catch (Exception $e) {
        $results[] = ['err', "template {$t['code']}: " . $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="el">
<head><meta charset="UTF-8"><title>Migration: complaints v3.51.2</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container" style="max-width:660px">
    <h3 class="mb-4">Migration: Σύστημα Παραπόνων v3.51.2</h3>
    <?php foreach ($results as [$type, $msg]): ?>
        <?php if ($type === 'ok'): ?>
            <div class="alert alert-success py-2">✅ <?= $msg ?></div>
        <?php elseif ($type === 'warn'): ?>
            <div class="alert alert-warning py-2">⚠️ <?= $msg ?></div>
        <?php else: ?>
            <div class="alert alert-danger py-2">❌ <?= $msg ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php $hasErrors = in_array('err', array_column($results, 0)); ?>
    <?php if (!$hasErrors): ?>
        <div class="alert alert-info mt-3">
            <strong>Ολοκληρώθηκε!</strong> Μπορείτε να δείτε τα email templates στις Ρυθμίσεις → Πρότυπα Email.<br>
            <strong>Διαγράψτε αμέσως αυτό το αρχείο από τον server!</strong>
        </div>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-primary mt-2">Επιστροφή</a>
    <a href="settings.php?tab=email_templates" class="btn btn-outline-secondary mt-2">Πρότυπα Email</a>
</div>
</body>
</html>
