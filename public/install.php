<?php
/**
 * VolunteerOps - Complete Web Installer
 * 
 * Αυτό το script:
 * 1. Ελέγχει τις απαιτήσεις PHP
 * 2. Δημιουργεί τη βάση δεδομένων (αν έχει δικαιώματα)
 * 3. Εισάγει το SQL schema αυτόματα
 * 4. Δημιουργεί το .env αρχείο
 * 5. Ρυθμίζει τα πάντα αυτόματα
 * 
 * ΔΙΑΓΡΑΨΤΕ ΑΥΤΟ ΤΟ ΑΡΧΕΙΟ ΜΕΤΑ ΤΗΝ ΕΓΚΑΤΑΣΤΑΣΗ!
 */

session_start();

// Paths
$basePath = dirname(__DIR__);
$envPath = $basePath . '/.env';
$schemaPath = $basePath . '/database/schema.sql';
$fullSqlPath = $basePath . '/database/volunteer_ops_full.sql';

// Security check - block if already installed
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (strpos($envContent, 'APP_INSTALLED=true') !== false) {
        die('
        <!DOCTYPE html>
        <html lang="el">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>VolunteerOps - Ήδη Εγκατεστημένο</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container py-5">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card shadow">
                            <div class="card-body text-center py-5">
                                <h1 class="text-warning mb-4">⚠️</h1>
                                <h4>Η εφαρμογή είναι ήδη εγκατεστημένη!</h4>
                                <p class="text-muted">Για λόγους ασφαλείας, διαγράψτε το αρχείο <code>install.php</code></p>
                                <a href="/" class="btn btn-primary mt-3">Μετάβαση στην εφαρμογή</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ');
    }
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
    try {
        $dsn = "mysql:host={$host};port={$port}";
        if ($database) {
            $dsn .= ";dbname={$database}";
        }
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
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
    if (!file_exists($sqlPath)) {
        return ['success' => false, 'message' => 'Το αρχείο SQL δεν βρέθηκε: ' . $sqlPath];
    }
    
    try {
        $sql = file_get_contents($sqlPath);
        
        // Remove comments and split by semicolon
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Execute multi-query
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        
        // Split by delimiter
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $executedCount = 0;
        foreach ($statements as $statement) {
            if (!empty($statement) && $statement !== ';') {
                try {
                    $pdo->exec($statement);
                    $executedCount++;
                } catch (PDOException $e) {
                    // Skip duplicate table/key errors
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        // Log but continue
                    }
                }
            }
        }
        
        return ['success' => true, 'count' => $executedCount];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function countTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    return $stmt->rowCount();
}

function createStorageLink($basePath) {
    $target = $basePath . '/storage/app/public';
    $link = $basePath . '/public/storage';
    
    // Ensure target exists
    if (!file_exists($target)) {
        @mkdir($target, 0755, true);
    }
    
    // Check if link exists
    if (file_exists($link) || is_link($link)) {
        return ['success' => true, 'message' => 'Storage link υπάρχει ήδη'];
    }
    
    // Try to create symlink
    if (@symlink($target, $link)) {
        return ['success' => true, 'message' => 'Storage link δημιουργήθηκε'];
    }
    
    // If symlink fails, create a PHP redirect workaround
    $htaccess = $link . '/.htaccess';
    if (!file_exists($link)) {
        @mkdir($link, 0755, true);
    }
    
    $htaccessContent = "RewriteEngine On\nRewriteRule ^(.*)$ ../storage/app/public/$1 [L]";
    if (@file_put_contents($htaccess, $htaccessContent)) {
        return ['success' => true, 'message' => 'Storage link δημιουργήθηκε (μέσω .htaccess)'];
    }
    
    return ['success' => false, 'message' => 'Αδυναμία δημιουργίας storage link. Δημιουργήστε χειροκίνητα symlink.'];
}

function createEnvFile($basePath, $config) {
    $envContent = "APP_NAME=\"{$config['app_name']}\"
APP_ENV=production
APP_KEY={$config['app_key']}
APP_DEBUG=false
APP_URL={$config['app_url']}
APP_INSTALLED=true

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

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
SESSION_DOMAIN={$config['domain']}
";

    $envPath = $basePath . '/.env';
    if (file_put_contents($envPath, $envContent)) {
        return ['success' => true];
    }
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
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = trim($_POST['db_port'] ?? '3306');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $appUrl = trim($_POST['app_url'] ?? '');
        $appName = trim($_POST['app_name'] ?? 'VolunteerOps');
        $installType = $_POST['install_type'] ?? 'full';
        $createDb = isset($_POST['create_db']);
        
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
            'domain' => parse_url($appUrl, PHP_URL_HOST) ?? 'localhost'
        ];
        
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
                    }
                } else {
                    $errors[] = "Η βάση δεδομένων '{$dbName}' δεν υπάρχει. Επιλέξτε 'Δημιουργία βάσης' ή δημιουργήστε την χειροκίνητα.";
                }
            }
            
            if (empty($errors)) {
                $step = 3; // Move to step 3
            }
        }
    }
    
    // Step 3: Installation
    if ($step === 3 && isset($_POST['confirm_install'])) {
        $config = $_SESSION['install'] ?? [];
        
        if (empty($config)) {
            $errors[] = "Session expired. Ξεκινήστε ξανά.";
            $step = 1;
        } else {
            // Connect to the specific database
            $dbTest = testDatabaseConnection(
                $config['db_host'], 
                $config['db_port'], 
                $config['db_user'], 
                $config['db_pass'],
                $config['db_name']
            );
            
            if (!$dbTest['success']) {
                $errors[] = "Σφάλμα σύνδεσης στη βάση: " . $dbTest['message'];
                $step = 2;
            } else {
                $pdo = $dbTest['pdo'];
                
                // Import SQL
                $sqlFile = ($config['install_type'] === 'full') ? $fullSqlPath : $schemaPath;
                
                if (!file_exists($sqlFile)) {
                    $errors[] = "Το αρχείο SQL δεν βρέθηκε: " . basename($sqlFile);
                } else {
                    $importResult = importSqlFile($pdo, $sqlFile);
                    
                    if (!$importResult['success']) {
                        $errors[] = "Σφάλμα εισαγωγής SQL: " . $importResult['message'];
                    } else {
                        $tableCount = countTables($pdo);
                        $success[] = "Εισήχθησαν {$tableCount} πίνακες στη βάση δεδομένων";
                    }
                }
                
                if (empty($errors)) {
                    // Generate APP_KEY
                    $config['app_key'] = generateAppKey();
                    
                    // Create .env file
                    $envResult = createEnvFile($basePath, $config);
                    if ($envResult['success']) {
                        $success[] = "Δημιουργήθηκε το αρχείο .env";
                    } else {
                        $errors[] = $envResult['message'];
                    }
                    
                    // Create storage link
                    $linkResult = createStorageLink($basePath);
                    if ($linkResult['success']) {
                        $success[] = $linkResult['message'];
                    } else {
                        $warnings[] = $linkResult['message'];
                    }
                    
                    // Clear cache
                    $clearedCount = clearCache($basePath);
                    $success[] = "Καθαρίστηκε η cache ({$clearedCount} αρχεία)";
                    
                    // Fix directory permissions
                    @chmod($basePath . '/storage', 0755);
                    @chmod($basePath . '/bootstrap/cache', 0755);
                    
                    if (empty($errors)) {
                        $step = 4; // Success!
                        unset($_SESSION['install']);
                    }
                }
            }
        }
    }
}

// Check requirements
$requirements = checkRequirements();
$directories = checkDirectories($basePath);
$vendorExists = checkVendor($basePath);

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
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Προσοχή:</strong> Αν η βάση έχει ήδη πίνακες, μπορεί να αντικατασταθούν!
                        </div>
                        
                        <form method="post">
                            <input type="hidden" name="step" value="3">
                            <input type="hidden" name="confirm_install" value="1">
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-rocket-takeoff me-2"></i>Εγκατάσταση Τώρα
                                </button>
                                <a href="?step=2" class="btn btn-outline-secondary">
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
                        VolunteerOps v1.0 | 
                        <a href="https://github.com/TheoSfak/volunteer-ops" target="_blank" class="text-decoration-none">
                            <i class="bi bi-github me-1"></i>GitHub
                        </a>
                    </small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
