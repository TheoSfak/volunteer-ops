<?php
/**
 * VolunteerOps - Mission Certificate (print/download view)
 * The actual certificate document for one mission_certificates row. Reached
 * either by the recipient themselves (including guest/external-org accounts —
 * see the bootstrap.php allow-list entry for this file) or by an admin with
 * the same missions_manage/responsible_user_id gate used everywhere else in
 * War Room. Renders in whichever language was stored at issuance — fixed
 * forever once created, unlike the app's own t() system which is a live
 * per-viewer-language switch; this is "what language was this specific
 * document issued in", a different concern, so the bilingual copy below is a
 * plain per-line ternary rather than a t() call.
 *
 * Same "no real PDF library, browser print-to-PDF" convention as every other
 * printed document in this app (mission-report-print.php, inventory-print.php)
 * — own standalone <!DOCTYPE>, own <style>, window.print(). Landscape
 * orientation (unlike the portrait mission report) since certificates
 * conventionally are.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$certId = (int) get('id');

$cert = dbFetchOne(
    "SELECT mc.*, m.title AS mission_title, m.location, m.location_details,
            m.start_datetime, m.end_datetime, m.responsible_user_id,
            u.name AS recipient_name, u.is_external AS recipient_is_external, u.guest_org_name AS recipient_guest_org_name
     FROM mission_certificates mc
     JOIN missions m ON m.id = mc.mission_id AND m.deleted_at IS NULL
     JOIN users u ON u.id = mc.recipient_user_id
     WHERE mc.id = ?",
    [$certId]
);
if (!$cert) {
    http_response_code(404);
    exit('Το πιστοποιητικό δεν βρέθηκε.');
}

$isRecipient = (int) $userId === (int) $cert['recipient_user_id'];
$canManageWarRoom = hasPagePermission('missions_manage') || (int) $cert['responsible_user_id'] === (int) $userId;
if (!$isRecipient && !$canManageWarRoom) {
    http_response_code(403);
    exit('Δεν έχετε δικαίωμα πρόσβασης σε αυτό το πιστοποιητικό.');
}

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$presidentName = getSetting('org_president_name', '');
$secretaryName = getSetting('org_secretary_name', '');

$lang = $cert['language'] === 'en' ? 'en' : 'el';
$isGuestRecipient = (bool) $cert['recipient_is_external'];
$guestOrgName = $cert['recipient_guest_org_name'];

$missionDateRange = formatDate($cert['start_datetime'])
    . ($cert['end_datetime'] && substr($cert['end_datetime'], 0, 10) !== substr($cert['start_datetime'], 0, 10) ? ' – ' . formatDate($cert['end_datetime']) : '');

// Authenticity QR — same api.qrserver.com recipe already established for
// shift-view.php's QR check-in feature (this app has no server-side QR
// library), pointed at the new public certificate-verify.php lookup page.
$verifyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/certificate-verify.php?number='
    . urlencode((string) $cert['certificate_number']);

$copy = [
    'el' => [
        'doc_title'   => 'Πιστοποιητικό Συμμετοχής',
        'presented_to'=> 'Απονέμεται στον/στην',
        'body_lead'   => 'για τη συμμετοχή του/της στην αποστολή',
        'held_on'     => 'που πραγματοποιήθηκε στις',
        'at_location' => 'στην περιοχή',
        'representing'=> 'εκπροσωπώντας',
        'sig_president' => 'Ο Πρόεδρος',
        'sig_secretary' => 'Ο Γενικός Γραμματέας',
        'cert_no'     => 'Αριθμός Πιστοποιητικού',
        'issued_on'   => 'Ημερομηνία Έκδοσης',
        'print_btn'   => '🖶 Εκτύπωση / PDF',
        'close_btn'   => '✕ Κλείσιμο',
        'notice'      => 'Προεπισκόπηση Εκτύπωσης — Πιστοποιητικό Συμμετοχής',
        'scan_verify' => 'Σαρώστε για επαλήθευση γνησιότητας',
    ],
    'en' => [
        'doc_title'   => 'Certificate of Participation',
        'presented_to'=> 'This certificate is proudly presented to',
        'body_lead'   => 'in recognition of participation in the mission',
        'held_on'     => 'held on',
        'at_location' => 'at',
        'representing'=> 'representing',
        'sig_president' => 'President',
        'sig_secretary' => 'General Secretary',
        'cert_no'     => 'Certificate No.',
        'issued_on'   => 'Date of Issue',
        'print_btn'   => '🖶 Print / PDF',
        'close_btn'   => '✕ Close',
        'notice'      => 'Print Preview — Certificate of Participation',
        'scan_verify' => 'Scan to verify authenticity',
    ],
][$lang];

$printDate = date('d/m/Y');
?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($copy['doc_title']) ?> - <?= h($cert['recipient_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=EB+Garamond:ital,wght@0,400;0,600;1,400&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'EB Garamond', Georgia, serif;
            background: #ece7db;
            color: #23231f;
            padding: 20px;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .cert-page {
            max-width: 1122px;
            margin: 0 auto;
            background: #fffdf8;
            position: relative;
            padding: 22px;
            box-shadow: 0 8px 30px rgba(0,0,0,.18);
        }
        .cert-border-outer {
            border: 3px solid #172554;
            padding: 8px;
        }
        .cert-border-inner {
            border: 1.5px solid #b8860b;
            padding: 46px 64px;
            position: relative;
            min-height: 620px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .cert-corner { position: absolute; width: 34px; height: 34px; border: 2px solid #b8860b; }
        .cert-corner.tl { top: -1.5px; left: -1.5px; border-right: none; border-bottom: none; }
        .cert-corner.tr { top: -1.5px; right: -1.5px; border-left: none; border-bottom: none; }
        .cert-corner.bl { bottom: -1.5px; left: -1.5px; border-right: none; border-top: none; }
        .cert-corner.br { bottom: -1.5px; right: -1.5px; border-left: none; border-top: none; }

        .cert-watermark {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            opacity: .05; pointer-events: none;
        }
        .cert-watermark img { max-height: 380px; }

        .cert-org-row { display: flex; align-items: center; gap: 12px; margin-bottom: 6px; z-index: 1; }
        .cert-org-row img { height: 44px; width: auto; }
        .cert-org-name { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 15pt; color: #172554; letter-spacing: .02em; }

        .cert-title {
            font-family: 'Playfair Display', serif; font-weight: 800; font-size: 32pt;
            color: #172554; margin-top: 18px; letter-spacing: .01em; z-index: 1;
        }
        .cert-title-rule { width: 140px; height: 3px; background: #b8860b; margin: 14px 0 22px; z-index: 1; }

        .cert-presented-to { font-size: 12pt; color: #52514e; z-index: 1; }
        .cert-recipient-name {
            font-family: 'Playfair Display', serif; font-weight: 700; font-size: 27pt;
            color: #23231f; margin: 10px 0 8px; z-index: 1;
            border-bottom: 1.5px solid #b8860b; padding-bottom: 8px; display: inline-block; min-width: 60%;
        }
        .cert-guest-org { font-size: 10.5pt; font-style: italic; color: #6b6a63; margin-bottom: 14px; z-index: 1; }

        .cert-body { font-size: 12.5pt; line-height: 1.75; max-width: 720px; color: #3a3a35; z-index: 1; }
        .cert-body strong { color: #172554; }

        .cert-citation {
            font-style: italic; font-size: 11.5pt; color: #52514e; margin-top: 16px;
            max-width: 640px; z-index: 1; position: relative; padding: 0 28px;
        }
        .cert-citation::before, .cert-citation::after { font-family: 'Playfair Display', serif; font-size: 22pt; color: #b8860b; position: absolute; }
        .cert-citation::before { content: '“'; left: 0; top: -6px; }
        .cert-citation::after { content: '”'; right: 0; bottom: -16px; }

        .cert-signatures { display: flex; justify-content: space-between; width: 100%; max-width: 720px; margin-top: auto; padding-top: 56px; z-index: 1; }
        .cert-sig { text-align: center; width: 45%; }
        .cert-sig-name { font-family: 'Dancing Script', cursive; font-weight: 700; font-size: 22pt; color: #172554; line-height: 1; transform: rotate(-1.5deg); display: inline-block; }
        .cert-sig-line { border-top: 1.3px solid #23231f; margin-top: 10px; padding-top: 6px; font-size: 9.5pt; letter-spacing: .04em; text-transform: uppercase; color: #52514e; }

        .cert-footer-meta {
            display: flex; justify-content: space-between; align-items: flex-end; width: 100%; max-width: 720px;
            margin-top: 26px; font-size: 8pt; color: #918f86; z-index: 1;
        }
        .cert-footer-meta .cert-meta-text { text-align: left; line-height: 1.6; }
        .cert-qr { text-align: center; }
        .cert-qr img { display: block; border: 1px solid #e5e1d5; border-radius: 4px; }
        .cert-qr .cert-qr-caption { font-size: 6.8pt; color: #918f86; margin-top: 3px; max-width: 90px; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #17375e; color: #fff; text-align: center; padding: 8px 16px; font-size: 10pt; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; }
        .screen-notice button { background: #fff; color: #17375e; border: none; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: bold; }
        .screen-notice button:hover { background: #e0e8f5; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; background: #fff; }
            @page { size: A4 landscape; margin: 8mm; }
            .cert-page { box-shadow: none; max-width: none; }
        }
    </style>
</head>
<body>

<div class="screen-notice">
    <span><?= h($copy['notice']) ?></span>
    <button onclick="window.print()"><?= h($copy['print_btn']) ?></button>
    <button onclick="window.close()"><?= h($copy['close_btn']) ?></button>
</div>

<div class="cert-page" style="margin-top:48px;">
    <div class="cert-border-outer">
        <div class="cert-border-inner">
            <span class="cert-corner tl"></span>
            <span class="cert-corner tr"></span>
            <span class="cert-corner bl"></span>
            <span class="cert-corner br"></span>

            <?php if ($hasLogo): ?>
            <div class="cert-watermark"><img src="uploads/logos/<?= h($appLogo) ?>" alt=""></div>
            <?php endif; ?>

            <div class="cert-org-row">
                <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt=""><?php endif; ?>
                <span class="cert-org-name"><?= h($orgName) ?></span>
            </div>

            <div class="cert-title"><?= h($copy['doc_title']) ?></div>
            <div class="cert-title-rule"></div>

            <div class="cert-presented-to"><?= h($copy['presented_to']) ?></div>
            <div class="cert-recipient-name"><?= h($cert['recipient_name']) ?></div>
            <?php if ($isGuestRecipient && $guestOrgName): ?>
            <div class="cert-guest-org"><?= h($copy['representing']) ?> <?= h($guestOrgName) ?></div>
            <?php endif; ?>

            <div class="cert-body">
                <?= h($copy['body_lead']) ?> «<strong><?= h($cert['mission_title']) ?></strong>»
                <?= h($copy['held_on']) ?> <strong><?= h($missionDateRange) ?></strong><?php if (!empty($cert['location'])): ?>,
                <?= h($copy['at_location']) ?> <strong><?= h($cert['location']) ?></strong><?php endif; ?>.
            </div>

            <?php if (!empty($cert['citation_text'])): ?>
            <div class="cert-citation"><?= nl2br(h($cert['citation_text'])) ?></div>
            <?php endif; ?>

            <div class="cert-signatures">
                <div class="cert-sig">
                    <div class="cert-sig-name"><?= h($presidentName ?: '—') ?></div>
                    <div class="cert-sig-line"><?= h($copy['sig_president']) ?></div>
                </div>
                <div class="cert-sig">
                    <div class="cert-sig-name"><?= h($secretaryName ?: '—') ?></div>
                    <div class="cert-sig-line"><?= h($copy['sig_secretary']) ?></div>
                </div>
            </div>

            <div class="cert-footer-meta">
                <div class="cert-meta-text">
                    <div><?= h($copy['cert_no']) ?>: <?= h($cert['certificate_number'] ?: '—') ?></div>
                    <div><?= h($copy['issued_on']) ?>: <?= formatDate($cert['issued_at']) ?></div>
                </div>
                <?php if (!empty($cert['certificate_number'])): ?>
                <div class="cert-qr">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=90x90&ecc=M&data=<?= urlencode($verifyUrl) ?>" width="90" height="90" alt="QR">
                    <div class="cert-qr-caption"><?= h($copy['scan_verify']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
setTimeout(function () { window.print(); }, 400);
</script>
</body>
</html>
