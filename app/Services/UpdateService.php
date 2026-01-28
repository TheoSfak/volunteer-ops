<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class UpdateService
{
    /**
     * Τρέχουσα έκδοση της εφαρμογής
     */
    const CURRENT_VERSION = '1.1.1';
    
    /**
     * GitHub repository
     */
    const GITHUB_REPO = 'TheoSfak/volunteer-ops';
    
    /**
     * Cache duration σε λεπτά
     */
    const CACHE_DURATION = 60; // 1 ώρα

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
}
