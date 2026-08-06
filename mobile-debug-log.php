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
 * GET (admin session) renders the log as plain text so it can be read from
 * a live domain this session has no direct file access to.
 */
require_once __DIR__ . '/bootstrap.php';

if (isPost()) {
    header('Content-Type: application/json');

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

    // Two request shapes: native (bearer-authed) sends a JSON body, JS
    // (session-authed) sends normal form fields — same branch this file's
    // sibling mobile-ping-location.php already uses for the same reason.
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $source = substr((string) ($body['source'] ?? '?'), 0, 20);
        $event = substr((string) ($body['event'] ?? '?'), 0, 60);
        $detail = substr((string) ($body['detail'] ?? ''), 0, 300);
    } else {
        $source = substr((string) post('source', 'js'), 0, 20);
        $event = substr((string) post('event', '?'), 0, 60);
        $detail = substr((string) post('detail', ''), 0, 300);
    }

    $line = sprintf(
        "[%s] user=%d source=%s event=%s detail=%s\n",
        date('Y-m-d H:i:s'),
        $userId,
        $source,
        $event,
        str_replace(["\r", "\n"], ' ', $detail)
    );

    $logFile = __DIR__ . '/uploads/mobile-debug.log';
    // Defensive size cap — this is a temporary diagnostic tool, not a
    // rotated production log, so just truncate rather than growing forever.
    if (file_exists($logFile) && filesize($logFile) > 500000) {
        file_put_contents($logFile, '');
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

$logFile = __DIR__ . '/uploads/mobile-debug.log';
$content = file_exists($logFile) ? file_get_contents($logFile) : '(no log entries yet)';
header('Content-Type: text/plain; charset=utf-8');
echo $content;
