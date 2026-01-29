<?php
/**
 * VolunteerOps - Complete Web Installer
 */

// ============================================
// EMERGENCY DEBUG - Show ALL errors immediately
// ============================================
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        echo "<html><body style='font-family:monospace;padding:20px;'>";
        echo "<h1 style='color:red;'>Fatal Error!</h1>";
        echo "<pre style='background:#fee;padding:20px;border:1px solid red;'>";
        echo "Type: " . $error['type'] . "\n";
        echo "Message: " . htmlspecialchars($error['message']) . "\n";
        echo "File: " . $error['file'] . "\n";
        echo "Line: " . $error['line'] . "\n";
        echo "</pre>";
        echo "<h3>Debug Info:</h3>";
        echo "<pre>";
        echo "POST: " . print_r($_POST, true) . "\n";
        echo "GET: " . print_r($_GET, true) . "\n";
        echo "SESSION: " . print_r($_SESSION ?? [], true) . "\n";
        echo "</pre>";
        echo "</body></html>";
    }
});

// ============================================
// DEBUG MODE - Set to true for troubleshooting
// ============================================
$DEBUG_MODE = isset($_GET['debug']) || isset($_POST['debug']);

// Error handling - capture all errors
$installErrors = [];
$installLog = [];

set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$installErrors) {
    $installErrors[] = [
        'type' => 'PHP Error',
        'code' => $errno,
        'message' => $errstr,
        'file' => basename($errfile),
        'line' => $errline,
        'time' => date('H:i:s')
    ];
    return true;
});

set_exception_handler(function($e) use (&$installErrors) {
    $installErrors[] = [
        'type' => 'Exception',
        'code' => $e->getCode(),
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'time' => date('H:i:s')
    ];
});

function logInstall($message, $type = 'info') {
    global $installLog;
    $installLog[] = [
        'time' => date('H:i:s'),
        'type' => $type,
        'message' => $message
    ];
}

logInstall('Installer started');

session_start();

// Paths
$basePath = __DIR__ . '/volunteerops';
$envPath = $basePath . '/.env';
$schemaPath = $basePath . '/database/schema.sql';
$fullSqlPath = $basePath . '/database/volunteer_ops_full.sql';

logInstall("Base path: $basePath");
logInstall("Checking paths - basePath exists: " . (file_exists($basePath) ? 'YES' : 'NO'));
logInstall("Schema path exists: " . (file_exists($schemaPath) ? 'YES' : 'NO'));
logInstall("Full SQL path exists: " . (file_exists($fullSqlPath) ? 'YES' : 'NO'));

// Check if reinstall mode is requested
$REINSTALL_MODE = isset($_GET['reinstall']) || isset($_POST['reinstall']);

// Security check - block if already installed (unless reinstall mode)
if (file_exists($envPath) && !$REINSTALL_MODE) {
    $envContent = file_get_contents($envPath);
    if (strpos($envContent, 'APP_INSTALLED=true') !== false) {
        echo '
        <!DOCTYPE html>
        <html lang="el">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>VolunteerOps - Ήδη Εγκατεστημένο</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-body text-center py-5">
                                <h1 class="text-success mb-4"><i class="bi bi-check-circle-fill"></i></h1>
                                <h4>Η εφαρμογή είναι ήδη εγκατεστημένη!</h4>
                                <p class="text-muted">Για λόγους ασφαλείας, διαγράψτε το αρχείο <code>install.php</code></p>
                                <a href="/" class="btn btn-primary mt-3">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Μετάβαση στην εφαρμογή
                                </a>
                                <hr>
                                <p class="text-muted small">Θέλετε να επανεγκαταστήσετε;</p>
                                <a href="?reinstall=1&debug=1" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-arrow-repeat me-1"></i>Επανεγκατάσταση
                                </a>
                            </div>
                        </div>
                        <div class="text-center mt-3 text-muted">
                            <small>Created by Theodore Sfakianakis</small>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ';
        exit;
    }
}

// Force debug mode during reinstall
if ($REINSTALL_MODE) {
    $DEBUG_MODE = true;
    logInstall('REINSTALL MODE ACTIVATED - Debug enabled', 'warning');
}

$errors = [];
$warnings = [];
$success = [];
$step = isset($_POST['step']) ? (int)$_POST['step'] : (isset($_GET['step']) ? (int)$_GET['step'] : 1);

// ============================================
// HELPER FUNCTIONS
// ============================================

function checkRequirements() {
    $requirements = [
        'PHP Version' => [
            'required' => '8.2.0',
            'current' => PHP_VERSION,
            'met' => version_compare(PHP_VERSION, '8.2.0', '>=')
        ],
        'PDO MySQL' => [
            'required' => 'Enabled',
            'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('pdo_mysql')
        ],
        'OpenSSL' => [
            'required' => 'Enabled',
            'current' => extension_loaded('openssl') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('openssl')
        ],
        'Mbstring' => [
            'required' => 'Enabled',
            'current' => extension_loaded('mbstring') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('mbstring')
        ],
        'Tokenizer' => [
            'required' => 'Enabled',
            'current' => extension_loaded('tokenizer') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('tokenizer')
        ],
        'JSON' => [
            'required' => 'Enabled',
            'current' => extension_loaded('json') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('json')
        ],
        'Ctype' => [
            'required' => 'Enabled',
            'current' => extension_loaded('ctype') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('ctype')
        ],
        'XML' => [
            'required' => 'Enabled',
            'current' => extension_loaded('xml') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('xml')
        ],
        'Fileinfo' => [
            'required' => 'Enabled',
            'current' => extension_loaded('fileinfo') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('fileinfo')
        ],
        'BCMath' => [
            'required' => 'Enabled',
            'current' => extension_loaded('bcmath') ? 'Enabled' : 'Disabled',
            'met' => extension_loaded('bcmath')
        ],
    ];
    return $requirements;
}

function checkDirectories($basePath) {
    $directories = [
        'storage' => $basePath . '/storage',
        'storage/app' => $basePath . '/storage/app',
        'storage/app/public' => $basePath . '/storage/app/public',
        'storage/framework' => $basePath . '/storage/framework',
        'storage/framework/cache' => $basePath . '/storage/framework/cache',
        'storage/framework/sessions' => $basePath . '/storage/framework/sessions',
        'storage/framework/views' => $basePath . '/storage/framework/views',
        'storage/logs' => $basePath . '/storage/logs',
        'bootstrap/cache' => $basePath . '/bootstrap/cache',
    ];
    
    $results = [];
    foreach ($directories as $name => $path) {
        if (!file_exists($path)) {
            @mkdir($path, 0755, true);
        }
        $results[$name] = [
            'path' => $path,
            'exists' => file_exists($path),
            'writable' => is_writable($path)
        ];
    }
    return $results;
}

function checkVendor($basePath) {
    return file_exists($basePath . '/vendor/autoload.php');
}

function generateAppKey() {
    return 'base64:' . base64_encode(random_bytes(32));
}

function testDatabaseConnection($host, $port, $username, $password, $database = null) {
    logInstall("Testing DB connection: host=$host, port=$port, user=$username, db=" . ($database ?? 'none'));
    try {
        $dsn = "mysql:host={$host};port={$port}";
        if ($database) {
            $dsn .= ";dbname={$database}";
        }
        logInstall("DSN: $dsn");
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        logInstall("DB connection successful", 'success');
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        logInstall("DB connection failed: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function databaseExists($pdo, $dbName) {
    $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
    $stmt->execute([$dbName]);
    return $stmt->fetch() !== false;
}

function createDatabase($pdo, $dbName) {
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function importSqlFile($pdo, $sqlPath) {
    logInstall("Importing SQL file: $sqlPath");
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] importSqlFile() started\n", FILE_APPEND);
    
    if (!file_exists($sqlPath)) {
        logInstall("SQL file not found: $sqlPath", 'error');
        return ['success' => false, 'message' => 'Το αρχείο SQL δεν βρέθηκε: ' . $sqlPath];
    }
    
    try {
        $sql = file_get_contents($sqlPath);
        $fileSize = strlen($sql);
        logInstall("SQL file size: " . $fileSize . " bytes");
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL file loaded: $fileSize bytes\n", FILE_APPEND);
        
        // Log first 20 bytes as hex for debugging
        $firstBytes = '';
        for ($i = 0; $i < min(20, strlen($sql)); $i++) {
            $firstBytes .= sprintf('%02X ', ord($sql[$i]));
        }
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] First 20 bytes (hex): $firstBytes\n", FILE_APPEND);
        
        // Remove ANY BOM or garbage at start - strip until we find a valid SQL character
        $sql = ltrim($sql, "\xEF\xBB\xBF\xFE\xFF\x00");
        // Also remove any leading non-printable characters
        $sql = preg_replace('/^[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\xFF]*/', '', $sql);
        // If file starts with something other than valid SQL start chars, find first valid char
        if (!preg_match('/^[\-\/\!\*a-zA-Z\s]/', $sql)) {
            // Find first occurrence of common SQL starters
            $pos = strcspn($sql, "-/!*abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");
            if ($pos > 0 && $pos < 100) {
                $sql = substr($sql, $pos);
                @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Skipped $pos garbage bytes at start\n", FILE_APPEND);
            }
        }
        
        // Normalize line endings to \n
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Normalized line endings\n", FILE_APPEND);
        
        // Log first 100 chars after cleanup
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL starts with: " . substr($sql, 0, 100) . "\n", FILE_APPEND);
        
        // CRITICAL: Set UTF-8 charset for Greek characters
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET character_set_connection = utf8mb4");
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] UTF-8 charset set\n", FILE_APPEND);
        
        // IMPORTANT: Disable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        $pdo->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Foreign key checks disabled\n", FILE_APPEND);
        
        // Remove SQL line comments (-- ...) but NOT MySQL conditional comments (/*!...*/)
        $sql = preg_replace('/^--[^\n]*\n/m', '', $sql);
        
        // Remove LOCK/UNLOCK TABLES statements (require special privileges)
        $sql = preg_replace('/LOCK TABLES[^;]+;\n?/i', '', $sql);
        $sql = preg_replace('/UNLOCK TABLES;\n?/i', '', $sql);
        
        // Execute multi-query
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        
        // Split by semicolon followed by newline (handles most SQL dumps)
        $statements = preg_split('/;\n/', $sql);
        $statements = array_filter(array_map('trim', $statements));
        $totalStatements = count($statements);
        logInstall("Found $totalStatements SQL statements to execute");
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Found $totalStatements SQL statements\n", FILE_APPEND);
        
        $executedCount = 0;
        $errorCount = 0;
        $createTableCount = 0;
        $lastError = '';
        
        foreach ($statements as $statement) {
            if (!empty($statement) && $statement !== ';') {
                // Track CREATE TABLE statements
                if (stripos($statement, 'CREATE TABLE') !== false) {
                    $createTableCount++;
                }
                
                try {
                    $pdo->exec($statement);
                    $executedCount++;
                } catch (PDOException $e) {
                    $errorCount++;
                    $lastError = $e->getMessage();
                    // Log first few errors with statement preview
                    if ($errorCount <= 3) {
                        $stmtPreview = substr(preg_replace('/\s+/', ' ', $statement), 0, 100);
                        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL ERROR #$errorCount: " . $e->getMessage() . "\n", FILE_APPEND);
                        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Statement: $stmtPreview...\n", FILE_APPEND);
                    }
                    // Log errors but continue (skip duplicate errors)
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        logInstall("SQL error: " . substr($e->getMessage(), 0, 150), 'warning');
                    }
                }
            }
        }
        
        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        logInstall("SQL import complete: $executedCount executed, $errorCount errors, $createTableCount CREATE TABLE statements", 'success');
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL import complete: $executedCount ok, $errorCount errors, $createTableCount CREATE TABLEs\n", FILE_APPEND);
        
        // Only fail if ALL statements failed
        if ($errorCount > 0 && $executedCount == 0) {
            return ['success' => false, 'message' => "Αποτυχία εκτέλεσης SQL: $lastError"];
        }
        
        return ['success' => true, 'count' => $executedCount];
    } catch (Exception $e) {
        logInstall("SQL import failed: " . $e->getMessage(), 'error');
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function countTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    return $stmt->rowCount();
}

function dropAllTables($pdo, $dbName) {
    logInstall("Dropping all tables in database: $dbName", 'warning');
    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $droppedCount = 0;
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
            $droppedCount++;
        }
        
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        logInstall("Dropped $droppedCount tables", 'success');
        return ['success' => true, 'count' => $droppedCount];
    } catch (Exception $e) {
        logInstall("Error dropping tables: " . $e->getMessage(), 'error');
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function createStorageLink($basePath) {
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] createStorageLink() started\n", FILE_APPEND);
    
    try {
        // In our setup, public files are at __DIR__ (public_html), not inside volunteerops/public
        $target = $basePath . '/storage/app/public';
        $link = __DIR__ . '/storage';  // Create link in public_html root, not volunteerops/public
        
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Target: $target\n", FILE_APPEND);
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Link: $link\n", FILE_APPEND);
        
        // Ensure target exists
        if (!file_exists($target)) {
            @mkdir($target, 0755, true);
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Created target directory\n", FILE_APPEND);
        }
        
        // Check if link exists
        if (file_exists($link) || is_link($link)) {
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Link already exists\n", FILE_APPEND);
            return ['success' => true, 'message' => 'Storage link υπάρχει ήδη'];
        }
        
        // Try to create symlink (often fails on shared hosting)
        $symlinkResult = @symlink($target, $link);
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] symlink() result: " . ($symlinkResult ? 'OK' : 'FAILED') . "\n", FILE_APPEND);
        
        if ($symlinkResult) {
            return ['success' => true, 'message' => 'Storage link δημιουργήθηκε'];
        }
        
        // If symlink fails, create a directory with .htaccess workaround
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Creating .htaccess workaround\n", FILE_APPEND);
        
        if (!file_exists($link)) {
            @mkdir($link, 0755, true);
        }
        
        $htaccess = $link . '/.htaccess';
        $htaccessContent = "RewriteEngine On\nRewriteRule ^(.*)$ volunteerops/storage/app/public/$1 [L]";
        if (@file_put_contents($htaccess, $htaccessContent)) {
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] .htaccess workaround created\n", FILE_APPEND);
            return ['success' => true, 'message' => 'Storage link δημιουργήθηκε (μέσω .htaccess)'];
        }
        
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Storage link creation failed\n", FILE_APPEND);
        return ['success' => false, 'message' => 'Αδυναμία δημιουργίας storage link. Δημιουργήστε χειροκίνητα symlink.'];
        
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] createStorageLink EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
    }
}

function createEnvFile($basePath, $config) {
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] createEnvFile() started - basePath: $basePath\n", FILE_APPEND);
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Config keys: " . implode(', ', array_keys($config)) . "\n", FILE_APPEND);
    
    // Ensure domain is set
    if (empty($config['domain'])) {
        $config['domain'] = parse_url($config['app_url'], PHP_URL_HOST) ?? 'localhost';
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Domain was empty, set to: " . $config['domain'] . "\n", FILE_APPEND);
    }
    
    $envContent = "APP_NAME=\"{$config['app_name']}\"
APP_ENV=production
APP_KEY={$config['app_key']}
APP_DEBUG=true
APP_URL={$config['app_url']}
APP_INSTALLED=true

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST={$config['db_host']}
DB_PORT={$config['db_port']}
DB_DATABASE={$config['db_name']}
DB_USERNAME={$config['db_user']}
DB_PASSWORD={$config['db_pass']}

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

MAIL_MAILER=smtp
MAIL_HOST=localhost
MAIL_PORT=25
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=\"noreply@{$config['domain']}\"
MAIL_FROM_NAME=\"\${APP_NAME}\"

SANCTUM_STATEFUL_DOMAINS={$config['domain']}
SESSION_DOMAIN=.{$config['domain']}
";

    $envPath = $basePath . '/.env';
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Writing .env to: $envPath\n", FILE_APPEND);
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] .env content length: " . strlen($envContent) . " bytes\n", FILE_APPEND);
    
    $writeResult = file_put_contents($envPath, $envContent);
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] file_put_contents result: " . ($writeResult !== false ? "$writeResult bytes written" : "FAILED") . "\n", FILE_APPEND);
    
    if ($writeResult !== false) {
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] .env created successfully!\n", FILE_APPEND);
        return ['success' => true];
    }
    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] .env creation FAILED!\n", FILE_APPEND);
    return ['success' => false, 'message' => 'Αδυναμία εγγραφής .env αρχείου'];
}

function clearCache($basePath) {
    $cacheDirs = [
        $basePath . '/bootstrap/cache',
        $basePath . '/storage/framework/cache/data',
        $basePath . '/storage/framework/views',
        $basePath . '/storage/framework/sessions',
    ];
    
    $cleared = 0;
    foreach ($cacheDirs as $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== '.gitignore') {
                    @unlink($file);
                    $cleared++;
                }
            }
        }
    }
    return $cleared;
}

function getDefaultCredentials() {
    return [
        'admin' => [
            'email' => 'admin@volunteerops.gr',
            'password' => 'password123',
            'role' => 'SYSTEM_ADMIN'
        ],
        'volunteer' => [
            'email' => 'volunteer@volunteerops.gr', 
            'password' => 'password123',
            'role' => 'VOLUNTEER'
        ]
    ];
}

// ============================================
// PROCESS FORM SUBMISSIONS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Step 2: Database Configuration
    if ($step === 2) {
        logInstall("Processing Step 2: Database Configuration");
        
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $appUrl = trim($_POST['app_url'] ?? '');
        $appName = trim($_POST['app_name'] ?? 'VolunteerOps');
        $installType = $_POST['install_type'] ?? 'full';
        $createDb = isset($_POST['create_db']);
        $dropTables = isset($_POST['drop_tables']);
        
        logInstall("DB Config: host=$dbHost, port=$dbPort, db=$dbName, user=$dbUser");
        logInstall("Options: createDb=" . ($createDb ? 'YES' : 'NO') . ", dropTables=" . ($dropTables ? 'YES' : 'NO'));
        
        // Store in session for step 3
        $_SESSION['install'] = [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'app_url' => $appUrl,
            'app_name' => $appName,
            'install_type' => $installType,
            'create_db' => $createDb,
            'drop_tables' => $dropTables,
            'domain' => parse_url($appUrl, PHP_URL_HOST) ?? 'localhost'
        ];
        
        // Validate required fields
        if (empty($dbName) || empty($dbUser) || empty($appUrl)) {
            $errors[] = "Παρακαλώ συμπληρώστε όλα τα υποχρεωτικά πεδία.";
            logInstall("Validation failed: missing required fields", 'error');
        }
        
        if (empty($errors)) {
            // Test connection without database first
            $connTest = testDatabaseConnection($dbHost, $dbPort, $dbUser, $dbPass);
            
            if (!$connTest['success']) {
                $errors[] = "Σφάλμα σύνδεσης MySQL: " . $connTest['message'];
            } else {
                $pdo = $connTest['pdo'];
                
                // Check/Create database
                if (!databaseExists($pdo, $dbName)) {
                    if ($createDb) {
                        $createResult = createDatabase($pdo, $dbName);
                        if (!$createResult['success']) {
                            $errors[] = "Αδυναμία δημιουργίας βάσης: " . $createResult['message'];
                        } else {
                            $success[] = "Η βάση δεδομένων '{$dbName}' δημιουργήθηκε";
                            logInstall("Database '$dbName' created successfully", 'success');
                        }
                    } else {
                        $errors[] = "Η βάση δεδομένων '{$dbName}' δεν υπάρχει. Επιλέξτε 'Δημιουργία βάσης' ή δημιουργήστε την χειροκίνητα.";
                    }
                } else {
                    logInstall("Database '$dbName' already exists");
                    
                    // If drop tables is selected, drop all tables
                    if ($dropTables) {
                        $dbTest = testDatabaseConnection($dbHost, $dbPort, $dbUser, $dbPass, $dbName);
                        if ($dbTest['success']) {
                            $dropResult = dropAllTables($dbTest['pdo'], $dbName);
                            if ($dropResult['success']) {
                                $success[] = "Διαγράφηκαν {$dropResult['count']} πίνακες από τη βάση";
                            } else {
                                $warnings[] = "Πρόβλημα κατά τη διαγραφή πινάκων: " . ($dropResult['message'] ?? '');
                            }
                        }
                    }
                }
                
                if (empty($errors)) {
                    $step = 3; // Move to step 3
                    logInstall("Moving to Step 3", 'success');
                }
            }
        }
    }
    
    // Step 3: Installation
    if ($step === 3 && isset($_POST['confirm_install'])) {
        // IMMEDIATE LOG - write to file directly
        @file_put_contents(__DIR__ . '/install_debug.log', "\n[" . date('H:i:s') . "] Step 3 CONFIRM received - starting installation\n", FILE_APPEND);
        
        logInstall("Processing Step 3: Installation");
        $config = $_SESSION['install'] ?? [];
        
        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Session config keys: " . json_encode(array_keys($config)) . "\n", FILE_APPEND);
        
        if (empty($config)) {
            $errors[] = "Session expired. Ξεκινήστε ξανά.";
            logInstall("Session expired", 'error');
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] ERROR: Session expired!\n", FILE_APPEND);
            $step = 1;
        } else {
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Config loaded OK, proceeding...\n", FILE_APPEND);
            logInstall("Config loaded from session: " . json_encode(array_keys($config)));
            
            // Delete old .env if reinstalling
            if (file_exists($envPath)) {
                @unlink($envPath);
                logInstall("Deleted old .env file");
            }
            
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Connecting to database...\n", FILE_APPEND);
            logInstall("Connecting to database...");
            
            // Connect to the specific database
            $dbTest = testDatabaseConnection(
                $config['db_host'], 
                $config['db_port'], 
                $config['db_user'], 
                $config['db_pass'],
                $config['db_name']
            );
            
            @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] DB connection result: " . ($dbTest['success'] ? 'SUCCESS' : 'FAILED') . "\n", FILE_APPEND);
            
            if (!$dbTest['success']) {
                $errors[] = "Σφάλμα σύνδεσης στη βάση: " . $dbTest['message'];
                $step = 2;
            } else {
                $pdo = $dbTest['pdo'];
                logInstall("Connected to database successfully");
                
                // Drop tables if reinstalling
                if (!empty($config['drop_tables'])) {
                    logInstall("Dropping all tables before import...", 'warning');
                    $dropResult = dropAllTables($pdo, $config['db_name']);
                    if ($dropResult['success']) {
                        $success[] = "Διαγράφηκαν {$dropResult['count']} πίνακες";
                    }
                }
                
                // Import SQL
                $sqlFile = ($config['install_type'] === 'full') ? $fullSqlPath : $schemaPath;
                logInstall("SQL file to import: $sqlFile");
                @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL file: $sqlFile\n", FILE_APPEND);
                
                if (!file_exists($sqlFile)) {
                    $errors[] = "Το αρχείο SQL δεν βρέθηκε: " . basename($sqlFile);
                    logInstall("SQL file not found: $sqlFile", 'error');
                } else {
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Starting SQL import...\n", FILE_APPEND);
                    logInstall("Starting SQL import - this may take a while...");
                    
                    // Flush output buffer to show progress
                    if (ob_get_level()) {
                        ob_flush();
                        flush();
                    }
                    
                    $importResult = importSqlFile($pdo, $sqlFile);
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL import finished: " . ($importResult['success'] ? 'SUCCESS' : 'FAILED') . "\n", FILE_APPEND);
                    logInstall("SQL import finished");
                    
                    if (!$importResult['success']) {
                        $errors[] = "Σφάλμα εισαγωγής SQL: " . $importResult['message'];
                        logInstall("SQL import error: " . $importResult['message'], 'error');
                        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] SQL ERROR: " . $importResult['message'] . "\n", FILE_APPEND);
                    } else {
                        $tableCount = countTables($pdo);
                        $success[] = "Εισήχθησαν {$tableCount} πίνακες στη βάση δεδομένων";
                        logInstall("SQL import success: $tableCount tables created", 'success');
                        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Tables created: $tableCount\n", FILE_APPEND);
                    }
                }
                
                @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] After SQL block, errors array: " . (empty($errors) ? 'EMPTY' : implode(', ', $errors)) . "\n", FILE_APPEND);
                
                if (empty($errors)) {
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Inside empty(errors) block, starting post-SQL tasks\n", FILE_APPEND);
                    
                    logInstall("Generating APP_KEY...");
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Calling generateAppKey()...\n", FILE_APPEND);
                    
                    // Generate APP_KEY
                    $config['app_key'] = generateAppKey();
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] APP_KEY generated: " . substr($config['app_key'], 0, 20) . "...\n", FILE_APPEND);
                    
                    logInstall("Creating .env file...");
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Calling createEnvFile()...\n", FILE_APPEND);
                    
                    // Create .env file
                    $envResult = createEnvFile($basePath, $config);
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] createEnvFile result: " . ($envResult['success'] ? 'SUCCESS' : 'FAILED - ' . ($envResult['message'] ?? 'unknown')) . "\n", FILE_APPEND);
                    if ($envResult['success']) {
                        $success[] = "Δημιουργήθηκε το αρχείο .env";
                        logInstall(".env file created", 'success');
                    } else {
                        $errors[] = $envResult['message'];
                        logInstall(".env creation failed: " . $envResult['message'], 'error');
                    }
                    
                    // Create storage link
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Creating storage link...\n", FILE_APPEND);
                    $linkResult = createStorageLink($basePath);
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Storage link result: " . ($linkResult['success'] ? 'SUCCESS' : 'FAILED') . " - " . ($linkResult['message'] ?? '') . "\n", FILE_APPEND);
                    if ($linkResult['success']) {
                        $success[] = $linkResult['message'];
                    } else {
                        $warnings[] = $linkResult['message'];
                    }
                    
                    // Clear cache
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Clearing cache...\n", FILE_APPEND);
                    $clearedCount = clearCache($basePath);
                    $success[] = "Καθαρίστηκε η cache ({$clearedCount} αρχεία)";
                    logInstall("Cache cleared: $clearedCount files");
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Cache cleared: $clearedCount files\n", FILE_APPEND);
                    
                    // Fix directory permissions
                    @chmod($basePath . '/storage', 0755);
                    @chmod($basePath . '/bootstrap/cache', 0755);
                    logInstall("Permissions fixed for storage and bootstrap/cache");
                    @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] Permissions fixed\n", FILE_APPEND);
                    
                    if (empty($errors)) {
                        $step = 4; // Success!
                        @file_put_contents(__DIR__ . '/install_debug.log', "[" . date('H:i:s') . "] *** INSTALLATION COMPLETE - Moving to Step 4 ***\n", FILE_APPEND);
                        logInstall("Installation completed successfully!", 'success');
                        unset($_SESSION['install']);
                    }
                }
            }
        }
    }
}

logInstall("Current step: $step");

// Check requirements
$requirements = checkRequirements();
$directories = checkDirectories($basePath);
$vendorExists = checkVendor($basePath);
logInstall("Vendor exists: " . ($vendorExists ? 'YES' : 'NO'));

$allRequirementsMet = true;
foreach ($requirements as $req) {
    if (!$req['met']) {
        $allRequirementsMet = false;
        break;
    }
}

$allDirsWritable = true;
foreach ($directories as $dir) {
    if (!$dir['writable']) {
        $allDirsWritable = false;
        break;
    }
}

$sqlFilesExist = file_exists($schemaPath) || file_exists($fullSqlPath);

// Save log to file for debugging - ALWAYS save, append mode
$logFile = __DIR__ . '/install_debug.log';
$logContent = "\n\n=== VolunteerOps Install Log ===\n";
$logContent .= "Date: " . date('Y-m-d H:i:s') . "\n";
$logContent .= "PHP Version: " . PHP_VERSION . "\n";
$logContent .= "Step: $step\n";
$logContent .= "Request Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
$logContent .= "POST data: " . json_encode($_POST) . "\n\n";
foreach ($installLog as $log) {
    $logContent .= "[{$log['time']}] [{$log['type']}] {$log['message']}\n";
}
if (!empty($installErrors)) {
    $logContent .= "\n=== ERRORS ===\n";
    foreach ($installErrors as $err) {
        $logContent .= "[{$err['time']}] {$err['type']}: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
}
@file_put_contents($logFile, $logContent, FILE_APPEND);

?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VolunteerOps - Εγκατάσταση</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1a237e 0%, #0d47a1 100%);
        }
        body { 
            background: var(--primary-gradient); 
            min-height: 100vh;
            padding: 20px 0;
        }
        .installer-container { 
            max-width: 700px; 
            margin: 0 auto; 
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .card-header {
            border-radius: 15px 15px 0 0 !important;
            background: var(--primary-gradient) !important;
        }
        .step-indicator { 
            display: flex; 
            justify-content: center; 
            gap: 10px;
            margin-bottom: 25px; 
        }
        .step { 
            width: 45px; 
            height: 45px; 
            border-radius: 50%; 
            background: #e0e0e0; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-weight: bold;
            font-size: 18px;
            position: relative;
        }
        .step::after {
            content: '';
            position: absolute;
            width: 30px;
            height: 3px;
            background: #e0e0e0;
            right: -35px;
        }
        .step:last-child::after {
            display: none;
        }
        .step.active { 
            background: #1976d2; 
            color: white;
            box-shadow: 0 0 0 4px rgba(25, 118, 210, 0.3);
        }
        .step.completed { 
            background: #4caf50; 
            color: white; 
        }
        .step.completed::after {
            background: #4caf50;
        }
        .requirement-item { 
            display: flex; 
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px; 
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .install-option {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .install-option:hover {
            border-color: #1976d2;
            background: #f5f9ff;
        }
        .install-option.selected {
            border-color: #1976d2;
            background: #e3f2fd;
        }
        .credentials-box {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 10px;
            padding: 20px;
        }
        .password-toggle {
            cursor: pointer;
        }
        .form-control:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 0.2rem rgba(25, 118, 210, 0.25);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="installer-container">
            <div class="card">
                <div class="card-header text-white text-center py-4">
                    <h2 class="mb-1"><i class="bi bi-people-fill me-2"></i>VolunteerOps</h2>
                    <p class="mb-0 opacity-75">Σύστημα Διαχείρισης Εθελοντών</p>
                </div>
                
                <div class="card-body p-4">
                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step <?= $step >= 2 ? 'completed' : ($step === 1 ? 'active' : '') ?>">1</div>
                        <div class="step <?= $step >= 3 ? 'completed' : ($step === 2 ? 'active' : '') ?>">2</div>
                        <div class="step <?= $step >= 4 ? 'completed' : ($step === 3 ? 'active' : '') ?>">3</div>
                        <div class="step <?= $step === 4 ? 'active completed' : '' ?>">4</div>
                    </div>
                    
                    <!-- Alerts -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <h6 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Σφάλματα</h6>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($warnings)): ?>
                        <div class="alert alert-warning">
                            <ul class="mb-0">
                                <?php foreach ($warnings as $warning): ?>
                                    <li><?= htmlspecialchars($warning) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success) && $step !== 4): ?>
                        <div class="alert alert-success">
                            <ul class="mb-0">
                                <?php foreach ($success as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <!-- ============================================ -->
                    <!-- STEP 1: Requirements Check -->
                    <!-- ============================================ -->
                    <?php if ($step === 1): ?>
                        <h4 class="mb-4"><i class="bi bi-check-circle text-primary me-2"></i>Βήμα 1: Έλεγχος Απαιτήσεων</h4>
                        
                        <!-- PHP Extensions -->
                        <h6 class="text-muted mb-3">PHP Extensions</h6>
                        <?php foreach ($requirements as $name => $req): ?>
                            <div class="requirement-item">
                                <span>
                                    <?= htmlspecialchars($name) ?>
                                    <small class="text-muted">(<?= htmlspecialchars($req['current']) ?>)</small>
                                </span>
                                <?php if ($req['met']): ?>
                                    <span class="text-success"><i class="bi bi-check-circle-fill fs-5"></i></span>
                                <?php else: ?>
                                    <span class="text-danger"><i class="bi bi-x-circle-fill fs-5"></i></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Directory Permissions -->
                        <h6 class="text-muted mb-3 mt-4">Δικαιώματα Φακέλων</h6>
                        <?php foreach ($directories as $name => $dir): ?>
                            <div class="requirement-item">
                                <span><?= htmlspecialchars($name) ?>/</span>
                                <?php if ($dir['writable']): ?>
                                    <span class="text-success"><i class="bi bi-check-circle-fill fs-5"></i></span>
                                <?php else: ?>
                                    <span class="text-danger"><i class="bi bi-x-circle-fill fs-5"></i> Μη εγγράψιμο</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Vendor & SQL -->
                        <h6 class="text-muted mb-3 mt-4">Αρχεία Εγκατάστασης</h6>
                        <div class="requirement-item">
                            <span>Vendor (Dependencies)</span>
                            <?php if ($vendorExists): ?>
                                <span class="text-success"><i class="bi bi-check-circle-fill fs-5"></i></span>
                            <?php else: ?>
                                <span class="text-danger"><i class="bi bi-x-circle-fill fs-5"></i> Λείπει</span>
                            <?php endif; ?>
                        </div>
                        <div class="requirement-item">
                            <span>SQL Αρχεία</span>
                            <?php if ($sqlFilesExist): ?>
                                <span class="text-success"><i class="bi bi-check-circle-fill fs-5"></i></span>
                            <?php else: ?>
                                <span class="text-danger"><i class="bi bi-x-circle-fill fs-5"></i> Λείπουν</span>
                            <?php endif; ?>
                        </div>
                        
                        <hr class="my-4">
                        
                        <?php if ($allRequirementsMet && $allDirsWritable && $vendorExists && $sqlFilesExist): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>Τέλεια!</strong> Όλες οι απαιτήσεις πληρούνται.
                            </div>
                            <form method="post">
                                <input type="hidden" name="step" value="2">
                                <?php if ($DEBUG_MODE): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                                <?php if ($REINSTALL_MODE): ?><input type="hidden" name="reinstall" value="1"><?php endif; ?>
                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    Συνέχεια <i class="bi bi-arrow-right ms-2"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Προσοχή!</strong> Διορθώστε τα παραπάνω προβλήματα πριν συνεχίσετε.
                            </div>
                            <?php if (!$vendorExists): ?>
                                <div class="alert alert-info">
                                    <strong>Λείπει το vendor folder:</strong> Βεβαιωθείτε ότι αποσυμπιέσατε όλα τα αρχεία από το ZIP.
                                </div>
                            <?php endif; ?>
                            <button class="btn btn-secondary btn-lg w-100" onclick="location.reload()">
                                <i class="bi bi-arrow-clockwise me-2"></i>Επανέλεγχος
                            </button>
                        <?php endif; ?>
                        
                    <!-- ============================================ -->
                    <!-- STEP 2: Database Configuration -->
                    <!-- ============================================ -->
                    <?php elseif ($step === 2): ?>
                        <h4 class="mb-4"><i class="bi bi-database text-primary me-2"></i>Βήμα 2: Ρύθμιση Βάσης Δεδομένων</h4>
                        
                        <form method="post" id="dbForm">
                            <input type="hidden" name="step" value="2">
                            <?php if ($DEBUG_MODE): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                            <?php if ($REINSTALL_MODE): ?><input type="hidden" name="reinstall" value="1"><?php endif; ?>
                            
                            <!-- App Settings -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Όνομα Εφαρμογής</label>
                                    <input type="text" name="app_name" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['app_name'] ?? 'VolunteerOps') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">URL Εφαρμογής</label>
                                    <input type="url" name="app_url" class="form-control" 
                                           placeholder="https://yourdomain.com" 
                                           value="<?= htmlspecialchars($_POST['app_url'] ?? (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) ?>" required>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            <h6 class="text-muted mb-3">Στοιχεία MySQL</h6>
                            
                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <label class="form-label">Database Host</label>
                                    <input type="text" name="db_host" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>" required>
                                    <small class="text-muted">Συνήθως "localhost" σε shared hosting</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Port</label>
                                    <input type="text" name="db_port" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Όνομα Βάσης Δεδομένων</label>
                                <input type="text" name="db_name" class="form-control" 
                                       value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" 
                                       placeholder="volunteer_ops" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Χρήστης MySQL</label>
                                <input type="text" name="db_user" class="form-control" 
                                       value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Κωδικός MySQL</label>
                                <div class="input-group">
                                    <input type="password" name="db_pass" id="db_pass" class="form-control" 
                                           value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>">
                                    <button class="btn btn-outline-secondary password-toggle" type="button" onclick="togglePassword()">
                                        <i class="bi bi-eye" id="toggleIcon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="create_db" id="create_db" checked>
                                    <label class="form-check-label" for="create_db">
                                        Δημιουργία βάσης αν δεν υπάρχει
                                    </label>
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="drop_tables" id="drop_tables" 
                                           <?= $REINSTALL_MODE ? 'checked' : '' ?>>
                                    <label class="form-check-label text-danger" for="drop_tables">
                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                        Διαγραφή υπαρχόντων πινάκων (για επανεγκατάσταση)
                                    </label>
                                    <br><small class="text-muted">⚠️ Θα διαγραφούν ΟΛΑ τα δεδομένα!</small>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            <h6 class="text-muted mb-3">Τύπος Εγκατάστασης</h6>
                            
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <div class="install-option selected" onclick="selectInstallType('full')">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="install_type" 
                                                   value="full" id="install_full" checked>
                                            <label class="form-check-label" for="install_full">
                                                <strong>Πλήρης Εγκατάσταση</strong>
                                                <br><small class="text-muted">Με δοκιμαστικά δεδομένα & demo λογαριασμούς</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="install-option" onclick="selectInstallType('clean')">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="install_type" 
                                                   value="clean" id="install_clean">
                                            <label class="form-check-label" for="install_clean">
                                                <strong>Καθαρή Εγκατάσταση</strong>
                                                <br><small class="text-muted">Μόνο δομή, χωρίς δεδομένα</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-database-check me-2"></i>Έλεγχος Σύνδεσης & Συνέχεια
                                </button>
                                <a href="?step=1" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Πίσω
                                </a>
                            </div>
                        </form>
                        
                        <script>
                        function togglePassword() {
                            const input = document.getElementById('db_pass');
                            const icon = document.getElementById('toggleIcon');
                            if (input.type === 'password') {
                                input.type = 'text';
                                icon.className = 'bi bi-eye-slash';
                            } else {
                                input.type = 'password';
                                icon.className = 'bi bi-eye';
                            }
                        }
                        
                        function selectInstallType(type) {
                            document.querySelectorAll('.install-option').forEach(el => el.classList.remove('selected'));
                            event.currentTarget.classList.add('selected');
                            document.getElementById('install_' + type).checked = true;
                        }
                        </script>
                        
                    <!-- ============================================ -->
                    <!-- STEP 3: Installation Confirmation -->
                    <!-- ============================================ -->
                    <?php elseif ($step === 3): ?>
                        <?php $config = $_SESSION['install'] ?? []; ?>
                        
                        <h4 class="mb-4"><i class="bi bi-gear text-primary me-2"></i>Βήμα 3: Επιβεβαίωση Εγκατάστασης</h4>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Ελέγξτε τις ρυθμίσεις σας πριν συνεχίσετε. Η εγκατάσταση θα εισάγει τα δεδομένα στη βάση.
                        </div>
                        
                        <table class="table">
                            <tr>
                                <th width="40%">Εφαρμογή:</th>
                                <td><?= htmlspecialchars($config['app_name'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <th>URL:</th>
                                <td><?= htmlspecialchars($config['app_url'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <th>Database Host:</th>
                                <td><?= htmlspecialchars($config['db_host'] ?? '') ?>:<?= htmlspecialchars($config['db_port'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <th>Database Name:</th>
                                <td><?= htmlspecialchars($config['db_name'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <th>Database User:</th>
                                <td><?= htmlspecialchars($config['db_user'] ?? '') ?></td>
                            </tr>
                            <tr>
                                <th>Τύπος:</th>
                                <td>
                                    <?php if (($config['install_type'] ?? '') === 'full'): ?>
                                        <span class="badge bg-primary">Πλήρης (με δοκιμαστικά δεδομένα)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Καθαρή (χωρίς δεδομένα)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        
                        <?php if (!empty($config['drop_tables'])): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>ΠΡΟΣΟΧΗ:</strong> Θα διαγραφούν ΟΛΟΙ οι υπάρχοντες πίνακες πριν την εγκατάσταση!
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Προσοχή:</strong> Αν η βάση έχει ήδη πίνακες, μπορεί να αντικατασταθούν!
                        </div>
                        <?php endif; ?>
                        
                        <form method="post">
                            <input type="hidden" name="step" value="3">
                            <input type="hidden" name="confirm_install" value="1">
                            <?php if ($DEBUG_MODE): ?><input type="hidden" name="debug" value="1"><?php endif; ?>
                            <?php if ($REINSTALL_MODE): ?><input type="hidden" name="reinstall" value="1"><?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-rocket-takeoff me-2"></i>Εγκατάσταση Τώρα
                                </button>
                                <a href="?step=2<?= $DEBUG_MODE ? '&debug=1' : '' ?><?= $REINSTALL_MODE ? '&reinstall=1' : '' ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left me-2"></i>Πίσω για διορθώσεις
                                </a>
                            </div>
                        </form>
                        
                    <!-- ============================================ -->
                    <!-- STEP 4: Success! -->
                    <!-- ============================================ -->
                    <?php elseif ($step === 4): ?>
                        <?php $credentials = getDefaultCredentials(); ?>
                        
                        <div class="text-center mb-4">
                            <div class="mb-3">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 80px;"></i>
                            </div>
                            <h3 class="text-success">Η εγκατάσταση ολοκληρώθηκε!</h3>
                            <p class="text-muted">Το VolunteerOps είναι έτοιμο για χρήση.</p>
                        </div>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <h6 class="alert-heading">Ενέργειες που ολοκληρώθηκαν:</h6>
                                <ul class="mb-0">
                                    <?php foreach ($success as $msg): ?>
                                        <li><?= htmlspecialchars($msg) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Credentials Box -->
                        <div class="credentials-box mb-4">
                            <h5 class="mb-3"><i class="bi bi-key me-2"></i>Στοιχεία Σύνδεσης</h5>
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Ρόλος</th>
                                        <th>Email</th>
                                        <th>Κωδικός</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><span class="badge bg-danger">Admin</span></td>
                                        <td><code><?= $credentials['admin']['email'] ?></code></td>
                                        <td><code><?= $credentials['admin']['password'] ?></code></td>
                                    </tr>
                                    <tr>
                                        <td><span class="badge bg-primary">Volunteer</span></td>
                                        <td><code><?= $credentials['volunteer']['email'] ?></code></td>
                                        <td><code><?= $credentials['volunteer']['password'] ?></code></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Security Warning -->
                        <div class="alert alert-danger">
                            <h6 class="alert-heading"><i class="bi bi-shield-exclamation me-2"></i>ΣΗΜΑΝΤΙΚΟ - Ασφάλεια!</h6>
                            <ol class="mb-0">
                                <li><strong>Διαγράψτε ΑΜΕΣΩΣ το αρχείο:</strong> <code>public/install.php</code></li>
                                <li><strong>Αλλάξτε τους κωδικούς</strong> των demo λογαριασμών</li>
                                <li>Βεβαιωθείτε ότι το <code>.env</code> δεν είναι προσβάσιμο από το web</li>
                            </ol>
                        </div>
                        
                        <div class="d-grid">
                            <a href="/" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Είσοδος στην Εφαρμογή
                            </a>
                        </div>
                        
                    <?php endif; ?>
                </div>
                
                <div class="card-footer text-center text-muted py-3">
                    <small>
                        VolunteerOps v1.2 | Created by <strong>Theodore Sfakianakis</strong>
                        <br>
                        <a href="https://github.com/TheoSfak/volunteer-ops" target="_blank" class="text-decoration-none">
                            <i class="bi bi-github me-1"></i>GitHub
                        </a>
                        <?php if (!$DEBUG_MODE): ?>
                        | <a href="?debug=1" class="text-decoration-none text-warning">
                            <i class="bi bi-bug me-1"></i>Debug Mode
                        </a>
                        <?php endif; ?>
                        <?php if (!$REINSTALL_MODE): ?>
                        | <a href="?reinstall=1" class="text-decoration-none text-danger">
                            <i class="bi bi-arrow-repeat me-1"></i>Reinstall
                        </a>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            
            <!-- Debug Panel -->
            <?php if ($DEBUG_MODE): ?>
            <div class="card mt-4 border-warning">
                <div class="card-header bg-warning text-dark">
                    <i class="bi bi-bug me-2"></i><strong>Debug Panel</strong>
                    <span class="badge bg-dark float-end">Log file: install_debug.log</span>
                </div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                    <h6>System Info:</h6>
                    <ul class="mb-3">
                        <li>PHP: <?= PHP_VERSION ?></li>
                        <li>Server: <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></li>
                        <li>Document Root: <?= $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown' ?></li>
                        <li>Script Path: <?= __DIR__ ?></li>
                        <li>Base Path: <?= $basePath ?></li>
                        <li>Base Path Exists: <?= file_exists($basePath) ? 'YES' : 'NO' ?></li>
                        <li>Vendor Exists: <?= $vendorExists ? 'YES' : 'NO' ?></li>
                    </ul>
                    
                    <h6>Install Log:</h6>
                    <div class="bg-dark text-light p-2 rounded" style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($installLog as $log): ?>
                            <div class="<?= $log['type'] === 'error' ? 'text-danger' : ($log['type'] === 'success' ? 'text-success' : ($log['type'] === 'warning' ? 'text-warning' : '')) ?>">
                                [<?= $log['time'] ?>] <?= htmlspecialchars($log['message']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!empty($installErrors)): ?>
                    <h6 class="mt-3 text-danger">PHP Errors:</h6>
                    <div class="bg-danger text-white p-2 rounded">
                        <?php foreach ($installErrors as $err): ?>
                            <div>[<?= $err['time'] ?>] <?= $err['type'] ?>: <?= htmlspecialchars($err['message']) ?> in <?= $err['file'] ?>:<?= $err['line'] ?></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errors)): ?>
                    <h6 class="mt-3 text-danger">Installation Errors:</h6>
                    <ul class="text-danger">
                        <?php foreach ($errors as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    </div>
</body>
</html>
