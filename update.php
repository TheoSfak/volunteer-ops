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

// SSL CA bundle: prefer XAMPP's bundled CA cert, fall back to system default
$sslCaFile = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
define('SSL_VERIFY', file_exists($sslCaFile));
define('SSL_CA_FILE', $sslCaFile);
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
            'verify_peer' => SSL_VERIFY,
            'verify_peer_name' => SSL_VERIFY,
            'cafile' => SSL_CA_FILE
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
    $dump  = "-- VolunteerOps Database Backup\n";
    $dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $dump .= "-- Version: " . APP_VERSION . "\n\n";
    $dump .= "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $pdo = db();

    foreach ($tables as $tableRow) {
        $tableName = array_values($tableRow)[0];

        // Get CREATE TABLE statement
        $createResult = dbFetchOne("SHOW CREATE TABLE `{$tableName}`");
        $createStmt   = $createResult['Create Table'] ?? '';

        $dump .= "-- Table: {$tableName}\n";
        $dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        $dump .= $createStmt . ";\n\n";

        // Get data
        $rows = dbFetchAll("SELECT * FROM `{$tableName}`");

        if (!empty($rows)) {
            $columns    = array_keys($rows[0]);
            $columnList = '`' . implode('`, `', $columns) . '`';

            foreach ($rows as $row) {
                $values = array_map(function ($val) use ($pdo) {
                    if ($val === null) return 'NULL';
                    return $pdo->quote((string)$val);
                }, array_values($row));

                $dump .= "INSERT INTO `{$tableName}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n";
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
            'verify_peer' => SSL_VERIFY,
            'verify_peer_name' => SSL_VERIFY,
            'cafile' => SSL_CA_FILE
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
    updateLog("Source dir: {$sourceDir}");
    updateLog("Target dir: " . __DIR__);
    
    $targetDir = __DIR__;
    $updated = 0;
    $skipped = 0;
    $failed = 0;
    $failedFiles = [];
    
    // Files/folders to preserve (never overwrite)
    $preserve = [
        'config.local.php',
        'uploads',
        'backups',
        '.htaccess',
        'update.log',
        'install_errors.log'
    ];
    
    // Normalize source dir path (remove trailing slash)
    $sourceDir = rtrim($sourceDir, '/\\');
    $sourceDirLen = strlen($sourceDir) + 1; // +1 for the separator
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
        // Use substr for reliable path extraction (works on both Windows & Linux)
        $relativePath = substr($item->getPathname(), $sourceDirLen);
        // Normalize to forward slashes
        $relativePath = str_replace('\\', '/', $relativePath);
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
                if (!@mkdir($targetPath, 0755, true)) {
                    updateLog("ΣΦΑΛΜΑ: Αδυναμία δημιουργίας φακέλου: {$relativePath}", 'error');
                    $failed++;
                }
            }
        } else {
            // Create directory if needed
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            
            // Copy with error checking
            if (@copy($item->getPathname(), $targetPath)) {
                $updated++;
                // Make file writable for future updates
                @chmod($targetPath, 0644);
            } else {
                $failed++;
                $failedFiles[] = $relativePath;
                $err = error_get_last();
                updateLog("ΣΦΑΛΜΑ copy: {$relativePath} — " . ($err['message'] ?? 'unknown error'), 'error');
            }
        }
    }
    
    updateLog("Ενημερώθηκαν {$updated} αρχεία, παραλήφθηκαν {$skipped}, ΑΠΟΤΥΧΙΑ {$failed}");
    
    if ($failed > 0) {
        updateLog("Αρχεία που απέτυχαν: " . implode(', ', array_slice($failedFiles, 0, 20)), 'error');
    }
    
    // Clear OPcache immediately after file copy so PHP sees new files
    if (function_exists('opcache_reset')) {
        opcache_reset();
        updateLog('OPcache cleared μετά την αντιγραφή αρχείων');
    }
    
    return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed, 'failed_files' => $failedFiles];
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
            
            // Parse special directives BEFORE stripping comments
            // -- mkdir: relative/path  → creates directory relative to app root
            foreach (explode("\n", $sql) as $line) {
                if (preg_match('/^--\s*mkdir:\s*(.+)$/i', trim($line), $m)) {
                    $dirPath = __DIR__ . '/' . trim($m[1], '/\\ ');
                    if (!is_dir($dirPath)) {
                        mkdir($dirPath, 0755, true)
                            ? updateLog("  Δημιουργήθηκε φάκελος: " . trim($m[1]))
                            : updateLog("  Αποτυχία δημιουργίας φακέλου: " . trim($m[1]), 'error');
                    } else {
                        updateLog("  Φάκελος ήδη υπάρχει: " . trim($m[1]));
                    }
                }
            }

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
            
            // Quote-aware SQL statement splitter
            // Splits on semicolons only when NOT inside quoted strings
            $allStatements = [];
            $current = '';
            $inSingleQuote = false;
            $len = strlen($cleanSql);
            for ($ci = 0; $ci < $len; $ci++) {
                $ch = $cleanSql[$ci];
                if ($ch === "'" && !$inSingleQuote) {
                    $inSingleQuote = true;
                    $current .= $ch;
                } elseif ($ch === "'" && $inSingleQuote) {
                    // Check for escaped quote ''
                    if ($ci + 1 < $len && $cleanSql[$ci + 1] === "'") {
                        $current .= "''";
                        $ci++;
                    } else {
                        $inSingleQuote = false;
                        $current .= $ch;
                    }
                } elseif ($ch === '\\' && $inSingleQuote && $ci + 1 < $len) {
                    // Backslash escape inside string
                    $current .= $ch . $cleanSql[$ci + 1];
                    $ci++;
                } elseif ($ch === ';' && !$inSingleQuote) {
                    $stmt = trim($current);
                    if (!empty($stmt)) {
                        $allStatements[] = $stmt;
                    }
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            $stmt = trim($current);
            if (!empty($stmt)) {
                $allStatements[] = $stmt;
            }
            $stmtErrors = 0;
            
            foreach ($allStatements as $stmt) {
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
        // Skip incomplete backups (state.json present means not finalized)
        if (file_exists($dir . '/state.json')) continue;

        $infoFile = $dir . '/backup_info.json';
        $info = file_exists($infoFile) ? json_decode(file_get_contents($infoFile), true) : [];

        $backups[] = [
            'name'        => basename($dir),
            'path'        => $dir,
            'timestamp'   => $info['timestamp'] ?? basename($dir),
            'version'     => $info['version'] ?? 'Άγνωστη',
            'db_included' => $info['db_included'] ?? false,
            'skipped'     => $info['skipped_tables'] ?? [],
            'size'        => $info['db_size_bytes'] ?? getDirectorySize($dir),
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
        'db_restored'    => false,
        'errors'         => [],
    ];

    // 1. Restore database with quote-aware parser + transaction
    $dbFile = $backupPath . '/database.sql';
    if (file_exists($dbFile)) {
        updateLog('Επαναφορά βάσης δεδομένων...');

        try {
            $sql = file_get_contents($dbFile);

            // Strip comment-only lines
            $lines    = explode("\n", $sql);
            $filtered = array_filter($lines, fn($l) => !preg_match('/^\s*--/', $l));
            $cleanSql = implode("\n", $filtered);

            // Quote-aware semicolon splitter (handles semicolons inside string values)
            $statements    = [];
            $current       = '';
            $inSingleQuote = false;
            $len           = strlen($cleanSql);
            for ($ci = 0; $ci < $len; $ci++) {
                $ch = $cleanSql[$ci];
                if ($ch === "'" && !$inSingleQuote) {
                    $inSingleQuote = true;
                    $current .= $ch;
                } elseif ($ch === "'" && $inSingleQuote) {
                    if ($ci + 1 < $len && $cleanSql[$ci + 1] === "'") {
                        $current .= "''";
                        $ci++;
                    } else {
                        $inSingleQuote = false;
                        $current .= $ch;
                    }
                } elseif ($ch === '\\' && $inSingleQuote && $ci + 1 < $len) {
                    $current .= $ch . $cleanSql[$ci + 1];
                    $ci++;
                } elseif ($ch === ';' && !$inSingleQuote) {
                    $stmt = trim($current);
                    if (!empty($stmt)) $statements[] = $stmt;
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            $stmt = trim($current);
            if (!empty($stmt)) $statements[] = $stmt;

            // Execute inside a transaction — rollback on any error
            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        $pdo->exec($stmt);
                    }
                }
                $pdo->commit();
                $results['db_restored'] = true;
                updateLog('Βάση δεδομένων επαναφέρθηκε επιτυχώς (' . count($statements) . ' statements)');
            } catch (Exception $innerEx) {
                $pdo->rollBack();
                throw $innerEx;
            }
        } catch (Exception $e) {
            $results['errors'][] = 'DB Restore Error: ' . $e->getMessage();
            updateLog('Σφάλμα επαναφοράς DB (rollback εκτελέστηκε): ' . $e->getMessage(), 'error');
        }
    }

    // 2. Restore files — config.local.php is intentionally excluded to preserve
    //    current DB credentials.
    $filesDir = $backupPath . '/files';
    if (is_dir($filesDir)) {
        updateLog('Επαναφορά αρχείων (εξαιρείται config.local.php)...');
        copyDirectoryExcluding($filesDir, __DIR__, ['config.local.php']);
        $results['files_restored'] = 1;
        updateLog('Αρχεία επαναφέρθηκαν');
    }

    return $results;
}

/**
 * Recursive directory copy, skipping specific filenames at the top level.
 */
function copyDirectoryExcluding(string $source, string $dest, array $excludeFiles = []): void {
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    $dh = opendir($source);
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        if (in_array($file, $excludeFiles, true)) continue;
        $srcPath  = "{$source}/{$file}";
        $destPath = "{$dest}/{$file}";
        if (is_dir($srcPath)) {
            copyDirectoryExcluding($srcPath, $destPath, []);
        } else {
            copy($srcPath, $destPath);
        }
    }
    closedir($dh);
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
                
                // User confirmed they have a backup — proceed directly
                updateLog("=== ΕΝΑΡΞΗ ΕΝΗΜΕΡΩΣΗΣ σε {$version} ===");
                updateLog('Ο χρήστης επιβεβαίωσε ότι έχει λάβει backup.');
                
                // Step 1: Download update
                $download = downloadUpdate($zipUrl, $version);
                
                // Step 3: Extract
                $contentDir = extractUpdate($download['zip_file'], $download['temp_dir']);
                
                // Step 4: Apply update
                $updateResult = applyUpdate($contentDir);
                
                // Check for copy failures
                if (!empty($updateResult['failed']) && $updateResult['failed'] > 0) {
                    updateLog("ΠΡΟΕΙΔΟΠΟΙΗΣΗ: {$updateResult['failed']} αρχεία δεν αντιγράφηκαν!", 'warning');
                }
                updateLog("Αρχεία που ενημερώθηκαν: {$updateResult['updated']}");
                
                // Step 5: Run SQL file migrations
                $migrations = runMigrations();

                // Step 5b: Run PHP schema migrations from the freshly applied migrations.php
                // (the version loaded in memory via bootstrap is the OLD one, so we
                //  re-read the new file, rename the function, and eval it)
                updateLog('Εκτέλεση PHP schema migrations από ενημερωμένο αρχείο...');
                try {
                    $newMigrCode = @file_get_contents(__DIR__ . '/includes/migrations.php');
                    if ($newMigrCode) {
                        // Rename function so PHP won't complain about redeclaration
                        $newMigrCode = preg_replace(
                            '/function\s+runSchemaMigrations\s*\(/',
                            'function _runSchemaMigrations_postupdate(',
                            $newMigrCode
                        );
                        // Remove the function_exists guard (opening if + its closing brace)
                        $newMigrCode = preg_replace(
                            '/if\s*\(\s*!\s*function_exists\s*\([^)]+\)\s*\)\s*\{/',
                            '',
                            $newMigrCode,
                            1
                        );
                        // Remove the closing brace of the function_exists wrapper
                        // It appears as "} // end function_exists check" (or similar comment)
                        $newMigrCode = preg_replace('/\}\s*\/\/\s*end function_exists[^\n]*\n?/i', '', $newMigrCode, 1);
                        // Also strip any auto-call at the bottom (we call it explicitly below)
                        $newMigrCode = preg_replace('/^\s*runSchemaMigrations\s*\(\s*\)\s*;\s*$/m', '', $newMigrCode);
                        // Strip PHP open tag for eval
                        $newMigrCode = preg_replace('/<\?php\b/', '', $newMigrCode);
                        eval($newMigrCode);
                        if (function_exists('_runSchemaMigrations_postupdate')) {
                            _runSchemaMigrations_postupdate();
                            updateLog('PHP schema migrations εκτελέστηκαν επιτυχώς');
                        }
                    }
                } catch (\Throwable $e) {
                    updateLog('PHP schema migrations warning: ' . $e->getMessage(), 'warning');
                }

                // Step 5c: Direct SQL fallback — ensure critical v13 tables/columns exist
                // regardless of whether the eval approach succeeded
                updateLog('Εφαρμογή κρίσιμων SQL migrations (fallback)...');
                try {
                    $fsCol = dbFetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='participation_requests' AND COLUMN_NAME='field_status'");
                    if (!$fsCol) {
                        dbExecute("ALTER TABLE participation_requests ADD COLUMN field_status ENUM('on_way','on_site','needs_help') NULL DEFAULT NULL");
                        updateLog('  + field_status column added');
                    }
                    $fsUpdCol = dbFetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='participation_requests' AND COLUMN_NAME='field_status_updated_at'");
                    if (!$fsUpdCol) {
                        dbExecute("ALTER TABLE participation_requests ADD COLUMN field_status_updated_at TIMESTAMP NULL DEFAULT NULL");
                        updateLog('  + field_status_updated_at column added');
                    }
                    $vpTable = dbFetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='volunteer_pings'");
                    if (!$vpTable) {
                        dbExecute("CREATE TABLE volunteer_pings (
                            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id     INT UNSIGNED NOT NULL,
                            shift_id    INT UNSIGNED NOT NULL,
                            lat         DECIMAL(10,8) NOT NULL,
                            lng         DECIMAL(11,8) NOT NULL,
                            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            CONSTRAINT fk_vp_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
                            CONSTRAINT fk_vp_shift FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
                            INDEX idx_pings_shift_time (shift_id, created_at),
                            INDEX idx_pings_user_shift (user_id, shift_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        updateLog('  + volunteer_pings table created');
                    }
                    // Update schema version to 13 if lower
                    $curVer = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key='db_schema_version'");
                    if ($curVer < 13) {
                        dbExecute("INSERT INTO settings (setting_key,setting_value,updated_at) VALUES ('db_schema_version','13',NOW()) ON DUPLICATE KEY UPDATE setting_value='13',updated_at=NOW()");
                        updateLog('  + db_schema_version set to 13');
                    }
                    updateLog('SQL fallback migrations ολοκληρώθηκαν');
                } catch (\Throwable $e) {
                    updateLog('SQL fallback migrations error: ' . $e->getMessage(), 'error');
                }

                // Step 6: Cleanup temp directory
                if (is_dir($download['temp_dir'])) {
                    $cleanIterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($download['temp_dir'], RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($cleanIterator as $cleanItem) {
                        if ($cleanItem->isDir()) {
                            @rmdir($cleanItem->getPathname());
                        } else {
                            @unlink($cleanItem->getPathname());
                        }
                    }
                    @rmdir($download['temp_dir']);
                    updateLog('Temp directory cleaned up');
                }
                
                // Step 7: Force-update APP_VERSION in config.php
                patchConfigVersion($version);
                
                updateLog("=== ΕΝΗΜΕΡΩΣΗ ΟΛΟΚΛΗΡΩΘΗΚΕ ===");
                
                // Clear OPcache BEFORE redirect so the new files are served
                if (function_exists('opcache_reset')) {
                    opcache_reset();
                    updateLog('OPcache cleared');
                }
                
                setFlash('success', "Η ενημέρωση στην έκδοση {$version} ολοκληρώθηκε επιτυχώς! Ενημερώθηκαν {$updateResult['updated']} αρχεία." . (!empty($updateResult['failed']) ? " ΠΡΟΣΟΧΗ: {$updateResult['failed']} αρχεία απέτυχαν!" : ''));
                
                redirect('update.php');
                break;
                
            case 'download_backup':
                $backupName = post('backup_name', '');
                if (!preg_match('/^backup_[\d_-]+$/', $backupName)) {
                    setFlash('error', 'Μη έγκυρο όνομα backup.');
                    redirect('update.php');
                }
                $backupPath = BACKUP_DIR . '/' . $backupName;
                if (!is_dir($backupPath)) {
                    setFlash('error', 'Το backup δεν βρέθηκε.');
                    redirect('update.php');
                }
                if (!class_exists('ZipArchive')) {
                    setFlash('error', 'Η επέκταση zip δεν είναι ενεργοποιημένη στον server.');
                    redirect('update.php');
                }

                // Read backup_info to know which upload folders were excluded during backup
                $infoFile     = $backupPath . '/backup_info.json';
                $backupInfo   = file_exists($infoFile) ? (json_decode(file_get_contents($infoFile), true) ?: []) : [];
                // Normalise skip folder paths — handle both new format ('uploads/volunteer-docs')
                // and legacy format ('volunteer-docs', relative to uploads/)
                $skippedFolders = array_map(function($f) {
                    $f = rtrim(str_replace('\\', '/', $f), '/');
                    return str_contains($f, '/') ? $f : 'uploads/' . $f;
                }, $backupInfo['skipped_folders'] ?? []);

                // Normalise app root to forward slashes
                $appRoot   = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
                $zipFile   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $backupName . '.zip';

                $zip = new ZipArchive();
                if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    setFlash('error', 'Αδυναμία δημιουργίας zip αρχείου.');
                    redirect('update.php');
                }

                set_time_limit(300); // Up to 5 min for large sites

                $appRootLen  = strlen($appRoot) + 1; // +1 for trailing slash
                $uploadsRoot = $appRoot . '/uploads/';

                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($appRoot, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if (!$file->isFile()) continue;
                    $real = str_replace('\\', '/', $file->getPathname());
                    $relPath = substr($real, $appRootLen);

                    // Skip the backups directory (avoid recursive self-inclusion)
                    if (str_starts_with($relPath, 'backups/')) continue;

                    // Skip config.local.php (host-specific DB credentials)
                    if ($relPath === 'config.local.php') continue;

                    // Skip upload folders that were excluded when this backup was created
                    if (!empty($skippedFolders) && str_starts_with($relPath, 'uploads/')) {
                        foreach ($skippedFolders as $folder) {
                            if (str_starts_with($relPath, $folder . '/') || $relPath === $folder) {
                                continue 2;
                            }
                        }
                    }

                    $zip->addFile($file->getPathname(), $relPath);
                }

                // Add the SQL dump from the backup folder at the zip root
                $sqlDump = $backupPath . '/database.sql';
                if (file_exists($sqlDump)) {
                    $zip->addFile(realpath($sqlDump), 'database.sql');
                }

                $zip->close();

                // Stream zip to browser
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $backupName . '.zip"');
                header('Content-Length: ' . filesize($zipFile));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                ob_end_clean();
                readfile($zipFile);
                unlink($zipFile);
                exit;

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

            case 'mass_delete_backups':
                $names = $_POST['backup_names'] ?? [];
                if (empty($names)) {
                    setFlash('error', 'Δεν επιλέξατε κανένα backup.');
                    redirect('update.php');
                }
                $deleted = 0;
                $failed  = 0;
                foreach ($names as $name) {
                    $path = BACKUP_DIR . '/' . basename($name);
                    if (deleteBackup($path)) { $deleted++; } else { $failed++; }
                }
                if ($failed === 0) {
                    setFlash('success', "Διαγράφηκαν {$deleted} backups επιτυχώς.");
                } else {
                    setFlash('warning', "Διαγράφηκαν {$deleted} backups, {$failed} αποτυχίες.");
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
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            <strong>Προσοχή — Πριν την ενημέρωση:</strong>
                            <ul class="mb-2 mt-2">
                                <li>Βεβαιωθείτε ότι έχετε πάρει <strong>αντίγραφο ασφαλείας</strong> (backup) μόνοι σας</li>
                                <li>Μπορείτε να χρησιμοποιήσετε το κουμπί «Δημιουργία Backup» παρακάτω, ή να κατεβάσετε τα αρχεία χειροκίνητα</li>
                            </ul>
                            <strong>Η ενημέρωση θα:</strong>
                            <ol class="mb-0 mt-1">
                                <li>Κατεβάσει τη νέα έκδοση από GitHub</li>
                                <li>Εφαρμόσει τις αλλαγές (διατηρώντας τις ρυθμίσεις σας)</li>
                                <li>Εκτελέσει τυχόν database migrations</li>
                            </ol>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="backupConfirm" onchange="toggleUpdateBtn()">
                            <label class="form-check-label fw-bold" for="backupConfirm">
                                Επιβεβαιώνω ότι έχω λάβει αντίγραφο ασφαλείας (backup) πριν την ενημέρωση
                            </label>
                        </div>
                        
                        <button type="button" class="btn btn-success btn-lg" id="updateBtn" onclick="confirmUpdate()" disabled>
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
                <button type="button" class="btn btn-sm btn-outline-success" onclick="openBackupModal()">
                    <i class="bi bi-plus-lg me-1"></i>Νέο Backup
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($backups)): ?>
                    <p class="text-muted text-center mb-0">Δεν υπάρχουν backups</p>
                <?php else: ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" id="selectAllBackups">
                            <label class="form-check-label fw-semibold" for="selectAllBackups">Επιλογή όλων</label>
                        </div>
                        <button type="button" id="massDeleteBtn" class="btn btn-sm btn-danger d-none"
                                onclick="massDeleteBackups()">
                            <i class="bi bi-trash me-1"></i>Διαγραφή επιλεγμένων (<span id="selCount">0</span>)
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:36px"></th>
                                    <th>Ημερομηνία</th>
                                    <th>Έκδοση</th>
                                    <th title="Μέγεθος SQL dump">SQL dump</th>
                                    <th>Βάση</th>
                                    <th class="text-end">Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($backups as $backup): ?>
                                <tr>
                                    <td>
                                        <input class="form-check-input backup-checkbox" type="checkbox"
                                               data-name="<?= h($backup['name']) ?>">
                                    </td>
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
                                        <form method="post" class="d-inline" onsubmit="startDownload(this)">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="download_backup">
                                            <input type="hidden" name="backup_name" value="<?= h($backup['name']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Λήψη">
                                                <i class="bi bi-download"></i>
                                            </button>
                                        </form>
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
                    <!-- Hidden form for mass delete — lives OUTSIDE the table forms -->
                    <form method="post" id="massDeleteForm" class="d-none">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="mass_delete_backups">
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <script>
        function startDownload(form) {
            const ov = document.getElementById('downloadOverlay');
            ov.classList.remove('d-none');
            ov.style.display = 'flex';
            // Hide after 90s in case browser pops save dialog without page reload
            setTimeout(() => { ov.classList.add('d-none'); ov.style.display = ''; }, 90000);
        }

        function massDeleteBackups() {
            if (!confirm('Διαγραφή επιλεγμένων backups; Η ενέργεια δεν αναιρείται.')) return;
            const form = document.getElementById('massDeleteForm');
            // Remove any previously injected inputs
            form.querySelectorAll('input[name="backup_names[]"]').forEach(el => el.remove());
            // Inject one hidden input per checked checkbox
            document.querySelectorAll('.backup-checkbox:checked').forEach(function (cb) {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'backup_names[]';
                inp.value = cb.dataset.name;
                form.appendChild(inp);
            });
            form.submit();
        }
        (function () {
            const selectAll = document.getElementById('selectAllBackups');
            const btn       = document.getElementById('massDeleteBtn');
            const counter   = document.getElementById('selCount');
            if (!selectAll) return;
            function updateBtn() {
                const checked = document.querySelectorAll('.backup-checkbox:checked').length;
                counter.textContent = checked;
                btn.classList.toggle('d-none', checked === 0);
            }
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.backup-checkbox').forEach(c => c.checked = this.checked);
                updateBtn();
            });
            document.querySelectorAll('.backup-checkbox').forEach(c => c.addEventListener('change', function () {
                selectAll.checked = document.querySelectorAll('.backup-checkbox').length ===
                                    document.querySelectorAll('.backup-checkbox:checked').length;
                updateBtn();
            }));
        })();
        </script>
    </div>

<!-- Download overlay -->
<div id="downloadOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100"
     style="background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
    <div class="bg-white rounded-3 shadow p-4 text-center mx-auto" style="max-width:340px;width:90%;margin-top:20vh">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem" role="status"></div>
        <h5 class="mb-1">Τα αρχεία ετοιμάζονται&hellip;</h5>
        <p class="text-muted mb-0 small">Παρακαλώ μην κλείσετε την σελίδα. Η λήψη θα αρχίσει αυτόματα.</p>
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
                                <button type="button" class="btn btn-outline-success" onclick="openBackupModal()">
                        <i class="bi bi-archive me-1"></i>Δημιουργία Backup
                    </button>
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
                <h5 id="updateStatus">Λήψη νέας έκδοσης...</h5>
                <p class="text-muted mb-0">Παρακαλώ μην κλείσετε το παράθυρο.</p>
                <div class="progress mt-4" style="height: 20px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="updateProgress" style="width: 10%;">10%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleUpdateBtn() {
    document.getElementById('updateBtn').disabled = !document.getElementById('backupConfirm').checked;
}

function confirmUpdate() {
    if (!document.getElementById('backupConfirm').checked) return;
    
    // Show progress modal
    const modal = new bootstrap.Modal(document.getElementById('updateModal'));
    modal.show();
    
    // Simulate progress (actual progress would need AJAX)
    const steps = [
        { percent: 20, text: 'Λήψη νέας έκδοσης...' },
        { percent: 50, text: 'Εφαρμογή ενημέρωσης...' },
        { percent: 75, text: 'Εκτέλεση migrations...' },
        { percent: 90, text: 'Ολοκλήρωση...' },
        { percent: 100, text: 'Ανακατεύθυνση...' }
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
</script>

<!-- Backup Modal -->
<div class="modal fade" id="backupModal" tabindex="-1" aria-labelledby="backupModalLabel">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="backupModalLabel"><i class="bi bi-archive me-2"></i>Backup</h5>
            </div>
            <div class="modal-body" id="backupModalBody">

                <!-- Step 0: Scanning -->
                <div id="backupScanning" class="text-center py-4">
                    <div class="spinner-border text-success mb-3" role="status" style="width:2.5rem;height:2.5rem"></div>
                    <p class="text-muted mb-0">Σάρωση φακέλων, παρακαλώ περιμένετε...</p>
                </div>

                <!-- Step 1: Options -->
                <div id="backupOptions" class="d-none">
                    <p class="text-muted mb-2 small">Επιλέξτε τι θέλετε να <strong>εξαιρέσετε</strong> από το backup. Τα επιλεγμένα παραλείπονται.</p>

                    <p class="mb-1 mt-2 small fw-semibold text-uppercase text-muted">Πίνακες βάσης δεδομένων</p>
                    <div class="list-group list-group-flush mb-3">
                        <label class="list-group-item d-flex gap-2 py-2">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" id="skip_audit_log" value="audit_log" checked>
                            <span><strong>audit_log</strong><br><small class="text-muted">Ιστορικό ενεργειών &mdash; αναπαράγεται αυτόματα</small></span>
                        </label>
                        <label class="list-group-item d-flex gap-2 py-2">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" id="skip_email_logs" value="email_logs" checked>
                            <span><strong>email_logs</strong><br><small class="text-muted">Παραδοθέντα email &mdash; δεν χρειάζονται για restore</small></span>
                        </label>
                        <label class="list-group-item d-flex gap-2 py-2">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" id="skip_notifications" value="notifications" checked>
                            <span><strong>notifications</strong><br><small class="text-muted">Ειδοποιήσεις &mdash; αναπαράγονται αυτόματα</small></span>
                        </label>
                    </div>

                    <p class="mb-1 mt-2 small fw-semibold text-uppercase text-muted">Φάκελοι &amp; αρχεία</p>
                    <div class="list-group list-group-flush mb-3" id="folderSkipList">
                        <!-- populated dynamically after scan -->
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                        <button type="button" class="btn btn-success" id="startBackupBtn" onclick="startBackup()">
                            <i class="bi bi-play-fill me-1"></i>Έναρξη Backup
                        </button>
                    </div>
                </div>

                <!-- Step 2: Progress -->
                <div id="backupProgress" class="d-none">
                    <div class="progress mb-3" style="height:22px">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             id="backupProgressBar" role="progressbar" style="width:0%">0%</div>
                    </div>
                    <div class="text-center mb-1" id="backupStatusMain" style="font-size:.9rem;font-weight:600">Εκκίνηση...</div>
                    <div class="text-center text-muted" id="backupStatusSub" style="font-size:.8rem"></div>
                </div>

                <!-- Step 3: Done -->
                <div id="backupDone" class="d-none text-center py-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem"></i>
                    <h5 class="mt-2">Το backup ολοκληρώθηκε!</h5>
                    <p class="text-muted" id="backupDoneName"></p>
                </div>

                <!-- Step 4: Error -->
                <div id="backupError" class="d-none">
                    <div class="alert alert-danger mb-2" id="backupErrorMsg"></div>
                    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Κλείσιμο</button>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

function openBackupModal() {
    // Show scanning state, hide everything else
    document.getElementById('backupScanning').classList.remove('d-none');
    document.getElementById('backupOptions').classList.add('d-none');
    document.getElementById('backupProgress').classList.add('d-none');
    document.getElementById('backupDone').classList.add('d-none');
    document.getElementById('backupError').classList.add('d-none');
    setBackupProgress(0, 'Εκκίνηση...', '');
    new bootstrap.Modal(document.getElementById('backupModal')).show();

    // Scan folders then reveal options
    backupAjax('scan')
        .then(data => {
            renderFolderList(data.items || []);
            document.getElementById('backupScanning').classList.add('d-none');
            document.getElementById('backupOptions').classList.remove('d-none');
        })
        .catch(err => {
            document.getElementById('backupScanning').classList.add('d-none');
            document.getElementById('folderSkipList').innerHTML =
                '<div class="list-group-item text-danger small">Αδυναμία σάρωσης: ' + err.message + '</div>';
            document.getElementById('backupOptions').classList.remove('d-none');
        });
}

function formatBytesJS(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576)    return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)       return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

function renderFolderList(items) {
    const list = document.getElementById('folderSkipList');
    list.innerHTML = '';
    if (!items.length) {
        list.innerHTML = '<div class="list-group-item text-muted small text-center">Δεν βρέθηκαν φάκελοι</div>';
        return;
    }
    items.forEach(item => {
        const large = item.size > 10 * 1024 * 1024;
        const badge = large ? '<span class="badge bg-warning text-dark ms-1">⚠ Μεγάλο</span>' : '';

        // uploads/ — show as non-selectable header + per-subfolder checkboxes
        if (item.children !== null && item.children !== undefined) {
            list.insertAdjacentHTML('beforeend',
                `<div class="list-group-item py-2 bg-light d-flex align-items-center gap-2">
                    <i class="bi bi-folder2-open text-warning"></i>
                    <strong>${item.name}/</strong>
                    <small class="text-muted ms-1">${formatBytesJS(item.size)} &mdash; ${item.files} αρχεία σύνολο</small>
                </div>`
            );
            if (!item.children.length) {
                list.insertAdjacentHTML('beforeend',
                    '<div class="list-group-item py-1 ps-5 text-muted small">(κενός φάκελος)</div>'
                );
            }
            item.children.forEach(child => {
                const cl    = child.size > 10 * 1024 * 1024;
                const cbadge = cl ? '<span class="badge bg-warning text-dark ms-1">⚠ Μεγάλο</span>' : '';
                const cid   = 'sfchk_' + child.rel_path.replace(/[^a-z0-9]/gi, '_');
                list.insertAdjacentHTML('beforeend',
                    `<label class="list-group-item d-flex gap-2 py-2 ps-5">
                        <input class="form-check-input flex-shrink-0 mt-0 skip-folder-check" type="checkbox"
                               id="${cid}" value="${child.rel_path}" ${cl ? 'checked' : ''}>
                        <span class="d-flex align-items-center flex-wrap gap-1">
                            <i class="bi bi-folder text-warning"></i>
                            <strong>${child.name}/</strong>
                            <small class="text-muted">${formatBytesJS(child.size)} &mdash; ${child.files} αρχεία</small>
                            ${cbadge}
                        </span>
                    </label>`
                );
            });
            return;
        }

        // Regular folder — no checkbox (code/config, always in backup)
        list.insertAdjacentHTML('beforeend',
            `<div class="list-group-item d-flex align-items-center gap-2 py-2 text-muted">
                <i class="bi bi-folder text-secondary"></i>
                <span>
                    <strong>${item.name}/</strong>
                    <small class="ms-1">${formatBytesJS(item.size)} &mdash; ${item.files} αρχεία</small>
                    ${badge}
                </span>
                <span class="ms-auto badge bg-secondary">Σταθερό</span>
            </div>`
        );
    });
}

function setBackupProgress(pct, main, sub) {
    const bar = document.getElementById('backupProgressBar');
    bar.style.width = pct + '%';
    bar.textContent = pct + '%';
    document.getElementById('backupStatusMain').textContent = main;
    document.getElementById('backupStatusSub').textContent  = sub;
}

async function backupAjax(action, extra = {}) {
    const params = new URLSearchParams({ action, csrf_token: CSRF_TOKEN, ...extra });
    const res    = await fetch('backup-ajax.php', { method: 'POST', body: params });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Αγνωστο σφάλμα');
    return data;
}

async function startBackup() {
    // Collect skipped tables
    const skipTables = ['audit_log', 'email_logs', 'notifications']
        .filter(t => document.getElementById('skip_' + t)?.checked);
    // Collect skipped folders from dynamic checkboxes
    const skipFolders = Array.from(document.querySelectorAll('.skip-folder-check:checked'))
        .map(el => el.value);

    // Switch to progress view
    document.getElementById('backupOptions').classList.add('d-none');
    document.getElementById('backupProgress').classList.remove('d-none');
    // Prevent modal close while running
    const modalEl = document.getElementById('backupModal');
    modalEl.setAttribute('data-bs-backdrop', 'static');
    modalEl.setAttribute('data-bs-keyboard', 'false');

    let backupId = null;
    try {
        // --- INIT ---
        setBackupProgress(2, 'Αρχικοποίηση...', '');
        const skipParam = {};
        skipTables.forEach((t, i) => { skipParam['skip_tables[' + i + ']'] = t; });
        skipFolders.forEach((f, i) => { skipParam['skip_folders[' + i + ']'] = f; });
        const init = await backupAjax('init', skipParam);
        backupId = init.backup_id;

        const tables     = init.tables;
        const totalFiles = init.total_files;
        const totalSteps = tables.length + Math.ceil(totalFiles / 20) + 1; // +1 finalize
        let   step       = 0;

        // --- DUMP TABLES ---
        for (let ti = 0; ti < tables.length; ti++) {
            const table  = tables[ti];
            let   offset = 0;
            let   done   = false;
            while (!done) {
                step++;
                const pct = Math.round((step / totalSteps) * 90);
                setBackupProgress(pct,
                    'Βάση: πίνακας ' + (ti + 1) + '/' + tables.length + ': ' + table,
                    'Αρχεία: σε αναμονή'
                );
                const r = await backupAjax('dump_table', {
                    backup_id:  backupId,
                    table_name: table,
                    offset:     offset,
                });
                done   = r.done;
                offset = r.next_offset;
            }
        }

        // --- COPY FILES ---
        let fileOffset = 0;
        let filesDone  = (totalFiles === 0);
        while (!filesDone) {
            step++;
            const pct = Math.round((step / totalSteps) * 90);
            const r = await backupAjax('copy_batch', {
                backup_id: backupId,
                offset:    fileOffset,
            });
            setBackupProgress(pct,
                'Αρχεία: ' + r.next_offset + ' / ' + totalFiles,
                ''
            );
            filesDone  = r.done;
            fileOffset = r.next_offset;
        }

        // --- FINALIZE ---
        setBackupProgress(97, 'Ολοκλήρωση...', '');
        const fin = await backupAjax('finalize', { backup_id: backupId });

        // Success
        setBackupProgress(100, 'Ολοκληρώθηκε!', '');
        document.getElementById('backupProgress').classList.add('d-none');
        document.getElementById('backupDone').classList.remove('d-none');
        document.getElementById('backupDoneName').textContent = fin.backup_name;
        // Allow modal close, reload after 1.5s
        modalEl.removeAttribute('data-bs-backdrop');
        modalEl.removeAttribute('data-bs-keyboard');
        setTimeout(() => location.reload(), 1500);

    } catch (err) {
        // Try to clean up the incomplete backup
        if (backupId) {
            try { await backupAjax('cancel', { backup_id: backupId }); } catch (_) {}
        }
        document.getElementById('backupProgress').classList.add('d-none');
        document.getElementById('backupError').classList.remove('d-none');
        document.getElementById('backupErrorMsg').textContent = err.message;
        modalEl.removeAttribute('data-bs-backdrop');
        modalEl.removeAttribute('data-bs-keyboard');
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
