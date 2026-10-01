<?php
/**
 * VolunteerOps - Download or refresh all of Crete's OpenStreetMap points
 *
 * The endpoint behind the «Λήψη / Ενημέρωση δεδομένων Κρήτης» button in Settings.
 * POST only, AJAX, system administrators only. Actions:
 *
 *   status - what is stored and how far the current job has got.
 *   start  - begin a download or refresh: from now on a chunk of tiles counts as
 *            done once it holds rows newer than this moment.
 *   step   - fetch the next chunk that still needs it (one Overpass query) and
 *            say how many remain. The page calls this one after another.
 *
 * See includes/osm-bulk.php for how a job works. Each step takes seconds, and the
 * page keeps asking, so the session stays alive for the hour it takes; a page
 * closed halfway is picked up again from the first chunk still missing.
 *
 * WHAT LEAVES THIS BUILDING: bounding boxes, to Overpass, nothing else.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/osm-bulk.php';
requireLogin();

header('Content-Type: application/json');

$fail = function (string $error): void {
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isPost()) $fail('Method not allowed');

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    $fail(t('common.invalid_request'));
}

if (!isSystemAdmin()) $fail('Μόνο διαχειριστές συστήματος.');

// A step is one Overpass call; the session file would stay locked for all of it.
session_write_close();
ignore_user_abort(true);
@set_time_limit(OSM_REQUEST_DEADLINE + 20);

switch ((string) post('action')) {
    case 'status':
        echo json_encode(['ok' => true] + osmBulkStatus(), JSON_UNESCAPED_UNICODE);
        break;
    case 'start':
        echo json_encode(['ok' => true] + osmBulkStart(), JSON_UNESCAPED_UNICODE);
        break;
    case 'step':
        echo json_encode(osmBulkStep((int) post('after')), JSON_UNESCAPED_UNICODE);
        break;
    default:
        $fail(t('common.invalid_request'));
}
