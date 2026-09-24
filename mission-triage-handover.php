<?php
/**
 * VolunteerOps - Casualty handover sheet («Παράδοση στο ΕΚΑΒ»)
 *
 * Volunteers are very often first on scene. When the ambulance service
 * arrives, the one thing its officer needs from the command post is a single
 * page: how many, which colours, where the reds are that have not left yet,
 * where the collection point is, and who has already gone to which hospital.
 * This is that page — printable, or simply shown on a tablet.
 *
 * Command staff only, and UNMASKED: a medical handover is precisely where a
 * casualty's name and phone matter, unlike the archival PDF
 * (mission-report-print.php), which always masks. The sheet says so on its
 * face. Own <!DOCTYPE html> with no shared header, same as the other print
 * views, but bilingual through t() because it is part of the live Action Room,
 * not the post-mission archive.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = (int) getCurrentUserId();
$missionId = (int) get('id');

$mission = dbFetchOne(
    "SELECT id, title, location, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    setFlash('error', t('triage.err_manage'));
    redirect('war-room.php?id=' . $missionId);
}

$state = loadTriageStateForMission($missionId, true, $userId);
$victims = $state['victims'] ?? [];
$waiting = array_values(array_filter($victims, fn($v) => $v['status'] !== 'transported'));
$transported = array_values(array_filter($victims, fn($v) => $v['status'] === 'transported'));
$counts = $state['counts'] ?? ['red' => 0, 'yellow' => 0, 'green' => 0, 'black' => 0];
$walking = (int) ($state['walking'] ?? 0);
$lang = getUserLanguage($userId);

$catColors = ['red' => ['#c62828', '#fff'], 'yellow' => ['#f9a825', '#1f1300'], 'green' => ['#2e7d32', '#fff'], 'black' => ['#212121', '#fff']];
$coords = fn(?float $lat, ?float $lng) => $lat !== null ? sprintf('%.5f, %.5f', $lat, $lng) : '—';
$identity = function (array $v): string {
    return implode(' · ', array_filter([
        $v['patient_name'],
        $v['estimated_age'],
        $v['gender'] ? incidentGenderLabel($v['gender']) : null,
        $v['phone'],
    ]));
};
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('triage.handover_title')) ?> — <?= h($mission['title']) ?></title>
<style>
    body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 24px; font-size: 14px; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    h2 { font-size: 16px; margin: 22px 0 6px; border-bottom: 2px solid #111; padding-bottom: 3px; }
    .meta { color: #444; font-size: 13px; }
    .privacy { margin-top: 8px; padding: 6px 10px; border: 1px solid #b91c1c; color: #b91c1c; font-size: 12px; display: inline-block; }
    .counts { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
    .count { min-width: 110px; padding: 8px 10px; border-radius: 6px; text-align: center; }
    .count .n { font-size: 28px; font-weight: 800; line-height: 1; }
    .count .l { font-size: 12px; }
    table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    th, td { border: 1px solid #999; padding: 5px 6px; text-align: left; vertical-align: top; font-size: 13px; }
    th { background: #eee; }
    .chip { display: inline-block; padding: 1px 7px; border-radius: 4px; font-weight: 700; }
    .code { font-family: Consolas, monospace; font-weight: 700; }
    .points { margin-top: 10px; font-size: 13px; }
    .toolbar { margin-bottom: 14px; }
    .toolbar button { font-size: 15px; padding: 6px 16px; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @media print { .toolbar { display: none; } body { margin: 10mm; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()"><?= h(t('triage.handover_print')) ?></button></div>

<h1><?= h(t('triage.handover_title')) ?> — <?= h($mission['title']) ?></h1>
<div class="meta"><?= h($mission['location'] ?? '') ?> · <?= h(t('triage.handover_generated', ['time' => date('d/m/Y H:i')])) ?></div>
<div class="privacy"><?= h(t('triage.handover_privacy')) ?></div>

<div class="counts">
    <?php foreach (TRIAGE_CATEGORIES as $cat): ?>
    <div class="count" style="background:<?= $catColors[$cat][0] ?>;color:<?= $catColors[$cat][1] ?>;">
        <div class="n"><?= (int) $counts[$cat] + ($cat === 'green' ? $walking : 0) ?></div>
        <div class="l"><?= h(triageCategoryLabel($cat)) ?> · <?= h(t('triage.cat_desc.' . $cat)) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php if ($walking): ?>
<div class="meta" style="margin-top:6px;"><?= h(t('triage.walking_count', ['n' => $walking])) ?></div>
<?php endif; ?>

<div class="points">
    <?php if (!empty($state['ccp'])): ?>
    <div><strong><?= h(t('triage.ccp_full')) ?>:</strong> <?= h($coords($state['ccp']['lat'], $state['ccp']['lng'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($state['green'])): ?>
    <div><strong><?= h(t('triage.green_area')) ?>:</strong> <?= h($coords($state['green']['lat'], $state['green']['lng'])) ?></div>
    <?php endif; ?>
</div>

<?php foreach ([[t('triage.handover_waiting'), $waiting, false], [t('triage.handover_transported'), $transported, true]] as [$heading, $rows, $isGone]): ?>
<h2><?= h($heading) ?> (<?= count($rows) ?>)</h2>
<?php if (!$rows): ?>
<p><?= h(t('triage.handover_none')) ?></p>
<?php else: ?>
<table>
    <thead><tr>
        <th><?= h(t('triage.handover_col_code')) ?></th>
        <th><?= h(t('triage.handover_col_category')) ?></th>
        <th><?= h(t('triage.handover_col_reason')) ?></th>
        <th><?= h(t('triage.handover_col_time')) ?></th>
        <?php if ($isGone): ?>
        <th><?= h(t('triage.handover_col_destination')) ?></th>
        <?php else: ?>
        <th><?= h(t('triage.handover_col_status')) ?></th>
        <th><?= h(t('triage.handover_col_location')) ?></th>
        <?php endif; ?>
        <th><?= h(t('triage.handover_col_identity')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $v): ?>
    <tr>
        <td class="code"><?= h($v['code']) ?><?= $v['age_group'] === 'child' ? ' (' . h(t('triage.child_badge')) . ')' : '' ?></td>
        <td><span class="chip" style="background:<?= $catColors[$v['category']][0] ?>;color:<?= $catColors[$v['category']][1] ?>;"><?= h(triageCategoryLabel($v['category'])) ?></span></td>
        <td><?= h($v['reason']) ?></td>
        <td><?= h($v['first_at']) ?><?= $v['last_at'] !== $v['first_at'] ? ' / ' . h($v['last_at']) : '' ?></td>
        <?php if ($isGone): ?>
        <td><?= h(implode(' · ', array_filter([$v['vehicle'], $v['destination'], $v['status_at']]))) ?: '—' ?></td>
        <?php else: ?>
        <td><?= h(triageStatusLabel($v['status'])) ?></td>
        <td><?= h($coords($v['lat'], $v['lng'])) ?></td>
        <?php endif; ?>
        <td><?= h($identity($v)) ?: '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php endforeach; ?>
</body>
</html>
