<?php
/**
 * VolunteerOps - Email Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

// Notification codes that users CANNOT opt out of (always delivered)
if (!defined('NON_CONFIGURABLE_NOTIFICATIONS')) {
    define('NON_CONFIGURABLE_NOTIFICATIONS', ['welcome']);
}

/**
 * Get all notification preferences for a user (cached per user per request).
 * Returns array keyed by notification_code => ['email_enabled'=>int, 'in_app_enabled'=>int]
 */
function getUserNotificationPrefs(int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $prefs = [];
    try {
        $rows = dbFetchAll(
            "SELECT notification_code, email_enabled, in_app_enabled FROM user_notification_preferences WHERE user_id = ?",
            [$userId]
        );
        foreach ($rows as $row) {
            $prefs[$row['notification_code']] = [
                'email_enabled'  => (int)$row['email_enabled'],
                'in_app_enabled' => (int)$row['in_app_enabled'],
            ];
        }
    } catch (Exception $e) {
        // Table may not exist yet (pre-migration) — allow all
    }

    $cache[$userId] = $prefs;
    return $prefs;
}

/**
 * Check if a specific notification channel is enabled for a user.
 * Default: true (opted-in) when no row exists.
 * $channel = 'email' | 'in_app'
 */
function isUserNotifEnabled(int $userId, string $code, string $channel = 'email'): bool {
    $prefs = getUserNotificationPrefs($userId);
    if (!isset($prefs[$code])) {
        return true; // no preference set = opted-in
    }
    $key = ($channel === 'email') ? 'email_enabled' : 'in_app_enabled';
    return (bool)$prefs[$code][$key];
}

/**
 * Save notification preferences for a user.
 * $prefs = [ 'code' => ['email_enabled'=>0|1, 'in_app_enabled'=>0|1], ... ]
 */
function saveUserNotificationPrefs(int $userId, array $prefs): void {
    foreach ($prefs as $code => $settings) {
        $emailEnabled  = (int)($settings['email_enabled'] ?? 1);
        $inAppEnabled  = (int)($settings['in_app_enabled'] ?? 1);
        dbExecute(
            "INSERT INTO user_notification_preferences (user_id, notification_code, email_enabled, in_app_enabled, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), in_app_enabled = VALUES(in_app_enabled), updated_at = NOW()",
            [$userId, $code, $emailEnabled, $inAppEnabled]
        );
    }
    // Clear cache for this user
    // (static var — we just call it fresh next time; for this request we reset manually)
}

/**
 * Get SMTP settings from database (cached per request)
 */
function getSmtpSettings(): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $settings = [];
    $rows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $cached = [
        'host' => $settings['smtp_host'] ?? '',
        'port' => (int)($settings['smtp_port'] ?? 587),
        'username' => $settings['smtp_username'] ?? '',
        'password' => $settings['smtp_password'] ?? '',
        'encryption' => $settings['smtp_encryption'] ?? 'tls',
        'from_email' => $settings['smtp_from_email'] ?? '',
        'from_name' => $settings['smtp_from_name'] ?? 'VolunteerOps',
    ];
    return $cached;
}

/**
 * Check if email sending is configured
 */
function isEmailConfigured(): bool {
    $smtp = getSmtpSettings();
    return !empty($smtp['host']) && !empty($smtp['from_email']);
}

/**
 * Get email template by code
 */
function getEmailTemplate(string $code): ?array {
    return dbFetchOne("SELECT * FROM email_templates WHERE code = ? AND is_active = 1", [$code]);
}

/**
 * Get all email templates
 */
function getEmailTemplates(): array {
    return dbFetchAll("SELECT * FROM email_templates ORDER BY name");
}

/**
 * Get notification setting by code
 */
function getNotificationSetting(string $code): ?array {
    $result = dbFetchOne("SELECT ns.*, et.code as template_code FROM notification_settings ns 
                       LEFT JOIN email_templates et ON ns.email_template_id = et.id 
                       WHERE ns.code = ?", [$code]);
    return $result ?: null;
}

/**
 * Check if notification is enabled
 */
function isNotificationEnabled(string $code): bool {
    $setting = getNotificationSetting($code);
    return $setting && $setting['email_enabled'] == 1;
}

/**
 * Replace template variables
 */
function replaceTemplateVariables(string $text, array $variables): string {
    foreach ($variables as $key => $value) {
        $text = str_replace('{{' . $key . '}}', $value, $text);
    }
    return $text;
}

/**
 * Log email send attempt to database
 */
function logEmail(string $to, string $subject, array $result, ?string $notificationCode = null): void {
    try {
        $smtp = getSmtpSettings();
        $userId = null;
        if (function_exists('getCurrentUserId')) {
            try { $userId = getCurrentUserId(); } catch (Exception $e) {}
        }
        dbInsert(
            "INSERT INTO email_logs (recipient_email, subject, notification_code, status, error_message, smtp_log, smtp_host, from_email, sent_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $to,
                mb_substr($subject, 0, 500),
                $notificationCode,
                $result['success'] ? 'SUCCESS' : 'FAILED',
                $result['success'] ? null : ($result['message'] ?? 'Unknown error'),
                isset($result['log']) ? implode("\n", $result['log']) : null,
                $smtp['host'] ?? null,
                $smtp['from_email'] ?? null,
                $userId
            ]
        );
    } catch (Exception $e) {
        // Silently fail — logging should never break email sending
    }
}

/**
 * Send email using SMTP
 * @param string|null $notificationCode  Optional code for log tracking
 */
function sendEmail(string $to, string $subject, string $htmlBody, ?string $fromName = null, ?string $notificationCode = null): array {
    $smtp = getSmtpSettings();
    
    if (empty($smtp['host']) || empty($smtp['from_email'])) {
        $result = ['success' => false, 'message' => 'Οι ρυθμίσεις SMTP δεν έχουν οριστεί'];
        logEmail($to, $subject, $result, $notificationCode);
        return $result;
    }
    
    $fromName = $fromName ?? $smtp['from_name'];
    $fromEmail = $smtp['from_email'];
    
    // Build headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: VolunteerOps/' . APP_VERSION
    ];
    
    // If SMTP host is configured, use socket connection
    if (!empty($smtp['host'])) {
        $result = sendSmtpEmail($to, $subject, $htmlBody, $smtp);
        logEmail($to, $subject, $result, $notificationCode);
        return $result;
    }
    
    // Fallback to PHP mail() - usually won't work without proper server config
    $success = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    
    $result = [
        'success' => $success,
        'message' => $success ? 'Το email στάλθηκε' : 'Αποτυχία αποστολής'
    ];
    logEmail($to, $subject, $result, $notificationCode);
    return $result;
}

/**
 * Send email via SMTP socket
 */
function sendSmtpEmail(string $to, string $subject, string $htmlBody, array $smtp): array {
    $log = [];
    
    try {
        // Determine port and encryption
        $port = $smtp['port'];
        $encryption = $smtp['encryption'];
        
        // SSL context — peer verification is enabled by default (secure).
        // To allow self-signed certs in a dev/intranet environment,
        // add: define('SMTP_VERIFY_SSL', false); in config.local.php
        $verifySsl = defined('SMTP_VERIFY_SSL') ? SMTP_VERIFY_SSL : true;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => $verifySsl,
                'verify_peer_name'  => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ]
        ]);
        
        // Connect based on encryption
        if ($encryption === 'ssl') {
            $socket = stream_socket_client(
                'ssl://' . $smtp['host'] . ':' . $port,
                $errno, $errstr, 30,
                STREAM_CLIENT_CONNECT,
                $context
            );
        } else {
            $socket = stream_socket_client(
                'tcp://' . $smtp['host'] . ':' . $port,
                $errno, $errstr, 30,
                STREAM_CLIENT_CONNECT,
                $context
            );
        }
        
        if (!$socket) {
            throw new Exception("Σύνδεση απέτυχε: $errstr ($errno)");
        }
        
        // Read greeting
        $response = fgets($socket, 515);
        $log[] = "S: " . trim($response);
        if (substr($response, 0, 3) !== '220') {
            throw new Exception("Μη αναμενόμενη απάντηση: $response");
        }
        
        // EHLO
        $hostname = gethostname() ?: 'localhost';
        fwrite($socket, "EHLO $hostname\r\n");
        $log[] = "C: EHLO $hostname";
        
        // Read multi-line EHLO response
        $ehloResponse = '';
        while ($line = fgets($socket, 515)) {
            $ehloResponse .= $line;
            $log[] = "S: " . trim($line);
            if (substr($line, 3, 1) === ' ') break;
        }
        
        // STARTTLS if needed
        if ($encryption === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $log[] = "C: STARTTLS";
            $response = fgets($socket, 515);
            $log[] = "S: " . trim($response);
            
            if (substr($response, 0, 3) !== '220') {
                throw new Exception("STARTTLS απέτυχε: $response");
            }
            
            // Enable TLS
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Αποτυχία ενεργοποίησης TLS");
            }
            
            // EHLO again after TLS
            fwrite($socket, "EHLO $hostname\r\n");
            $log[] = "C: EHLO $hostname";
            while ($line = fgets($socket, 515)) {
                $log[] = "S: " . trim($line);
                if (substr($line, 3, 1) === ' ') break;
            }
        }
        
        // AUTH LOGIN
        if (!empty($smtp['username']) && !empty($smtp['password'])) {
            fwrite($socket, "AUTH LOGIN\r\n");
            $log[] = "C: AUTH LOGIN";
            $response = fgets($socket, 515);
            $log[] = "S: " . trim($response);
            
            if (substr($response, 0, 3) !== '334') {
                throw new Exception("AUTH LOGIN απέτυχε: $response");
            }
            
            // Username
            fwrite($socket, base64_encode($smtp['username']) . "\r\n");
            $log[] = "C: [username]";
            $response = fgets($socket, 515);
            $log[] = "S: " . trim($response);
            
            // Password
            fwrite($socket, base64_encode($smtp['password']) . "\r\n");
            $log[] = "C: [password]";
            $response = fgets($socket, 515);
            $log[] = "S: " . trim($response);
            
            if (substr($response, 0, 3) !== '235') {
                throw new Exception("Αποτυχία πιστοποίησης: $response");
            }
        }
        
        // MAIL FROM
        fwrite($socket, "MAIL FROM:<{$smtp['from_email']}>\r\n");
        $log[] = "C: MAIL FROM:<{$smtp['from_email']}>";
        $response = fgets($socket, 515);
        $log[] = "S: " . trim($response);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception("MAIL FROM απέτυχε: $response");
        }
        
        // RCPT TO
        fwrite($socket, "RCPT TO:<$to>\r\n");
        $log[] = "C: RCPT TO:<$to>";
        $response = fgets($socket, 515);
        $log[] = "S: " . trim($response);
        if (substr($response, 0, 3) !== '250' && substr($response, 0, 3) !== '251') {
            throw new Exception("RCPT TO απέτυχε: $response");
        }
        
        // DATA
        fwrite($socket, "DATA\r\n");
        $log[] = "C: DATA";
        $response = fgets($socket, 515);
        $log[] = "S: " . trim($response);
        if (substr($response, 0, 3) !== '354') {
            throw new Exception("DATA απέτυχε: $response");
        }
        
        // Build message with proper headers for Yahoo/Gmail/Outlook compatibility
        $boundary = md5(uniqid(time()));
        $domain = explode('@', $smtp['from_email'])[1] ?? 'localhost';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        
        // Strip HTML for plain text alternative
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlBody));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));
        
        $message = "Date: " . date('r') . "\r\n";
        $message .= "From: =?UTF-8?B?" . base64_encode($smtp['from_name']) . "?= <{$smtp['from_email']}>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "Message-ID: $messageId\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $message .= "X-Mailer: VolunteerOps/" . APP_VERSION . "\r\n";
        $message .= "\r\n";
        // Plain text part
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($plainText));
        $message .= "\r\n";
        // HTML part
        $message .= "--$boundary\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($htmlBody));
        $message .= "\r\n";
        $message .= "--$boundary--\r\n";
        $message .= "\r\n.\r\n";
        
        fwrite($socket, $message);
        $log[] = "C: [message body]";
        
        $response = fgets($socket, 515);
        $log[] = "S: " . trim($response);
        if (substr($response, 0, 3) !== '250') {
            throw new Exception("Αποστολή απέτυχε: $response");
        }
        
        // QUIT
        fwrite($socket, "QUIT\r\n");
        $log[] = "C: QUIT";
        
        fclose($socket);
        
        return [
            'success' => true,
            'message' => 'Το email στάλθηκε επιτυχώς',
            'log' => $log
        ];
        
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'log' => $log
        ];
    }
}

/**
 * Send test email
 */
function sendTestEmail(string $to): array {
    $smtp = getSmtpSettings();
    $appName = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'app_name'") ?? 'VolunteerOps';
    
    $subject = "Δοκιμαστικό Email - $appName";
    $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
        <div style="background: #3498db; color: white; padding: 20px; text-align: center;">
            <h1>' . h($appName) . '</h1>
        </div>
        <div style="padding: 30px; background: #fff;">
            <h2>Δοκιμαστικό Email</h2>
            <p>Αυτό είναι ένα δοκιμαστικό email για να επιβεβαιωθεί ότι οι ρυθμίσεις SMTP είναι σωστές.</p>
            <p><strong>Ρυθμίσεις:</strong></p>
            <ul>
                <li>SMTP Host: ' . h($smtp['host']) . '</li>
                <li>Port: ' . h($smtp['port']) . '</li>
                <li>Encryption: ' . h($smtp['encryption']) . '</li>
                <li>From: ' . h($smtp['from_email']) . '</li>
            </ul>
            <p>Αν λάβατε αυτό το email, οι ρυθμίσεις είναι σωστές! ✓</p>
        </div>
        <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
            ' . h($appName) . ' - ' . date('d/m/Y H:i:s') . '
        </div>
    </div>';
    
    return sendEmail($to, $subject, $body);
}

/**
 * Send notification email using template
 * Checks both global (admin) setting AND per-user preference.
 */
function sendNotificationEmail(string $notificationCode, string $to, array $variables = []): array {
    // Check if notification is globally enabled by admin
    $setting = getNotificationSetting($notificationCode);
    if (!$setting || !$setting['email_enabled']) {
        return ['success' => false, 'message' => 'Η ειδοποίηση δεν είναι ενεργοποιημένη'];
    }
    
    // Check per-user email preference (skip for non-configurable codes)
    if (!in_array($notificationCode, NON_CONFIGURABLE_NOTIFICATIONS)) {
        $recipientUser = dbFetchOne("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL", [$to]);
        if ($recipientUser && !isUserNotifEnabled((int)$recipientUser['id'], $notificationCode, 'email')) {
            return ['success' => false, 'message' => 'Ο χρήστης έχει απενεργοποιήσει αυτή την ειδοποίηση'];
        }
    }
    
    // Get template
    $template = getEmailTemplate($setting['template_code'] ?? $notificationCode);
    if (!$template) {
        return ['success' => false, 'message' => 'Δεν βρέθηκε το template'];
    }
    
    // Add default variables (using getSetting() which is cached per request — zero extra queries)
    $appName = getSetting('app_name', 'VolunteerOps');
    $variables['app_name'] = $appName;
    $variables['login_url'] = rtrim(BASE_URL ?? 'http://localhost/volunteerops', '/') . '/login.php';

    // Inject logo_html — resolves to an <img> if a logo is configured, empty string otherwise
    $logoFile = getSetting('app_logo', '');
    if (!empty($logoFile)) {
        $baseUrl = rtrim(BASE_URL ?? 'http://localhost/volunteerops', '/');
        $logoUrl = $baseUrl . '/uploads/logos/' . $logoFile;
        $variables['logo_html'] = '<div style="margin:0 auto 14px;line-height:1;">'
            . '<img src="' . $logoUrl . '" alt="' . htmlspecialchars($appName, ENT_QUOTES) . '" '
            . 'style="max-height:64px;max-width:200px;object-fit:contain;display:inline-block;">'
            . '</div>';
    } else {
        $variables['logo_html'] = '';
    }

    
    // Replace variables
    $subject = replaceTemplateVariables($template['subject'], $variables);
    $body = replaceTemplateVariables($template['body_html'], $variables);
    
    return sendEmail($to, $subject, $body, null, $notificationCode);
}

/**
 * Simple notification wrapper - creates a notification record in database.
 * Pass $notificationCode to check per-user in-app preference.
 */
function sendNotification(int $userId, string $title, string $message, string $type = 'info', string $notificationCode = ''): void {
    // Check per-user in-app preference when a code is provided
    if ($notificationCode !== '' && !in_array($notificationCode, NON_CONFIGURABLE_NOTIFICATIONS)) {
        if (!isUserNotifEnabled($userId, $notificationCode, 'in_app')) {
            return;
        }
    }
    dbInsert(
        "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$userId, $type, $title, $message]
    );
}

/**
 * Bulk notification wrapper - creates multiple notification records in a single query.
 * Pass $notificationCode to respect per-user in-app preferences.
 */
function sendBulkNotifications(array $userIds, string $title, string $message, string $type = 'info', string $notificationCode = ''): void {
    if (empty($userIds)) return;
    
    // Filter out users who opted out of this in-app notification
    if ($notificationCode !== '' && !in_array($notificationCode, NON_CONFIGURABLE_NOTIFICATIONS)) {
        $userIds = array_filter($userIds, function ($uid) use ($notificationCode) {
            return isUserNotifEnabled((int)$uid, $notificationCode, 'in_app');
        });
        $userIds = array_values($userIds); // re-index
        if (empty($userIds)) return;
    }
    
    $values = [];
    $params = [];
    foreach ($userIds as $userId) {
        $values[] = "(?, ?, ?, ?, NOW())";
        $params[] = $userId;
        $params[] = $type;
        $params[] = $title;
        $params[] = $message;
    }
    
    $sql = "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES " . implode(', ', $values);
    dbExecute($sql, $params);
}
