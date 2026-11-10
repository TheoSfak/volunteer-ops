<?php
/**
 * VolunteerOps - the Scenario, as a printable briefing (v3.467.0)
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
 * includes/functions-scenario.php is the one place the words for a staged order's kind, target and state are
 * worked out, in PHP, for a page with no script to read them from a live card) - unlike mission-report-print.php
 * and mission-stats.php, which stay deliberately Greek-only for reasons specific to that archival report.
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
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #1b1b18; background: #fff; margin: 0; padding: 24px; }
        h1 { font-size: 19pt; margin: 0 0 2px; }
        .sub { color: #5b5a55; font-size: 10pt; margin-bottom: 16px; }
        .doc-header { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #172554; padding-bottom: 10px; margin-bottom: 14px; }
        .doc-header img { height: 44px; }
        .phase { border: 1px solid #c9c7bd; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; page-break-inside: avoid; }
        .phase h2 { font-size: 13pt; margin: 0 0 2px; }
        .phase .meta { font-size: 9pt; color: #5b5a55; margin-bottom: 6px; }
        .phase .meta div { margin-bottom: 1px; }
        ul.items { list-style: none; margin: 6px 0 0; padding: 0; }
        ul.items li { padding: 4px 0; border-top: 1px dashed #d8d6cc; font-size: 10.5pt; }
        ul.items li:first-child { border-top: 0; }
        .item-target { color: #172554; font-weight: 600; }
        .item-state { color: #5b5a55; font-size: 9.5pt; }
        .no-orders, .no-phases { color: #5b5a55; font-style: italic; font-size: 10pt; }
        .slots { margin-top: 18px; }
        .slots h2 { font-size: 12pt; margin: 0 0 6px; }
        .slots ul { margin: 0; padding-left: 18px; font-size: 10.5pt; }
        .footer-note { margin-top: 20px; font-size: 8.5pt; color: #918f86; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #172554; color: #fff; text-align: center; padding: 8px 16px; font-size: 10pt; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; }
        .screen-notice button { background: #fff; color: #172554; border: none; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: bold; }
        .screen-notice button:hover { background: #dbe4f5; }
        .doc-body { margin-top: 48px; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 10mm; }
            @page { size: A4 portrait; margin: 10mm; }
            .doc-body { margin-top: 0 !important; }
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
    <div class="doc-header">
        <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($orgName) ?>"><?php endif; ?>
        <div>
            <h1><?= h(t('scenario.card_title')) ?>: <?= h($mission['title']) ?></h1>
            <div class="sub">
                <?= h($mission['location'] ?? '') ?><?php if (!empty($mission['department_name'])): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
                · <?= h(t('scenario.print_generated_at', ['time' => $printedAt])) ?> · <?= h($user['name']) ?>
            </div>
        </div>
    </div>

    <?php if (!$phases): ?>
        <p class="no-phases"><?= h(t('scenario.print_no_phases')) ?></p>
    <?php else: foreach ($phases as $phase): ?>
        <div class="phase">
            <h2><?= h(t('scenario.phase_n', ['n' => $phase['n']])) ?><?php if (!empty($phase['title'])): ?> · <?= h($phase['title']) ?><?php endif; ?></h2>
            <div class="meta">
                <?php if ($phase['created_label']): ?><div><?= h(t('scenario.created_line', ['time' => $phase['created_label'], 'who' => $phase['created_by_name'] ?? ''])) ?></div><?php endif; ?>
                <?php if ($phase['played_at']): ?>
                    <div><?= h(t('scenario.played_line', ['time' => $phase['played_label'] ?? '', 'who' => $phase['played_by_name'] ?? ''])) ?></div>
                <?php else: ?>
                    <div><?= h(t('scenario.print_not_sent')) ?></div>
                <?php endif; ?>
            </div>
            <?php if (!$phase['items']): ?>
                <p class="no-orders"><?= h(t('scenario.phase_no_orders')) ?></p>
            <?php else: ?>
                <ul class="items">
                    <?php foreach ($phase['items'] as $item): $line = scenarioPrintItemLine($item); ?>
                        <li>
                            <?= h($line['title']) ?> <span class="item-target">&rarr; <?= h($line['target']) ?></span><br>
                            <span class="item-state"><?= h($line['state']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; endif; ?>

    <?php if ($slots): ?>
        <div class="slots">
            <h2><?= h(t('scenario.slots_title')) ?></h2>
            <ul>
                <?php foreach ($slots as $slot): ?>
                    <li><?= h($slot['mapped']
                        ? t('scenario.print_slots_mapped', ['label' => $slot['label'], 'team' => $slot['team_label']])
                        : t('scenario.print_slots_unmapped', ['label' => $slot['label']])) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p class="footer-note"><?= h(t('scenario.note')) ?></p>
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
