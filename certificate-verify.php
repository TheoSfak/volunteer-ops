<?php
/**
 * VolunteerOps - Certificate Authenticity Verification
 * The one genuinely public, no-login page in this app — reached by anyone
 * scanning the QR code printed on a mission-certificate-print.php document,
 * including people with no account here at all (e.g. an employer or partner
 * org confirming a certificate is real). Every other page in this codebase
 * calls requireLogin(); this deliberately doesn't, matching checkin.php's
 * precedent of bootstrap.php being safe to include without forcing a login
 * redirect.
 *
 * Shows only the same fields already printed in plain text on the physical
 * certificate itself (recipient name, mission, date, language) plus a clear
 * genuine/not-found verdict — nothing beyond what the document already
 * reveals (no email, no user id, no other missions). Bilingual on one page
 * (Greek + English shown together) since an anonymous visitor has no known
 * language preference to key off, unlike the rest of the app's t() system.
 */

require_once __DIR__ . '/bootstrap.php';

$number = trim(get('number', ''));

$cert = $number !== '' ? dbFetchOne(
    "SELECT mc.certificate_number, mc.issued_at, mc.language,
            m.title AS mission_title, m.start_datetime,
            u.name AS recipient_name, u.is_external AS recipient_is_external, u.guest_org_name AS recipient_guest_org_name
     FROM mission_certificates mc
     JOIN missions m ON m.id = mc.mission_id
     JOIN users u ON u.id = mc.recipient_user_id
     WHERE mc.certificate_number = ?",
    [$number]
) : null;

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Επαλήθευση Βεβαίωσης / Certificate Verification</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;800&family=EB+Garamond:wght@400;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'EB Garamond', Georgia, serif;
            background: #ece7db; color: #23231f;
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
        }
        .verify-card {
            max-width: 480px; width: 100%; background: #fffdf8; border: 1.5px solid #b8860b;
            border-radius: 10px; padding: 32px 28px; text-align: center; box-shadow: 0 8px 30px rgba(0,0,0,.15);
        }
        .verify-org-row { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 18px; }
        .verify-org-row img { height: 36px; width: auto; }
        .verify-org-name { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 12pt; color: #172554; }

        .verify-icon { font-size: 46px; line-height: 1; margin-bottom: 10px; }
        .verify-icon.valid { color: #0ca30c; }
        .verify-icon.invalid { color: #d03b3b; }

        .verify-title { font-family: 'Playfair Display', serif; font-weight: 800; font-size: 17pt; margin-bottom: 4px; }
        .verify-title.valid { color: #0ca30c; }
        .verify-title.invalid { color: #d03b3b; }
        .verify-subtitle { font-size: 10.5pt; color: #6b6a63; margin-bottom: 20px; font-style: italic; }

        .verify-details { text-align: left; background: #f7f4ea; border-radius: 8px; padding: 16px 18px; margin-bottom: 6px; }
        .verify-row { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 10.5pt; border-bottom: 1px solid #e5e1d0; }
        .verify-row:last-child { border-bottom: none; }
        .verify-row .label { color: #6b6a63; }
        .verify-row .value { font-weight: 600; color: #23231f; text-align: right; }

        .verify-number { margin-top: 18px; font-size: 8.5pt; color: #918f86; letter-spacing: .03em; }
        .verify-form { margin-top: 22px; display: flex; gap: 8px; }
        .verify-form input { flex: 1; padding: 8px 10px; border: 1px solid #d8d4c4; border-radius: 6px; font-family: inherit; font-size: 10pt; }
        .verify-form button { padding: 8px 16px; border: none; border-radius: 6px; background: #172554; color: #fff; font-weight: 600; cursor: pointer; font-size: 10pt; }
        .verify-form button:hover { background: #1e3169; }
    </style>
</head>
<body>

<div class="verify-card">
    <div class="verify-org-row">
        <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt=""><?php endif; ?>
        <span class="verify-org-name"><?= h($orgName) ?></span>
    </div>

    <?php if ($number === ''): ?>
        <div class="verify-icon" style="color:#918f86;">🔍</div>
        <div class="verify-title" style="color:#23231f;">Επαλήθευση Βεβαίωσης / Certificate Verification</div>
        <div class="verify-subtitle">Εισάγετε τον αριθμό βεβαίωσης παρακάτω. / Enter a certificate number below.</div>
    <?php elseif ($cert): ?>
        <div class="verify-icon valid">✓</div>
        <div class="verify-title valid">Γνήσια Βεβαίωση</div>
        <div class="verify-title valid" style="font-size:12pt;">Genuine Certificate</div>
        <div class="verify-subtitle">Αυτή η βεβαίωση υπάρχει στο μητρώο μας. / This certificate exists in our records.</div>
        <div class="verify-details">
            <div class="verify-row"><span class="label">Παραλήπτης / Recipient</span><span class="value"><?= h($cert['recipient_name']) ?><?php if ($cert['recipient_is_external'] && $cert['recipient_guest_org_name']): ?> (<?= h($cert['recipient_guest_org_name']) ?>)<?php endif; ?></span></div>
            <div class="verify-row"><span class="label">Αποστολή / Mission</span><span class="value"><?= h($cert['mission_title']) ?></span></div>
            <div class="verify-row"><span class="label">Ημερομηνία / Date</span><span class="value"><?= formatDate($cert['start_datetime']) ?></span></div>
            <div class="verify-row"><span class="label">Έκδοση / Issued</span><span class="value"><?= formatDate($cert['issued_at']) ?></span></div>
        </div>
        <div class="verify-number"><?= h($cert['certificate_number']) ?></div>
    <?php else: ?>
        <div class="verify-icon invalid">✕</div>
        <div class="verify-title invalid">Δεν Βρέθηκε</div>
        <div class="verify-title invalid" style="font-size:12pt;">Not Found</div>
        <div class="verify-subtitle">Δεν βρέθηκε βεβαίωση με αυτόν τον αριθμό. / No certificate matches this number.</div>
        <div class="verify-number"><?= h($number) ?></div>
    <?php endif; ?>

    <form class="verify-form" method="get" action="certificate-verify.php">
        <input type="text" name="number" placeholder="EPI-2026-000001" value="<?= h($number) ?>">
        <button type="submit">Έλεγχος / Check</button>
    </form>
</div>

</body>
</html>
