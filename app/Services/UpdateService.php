<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class UpdateService
{
    /**
     * Τρέχουσα έκδοση της εφαρμογής
     */
    const CURRENT_VERSION = '1.3.0';
    
    /**
     * GitHub repository
     */
    const GITHUB_REPO = 'TheoSfak/volunteer-ops';
    
    /**
     * Cache duration σε λεπτά
     */
    const CACHE_DURATION = 60; // 1 ώρα

    /**
     * Φάκελοι/αρχεία που ΔΕΝ πρέπει να αντικατασταθούν κατά το update
     */
    const PROTECTED_PATHS = [
        '.env',
        'storage/logs',
        'storage/app/public',
        'storage/framework/sessions',
        'bootstrap/cache',
        'public/storage',
    ];

    /**
     * Φάκελοι που πρέπει να ενημερωθούν
     */
    const UPDATE_PATHS = [
        'app',
        'config',
        'database/migrations',
        'database/seeders',
        'resources/views',
        'routes',
        'public/css',
        'public/js',
    ];

    /**
     * Επιστρέφει την τρέχουσα έκδοση
     */
    public function getCurrentVersion(): string
    {
        return self::CURRENT_VERSION;
    }

    /**
     * Ελέγχει αν υπάρχει νέα έκδοση στο GitHub
     */
    public function checkForUpdates(): array
    {
        $cacheKey = 'volunteerops_update_check';
        
        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () {
            try {
                // Χρήση file_get_contents για συμβατότητα (χωρίς Guzzle dependency)
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => [
                            'User-Agent: VolunteerOps/' . self::CURRENT_VERSION,
                            'Accept: application/vnd.github.v3+json',
                        ],
                        'timeout' => 10,
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                ]);
                
                $url = "https://api.github.com/repos/" . self::GITHUB_REPO . "/releases/latest";
                $response = @file_get_contents($url, false, $context);
                
                if ($response === false) {
                    return [
                        'success' => false,
                        'current_version' => self::CURRENT_VERSION,
                        'error' => 'Αδυναμία σύνδεσης με το GitHub',
                    ];
                }
                
                $release = json_decode($response, true);
                
                if (!$release || !isset($release['tag_name'])) {
                    return [
                        'success' => false,
                        'current_version' => self::CURRENT_VERSION,
                        'error' => 'Μη έγκυρη απάντηση από το GitHub',
                    ];
                }
                
                $latestVersion = ltrim($release['tag_name'] ?? '', 'v');
                
                // Βρες το ZIP asset
                $downloadUrl = null;
                $assetSize = 0;
                foreach ($release['assets'] ?? [] as $asset) {
                    if (str_contains($asset['name'], '.zip')) {
                        $downloadUrl = $asset['browser_download_url'];
                        $assetSize = $asset['size'];
                        break;
                    }
                }
                
                // Αν δεν βρέθηκε asset, χρησιμοποίησε το zipball
                if (!$downloadUrl) {
                    $downloadUrl = $release['zipball_url'] ?? null;
                }
                
                return [
                    'success' => true,
                    'current_version' => self::CURRENT_VERSION,
                    'latest_version' => $latestVersion,
                    'has_update' => version_compare($latestVersion, self::CURRENT_VERSION, '>'),
                    'release_name' => $release['name'] ?? "v{$latestVersion}",
                    'release_notes' => $release['body'] ?? '',
                    'release_date' => $release['published_at'] ?? null,
                    'download_url' => $downloadUrl,
                    'download_size' => $assetSize,
                    'html_url' => $release['html_url'] ?? null,
                ];
                
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'current_version' => self::CURRENT_VERSION,
                    'error' => 'Σφάλμα: ' . $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Καθαρίζει το cache του update check
     */
    public function clearCache(): void
    {
        Cache::forget('volunteerops_update_check');
    }

    /**
     * Λήψη πληροφοριών για όλες τις διαθέσιμες εκδόσεις
     */
    public function getAllReleases(int $limit = 5): array
    {
        try {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: VolunteerOps/' . self::CURRENT_VERSION,
                        'Accept: application/vnd.github.v3+json',
                    ],
                    'timeout' => 10,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            
            $url = "https://api.github.com/repos/" . self::GITHUB_REPO . "/releases?per_page=" . $limit;
            $response = @file_get_contents($url, false, $context);
            
            if ($response === false) {
                return ['success' => false, 'releases' => []];
            }
            
            $data = json_decode($response, true);
            
            if (!is_array($data)) {
                return ['success' => false, 'releases' => []];
            }
            
            $releases = [];
            foreach ($data as $release) {
                $releases[] = [
                    'version' => ltrim($release['tag_name'] ?? '', 'v'),
                    'name' => $release['name'] ?? '',
                    'date' => $release['published_at'] ?? null,
                    'url' => $release['html_url'] ?? null,
                ];
            }
            return ['success' => true, 'releases' => $releases];
            
        } catch (\Exception $e) {
            return ['success' => false, 'releases' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Λήψη των release notes σε HTML format
     */
    public function getReleaseNotesHtml(string $markdown): string
    {
        // Απλή μετατροπή markdown σε HTML
        $html = $markdown;
        
        // Headers
        $html = preg_replace('/^### (.*)$/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^# (.*)$/m', '<h3>$1</h3>', $html);
        
        // Bold
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        
        // Lists
        $html = preg_replace('/^- (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $html);
        
        // Line breaks
        $html = nl2br($html);
        
        return $html;
    }

    /**
     * Πληροφορίες συστήματος για debugging
     */
    public function getSystemInfo(): array
    {
        return [
            'app_version' => self::CURRENT_VERSION,
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'database_driver' => config('database.default'),
            'cache_driver' => config('cache.default'),
            'session_driver' => config('session.driver'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'debug_mode' => config('app.debug') ? 'Ενεργό' : 'Ανενεργό',
        ];
    }

    // =========================================================================
    // AUTO-UPDATE METHODS
    // =========================================================================

    /**
     * Δημιουργία backup πριν το update
     */
    public function createBackup(): array
    {
        try {
            $backupDir = storage_path('backups');
            if (!File::exists($backupDir)) {
                File::makeDirectory($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_His');
            $backupName = "backup_{$timestamp}";
            $backupPath = "{$backupDir}/{$backupName}";

            File::makeDirectory($backupPath, 0755, true);

            // Backup critical folders
            $foldersToBackup = ['app', 'config', 'routes', 'resources/views'];
            
            foreach ($foldersToBackup as $folder) {
                $source = base_path($folder);
                $dest = "{$backupPath}/{$folder}";
                
                if (File::exists($source)) {
                    File::copyDirectory($source, $dest);
                }
            }

            // Save current version info
            File::put("{$backupPath}/version.txt", self::CURRENT_VERSION);

            Log::info("Backup created: {$backupName}");

            return [
                'success' => true,
                'backup_name' => $backupName,
                'path' => $backupPath,
            ];

        } catch (\Exception $e) {
            Log::error("Backup failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Αποτυχία δημιουργίας backup: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Λήψη του update ZIP από GitHub
     */
    public function downloadUpdate(string $url): array
    {
        try {
            $tempDir = storage_path('app/updates');
            if (!File::exists($tempDir)) {
                File::makeDirectory($tempDir, 0755, true);
            }

            $zipFile = "{$tempDir}/update_" . time() . ".zip";

            // Download με file_get_contents (συμβατό με shared hosting)
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: VolunteerOps/' . self::CURRENT_VERSION,
                        'Accept: application/octet-stream',
                    ],
                    'timeout' => 120,
                    'follow_location' => true,
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            // Handle GitHub redirect for assets
            $content = @file_get_contents($url, false, $context);
            
            if ($content === false) {
                return [
                    'success' => false,
                    'message' => 'Αποτυχία λήψης αρχείου από: ' . $url,
                ];
            }

            if (File::put($zipFile, $content) === false) {
                return [
                    'success' => false,
                    'message' => 'Αποτυχία αποθήκευσης αρχείου',
                ];
            }

            $fileSize = filesize($zipFile);
            Log::info("Update downloaded: {$zipFile} ({$fileSize} bytes)");

            return [
                'success' => true,
                'file' => $zipFile,
                'size' => $fileSize,
            ];

        } catch (\Exception $e) {
            Log::error("Download failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Σφάλμα λήψης: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Εφαρμογή του update (εξαγωγή και αντικατάσταση αρχείων)
     */
    public function applyUpdate(string $zipFile): array
    {
        try {
            if (!File::exists($zipFile)) {
                return ['success' => false, 'message' => 'Το αρχείο ZIP δεν βρέθηκε'];
            }

            $zip = new ZipArchive();
            if ($zip->open($zipFile) !== true) {
                return ['success' => false, 'message' => 'Αποτυχία ανοίγματος ZIP'];
            }

            $extractDir = storage_path('app/updates/extracted_' . time());
            File::makeDirectory($extractDir, 0755, true);

            $zip->extractTo($extractDir);
            $zip->close();

            // Find the root folder inside ZIP (GitHub creates a folder like repo-main/)
            $extractedFolders = File::directories($extractDir);
            $sourceDir = count($extractedFolders) === 1 ? $extractedFolders[0] : $extractDir;

            $filesUpdated = 0;

            // Update only allowed paths
            foreach (self::UPDATE_PATHS as $path) {
                $sourcePath = "{$sourceDir}/{$path}";
                $destPath = base_path($path);

                if (File::exists($sourcePath)) {
                    // Remove old files (except protected)
                    if (File::exists($destPath) && !$this->isProtectedPath($path)) {
                        File::deleteDirectory($destPath);
                    }

                    // Copy new files
                    if (File::isDirectory($sourcePath)) {
                        File::copyDirectory($sourcePath, $destPath);
                    } else {
                        File::copy($sourcePath, $destPath);
                    }

                    $filesUpdated++;
                    Log::info("Updated: {$path}");
                }
            }

            // Update individual files in root
            $rootFiles = ['artisan', 'composer.json', 'composer.lock'];
            foreach ($rootFiles as $file) {
                $sourceFile = "{$sourceDir}/{$file}";
                $destFile = base_path($file);
                
                if (File::exists($sourceFile) && !$this->isProtectedPath($file)) {
                    File::copy($sourceFile, $destFile);
                    $filesUpdated++;
                }
            }

            // Cleanup extracted folder
            File::deleteDirectory($extractDir);

            Log::info("Update applied: {$filesUpdated} paths updated");

            return [
                'success' => true,
                'files_count' => $filesUpdated,
            ];

        } catch (\Exception $e) {
            Log::error("Apply update failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Σφάλμα εφαρμογής ενημέρωσης: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Εκτέλεση migrations
     */
    public function runMigrations(): array
    {
        try {
            // Get pending migrations before running
            $pendingBefore = $this->getPendingMigrations();

            // Run migrations
            Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            // Get what was run
            $pendingAfter = $this->getPendingMigrations();
            $migrationsRun = array_diff($pendingBefore, $pendingAfter);

            Log::info("Migrations run: " . count($migrationsRun));

            return [
                'success' => true,
                'migrations' => array_values($migrationsRun),
                'output' => $output,
            ];

        } catch (\Exception $e) {
            Log::error("Migration failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Σφάλμα migrations: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Λήψη εκκρεμών migrations
     */
    protected function getPendingMigrations(): array
    {
        try {
            // Get all migration files
            $migrationPath = database_path('migrations');
            $files = File::glob("{$migrationPath}/*.php");
            $allMigrations = array_map(function ($file) {
                return pathinfo($file, PATHINFO_FILENAME);
            }, $files);

            // Get already run migrations
            $ranMigrations = DB::table('migrations')->pluck('migration')->toArray();

            // Return pending
            return array_diff($allMigrations, $ranMigrations);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Καθαρισμός όλων των caches
     */
    public function clearAllCaches(): void
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            
            // Clear update check cache
            $this->clearCache();

            Log::info("All caches cleared");

        } catch (\Exception $e) {
            Log::warning("Cache clear warning: " . $e->getMessage());
        }
    }

    /**
     * Καθαρισμός temp files από updates
     */
    public function cleanupTempFiles(): void
    {
        try {
            $updateDir = storage_path('app/updates');
            if (File::exists($updateDir)) {
                // Delete ZIP files older than 1 hour
                $files = File::glob("{$updateDir}/*.zip");
                foreach ($files as $file) {
                    if (filemtime($file) < time() - 3600) {
                        File::delete($file);
                    }
                }

                // Delete extracted folders
                $dirs = File::directories($updateDir);
                foreach ($dirs as $dir) {
                    if (strpos(basename($dir), 'extracted_') === 0) {
                        File::deleteDirectory($dir);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Cleanup warning: " . $e->getMessage());
        }
    }

    /**
     * Επαναφορά από backup
     */
    public function restoreBackup(string $backupName): array
    {
        try {
            $backupPath = storage_path("backups/{$backupName}");
            
            if (!File::exists($backupPath)) {
                return ['success' => false, 'message' => 'Το backup δεν βρέθηκε'];
            }

            // Restore folders
            $folders = ['app', 'config', 'routes', 'resources/views'];
            
            foreach ($folders as $folder) {
                $source = "{$backupPath}/{$folder}";
                $dest = base_path($folder);
                
                if (File::exists($source)) {
                    File::deleteDirectory($dest);
                    File::copyDirectory($source, $dest);
                }
            }

            $this->clearAllCaches();

            Log::info("Restored from backup: {$backupName}");

            return [
                'success' => true,
                'message' => 'Η επαναφορά ολοκληρώθηκε επιτυχώς.',
            ];

        } catch (\Exception $e) {
            Log::error("Restore failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Σφάλμα επαναφοράς: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Λίστα διαθέσιμων backups
     */
    public function getBackupsList(): array
    {
        $backups = [];
        $backupDir = storage_path('backups');

        if (File::exists($backupDir)) {
            $dirs = File::directories($backupDir);
            
            foreach ($dirs as $dir) {
                $name = basename($dir);
                $versionFile = "{$dir}/version.txt";
                $version = File::exists($versionFile) ? File::get($versionFile) : 'Unknown';
                
                $backups[] = [
                    'name' => $name,
                    'version' => trim($version),
                    'date' => date('Y-m-d H:i:s', filemtime($dir)),
                    'size' => $this->getDirectorySize($dir),
                ];
            }
        }

        // Sort by date descending
        usort($backups, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        return $backups;
    }

    /**
     * Έλεγχος αν path είναι protected
     */
    protected function isProtectedPath(string $path): bool
    {
        foreach (self::PROTECTED_PATHS as $protected) {
            if (strpos($path, $protected) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Υπολογισμός μεγέθους φακέλου
     */
    protected function getDirectorySize(string $dir): string
    {
        $size = 0;
        foreach (File::allFiles($dir) as $file) {
            $size += $file->getSize();
        }
        
        if ($size < 1024) {
            return $size . ' B';
        } elseif ($size < 1048576) {
            return round($size / 1024, 2) . ' KB';
        } else {
            return round($size / 1048576, 2) . ' MB';
        }
    }
}
