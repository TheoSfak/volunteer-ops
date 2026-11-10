<?php
/**
 * VolunteerOps - the «Σενάριο» page (v3.462.0)
 *
 * The whole Scenario card on a page of its own, like the heart-rate report: the phases of orders written down before
 * an exercise, the state of each team under every phase, ▶ with its confirmation, «Ποια ομάδα είναι ποια;», «Στείλε
 * τώρα» / «Ακύρωση αναμονής», taking a phase back, renaming and deleting, and the «Εντολή σε άτομα» form. It opens in a
 * tab of its own from the header of the Action Room, to sit on a second screen while the map keeps running.
 *
 * Nothing here knows the plan. The page is a frame: the card is drawn by assets/js/war-room-scenario.js - the same
 * file the Action Room loads, in its page mode (data-sc-mode="page") - from mission-scenario.php, which stays the one
 * place that reads and writes the plan. There is no endpoint, table or migration of this page's own. What needs the map
 * (staging from the order windows, the ghosts, «Δείξε το στον χάρτη», «Τομείς», «Κτίριο») stays in the Action Room and
 * is a link to it from here.
 *
 * System admins only (v3.466.0), not every account that can manage the Action Room, and the endpoint's own rule
 * that the mission is open and on the board: anyone else is sent away with a message and is never given the
 * script, which is what keeps a staged plan - unplayed or not - from being seen, or reaching the field, before
 * the one account trusted with it presses play.
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

// System admins only (v3.466.0), the same tightening as the endpoint: a department admin manages everything
// else of the Action Room but is never given a staged plan, played or not.
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId) || !isSystemAdmin()) {
    setFlash('error', t('scenario.no_permission'));
    redirect('mission-view.php?id=' . $missionId);
}

// The endpoint answers only for an open mission that is on the board; a page that could not load its card would be
// an empty frame, so it says why and goes where the reason is shown.
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('error', t('common.mission_not_found_or_inactive'));
    redirect('war-room.php?id=' . $missionId);
}

$lang         = (string) ($user['language'] ?? DEFAULT_LANGUAGE);
$pageStrings  = scenarioPageStrings($lang);
$jsonFlags    = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
$baseUrl      = rtrim(BASE_URL, '/');
$scriptFile   = __DIR__ . '/assets/js/war-room-scenario.js';

$pageTitle    = t('scenario.card_title') . ': ' . $mission['title'];
$clockMission = $mission; // the clock strip names its prefecture (includes/clock-place.php)
include __DIR__ . '/includes/header.php';
?>

<style>
    .sc-hero { background: linear-gradient(135deg, #14532d, #172554); color: #fff; border-radius: 16px; padding: 20px 24px; }
    .sc-hero h1 { color: #fff; font-weight: 700; font-size: 1.5rem; margin: 0; }
    .sc-live { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.18); padding: 3px 12px; border-radius: 999px; font-size: .8rem; font-weight: 600; }
    .sc-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; animation: scPulse 1.6s ease-in-out infinite; }
    @keyframes scPulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) { .sc-live-dot { animation: none; } }
</style>

<div class="container-fluid px-0 wr-sc-page" id="scContent">

    <div class="sc-hero mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="small opacity-75 mb-1"><i class="bi bi-collection-play-fill me-1"></i><?= h(t('scenario.page_label')) ?></div>
            <h1><?= h($mission['title']) ?></h1>
            <div class="small opacity-75 mt-1">
                <?= h($mission['location'] ?? '') ?>
                <?php if (!empty($mission['department_name'])): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <span class="sc-live"><span class="sc-live-dot"></span><?= h(t('scenario.page_live')) ?></span>
            <div class="mt-2 d-flex gap-2 justify-content-end">
                <!-- The printable briefing (v3.467.0): every phase and its orders, on paper or as a PDF, for an exercise's own
                     briefing or for the record once the mission closes. mission-scenario-print.php reads the plan itself, so
                     this is a plain link - a new tab, named for the mission, so a second press brings the same one back. -->
                <a href="mission-scenario-print.php?id=<?= $missionId ?>" target="wrScenarioPrint<?= $missionId ?>" class="btn btn-sm btn-outline-light" title="<?= h(t('scenario.print_hero_title')) ?>"><?= h(t('scenario.print_hero_btn')) ?></a>
                <a href="war-room.php?id=<?= $missionId ?>" class="btn btn-sm btn-outline-light" data-sc-room-link="1"><i class="bi bi-arrow-left me-1"></i><?= h(t('scenario.page_open_room')) ?></a>
            </div>
        </div>
    </div>

    <div class="alert alert-light border small mb-3" data-sc-page-note="1">
        <div><?= h(t('scenario.note')) ?></div>
        <div class="mt-1"><i class="bi bi-map me-1"></i><?= h(t('scenario.page_map_hint')) ?></div>
    </div>

    <div class="card shadow-sm border-primary">
        <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-collection-play-fill me-1"></i><?= h(t('scenario.card_title')) ?></h5></div>
        <div class="card-body">
            <div id="scenarioBody" data-sc-mode="page"><div class="small text-muted"><?= h(t('common.loading')) ?></div></div>
        </div>
    </div>
</div>

<script>
// What assets/js/war-room-scenario.js reaches for, defined here because this is not the Action Room: the mission, the
// CSRF token, and the few words it asks t() for. The script itself is the same file, byte for byte, for every mission.
const WR_MISSION_ID = <?= (int) $missionId ?>;
const csrfToken = <?= json_encode(csrfToken(), $jsonFlags) ?>;
const WR_STRINGS = <?= json_encode($pageStrings['strings'], $jsonFlags) ?>;
const WR_STRINGS_FALLBACK = <?= json_encode($pageStrings['fallback'], $jsonFlags) ?>;
function t(key, vars = {}) {
    let text = WR_STRINGS[key] ?? WR_STRINGS_FALLBACK[key] ?? key;
    for (const [k, v] of Object.entries(vars)) text = text.replaceAll('{' + k + '}', String(v));
    return text;
}
</script>
<script src="<?= $baseUrl ?>/assets/js/war-room-utils.js?v=<?= APP_VERSION ?>"></script>
<script src="<?= $baseUrl ?>/assets/js/war-room-scenario.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime($scriptFile) ?>"></script>
<script>window.wrScenario.init();</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
