<?php
/**
 * VolunteerOps - Temporary Mobile Background-GPS Debug Log
 * TEMPORARY diagnostic tool for the recurring "native background tracking
 * shows active but no pings arrive" bug — not a permanent admin feature,
 * remove once that's actually root-caused and fixed for good. Exists
 * because this dev session has no adb/SSH access to the user's phone or
 * either live prod domain, so both the JS bootstrap hook (war-room.php) and
 * the native Java service log key lifecycle events here instead. POST
 * (bearer-token auth, same as mobile-ping-location.php) appends a line;
 * GET (admin session) renders the log as plain text so it can be read from
 * a live domain this session has no direct file access to.
 */
require_once __DIR__ . '/bootstrap.php';

if (isPost()) {
    header('Content-Type: application/json');

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Missing or malformed bearer token']);
        exit;
    }
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

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $source = substr((string) ($body['source'] ?? '?'), 0, 20);
    $event = substr((string) ($body['event'] ?? '?'), 0, 60);
    $detail = substr((string) ($body['detail'] ?? ''), 0, 300);

    $line = sprintf(
        "[%s] user=%d source=%s event=%s detail=%s\n",
        date('Y-m-d H:i:s'),
        (int) $tokenRow['user_id'],
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
