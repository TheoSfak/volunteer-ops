<?php
/**
 * AJAX endpoint — test the routing provider used for target distances.
 * POST only, system admin required.
 * Returns JSON: { ok: bool, message: string, provider: string }
 *
 * Takes the key FROM THE FORM rather than only from storage, so an admin can
 * check a key before saving it. Pasting a key, saving, discovering it is wrong
 * and pasting again is three round trips to learn one fact — and with no key
 * posted at all this falls back to what is stored, which is how the button
 * behaves when somebody just wants to know whether routing works today.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);
require_once __DIR__ . '/includes/route-distance.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρη μέθοδος']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο αίτημα']);
    exit;
}

// An empty field means "test what is stored", not "test OSRM" — otherwise a
// browser that did not send the field would quietly report the free router as
// working while the paid one is what the organisation actually uses.
$posted = array_key_exists('api_key', $_POST) ? trim((string) $_POST['api_key']) : null;
$key    = ($posted === null || $posted === '') ? null : $posted;

// Never logged, never echoed back: this is a billable credential and the only
// thing that should leave here is whether it worked.
echo json_encode(routeDistanceProbe($key), JSON_UNESCAPED_UNICODE);
