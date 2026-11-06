<?php
/**
 * VolunteerOps - delivery probe for the war-room script (TEMPORARY).
 *
 * What it is: a small stand-in for the file loader the plan for shrinking the Action Room page may add. It serves a
 * 340 KB sample of JavaScript that is ALREADY PUBLIC on the site (parts of assets/js/*.js) in the ways the real loader
 * would, so the host's behaviour can be measured before anything real is built. It changes nothing in the application,
 * reads no database, no session, no password, and prints no path - only the PHP version, the server's name and
 * yes/no answers. It refuses to run after the expiry date below. Delete the whole folder when done (or call
 * ?mode=cleanup, which tries to).
 *
 * Modes:  info (default) | plain | gz | cached | cleanup
 */

const PROBE_VERSION = 1;
const PROBE_EXPIRES = 1796561916;   // unix time; the build fills it in

$mode = isset($_GET['mode']) ? (string) $_GET['mode'] : 'info';
$here = __DIR__;

function probeOut(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

if ($mode !== 'cleanup' && time() > PROBE_EXPIRES) {
    probeOut(['error' => 'expired', 'note' => 'This probe has expired. Delete this folder from the server.'], 410);
}

if ($mode === 'cleanup') {
    $removed = [];
    $failed = [];
    $walk = function (string $dir) use (&$walk, &$removed, &$failed): void {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                $walk($path);
                if (@rmdir($path)) {
                    $removed[] = $name . '/';
                } else {
                    $failed[] = $name . '/';
                }
            } elseif (realpath($path) !== realpath(__FILE__)) {
                if (@unlink($path)) {
                    $removed[] = $name;
                } else {
                    $failed[] = $name;
                }
            }
        }
    };
    $walk($here);
    $selfGone = @unlink(__FILE__);
    $folderGone = @rmdir($here);
    probeOut(['cleanup' => true, 'removed' => $removed, 'failed' => $failed, 'index_php_removed' => $selfGone, 'folder_removed' => $folderGone,
        'note' => $failed || !$selfGone || !$folderGone ? 'Something is left: delete the folder from the file manager.' : 'All gone.']);
}

$rawFile = $here . '/payload.js';
$raw = is_file($rawFile) ? (string) file_get_contents($rawFile) : '';
if ($raw === '') {
    probeOut(['error' => 'payload.js is missing from this folder'], 500);
}
$sha = sha1($raw);
$acceptsGzip = isset($_SERVER['HTTP_ACCEPT_ENCODING']) && stripos((string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false;

// the headers the real loader would send for a file that never changes under its URL
function probeCommonHeaders(string $sha, bool $gz): string
{
    $etag = '"' . substr($sha, 0, 16) . ($gz ? '-gz' : '') . '"';
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('Vary: Accept-Encoding');
    header('X-Probe-Raw-Sha1: ' . $sha);
    header('X-Probe-Raw-Length: ' . strlen((string) $GLOBALS['raw']));
    return $etag;
}

if ($mode === 'plain') {
    // no encoding of our own: whatever the host does to plain PHP output
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Probe-Raw-Sha1: ' . $sha);
    header('X-Probe-Raw-Length: ' . strlen($raw));
    echo $raw;
    exit;
}

if ($mode === 'gz' || $mode === 'cached') {
    $etag = probeCommonHeaders($sha, $acceptsGzip);
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? (string) $_SERVER['HTTP_IF_NONE_MATCH'] : '';
    if ($inm !== '' && strpos($inm, substr($sha, 0, 16)) !== false) {
        http_response_code(304);
        header('X-Probe-Conditional: 304 from PHP');
        exit;
    }
    header('Content-Type: application/javascript; charset=utf-8');
    if (!$acceptsGzip) {
        header('X-Probe-Encoding: identity (client did not offer gzip)');
        header('Content-Length: ' . strlen($raw));
        echo $raw;
        exit;
    }
    header('Content-Encoding: gzip');
    if ($mode === 'gz') {
        $t0 = hrtime(true);
        $body = gzencode($raw, 9);
        header('X-Probe-Gzip-Ms: ' . round((hrtime(true) - $t0) / 1e6, 1));
        header('X-Probe-Cache: none (built per request)');
        header('Content-Length: ' . strlen((string) $body));
        echo $body;
        exit;
    }
    // cached: the way the loader would do it - build once, keep on disk, then just send the file
    $cacheFile = $here . '/cache-' . substr($sha, 0, 12) . '.js.gz';
    $state = 'hit';
    if (!is_file($cacheFile)) {
        $state = 'miss (written now)';
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        $okWrite = @file_put_contents($tmp, gzencode($raw, 9), LOCK_EX) !== false && @rename($tmp, $cacheFile);
        if (!$okWrite) {
            @unlink($tmp);
            $state = 'none (this folder is not writable; built per request)';
            $body = gzencode($raw, 9);
            header('X-Probe-Cache: ' . $state);
            header('Content-Length: ' . strlen((string) $body));
            echo $body;
            exit;
        }
    }
    header('X-Probe-Cache: ' . $state);
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

// ---- info ----------------------------------------------------------------------------------------------------------
$root = dirname($here);

function probeDirReport(string $path): array
{
    $r = ['exists' => is_dir($path), 'writable' => is_dir($path) && is_writable($path), 'can_create_file' => false, 'can_make_subfolder' => false];
    if ($r['exists']) {
        $f = $path . '/.wrprobe-' . bin2hex(random_bytes(4)) . '.tmp';
        $r['can_create_file'] = @file_put_contents($f, 'x') !== false;
        if ($r['can_create_file']) {
            @unlink($f);
        }
        $d = $path . '/.wrprobe-dir-' . bin2hex(random_bytes(4));
        $r['can_make_subfolder'] = @mkdir($d, 0755);
        if ($r['can_make_subfolder']) {
            @rmdir($d);
        }
    }
    return $r;
}

$sw = is_file($root . '/sw.js') ? (string) file_get_contents($root . '/sw.js') : '';
preg_match("~CACHE_VERSION\\s*=\\s*'([^']+)'~", $sw, $m);

$t0 = hrtime(true);
$gz = gzencode($raw, 9);
$gzMs = round((hrtime(true) - $t0) / 1e6, 1);

$seen = [];
foreach (['HTTP_ACCEPT_ENCODING' => 'Accept-Encoding', 'HTTP_IF_NONE_MATCH' => 'If-None-Match', 'HTTP_CONNECTION' => 'Connection', 'HTTP_X_FORWARDED_PROTO' => 'X-Forwarded-Proto'] as $key => $label) {
    if (isset($_SERVER[$key])) {
        $seen[$label] = (string) $_SERVER[$key];
    }
}

$disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
probeOut([
    'probe' => ['version' => PROBE_VERSION, 'expires_utc' => gmdate('Y-m-d H:i', PROBE_EXPIRES), 'time_utc' => gmdate('Y-m-d H:i:s')],
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os_family' => PHP_OS_FAMILY,
        'zlib_loaded' => extension_loaded('zlib'),
        'gzencode_usable' => function_exists('gzencode') && !in_array('gzencode', $disabled, true),
        'readfile_usable' => function_exists('readfile') && !in_array('readfile', $disabled, true),
        'opcache_enabled' => (bool) ini_get('opcache.enable'),
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'zlib_output_compression' => (string) ini_get('zlib.output_compression'),
        'output_handler' => (string) ini_get('output_handler'),
        'session_auto_start' => (string) ini_get('session.auto_start'),
        'disabled_function_count' => count($disabled),
    ],
    'server' => [
        'software' => isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : null,
        'protocol_seen_by_php' => isset($_SERVER['SERVER_PROTOCOL']) ? (string) $_SERVER['SERVER_PROTOCOL'] : null,
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'request_headers_that_reach_php' => $seen,
        'disk_free_mb' => (int) round((float) @disk_free_space($here) / 1048576),
    ],
    'this_is_the_app_root' => ['war-room.php_next_to_probe_folder' => is_file($root . '/war-room.php'), 'deployed_cache_version' => $m[1] ?? null],
    'folders_php_can_write' => [
        'probe_folder' => probeDirReport($here),
        'storage' => probeDirReport($root . '/storage'),
        'uploads' => probeDirReport($root . '/uploads'),
        'assets_js' => probeDirReport($root . '/assets/js'),
        'system_temp' => probeDirReport(sys_get_temp_dir()),
    ],
    'payload' => ['raw_bytes' => strlen($raw), 'sha1' => $sha, 'gzip9_bytes' => strlen((string) $gz), 'gzip9_ms_here' => $gzMs, 'peak_memory_mb' => round(memory_get_peak_usage() / 1048576, 1)],
    'try' => ['plain' => '?mode=plain', 'gzip' => '?mode=gz', 'cached' => '?mode=cached', 'static' => 'payload.js', 'static_gz_route' => 'static-gz/payload.js', 'cleanup' => '?mode=cleanup'],
]);
