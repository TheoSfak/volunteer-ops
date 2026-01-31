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
                logDebug('Ξεκινάει ρύθμιση βάσης δεδομένων');
                
                $dbHost = trim($_POST['db_host'] ?? 'localhost');
                $dbPort = trim($_POST['db_port'] ?? '3306');
                $dbName = trim($_POST['db_name'] ?? '');
                $dbUser = trim($_POST['db_user'] ?? '');
                $dbPass = $_POST['db_pass'] ?? '';
                
                logDebug("Παράμετροι: Host={$dbHost}, Port={$dbPort}, DB={$dbName}, User={$dbUser}");
                
                // Test connection
                try {
                    $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                    logDebug("DSN: {$dsn}");
                    
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]);
                    logDebug('Σύνδεση με MySQL επιτυχής', 'success');
                    
                    // Get MySQL version
                    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
                    logDebug("MySQL Version: {$version}", 'info');
                    
                    // Create database if not exists
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    logDebug("Database '{$dbName}' δημιουργήθηκε/υπάρχει", 'success');
                    
                    $pdo->exec("USE `{$dbName}`");
                    
                    // Check if schema.sql exists
                    $schemaFile = __DIR__ . '/sql/schema.sql';
                    if (!file_exists($schemaFile)) {
                        throw new Exception("Το αρχείο schema.sql δεν βρέθηκε: {$schemaFile}");
                    }
                    logDebug('Αρχείο schema.sql βρέθηκε', 'success');
                    
                    // Import schema
                    $sql = file_get_contents($schemaFile);
                    $sql = preg_replace('/^--.*$/m', '', $sql); // Remove comments
                    logDebug('Φόρτωση schema.sql: ' . strlen($sql) . ' bytes');
                    
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
                    
                    logDebug("SQL εκτελέστηκε: {$executed} statements, {$skipped} παραλήφθηκαν", 'success');
                    
                    // Verify tables exist
                    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    logDebug('Πίνακες στη βάση: ' . implode(', ', $tables), 'info');
                    
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
                    $error = 'Σφάλμα σύνδεσης βάσης δεδομένων';
                    $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                }
                break;
                
            case 3: // Admin setup
                logDebug('Ξεκινάει δημιουργία διαχειριστή');
                
                $adminName = trim($_POST['admin_name'] ?? '');
                $adminEmail = trim($_POST['admin_email'] ?? '');
                $adminPass = $_POST['admin_pass'] ?? '';
                $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
                
                logDebug("Admin: {$adminName} <{$adminEmail}>");
                
                if (empty($adminName) || empty($adminEmail) || empty($adminPass)) {
                    $error = 'Όλα τα πεδία είναι υποχρεωτικά.';
                    logDebug($error, 'error');
                } elseif ($adminPass !== $adminPassConfirm) {
                    $error = 'Οι κωδικοί δεν ταιριάζουν.';
                    logDebug($error, 'error');
                } elseif (strlen($adminPass) < 6) {
                    $error = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
                    logDebug($error, 'error');
                } else {
                    $db = $_SESSION['db'];
                    
                    try {
                        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                        ]);
                        logDebug('Σύνδεση με βάση επιτυχής', 'success');
                        
                        // Update or insert admin user
                        $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, password = ? WHERE role = 'SYSTEM_ADMIN' LIMIT 1");
                        $stmt->execute([$adminName, $adminEmail, $hashedPass]);
                        
                        if ($stmt->rowCount() === 0) {
                            // Insert new admin
                            $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 'SYSTEM_ADMIN', 1, NOW(), NOW())");
                            $stmt->execute([$adminName, $adminEmail, $hashedPass]);
                            logDebug('Νέος διαχειριστής δημιουργήθηκε', 'success');
                        } else {
                            logDebug('Διαχειριστής ενημερώθηκε', 'success');
                        }
                        
                        $_SESSION['debug_log'] = array_merge($_SESSION['debug_log'] ?? [], $debugLog);
                        
                        header('Location: install.php?step=4');
                        exit;
                        
                    } catch (PDOException $e) {
                        logDebug('PDO Error: ' . $e->getMessage(), 'error');
                        $error = 'Σφάλμα δημιουργίας διαχειριστή';
                        $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                    }
                }
                break;
                
            case 4: // Demo data & finalize
                logDebug('Ξεκινάει ρύθμιση demo data και config');
                
                $installDemoData = isset($_POST['install_demo_data']);
                $debugMode = isset($_POST['debug_mode']);
                
                $db = $_SESSION['db'];
                
                try {
                    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    
                    if ($installDemoData) {
                        logDebug('Εγκατάσταση demo data...', 'info');
                        installDemoData($pdo);
                        logDebug('Demo data εγκαταστάθηκε επιτυχώς', 'success');
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
                        throw new Exception("Αδυναμία εγγραφής στο {$configPath}");
                    }
                    logDebug('config.local.php δημιουργήθηκε', 'success');
                    
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
                    $error = 'Σφάλμα ολοκλήρωσης εγκατάστασης';
                    $errorDetails = $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString();
                }
                break;
        }
    } catch (Throwable $e) {
        logDebug('Critical Error: ' . $e->getMessage(), 'error');
        $error = 'Κρίσιμο σφάλμα';
        $errorDetails = get_class($e) . ": " . $e->getMessage() . "\n\nFile: " . $e->getFile() . ":" . $e->getLine() . "\n\nTrace:\n" . $e->getTraceAsString();
    }
}

/**
 * Install demo data - volunteers, departments, missions, shifts
 */
function installDemoData(PDO $pdo) {
    logDebug('Δημιουργία demo departments...');
    
    // Demo Departments
    $departments = [
        ['name' => 'Ιατρική Υποστήριξη', 'description' => 'Παροχή πρώτων βοηθειών και ιατρικής φροντίδας'],
        ['name' => 'Διανομή Τροφίμων', 'description' => 'Συλλογή και διανομή τροφίμων σε ευπαθείς ομάδες'],
        ['name' => 'Εκπαίδευση', 'description' => 'Εκπαιδευτικά προγράμματα και υποστήριξη μαθητών'],
        ['name' => 'Περιβάλλον', 'description' => 'Καθαρισμοί, δενδροφυτεύσεις και περιβαλλοντικές δράσεις'],
        ['name' => 'Κοινωνική Μέριμνα', 'description' => 'Υποστήριξη ηλικιωμένων και ατόμων με αναπηρία'],
    ];
    
    $deptIds = [];
    $stmt = $pdo->prepare("INSERT INTO departments (name, description, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    foreach ($departments as $dept) {
        $stmt->execute([$dept['name'], $dept['description']]);
        $deptIds[] = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM departments WHERE name = " . $pdo->quote($dept['name']))->fetchColumn();
    }
    logDebug(count($departments) . ' τμήματα δημιουργήθηκαν', 'success');
    
    // Demo Volunteers
    logDebug('Δημιουργία demo εθελοντών...');
    $volunteers = [
        ['name' => 'Μαρία Παπαδοπούλου', 'email' => 'maria.p@example.gr', 'phone' => '6971234567'],
        ['name' => 'Γιώργος Νικολάου', 'email' => 'giorgos.n@example.gr', 'phone' => '6972345678'],
        ['name' => 'Ελένη Κωνσταντίνου', 'email' => 'eleni.k@example.gr', 'phone' => '6973456789'],
        ['name' => 'Δημήτρης Αλεξίου', 'email' => 'dimitris.a@example.gr', 'phone' => '6974567890'],
        ['name' => 'Αναστασία Γεωργίου', 'email' => 'anastasia.g@example.gr', 'phone' => '6975678901'],
        ['name' => 'Κώστας Ιωάννου', 'email' => 'kostas.i@example.gr', 'phone' => '6976789012'],
        ['name' => 'Σοφία Δημητρίου', 'email' => 'sofia.d@example.gr', 'phone' => '6977890123'],
        ['name' => 'Νίκος Παναγιώτου', 'email' => 'nikos.p@example.gr', 'phone' => '6978901234'],
    ];
    
    $volunteerIds = [];
    $hashedPass = password_hash('demo123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 'VOLUNTEER', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    foreach ($volunteers as $vol) {
        $stmt->execute([$vol['name'], $vol['email'], $vol['phone'], $hashedPass]);
        $volunteerIds[] = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($vol['email']))->fetchColumn();
    }
    logDebug(count($volunteers) . ' εθελοντές δημιουργήθηκαν (κωδικός: demo123)', 'success');
    
    // Demo Shift Leader
    $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, password, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 'SHIFT_LEADER', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->execute(['Πέτρος Αρχηγός', 'leader@example.gr', '6979012345', $hashedPass]);
    $leaderId = $pdo->lastInsertId();
    logDebug('Υπεύθυνος βάρδιας δημιουργήθηκε', 'success');
    
    // Demo Missions
    logDebug('Δημιουργία demo αποστολών...');
    $missions = [
        [
            'title' => 'Διανομή Τροφίμων - Κέντρο Πόλης',
            'description' => 'Διανομή τροφίμων σε οικογένειες που έχουν ανάγκη στο κέντρο της πόλης. Θα χρειαστούμε εθελοντές για τη μεταφορά και διανομή.',
            'location' => 'Πλατεία Συντάγματος, Αθήνα',
            'department_id' => $deptIds[1] ?? 1,
            'status' => 'OPEN',
            'start_date' => date('Y-m-d H:i:s', strtotime('+3 days 09:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('+3 days 14:00')),
        ],
        [
            'title' => 'Καθαρισμός Παραλίας',
            'description' => 'Εθελοντικός καθαρισμός της παραλίας από πλαστικά και σκουπίδια. Παρέχονται γάντια και σακούλες.',
            'location' => 'Παραλία Γλυφάδας',
            'department_id' => $deptIds[3] ?? 1,
            'status' => 'OPEN',
            'start_date' => date('Y-m-d H:i:s', strtotime('+5 days 08:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('+5 days 13:00')),
        ],
        [
            'title' => 'Υποστήριξη Μαθητών - Κέντρο Μελέτης',
            'description' => 'Βοήθεια σε μαθητές Γυμνασίου με τα μαθήματά τους. Χρειαζόμαστε εθελοντές με γνώσεις Μαθηματικών και Φυσικής.',
            'location' => 'Δημοτική Βιβλιοθήκη Αθηνών',
            'department_id' => $deptIds[2] ?? 1,
            'status' => 'DRAFT',
            'start_date' => date('Y-m-d H:i:s', strtotime('+7 days 16:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('+7 days 20:00')),
        ],
        [
            'title' => 'Ιατρείο Αστέγων',
            'description' => 'Παροχή πρώτων βοηθειών και βασικής ιατρικής φροντίδας σε αστέγους. Χρειάζεται ιατρικό/νοσηλευτικό προσωπικό.',
            'location' => 'Κέντρο Αστέγων, Πειραιάς',
            'department_id' => $deptIds[0] ?? 1,
            'status' => 'OPEN',
            'start_date' => date('Y-m-d H:i:s', strtotime('+2 days 18:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('+2 days 22:00')),
        ],
        [
            'title' => 'Επίσκεψη σε Γηροκομείο',
            'description' => 'Συντροφιά και ψυχαγωγία ηλικιωμένων. Περιλαμβάνει επιτραπέζια παιχνίδια, ανάγνωση και συζήτηση.',
            'location' => 'Γηροκομείο Αγία Ειρήνη, Νέα Σμύρνη',
            'department_id' => $deptIds[4] ?? 1,
            'status' => 'OPEN',
            'start_date' => date('Y-m-d H:i:s', strtotime('+4 days 10:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('+4 days 13:00')),
        ],
        [
            'title' => 'Δενδροφύτευση Πάρκου',
            'description' => 'Φύτευση 100 νέων δέντρων στο δημοτικό πάρκο. Παρέχονται εργαλεία και οδηγίες.',
            'location' => 'Πάρκο Τρίτση, Ίλιον',
            'department_id' => $deptIds[3] ?? 1,
            'status' => 'COMPLETED',
            'start_date' => date('Y-m-d H:i:s', strtotime('-10 days 09:00')),
            'end_date' => date('Y-m-d H:i:s', strtotime('-10 days 15:00')),
        ],
    ];
    
    $missionIds = [];
    $stmt = $pdo->prepare("INSERT INTO missions (title, description, location, department_id, status, start_date, end_date, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())");
    foreach ($missions as $mission) {
        $stmt->execute([
            $mission['title'],
            $mission['description'],
            $mission['location'],
            $mission['department_id'],
            $mission['status'],
            $mission['start_date'],
            $mission['end_date']
        ]);
        $missionIds[] = $pdo->lastInsertId();
    }
    logDebug(count($missions) . ' αποστολές δημιουργήθηκαν', 'success');
    
    // Demo Shifts
    logDebug('Δημιουργία demo βαρδιών...');
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
                $i === 0 ? 'Πρωινή βάρδια' : 'Απογευματινή βάρδια'
            ]);
            $shiftIds[] = $pdo->lastInsertId();
            $shiftCount++;
        }
    }
    logDebug("{$shiftCount} βάρδιες δημιουργήθηκαν", 'success');
    
    // Demo Participation Requests
    logDebug('Δημιουργία demo αιτήσεων συμμετοχής...');
    $participationCount = 0;
    $statuses = ['PENDING', 'APPROVED', 'APPROVED', 'APPROVED']; // More approved than pending
    
    foreach ($shiftIds as $shiftId) {
        // 2-4 volunteers per shift
        $numVolunteers = rand(2, 4);
        $selectedVolunteers = array_rand(array_flip($volunteerIds), min($numVolunteers, count($volunteerIds)));
        if (!is_array($selectedVolunteers)) $selectedVolunteers = [$selectedVolunteers];
        
        foreach ($selectedVolunteers as $volId) {
            $status = $statuses[array_rand($statuses)];
            $stmt = $pdo->prepare("INSERT INTO participation_requests (shift_id, volunteer_id, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE status = status");
            $stmt->execute([$shiftId, $volId, $status]);
            $participationCount++;
        }
    }
    logDebug("{$participationCount} αιτήσεις συμμετοχής δημιουργήθηκαν", 'success');
    
    // Demo Points for completed mission
    logDebug('Δημιουργία demo πόντων...');
    foreach (array_slice($volunteerIds, 0, 4) as $volId) {
        $points = rand(50, 200);
        $stmt = $pdo->prepare("INSERT INTO volunteer_points (user_id, points, reason, description, pointable_type, pointable_id, created_at) VALUES (?, ?, 'Ολοκλήρωση αποστολής', ?, 'mission', ?, NOW()) ON DUPLICATE KEY UPDATE points = points");
        $stmt->execute([$volId, $points, $missionIds[5] ?? 1, 'Συμμετοχή σε αποστολή']);
        
        // Update user total points
        $pdo->exec("UPDATE users SET total_points = total_points + {$points} WHERE id = {$volId}");
    }
    logDebug('Πόντοι εθελοντών ενημερώθηκαν', 'success');
    
    logDebug('=== DEMO DATA ΟΛΟΚΛΗΡΩΘΗΚΕ ===', 'success');
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
    <title>Εγκατάσταση - VolunteerOps</title>
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
            <p class="mb-0 opacity-75">Οδηγός Εγκατάστασης</p>
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
                <h4 class="mb-4"><i class="bi bi-gear me-2"></i>Βήμα 1: Έλεγχος Απαιτήσεων</h4>
                
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
                    <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Πληροφορίες Συστήματος</h6>
                    <small>
                        <strong>PHP:</strong> <?= PHP_VERSION ?><br>
                        <strong>Server:</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?><br>
                        <strong>OS:</strong> <?= PHP_OS ?><br>
                        <strong>Memory Limit:</strong> <?= ini_get('memory_limit') ?>
                    </small>
                </div>
                
                <?php if ($allPassed): ?>
                    <a href="install.php?step=2" class="btn btn-primary w-100 btn-lg">
                        Συνέχεια <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Παρακαλώ διορθώστε τα παραπάνω προβλήματα πριν συνεχίσετε.
                    </div>
                    <a href="install.php" class="btn btn-secondary w-100">
                        <i class="bi bi-arrow-clockwise me-1"></i>Επανέλεγχος
                    </a>
                <?php endif; ?>
                
            <?php elseif ($step === 2): ?>
                <!-- Step 2: Database Configuration -->
                <h4 class="mb-4"><i class="bi bi-database me-2"></i>Βήμα 2: Ρύθμιση Βάσης Δεδομένων</h4>
                
                <form method="post">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Database Host</label>
                            <input type="text" class="form-control" name="db_host" value="localhost" required>
                            <div class="form-text">Συνήθως: localhost ή 127.0.0.1</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port</label>
                            <input type="text" class="form-control" name="db_port" value="3306" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" name="db_name" value="volunteer_ops" required>
                        <div class="form-text">Η βάση θα δημιουργηθεί αυτόματα αν δεν υπάρχει.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Database Username</label>
                        <input type="text" class="form-control" name="db_user" value="root" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Database Password</label>
                        <input type="password" class="form-control" name="db_pass">
                        <div class="form-text">Αφήστε κενό αν δεν υπάρχει κωδικός (π.χ. τοπικό XAMPP).</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="install.php?step=1" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Πίσω
                        </a>
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            Σύνδεση & Εγκατάσταση Σχήματος <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 3): ?>
                <!-- Step 3: Admin Setup -->
                <h4 class="mb-4"><i class="bi bi-person-badge me-2"></i>Βήμα 3: Δημιουργία Διαχειριστή</h4>
                
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Ονοματεπώνυμο</label>
                        <input type="text" class="form-control" name="admin_name" required placeholder="π.χ. Γιάννης Παπαδόπουλος">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="admin_email" required placeholder="admin@example.gr">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Κωδικός</label>
                        <input type="password" class="form-control" name="admin_pass" minlength="6" required>
                        <div class="form-text">Τουλάχιστον 6 χαρακτήρες.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Επιβεβαίωση Κωδικού</label>
                        <input type="password" class="form-control" name="admin_pass_confirm" required>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="install.php?step=2" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Πίσω
                        </a>
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            Συνέχεια <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </form>
                
            <?php elseif ($step === 4): ?>
                <!-- Step 4: Demo Data & Options -->
                <h4 class="mb-4"><i class="bi bi-sliders me-2"></i>Βήμα 4: Ρυθμίσεις & Demo Data</h4>
                
                <form method="post">
                    <!-- Demo Data Option -->
                    <div class="demo-feature border border-primary">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="install_demo_data" id="demoData" checked>
                            <label class="form-check-label fw-bold" for="demoData">
                                <i class="bi bi-box-seam me-1"></i>Εγκατάσταση Demo Data
                            </label>
                        </div>
                        <p class="text-muted small mb-3 mt-2">
                            Προσθέτει δοκιμαστικά δεδομένα για να δείτε πώς λειτουργεί η εφαρμογή.
                        </p>
                        
                        <div class="row text-center g-2" id="demoDetails">
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-people text-primary demo-feature-icon"></i>
                                    <div class="small"><strong>8</strong> Εθελοντές</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-flag text-success demo-feature-icon"></i>
                                    <div class="small"><strong>6</strong> Αποστολές</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="bg-white rounded p-2">
                                    <i class="bi bi-clock text-warning demo-feature-icon"></i>
                                    <div class="small"><strong>8+</strong> Βάρδιες</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Κωδικός demo εθελοντών:</strong> demo123
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
                            Εμφανίζει αναλυτικά μηνύματα σφαλμάτων. <strong class="text-danger">Απενεργοποιήστε το σε παραγωγή!</strong>
                        </p>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <a href="install.php?step=3" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Πίσω
                        </a>
                        <button type="submit" class="btn btn-success flex-grow-1 btn-lg">
                            <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση Εγκατάστασης
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
                    <h4 class="mb-3">Η εγκατάσταση ολοκληρώθηκε επιτυχώς!</h4>
                    
                    <?php 
                    $summary = $_SESSION['install_summary'] ?? [];
                    ?>
                    
                    <?php if (!empty($summary['demo_data'])): ?>
                        <div class="alert alert-success text-start">
                            <strong><i class="bi bi-box-seam me-1"></i>Demo Data εγκαταστάθηκε!</strong>
                            <ul class="mb-0 mt-2 small">
                                <li>8 εθελοντές (κωδικός: <code>demo123</code>)</li>
                                <li>6 αποστολές σε διάφορες καταστάσεις</li>
                                <li>Βάρδιες με αιτήσεις συμμετοχής</li>
                                <li>5 τμήματα οργάνωσης</li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($summary['debug_mode'])): ?>
                        <div class="alert alert-warning text-start">
                            <strong><i class="bi bi-bug me-1"></i>Debug Mode ενεργό</strong>
                            <p class="mb-0 small">Τα σφάλματα θα εμφανίζονται αναλυτικά. Απενεργοποιήστε το πριν βγείτε σε παραγωγή.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-danger text-start">
                        <strong><i class="bi bi-exclamation-triangle me-1"></i>Σημαντικό - Κάντε αυτά τώρα:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Διαγράψτε το αρχείο <code>install.php</code></strong> για ασφάλεια</li>
                            <li>Ελέγξτε τα δικαιώματα του φακέλου <code>uploads/</code></li>
                            <li>Σε παραγωγή: απενεργοποιήστε DEBUG_MODE στο <code>config.local.php</code></li>
                        </ul>
                    </div>
                    
                    <a href="login.php" class="btn btn-success btn-lg">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Σύνδεση στο VolunteerOps
                    </a>
                    
                    <?php if (!empty($debugLog)): ?>
                        <div class="mt-4">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#debugLog">
                                <i class="bi bi-terminal me-1"></i>Προβολή Debug Log
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
                        <i class="bi bi-terminal me-1"></i>Προβολή Debug Log (<?= count($debugLog) ?> entries)
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
