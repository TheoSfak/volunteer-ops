<?php
/**
 * VolunteerOps - Pre-Deployment Briefing (PUBLIC - no login required)
 *
 * Per-team, no-login page for "special" missions with guest/partner-org
 * teams arriving from abroad — shows RV point, radio channel, coordinator,
 * shift time, and roster before the team even has app access. One of a
 * small number of genuinely public pages in this app (see also
 * certificate-verify.php, newsletter-unsubscribe.php).
 *
 * URL: /briefing-view.php?token=XXXX
 *
 * The link IS the access control, and it self-expires: the lookup below
 * requires the mission to still be OPEN, so the moment an admin closes the
 * mission the same token simply stops resolving — no separate revoke step,
 * and deliberately no distinction in the error state between "never
 * existed" and "mission closed" (that would need a second lookup ignoring
 * the expiry gate, which would leak whether a token used to be valid).
 *
 * Bilingual on one page (Greek + English shown together, not via t()) since
 * an anonymous visitor has no known language preference — same reasoning
 * certificate-verify.php already uses.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/functions-ics.php';
// No requireLogin() — this page is intentionally public.

$token = trim(get('token', ''));

$team = $token !== '' ? dbFetchOne(
    "SELECT mt.id AS team_id, mt.codename, mt.team_number, mt.color, mt.leader_id,
            m.id AS mission_id, m.title, m.location, m.location_details,
            m.start_datetime, m.end_datetime, m.rv_point_label, m.radio_channel,
            r.name AS coordinator_name, r.phone AS coordinator_phone
     FROM mission_teams mt
     JOIN missions m ON m.id = mt.mission_id
     LEFT JOIN users r ON r.id = m.responsible_user_id
     WHERE mt.briefing_token = ? AND m.status = ? AND m.is_special_mission = 1 AND m.deleted_at IS NULL",
    [$token, STATUS_OPEN]
) : null;

$roster = [];
if ($team) {
    $roster = dbFetchAll(
        "SELECT mtm.user_id, u.name, u.is_external, u.guest_org_name, u.guest_country_code,
                vt.name AS home_team_name, vt.color AS home_team_color
         FROM mission_team_members mtm
         JOIN users u ON u.id = mtm.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         WHERE mtm.team_id = ?
         ORDER BY u.name",
        [$team['team_id']]
    );
}

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);

if ($team) {
    [$teamBg, $teamFg] = teamBadgeColors($team['color']);
    $teamLabelStr = teamLabel($team['codename'], $team['team_number']);
    $rvPoint = $team['rv_point_label'] ?: $team['location'];

    // One VEVENT, embedded as a data: URI — no second endpoint/token needed.
    $icsDescParts = ['RV / Σημείο: ' . $rvPoint];
    if ($team['radio_channel'])    $icsDescParts[] = 'Radio / Ασύρματος: ' . $team['radio_channel'];
    if ($team['coordinator_name']) $icsDescParts[] = 'Coordinator / Συντονιστής: ' . $team['coordinator_name'] . ($team['coordinator_phone'] ? ' (' . $team['coordinator_phone'] . ')' : '');
    $icsLines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//VolunteerOps//Briefing//EL', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
    array_push($icsLines, ...icsAthensTimezoneLines());
    $icsLines[] = 'BEGIN:VEVENT';
    $icsLines[] = icsFold('UID:briefing-' . $team['team_id'] . '@volunteerops');
    $icsLines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
    $icsLines[] = 'DTSTART;TZID=Europe/Athens:' . icsDate($team['start_datetime']);
    $icsLines[] = 'DTEND;TZID=Europe/Athens:' . icsDate($team['end_datetime']);
    $icsLines[] = icsFold('SUMMARY:' . icsText($team['title'] . ' — ' . $teamLabelStr));
    $icsLines[] = icsFold('DESCRIPTION:' . icsText(implode("\n", $icsDescParts)));
    $icsLines[] = icsFold('LOCATION:' . icsText($rvPoint));
    $icsLines[] = 'END:VEVENT';
    $icsLines[] = 'END:VCALENDAR';
    $icsDataUri = 'data:text/calendar;charset=utf-8;base64,' . base64_encode(implode("\r\n", $icsLines) . "\r\n");

    // ISO-8601 for the JS countdown — Safari's Date() parser rejects the
    // raw MySQL "Y-m-d H:i:s" format that Chrome silently tolerates.
    $startIso = str_replace(' ', 'T', $team['start_datetime']);
    $mapsHref = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($rvPoint);
    $telHref = $team['coordinator_phone'] ? 'tel:' . preg_replace('/[^0-9+]/', '', $team['coordinator_phone']) : '';
}
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#172554">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $team ? h($team['title'] . ' — ' . $teamLabelStr) : 'Ενημέρωση Ανάπτυξης / Pre-Deployment Briefing' ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        body { background: #eef0f4; }
        .bp-page { max-width: 640px; margin: 24px auto; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 4px 18px rgba(23,37,84,.12); }
        .bp-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; padding: 22px 22px 20px; position: relative; overflow: hidden; }
        .bp-section { padding: 20px 22px; }
        .bp-h { font-size: 13px; font-weight: 800; color: #172554; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
        .bp-icon-circle { width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 15px; }
        .bp-fact { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 12px 14px; display: flex; gap: 10px; align-items: flex-start; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .bp-fact .l { font-size: 10.5px; font-weight: 700; color: #8a8a86; text-transform: uppercase; letter-spacing: .03em; }
        .bp-fact .v { font-size: 14.5px; font-weight: 800; color: #1a1a1a; margin-top: 2px; line-height: 1.25; }
        .bp-fact .v2 { font-size: 11px; color: #8a8a86; margin-top: 2px; }
        .bp-actionbtn { display: flex; flex-direction: column; align-items: center; gap: 6px; text-decoration: none; color: #1a1a1a; flex: 1; padding: 10px 4px; border-radius: 12px; background: #f6f5f1; }
        .bp-actionbtn.disabled { opacity: .45; pointer-events: none; }
        .bp-actionbtn .ic { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 17px; }
        .bp-actionbtn span.lbl { font-size: 10.5px; font-weight: 700; text-align: center; }
        .bp-tl { display: flex; gap: 12px; }
        .bp-tl-dotcol { display: flex; flex-direction: column; align-items: center; width: 20px; flex-shrink: 0; }
        .bp-tl-dot { width: 11px; height: 11px; border-radius: 50%; background: #172554; flex-shrink: 0; margin-top: 3px; }
        .bp-tl-line { width: 2px; flex: 1; background: #e5e3dd; margin: 2px 0; }
        .bp-tl-item { padding-bottom: 16px; }
        .bp-tl-time { font-size: 11px; font-weight: 800; color: #172554; }
        .bp-tl-label { font-size: 12.5px; font-weight: 700; margin-top: 1px; }
        .bp-tl-sub { font-size: 11px; color: #8a8a86; margin-top: 1px; }
        .bp-avatar { width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: #fff; flex-shrink: 0; }
        .bp-member { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f2f2f0; }
        .bp-member:last-child { border-bottom: none; }
        .flag-icon { width: 16px; height: 12px; object-fit: cover; border-radius: 2px; vertical-align: -1px; margin-right: 3px; }
        .team-name-badge { font-size: 10px; padding: 1px 6px; border-radius: 6px; font-weight: 700; }
        @media (max-width: 480px) { .bp-page { margin: 0; border-radius: 0; } body { background: #fff; } }
    </style>
</head>
<body>

<div class="bp-page">
<?php if (!$team): ?>

    <div class="bp-section text-center py-5">
        <?php if ($token === ''): ?>
            <i class="bi bi-signpost-2" style="font-size:2.5rem;color:#918f86;"></i>
            <h2 class="mt-3 mb-2" style="font-size:1.25rem;">Ενημέρωση Ανάπτυξης<br>Pre-Deployment Briefing</h2>
            <p class="text-muted small mb-0">Χρειάζεστε τον προσωπικό σύνδεσμο της ομάδας σας.<br>You need your team's personal link.</p>
        <?php else: ?>
            <i class="bi bi-x-circle text-danger" style="font-size:2.5rem;"></i>
            <h2 class="mt-3 mb-2" style="font-size:1.25rem;">Μη έγκυρος ή ληγμένος σύνδεσμος<br><span class="fs-6">Invalid or expired link</span></h2>
            <p class="text-muted small mb-0">Αυτός ο σύνδεσμος δεν λειτουργεί πια — ίσως η αποστολή ολοκληρώθηκε.<br>This link no longer works — the mission may have concluded.<br>Επικοινωνήστε με τον συντονιστή σας. / Contact your coordinator.</p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="bp-hero">
        <svg style="position:absolute;inset:0;opacity:.12;" viewBox="0 0 400 200" preserveAspectRatio="none" aria-hidden="true">
            <path d="M-10,40 C80,10 160,70 250,40 S420,10 460,50" fill="none" stroke="#fff" stroke-width="2"/>
            <path d="M-10,90 C80,60 160,120 250,90 S420,60 460,100" fill="none" stroke="#fff" stroke-width="2"/>
            <path d="M-10,150 C80,120 160,180 250,150 S420,120 460,160" fill="none" stroke="#fff" stroke-width="2"/>
        </svg>
        <div style="position:relative;display:flex;justify-content:space-between;align-items:flex-start;gap:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <?php if ($hasLogo): ?>
                <img src="uploads/logos/<?= h($appLogo) ?>" alt="" style="width:30px;height:30px;border-radius:9px;object-fit:cover;">
                <?php else: ?>
                <div style="width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;"><?= h(mb_substr($orgName, 0, 1)) ?></div>
                <?php endif; ?>
                <div style="font-size:11px;opacity:.9;font-weight:600;"><?= h($orgName) ?></div>
            </div>
            <div id="bpCountdown" style="background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);padding:4px 11px;border-radius:999px;font-weight:800;font-size:11px;white-space:nowrap;"></div>
        </div>
        <div style="position:relative;font-size:20px;font-weight:800;margin:16px 0 3px;line-height:1.25;"><?= h($team['title']) ?></div>
        <div style="position:relative;font-size:11.5px;opacity:.9;font-weight:600;">PRE-DEPLOYMENT BRIEFING &middot; ΕΝΗΜΕΡΩΣΗ ΑΝΑΠΤΥΞΗΣ</div>
        <div style="position:relative;display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;">
            <div style="background:rgba(255,255,255,.14);padding:5px 10px;border-radius:8px;font-size:11px;display:flex;align-items:center;gap:5px;"><i class="bi bi-calendar" aria-hidden="true"></i><?= h(formatDate($team['start_datetime'])) ?></div>
            <div style="background:rgba(255,255,255,.14);padding:5px 10px;border-radius:8px;font-size:11px;display:flex;align-items:center;gap:5px;"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= h($team['location']) ?></div>
        </div>
        <div style="position:relative;margin-top:14px;display:inline-block;background:<?= h($teamBg) ?>;color:<?= h($teamFg) ?>;padding:6px 16px;border-radius:9px;font-weight:800;font-size:15px;">ΟΜΑΔΑ / TEAM: <?= h(mb_strtoupper($teamLabelStr)) ?></div>
    </div>

    <div class="bp-section" style="padding-bottom:8px;">
        <div style="display:flex;gap:8px;">
            <a href="<?= h($mapsHref) ?>" target="_blank" rel="noopener noreferrer" class="bp-actionbtn">
                <div class="ic" style="background:#2a78d6;"><i class="bi bi-signpost-split" aria-hidden="true"></i></div>
                <span class="lbl">Διαδρομή<br>Navigate</span>
            </a>
            <a href="<?= h($telHref) ?>" class="bp-actionbtn<?= $telHref === '' ? ' disabled' : '' ?>">
                <div class="ic" style="background:#eda100;"><i class="bi bi-telephone" aria-hidden="true"></i></div>
                <span class="lbl">Κλήση<br>Call</span>
            </a>
            <a href="<?= h($icsDataUri) ?>" download="briefing.ics" class="bp-actionbtn">
                <div class="ic" style="background:#1baf7a;"><i class="bi bi-calendar-plus" aria-hidden="true"></i></div>
                <span class="lbl">Ημερολόγιο<br>Calendar</span>
            </a>
        </div>
    </div>

    <div class="bp-section" style="padding-top:8px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="bp-fact"><div class="bp-icon-circle" style="background:#e6f1fb;color:#2a78d6;"><i class="bi bi-geo-alt" aria-hidden="true"></i></div><div><div class="l">RV Point</div><div class="v"><?= h($rvPoint) ?></div><div class="v2"><?= h(date('H:i', strtotime($team['start_datetime']))) ?></div></div></div>
            <div class="bp-fact"><div class="bp-icon-circle" style="background:#eaf3de;color:#1baf7a;"><i class="bi bi-broadcast" aria-hidden="true"></i></div><div><div class="l">Radio Channel</div><div class="v"><?= $team['radio_channel'] ? h($team['radio_channel']) : '<span class="text-muted fw-normal">—</span>' ?></div></div></div>
            <div class="bp-fact"><div class="bp-icon-circle" style="background:#faeeda;color:#eda100;"><i class="bi bi-person-badge" aria-hidden="true"></i></div><div><div class="l">Coordinator</div><div class="v"><?= $team['coordinator_name'] ? h($team['coordinator_name']) : '<span class="text-muted fw-normal">—</span>' ?></div><div class="v2"><?= h($team['coordinator_phone'] ?? '') ?></div></div></div>
            <div class="bp-fact"><div class="bp-icon-circle" style="background:#eeedfe;color:#534ab7;"><i class="bi bi-clock" aria-hidden="true"></i></div><div><div class="l">Shift</div><div class="v"><?= h(date('H:i', strtotime($team['start_datetime']))) ?>&ndash;<?= h(date('H:i', strtotime($team['end_datetime']))) ?></div></div></div>
        </div>
    </div>

    <div class="bp-section">
        <div class="bp-h"><i class="bi bi-signpost-2" aria-hidden="true"></i>Πρόγραμμα &middot; Schedule</div>
        <div class="bp-tl">
            <div class="bp-tl-dotcol"><div class="bp-tl-dot"></div><div class="bp-tl-line"></div></div>
            <div class="bp-tl-item"><div class="bp-tl-time"><?= h(date('H:i', strtotime($team['start_datetime']))) ?></div><div class="bp-tl-label">Άφιξη στο σημείο συγκέντρωσης</div><div class="bp-tl-sub">Arrival at RV point</div></div>
        </div>
        <div class="bp-tl">
            <div class="bp-tl-dotcol"><div class="bp-tl-dot" style="background:#1baf7a;"></div></div>
            <div class="bp-tl-item"><div class="bp-tl-time"><?= h(date('H:i', strtotime($team['end_datetime']))) ?></div><div class="bp-tl-label">Ολοκλήρωση βάρδιας</div><div class="bp-tl-sub">Shift complete</div></div>
        </div>
    </div>

    <div class="bp-section">
        <div class="bp-h"><i class="bi bi-people" aria-hidden="true"></i>Μέλη Ομάδας &middot; Team Roster</div>
        <?php foreach ($roster as $member): ?>
        <?php
            $initials = mb_strtoupper(mb_substr($member['name'], 0, 1) . (strpos($member['name'], ' ') !== false ? mb_substr(strrchr($member['name'], ' '), 1, 1) : ''));
            $isLeader = $team['leader_id'] !== null && (int)$member['user_id'] === (int)$team['leader_id'];
        ?>
        <div class="bp-member">
            <div class="bp-avatar" style="background:<?= h($teamBg) ?>;"><?= h($initials) ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:12.5px;font-weight:700;"><?= guestNameHtml($member['name'], (bool)$member['is_external'], $member['home_team_name'], $member['home_team_color'], $member['guest_country_code']) ?><?= $isLeader ? ' ⭐' : '' ?></div>
                <?php if ($isLeader): ?><div style="font-size:10.5px;color:#8a8a86;">Αρχηγός Ομάδας &middot; Team Leader</div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="bp-section">
        <div class="bp-h"><i class="bi bi-map" aria-hidden="true"></i>Περιοχή Ανάθεσης &middot; Assigned Area</div>
        <div style="font-size:12.5px;font-weight:700;"><?= h($team['location']) ?></div>
        <?php if ($team['location_details']): ?><div style="font-size:11px;color:#8a8a86;margin-top:2px;"><?= h($team['location_details']) ?></div><?php endif; ?>
    </div>

    <div class="bp-section" style="background:#fff8f8;border-top:1px solid #f5e0e0;">
        <div style="display:flex;gap:10px;align-items:flex-start;">
            <i class="bi bi-exclamation-triangle-fill" style="color:#b91c1c;font-size:18px;flex-shrink:0;margin-top:1px;" aria-hidden="true"></i>
            <div style="font-size:11.5px;color:#5a2020;line-height:1.5;"><strong>Έκτακτη ανάγκη / Emergency:</strong> Καλέστε 112 ή τον συντονιστή σας.<br>Call 112 (EU emergency number) or your coordinator.</div>
        </div>
    </div>

    <div class="bp-section" style="padding-top:12px;text-align:center;">
        <div style="font-size:10.5px;color:#b0afa9;">Αυτός ο σύνδεσμος απενεργοποιείται όταν ολοκληρωθεί η αποστολή.<br>This link deactivates once the mission concludes.</div>
    </div>

    <script>
    (function() {
        const target = new Date('<?= h($startIso) ?>').getTime();
        const el = document.getElementById('bpCountdown');
        function tick() {
            const diffMs = target - Date.now();
            if (isNaN(target)) { el.remove(); return; }
            const days = Math.floor(diffMs / 86400000);
            if (diffMs <= 0) {
                el.textContent = 'ΣΗΜΕΡΑ · TODAY';
            } else if (days === 0) {
                el.textContent = '< 24Ω · < 24H';
            } else {
                el.textContent = 'ΣΕ ' + days + ' ΗΜΕΡΕΣ · IN ' + days + ' DAYS';
            }
        }
        tick();
        setInterval(tick, 3600000);
    })();
    </script>

<?php endif; ?>
</div>

</body>
</html>
