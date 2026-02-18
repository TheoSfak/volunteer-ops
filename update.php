<?php
/**
 * VolunteerOps - GitHub Auto-Update System
 * Full automated updates with backup, migrations, and rollback
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

// ============================================================
// Configuration
// ============================================================
define('GITHUB_REPO', 'TheoSfak/volunteer-ops'); // GitHub repository
define('GITHUB_API_URL', 'https://api.github.com/repos/' . GITHUB_REPO);
define('BACKUP_DIR', __DIR__ . '/backups');
define('UPDATE_LOG_FILE', __DIR__ . '/update.log');

// Error logging for update process
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(300); // 5 minutes max

$pageTitle = 'Ενημέρωση Συστήματος';

// ============================================================
// Helper Functions
// ============================================================

function updateLog($message, $type = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$type}] {$message}\n";
    file_put_contents(UPDATE_LOG_FILE, $logLine, FILE_APPEND);
    return $logLine;
}

function getLatestRelease() {
    $url = GITHUB_API_URL . '/releases/latest';
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: VolunteerOps-Updater',
                'Accept: application/vnd.github.v3+json'
            ],
            'timeout' => 30
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        // Try alternative - get tags
        $tagsUrl = GITHUB_API_URL . '/tags';
        $response = @file_get_contents($tagsUrl, false, $context);
        
        if ($response === false) {
            return ['error' => 'Αδυναμία σύνδεσης με GitHub. Ελέγξτε τη σύνδεση internet.'];
        }
        
        $tags = json_decode($response, true);
        if (empty($tags)) {
            return ['error' => 'Δεν βρέθηκαν εκδόσεις στο repository.'];
        }
        
        return [
            'tag_name' => $tags[0]['name'],
            'name' => $tags[0]['name'],
            'published_at' => null,
            'body' => 'Διαθέσιμη νέα έκδοση',
            'zipball_url' => $tags[0]['zipball_url'],
            'html_url' => 'https://github.com/' . GITHUB_REPO . '/releases/tag/' . $tags[0]['name']
        ];
    }
    
    return json_decode($response, true);
}

function compareVersions($current, $latest) {
    // Remove 'v' prefix if exists
    $current = ltrim($current, 'v');
    $latest = ltrim($latest, 'v');
    
    return version_compare($latest, $current, '>');
}

function createBackup() {
    $backupDir = BACKUP_DIR;
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_His');
    $backupName = "backup_{$timestamp}";
    $backupPath = "{$backupDir}/{$backupName}";
    
    // Create backup directory
    mkdir($backupPath, 0755, true);
    mkdir("{$backupPath}/files", 0755, true);
    
    $results = [
        'timestamp' => $timestamp,
        'path' => $backupPath,
        'files_backed_up' => 0,
        'db_backed_up' => false,
        'errors' => []
    ];
    
    // 1. Backup important files
    updateLog('Δημιουργία backup αρχείων...');
    
    $filesToBackup = [
        'config.local.php',
        'config.php',
        '.htaccess',
    ];
    
    $dirsToBackup = [
        'includes',
        'sql',
        'uploads',
    ];
    
    foreach ($filesToBackup as $file) {
        $source = __DIR__ . '/' . $file;
        if (file_exists($source)) {
            copy($source, "{$backupPath}/files/{$file}");
            $results['files_backed_up']++;
        }
    }
    
    foreach ($dirsToBackup as $dir) {
        $source = __DIR__ . '/' . $dir;
        if (is_dir($source)) {
            copyDirectory($source, "{$backupPath}/files/{$dir}");
            $results['files_backed_up']++;
        }
    }
    
    // 2. Backup Database
    updateLog('Δημιουργία backup βάσης δεδομένων...');
    
    try {
        $dbBackupFile = "{$backupPath}/database.sql";
        $dbDump = exportDatabase();
        
        if ($dbDump) {
            file_put_contents($dbBackupFile, $dbDump);
            $results['db_backed_up'] = true;
            updateLog('Backup βάσης δεδομένων επιτυχές (' . round(strlen($dbDump) / 1024, 2) . ' KB)');
        } else {
            $results['errors'][] = 'Αποτυχία backup βάσης δεδομένων';
        }
    } catch (Exception $e) {
        $results['errors'][] = 'DB Backup Error: ' . $e->getMessage();
        updateLog('Σφάλμα backup DB: ' . $e->getMessage(), 'error');
    }
    
    // 3. Create backup info file
    file_put_contents("{$backupPath}/backup_info.json", json_encode([
        'timestamp' => $timestamp,
        'version' => APP_VERSION,
        'php_version' => PHP_VERSION,
        'files_count' => $results['files_backed_up'],
        'db_included' => $results['db_backed_up']
    ], JSON_PRETTY_PRINT));
    
    updateLog("Backup ολοκληρώθηκε: {$backupName}");
    
    return $results;
}

function copyDirectory($source, $dest) {
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        
        $srcPath = "{$source}/{$file}";
        $destPath = "{$dest}/{$file}";
        
        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $destPath);
        } else {
            copy($srcPath, $destPath);
        }
    }
    closedir($dir);
}

function exportDatabase() {
    $tables = dbFetchAll("SHOW TABLES");
    $dump = "-- VolunteerOps Database Backup\n";
    $dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $dump .= "-- Version: " . APP_VERSION . "\n\n";
    $dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    
    foreach ($tables as $tableRow) {
        $tableName = array_values($tableRow)[0];
        
        // Get CREATE TABLE statement
        $createResult = dbFetchOne("SHOW CREATE TABLE `{$tableName}`");
        $createStmt = $createResult['Create Table'] ?? '';
        
        $dump .= "-- Table: {$tableName}\n";
        $dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        $dump .= $createStmt . ";\n\n";
        
        // Get data
        $rows = dbFetchAll("SELECT * FROM `{$tableName}`");
        
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $columnList = implode('`, `', $columns);
            
            foreach ($rows as $row) {
                $values = array_map(function($val) {
                    if ($val === null) return 'NULL';
                    return "'" . addslashes($val) . "'";
                }, array_values($row));
                
                $dump .= "INSERT INTO `{$tableName}` (`{$columnList}`) VALUES (" . implode(', ', $values) . ");\n";
            }
            $dump .= "\n";
        }
    }
    
    $dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    
    return $dump;
}

function downloadUpdate($zipUrl, $version) {
    updateLog("Λήψη έκδοσης {$version}...");
    
    $tempDir = sys_get_temp_dir() . '/volunteerops_update_' . time();
    $zipFile = $tempDir . '/update.zip';
    
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: VolunteerOps-Updater',
                'Accept: application/vnd.github.v3+json'
            ],
            'timeout' => 120,
            'follow_location' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $zipContent = @file_get_contents($zipUrl, false, $context);
    
    if ($zipContent === false) {
        throw new Exception('Αδυναμία λήψης αρχείου ενημέρωσης');
    }
    
    file_put_contents($zipFile, $zipContent);
    updateLog('Λήψη ολοκληρώθηκε: ' . round(strlen($zipContent) / 1024 / 1024, 2) . ' MB');
    
    return ['temp_dir' => $tempDir, 'zip_file' => $zipFile];
}

function extractUpdate($zipFile, $tempDir) {
    updateLog('Εξαγωγή αρχείων...');
    
    // Check if ZipArchive is available
    if (!class_exists('ZipArchive')) {
        throw new Exception(
            'Η επέκταση PHP zip δεν είναι ενεργοποιημένη. ' .
            'Παρακαλώ ενεργοποιήστε την στο php.ini: ' .
            'Αφαιρέστε το ; από τη γραμμή: ;extension=zip'
        );
    }
    
    $zip = new ZipArchive();
    $result = $zip->open($zipFile);
    
    if ($result !== true) {
        throw new Exception('Αδυναμία ανοίγματος ZIP αρχείου (κωδικός: ' . $result . ')');
    }
    
    $extractDir = $tempDir . '/extracted';
    mkdir($extractDir, 0755, true);
    
    $zip->extractTo($extractDir);
    $zip->close();
    
    // Find the actual content directory (GitHub adds a folder)
    $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
    $contentDir = !empty($dirs) ? $dirs[0] : $extractDir;
    
    updateLog('Εξαγωγή ολοκληρώθηκε');
    
    return $contentDir;
}

function applyUpdate($sourceDir) {
    updateLog('Εφαρμογή ενημέρωσης...');
    
    $targetDir = __DIR__;
    $updated = 0;
    $skipped = 0;
    
    // Files/folders to preserve (never overwrite)
    $preserve = [
        'config.local.php',
        'uploads',
        'backups',
        '.htaccess',
        'update.log',
        'install_errors.log'
    ];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        $relativePath = str_replace($sourceDir . '/', '', $item->getPathname());
        $targetPath = $targetDir . '/' . $relativePath;
        
        // Check if should preserve
        $shouldPreserve = false;
        foreach ($preserve as $p) {
            if (strpos($relativePath, $p) === 0) {
                $shouldPreserve = true;
                break;
            }
        }
        
        if ($shouldPreserve) {
            $skipped++;
            continue;
        }
        
        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            // Create directory if needed
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            copy($item->getPathname(), $targetPath);
            $updated++;
        }
    }
    
    updateLog("Ενημερώθηκαν {$updated} αρχεία, παραλήφθηκαν {$skipped}");
    
    return ['updated' => $updated, 'skipped' => $skipped];
}

function runMigrations() {
    updateLog('Εκτέλεση database migrations...');
    
    $migrationsDir = __DIR__ . '/sql/migrations';
    $executed = 0;
    $errors = [];
    
    if (!is_dir($migrationsDir)) {
        updateLog('Δεν βρέθηκε φάκελος migrations');
        return ['executed' => 0, 'errors' => []];
    }
    
    // Ensure migrations table exists
    dbExecute("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (migration)
    )");
    
    // Get executed migrations
    $executedMigrations = [];
    $rows = dbFetchAll("SELECT migration FROM migrations");
    foreach ($rows as $row) {
        $executedMigrations[] = $row['migration'];
    }
    
    // Get migration files
    $files = glob($migrationsDir . '/*.sql');
    sort($files);
    
    foreach ($files as $file) {
        $migrationName = basename($file);
        
        if (in_array($migrationName, $executedMigrations)) {
            continue;
        }
        
        updateLog("Εκτέλεση migration: {$migrationName}");
        
        try {
            $sql = file_get_contents($file);
            
            // Remove SQL comments (lines starting with --)
            $lines = explode("\n", $sql);
            $cleanLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                    continue;
                }
                $cleanLines[] = $line;
            }
            $cleanSql = implode("\n", $cleanLines);
            
            $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
            $stmtErrors = 0;
            
            foreach ($statements as $stmt) {
                if (!empty($stmt)) {
                    try {
                        dbExecute($stmt);
                    } catch (Exception $stmtEx) {
                        // Log per-statement error but continue with remaining statements
                        $stmtErrors++;
                        $errMsg = $stmtEx->getMessage();
                        // Ignore "already exists" type errors (column/index/table already present)
                        if (stripos($errMsg, 'Duplicate') !== false || 
                            stripos($errMsg, 'already exists') !== false) {
                            updateLog("  Παράλειψη (ήδη υπάρχει): " . substr($stmt, 0, 80));
                        } else {
                            $errors[] = "{$migrationName}: {$errMsg}";
                            updateLog("  Σφάλμα statement: {$errMsg}", 'error');
                        }
                    }
                }
            }
            
            // Mark as executed even if some statements had "already exists" errors
            dbInsert("INSERT INTO migrations (migration) VALUES (?)", [$migrationName]);
            $executed++;
            
        } catch (Exception $e) {
            $errors[] = "{$migrationName}: " . $e->getMessage();
            updateLog("Σφάλμα migration {$migrationName}: " . $e->getMessage(), 'error');
        }
    }
    
    updateLog("Migrations ολοκληρώθηκαν: {$executed} εκτελέστηκαν");
    
    return ['executed' => $executed, 'errors' => $errors];
}

function patchConfigVersion($version) {
    $configFile = __DIR__ . '/config.php';
    
    if (!file_exists($configFile) || !is_writable($configFile)) {
        updateLog('Αδυναμία ενημέρωσης έκδοσης στο config.php (δεν είναι εγγράψιμο)', 'warning');
        return false;
    }
    
    $content = file_get_contents($configFile);
    $cleanVersion = ltrim($version, 'v'); // Remove 'v' prefix if present
    
    // Replace APP_VERSION constant value
    $newContent = preg_replace(
        "/define\s*\(\s*'APP_VERSION'\s*,\s*'[^']*'\s*\)/",
        "define('APP_VERSION', '{$cleanVersion}')",
        $content,
        1,
        $count
    );
    
    if ($count > 0) {
        file_put_contents($configFile, $newContent);
        updateLog("APP_VERSION ενημερώθηκε σε {$cleanVersion}");
        return true;
    } else {
        updateLog('Δεν βρέθηκε η σταθερά APP_VERSION στο config.php', 'warning');
        return false;
    }
}

function getBackups() {
    $backupDir = BACKUP_DIR;
    $backups = [];
    
    if (!is_dir($backupDir)) {
        return [];
    }
    
    $dirs = glob($backupDir . '/backup_*', GLOB_ONLYDIR);
    rsort($dirs); // Most recent first
    
    foreach ($dirs as $dir) {
        $infoFile = $dir . '/backup_info.json';
        $info = file_exists($infoFile) ? json_decode(file_get_contents($infoFile), true) : [];
        
        $backups[] = [
            'name' => basename($dir),
            'path' => $dir,
            'timestamp' => $info['timestamp'] ?? basename($dir),
            'version' => $info['version'] ?? 'Άγνωστη',
            'db_included' => $info['db_included'] ?? false,
            'size' => getDirectorySize($dir)
        ];
    }
    
    return $backups;
}

function getDirectorySize($dir) {
    $size = 0;
    
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    
    return $size;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' bytes';
}

function restoreBackup($backupPath) {
    updateLog("Επαναφορά από backup: " . basename($backupPath));
    
    $results = [
        'files_restored' => 0,
        'db_restored' => false,
        'errors' => []
    ];
    
    // 1. Restore database if exists
    $dbFile = $backupPath . '/database.sql';
    if (file_exists($dbFile)) {
        updateLog('Επαναφορά βάσης δεδομένων...');
        
        try {
            $sql = file_get_contents($dbFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            foreach ($statements as $stmt) {
                if (!empty($stmt) && !preg_match('/^--/', $stmt)) {
                    dbExecute($stmt);
                }
            }
            
            $results['db_restored'] = true;
            updateLog('Βάση δεδομένων επαναφέρθηκε');
        } catch (Exception $e) {
            $results['errors'][] = 'DB Restore Error: ' . $e->getMessage();
            updateLog('Σφάλμα επαναφοράς DB: ' . $e->getMessage(), 'error');
        }
    }
    
    // 2. Restore files
    $filesDir = $backupPath . '/files';
    if (is_dir($filesDir)) {
        updateLog('Επαναφορά αρχείων...');
        copyDirectory($filesDir, __DIR__);
        $results['files_restored'] = 1;
        updateLog('Αρχεία επαναφέρθηκαν');
    }
    
    return $results;
}

function deleteBackup($backupPath) {
    if (!is_dir($backupPath) || strpos($backupPath, BACKUP_DIR) !== 0) {
        return false;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    
    rmdir($backupPath);
    updateLog("Backup διαγράφηκε: " . basename($backupPath));
    
    return true;
}

function getUpdateLog($lines = 50) {
    if (!file_exists(UPDATE_LOG_FILE)) {
        return [];
    }
    
    $allLines = file(UPDATE_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_slice($allLines, -$lines);
}

// ============================================================
// Handle Actions
// ============================================================

$latestRelease = null;
$updateAvailable = false;
$checkError = null;
$actionResult = null;

if (isPost()) {
    verifyCsrf();
    $action = post('action', '');
    
    try {
        switch ($action) {
            case 'check_update':
                // Just refresh page to check
                break;
                
            case 'create_backup':
                $actionResult = createBackup();
                if (empty($actionResult['errors'])) {
                    setFlash('success', 'Το backup δημιουργήθηκε επιτυχώς!');
                } else {
                    setFlash('warning', 'Backup δημιουργήθηκε με προβλήματα: ' . implode(', ', $actionResult['errors']));
                }
                redirect('update.php');
                break;
                
            case 'perform_update':
                $version = post('version', '');
                $zipUrl = post('zip_url', '');
                
                if (empty($version) || empty($zipUrl)) {
                    throw new Exception('Λείπουν παράμετροι ενημέρωσης');
                }
                
                // Step 1: Create backup first
                updateLog("=== ΕΝΑΡΞΗ ΕΝΗΜΕΡΩΣΗΣ σε {$version} ===");
                $backup = createBackup();
                
                if (!$backup['db_backed_up']) {
                    throw new Exception('Αποτυχία δημιουργίας backup βάσης δεδομένων. Η ενημέρωση ακυρώθηκε.');
                }
                
                // Step 2: Download update
                $download = downloadUpdate($zipUrl, $version);
                
                // Step 3: Extract
                $contentDir = extractUpdate($download['zip_file'], $download['temp_dir']);
                
                // Step 4: Apply update
                $updateResult = applyUpdate($contentDir);
                
                // Step 5: Run migrations
                $migrations = runMigrations();
                
                // Step 6: Cleanup
                if (is_dir($download['temp_dir'])) {
                    // Simple cleanup - just remove temp files
                    array_map('unlink', glob($download['temp_dir'] . '/*'));
                    rmdir($download['temp_dir']);
                }
                
                // Step 7: Force-update APP_VERSION in config.php
                patchConfigVersion($version);
                
                updateLog("=== ΕΝΗΜΕΡΩΣΗ ΟΛΟΚΛΗΡΩΘΗΚΕ ===");
                
                setFlash('success', "Η ενημέρωση στην έκδοση {$version} ολοκληρώθηκε επιτυχώς!");
                
                // Clear OPcache if available
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                }
                
                redirect('update.php');
                break;
                
            case 'restore_backup':
                $backupName = post('backup_name', '');
                $backupPath = BACKUP_DIR . '/' . basename($backupName);
                
                if (!is_dir($backupPath)) {
                    throw new Exception('Το backup δεν βρέθηκε');
                }
                
                $restoreResult = restoreBackup($backupPath);
                
                if ($restoreResult['db_restored'] || $restoreResult['files_restored']) {
                    setFlash('success', 'Η επαναφορά ολοκληρώθηκε επιτυχώς!');
                } else {
                    setFlash('error', 'Η επαναφορά απέτυχε');
                }
                redirect('update.php');
                break;
                
            case 'delete_backup':
                $backupName = post('backup_name', '');
                $backupPath = BACKUP_DIR . '/' . basename($backupName);
                
                if (deleteBackup($backupPath)) {
                    setFlash('success', 'Το backup διαγράφηκε');
                } else {
                    setFlash('error', 'Αδυναμία διαγραφής backup');
                }
                redirect('update.php');
                break;
        }
    } catch (Exception $e) {
        updateLog('ΣΦΑΛΜΑ: ' . $e->getMessage(), 'error');
        setFlash('error', $e->getMessage());
        redirect('update.php');
    }
}

// Check for updates
try {
    $latestRelease = getLatestRelease();
    
    if (isset($latestRelease['error'])) {
        $checkError = $latestRelease['error'];
    } else {
        $latestVersion = $latestRelease['tag_name'] ?? '';
        $updateAvailable = !empty($latestVersion) && compareVersions(APP_VERSION, $latestVersion);
    }
} catch (Exception $e) {
    $checkError = $e->getMessage();
}

$backups = getBackups();
$updateLog = getUpdateLog(30);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-cloud-download me-2"></i>Ενημέρωση Συστήματος
    </h1>
    <span class="badge bg-primary fs-6">Τρέχουσα Έκδοση: <?= APP_VERSION ?></span>
</div>

<?= showFlash() ?>

<div class="row">
    <!-- Update Status -->
    <div class="col-lg-8">
        <!-- Current Version & Check -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-arrow-repeat me-1"></i>Έλεγχος Ενημερώσεων</h5>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="check_update">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-clockwise me-1"></i>Έλεγχος Τώρα
                    </button>
                </form>
            </div>
            <div class="card-body">
                <?php if ($checkError): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <?= h($checkError) ?>
                        <hr>
                        <small class="text-muted">
                            Βεβαιωθείτε ότι έχετε ρυθμίσει σωστά το GITHUB_REPO στο αρχείο update.php
                        </small>
                    </div>
                <?php elseif ($updateAvailable): ?>
                    <div class="alert alert-success mb-3">
                        <h5 class="alert-heading">
                            <i class="bi bi-gift me-1"></i>Νέα Έκδοση Διαθέσιμη!
                        </h5>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Τρέχουσα:</strong> <?= APP_VERSION ?><br>
                                <strong>Νέα:</strong> <?= h($latestRelease['tag_name']) ?>
                            </div>
                            <div class="col-md-6">
                                <?php if (!empty($latestRelease['published_at'])): ?>
                                    <strong>Ημ/νία:</strong> <?= date('d/m/Y', strtotime($latestRelease['published_at'])) ?><br>
                                <?php endif; ?>
                                <a href="<?= h($latestRelease['html_url'] ?? '#') ?>" target="_blank" class="text-decoration-none">
                                    <i class="bi bi-github me-1"></i>Δείτε στο GitHub
                                </a>
                            </div>
                        </div>
                        <?php if (!empty($latestRelease['body'])): ?>
                            <hr>
                            <h6>Τι νέο υπάρχει:</h6>
                            <div class="small"><?= nl2br(h(substr($latestRelease['body'], 0, 500))) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Update Button -->
                    <form method="post" id="updateForm">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="perform_update">
                        <input type="hidden" name="version" value="<?= h($latestRelease['tag_name']) ?>">
                        <input type="hidden" name="zip_url" value="<?= h($latestRelease['zipball_url'] ?? '') ?>">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Η ενημέρωση θα:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Δημιουργήσει αυτόματο backup (αρχεία + βάση)</li>
                                <li>Κατεβάσει τη νέα έκδοση από GitHub</li>
                                <li>Εφαρμόσει τις αλλαγές (διατηρώντας τις ρυθμίσεις σας)</li>
                                <li>Εκτελέσει τυχόν database migrations</li>
                            </ol>
                        </div>
                        
                        <button type="button" class="btn btn-success btn-lg" onclick="confirmUpdate()">
                            <i class="bi bi-cloud-download me-1"></i>Ενημέρωση σε <?= h($latestRelease['tag_name']) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Έχετε την τελευταία έκδοση!</h5>
                        <p class="text-muted mb-0">Έκδοση <?= APP_VERSION ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Backups -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-archive me-1"></i>Backups</h5>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-plus-lg me-1"></i>Νέο Backup
                    </button>
                </form>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <p class="text-muted text-center mb-0">Δεν υπάρχουν backups</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Ημερομηνία</th>
                                    <th>Έκδοση</th>
                                    <th>Μέγεθος</th>
                                    <th>Βάση</th>
                                    <th class="text-end">Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td>
                                        <i class="bi bi-folder me-1 text-warning"></i>
                                        <?= h(str_replace('_', ' ', str_replace('backup_', '', $backup['name']))) ?>
                                    </td>
                                    <td><?= h($backup['version']) ?></td>
                                    <td><?= formatBytes($backup['size']) ?></td>
                                    <td>
                                        <?php if ($backup['db_included']): ?>
                                            <span class="badge bg-success">Ναι</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Όχι</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Επαναφορά από αυτό το backup; Η τρέχουσα κατάσταση θα χαθεί.');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="restore_backup">
                                            <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή αυτού του backup;');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-lightning me-1"></i>Γρήγορες Ενέργειες</h5>
            </div>
            <div class="card-body">
                <form method="post" class="d-grid gap-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_backup">
                    <button type="submit" class="btn btn-outline-success">
                        <i class="bi bi-archive me-1"></i>Δημιουργία Backup
                    </button>
                </form>
                <a href="audit.php" class="btn btn-outline-secondary w-100 mt-2">
                    <i class="bi bi-journal-text me-1"></i>Audit Log
                </a>
            </div>
        </div>
        
        <!-- Update Log -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-terminal me-1"></i>Log Ενημερώσεων</h5>
            </div>
            <div class="card-body p-0">
                <div class="bg-dark text-light p-3" style="max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 11px;">
                    <?php if (empty($updateLog)): ?>
                        <span class="text-muted">Δεν υπάρχουν καταγραφές</span>
                    <?php else: ?>
                        <?php foreach ($updateLog as $line): ?>
                            <div class="<?= strpos($line, '[error]') !== false ? 'text-danger' : (strpos($line, '[success]') !== false ? 'text-success' : '') ?>">
                                <?= h($line) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Help -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-question-circle me-1"></i>Βοήθεια</h5>
            </div>
            <div class="card-body small">
                <h6>Πριν την ενημέρωση:</h6>
                <ul>
                    <li>Δημιουργείται αυτόματα backup</li>
                    <li>Οι ρυθμίσεις σας διατηρούνται</li>
                    <li>Τα uploads δεν επηρεάζονται</li>
                </ul>
                
                <h6>Σε περίπτωση προβλήματος:</h6>
                <ul class="mb-0">
                    <li>Επαναφέρετε από backup</li>
                    <li>Ελέγξτε το update.log</li>
                    <li>Επικοινωνήστε για υποστήριξη</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Update Progress Modal -->
<div class="modal fade" id="updateModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cloud-download me-2"></i>Ενημέρωση σε εξέλιξη...</h5>
            </div>
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;"></div>
                <h5 id="updateStatus">Δημιουργία backup...</h5>
                <p class="text-muted mb-0">Παρακαλώ μην κλείσετε το παράθυρο.</p>
                <div class="progress mt-4" style="height: 20px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="updateProgress" style="width: 10%;">10%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmUpdate() {
    if (confirm('Είστε σίγουροι ότι θέλετε να ενημερώσετε το σύστημα;\n\nΘα δημιουργηθεί αυτόματα backup πριν την ενημέρωση.')) {
        // Show progress modal
        const modal = new bootstrap.Modal(document.getElementById('updateModal'));
        modal.show();
        
        // Simulate progress (actual progress would need AJAX)
        let progress = 10;
        const steps = [
            { percent: 25, text: 'Δημιουργία backup...' },
            { percent: 50, text: 'Λήψη νέας έκδοσης...' },
            { percent: 75, text: 'Εφαρμογή ενημέρωσης...' },
            { percent: 90, text: 'Εκτέλεση migrations...' },
            { percent: 100, text: 'Ολοκλήρωση...' }
        ];
        
        let stepIndex = 0;
        const interval = setInterval(() => {
            if (stepIndex < steps.length) {
                document.getElementById('updateProgress').style.width = steps[stepIndex].percent + '%';
                document.getElementById('updateProgress').textContent = steps[stepIndex].percent + '%';
                document.getElementById('updateStatus').textContent = steps[stepIndex].text;
                stepIndex++;
            } else {
                clearInterval(interval);
            }
        }, 1500);
        
        // Submit form
        document.getElementById('updateForm').submit();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
