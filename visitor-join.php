<?php
/**
 * VolunteerOps - Mission Visitor self-serve registration (PUBLIC - no login required)
 *
 * Fast, walk-up registration for people outside the org who need temporary
 * Action Room access to exactly one live mission — police, local citizens,
 * fire dept/EMAK/civil protection/municipality staff. Reached via a QR code
 * or link an admin generates from that mission's Action Room.
 *
 * Modeled structurally on briefing-view.php: no requireLogin(), own complete
 * HTML document, the token IS the access control and self-expires the moment
 * the mission leaves STATUS_OPEN (no separate revoke step, and deliberately
 * no distinction between "never existed" and "expired" in the error state —
 * that would leak whether a token used to be valid).
 *
 * Registration IS login: submitting the form creates the account and
 * establishes a session in the same step (establishMissionVisitorSession(),
 * includes/auth.php) — no password is ever shown to the visitor. If they
 * reload or switch devices, the "already registered?" box resumes their
 * session by phone number alone, scoped to this one mission (see
 * includes/auth.php's isMissionVisitor()/establishMissionVisitorSession()
 * and bootstrap.php's is_external gate for how the resulting account is
 * confined to Action Room and to this single mission afterward).
 *
 * Registration also requires an explicit GDPR consent tick
 * (users.mission_visitor_consent_at, migration v137). The `resume` action
 * never re-asks — that row's consent was recorded when it was created.
 *
 * URL: /visitor-join.php?token=XXXX
 */
require_once __DIR__ . '/bootstrap.php';
// No requireLogin() — this page is intentionally public.

function normalizeVisitorPhone(string $raw): string {
    return preg_replace('/[^0-9+]/', '', $raw) ?? '';
}

$token = trim(get('token', ''));

$mission = $token !== '' ? dbFetchOne(
    "SELECT id, title, location, status FROM missions WHERE visitor_join_token = ? AND deleted_at IS NULL",
    [$token]
) : null;
if ($mission && $mission['status'] !== STATUS_OPEN) {
    $mission = null; // self-expiring, same reasoning as briefing-view.php
}

$errors = [];
$activeForm = 'register';

if ($mission && isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'register') {
        $activeForm = 'register';
        $firstName = trim(post('first_name'));
        $lastName  = trim(post('last_name'));
        $phone     = normalizeVisitorPhone(post('phone'));

        if ($firstName === '' || $lastName === '') {
            $errors[] = t('visitor.join_err_name_required', [], 'el');
        }
        if ($phone === '') {
            $errors[] = t('visitor.join_err_phone_required', [], 'el');
        }
        // GDPR consent is a hard gate on registration, not a formality:
        // this is the only moment a walk-up visitor is ever asked, and the
        // account created below is what puts their name/phone into a live
        // operational picture and, later, into the mission's report PDFs.
        // Checked server-side rather than trusting the `required` attribute
        // — the same reasoning every other validation on this page follows.
        // The `resume` action deliberately does NOT re-ask: consent was
        // already given and stamped when that row was first created.
        if (post('gdpr_consent') !== '1') {
            $errors[] = t('visitor.join_err_gdpr_required', [], 'el');
        }

        if (empty($errors)) {
            $name = mb_substr(trim("$firstName $lastName"), 0, 100);

            // Re-submitting the whole form after already registering for
            // this mission (e.g. double-tap) — resume instead of creating
            // a duplicate row.
            $existingId = dbFetchValue(
                "SELECT id FROM users
                 WHERE mission_visitor_mission_id = ? AND is_mission_visitor = 1 AND phone = ? AND is_active = 1
                 ORDER BY created_at DESC LIMIT 1",
                [$mission['id'], $phone]
            );

            if ($existingId) {
                $visitorUser = dbFetchOne("SELECT id, name, email FROM users WHERE id = ?", [$existingId]);
                // They just ticked the box again on a row that predates it
                // (or a double-tap whose first request already created the
                // row) — record the consent we actually have rather than
                // leaving a live visitor with no timestamp at all. COALESCE
                // keeps the ORIGINAL consent moment when there already is
                // one; a re-tick is not a new consent event.
                dbExecute(
                    "UPDATE users SET mission_visitor_consent_at = COALESCE(mission_visitor_consent_at, NOW()), updated_at = NOW() WHERE id = ?",
                    [$existingId]
                );
                establishMissionVisitorSession((int) $visitorUser['id'], $visitorUser['name'], $visitorUser['email']);
            } else {
                // users.email/password are NOT NULL UNIQUE — synthesize both.
                // .invalid is the IANA-reserved TLD for addresses that must
                // never resolve (RFC 2606). The password is a bcrypt hash of
                // random bytes, never shown anywhere; login() itself also
                // refuses is_mission_visitor accounts as defense in depth.
                $email = 'mv-' . $mission['id'] . '-' . bin2hex(random_bytes(16)) . '@mission-visitor.invalid';
                $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

                $userId = dbInsert(
                    "INSERT INTO users
                        (name, email, password, phone, role, is_active, is_external, is_mission_visitor, mission_visitor_mission_id, mission_visitor_consent_at, language, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 1, 1, 1, ?, NOW(), 'el', NOW(), NOW())",
                    [$name, $email, $passwordHash, $phone, ROLE_VOLUNTEER, $mission['id']]
                );

                // Same straight-to-APPROVED insert as mission-view.php's
                // manual_add_volunteer, just triggered by the visitor
                // themselves instead of an admin picking from a dropdown —
                // decided_by stays NULL, no admin decided this one. Every
                // OPEN mission always has at least one shift (auto-created,
                // mission-form.php), so this is never null in practice.
                $shiftId = dbFetchValue(
                    "SELECT id FROM shifts WHERE mission_id = ? ORDER BY start_time DESC LIMIT 1",
                    [$mission['id']]
                );
                if ($shiftId) {
                    dbInsert(
                        "INSERT INTO participation_requests
                            (volunteer_id, shift_id, status, admin_notes, decided_at, created_at, updated_at)
                         VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())",
                        [$userId, $shiftId, PARTICIPATION_APPROVED, 'Αυτόματη εγγραφή μέσω QR επισκέπτη αποστολής.']
                    );
                }

                logAudit('mission_visitor_register', 'users', $userId, null, [
                    'mission_id'   => $mission['id'],
                    'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    // The consent record lives in two places on purpose: the
                    // users column is what the app reads, this audit row is
                    // the tamper-evident copy with the IP and exact request
                    // it was given in.
                    'gdpr_consent' => true,
                ]);
                establishMissionVisitorSession($userId, $name, $email);
            }

            redirect('war-room.php?id=' . $mission['id']);
        }
    } elseif ($action === 'resume') {
        $activeForm = 'resume';
        $phone = normalizeVisitorPhone(post('resume_phone'));

        if ($phone === '') {
            $errors[] = t('visitor.join_err_phone_required', [], 'el');
        } else {
            // Scoped to THIS mission only, not a global phone lookup — a
            // mission visitor is never a reusable cross-mission account (see
            // the docblock above), so a new mission's QR always creates a
            // fresh row for a returning person. is_active=1 is what stops a
            // removed visitor from resuming their way back in.
            $visitorUser = dbFetchOne(
                "SELECT id, name, email FROM users
                 WHERE mission_visitor_mission_id = ? AND is_mission_visitor = 1 AND phone = ? AND is_active = 1
                 ORDER BY created_at DESC LIMIT 1",
                [$mission['id'], $phone]
            );
            if ($visitorUser) {
                logAudit('mission_visitor_resume', 'users', $visitorUser['id'], null, [
                    'mission_id' => $mission['id'],
                    'ip'         => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                establishMissionVisitorSession((int) $visitorUser['id'], $visitorUser['name'], $visitorUser['email']);
                redirect('war-room.php?id=' . $mission['id']);
            } else {
                $errors[] = t('visitor.join_err_resume_not_found', [], 'el');
            }
        }
    }
}

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#172554">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $mission ? h($mission['title']) . ' — ' . t('visitor.join_title', [], 'el') : t('visitor.join_invalid_link', [], 'el') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        body { background: #eef0f4; }
        .vj-page { max-width: 480px; margin: 24px auto; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 4px 18px rgba(23,37,84,.12); }
        .vj-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; padding: 22px; }
        .vj-section { padding: 22px; }
        .vj-h { font-size: 13px; font-weight: 800; color: #172554; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
        .vj-resume { background: #f6f5f1; border-top: 1px solid #eee; }
        .vj-consent { background: #f6f5f1; border: 1px solid #e2e0da; border-radius: 12px; padding: 12px 14px; }
        .vj-consent .form-check-input { margin-top: .2rem; }
        .vj-consent .form-check-label { font-size: 13px; line-height: 1.45; color: #172554; }
        .vj-consent-detail { font-size: 11.5px; line-height: 1.5; color: #6b6b6b; margin-top: 8px; }
        @media (max-width: 480px) { .vj-page { margin: 0; border-radius: 0; } body { background: #fff; } }
    </style>
</head>
<body>

<div class="vj-page">
<?php if (!$mission): ?>

    <div class="vj-section text-center py-5">
        <?php if (get('ended', '') === '1'): ?>
        <i class="bi bi-flag-fill text-success" style="font-size:2.5rem;"></i>
        <h2 class="mt-3 mb-2" style="font-size:1.25rem;"><?= h(t('visitor.session_ended_mission_closed', [], 'el')) ?></h2>
        <?php else: ?>
        <i class="bi bi-x-circle text-danger" style="font-size:2.5rem;"></i>
        <h2 class="mt-3 mb-2" style="font-size:1.25rem;"><?= h(t('visitor.join_invalid_link', [], 'el')) ?></h2>
        <p class="text-muted small mb-0"><?= h(t('visitor.join_invalid_link_detail', [], 'el')) ?></p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="vj-hero">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <?php if ($hasLogo): ?>
            <img src="uploads/logos/<?= h($appLogo) ?>" alt="" style="width:30px;height:30px;border-radius:9px;object-fit:cover;">
            <?php else: ?>
            <div style="width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;"><?= h(mb_substr($orgName, 0, 1)) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;opacity:.9;font-weight:600;"><?= h($orgName) ?></div>
        </div>
        <div style="font-size:19px;font-weight:800;line-height:1.3;"><?= h(t('visitor.join_title', [], 'el')) ?></div>
        <div style="font-size:12.5px;opacity:.9;margin-top:6px;"><?= h(t('visitor.join_intro', [], 'el')) ?></div>
        <div style="margin-top:14px;background:rgba(255,255,255,.14);padding:8px 12px;border-radius:10px;font-size:13px;">
            <div style="font-size:10px;text-transform:uppercase;letter-spacing:.03em;opacity:.8;"><?= h(t('visitor.join_mission_label', [], 'el')) ?></div>
            <div style="font-weight:700;margin-top:2px;"><?= h($mission['title']) ?></div>
            <div style="opacity:.85;margin-top:1px;"><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= h($mission['location']) ?></div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="vj-section pb-0">
        <div class="alert alert-danger mb-0">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="vj-section">
        <form method="post" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="register">
            <div class="mb-3">
                <label class="form-label"><?= h(t('visitor.join_first_name_label', [], 'el')) ?></label>
                <input type="text" name="first_name" class="form-control form-control-lg" required maxlength="100" autocomplete="given-name" value="<?= $activeForm === 'register' ? h(post('first_name')) : '' ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= h(t('visitor.join_last_name_label', [], 'el')) ?></label>
                <input type="text" name="last_name" class="form-control form-control-lg" required maxlength="100" autocomplete="family-name" value="<?= $activeForm === 'register' ? h(post('last_name')) : '' ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= h(t('visitor.join_phone_label', [], 'el')) ?></label>
                <input type="tel" name="phone" class="form-control form-control-lg" required autocomplete="tel" inputmode="tel" value="<?= $activeForm === 'register' ? h(post('phone')) : '' ?>">
            </div>
            <!-- GDPR consent. Deliberately never pre-ticked and never
                 remembered across a failed submit (unlike the name/phone
                 fields just above, which are): a consent box that restores
                 itself as already-ticked is not a consent the person gave on
                 this submission. Server-side enforcement is what actually
                 blocks registration — see the gdpr_consent check above. -->
            <div class="vj-consent mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="gdpr_consent" value="1" id="vjGdprConsent" required>
                    <label class="form-check-label" for="vjGdprConsent">
                        <span class="fw-semibold"><?= h(t('visitor.join_gdpr_label', ['org' => $orgName], 'el')) ?></span>
                    </label>
                </div>
                <p class="vj-consent-detail mb-0"><?= h(t('visitor.join_gdpr_detail', ['org' => $orgName], 'el')) ?></p>
            </div>
            <button type="submit" class="btn btn-danger btn-lg w-100 fw-bold"><?= h(t('visitor.join_submit_btn', [], 'el')) ?></button>
        </form>
    </div>

    <div class="vj-section vj-resume">
        <div class="vj-h"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><?= h(t('visitor.join_resume_title', [], 'el')) ?></div>
        <p class="text-muted small"><?= h(t('visitor.join_resume_intro', [], 'el')) ?></p>
        <form method="post" class="d-flex gap-2" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resume">
            <input type="tel" name="resume_phone" class="form-control" required autocomplete="tel" inputmode="tel" placeholder="<?= h(t('visitor.join_phone_label', [], 'el')) ?>" value="<?= $activeForm === 'resume' ? h(post('resume_phone')) : '' ?>">
            <button type="submit" class="btn btn-outline-secondary text-nowrap"><?= h(t('visitor.join_resume_btn', [], 'el')) ?></button>
        </form>
    </div>

<?php endif; ?>
</div>

</body>
</html>
