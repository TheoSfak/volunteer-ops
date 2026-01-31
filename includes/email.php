<?php
/**
 * VolunteerOps - Email Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Get SMTP settings from database
 */
function getSmtpSettings(): array {
    $settings = [];
    $rows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    return [
        'host' => $settings['smtp_host'] ?? '',
        'port' => (int)($settings['smtp_port'] ?? 587),
        'username' => $settings['smtp_username'] ?? '',
        'password' => $settings['smtp_password'] ?? '',
        'encryption' => $settings['smtp_encryption'] ?? 'tls',
        'from_email' => $settings['smtp_from_email'] ?? '',
        'from_name' => $settings['smtp_from_name'] ?? 'VolunteerOps',
    ];
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
    return dbFetchOne("SELECT ns.*, et.code as template_code FROM notification_settings ns 
                       LEFT JOIN email_templates et ON ns.email_template_id = et.id 
                       WHERE ns.code = ?", [$code]);
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
 * Send email using SMTP
 */
function sendEmail(string $to, string $subject, string $htmlBody, ?string $fromName = null): array {
    $smtp = getSmtpSettings();
    
    if (empty($smtp['host']) || empty($smtp['from_email'])) {
        return ['success' => false, 'message' => 'Οι ρυθμίσεις SMTP δεν έχουν οριστεί'];
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
        return sendSmtpEmail($to, $subject, $htmlBody, $smtp);
    }
    
    // Fallback to PHP mail() - usually won't work without proper server config
    $success = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
    
    return [
        'success' => $success,
        'message' => $success ? 'Το email στάλθηκε' : 'Αποτυχία αποστολής'
    ];
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
        
        // SSL context for TLS/SSL
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
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
        
        // Build message
        $boundary = md5(uniqid(time()));
        $message = "Date: " . date('r') . "\r\n";
        $message .= "From: {$smtp['from_name']} <{$smtp['from_email']}>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($htmlBody));
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
 */
function sendNotificationEmail(string $notificationCode, string $to, array $variables = []): array {
    // Check if notification is enabled
    $setting = getNotificationSetting($notificationCode);
    if (!$setting || !$setting['email_enabled']) {
        return ['success' => false, 'message' => 'Η ειδοποίηση δεν είναι ενεργοποιημένη'];
    }
    
    // Get template
    $template = getEmailTemplate($setting['template_code'] ?? $notificationCode);
    if (!$template) {
        return ['success' => false, 'message' => 'Δεν βρέθηκε το template'];
    }
    
    // Add default variables
    $appName = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'app_name'") ?? 'VolunteerOps';
    $variables['app_name'] = $appName;
    $variables['login_url'] = rtrim(BASE_URL ?? 'http://localhost/volunteerops', '/') . '/login.php';
    
    // Replace variables
    $subject = replaceTemplateVariables($template['subject'], $variables);
    $body = replaceTemplateVariables($template['body_html'], $variables);
    
    return sendEmail($to, $subject, $body);
}

/**
 * Simple notification wrapper - creates a notification record in database
 */
function sendNotification(int $userId, string $title, string $message, string $type = 'info'): void {
    dbInsert(
        "INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$userId, $type, $title, $message]
    );
}
