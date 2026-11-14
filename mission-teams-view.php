<?php
/**
 * VolunteerOps - the «Ομάδες» page (v3.463.0)
 *
 * The teams of a mission on a page of their own, like the heart-rate report and the Scenario: the automatic split
 * (command gives one number, how many people a team has; whoever is left over goes into the last team and people of
 * one home team stay together), a team by hand, editing a team (its name, its leader, how it moves), deleting it, moving one
 * person from a team to another, and GPS - a switch beside every name, off for everybody until it is switched on. It
 * opens in a tab of its own from the header of the Action Room.
 *
 * Nothing here knows the teams. The page is a frame: it is drawn by assets/js/mission-teams.js from mission-teams.php,
 * and everything those two do is in includes/functions-mission-teams.php, which the two team windows of the Action
 * Room share. There is no table or migration of this page's own.
 *
 * Command staff only, with the Action Room's own gate (canManageActionRoom) and the endpoint's rule that the mission
 * is open and on the board: anyone else is sent away with a message and is never given the script.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user      = getCurrentUser();
$userId    = (int) $user['id'];
$missionId = (int) get('id');

$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    setFlash('error', t('teamsp.no_permission'));
    redirect('mission-view.php?id=' . $missionId);
}

// The endpoint answers only for an open mission that is on the board; a page that could not load its teams would be
// an empty frame, so it says why and goes where the reason is shown.
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('error', t('common.mission_not_found_or_inactive'));
    redirect('war-room.php?id=' . $missionId);
}

$lang         = (string) ($user['language'] ?? DEFAULT_LANGUAGE);
$pageStrings  = missionTeamsPageStrings($lang);
$jsonFlags    = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
$baseUrl      = rtrim(BASE_URL, '/');
$scriptFile   = __DIR__ . '/assets/js/mission-teams.js';

$pageTitle    = t('teamsp.page_title') . ': ' . $mission['title'];
$clockMission = $mission; // the clock strip names its prefecture (includes/clock-place.php)
include __DIR__ . '/includes/header.php';
?>

<style>
    .mt-hero { background: linear-gradient(135deg, #1e3a8a, #0f766e); color: #fff; border-radius: 16px; padding: 20px 24px; }
    .mt-hero h1 { color: #fff; font-weight: 700; font-size: 1.5rem; margin: 0; }
    .mt-live { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.18); padding: 3px 12px; border-radius: 999px; font-size: .8rem; font-weight: 600; }
    .mt-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; animation: mtPulse 1.6s ease-in-out infinite; }
    @keyframes mtPulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) { .mt-live-dot { animation: none; } }

    .mt-busy { cursor: progress; }
    .mt-busy button, .mt-busy select, .mt-busy input { pointer-events: none; }
    .mt-chip { display: inline-flex; align-items: center; background: var(--bs-tertiary-bg, #f1f3f5); border: 1px solid var(--bs-border-color, #dee2e6); border-radius: 999px; padding: 4px 12px; font-size: .88rem; }
    .mt-chip-strong { background: #fff3cd; border-color: #ffda6a; color: #664d03; font-weight: 600; }

    /* One person: who they are on the left, where they go and their GPS on the right. Wraps on a phone. */
    .mt-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 6px 8px; border-radius: 8px; }
    .mt-row + .mt-row { border-top: 1px solid var(--bs-border-color-translucent, rgba(0,0,0,.08)); }
    .mt-row[draggable="true"] { cursor: grab; }
    .mt-row.has-gps { background: rgba(13,110,253,.06); }
    .mt-who { flex: 1 1 8rem; min-width: 0; overflow-wrap: anywhere; }
    .mt-name { font-weight: 500; }
    .mt-move { width: 9.5rem; flex: none; }
    .mt-leader-line { font-size: .88rem; }
    .mt-gps { display: flex; align-items: center; gap: 6px; min-width: 4.6rem; padding-left: 0; }
    .mt-gps .form-check-input { margin: 0; float: none; cursor: pointer; }
    .mt-pick { margin: 0; flex: none; width: 1.15rem; height: 1.15rem; cursor: pointer; }
    .mt-star { flex: none; border: 0; background: transparent; padding: 0 2px; font-size: 1.05rem; line-height: 1; color: var(--bs-secondary-color, #6c757d); }
    .mt-star:hover { color: #f59f00; }
    .mt-star.is-leader { color: #f59f00; cursor: default; }
    .mt-plan-row { font-size: .9rem; padding: 1px 0; overflow-wrap: anywhere; }
    .mt-dragging .mt-team { outline: 2px dashed transparent; outline-offset: 2px; }
    .mt-dragging .mt-team.mt-drop-over { outline-color: #0d6efd; background: rgba(13,110,253,.05); }
</style>

<div class="container-fluid px-0 wr-teams-page" id="mtContent">

    <div class="mt-hero mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="small opacity-75 mb-1"><i class="bi bi-diagram-3-fill me-1"></i><?= h(t('teamsp.page_label')) ?></div>
            <h1><?= h($mission['title']) ?></h1>
            <div class="small opacity-75 mt-1">
                <?= h($mission['location'] ?? '') ?>
                <?php if (!empty($mission['department_name'])): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <span class="mt-live"><span class="mt-live-dot"></span><?= h(t('teamsp.page_live')) ?></span>
            <div class="mt-2 d-flex gap-2 justify-content-end">
                <a href="war-room.php?id=<?= $missionId ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left me-1"></i><?= h(t('teamsp.page_open_room')) ?></a>
            </div>
        </div>
    </div>

    <div id="missionTeamsBody"><div class="small text-muted"><?= h(t('common.loading')) ?></div></div>
</div>

<script>
// What assets/js/mission-teams.js reaches for, defined here because this is not the Action Room: the mission, the
// CSRF token, the three transport icons and the few words it asks t() for. The script itself is the same file, byte
// for byte, for every mission.
const WR_MISSION_ID = <?= (int) $missionId ?>;
const csrfToken = <?= json_encode(csrfToken(), $jsonFlags) ?>;
const MT_TRANSPORT_ICONS = <?= json_encode(teamTransportIconPaths(), $jsonFlags) ?>;
const MT_EQUIPMENT_TYPES = <?= json_encode(teamEquipmentTypes(true), $jsonFlags) ?>;
const WR_STRINGS = <?= json_encode($pageStrings['strings'], $jsonFlags) ?>;
const WR_STRINGS_FALLBACK = <?= json_encode($pageStrings['fallback'], $jsonFlags) ?>;
function t(key, vars = {}) {
    let text = WR_STRINGS[key] ?? WR_STRINGS_FALLBACK[key] ?? key;
    for (const [k, v] of Object.entries(vars)) text = text.replaceAll('{' + k + '}', String(v));
    return text;
}
</script>
<script src="<?= $baseUrl ?>/assets/js/war-room-utils.js?v=<?= APP_VERSION ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/mission-teams.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime($scriptFile) ?>"></script>
<script>window.missionTeams.init();</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
