<?php
/**
 * VolunteerOps - Temporary Mobile Background-GPS Debug Log
 * TEMPORARY diagnostic tool for the recurring "native background tracking
 * shows active but no pings arrive" bug — not a permanent admin feature,
 * remove once that's actually root-caused and fixed for good. Exists
 * because this dev session has no adb/SSH access to the user's phone or
 * either live prod domain, so both the JS bootstrap hook (war-room.php) and
 * the native Java service log key lifecycle events here instead.
 * POST auth is EITHER a bearer token (native app calls, same as
 * mobile-ping-location.php) OR a session+CSRF check (JS calls) — the JS
 * side deliberately uses session auth, not bearer, so it can log the
 * EARLIEST lifecycle points too (plugin missing, token issuance failing),
 * none of which have a bearer token yet.
 * The detail cap is 4000, not 300: war-room.php's live-video diagnostic
 * posts a whole buffered session here (UA, codec capabilities, both track
 * states, RTP counters, ICE) because the phone that fails belongs to a
 * volunteer and cannot be borrowed to read a screen. The 500KB truncation
 * below still bounds the file.
 * GET (admin session) renders the log as plain text so it can be read from
 * a live domain this session has no direct file access to.
 *
 * WHERE THE FILE LIVES (v3.336.1). It used to be uploads/mobile-debug.log, and
 * on both live sites anyone could download it without logging in: they run
 * LiteSpeed, which ignores the root .htaccess <FilesMatch> that denies *.log
 * (README.md and CHANGELOG.md are served there too, the same way). The file
 * held every native fix's raw coordinates, every few seconds, with the user id
 * — where each volunteer's phone spent the night. Now it is storage/, whose
 * own deny-all .htaccess is a directory-level rule, the kind LiteSpeed does
 * honour (sql/, backups/ and tests/ answer 403 live). ensurePrivateUploadDir()
 * re-creates that guard if a deployment ever arrives without it. The old file
 * is deleted the first time this endpoint runs.
 */
require_once __DIR__ . '/bootstrap.php';

$logDir = __DIR__ . '/storage';
ensurePrivateUploadDir($logDir);
$logFile = $logDir . '/mobile-debug.log';
if (is_file(__DIR__ . '/uploads/mobile-debug.log')) {
    @unlink(__DIR__ . '/uploads/mobile-debug.log');
}

if (isPost()) {
    header('Content-Type: application/json');

    // Two request shapes: native (bearer-authed) sends a JSON body, JS
    // (session-authed) sends normal form fields — same branch this file's
    // sibling mobile-ping-location.php already uses for the same reason.
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($contentType, 'application/json') !== false;
    $body = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];

    // Apps up to 1.1.18 / 1.0.19 report every single fix here, with its
    // coordinates, on top of the ping that already stores it — a second
    // HTTPS request per fix for the phone and one more token lookup for the
    // server. Every fix that reaches the server is in volunteer_pings anyway
    // (raw_* holds exactly what the phone said), so these are answered at
    // once, before the database is touched, and never written.
    if ($isJson && ($body['event'] ?? '') === 'location_received') {
        echo json_encode(['ok' => true, 'skipped' => 'location_received']);
        exit;
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
        $tokenHash = hash('sha256', $matches[1]);
        $tokenRow = dbFetchOne(
            "SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
            [$tokenHash]
        );
        if (!$tokenRow) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Invalid or revoked token']);
            exit;
        }
        $userId = (int) $tokenRow['user_id'];
    } else {
        requireLogin();
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
            echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
            exit;
        }
        $userId = getCurrentUserId();
    }

    if ($isJson) {
        $source = substr((string) ($body['source'] ?? '?'), 0, 20);
        $event = substr((string) ($body['event'] ?? '?'), 0, 60);
        $detail = substr((string) ($body['detail'] ?? ''), 0, 4000);
    } else {
        $source = substr((string) post('source', 'js'), 0, 20);
        $event = substr((string) post('event', '?'), 0, 60);
        $detail = substr((string) post('detail', ''), 0, 4000);
    }

    $line = sprintf(
        "[%s] user=%d source=%s event=%s detail=%s\n",
        date('Y-m-d H:i:s'),
        $userId,
        $source,
        $event,
        str_replace(["\r", "\n"], ' ', $detail)
    );

    // Size cap. Keeps the newer half rather than emptying the file: wiping it
    // threw away the whole history at the moment it had grown busy enough to
    // be worth reading.
    if (file_exists($logFile) && filesize($logFile) > 500000) {
        $kept = (string) @file_get_contents($logFile, false, null, -250000);
        $cut = strpos($kept, "\n");
        file_put_contents($logFile, $cut === false ? '' : substr($kept, $cut + 1), LOCK_EX);
    }
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    echo json_encode(['ok' => true]);
    exit;
}

// GET: admin-only plain-text viewer.
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    die('Forbidden');
}

$content = file_exists($logFile) ? file_get_contents($logFile) : '(no log entries yet)';
header('Content-Type: text/plain; charset=utf-8');
echo $content;
