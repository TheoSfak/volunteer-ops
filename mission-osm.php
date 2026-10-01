<?php
/**
 * VolunteerOps - Action Room «OpenStreetMap» layer
 *
 * What OpenStreetMap knows about the ground the map is showing: paths, caves,
 * huts, springs, chapels, cliffs, peaks. POST only, AJAX. The page sends the
 * box it is looking at and the groups of features the viewer has ticked; the
 * answer is the features in it, drawn by the page. See includes/osm.php for
 * where they come from and how long they are kept.
 *
 * Open to everyone who can open the Action Room itself, like the measuring
 * tool: a volunteer on the hillside wants to know where the nearest spring or
 * hut is as much as command does. Nothing is stored about who asked.
 *
 * WHAT LEAVES THIS BUILDING: a bounding box, to Overpass, and only when the
 * cache has nothing fresh for it. No mission, no user, no people.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/osm.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Requests per person per mission in the window. Moving a map fires one per
 * pause, and a tile still being fetched makes the page ask again, so this is
 * generous; it is there for a stuck script, not for a person.
 */
const OSM_RATE_MAX = 300;
const OSM_RATE_WINDOW = 600;

/** Overpass calls one request may make. The rest of a big view fills in on the next ask. */
const OSM_FETCHES_PER_REQUEST = 1;

$userId = (int) getCurrentUserId();

$fail = function (string $error): void {
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
};

if (!isPost()) $fail('Method not allowed');

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    $fail(t('common.invalid_request'));
}

if (getSetting('osm_layer_enabled', '1') !== '1') $fail(t('osm.disabled'));

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    $fail(t('common.mission_not_found_or_inactive'));
}

// The same gate war-room.php itself applies.
$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) $fail(t('wr.access_denied'));

$tiles = osmTileKeys(post('south'), post('west'), post('north'), post('east'));
if ($tiles === null) $fail(t('common.invalid_request'));

$groups = array_values(array_intersect(OSM_GROUPS, array_filter(array_map('trim', explode(',', (string) post('groups'))))));
if (!$groups) $fail(t('common.invalid_request'));

// Counted in the session, so before it is released below.
$now = time();
$key = 'osm_calls_' . $missionId;
$calls = array_values(array_filter((array) ($_SESSION[$key] ?? []), fn($ts) => is_int($ts) && $ts > $now - OSM_RATE_WINDOW));
if (count($calls) >= OSM_RATE_MAX) {
    $_SESSION[$key] = $calls;
    $fail(t('osm.rate_limited'));
}
$calls[] = $now;
$_SESSION[$key] = $calls;

// Overpass can take half a minute; the session file stays locked for the whole
// request otherwise and the 5s poll queues behind it.
session_write_close();

// Whatever the page does, finish and cache what Overpass was asked for: a call
// abandoned halfway is a tile that never fills. Each Overpass call keeps to
// OSM_REQUEST_DEADLINE seconds by itself, so a host that cannot raise PHP's
// time limit (set_time_limit is often disabled) still fits.
ignore_user_abort(true);
@set_time_limit(OSM_FETCHES_PER_REQUEST * OSM_REQUEST_DEADLINE + 15);

$budget = OSM_FETCHES_PER_REQUEST;
$why = null;
$items = [];
$seen = [];
$ready = [];
$pending = [];
$failed = [];
$truncated = false;

// Groups travel in bundles (one Overpass query each): ask per bundle, answer per group.
$wantedByBundle = [];
foreach ($groups as $group) $wantedByBundle[osmBundleOf($group)][] = $group;

// Tiles side by side in a row are asked of Overpass together, OSM_CHUNK_TILES at
// a time: one query for a row of them, not one each.
$rows = [];
foreach ($tiles as $tile) $rows[(int) explode('_', $tile[0])[0]][] = $tile;
$chunks = [];
foreach ($rows as $rowTiles) {
    foreach (array_chunk($rowTiles, OSM_CHUNK_TILES) as $chunk) $chunks[] = $chunk;
}

foreach ($chunks as $chunk) {
    foreach ($wantedByBundle as $bundle => $wanted) {
        [$byTile, $states] = osmChunkBundle($chunk, $bundle, $budget, $why);
        foreach ($chunk as $tile) {
            $tileKey = $tile[0];
            $state = $states[$tileKey];
            foreach ($wanted as $group) {
                // "tile|group" is what the page keeps to know what it already has.
                $tileGroup = $tileKey . '|' . $group;
                if ($state === 'pending') $pending[] = $tileGroup;
                elseif ($state === 'failed') $failed[] = $tileGroup;
                else $ready[] = $tileGroup;
                foreach ($byTile[$tileKey][$group] ?? [] as $item) {
                    // A feature that crosses a tile edge comes back with each tile.
                    $featureId = $item['t'] . $item['id'];
                    if (isset($seen[$featureId])) continue;
                    if (count($items) >= OSM_MAX_ITEMS) {
                        $truncated = true;
                        break;
                    }
                    $seen[$featureId] = true;
                    $items[] = $item;
                }
            }
        }
    }
}

echo json_encode([
    'ok'          => true,
    'items'       => $items,
    'ready'       => $ready,
    'pending'     => $pending,
    'failed'      => $failed,
    // Why the last fetch failed, for the page to show: a developer reading the
    // console, or an admin asked "what does it say?", can tell Overpass being
    // busy from this server being unable to reach it at all.
    'diag'        => $failed || $why !== null ? $why : null,
    'truncated'   => $truncated,
    'attribution' => '© OpenStreetMap contributors',
], JSON_UNESCAPED_UNICODE);
