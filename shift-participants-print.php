<?php
/**
 * VolunteerOps — printable participant list for ONE shift of ONE mission.
 *
 * The sheet that goes on a clipboard at the meeting point: who is down for this
 * shift, how to reach them, which Action Room team they are in, and an empty
 * box + signature line for the roll call. Approved volunteers only by default;
 * ?status=all adds the pending applications (labelled) for a planning printout.
 * Cancelled and rejected applications never print.
 *
 * Read-only, and gated like the shift's own management actions (admins, the
 * missions_manage page permission, or the mission's responsible user). It
 * prints phone numbers, so an ordinary volunteer must not reach it. Unlike the
 * management actions on shift-view.php it stays available on a COMPLETED
 * mission: printing the roll afterwards is exactly when you want the attended
 * ticks.
 */

require_once __DIR__ . '/bootstrap.php';

requireLogin();

$shiftId = (int) get('id');
$userId  = getCurrentUserId();

$shift = dbFetchOne(
    "SELECT s.*, m.title AS mission_title, m.location AS mission_location,
            m.mission_type_id, m.responsible_user_id, m.id AS mission_id
     FROM shifts s
     JOIN missions m ON m.id = s.mission_id AND m.deleted_at IS NULL
     WHERE s.id = ?",
    [$shiftId]
);
if (!$shift) {
    setFlash('error', 'Η βάρδια δεν βρέθηκε.');
    redirect('shifts.php');
}

$isResponsible = (int) $shift['responsible_user_id'] === (int) $userId;
if (!isAdmin() && !hasPagePermission('missions_manage') && !$isResponsible) {
    setFlash('error', 'Η εκτύπωση συμμετεχόντων είναι διαθέσιμη μόνο σε διαχειριστές.');
    redirect('shift-view.php?id=' . $shiftId);
}
if (isTepMission((int) $shift['mission_type_id']) && !canSeeTep((int) $shift['responsible_user_id'])) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε βάρδιες αποστολών Τ.Ε.Π.');
    redirect('missions.php');
}

$includePending = get('status') === 'all';
$statuses = $includePending
    ? [PARTICIPATION_APPROVED, PARTICIPATION_PENDING]
    : [PARTICIPATION_APPROVED];
$in = implode(',', array_fill(0, count($statuses), '?'));

// One team per user per mission (uniq_mission_user), so the LEFT JOIN cannot
// duplicate a row.
$rows = dbFetchAll(
    "SELECT pr.status, pr.attended, pr.actual_hours,
            u.name, u.phone, u.volunteer_type, u.is_external, u.guest_org_name,
            mt.codename, mt.team_number
     FROM participation_requests pr
     JOIN users u ON u.id = pr.volunteer_id
     LEFT JOIN mission_team_members mtm ON mtm.user_id = u.id AND mtm.mission_id = ?
     LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
     WHERE pr.shift_id = ? AND pr.status IN ($in)
     ORDER BY pr.status ASC, u.name ASC",
    array_merge([(int) $shift['mission_id'], $shiftId], $statuses)
);

$approvedCount = 0;
$hasTeams = false;
foreach ($rows as $r) {
    if ($r['status'] === PARTICIPATION_APPROVED) $approvedCount++;
    if ($r['codename'] !== null && $r['codename'] !== '') $hasTeams = true;
}

$orgName   = getSetting('org_name', 'VolunteerOps');
$appLogo   = getSetting('app_logo', '');
$hasLogo   = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$printDate = date('d/m/Y H:i');

$startTs = strtotime((string) $shift['start_time']);
$endTs   = strtotime((string) $shift['end_time']);
$sameDay = $startTs && $endTs && date('Y-m-d', $startTs) === date('Y-m-d', $endTs);
$when = $startTs ? date('d/m/Y H:i', $startTs) : '—';
if ($endTs) {
    $when .= ' – ' . ($sameDay ? date('H:i', $endTs) : date('d/m/Y H:i', $endTs));
}

logAudit('print', 'shift_participants', $shiftId, $includePending ? 'incl. pending' : 'approved');
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Συμμετέχοντες — <?= h($shift['mission_title']) ?> — <?= h($when) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #1a1a1a; background: #f4f4f2; padding: 15mm 12mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #172554; color: #fff; padding: 8px 14px; display: flex; align-items: center; gap: 12px; font-size: 9pt; z-index: 50; }
        .screen-notice button, .screen-notice a { background: #fff; color: #172554; border: 0; border-radius: 6px; padding: 4px 12px; font-weight: 700; cursor: pointer; font-family: inherit; font-size: inherit; text-decoration: none; }

        .head { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 14px 18px; margin-top: 36px; margin-bottom: 12px; }
        .head .org { font-size: 9pt; color: #555; display: flex; align-items: center; gap: 8px; }
        .head .org img { height: 24px; width: auto; }
        .head .kicker { font-size: 8.5pt; letter-spacing: .1em; text-transform: uppercase; color: #172554; margin-top: 8px; font-weight: 700; }
        .head .title { font-size: 16pt; font-weight: 800; margin: 2px 0 6px; color: #172554; }
        .head .meta { font-size: 9.5pt; line-height: 1.7; }
        .head .meta b { font-weight: 700; }

        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { border: 1px solid #bbb; padding: 6px 8px; text-align: left; vertical-align: middle; }
        th { background: #eef0f6; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #172554; }
        td.n { width: 8mm; text-align: center; color: #666; }
        td.box { width: 18mm; text-align: center; }
        td.sig { width: 42mm; }
        td.phone { white-space: nowrap; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .tick { display: inline-block; width: 4.5mm; height: 4.5mm; border: 1.3px solid #333; vertical-align: middle; }
        .tick.on { background: #198754; border-color: #198754; color: #fff; font-size: 9pt; line-height: 4mm; text-align: center; font-weight: 700; }
        .pending td { color: #8a6d00; background: #fff9e6; }
        .tag { font-size: 7.5pt; color: #666; display: block; }
        .empty { text-align: center; padding: 18px; color: #666; }

        .foot { font-size: 8pt; color: #666; margin-top: 10px; display: flex; justify-content: space-between; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; background: #fff; }
            @page { size: A4 portrait; margin: 14mm 12mm; }
            .head { margin-top: 0; }
        }
    </style>
</head>
<body>

<div class="screen-notice">
    <span>Προεπισκόπηση εκτύπωσης &mdash; Συμμετέχοντες βάρδιας</span>
    <button onclick="window.print()">Εκτύπωση / PDF</button>
    <?php if ($includePending): ?>
        <a href="shift-participants-print.php?id=<?= $shiftId ?>">Μόνο εγκεκριμένοι</a>
    <?php else: ?>
        <a href="shift-participants-print.php?id=<?= $shiftId ?>&amp;status=all">Και εκκρεμείς</a>
    <?php endif; ?>
    <button onclick="window.close()">Κλείσιμο</button>
</div>

<div class="head">
    <div class="org">
        <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt=""><?php endif; ?>
        <span><?= h($orgName) ?></span>
    </div>
    <div class="kicker">Κατάσταση συμμετεχόντων βάρδιας</div>
    <div class="title"><?= h($shift['mission_title']) ?></div>
    <div class="meta">
        <b>Βάρδια:</b> <?= h($when) ?><br>
        <?php if (!empty($shift['mission_location'])): ?><b>Τοποθεσία:</b> <?= h($shift['mission_location']) ?><br><?php endif; ?>
        <b>Εγκεκριμένοι:</b> <?= $approvedCount ?><?= $shift['max_volunteers'] ? ' / ' . (int) $shift['max_volunteers'] : '' ?>
        <?php if ($includePending && count($rows) > $approvedCount): ?>
            &middot; <b>Εκκρεμείς:</b> <?= count($rows) - $approvedCount ?>
        <?php endif; ?>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Ονοματεπώνυμο</th>
            <th>Τηλέφωνο</th>
            <?php if ($hasTeams): ?><th>Ομάδα</th><?php endif; ?>
            <th>Παρών</th>
            <th>Υπογραφή</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?= $hasTeams ? 6 : 5 ?>" class="empty">Δεν υπάρχουν εγκεκριμένοι συμμετέχοντες σε αυτή τη βάρδια.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r):
        $isPending = $r['status'] === PARTICIPATION_PENDING;
        $typeLabel = !empty($r['is_external'])
            ? ($r['guest_org_name'] ?: 'Φιλοξενούμενος')
            : (VOLUNTEER_TYPE_LABELS[$r['volunteer_type']] ?? '');
    ?>
        <tr class="<?= $isPending ? 'pending' : '' ?>">
            <td class="n"><?= $i + 1 ?></td>
            <td>
                <strong><?= h($r['name']) ?></strong>
                <?php if ($typeLabel !== '' || $isPending): ?>
                    <span class="tag"><?= h(trim($typeLabel . ($isPending ? ($typeLabel !== '' ? ' · ' : '') . 'Εκκρεμεί έγκριση' : ''))) ?></span>
                <?php endif; ?>
            </td>
            <td class="phone"><?= $r['phone'] ? h($r['phone']) : '—' ?></td>
            <?php if ($hasTeams): ?>
                <td><?= h(teamLabel($r['codename'], $r['team_number'])) ?: '—' ?></td>
            <?php endif; ?>
            <td class="box">
                <?php if (!empty($r['attended'])): ?>
                    <span class="tick on">&#10003;</span>
                <?php else: ?>
                    <span class="tick"></span>
                <?php endif; ?>
            </td>
            <td class="sig"></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="foot">
    <span><?= h($orgName) ?> &middot; Εκτυπώθηκε <?= h($printDate) ?></span>
    <span>Σύνολο: <?= count($rows) ?></span>
</div>

</body>
</html>
