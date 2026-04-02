<?php
/**
 * VolunteerOps - Chunked AJAX Backup Endpoint
 *
 * Handles backup creation in small async chunks to avoid PHP memory/timeout
 * limits on large databases and upload folders (shared hosting friendly).
 *
 * Actions (POST):
 *   init        – Create backup directory, write SQL header, return table + file lists.
 *   dump_table  – Append one table's CREATE + INSERT rows (batches of 500) to database.sql.
 *   copy_batch  – Copy 20 files from uploads/ per call.
 *   finalize    – Write backup_info.json, remove state.json (marks backup complete), prune old.
 *   cancel      – Delete incomplete backup directory.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

// Manual CSRF check (cannot use redirect-based verifyCsrf() in an AJAX endpoint)
$postToken    = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($postToken) || !hash_equals($sessionToken, $postToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Άκυρο CSRF token']);
    exit;
}

// Give each chunk enough time; memory kept low since we stream to disk
set_time_limit(60);
ini_set('memory_limit', '128M');

if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', __DIR__ . '/backups');
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'scan':       handleScan();       break;
        case 'init':       handleInit();       break;
        case 'dump_table': handleDumpTable();  break;
        case 'copy_batch': handleCopyBatch();  break;
        case 'finalize':   handleFinalize();   break;
        case 'cancel':     handleCancel();     break;
        default:           respond(false, 'Άγνωστη ενέργεια');
    }
} catch (Throwable $e) {
    respond(false, 'Σφάλμα: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Utility
// ---------------------------------------------------------------------------

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

/**
 * Validate and return the absolute path for a backup ID.
 * Prevents path-traversal attacks.
 */
function backupPath(string $backupId): string
{
    if (!preg_match('/^backup_[\d_-]+$/', $backupId)) {
        throw new \InvalidArgumentException('Άκυρο backup ID');
    }
    return BACKUP_DIR . '/' . $backupId;
}

// ---------------------------------------------------------------------------
// ACTION: init
// ---------------------------------------------------------------------------
function handleInit(): void
{
    // Validate and sanitise skip_tables
    $skipTables = array_values(array_filter(
        (array)($_POST['skip_tables'] ?? []),
        fn($t) => preg_match('/^[a-zA-Z0-9_]+$/', $t)
    ));

    // Validate and sanitise skip_folders (no path traversal allowed)
    $skipFolders = array_values(array_filter(
        (array)($_POST['skip_folders'] ?? []),
        fn($f) => preg_match('/^[a-zA-Z0-9_\-\/]+$/', $f) && !str_contains($f, '..')
    ));

    // Build table list (excluding skipped tables)
    $allTables = dbFetchAll('SHOW TABLES');
    $tables = [];
    foreach ($allTables as $row) {
        $name = array_values($row)[0];
        if (!in_array($name, $skipTables, true)) {
            $tables[] = $name;
        }
    }

    // Collect uploads file paths (respecting skip_folders)
    $uploadsDir    = __DIR__ . '/uploads';
    $uploadsPrefix = realpath($uploadsDir) . DIRECTORY_SEPARATOR;
    $fileList      = [];
    if (is_dir($uploadsDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            if (!empty($skipFolders)) {
                // Relative path from uploads/, normalized to forward slashes
                $relFromUploads = str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsPrefix)));
                foreach ($skipFolders as $folder) {
                    $folder = rtrim($folder, '/');
                    // Accept both 'uploads/volunteer-docs' (new) and 'volunteer-docs' (legacy)
                    $folderRel = str_starts_with($folder, 'uploads/') ? substr($folder, 8) : $folder;
                    if (str_starts_with($relFromUploads, $folderRel . '/') || $relFromUploads === $folderRel) {
                        continue 2;
                    }
                }
            }
            $fileList[] = $file->getPathname();
        }
    }

    // Create backup directory structure
    $backupId = 'backup_' . date('Y-m-d_His');
    $dir      = BACKUP_DIR . '/' . $backupId;
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);
    mkdir($dir,                    0755, true);
    mkdir($dir . '/files',         0755, true);
    mkdir($dir . '/files/uploads', 0755, true);

    // Write SQL file header (streaming — no large strings in RAM later)
    $sqlHeader = "-- VolunteerOps Database Backup\n"
        . '-- Generated: ' . date('Y-m-d H:i:s') . "\n"
        . '-- Version: '   . APP_VERSION          . "\n"
        . '-- Skipped tables: ' . (empty($skipTables) ? 'none' : implode(', ', $skipTables)) . "\n\n"
        . "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    file_put_contents($dir . '/database.sql', $sqlHeader);

    // Copy small static files (config.local.php intentionally excluded)
    foreach (['config.php', '.htaccess'] as $f) {
        $src = __DIR__ . '/' . $f;
        if (file_exists($src)) copy($src, $dir . '/files/' . $f);
    }
    // Copy includes/ and sql/ directories (typically small)
    foreach (['includes', 'sql'] as $d) {
        $src = __DIR__ . '/' . $d;
        if (is_dir($src)) copyDirSimple($src, $dir . '/files/' . $d);
    }

    // Write state.json — its presence marks the backup as INCOMPLETE
    $state = [
        'backup_id'    => $backupId,
        'tables'       => $tables,
        'skip_tables'  => $skipTables,
        'skip_folders' => $skipFolders,
        'file_list'    => $fileList,
        'total_files'  => count($fileList),
        'started_at'   => date('Y-m-d H:i:s'),
    ];
    file_put_contents($dir . '/state.json', json_encode($state, JSON_PRETTY_PRINT));

    respond(true, 'Αρχικοποίηση backup', [
        'backup_id'   => $backupId,
        'tables'      => $tables,
        'total_files' => count($fileList),
    ]);
}

// ---------------------------------------------------------------------------
// ACTION: dump_table
// ---------------------------------------------------------------------------
function handleDumpTable(): void
{
    $backupId  = $_POST['backup_id']  ?? '';
    $tableName = $_POST['table_name'] ?? '';
    $offset    = (int)($_POST['offset'] ?? 0);
    $batchSize = 500;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        respond(false, 'Άκυρο όνομα πίνακα');
    }

    $dir     = backupPath($backupId);
    $sqlFile = $dir . '/database.sql';
    if (!is_dir($dir) || !file_exists($sqlFile)) {
        respond(false, 'Δεν βρέθηκε το backup');
    }

    // Open for append — write directly to disk, no large strings in RAM
    $fh = fopen($sqlFile, 'a');
    if (!$fh) {
        respond(false, 'Αδυναμία εγγραφής στο database.sql');
    }

    // On first chunk write the CREATE TABLE statement
    if ($offset === 0) {
        $createResult = dbFetchOne("SHOW CREATE TABLE `{$tableName}`");
        $createStmt   = $createResult['Create Table'] ?? '';
        fwrite($fh, "-- Table: {$tableName}\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$tableName}`;\n");
        fwrite($fh, $createStmt . ";\n\n");
    }

    // Fetch a batch of rows using bound parameters (avoids injection)
    $stmt = db()->prepare("SELECT * FROM `{$tableName}` LIMIT :batchSize OFFSET :offset");
    $stmt->bindValue(':batchSize', $batchSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset',    $offset,    PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        $pdo        = db();
        $columns    = array_keys($rows[0]);
        $columnList = '`' . implode('`, `', $columns) . '`';

        foreach ($rows as $row) {
            $values = array_map(function ($val) use ($pdo) {
                if ($val === null) return 'NULL';
                return $pdo->quote((string)$val);   // Safe PDO quoting, not addslashes()
            }, array_values($row));
            fwrite($fh, "INSERT INTO `{$tableName}` ({$columnList}) VALUES (" . implode(', ', $values) . ");\n");
        }
    }

    $rowsWritten = count($rows);
    $done        = ($rowsWritten < $batchSize);

    if ($done) {
        fwrite($fh, "\n"); // blank line after each table
    }

    fclose($fh);

    respond(true, "Πίνακας {$tableName}: {$rowsWritten} εγγραφές (offset {$offset})", [
        'done'         => $done,
        'next_offset'  => $offset + $rowsWritten,
        'rows_written' => $rowsWritten,
    ]);
}

// ---------------------------------------------------------------------------
// ACTION: copy_batch
// ---------------------------------------------------------------------------
function handleCopyBatch(): void
{
    $backupId  = $_POST['backup_id'] ?? '';
    $offset    = (int)($_POST['offset'] ?? 0);
    $batchSize = 20;

    $dir = backupPath($backupId);
    if (!is_dir($dir)) {
        respond(false, 'Δεν βρέθηκε το backup');
    }

    $stateFile = $dir . '/state.json';
    if (!file_exists($stateFile)) {
        respond(false, 'Δεν βρέθηκε το state.json');
    }

    $state    = json_decode(file_get_contents($stateFile), true);
    $fileList = $state['file_list'] ?? [];
    $batch    = array_slice($fileList, $offset, $batchSize);

    $uploadsBase = realpath(__DIR__ . '/uploads');
    foreach ($batch as $srcFile) {
        $srcReal  = realpath($srcFile);
        // Security: ensure source file is inside uploads/
        if (!$srcReal || strpos($srcReal, $uploadsBase) !== 0) continue;

        $relative = ltrim(str_replace($uploadsBase, '', $srcReal), DIRECTORY_SEPARATOR . '/\\');
        $destFile = $dir . '/files/uploads/' . $relative;
        $destDir  = dirname($destFile);
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        if (file_exists($srcFile)) copy($srcFile, $destFile);
    }

    $copied     = count($batch);
    $totalDone  = $offset + $copied;
    $totalFiles = count($fileList);
    $done       = ($totalDone >= $totalFiles);

    respond(true, "Αρχεία: {$totalDone} / {$totalFiles}", [
        'done'        => $done,
        'next_offset' => $totalDone,
        'copied'      => $copied,
        'total_files' => $totalFiles,
    ]);
}

// ---------------------------------------------------------------------------
// ACTION: finalize
// ---------------------------------------------------------------------------
function handleFinalize(): void
{
    $backupId = $_POST['backup_id'] ?? '';
    $dir      = backupPath($backupId);

    if (!is_dir($dir)) {
        respond(false, 'Δεν βρέθηκε το backup');
    }

    // Close the SQL file
    $sqlFile = $dir . '/database.sql';
    file_put_contents($sqlFile, "\nSET FOREIGN_KEY_CHECKS = 1;\n", FILE_APPEND);

    // Read state for metadata
    $stateFile = $dir . '/state.json';
    $state     = file_exists($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];

    // Write backup_info.json (same format as old createBackup())
    file_put_contents($dir . '/backup_info.json', json_encode([
        'timestamp'      => $state['started_at'] ?? date('Y-m-d H:i:s'),
        'version'        => APP_VERSION,
        'php_version'    => PHP_VERSION,
        'files_count'    => $state['total_files'] ?? 0,
        'db_included'     => true,
        'skipped_tables'  => $state['skip_tables'] ?? [],
        'skipped_folders' => $state['skip_folders'] ?? [],
        'db_size_bytes'   => file_exists($sqlFile) ? filesize($sqlFile) : 0,
    ], JSON_PRETTY_PRINT));

    // Remove state.json — this marks the backup as COMPLETE
    if (file_exists($stateFile)) unlink($stateFile);

    // Auto-prune: keep only the latest 5 complete backups
    pruneOldBackups(5);

    respond(true, 'Backup ολοκληρώθηκε: ' . $backupId, [
        'backup_name' => $backupId,
    ]);
}

// ---------------------------------------------------------------------------
// ACTION: scan — Returns folder sizes for the backup options preview
// ---------------------------------------------------------------------------
function handleScan(): void
{
    $appRoot = rtrim(__DIR__, '/\\');
    $items   = [];

    // Scan all root-level directories (backups/ is always excluded from backup)
    $dirs = glob($appRoot . '/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);

    foreach ($dirs as $dir) {
        $name = basename($dir);
        if ($name === 'backups') continue;

        [$size, $count] = dirStats($dir);
        $item = [
            'name'     => $name,
            'rel_path' => $name,
            'size'     => $size,
            'files'    => $count,
            'children' => null,
        ];

        // For uploads/: break down by subfolder so admin can exclude individually
        if ($name === 'uploads') {
            $subs = [];
            foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $subdir) {
                [$sSize, $sCount] = dirStats($subdir);
                $subs[] = [
                    'name'     => basename($subdir),
                    'rel_path' => 'uploads/' . basename($subdir),
                    'size'     => $sSize,
                    'files'    => $sCount,
                ];
            }
            usort($subs, fn($a, $b) => $b['size'] - $a['size']);
            $item['children'] = $subs;
        }

        $items[] = $item;
    }

    // Sort by size desc so large folders appear first
    usort($items, fn($a, $b) => $b['size'] - $a['size']);

    respond(true, 'OK', ['items' => $items]);
}

function dirStats(string $dir): array
{
    $size  = 0;
    $count = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size  += $file->getSize();
                $count++;
            }
        }
    } catch (\Throwable) {}
    return [$size, $count];
}

// ---------------------------------------------------------------------------
// ACTION: cancel
// ---------------------------------------------------------------------------
function handleCancel(): void
{
    $backupId = $_POST['backup_id'] ?? '';
    $dir      = backupPath($backupId);

    // Only delete if it's genuinely incomplete (state.json present)
    if (is_dir($dir) && file_exists($dir . '/state.json')) {
        deleteDirectoryRecursive($dir);
        respond(true, 'Backup ακυρώθηκε');
    } else {
        respond(false, 'Δεν βρέθηκε ατελές backup για ακύρωση');
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Keep only the $keep most recent complete backups; delete the rest.
 * Incomplete backups (state.json present) are ignored.
 */
function pruneOldBackups(int $keep): void
{
    $dirs = glob(BACKUP_DIR . '/backup_*', GLOB_ONLYDIR);
    if (!$dirs) return;
    rsort($dirs); // newest first

    $complete = array_values(array_filter($dirs, fn($d) => !file_exists($d . '/state.json')));

    foreach (array_slice($complete, $keep) as $old) {
        if (strpos(realpath($old), realpath(BACKUP_DIR)) === 0) {
            deleteDirectoryRecursive($old);
        }
    }
}

function deleteDirectoryRecursive(string $dir): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copyDirSimple(string $source, string $dest): void
{
    if (!is_dir($dest)) mkdir($dest, 0755, true);
    $dh = opendir($source);
    while (($file = readdir($dh)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $src = "{$source}/{$file}";
        $dst = "{$dest}/{$file}";
        is_dir($src) ? copyDirSimple($src, $dst) : copy($src, $dst);
    }
    closedir($dh);
}
