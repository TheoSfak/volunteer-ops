<?php
/**
 * VolunteerOps - Web Installer
 * 
 * Χρήση: Ανεβάστε τα αρχεία και επισκεφθείτε https://yourdomain.com/install.php
 * ΔΙΑΓΡΑΨΤΕ ΑΥΤΟ ΤΟ ΑΡΧΕΙΟ ΜΕΤΑ ΤΗΝ ΕΓΚΑΤΑΣΤΑΣΗ!
 */

// Security check - block if already installed
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    if (strpos($envContent, 'APP_INSTALLED=true') !== false) {
        die('<h1 style="color:red;">⚠️ Η εφαρμογή είναι ήδη εγκατεστημένη!</h1><p>Διαγράψτε το install.php για λόγους ασφαλείας.</p>');
    }
}

$errors = [];
$success = [];
$step = isset($_POST['step']) ? (int)$_POST['step'] : 0;

// Check requirements
function checkRequirements() {
    $requirements = [];
    $requirements['PHP 8.2+'] = version_compare(PHP_VERSION, '8.2.0', '>=');
    $requirements['PDO MySQL'] = extension_loaded('pdo_mysql');
    $requirements['OpenSSL'] = extension_loaded('openssl');
    $requirements['Mbstring'] = extension_loaded('mbstring');
    $requirements['Tokenizer'] = extension_loaded('tokenizer');
    $requirements['JSON'] = extension_loaded('json');
    $requirements['Ctype'] = extension_loaded('ctype');
    $requirements['XML'] = extension_loaded('xml');
    $requirements['Fileinfo'] = extension_loaded('fileinfo');
    $requirements['BCMath'] = extension_loaded('bcmath');
    $requirements['Storage Writable'] = is_writable(__DIR__ . '/../storage');
    $requirements['Cache Writable'] = is_writable(__DIR__ . '/../bootstrap/cache');
    return $requirements;
}

// Generate APP_KEY
function generateAppKey() {
    return 'base64:' . base64_encode(random_bytes(32));
}

// Create storage link
function createStorageLink() {
    $target = __DIR__ . '/../storage/app/public';
    $link = __DIR__ . '/storage';
    
    if (file_exists($link) || is_link($link)) {
        return ['success' => true, 'message' => 'Το storage link υπάρχει ήδη'];
    }
    
    // Try symlink first
    if (@symlink($target, $link)) {
        return ['success' => true, 'message' => 'Storage link δημιουργήθηκε'];
    }
    
    // If symlink fails, try copying (not ideal but works on some hosts)
    if (!file_exists($target)) {
        @mkdir($target, 0755, true);
    }
    
    return ['success' => false, 'message' => 'Δημιουργήστε χειροκίνητα symlink: ln -s ../storage/app/public public/storage'];
}

// Test database connection
function testDatabase($host, $port, $database, $username, $password) {
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database}";
        $pdo = new PDO($dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Check if database has tables
function databaseHasTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    return $stmt->rowCount() > 0;
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if ($step === 2) {
        // Step 2: Save configuration
        $dbHost = $_POST['db_host'] ?? 'localhost';
        $dbPort = $_POST['db_port'] ?? '3306';
        $dbName = $_POST['db_name'] ?? '';
        $dbUser = $_POST['db_user'] ?? '';
        $dbPass = $_POST['db_pass'] ?? '';
        $appUrl = $_POST['app_url'] ?? '';
        $appName = $_POST['app_name'] ?? 'VolunteerOps';
        
        // Test connection
        $dbTest = testDatabase($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        
        if (!$dbTest['success']) {
            $errors[] = "Σφάλμα σύνδεσης βάσης: " . $dbTest['message'];
            $step = 1;
        } else {
            // Check if tables exist
            if (!databaseHasTables($dbTest['pdo'])) {
                $errors[] = "Η βάση δεδομένων είναι άδεια! Εισάγετε πρώτα το schema.sql ή volunteer_ops_full.sql";
                $step = 1;
            } else {
                // Generate key and create .env
                $appKey = generateAppKey();
                
                $envContent = "APP_NAME=\"{$appName}\"
APP_ENV=production
APP_KEY={$appKey}
APP_DEBUG=false
APP_URL={$appUrl}
APP_INSTALLED=true

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_DATABASE={$dbName}
DB_USERNAME={$dbUser}
DB_PASSWORD={$dbPass}

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

SANCTUM_STATEFUL_DOMAINS=" . parse_url($appUrl, PHP_URL_HOST) . "
";
                
                if (file_put_contents($envPath, $envContent)) {
                    $success[] = "Αρχείο .env δημιουργήθηκε επιτυχώς";
                } else {
                    $errors[] = "Αδυναμία δημιουργίας .env - ελέγξτε τα δικαιώματα";
                }
                
                // Create storage link
                $linkResult = createStorageLink();
                if ($linkResult['success']) {
                    $success[] = $linkResult['message'];
                } else {
                    $errors[] = $linkResult['message'];
                }
                
                // Clear caches
                $cacheDirs = [
                    __DIR__ . '/../bootstrap/cache',
                    __DIR__ . '/../storage/framework/cache/data',
                    __DIR__ . '/../storage/framework/views',
                    __DIR__ . '/../storage/framework/sessions',
                ];
                
                foreach ($cacheDirs as $dir) {
                    if (is_dir($dir)) {
                        $files = glob($dir . '/*');
                        foreach ($files as $file) {
                            if (is_file($file) && basename($file) !== '.gitignore') {
                                @unlink($file);
                            }
                        }
                    }
                }
                $success[] = "Cache καθαρίστηκε";
                
                if (empty($errors)) {
                    $step = 3;
                } else {
                    $step = 1;
                }
            }
        }
    }
}

$requirements = checkRequirements();
$allRequirementsMet = !in_array(false, $requirements, true);

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
        body { background: linear-gradient(135deg, #1a237e 0%, #0d47a1 100%); min-height: 100vh; }
        .install-card { max-width: 600px; margin: 50px auto; }
        .step-indicator { display: flex; justify-content: center; margin-bottom: 30px; }
        .step { width: 40px; height: 40px; border-radius: 50%; background: #e0e0e0; display: flex; align-items: center; justify-content: center; margin: 0 10px; font-weight: bold; }
        .step.active { background: #1976d2; color: white; }
        .step.completed { background: #4caf50; color: white; }
        .requirement-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-card">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white text-center py-4">
                    <h2><i class="bi bi-people-fill me-2"></i>VolunteerOps</h2>
                    <p class="mb-0">Οδηγός Εγκατάστασης</p>
                </div>
                
                <div class="card-body p-4">
                    <!-- Step Indicator -->
                    <div class="step-indicator">
                        <div class="step <?= $step >= 1 ? 'completed' : ($step === 0 ? 'active' : '') ?>">1</div>
                        <div class="step <?= $step >= 2 ? 'completed' : ($step === 1 ? 'active' : '') ?>">2</div>
                        <div class="step <?= $step === 3 ? 'active completed' : '' ?>">3</div>
                    </div>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success) && $step !== 3): ?>
                        <div class="alert alert-success">
                            <ul class="mb-0">
                                <?php foreach ($success as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($step === 0): ?>
                        <!-- Step 1: Requirements Check -->
                        <h4 class="mb-3"><i class="bi bi-check-circle me-2"></i>Έλεγχος Απαιτήσεων</h4>
                        
                        <div class="requirements-list mb-4">
                            <?php foreach ($requirements as $name => $met): ?>
                                <div class="requirement-item">
                                    <span><?= $name ?></span>
                                    <?php if ($met): ?>
                                        <span class="text-success"><i class="bi bi-check-circle-fill"></i></span>
                                    <?php else: ?>
                                        <span class="text-danger"><i class="bi bi-x-circle-fill"></i></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($allRequirementsMet): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i>Όλες οι απαιτήσεις πληρούνται!
                            </div>
                            <form method="post">
                                <input type="hidden" name="step" value="1">
                                <button type="submit" class="btn btn-primary w-100">
                                    Συνέχεια <i class="bi bi-arrow-right"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i>Διορθώστε τα προβλήματα πριν συνεχίσετε.
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($step === 1): ?>
                        <!-- Step 2: Database Configuration -->
                        <h4 class="mb-3"><i class="bi bi-database me-2"></i>Ρύθμιση Βάσης Δεδομένων</h4>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Σημαντικό:</strong> Πρέπει να έχετε ήδη εισάγει το <code>schema.sql</code> ή <code>volunteer_ops_full.sql</code> στη βάση σας μέσω phpMyAdmin.
                        </div>
                        
                        <form method="post">
                            <input type="hidden" name="step" value="2">
                            
                            <div class="mb-3">
                                <label class="form-label">Όνομα Εφαρμογής</label>
                                <input type="text" name="app_name" class="form-control" value="VolunteerOps" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">URL Εφαρμογής</label>
                                <input type="url" name="app_url" class="form-control" placeholder="https://yourdomain.com" value="<?= 'https://' . $_SERVER['HTTP_HOST'] ?>" required>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-8 mb-3">
                                    <label class="form-label">Database Host</label>
                                    <input type="text" name="db_host" class="form-control" value="localhost" required>
                                </div>
                                <div class="col-4 mb-3">
                                    <label class="form-label">Port</label>
                                    <input type="text" name="db_port" class="form-control" value="3306" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Όνομα Βάσης</label>
                                <input type="text" name="db_name" class="form-control" placeholder="volunteer_ops" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Χρήστης Βάσης</label>
                                <input type="text" name="db_user" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Κωδικός Βάσης</label>
                                <input type="password" name="db_pass" class="form-control">
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-gear me-2"></i>Εγκατάσταση
                            </button>
                        </form>
                        
                    <?php elseif ($step === 3): ?>
                        <!-- Step 3: Complete -->
                        <div class="text-center">
                            <div class="mb-4">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 80px;"></i>
                            </div>
                            <h4 class="text-success mb-3">Η εγκατάσταση ολοκληρώθηκε!</h4>
                            
                            <div class="alert alert-warning text-start">
                                <h6><i class="bi bi-exclamation-triangle me-2"></i>Σημαντικά βήματα:</h6>
                                <ol class="mb-0">
                                    <li><strong>Διαγράψτε αυτό το αρχείο:</strong> <code>public/install.php</code></li>
                                    <li>Αλλάξτε τους προεπιλεγμένους κωδικούς</li>
                                </ol>
                            </div>
                            
                            <div class="card bg-light mb-4">
                                <div class="card-body text-start">
                                    <h6>Προεπιλεγμένοι λογαριασμοί:</h6>
                                    <table class="table table-sm mb-0">
                                        <tr>
                                            <td><strong>Admin:</strong></td>
                                            <td>admin@volunteerops.gr</td>
                                            <td>password123</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Volunteer:</strong></td>
                                            <td>volunteer@volunteerops.gr</td>
                                            <td>password123</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <a href="/" class="btn btn-success btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Είσοδος στην εφαρμογή
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card-footer text-center text-muted">
                    <small>VolunteerOps v1.0 | <a href="https://github.com/TheoSfak/volunteer-ops" target="_blank">GitHub</a></small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
