<?php
/**
 * VolunteerOps - Αίτηση Υποψηφίου Νέου Μέλους (PUBLIC - no login required)
 *
 * Web version of the org's paper "ΑΙΤΗΣΗ ΥΠΟΨΗΦΙΟΥ ΝΕΟΥ ΜΕΛΟΥΣ" form. Modeled
 * structurally on visitor-join.php: no requireLogin(), own complete HTML
 * document, generic org branding via getSetting() so it works on every
 * deployment of this codebase, not just this one org.
 *
 * Deliberately does NOT create a `users` row — register.php already states
 * this app's policy that accounts are admin-created only, and the paper form
 * itself has no password field. Submissions land in volunteer_applications
 * and are reviewed by staff on volunteer-applications.php, who convert an
 * approved candidate into a real volunteer account themselves (see the
 * from_application handling in volunteer-form.php).
 *
 * URL: /aithsh.php
 */
require_once __DIR__ . '/bootstrap.php';
// No requireLogin() — this page is intentionally public.

$errors = [];
$submitted = false;

if (isPost()) {
    verifyCsrf();

    $fullName    = trim(post('full_name'));
    $patronymic  = trim(post('patronymic')) ?: null;
    $birthDate   = trim(post('birth_date')) ?: null;
    $address     = trim(post('address')) ?: null;
    $postalCode  = trim(post('postal_code')) ?: null;
    $city        = trim(post('city')) ?: null;
    $homePhone   = trim(post('home_phone')) ?: null;
    $mobilePhone = trim(post('mobile_phone'));
    $email       = trim(post('email'));
    $occupation  = trim(post('occupation')) ?: null;

    if ($fullName === '') {
        $errors[] = 'Το ονοματεπώνυμο είναι υποχρεωτικό.';
    }
    if ($mobilePhone === '') {
        $errors[] = 'Το κινητό τηλέφωνο είναι υποχρεωτικό.';
    }
    if ($email === '') {
        $errors[] = 'Το email είναι υποχρεωτικό.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Το email δεν είναι έγκυρο.';
    }
    if ($birthDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        $errors[] = 'Μη έγκυρη ημερομηνία γέννησης.';
    }
    // GDPR consent is a hard gate, not a formality — same reasoning as
    // visitor-join.php's gdpr_consent check: checked server-side rather than
    // trusting the `required` attribute on the checkbox.
    if (post('gdpr_consent') !== '1') {
        $errors[] = 'Η συναίνεση επεξεργασίας προσωπικών δεδομένων είναι υποχρεωτική.';
    }

    if (empty($errors)) {
        $id = dbInsert(
            "INSERT INTO volunteer_applications
                (full_name, patronymic, birth_date, address, postal_code, city, home_phone, mobile_phone, email, occupation, gdpr_consent_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())",
            [$fullName, $patronymic, $birthDate, $address, $postalCode, $city, $homePhone, $mobilePhone, $email, $occupation, VOL_APP_NEW]
        );

        logAudit('volunteer_application_submit', 'volunteer_applications', $id, null, [
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'gdpr_consent' => true,
        ]);

        // Notify admins (in-app + email) — same admin query as complaint-form.php.
        $admins = dbFetchAll("SELECT id, name, email FROM users WHERE role IN (?, ?)", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
        $applicationUrl = rtrim(BASE_URL, '/') . '/volunteer-applications.php?id=' . $id;
        foreach ($admins as $admin) {
            sendNotification(
                $admin['id'],
                'Νέα Αίτηση Μέλους',
                'Ο/Η ' . $fullName . ' υπέβαλε αίτηση υποψηφίου νέου μέλους.',
                'volunteer_application',
                'member_application_submitted',
                ['url' => 'volunteer-applications.php']
            );
            if (isNotificationEnabled('member_application_submitted')) {
                sendNotificationEmail('member_application_submitted', $admin['email'], [
                    'admin_name'       => $admin['name'],
                    'applicant_name'   => $fullName,
                    'applicant_phone'  => $mobilePhone,
                    'applicant_email'  => $email,
                    'application_url'  => $applicationUrl,
                ]);
            }
        }

        // Confirm to the applicant — a plain email address, never a users row,
        // but sendNotificationEmail() handles that fine (its per-user opt-out
        // lookup only matches an existing account, so this always sends once
        // the toggle above is on).
        if (isNotificationEnabled('member_application_confirmation')) {
            sendNotificationEmail('member_application_confirmation', $email, [
                'applicant_name' => $fullName,
            ]);
        }

        $submitted = true;
    }
}

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$contactPhone = trim(getSetting('org_contact_phone', ''));
$contactEmail = trim(getSetting('org_contact_email', ''));
$contactAddress = trim(getSetting('org_contact_address', ''));
$hasFooterContact = ($contactPhone !== '' || $contactEmail !== '' || $contactAddress !== '');
$bgImage = getSetting('aithsh_bg_image', '');
$hasBgImage = !empty($bgImage) && file_exists(__DIR__ . '/uploads/backgrounds/' . $bgImage);
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0a1e3d">
    <meta name="robots" content="noindex, nofollow">
    <title>Αίτηση Υποψηφίου Νέου Μέλους — <?= h($orgName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        :root {
            --aj-navy: #0a1e3d;
            --aj-blue: #005596;
            --aj-blue-deep: #003a66;
            --aj-gold: #f0a93a;
            --aj-ink: #1e293b;
            --aj-border: #e2e8f0;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--aj-ink);
            padding: 44px 16px 28px;
            background: linear-gradient(160deg, var(--aj-navy) 0%, var(--aj-blue) 55%, var(--aj-blue-deep) 100%);
        }
        /* Dot-grid texture as its own fixed full-page layer behind everything,
           rather than a body background layer — keeps the (taller, scrolling)
           body's gradient a single simple layer with nothing that can fall out
           of sync with its height, and the dots still cover the whole page. */
        .aj-dots {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background: radial-gradient(circle, rgba(255,255,255,.16) 1px, transparent 1.6px) 0 0/24px 24px;
        }
        .aj-shell { max-width: 640px; margin: 0 auto; position: relative; z-index: 1; }
        .aj-hero { text-align: center; padding: 8px 20px 58px; color: #fff; position: relative; z-index: 1; }
        .aj-logo {
            width: 104px; height: 104px; border-radius: 28px; margin: 0 auto 20px;
            background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.4);
            display: flex; align-items: center; justify-content: center; overflow: hidden;
            box-shadow: 0 16px 34px rgba(0,0,0,.28), 0 0 0 7px rgba(255,255,255,.07);
        }
        .aj-logo img { width: 100%; height: 100%; object-fit: cover; }
        .aj-logo-fallback { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 2.9rem; color: #fff; }
        .aj-org-name { font-size: .8rem; font-weight: 600; opacity: .85; margin-bottom: 14px; }
        .aj-kicker {
            font-family: 'Manrope', sans-serif; font-size: .72rem; font-weight: 700;
            letter-spacing: .16em; text-transform: uppercase; color: var(--aj-gold); margin-bottom: 10px;
        }
        .aj-title {
            font-family: 'Manrope', sans-serif; font-weight: 800; margin: 0 0 12px;
            font-size: clamp(1.6rem, 5vw, 2.3rem); line-height: 1.18;
        }
        .aj-subtitle { font-size: .98rem; opacity: .88; max-width: 440px; margin: 0 auto; line-height: 1.6; }

        .aj-card {
            background: #fff; border-radius: 22px; position: relative; z-index: 2;
            margin-top: -42px; box-shadow: 0 26px 60px -14px rgba(6,20,40,.4), 0 2px 10px rgba(6,20,40,.08);
        }
        .aj-card-inner { padding: 34px 30px 30px; }

        .aj-fieldgroup { margin-bottom: 28px; }
        .aj-fieldgroup:last-of-type { margin-bottom: 8px; }
        .aj-fieldgroup-title {
            display: flex; align-items: center; gap: 9px; font-family: 'Manrope', sans-serif;
            font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
            color: var(--aj-blue); margin: 0 0 18px; padding-bottom: 11px; border-bottom: 2px solid #eef2f7;
        }
        .aj-fieldgroup-title i { font-size: 1.05rem; color: var(--aj-gold); }

        label.form-label { font-size: .82rem; font-weight: 600; color: #334155; margin-bottom: 5px; }

        .aj-input-group { border: 1.5px solid var(--aj-border); border-radius: 12px; overflow: hidden; transition: border-color .15s, box-shadow .15s; }
        .aj-input-group:focus-within { border-color: var(--aj-blue); box-shadow: 0 0 0 4px rgba(0,85,150,.12); }
        .aj-input-group .input-group-text { background: #f8fafc; border: none; color: var(--aj-blue); width: 44px; justify-content: center; font-size: 1rem; }
        .aj-input-group .form-control { border: none; padding: .65rem .8rem; font-size: .96rem; }
        .aj-input-group .form-control:focus { border: none; box-shadow: none; }

        .aj-consent {
            background: linear-gradient(135deg, #f8fafc, #eef4fa); border: 1.5px solid #dce7f0;
            border-radius: 14px; padding: 16px 18px; margin: 24px 0 22px;
        }
        .aj-consent .form-check-input { margin-top: .25rem; width: 1.15em; height: 1.15em; flex-shrink: 0; }
        .aj-consent .form-check-input:checked { background-color: var(--aj-blue); border-color: var(--aj-blue); }
        .aj-consent .form-check-label { font-size: .85rem; line-height: 1.55; color: #334155; }

        .aj-submit {
            width: 100%; border: none; border-radius: 14px; padding: 16px;
            font-family: 'Manrope', sans-serif; font-weight: 700; font-size: 1.02rem; color: #fff;
            background: linear-gradient(135deg, var(--aj-blue), var(--aj-blue-deep));
            box-shadow: 0 12px 26px -8px rgba(0,85,150,.55);
            display: flex; align-items: center; justify-content: center; gap: 10px;
            transition: transform .15s, box-shadow .15s; cursor: pointer;
        }
        .aj-submit:hover { transform: translateY(-2px); box-shadow: 0 18px 32px -8px rgba(0,85,150,.6); }
        .aj-submit:active { transform: translateY(0); }

        .aj-success { text-align: center; padding: 54px 30px 46px; }
        .aj-success-icon {
            width: 84px; height: 84px; border-radius: 50%; margin: 0 auto 22px;
            background: linear-gradient(135deg, #22c55e, #16a34a); display: flex; align-items: center; justify-content: center;
            box-shadow: 0 14px 28px -6px rgba(34,197,94,.5);
        }
        .aj-success-icon i { font-size: 2.3rem; color: #fff; }
        .aj-success h2 { font-family: 'Manrope', sans-serif; font-weight: 800; font-size: 1.35rem; margin-bottom: 10px; }
        .aj-success p { color: #64748b; max-width: 380px; margin: 0 auto; line-height: 1.6; }

        .aj-footer { text-align: center; color: rgba(255,255,255,.9); padding: 30px 20px 6px; position: relative; z-index: 1; }
        .aj-footer-contact { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 22px; margin-bottom: 16px; font-size: .86rem; }
        .aj-footer-contact a, .aj-footer-contact span { color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; opacity: .92; }
        .aj-footer-contact i { color: var(--aj-gold); font-size: 1rem; }
        .aj-copyright { font-size: .76rem; opacity: .6; }

        @media (max-width: 560px) {
            body { padding: 26px 10px 18px; }
            .aj-card { border-radius: 20px; }
            .aj-card-inner { padding: 28px 20px 24px; }
            .aj-hero { padding: 4px 12px 50px; }
        }
        <?php if ($hasBgImage): ?>
        /* Optional org-uploaded hero photo (Settings → Γενικές Ρυθμίσεις) — a
           semi-transparent overlay keeps the white title/subtitle readable
           over any photo. Scoped to .aj-hero (not body) so a tall page never
           forces this image to stretch/crop across the whole scroll height. */
        .aj-hero {
            background:
                linear-gradient(160deg, rgba(10,30,61,.86) 0%, rgba(0,85,150,.82) 55%, rgba(0,58,102,.88) 100%),
                url('uploads/backgrounds/<?= h($bgImage) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        <?php endif; ?>
    </style>
</head>
<body>

<div class="aj-dots"></div>

<div class="aj-shell">

    <div class="aj-hero">
        <div class="aj-logo">
            <?php if ($hasLogo): ?>
            <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($orgName) ?>">
            <?php else: ?>
            <span class="aj-logo-fallback"><?= h(mb_substr($orgName, 0, 1)) ?></span>
            <?php endif; ?>
        </div>
        <div class="aj-org-name"><?= h($orgName) ?></div>
        <div class="aj-kicker">Γίνε Μέλος της Ομάδας</div>
        <h1 class="aj-title">Αίτηση Υποψηφίου<br>Νέου Μέλους</h1>
        <p class="aj-subtitle">Συμπληρώστε τα στοιχεία σας και θα επικοινωνήσουμε μαζί σας σύντομα.</p>
    </div>

    <div class="aj-card">
    <?php if ($submitted): ?>

        <div class="aj-success">
            <div class="aj-success-icon"><i class="bi bi-check-lg"></i></div>
            <h2>Η αίτησή σας υποβλήθηκε!</h2>
            <p>Σας ευχαριστούμε για το ενδιαφέρον σας. Θα επικοινωνήσουμε μαζί σας σύντομα.</p>
        </div>

    <?php else: ?>
        <div class="aj-card-inner">

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <?= csrfField() ?>

            <section class="aj-fieldgroup">
                <h2 class="aj-fieldgroup-title"><i class="bi bi-person-fill"></i> Προσωπικά Στοιχεία</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Ονοματεπώνυμο <span class="text-danger">*</span></label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="full_name" class="form-control" required maxlength="100" autocomplete="name" value="<?= h(post('full_name')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Πατρώνυμο</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-person-vcard"></i></span>
                            <input type="text" name="patronymic" class="form-control" maxlength="100" value="<?= h(post('patronymic')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ημερομηνία Γέννησης</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                            <input type="date" name="birth_date" class="form-control" lang="el-GR" value="<?= h(post('birth_date')) ?>">
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Επαγγελματική ιδιότητα</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-briefcase"></i></span>
                            <input type="text" name="occupation" class="form-control" maxlength="150" value="<?= h(post('occupation')) ?>">
                        </div>
                    </div>
                </div>
            </section>

            <section class="aj-fieldgroup">
                <h2 class="aj-fieldgroup-title"><i class="bi bi-geo-alt-fill"></i> Στοιχεία Επικοινωνίας</h2>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Διεύθυνση</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-house"></i></span>
                            <input type="text" name="address" class="form-control" maxlength="255" autocomplete="street-address" value="<?= h(post('address')) ?>">
                        </div>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Τ.Κ.</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-mailbox2"></i></span>
                            <input type="text" name="postal_code" class="form-control" maxlength="10" autocomplete="postal-code" value="<?= h(post('postal_code')) ?>">
                        </div>
                    </div>
                    <div class="col-8">
                        <label class="form-label">Πόλη</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                            <input type="text" name="city" class="form-control" maxlength="100" autocomplete="address-level2" value="<?= h(post('city')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Τηλέφωνο Οικίας</label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" name="home_phone" class="form-control" autocomplete="tel" inputmode="tel" value="<?= h(post('home_phone')) ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Τηλέφωνο Κινητό <span class="text-danger">*</span></label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-phone"></i></span>
                            <input type="tel" name="mobile_phone" class="form-control" required autocomplete="tel" inputmode="tel" value="<?= h(post('mobile_phone')) ?>">
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <div class="input-group aj-input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" name="email" class="form-control" required autocomplete="email" value="<?= h(post('email')) ?>">
                        </div>
                    </div>
                </div>
            </section>

            <div class="aj-consent">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="gdpr_consent" value="1" id="ajGdprConsent" required>
                    <label class="form-check-label" for="ajGdprConsent">
                        <span class="fw-semibold">Συναινώ στην επεξεργασία των παραπάνω προσωπικών δεδομένων από την <?= h($orgName) ?> για τους σκοπούς αξιολόγησης της αίτησής μου.</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="aj-submit"><i class="bi bi-send-fill"></i> Υποβολή Αίτησης</button>
        </form>
        </div>
    <?php endif; ?>
    </div>

    <footer class="aj-footer">
        <?php if ($hasFooterContact): ?>
        <div class="aj-footer-contact">
            <?php if ($contactPhone !== ''): ?>
            <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><i class="bi bi-telephone-fill"></i> <?= h($contactPhone) ?></a>
            <?php endif; ?>
            <?php if ($contactEmail !== ''): ?>
            <a href="mailto:<?= h($contactEmail) ?>"><i class="bi bi-envelope-fill"></i> <?= h($contactEmail) ?></a>
            <?php endif; ?>
            <?php if ($contactAddress !== ''): ?>
            <span><i class="bi bi-geo-alt-fill"></i> <?= h($contactAddress) ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="aj-copyright">© <?= date('Y') ?> <?= h($orgName) ?></div>
    </footer>

</div>

</body>
</html>
