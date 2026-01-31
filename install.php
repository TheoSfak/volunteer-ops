<?php
/**
 * VolunteerOps - Web Installer
 * Comprehensive installation wizard with debug & demo data options
 */

// ============================================================
// FULL DEBUG MODE - Show ALL errors
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/install_errors.log');

// Custom error handler for detailed output
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Track all operations for debug output
$debugLog = [];
function logDebug($action, $status = 'info', $details = '') {
    global $debugLog;
    $debugLog[] = [
        'time' => date('H:i:s'),
        'action' => $action,
        'status' => $status,
        'details' => $details
    ];
}

session_start();

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$errorDetails = '';
$success = '';

// Check if already installed
if (file_exists(__DIR__ . '/config.local.php') && $step < 5) {
    $step = 5; // Go to completion
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($step) {
            case 2: // Database configuration
                logDebug('ÎžÎµÎºÎ¹Î½Î¬ÎµÎ¹ ÏÏÎ¸Î¼Î¹ÏƒÎ· Î²Î¬ÏƒÎ·Ï‚ Î´ÎµÎ´Î¿Î¼Î­Î½Ï‰Î½');
                
                $dbHost = trim($_POST['db_host'] ?? 'localhost');
                $dbPort = trim($_POST['db_port'] ?? '3306');
                $dbName = trim($_POST['db_name'] ?? '');
                $dbUser = trim($_POST['db_user'] ?? '');
                $dbPass = $_POST['db_pass'] ?? '';
                
                logDebug("Î Î±ÏÎ¬Î¼ÎµÏ„ÏÎ¿Î¹: Host={$dbHost}, Port={$dbPort}, DB={$dbName}, User={$dbUser}");
                
                // Test connection
                try {
                    $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                    logDebug("DSN: {$dsn}");
                    
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]);
                    logDebug('Î£ÏÎ½Î´ÎµÏƒÎ· Î¼Îµ MySQL ÎµÏ€Î¹Ï„Ï…Ï‡Î®Ï‚', 'success');
                    
                    // Get MySQL version
                    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
                    logDebug("MySQL Version: {$version}", 'info');
                    
                    // Create database if not exists
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    logDebug("Database '{$dbName}' Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎµ/Ï…Ï€Î¬ÏÏ‡ÎµÎ¹", 'success');
                    
                    $pdo->exec("USE `{$dbName}`");
                    
                    // Check if schema.sql exists
                    $schemaFile = __DIR__ . '/sql/schema.sql';
                    if (!file_exists($schemaFile)) {
                        throw new Exception("Î¤Î¿ Î±ÏÏ‡ÎµÎ¯Î¿ schema.sql Î´ÎµÎ½ Î²ÏÎ­Î¸Î·ÎºÎµ: {$schemaFile}");
                    }
                    logDebug('Î‘ÏÏ‡ÎµÎ¯Î¿ schema.sql Î²ÏÎ­Î¸Î·ÎºÎµ', 'success');
                    
                    // Import schema
                    $sql = file_get_contents($schemaFile);
                    $sql = preg_replace('/^--.*$/m', '', $sql); // Remove comments
                    logDebug('Î¦ÏŒÏÏ„Ï‰ÏƒÎ· schema.sql: ' . strlen($sql) . ' bytes');
                    
                    // Split and execute
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    $executed = 0;
                    $skipped = 0;
                    
                    foreach ($statements as $statement) {
                        if (!empty($statement) && $statement !== 'SET FOREIGN_KEY_CHECKS = 0' && $statement !== 'SET FOREIGN_KEY_CHECKS = 1') {
                            try {
                                $pdo->exec($statement);
                                $executed++;
                            } catch (PDOException $e) {
                                // Ignore duplicate errors
                                if (strpos($e->getMessage(), 'already exists') === false && 
                                    strpos($e->getMessage(), 'Duplicate') === false) {
                                    throw $e;
                                }
                                $skipped++;
                            }
                        }
                    }
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                    
                    logDebug("SQL ÎµÎºÏ„ÎµÎ»Î­ÏƒÏ„Î·ÎºÎµ: {$executed} statements, {$skipped} Ï€Î±ÏÎ±Î»Î®Ï†Î¸Î·ÎºÎ±Î½", 'success');
                    
                    // Verify tables exist
                    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    logDebug('Î Î¯Î½Î±ÎºÎµÏ‚ ÏƒÏ„Î· Î²Î¬ÏƒÎ·: ' . implode(', ', $tables), 'info');
                    
                    // Insert email templates using prepared statements (avoids SQL escaping issues)
                    logDebug('Î•Î¹ÏƒÎ±Î³Ï‰Î³Î® email templates...', 'info');
                    $emailTemplates = [
                        [
                            'code' => 'welcome',
                            'name' => 'ÎšÎ±Î»Ï‰ÏƒÏŒÏÎ¹ÏƒÎ¼Î±',
                            'subject' => 'ÎšÎ±Î»ÏŽÏ‚ Î®ÏÎ¸Î±Ï„Îµ ÏƒÏ„Î¿ {{app_name}}!',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #3498db; color: white; padding: 20px; text-align: center;">
        <h1>{{app_name}}</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>ÎšÎ±Î»ÏŽÏ‚ Î®ÏÎ¸Î±Ï„Îµ, {{user_name}}!</h2>
        <p>Î•Ï…Ï‡Î±ÏÎ¹ÏƒÏ„Î¿ÏÎ¼Îµ Î³Î¹Î± Ï„Î·Î½ ÎµÎ³Î³ÏÎ±Ï†Î® ÏƒÎ±Ï‚ ÏƒÏ„Î·Î½ Ï€Î»Î±Ï„Ï†ÏŒÏÎ¼Î± ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼Î¿Ï.</p>
        <p>ÎœÏ€Î¿ÏÎµÎ¯Ï„Îµ Ï„ÏŽÏÎ± Î½Î±:</p>
        <ul>
            <li>Î”ÎµÎ¯Ï„Îµ Ï„Î¹Ï‚ Î´Î¹Î±Î¸Î­ÏƒÎ¹Î¼ÎµÏ‚ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚</li>
            <li>Î”Î·Î»ÏŽÏƒÎµÏ„Îµ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎµ Î²Î¬ÏÎ´Î¹ÎµÏ‚</li>
            <li>ÎšÎµÏÎ´Î¯ÏƒÎµÏ„Îµ Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚ ÎºÎ±Î¹ ÎµÏ€Î¹Ï„ÎµÏÎ³Î¼Î±Ï„Î±</li>
        </ul>
        <p style="text-align: center; margin-top: 30px;">
            <a href="{{login_url}}" style="background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Î£ÏÎ½Î´ÎµÏƒÎ·</a>
        </p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}} - Î£ÏÏƒÏ„Î·Î¼Î± Î”Î¹Î±Ï‡ÎµÎ¯ÏÎ¹ÏƒÎ·Ï‚ Î•Î¸ÎµÎ»Î¿Î½Ï„ÏŽÎ½
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏƒÎµ Î½Î­Î¿Ï…Ï‚ Ï‡ÏÎ®ÏƒÏ„ÎµÏ‚ Î¼ÎµÏ„Î¬ Ï„Î·Î½ ÎµÎ³Î³ÏÎ±Ï†Î®',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}'
                        ],
                        [
                            'code' => 'participation_approved',
                            'name' => 'ÎˆÎ³ÎºÏÎ¹ÏƒÎ· Î£Ï…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚',
                            'subject' => 'Î— ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎ±Ï‚ ÎµÎ³ÎºÏÎ¯Î¸Î·ÎºÎµ - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>âœ“ Î•Î³ÎºÏÎ¯Î¸Î·ÎºÎµ!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>Î— ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎ±Ï‚ ÏƒÏ„Î· Î²Î¬ÏÎ´Î¹Î± ÎµÎ³ÎºÏÎ¯Î¸Î·ÎºÎµ!</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®:</strong> {{mission_title}}</p>
            <p><strong>Î’Î¬ÏÎ´Î¹Î±:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Î¤Î¿Ï€Î¿Î¸ÎµÏƒÎ¯Î±:</strong> {{location}}</p>
        </div>
        <p>Î Î±ÏÎ±ÎºÎ±Î»Î¿ÏÎ¼Îµ Î½Î± ÎµÎ¯ÏƒÏ„Îµ ÏƒÏ„Î·Î½ Ï„Î¿Ï€Î¿Î¸ÎµÏƒÎ¯Î± Î­Î³ÎºÎ±Î¹ÏÎ±.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}}
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ ÎµÎ³ÎºÏÎ¯Î½ÎµÏ„Î±Î¹ Î· ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î® ÏƒÎµ Î²Î¬ÏÎ´Î¹Î±',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
                        ],
                        [
                            'code' => 'participation_rejected',
                            'name' => 'Î‘Ï€ÏŒÏÏÎ¹ÏˆÎ· Î£Ï…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚',
                            'subject' => 'Î— ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎ±Ï‚ Î´ÎµÎ½ ÎµÎ³ÎºÏÎ¯Î¸Î·ÎºÎµ - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>Î‘Ï€ÏŒÏÏÎ¹ÏˆÎ· Î‘Î¯Ï„Î·ÏƒÎ·Ï‚</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>Î›Ï…Ï€Î¿ÏÎ¼Î±ÏƒÏ„Îµ, Î±Î»Î»Î¬ Î· Î±Î¯Ï„Î·ÏƒÎ® ÏƒÎ±Ï‚ Î³Î¹Î± Ï„Î· Î²Î¬ÏÎ´Î¹Î± Î´ÎµÎ½ ÎµÎ³ÎºÏÎ¯Î¸Î·ÎºÎµ.</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®:</strong> {{mission_title}}</p>
            <p><strong>Î’Î¬ÏÎ´Î¹Î±:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Î›ÏŒÎ³Î¿Ï‚:</strong> {{rejection_reason}}</p>
        </div>
        <p>ÎœÏ€Î¿ÏÎµÎ¯Ï„Îµ Î½Î± Î´Î·Î»ÏŽÏƒÎµÏ„Îµ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎµ Î¬Î»Î»ÎµÏ‚ Î´Î¹Î±Î¸Î­ÏƒÎ¹Î¼ÎµÏ‚ Î²Î¬ÏÎ´Î¹ÎµÏ‚.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}}
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ Î±Ï€Î¿ÏÏÎ¯Ï€Ï„ÎµÏ„Î±Î¹ Î· ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î®',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{rejection_reason}}'
                        ],
                        [
                            'code' => 'new_mission',
                            'name' => 'ÎÎ­Î± Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®',
                            'subject' => 'ÎÎ­Î± Î´Î¹Î±Î¸Î­ÏƒÎ¹Î¼Î· Î±Ï€Î¿ÏƒÏ„Î¿Î»Î® - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #3498db; color: white; padding: 20px; text-align: center;">
        <h1>âœ¨ ÎÎ­Î± Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>{{mission_title}}</h2>
        <p>{{mission_description}}</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î¤Î¿Ï€Î¿Î¸ÎµÏƒÎ¯Î±:</strong> {{location}}</p>
        </div>
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ Î´Î·Î¼Î¿ÏƒÎ¹ÎµÏÎµÏ„Î±Î¹ Î½Î­Î± Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®',
                            'available_variables' => '{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}'
                        ],
                        [
                            'code' => 'shift_reminder',
                            'name' => 'Î¥Ï€ÎµÎ½Î¸ÏÎ¼Î¹ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚',
                            'subject' => 'Î¥Ï€ÎµÎ½Î¸ÏÎ¼Î¹ÏƒÎ·: Î— Î²Î¬ÏÎ´Î¹Î± ÏƒÎ±Ï‚ ÎµÎ¯Î½Î±Î¹ Î±ÏÏÎ¹Î¿ - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #f39c12; color: white; padding: 20px; text-align: center;">
        <h1>â° Î¥Ï€ÎµÎ½Î¸ÏÎ¼Î¹ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>Î— Î²Î¬ÏÎ´Î¹Î± ÏƒÎ±Ï‚ ÎµÎ¯Î½Î±Î¹ <strong>Î±ÏÏÎ¹Î¿</strong>!</p>
        <div style="background: #fff3cd; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®:</strong> {{mission_title}}</p>
            <p><strong>Î’Î¬ÏÎ´Î¹Î±:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Î¤Î¿Ï€Î¿Î¸ÎµÏƒÎ¯Î±:</strong> {{location}}</p>
        </div>
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ Î¼Î¯Î± Î¼Î­ÏÎ± Ï€ÏÎ¹Î½ Î±Ï€ÏŒ Ï„Î· Î²Î¬ÏÎ´Î¹Î±',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
                        ],
                        [
                            'code' => 'mission_canceled',
                            'name' => 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®Ï‚',
                            'subject' => 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®Ï‚ - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>âš ï¸ Î‘ÎºÏÏÏ‰ÏƒÎ· Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®Ï‚</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>Î›Ï…Ï€Î¿ÏÎ¼Î±ÏƒÏ„Îµ Î½Î± ÏƒÎ±Ï‚ ÎµÎ½Î·Î¼ÎµÏÏŽÏƒÎ¿Ï…Î¼Îµ ÏŒÏ„Î¹ Î· Ï€Î±ÏÎ±ÎºÎ¬Ï„Ï‰ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î® Î±ÎºÏ…ÏÏŽÎ¸Î·ÎºÎµ:</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®:</strong> {{mission_title}}</p>
            <p><strong>Î›ÏŒÎ³Î¿Ï‚:</strong> {{cancellation_reason}}</p>
        </div>
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ Î±ÎºÏ…ÏÏŽÎ½ÎµÏ„Î±Î¹ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{cancellation_reason}}'
                        ],
                        [
                            'code' => 'shift_canceled',
                            'name' => 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚',
                            'subject' => 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î²Î¬ÏÎ´Î¹Î±Ï‚ - {{mission_title}}',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>âš ï¸ Î‘ÎºÏÏÏ‰ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>Î— Î²Î¬ÏÎ´Î¹Î± ÏƒÏ„Î·Î½ Î¿Ï€Î¿Î¯Î± ÎµÎ¯Ï‡Î±Ï„Îµ Î´Î·Î»ÏŽÏƒÎµÎ¹ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® Î±ÎºÏ…ÏÏŽÎ¸Î·ÎºÎµ:</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®:</strong> {{mission_title}}</p>
            <p><strong>Î’Î¬ÏÎ´Î¹Î±:</strong> {{shift_date}} ({{shift_time}})</p>
        </div>
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ Î±ÎºÏ…ÏÏŽÎ½ÎµÏ„Î±Î¹ Î²Î¬ÏÎ´Î¹Î±',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}'
                        ],
                        [
                            'code' => 'points_earned',
                            'name' => 'ÎšÎ­ÏÎ´Î¿Ï‚ Î ÏŒÎ½Ï„Ï‰Î½',
                            'subject' => 'ÎšÎµÏÎ´Î¯ÏƒÎ±Ï„Îµ {{points}} Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚!',
                            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>ðŸŽ‰ Î£Ï…Î³Ï‡Î±ÏÎ·Ï„Î®ÏÎ¹Î±!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Î“ÎµÎ¹Î± ÏƒÎ±Ï‚ {{user_name}},</h2>
        <p>ÎšÎµÏÎ´Î¯ÏƒÎ±Ï„Îµ <strong>{{points}} Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚</strong>!</p>
        <div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
            <p style="font-size: 48px; margin: 0;">{{points}}</p>
            <p style="font-size: 18px; margin: 5px 0;">Î ÏŒÎ½Ï„Î¿Î¹</p>
        </div>
        <p><strong>Î£ÏÎ½Î¿Î»Î¿ Ï€ÏŒÎ½Ï„Ï‰Î½:</strong> {{total_points}}</p>
    </div>
</div>',
                            'description' => 'Î‘Ï€Î¿ÏƒÏ„Î­Î»Î»ÎµÏ„Î±Î¹ ÏŒÏ„Î±Î½ Î¿ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î®Ï‚ ÎºÎµÏÎ´Î¯Î¶ÎµÎ¹ Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚',
                            'available_variables' => '{{app_name}}, {{user_name}}, {{points}}, {{total_points}}'
                        ]
                    ];
                    
                    $stmt = $pdo->prepare("INSERT INTO email_templates (code, name, subject, body_html, description, available_variables) 
                                          VALUES (?, ?, ?, ?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE name=VALUES(name), subject=VALUES(subject), body_html=VALUES(body_html)");
                    foreach ($emailTemplates as $template) {
                        $stmt->execute([
                            $template['code'],
                            $template['name'],
                            $template['subject'],
                            $template['body_html'],
                            $template['description'],
                            $template['available_variables']
                        ]);
                    }
                    logDebug('Email templates ÎµÎ¹ÏƒÎ®Ï‡Î¸Î·ÏƒÎ±Î½ ÎµÏ€Î¹Ï„Ï…Ï‡ÏŽÏ‚: ' . count($emailTemplates), 'success');
                    
                    // Link notification settings to email templates
                    logDebug('Î£ÏÎ½Î´ÎµÏƒÎ· notification settings Î¼Îµ email templates...', 'info');
                    $templateLinks = [
                        'welcome' => 'welcome',
                        'participation_approved' => 'participation_approved',
                        'participation_rejected' => 'participation_rejected',
                        'new_mission' => 'new_mission',
                        'shift_reminder' => 'shift_reminder',
                        'mission_canceled' => 'mission_canceled',
                        'shift_canceled' => 'shift_canceled',
                        'points_earned' => 'points_earned'
                    ];
                    foreach ($templateLinks as $notifCode => $templateCode) {
                        $pdo->exec("UPDATE notification_settings ns 
                                    SET email_template_id = (SELECT id FROM email_templates WHERE code = '$templateCode')
                                    WHERE ns.code = '$notifCode'");
                    }
                    logDebug('Notification settings ÏƒÏ…Î½Î´Î­Î¸Î·ÎºÎ±Î½ Î¼Îµ templates', 'success');
                    
                    // Store in session for next step
                    $_SESSION['db'] = [
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'name' => $dbName,
                        'user' => $dbUser,
                        'pass' => $dbPass
                    ];
                    $_SESSION['debug_log'] = $debugLog;
                    
                    header('Location: install.php?step=3');
                    exit;
                    
                } catch (PDOException $e) {
                    logDebug('PDO Error: ' . $e->getMessage(), 'error');
                    $error = 'Î£Ï†Î¬Î»Î¼Î± ÏƒÏÎ½Î´ÎµÏƒÎ·Ï‚ Î²Î¬ÏƒÎ·Ï‚ Î´ÎµÎ´Î¿Î¼Î­Î½Ï‰Î½';
                    $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                }
                break;
                
            case 3: // Admin setup
                logDebug('ÎžÎµÎºÎ¹Î½Î¬ÎµÎ¹ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± Î´Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®');
                
                $adminName = trim($_POST['admin_name'] ?? '');
                $adminEmail = trim($_POST['admin_email'] ?? '');
                $adminPass = $_POST['admin_pass'] ?? '';
                $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
                
                logDebug("Admin: {$adminName} <{$adminEmail}>");
                
                if (empty($adminName) || empty($adminEmail) || empty($adminPass)) {
                    $error = 'ÎŒÎ»Î± Ï„Î± Ï€ÎµÎ´Î¯Î± ÎµÎ¯Î½Î±Î¹ Ï…Ï€Î¿Ï‡ÏÎµÏ‰Ï„Î¹ÎºÎ¬.';
                    logDebug($error, 'error');
                } elseif ($adminPass !== $adminPassConfirm) {
                    $error = 'ÎŸÎ¹ ÎºÏ‰Î´Î¹ÎºÎ¿Î¯ Î´ÎµÎ½ Ï„Î±Î¹ÏÎ¹Î¬Î¶Î¿Ï…Î½.';
                    logDebug($error, 'error');
                } elseif (strlen($adminPass) < 6) {
                    $error = 'ÎŸ ÎºÏ‰Î´Î¹ÎºÏŒÏ‚ Ï€ÏÎ­Ï€ÎµÎ¹ Î½Î± Î­Ï‡ÎµÎ¹ Ï„Î¿Ï…Î»Î¬Ï‡Î¹ÏƒÏ„Î¿Î½ 6 Ï‡Î±ÏÎ±ÎºÏ„Î®ÏÎµÏ‚.';
                    logDebug($error, 'error');
                } else {
                    $db = $_SESSION['db'];
                    
                    try {
                        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                        ]);
                        logDebug('Î£ÏÎ½Î´ÎµÏƒÎ· Î¼Îµ Î²Î¬ÏƒÎ· ÎµÏ€Î¹Ï„Ï…Ï‡Î®Ï‚', 'success');
                        
                        // Update or insert admin user
                        $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE role = 'SYSTEM_ADMIN' LIMIT 1");
                        $stmt->execute([$adminName, $adminEmail, $hashedPass]);
                        
                        if ($stmt->rowCount() === 0) {
                            // Insert new admin
                            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 'SYSTEM_ADMIN', 1, NOW(), NOW())");
                            $stmt->execute([$adminName, $adminEmail, $hashedPass]);
                            logDebug('ÎÎ­Î¿Ï‚ Î´Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®Ï‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎµ', 'success');
                        } else {
                            logDebug('Î”Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®Ï‚ ÎµÎ½Î·Î¼ÎµÏÏŽÎ¸Î·ÎºÎµ', 'success');
                        }
                        
                        $_SESSION['debug_log'] = array_merge($_SESSION['debug_log'] ?? [], $debugLog);
                        
                        header('Location: install.php?step=4');
                        exit;
                        
                    } catch (PDOException $e) {
                        logDebug('PDO Error: ' . $e->getMessage(), 'error');
                        $error = 'Î£Ï†Î¬Î»Î¼Î± Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î±Ï‚ Î´Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®';
                        $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                    }
                }
                break;
                
            case 4: // Demo data & finalize
                logDebug('ÎžÎµÎºÎ¹Î½Î¬ÎµÎ¹ ÏÏÎ¸Î¼Î¹ÏƒÎ· demo data ÎºÎ±Î¹ config');
                
                $installDemoData = isset($_POST['install_demo_data']);
                $debugMode = isset($_POST['debug_mode']);
                
                $db = $_SESSION['db'];
                
                try {
                    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    
                    if ($installDemoData) {
                        logDebug('Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ· demo data...', 'info');
                        installDemoData($pdo);
                        logDebug('Demo data ÎµÎ³ÎºÎ±Ï„Î±ÏƒÏ„Î¬Î¸Î·ÎºÎµ ÎµÏ€Î¹Ï„Ï…Ï‡ÏŽÏ‚', 'success');
                    }
                    
                    // Create config.local.php
                    $configContent = "<?php\n";
                    $configContent .= "/**\n";
                    $configContent .= " * VolunteerOps - Local Configuration\n";
                    $configContent .= " * Generated: " . date('Y-m-d H:i:s') . "\n";
                    $configContent .= " * DO NOT COMMIT TO VERSION CONTROL\n";
                    $configContent .= " */\n\n";
                    $configContent .= "// Database Settings\n";
                    $configContent .= "define('DB_HOST', " . var_export($db['host'], true) . ");\n";
                    $configContent .= "define('DB_PORT', " . var_export($db['port'], true) . ");\n";
                    $configContent .= "define('DB_NAME', " . var_export($db['name'], true) . ");\n";
                    $configContent .= "define('DB_USER', " . var_export($db['user'], true) . ");\n";
                    $configContent .= "define('DB_PASS', " . var_export($db['pass'], true) . ");\n\n";
                    $configContent .= "// Debug Mode - Set to false in production!\n";
                    $configContent .= "define('DEBUG_MODE', " . ($debugMode ? 'true' : 'false') . ");\n\n";
                    $configContent .= "// Error display (controlled by DEBUG_MODE)\n";
                    $configContent .= "if (DEBUG_MODE) {\n";
                    $configContent .= "    error_reporting(E_ALL);\n";
                    $configContent .= "    ini_set('display_errors', 1);\n";
                    $configContent .= "    ini_set('display_startup_errors', 1);\n";
                    $configContent .= "} else {\n";
                    $configContent .= "    error_reporting(0);\n";
                    $configContent .= "    ini_set('display_errors', 0);\n";
                    $configContent .= "}\n";
                    
                    $configPath = __DIR__ . '/config.local.php';
                    if (file_put_contents($configPath, $configContent) === false) {
                        throw new Exception("Î‘Î´Ï…Î½Î±Î¼Î¯Î± ÎµÎ³Î³ÏÎ±Ï†Î®Ï‚ ÏƒÏ„Î¿ {$configPath}");
                    }
                    logDebug('config.local.php Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎµ', 'success');
                    
                    // Store summary in session
                    $_SESSION['install_summary'] = [
                        'demo_data' => $installDemoData,
                        'debug_mode' => $debugMode
                    ];
                    $_SESSION['debug_log'] = array_merge($_SESSION['debug_log'] ?? [], $debugLog);
                    
                    header('Location: install.php?step=5');
                    exit;
                    
                } catch (Exception $e) {
                    logDebug('Error: ' . $e->getMessage(), 'error');
                    $error = 'Î£Ï†Î¬Î»Î¼Î± Î¿Î»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎ·Ï‚ ÎµÎ³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ·Ï‚';
                    $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                }
                break;
        }
    } catch (Throwable $e) {
        logDebug('Critical Error: ' . $e->getMessage(), 'error');
        $error = 'ÎšÏÎ¯ÏƒÎ¹Î¼Î¿ ÏƒÏ†Î¬Î»Î¼Î±';
        $errorDetails = get_class($e) . ": " . $e->getMessage() . "\n\nFile: " . $e->getFile() . ":" . $e->getLine() . "\n\nTrace:\n" . $e->getTraceAsString();
    }
}

/**
 * Install demo data - volunteers, departments, missions, shifts
 */
function installDemoData(PDO $pdo) {
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo departments...');
    
    // Demo Departments
    $departments = [
        ['name' => 'Î™Î±Ï„ÏÎ¹ÎºÎ® Î¥Ï€Î¿ÏƒÏ„Î®ÏÎ¹Î¾Î·', 'description' => 'Î Î±ÏÎ¿Ï‡Î® Ï€ÏÏŽÏ„Ï‰Î½ Î²Î¿Î·Î¸ÎµÎ¹ÏŽÎ½ ÎºÎ±Î¹ Î¹Î±Ï„ÏÎ¹ÎºÎ®Ï‚ Ï†ÏÎ¿Î½Ï„Î¯Î´Î±Ï‚'],
        ['name' => 'Î”Î¹Î±Î½Î¿Î¼Î® Î¤ÏÎ¿Ï†Î¯Î¼Ï‰Î½', 'description' => 'Î£Ï…Î»Î»Î¿Î³Î® ÎºÎ±Î¹ Î´Î¹Î±Î½Î¿Î¼Î® Ï„ÏÎ¿Ï†Î¯Î¼Ï‰Î½ ÏƒÎµ ÎµÏ…Ï€Î±Î¸ÎµÎ¯Ï‚ Î¿Î¼Î¬Î´ÎµÏ‚'],
        ['name' => 'Î•ÎºÏ€Î±Î¯Î´ÎµÏ…ÏƒÎ·', 'description' => 'Î•ÎºÏ€Î±Î¹Î´ÎµÏ…Ï„Î¹ÎºÎ¬ Ï€ÏÎ¿Î³ÏÎ¬Î¼Î¼Î±Ï„Î± ÎºÎ±Î¹ Ï…Ï€Î¿ÏƒÏ„Î®ÏÎ¹Î¾Î· Î¼Î±Î¸Î·Ï„ÏŽÎ½'],
        ['name' => 'Î ÎµÏÎ¹Î²Î¬Î»Î»Î¿Î½', 'description' => 'ÎšÎ±Î¸Î±ÏÎ¹ÏƒÎ¼Î¿Î¯, Î´ÎµÎ½Î´ÏÎ¿Ï†Ï…Ï„ÎµÏÏƒÎµÎ¹Ï‚ ÎºÎ±Î¹ Ï€ÎµÏÎ¹Î²Î±Î»Î»Î¿Î½Ï„Î¹ÎºÎ­Ï‚ Î´ÏÎ¬ÏƒÎµÎ¹Ï‚'],
        ['name' => 'ÎšÎ¿Î¹Î½Ï‰Î½Î¹ÎºÎ® ÎœÎ­ÏÎ¹Î¼Î½Î±', 'description' => 'Î¥Ï€Î¿ÏƒÏ„Î®ÏÎ¹Î¾Î· Î·Î»Î¹ÎºÎ¹Ï‰Î¼Î­Î½Ï‰Î½ ÎºÎ±Î¹ Î±Ï„ÏŒÎ¼Ï‰Î½ Î¼Îµ Î±Î½Î±Ï€Î·ÏÎ¯Î±'],
    ];
    
    $deptIds = [];
    $stmt = $pdo->prepare("INSERT INTO departments (name, description, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    foreach ($departments as $dept) {
        $stmt->execute([$dept['name'], $dept['description']]);
        $deptIds[] = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM departments WHERE name = " . $pdo->quote($dept['name']))->fetchColumn();
    }
    logDebug(count($departments) . ' Ï„Î¼Î®Î¼Î±Ï„Î± Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎ±Î½', 'success');
    
    // Demo Volunteers
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo ÎµÎ¸ÎµÎ»Î¿Î½Ï„ÏŽÎ½...');
    $volunteers = [
        ['name' => 'ÎœÎ±ÏÎ¯Î± Î Î±Ï€Î±Î´Î¿Ï€Î¿ÏÎ»Î¿Ï…', 'email' => 'maria.p@example.gr', 'phone' => '6971234567'],
        ['name' => 'Î“Î¹ÏŽÏÎ³Î¿Ï‚ ÎÎ¹ÎºÎ¿Î»Î¬Î¿Ï…', 'email' => 'giorgos.n@example.gr', 'phone' => '6972345678'],
        ['name' => 'Î•Î»Î­Î½Î· ÎšÏ‰Î½ÏƒÏ„Î±Î½Ï„Î¯Î½Î¿Ï…', 'email' => 'eleni.k@example.gr', 'phone' => '6973456789'],
        ['name' => 'Î”Î·Î¼Î®Ï„ÏÎ·Ï‚ Î‘Î»ÎµÎ¾Î¯Î¿Ï…', 'email' => 'dimitris.a@example.gr', 'phone' => '6974567890'],
        ['name' => 'Î‘Î½Î±ÏƒÏ„Î±ÏƒÎ¯Î± Î“ÎµÏ‰ÏÎ³Î¯Î¿Ï…', 'email' => 'anastasia.g@example.gr', 'phone' => '6975678901'],
        ['name' => 'ÎšÏŽÏƒÏ„Î±Ï‚ Î™Ï‰Î¬Î½Î½Î¿Ï…', 'email' => 'kostas.i@example.gr', 'phone' => '6976789012'],
        ['name' => 'Î£Î¿Ï†Î¯Î± Î”Î·Î¼Î·Ï„ÏÎ¯Î¿Ï…', 'email' => 'sofia.d@example.gr', 'phone' => '6977890123'],
        ['name' => 'ÎÎ¯ÎºÎ¿Ï‚ Î Î±Î½Î±Î³Î¹ÏŽÏ„Î¿Ï…', 'email' => 'nikos.p@example.gr', 'phone' => '6978901234'],
    ];
    
    $volunteerIds = [];
    $hashedPass = password_hash('demo123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 'VOLUNTEER', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    foreach ($volunteers as $vol) {
        $stmt->execute([$vol['name'], $vol['email'], $vol['phone'], $hashedPass]);
        $volunteerIds[] = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($vol['email']))->fetchColumn();
    }
    logDebug(count($volunteers) . ' ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î­Ï‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎ±Î½ (ÎºÏ‰Î´Î¹ÎºÏŒÏ‚: demo123)', 'success');
    
    // Demo Shift Leader
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 'SHIFT_LEADER', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->execute(['Î Î­Ï„ÏÎ¿Ï‚ Î‘ÏÏ‡Î·Î³ÏŒÏ‚', 'leader@example.gr', '6979012345', $hashedPass]);
    $leaderId = $pdo->lastInsertId();
    logDebug('Î¥Ï€ÎµÏÎ¸Ï…Î½Î¿Ï‚ Î²Î¬ÏÎ´Î¹Î±Ï‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎµ', 'success');
    
    // Demo Missions
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo Î±Ï€Î¿ÏƒÏ„Î¿Î»ÏŽÎ½...');
    $missions = [
        [
            'title' => 'Î”Î¹Î±Î½Î¿Î¼Î® Î¤ÏÎ¿Ï†Î¯Î¼Ï‰Î½ - ÎšÎ­Î½Ï„ÏÎ¿ Î ÏŒÎ»Î·Ï‚',
            'description' => 'Î”Î¹Î±Î½Î¿Î¼Î® Ï„ÏÎ¿Ï†Î¯Î¼Ï‰Î½ ÏƒÎµ Î¿Î¹ÎºÎ¿Î³Î­Î½ÎµÎ¹ÎµÏ‚ Ï€Î¿Ï… Î­Ï‡Î¿Ï…Î½ Î±Î½Î¬Î³ÎºÎ· ÏƒÏ„Î¿ ÎºÎ­Î½Ï„ÏÎ¿ Ï„Î·Ï‚ Ï€ÏŒÎ»Î·Ï‚. Î˜Î± Ï‡ÏÎµÎ¹Î±ÏƒÏ„Î¿ÏÎ¼Îµ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î­Ï‚ Î³Î¹Î± Ï„Î· Î¼ÎµÏ„Î±Ï†Î¿ÏÎ¬ ÎºÎ±Î¹ Î´Î¹Î±Î½Î¿Î¼Î®.',
            'location' => 'Î Î»Î±Ï„ÎµÎ¯Î± Î£Ï…Î½Ï„Î¬Î³Î¼Î±Ï„Î¿Ï‚, Î‘Î¸Î®Î½Î±',
            'department_id' => $deptIds[1] ?? 1,
            'status' => 'OPEN',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('+3 days 09:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('+3 days 14:00')),
        ],
        [
            'title' => 'ÎšÎ±Î¸Î±ÏÎ¹ÏƒÎ¼ÏŒÏ‚ Î Î±ÏÎ±Î»Î¯Î±Ï‚',
            'description' => 'Î•Î¸ÎµÎ»Î¿Î½Ï„Î¹ÎºÏŒÏ‚ ÎºÎ±Î¸Î±ÏÎ¹ÏƒÎ¼ÏŒÏ‚ Ï„Î·Ï‚ Ï€Î±ÏÎ±Î»Î¯Î±Ï‚ Î±Ï€ÏŒ Ï€Î»Î±ÏƒÏ„Î¹ÎºÎ¬ ÎºÎ±Î¹ ÏƒÎºÎ¿Ï…Ï€Î¯Î´Î¹Î±. Î Î±ÏÎ­Ï‡Î¿Î½Ï„Î±Î¹ Î³Î¬Î½Ï„Î¹Î± ÎºÎ±Î¹ ÏƒÎ±ÎºÎ¿ÏÎ»ÎµÏ‚.',
            'location' => 'Î Î±ÏÎ±Î»Î¯Î± Î“Î»Ï…Ï†Î¬Î´Î±Ï‚',
            'department_id' => $deptIds[3] ?? 1,
            'status' => 'OPEN',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('+5 days 08:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('+5 days 13:00')),
        ],
        [
            'title' => 'Î¥Ï€Î¿ÏƒÏ„Î®ÏÎ¹Î¾Î· ÎœÎ±Î¸Î·Ï„ÏŽÎ½ - ÎšÎ­Î½Ï„ÏÎ¿ ÎœÎµÎ»Î­Ï„Î·Ï‚',
            'description' => 'Î’Î¿Î®Î¸ÎµÎ¹Î± ÏƒÎµ Î¼Î±Î¸Î·Ï„Î­Ï‚ Î“Ï…Î¼Î½Î±ÏƒÎ¯Î¿Ï… Î¼Îµ Ï„Î± Î¼Î±Î¸Î®Î¼Î±Ï„Î¬ Ï„Î¿Ï…Ï‚. Î§ÏÎµÎ¹Î±Î¶ÏŒÎ¼Î±ÏƒÏ„Îµ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î­Ï‚ Î¼Îµ Î³Î½ÏŽÏƒÎµÎ¹Ï‚ ÎœÎ±Î¸Î·Î¼Î±Ï„Î¹ÎºÏŽÎ½ ÎºÎ±Î¹ Î¦Ï…ÏƒÎ¹ÎºÎ®Ï‚.',
            'location' => 'Î”Î·Î¼Î¿Ï„Î¹ÎºÎ® Î’Î¹Î²Î»Î¹Î¿Î¸Î®ÎºÎ· Î‘Î¸Î·Î½ÏŽÎ½',
            'department_id' => $deptIds[2] ?? 1,
            'status' => 'DRAFT',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('+7 days 16:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('+7 days 20:00')),
        ],
        [
            'title' => 'Î™Î±Ï„ÏÎµÎ¯Î¿ Î‘ÏƒÏ„Î­Î³Ï‰Î½',
            'description' => 'Î Î±ÏÎ¿Ï‡Î® Ï€ÏÏŽÏ„Ï‰Î½ Î²Î¿Î·Î¸ÎµÎ¹ÏŽÎ½ ÎºÎ±Î¹ Î²Î±ÏƒÎ¹ÎºÎ®Ï‚ Î¹Î±Ï„ÏÎ¹ÎºÎ®Ï‚ Ï†ÏÎ¿Î½Ï„Î¯Î´Î±Ï‚ ÏƒÎµ Î±ÏƒÏ„Î­Î³Î¿Ï…Ï‚. Î§ÏÎµÎ¹Î¬Î¶ÎµÏ„Î±Î¹ Î¹Î±Ï„ÏÎ¹ÎºÏŒ/Î½Î¿ÏƒÎ·Î»ÎµÏ…Ï„Î¹ÎºÏŒ Ï€ÏÎ¿ÏƒÏ‰Ï€Î¹ÎºÏŒ.',
            'location' => 'ÎšÎ­Î½Ï„ÏÎ¿ Î‘ÏƒÏ„Î­Î³Ï‰Î½, Î ÎµÎ¹ÏÎ±Î¹Î¬Ï‚',
            'department_id' => $deptIds[0] ?? 1,
            'status' => 'OPEN',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('+2 days 18:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('+2 days 22:00')),
        ],
        [
            'title' => 'Î•Ï€Î¯ÏƒÎºÎµÏˆÎ· ÏƒÎµ Î“Î·ÏÎ¿ÎºÎ¿Î¼ÎµÎ¯Î¿',
            'description' => 'Î£Ï…Î½Ï„ÏÎ¿Ï†Î¹Î¬ ÎºÎ±Î¹ ÏˆÏ…Ï‡Î±Î³Ï‰Î³Î¯Î± Î·Î»Î¹ÎºÎ¹Ï‰Î¼Î­Î½Ï‰Î½. Î ÎµÏÎ¹Î»Î±Î¼Î²Î¬Î½ÎµÎ¹ ÎµÏ€Î¹Ï„ÏÎ±Ï€Î­Î¶Î¹Î± Ï€Î±Î¹Ï‡Î½Î¯Î´Î¹Î±, Î±Î½Î¬Î³Î½Ï‰ÏƒÎ· ÎºÎ±Î¹ ÏƒÏ…Î¶Î®Ï„Î·ÏƒÎ·.',
            'location' => 'Î“Î·ÏÎ¿ÎºÎ¿Î¼ÎµÎ¯Î¿ Î‘Î³Î¯Î± Î•Î¹ÏÎ®Î½Î·, ÎÎ­Î± Î£Î¼ÏÏÎ½Î·',
            'department_id' => $deptIds[4] ?? 1,
            'status' => 'OPEN',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('+4 days 10:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('+4 days 13:00')),
        ],
        [
            'title' => 'Î”ÎµÎ½Î´ÏÎ¿Ï†ÏÏ„ÎµÏ…ÏƒÎ· Î Î¬ÏÎºÎ¿Ï…',
            'description' => 'Î¦ÏÏ„ÎµÏ…ÏƒÎ· 100 Î½Î­Ï‰Î½ Î´Î­Î½Ï„ÏÏ‰Î½ ÏƒÏ„Î¿ Î´Î·Î¼Î¿Ï„Î¹ÎºÏŒ Ï€Î¬ÏÎºÎ¿. Î Î±ÏÎ­Ï‡Î¿Î½Ï„Î±Î¹ ÎµÏÎ³Î±Î»ÎµÎ¯Î± ÎºÎ±Î¹ Î¿Î´Î·Î³Î¯ÎµÏ‚.',
            'location' => 'Î Î¬ÏÎºÎ¿ Î¤ÏÎ¯Ï„ÏƒÎ·, ÎŠÎ»Î¹Î¿Î½',
            'department_id' => $deptIds[3] ?? 1,
            'status' => 'COMPLETED',
            'start_datetime' => date('Y-m-d H:i:s', strtotime('-10 days 09:00')),
            'end_datetime' => date('Y-m-d H:i:s', strtotime('-10 days 15:00')),
        ],
    ];
    
    $missionIds = [];
    $stmt = $pdo->prepare("INSERT INTO missions (title, description, location, department_id, status, start_datetime, end_datetime, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
    foreach ($missions as $mission) {
        $stmt->execute([
            $mission['title'],
            $mission['description'],
            $mission['location'],
            $mission['department_id'],
            $mission['status'],
            $mission['start_datetime'],
            $mission['end_datetime']
        ]);
        $missionIds[] = $pdo->lastInsertId();
    }
    logDebug(count($missions) . ' Î±Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎ±Î½', 'success');
    
    // Demo Shifts
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo Î²Î±ÏÎ´Î¹ÏŽÎ½...');
    $shiftCount = 0;
    $shiftIds = [];
    foreach ($missionIds as $idx => $missionId) {
        // 1-2 shifts per mission
        $shiftsForMission = rand(1, 2);
        for ($i = 0; $i < $shiftsForMission; $i++) {
            $startHour = 9 + ($i * 4);
            $stmt = $pdo->prepare("INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([
                $missionId,
                date('Y-m-d', strtotime("+$idx days")) . " {$startHour}:00:00",
                date('Y-m-d', strtotime("+$idx days")) . " " . ($startHour + 4) . ":00:00",
                rand(5, 15),
                rand(2, 4),
                $i === 0 ? 'Î ÏÏ‰Î¹Î½Î® Î²Î¬ÏÎ´Î¹Î±' : 'Î‘Ï€Î¿Î³ÎµÏ…Î¼Î±Ï„Î¹Î½Î® Î²Î¬ÏÎ´Î¹Î±'
            ]);
            $shiftIds[] = $pdo->lastInsertId();
            $shiftCount++;
        }
    }
    logDebug("{$shiftCount} Î²Î¬ÏÎ´Î¹ÎµÏ‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎ±Î½", 'success');
    
    // Demo Participation Requests
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo Î±Î¹Ï„Î®ÏƒÎµÏ‰Î½ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚...');
    $participationCount = 0;
    $statuses = ['PENDING', 'APPROVED', 'APPROVED', 'APPROVED']; // More approved than pending
    
    foreach ($shiftIds as $shiftId) {
        // 2-4 volunteers per shift
        $numVolunteers = rand(2, 4);
        $selectedVolunteers = array_rand(array_flip($volunteerIds), min($numVolunteers, count($volunteerIds)));
        if (!is_array($selectedVolunteers)) $selectedVolunteers = [$selectedVolunteers];
        
        foreach ($selectedVolunteers as $volId) {
            $status = $statuses[array_rand($statuses)];
            $stmt = $pdo->prepare("INSERT INTO participation_requests (shift_id, user_id, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status = status");
            $stmt->execute([$shiftId, $volId, $status]);
            $participationCount++;
        }
    }
    logDebug("{$participationCount} Î±Î¹Ï„Î®ÏƒÎµÎ¹Ï‚ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚ Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î®Î¸Î·ÎºÎ±Î½", 'success');
    
    // Demo Points for completed mission
    logDebug('Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± demo Ï€ÏŒÎ½Ï„Ï‰Î½...');
    foreach (array_slice($volunteerIds, 0, 4) as $volId) {
        $points = rand(50, 200);
$stmt = $pdo->prepare("INSERT INTO volunteer_points (user_id, points, reason, description, pointable_type, pointable_id, created_at) VALUES (?, ?, 'shift_completion', ?, 'mission', ?, NOW())");
                $stmt->execute([$volId, $points, 'Î£Ï…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÏƒÎµ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®', $missionIds[5] ?? 1]);
        
        // Update user total points
        $pdo->exec("UPDATE users SET total_points = total_points + {$points} WHERE id = {$volId}");
    }
    logDebug('Î ÏŒÎ½Ï„Î¿Î¹ ÎµÎ¸ÎµÎ»Î¿Î½Ï„ÏŽÎ½ ÎµÎ½Î·Î¼ÎµÏÏŽÎ¸Î·ÎºÎ±Î½', 'success');
    
    logDebug('=== DEMO DATA ÎŸÎ›ÎŸÎšÎ›Î—Î¡Î©Î˜Î—ÎšÎ• ===', 'success');
}

// Restore from session log
if (!empty($_SESSION['debug_log'])) {
    $debugLog = array_merge($_SESSION['debug_log'], $debugLog);
}
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ· - VolunteerOps</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        .install-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 700px;
            width: 100%;
        }
        .install-header {
            background: linear-gradient(135deg, #27ae60 0%, #1e8449 100%);
            color: white;
            padding: 2rem;
            text-align: center;
            border-radius: 1rem 1rem 0 0;
        }
        .install-body {
            padding: 2rem;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .step {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0.25rem;
            font-weight: bold;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .step.active {
            background: #3498db;
            color: white;
        }
        .step.completed {
            background: #27ae60;
            color: white;
        }
        .step-line {
            width: 30px;
            height: 2px;
            background: #e9ecef;
            align-self: center;
        }
        .step-line.completed {
            background: #27ae60;
        }
        .debug-log {
            max-height: 200px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.8rem;
            background: #1a1a2e;
            color: #0f0;
            padding: 1rem;
            border-radius: 0.5rem;
        }
        .debug-log .error { color: #ff6b6b; }
        .debug-log .success { color: #69db7c; }
        .debug-log .info { color: #74c0fc; }
        .demo-feature {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .demo-feature-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        .error-details {
            font-family: monospace;
            font-size: 0.75rem;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="install-card">
        <div class="install-header">
            <h2><i class="bi bi-heart-pulse me-2"></i>VolunteerOps</h2>
            <p class="mb-0 opacity-75">ÎŸÎ´Î·Î³ÏŒÏ‚ Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ·Ï‚</p>
        </div>
        <div class="install-body">
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 1 ? '<i class="bi bi-check"></i>' : '1' ?>
                </div>
                <div class="step-line <?= $step > 1 ? 'completed' : '' ?>"></div>
                <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 2 ? '<i class="bi bi-check"></i>' : '2' ?>
                </div>
                <div class="step-line <?= $step > 2 ? 'completed' : '' ?>"></div>
                <div class="step <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 3 ? '<i class="bi bi-check"></i>' : '3' ?>
                </div>
                <div class="step-line <?= $step > 3 ? 'completed' : '' ?>"></div>
                <div class="step <?= $step >= 4 ? ($step > 4 ? 'completed' : 'active') : '' ?>">
                    <?= $step > 4 ? '<i class="bi bi-check"></i>' : '4' ?>
                </div>
                <div class="step-line <?= $step > 4 ? 'completed' : '' ?>"></div>
                <div class="step <?= $step >= 5 ? 'active' : '' ?>">5</div>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></strong>
                    <?php if ($errorDetails): ?>
                        <hr>
                        <div class="error-details bg-dark text-danger p-2 rounded mt-2"><?= htmlspecialchars($errorDetails) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($step === 1): ?>
                <!-- Step 1: Requirements Check -->
                <h4 class="mb-4"><i class="bi bi-gear me-2"></i>Î’Î®Î¼Î± 1: ÎˆÎ»ÎµÎ³Ï‡Î¿Ï‚ Î‘Ï€Î±Î¹Ï„Î®ÏƒÎµÏ‰Î½</h4>
                
                <?php
                $requirements = [
                    'PHP Version >= 8.0' => [version_compare(PHP_VERSION, '8.0.0', '>='), 'PHP ' . PHP_VERSION],
                    'PDO Extension' => [extension_loaded('pdo'), ''],
                    'PDO MySQL' => [extension_loaded('pdo_mysql'), ''],
                    'JSON Extension' => [extension_loaded('json'), ''],
                    'Mbstring Extension' => [extension_loaded('mbstring'), ''],
                    'OpenSSL Extension' => [extension_loaded('openssl'), ''],
                    'sql/ directory readable' => [is_readable(__DIR__ . '/sql'), __DIR__ . '/sql'],
                    'schema.sql exists' => [file_exists(__DIR__ . '/sql/schema.sql'), ''],
                    'Root directory writable' => [is_writable(__DIR__), __DIR__],
                ];
                $allPassed = true;
                ?>
                
                <ul class="list-group mb-4">
                    <?php foreach ($requirements as $name => $data): 
                        $passed = $data[0];
                        $info = $data[1];
                        if (!$passed) $allPassed = false; 
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <?= $name ?>
                                <?php if ($info): ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($info) ?></small>
                                <?php endif; ?>
                            </div>
                            <?php if ($passed): ?>
                                <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <!-- System Info -->
                <div class="alert alert-info mb-4">
                    <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Î Î»Î·ÏÎ¿Ï†Î¿ÏÎ¯ÎµÏ‚ Î£Ï…ÏƒÏ„Î®Î¼Î±Ï„Î¿Ï‚</h6>
                    <small>
                        <strong>PHP:</strong> <?= PHP_VERSION ?><br>
                        <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?><br>
                        <strong>OS:</strong> <?= PHP_OS ?><br>
                        <strong>Memory Limit:</strong> <?= ini_get('memory_limit') ?>
                    </small>
                </div>
                
                <?php if ($allPassed): ?>
                    <a href="install.php?step=2" class="btn btn-primary w-100 btn-lg">
                        Î£Ï…Î½Î­Ï‡ÎµÎ¹Î± <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Î Î±ÏÎ±ÎºÎ±Î»ÏŽ Î´Î¹Î¿ÏÎ¸ÏŽÏƒÏ„Îµ Ï„Î± Ï€Î±ÏÎ±Ï€Î¬Î½Ï‰ Ï€ÏÎ¿Î²Î»Î®Î¼Î±Ï„Î± Ï€ÏÎ¹Î½ ÏƒÏ…Î½ÎµÏ‡Î¯ÏƒÎµÏ„Îµ.
                    </div>
                    <a href="install.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise me-1"></i>Î•Ï€Î±Î½Î­Î»ÎµÎ³Ï‡Î¿Ï‚
                    </a>
                <?php endif; ?>
                
            <?php elseif ($step === 2): ?>
                <!-- Step 2: Database Configuration -->
                <h4 class="mb-4"><i class="bi bi-database me-2"></i>Î’Î®Î¼Î± 2: Î¡ÏÎ¸Î¼Î¹ÏƒÎ· Î’Î¬ÏƒÎ·Ï‚ Î”ÎµÎ´Î¿Î¼Î­Î½Ï‰Î½</h4>
                
                <form method="post">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Database Host</label>
                            <input type="text" class="form-control" name="db_host" value="localhost" required>
                            <div class="form-text">Î£Ï…Î½Î®Î¸Ï‰Ï‚: localhost Î® 127.0.0.1</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port</label>
                            <input type="text" class="form-control" name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" name="db_name" value="volunteer_ops" required>
                        <div class="form-text">Î— Î²Î¬ÏƒÎ· Î¸Î± Î´Î·Î¼Î¹Î¿Ï…ÏÎ³Î·Î¸ÎµÎ¯ Î±Ï…Ï„ÏŒÎ¼Î±Ï„Î± Î±Î½ Î´ÎµÎ½ Ï…Ï€Î¬ÏÏ‡ÎµÎ¹.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Database Username</label>
                        <input type="text" class="form-control" name="db_user" value="root" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Database Password</label>
                        <input type="password" class="form-control" name="db_pass">
                        <div class="form-text">Î‘Ï†Î®ÏƒÏ„Îµ ÎºÎµÎ½ÏŒ Î±Î½ Î´ÎµÎ½ Ï…Ï€Î¬ÏÏ‡ÎµÎ¹ ÎºÏ‰Î´Î¹ÎºÏŒÏ‚ (Ï€.Ï‡. Ï„Î¿Ï€Î¹ÎºÏŒ XAMPP).</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="install.php?step=1" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Î Î¯ÏƒÏ‰
                        </a>
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            Î£ÏÎ½Î´ÎµÏƒÎ· & Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ· Î£Ï‡Î®Î¼Î±Ï„Î¿Ï‚ <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 3): ?>
                <!-- Step 3: Admin Setup -->
                <h4 class="mb-4"><i class="bi bi-person-badge me-2"></i>Î’Î®Î¼Î± 3: Î”Î·Î¼Î¹Î¿Ï…ÏÎ³Î¯Î± Î”Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®</h4>
                
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">ÎŸÎ½Î¿Î¼Î±Ï„ÎµÏ€ÏŽÎ½Ï…Î¼Î¿</label>
                        <input type="text" class="form-control" name="admin_name" required placeholder="Ï€.Ï‡. Î“Î¹Î¬Î½Î½Î·Ï‚ Î Î±Ï€Î±Î´ÏŒÏ€Î¿Ï…Î»Î¿Ï‚">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="admin_email" required placeholder="admin@example.gr">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ÎšÏ‰Î´Î¹ÎºÏŒÏ‚</label>
                        <input type="password" class="form-control" name="admin_pass" minlength="6" required>
                        <div class="form-text">Î¤Î¿Ï…Î»Î¬Ï‡Î¹ÏƒÏ„Î¿Î½ 6 Ï‡Î±ÏÎ±ÎºÏ„Î®ÏÎµÏ‚.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Î•Ï€Î¹Î²ÎµÎ²Î±Î¯Ï‰ÏƒÎ· ÎšÏ‰Î´Î¹ÎºÎ¿Ï</label>
                        <input type="password" class="form-control" name="admin_pass_confirm" required>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="install.php?step=2" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Î Î¯ÏƒÏ‰
                        </a>
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            Î£Ï…Î½Î­Ï‡ÎµÎ¹Î± <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 4): ?>
                <!-- Step 4: Demo Data & Options -->
                <h4 class="mb-4"><i class="bi bi-sliders me-2"></i>Î’Î®Î¼Î± 4: Î¡Ï…Î¸Î¼Î¯ÏƒÎµÎ¹Ï‚ & Demo Data</h4>
                
                <form method="post">
                    <!-- Demo Data Option -->
                    <div class="demo-feature border border-primary">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="install_demo_data" id="demoData" checked>
                            <label class="form-check-label fw-bold" for="demoData">
                                <i class="bi bi-box-seam me-1"></i>Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ· Demo Data
                            </label>
                        </div>
                        <p class="text-muted small mb-3 mt-2">
                            Î ÏÎ¿ÏƒÎ¸Î­Ï„ÎµÎ¹ Î´Î¿ÎºÎ¹Î¼Î±ÏƒÏ„Î¹ÎºÎ¬ Î´ÎµÎ´Î¿Î¼Î­Î½Î± Î³Î¹Î± Î½Î± Î´ÎµÎ¯Ï„Îµ Ï€ÏŽÏ‚ Î»ÎµÎ¹Ï„Î¿Ï…ÏÎ³ÎµÎ¯ Î· ÎµÏ†Î±ÏÎ¼Î¿Î³Î®.
                        </p>
                        
                        <div class="row text-center g-2" id="demoDetails">
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-people text-primary demo-feature-icon"></i>
                                    <div class="small"><strong>8</strong> Î•Î¸ÎµÎ»Î¿Î½Ï„Î­Ï‚</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-flag text-success demo-feature-icon"></i>
                                    <div class="small"><strong>6</strong> Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-clock text-warning demo-feature-icon"></i>
                                    <div class="small"><strong>8+</strong> Î’Î¬ÏÎ´Î¹ÎµÏ‚</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>ÎšÏ‰Î´Î¹ÎºÏŒÏ‚ demo ÎµÎ¸ÎµÎ»Î¿Î½Ï„ÏŽÎ½:</strong> demo123
                        </div>
                    </div>
                    
                    <!-- Debug Mode Option -->
                    <div class="demo-feature border border-warning">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="debug_mode" id="debugMode" checked>
                            <label class="form-check-label fw-bold" for="debugMode">
                                <i class="bi bi-bug me-1"></i>Debug Mode
                            </label>
                        </div>
                        <p class="text-muted small mb-0 mt-2">
                            Î•Î¼Ï†Î±Î½Î¯Î¶ÎµÎ¹ Î±Î½Î±Î»Ï…Ï„Î¹ÎºÎ¬ Î¼Î·Î½ÏÎ¼Î±Ï„Î± ÏƒÏ†Î±Î»Î¼Î¬Ï„Ï‰Î½. <strong class="text-danger">Î‘Ï€ÎµÎ½ÎµÏÎ³Î¿Ï€Î¿Î¹Î®ÏƒÏ„Îµ Ï„Î¿ ÏƒÎµ Ï€Î±ÏÎ±Î³Ï‰Î³Î®!</strong>
                        </p>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <a href="install.php?step=3" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Î Î¯ÏƒÏ‰
                        </a>
                        <button type="submit" class="btn btn-success flex-grow-1 btn-lg">
                            <i class="bi bi-check-circle me-1"></i>ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎ· Î•Î³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ·Ï‚
                        </button>
                    </div>
                </form>
                
                <script>
                document.getElementById('demoData').addEventListener('change', function() {
                    document.getElementById('demoDetails').style.opacity = this.checked ? '1' : '0.4';
                });
                </script>
                
            <?php elseif ($step === 5): ?>
                <!-- Step 5: Complete -->
                <div class="text-center">
                    <div class="mb-4">
                        <i class="bi bi-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="mb-3">Î— ÎµÎ³ÎºÎ±Ï„Î¬ÏƒÏ„Î±ÏƒÎ· Î¿Î»Î¿ÎºÎ»Î·ÏÏŽÎ¸Î·ÎºÎµ ÎµÏ€Î¹Ï„Ï…Ï‡ÏŽÏ‚!</h4>
                    
                    <?php 
                    $summary = $_SESSION['install_summary'] ?? [];
                    ?>
                    
                    <?php if (!empty($summary['demo_data'])): ?>
                        <div class="alert alert-success text-start">
                            <strong><i class="bi bi-box-seam me-1"></i>Demo Data ÎµÎ³ÎºÎ±Ï„Î±ÏƒÏ„Î¬Î¸Î·ÎºÎµ!</strong>
                            <ul class="mb-0 mt-2 small">
                                <li>8 ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î­Ï‚ (ÎºÏ‰Î´Î¹ÎºÏŒÏ‚: <code>demo123</code>)</li>
                                <li>6 Î±Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚ ÏƒÎµ Î´Î¹Î¬Ï†Î¿ÏÎµÏ‚ ÎºÎ±Ï„Î±ÏƒÏ„Î¬ÏƒÎµÎ¹Ï‚</li>
                                <li>Î’Î¬ÏÎ´Î¹ÎµÏ‚ Î¼Îµ Î±Î¹Ï„Î®ÏƒÎµÎ¹Ï‚ ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚</li>
                                <li>5 Ï„Î¼Î®Î¼Î±Ï„Î± Î¿ÏÎ³Î¬Î½Ï‰ÏƒÎ·Ï‚</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($summary['debug_mode'])): ?>
                        <div class="alert alert-warning text-start">
                            <strong><i class="bi bi-bug me-1"></i>Debug Mode ÎµÎ½ÎµÏÎ³ÏŒ</strong>
                            <p class="mb-0 small">Î¤Î± ÏƒÏ†Î¬Î»Î¼Î±Ï„Î± Î¸Î± ÎµÎ¼Ï†Î±Î½Î¯Î¶Î¿Î½Ï„Î±Î¹ Î±Î½Î±Î»Ï…Ï„Î¹ÎºÎ¬. Î‘Ï€ÎµÎ½ÎµÏÎ³Î¿Ï€Î¿Î¹Î®ÏƒÏ„Îµ Ï„Î¿ Ï€ÏÎ¹Î½ Î²Î³ÎµÎ¯Ï„Îµ ÏƒÎµ Ï€Î±ÏÎ±Î³Ï‰Î³Î®.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-danger text-start">
                        <strong><i class="bi bi-exclamation-triangle me-1"></i>Î£Î·Î¼Î±Î½Ï„Î¹ÎºÏŒ - ÎšÎ¬Î½Ï„Îµ Î±Ï…Ï„Î¬ Ï„ÏŽÏÎ±:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Î”Î¹Î±Î³ÏÎ¬ÏˆÏ„Îµ Ï„Î¿ Î±ÏÏ‡ÎµÎ¯Î¿ <code>install.php</code></strong> Î³Î¹Î± Î±ÏƒÏ†Î¬Î»ÎµÎ¹Î±</li>
                            <li>Î•Î»Î­Î³Î¾Ï„Îµ Ï„Î± Î´Î¹ÎºÎ±Î¹ÏŽÎ¼Î±Ï„Î± Ï„Î¿Ï… Ï†Î±ÎºÎ­Î»Î¿Ï… <code>uploads/</code></li>
                            <li>Î£Îµ Ï€Î±ÏÎ±Î³Ï‰Î³Î®: Î±Ï€ÎµÎ½ÎµÏÎ³Î¿Ï€Î¿Î¹Î®ÏƒÏ„Îµ DEBUG_MODE ÏƒÏ„Î¿ <code>config.local.php</code></li>
                        </ul>
                    </div>
                    
                    <a href="login.php" class="btn btn-success btn-lg">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Î£ÏÎ½Î´ÎµÏƒÎ· ÏƒÏ„Î¿ VolunteerOps
                    </a>
                    
                    <?php if (!empty($debugLog)): ?>
                        <div class="mt-4">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#debugLog">
                                <i class="bi bi-terminal me-1"></i>Î ÏÎ¿Î²Î¿Î»Î® Debug Log
                            </button>
                            <div class="collapse mt-3" id="debugLog">
                                <div class="debug-log text-start">
                                    <?php foreach ($debugLog as $log): ?>
                                        <div class="<?= $log['status'] ?>">
                                            [<?= $log['time'] ?>] <?= htmlspecialchars($log['action']) ?>
                                            <?php if ($log['details']): ?>
                                                <span class="opacity-75"> - <?= htmlspecialchars($log['details']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php 
                // Clear session
                unset($_SESSION['db'], $_SESSION['debug_log'], $_SESSION['install_summary']);
                ?>
            <?php endif; ?>
            
            <!-- Debug Log Panel (for errors) -->
            <?php if ($error && !empty($debugLog)): ?>
                <div class="mt-4">
                    <button class="btn btn-sm btn-outline-danger w-100" type="button" data-bs-toggle="collapse" data-bs-target="#debugLogError">
                        <i class="bi bi-terminal me-1"></i>Î ÏÎ¿Î²Î¿Î»Î® Debug Log (<?= count($debugLog) ?> entries)
                    </button>
                    <div class="collapse mt-2" id="debugLogError">
                        <div class="debug-log">
                            <?php foreach ($debugLog as $log): ?>
                                <div class="<?= $log['status'] ?>">
                                    [<?= $log['time'] ?>] <?= htmlspecialchars($log['action']) ?>
                                    <?php if ($log['details']): ?>
                                        <span class="opacity-75"> - <?= htmlspecialchars($log['details']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

