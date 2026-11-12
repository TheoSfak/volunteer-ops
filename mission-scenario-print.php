<?php
/**
 * VolunteerOps - the Scenario, as a printable briefing (v3.467.0, redesigned v3.470.0)
 *
 * Every phase of the plan and every order in it, on paper or as a PDF: for briefing an exercise before it runs, or
 * for filing the plan once it has. A link from the Scenario page's own header («🖶 Εκτύπωση σεναρίου»), opened in a
 * new tab the same way mission-certificate-print.php and mission-report-print.php are - this app's own browser
 * print-to-PDF convention, no server-side PDF library, own standalone <!DOCTYPE> and <style>, window.print().
 *
 * Same gate as the Scenario page (command account AND system admin, v3.466.0): a plan is not shown to
 * anyone else, printed or on screen. Unlike the page it does NOT also require the mission to be open and on the
 * board - printing reads nothing live and sends nothing, so a plan stays printable for the record after a mission
 * closes, which is exactly when a briefing is most likely to be wanted for filing.
 *
 * Bilingual through the ordinary t() table, like the rest of the Scenario feature (scenarioPrintItemLine() in
 * includes/functions-scenario.php is the one place the words AND the icon/colour for a staged order's kind, target
 * and state are worked out, in PHP, for a page with no script to read them from a live card) - unlike
 * mission-report-print.php and mission-stats.php, which stay deliberately Greek-only for reasons specific to that
 * archival report.
 *
 * v3.470.0: visually joined to the mission-report-print.php/mission-certificate-print.php family (Inter font,
 * Bootstrap Icons, the same gradient hero and white rounded cards) instead of the plain bordered boxes it had
 * before, and a timestamp wherever one exists: the summary tiles say how many phases are already sent, every
 * phase keeps its "built at"/"played at" lines, and every SENT item now also carries its own "Στάλθηκε: d/m H:i" -
 * which v3.467.0 never printed, only the live card showed it.
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

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId) || !isSystemAdmin()) {
    setFlash('error', t('scenario.no_permission'));
    redirect('mission-view.php?id=' . $missionId);
}

$lang = (string) ($user['language'] ?? DEFAULT_LANGUAGE);
$scenario = loadMissionScenario($missionId);
$phases = $scenario['phases'];
$slots = $scenario['slots'];

// The summary tiles: how much of the plan is already on its way, worked out here (print-only presentation, not
// something another page needs) rather than in loadMissionScenario().
$totalItems = 0;
$sentPhases = 0;
foreach ($phases as $p) {
    $totalItems += count($p['items']);
    if (!empty($p['played_at'])) {
        $sentPhases++;
    }
}

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$printedAt = date('d/m/Y H:i');
$printBackUrl = 'mission-scenario-view.php?id=' . $missionId;
?><!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(t('scenario.card_title')) ?> - <?= h($mission['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', Arial, Helvetica, sans-serif; font-size: 10pt; color: #1b1b18; background: #f4f4f2; margin: 0; padding: 24px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .scp-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 16px; padding: 22px 26px; margin-bottom: 16px; page-break-inside: avoid; }
        .scp-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .scp-hero .org { font-size: 9pt; opacity: .85; display: flex; align-items: center; gap: 8px; }
        .scp-hero .org img { height: 24px; width: auto; border-radius: 5px; }
        .scp-hero h1 { font-size: 18pt; font-weight: 800; margin: 6px 0 4px; }
        .scp-hero .sub { font-size: 9.5pt; opacity: .92; }
        .scp-hero .meta { text-align: right; font-size: 8.5pt; opacity: .92; line-height: 1.7; white-space: nowrap; }

        .scp-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
        .scp-tile { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 12px 8px; text-align: center; page-break-inside: avoid; }
        .scp-tile .v { font-size: 1.3rem; font-weight: 800; color: #172554; line-height: 1.1; }
        .scp-tile .l { font-size: 7pt; color: #52514e; text-transform: uppercase; letter-spacing: .03em; margin-top: 3px; }

        .scp-phase { background: #fff; border: 1px solid #eee; border-left: 5px solid #c9c7bd; border-radius: 0 14px 14px 0; padding: 14px 18px; margin-bottom: 12px; page-break-inside: avoid; }
        .scp-phase.sent { border-left-color: #198754; }
        .scp-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
        .scp-head h2 { font-size: 12.5pt; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 7px; color: #172554; }
        .scp-head h2 i { color: #6b7280; }
        .scp-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 11px; border-radius: 999px; font-size: 7.8pt; font-weight: 700; white-space: nowrap; }
        .scp-badge.sent { background: rgba(25,135,84,.12); color: #146c43; }
        .scp-badge.unsent { background: rgba(108,117,125,.14); color: #52514e; }

        .scp-meta { font-size: 8.3pt; color: #6b6a64; margin: 2px 0; display: flex; align-items: center; gap: 5px; }
        .scp-meta i { font-size: 8pt; }

        ul.scp-items { list-style: none; margin: 10px 0 0; padding: 0; }
        .scp-item { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; border-top: 1px dashed #e4e3dc; }
        .scp-item:first-child { border-top: 0; }
        .scp-item > i.bi { color: #6b7280; font-size: 10.5pt; margin-top: 1px; flex-shrink: 0; }
        .scp-item-body { flex: 1; min-width: 0; }
        .scp-item-title { font-weight: 600; }
        .scp-item-arrow { color: #9ca3af; }
        .scp-item-target { display: inline-block; background: #eef1fb; color: #172554; border-radius: 999px; padding: 0 9px; font-size: 8.3pt; font-weight: 600; }
        .scp-item-time { color: #898781; font-size: 8pt; white-space: nowrap; margin-top: 2px; display: flex; align-items: center; gap: 4px; }
        .scp-state { flex-shrink: 0; align-self: flex-start; display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: 7.6pt; font-weight: 700; white-space: nowrap; margin-top: 1px; }
        .scp-state.success { background: rgba(25,135,84,.12); color: #146c43; }
        .scp-state.primary { background: rgba(13,110,253,.1); color: #0a58ca; }
        .scp-state.warning { background: rgba(255,193,7,.16); color: #8a5300; }
        .scp-state.danger { background: rgba(220,53,69,.12); color: #b02a37; }
        .scp-state.secondary { background: rgba(108,117,125,.14); color: #52514e; }
        .scp-state.info { background: rgba(13,202,240,.14); color: #0a6e85; }
        .scp-state.muted { background: #f1f0eb; color: #898781; }

        .scp-empty { color: #898781; font-style: italic; font-size: 9pt; padding: 4px 0; }

        .scp-slots { background: #fff; border: 1px solid #eee; border-radius: 14px; padding: 14px 18px; margin-top: 4px; page-break-inside: avoid; }
        .scp-slots h2 { font-size: 11.5pt; font-weight: 800; margin: 0 0 8px; display: flex; align-items: center; gap: 7px; color: #172554; }
        ul.scp-slot-list { margin: 0; padding: 0; list-style: none; }
        ul.scp-slot-list li { display: flex; align-items: center; gap: 7px; padding: 4px 0; font-size: 9.5pt; border-top: 1px dashed #e4e3dc; }
        ul.scp-slot-list li:first-child { border-top: 0; }
        ul.scp-slot-list li i { color: #6b7280; }

        .scp-footer { margin-top: 18px; border-top: 1px solid #ddd; padding-top: 8px; font-size: 8pt; color: #898781; display: flex; justify-content: space-between; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #172554; color: #fff; text-align: center; padding: 8px 16px; font-size: 10pt; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; }
        .screen-notice button { background: #fff; color: #172554; border: none; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: bold; }
        .screen-notice button:hover { background: #dbe4f5; }
        .doc-body { margin-top: 48px; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 10mm; background: #fff; }
            @page { size: A4 portrait; margin: 10mm; }
            .doc-body { margin-top: 0 !important; }
            .scp-phase, .scp-tile, .scp-slots { break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="screen-notice">
    <span><?= h(t('scenario.print_notice')) ?></span>
    <button onclick="window.print()"><?= h(t('scenario.print_btn')) ?></button>
    <button onclick="vopsClosePage()"><?= h(t('common.close')) ?></button>
</div>

<div class="doc-body">
    <div class="scp-hero">
        <div class="scp-hero-top">
            <div>
                <div class="org">
                    <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($orgName) ?>"><?php endif; ?>
                    <span><?= h($orgName) ?> · <i class="bi bi-collection-play-fill"></i> <?= h(t('scenario.card_title')) ?></span>
                </div>
                <h1><?= h($mission['title']) ?></h1>
                <div class="sub">
                    <?= h($mission['location'] ?? '') ?><?php if (!empty($mission['department_name'])): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
                </div>
            </div>
            <div class="meta">
                <div><i class="bi bi-printer-fill"></i> <?= h(t('scenario.print_generated_at', ['time' => $printedAt])) ?></div>
                <div><i class="bi bi-person-fill"></i> <?= h($user['name']) ?></div>
            </div>
        </div>
    </div>

    <div class="scp-tiles">
        <div class="scp-tile"><div class="v"><?= count($phases) ?></div><div class="l"><?= h(t('scenario.print_summary_phases', ['n' => count($phases)])) ?></div></div>
        <div class="scp-tile"><div class="v"><?= $sentPhases ?>/<?= count($phases) ?></div><div class="l"><?= h(t('scenario.print_summary_sent', ['sent' => $sentPhases, 'total' => count($phases)])) ?></div></div>
        <div class="scp-tile"><div class="v"><?= $totalItems ?></div><div class="l"><?= h(t('scenario.print_summary_orders', ['n' => $totalItems])) ?></div></div>
    </div>

    <?php if (!$phases): ?>
        <p class="scp-empty"><?= h(t('scenario.print_no_phases')) ?></p>
    <?php else: foreach ($phases as $phase): $sent = !empty($phase['played_at']); ?>
        <div class="scp-phase<?= $sent ? ' sent' : '' ?>">
            <div class="scp-head">
                <h2><i class="bi bi-collection-play-fill"></i> <?= h(t('scenario.phase_n', ['n' => $phase['n']])) ?><?php if (!empty($phase['title'])): ?> <span style="color:#6b6a64;font-weight:600;">· <?= h($phase['title']) ?></span><?php endif; ?></h2>
                <span class="scp-badge <?= $sent ? 'sent' : 'unsent' ?>"><i class="bi <?= $sent ? 'bi-check-circle-fill' : 'bi-hourglass-split' ?>"></i> <?= h(t($sent ? 'scenario.print_badge_sent' : 'scenario.print_badge_unsent')) ?></span>
            </div>
            <?php if ($phase['created_label']): ?><div class="scp-meta"><i class="bi bi-pencil-square"></i> <?= h(t('scenario.created_line', ['time' => $phase['created_label'], 'who' => $phase['created_by_name'] ?? ''])) ?></div><?php endif; ?>
            <?php if ($sent): ?>
                <div class="scp-meta"><i class="bi bi-play-circle-fill"></i> <?= h(t('scenario.played_line', ['time' => $phase['played_label'] ?? '', 'who' => $phase['played_by_name'] ?? ''])) ?></div>
            <?php else: ?>
                <div class="scp-meta"><i class="bi bi-hourglass-split"></i> <?= h(t('scenario.print_not_sent')) ?></div>
            <?php endif; ?>
            <?php if (!$phase['items']): ?>
                <p class="scp-empty"><?= h(t('scenario.phase_no_orders')) ?></p>
            <?php else: ?>
                <ul class="scp-items">
                    <?php foreach ($phase['items'] as $item): $line = scenarioPrintItemLine($item); ?>
                        <li class="scp-item">
                            <i class="bi <?= h($line['icon']) ?>"></i>
                            <div class="scp-item-body">
                                <span class="scp-item-title"><?= h($line['title']) ?></span>
                                <span class="scp-item-arrow">&rarr;</span>
                                <span class="scp-item-target"><?= h($line['target']) ?></span>
                                <?php if ($line['time']): ?>
                                    <div class="scp-item-time"><i class="bi bi-clock-history"></i> <?= h(t('scenario.print_sent_at', ['time' => $line['time']])) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="scp-state <?= h($line['tone']) ?>"><?= h($line['state']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

    <?php if ($slots): ?>
        <div class="scp-slots">
            <h2><i class="bi bi-people-fill"></i> <?= h(t('scenario.slots_title')) ?></h2>
            <ul class="scp-slot-list">
                <?php foreach ($slots as $slot): ?>
                    <li>
                        <i class="bi <?= $slot['mapped'] ? 'bi-person-check-fill' : 'bi-person-dash-fill' ?>"></i>
                        <?= h($slot['mapped']
                            ? t('scenario.print_slots_mapped', ['label' => $slot['label'], 'team' => $slot['team_label']])
                            : t('scenario.print_slots_unmapped', ['label' => $slot['label']])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="scp-footer">
        <span><?= h($orgName) ?> &mdash; <?= h(t('scenario.print_footer_tag')) ?></span>
        <span><i class="bi bi-printer-fill"></i> <?= h(t('scenario.print_generated_at', ['time' => $printedAt])) ?></span>
    </div>
</div>

<script>
if (window.matchMedia('(min-width: 769px)').matches) {
    setTimeout(function () { window.print(); }, 400);
}
</script>
<?php require __DIR__ . '/includes/print-close-script.php'; ?>
<?php require __DIR__ . '/includes/inactivity-timeout.php'; ?>
</body>
</html>
