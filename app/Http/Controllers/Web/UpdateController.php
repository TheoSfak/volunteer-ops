<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class UpdateController extends Controller
{
    public function __construct(
        protected UpdateService $updateService
    ) {
        $this->middleware(['auth', 'can:manage-system']);
    }

    /**
     * Σελίδα ενημερώσεων
     */
    public function index()
    {
        $updateInfo = $this->updateService->checkForUpdates();
        $systemInfo = $this->updateService->getSystemInfo();
        $releases = $this->updateService->getAllReleases(5);
        
        return view('settings.updates', compact('updateInfo', 'systemInfo', 'releases'));
    }

    /**
     * Έλεγχος για νέα έκδοση (AJAX)
     */
    public function check()
    {
        $this->updateService->clearCache();
        $updateInfo = $this->updateService->checkForUpdates();
        
        return response()->json($updateInfo);
    }

    /**
     * Λήψη και εγκατάσταση ενημέρωσης
     */
    public function install(Request $request)
    {
        $request->validate([
            'version' => 'required|string',
            'download_url' => 'required|url',
        ]);

        try {
            // Βήμα 1: Δημιουργία backup
            Log::info('Update: Creating backup...');
            $backupResult = $this->updateService->createBackup();
            if (!$backupResult['success']) {
                return response()->json([
                    'success' => false,
                    'step' => 'backup',
                    'message' => $backupResult['message'],
                ], 500);
            }

            // Βήμα 2: Λήψη αρχείου
            Log::info('Update: Downloading update...');
            $downloadResult = $this->updateService->downloadUpdate($request->download_url);
            if (!$downloadResult['success']) {
                return response()->json([
                    'success' => false,
                    'step' => 'download',
                    'message' => $downloadResult['message'],
                ], 500);
            }

            // Βήμα 3: Εξαγωγή και εφαρμογή
            Log::info('Update: Applying update...');
            $applyResult = $this->updateService->applyUpdate($downloadResult['file']);
            if (!$applyResult['success']) {
                return response()->json([
                    'success' => false,
                    'step' => 'apply',
                    'message' => $applyResult['message'],
                ], 500);
            }

            // Βήμα 4: Εκτέλεση migrations
            Log::info('Update: Running migrations...');
            $migrateResult = $this->updateService->runMigrations();
            if (!$migrateResult['success']) {
                return response()->json([
                    'success' => false,
                    'step' => 'migrate',
                    'message' => $migrateResult['message'],
                    'warning' => true, // Migration failure is a warning, not critical
                ], 200);
            }

            // Βήμα 5: Καθαρισμός cache
            Log::info('Update: Clearing caches...');
            $this->updateService->clearAllCaches();

            // Βήμα 6: Καθαρισμός temp files
            $this->updateService->cleanupTempFiles();

            Log::info('Update: Completed successfully to version ' . $request->version);

            return response()->json([
                'success' => true,
                'message' => 'Η ενημέρωση ολοκληρώθηκε επιτυχώς!',
                'version' => $request->version,
                'files_updated' => $applyResult['files_count'] ?? 0,
                'migrations_run' => $migrateResult['migrations'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Update failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'step' => 'unknown',
                'message' => 'Σφάλμα: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Εκτέλεση migrations μόνο
     */
    public function migrate()
    {
        try {
            $result = $this->updateService->runMigrations();
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Επαναφορά από backup
     */
    public function rollback(Request $request)
    {
        $request->validate([
            'backup_name' => 'required|string',
        ]);

        try {
            $result = $this->updateService->restoreBackup($request->backup_name);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Λίστα διαθέσιμων backups
     */
    public function backups()
    {
        $backups = $this->updateService->getBackupsList();
        return response()->json(['backups' => $backups]);
    }

    /**
     * Καθαρισμός caches
     */
    public function clearCaches()
    {
        try {
            $this->updateService->clearAllCaches();
            return response()->json([
                'success' => true,
                'message' => 'Όλα τα caches καθαρίστηκαν επιτυχώς.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
