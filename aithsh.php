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
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#005596">
    <meta name="robots" content="noindex, nofollow">
    <title>Αίτηση Υποψηφίου Νέου Μέλους — <?= h($orgName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        body { background: #eef0f4; }
        .aj-page { max-width: 560px; margin: 24px auto; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 4px 18px rgba(0,85,150,.14); }
        .aj-hero { background: linear-gradient(135deg, #005596, #003a66); color: #fff; padding: 26px 24px; }
        .aj-section { padding: 26px 24px; }
        .aj-consent { background: #f6f8fa; border: 1px solid #e2e6ea; border-radius: 12px; padding: 12px 14px; }
        .aj-consent .form-check-input { margin-top: .2rem; }
        .aj-consent .form-check-label { font-size: 13px; line-height: 1.45; color: #005596; }
        label.form-label { font-weight: 600; font-size: 14px; color: #333; }
        @media (max-width: 560px) { .aj-page { margin: 0; border-radius: 0; } body { background: #fff; } }
    </style>
</head>
<body>

<div class="aj-page">
    <div class="aj-hero">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <?php if ($hasLogo): ?>
            <img src="uploads/logos/<?= h($appLogo) ?>" alt="" style="width:30px;height:30px;border-radius:9px;object-fit:cover;">
            <?php else: ?>
            <div style="width:30px;height:30px;border-radius:9px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;"><?= h(mb_substr($orgName, 0, 1)) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;opacity:.9;font-weight:600;"><?= h($orgName) ?></div>
        </div>
        <div style="font-size:20px;font-weight:800;line-height:1.3;">Αίτηση Υποψηφίου Νέου Μέλους</div>
        <div style="font-size:12.5px;opacity:.9;margin-top:6px;">Συμπληρώστε τα στοιχεία σας και θα επικοινωνήσουμε μαζί σας σύντομα.</div>
    </div>

    <?php if ($submitted): ?>

    <div class="aj-section text-center py-5">
        <i class="bi bi-check-circle-fill text-success" style="font-size:2.8rem;"></i>
        <h2 class="mt-3 mb-2" style="font-size:1.25rem;">Η αίτησή σας υποβλήθηκε!</h2>
        <p class="text-muted mb-0">Σας ευχαριστούμε για το ενδιαφέρον σας. Θα επικοινωνήσουμε μαζί σας σύντομα.</p>
    </div>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="aj-section pb-0">
        <div class="alert alert-danger mb-0">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="aj-section">
        <form method="post" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label">Ονοματεπώνυμο <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control form-control-lg" required maxlength="100" autocomplete="name" value="<?= h(post('full_name')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Πατρώνυμο</label>
                <input type="text" name="patronymic" class="form-control form-control-lg" maxlength="100" value="<?= h(post('patronymic')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Ημερομηνία Γέννησης</label>
                <input type="date" name="birth_date" class="form-control form-control-lg" lang="el-GR" value="<?= h(post('birth_date')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Διεύθυνση</label>
                <input type="text" name="address" class="form-control form-control-lg" maxlength="255" autocomplete="street-address" value="<?= h(post('address')) ?>">
            </div>
            <div class="row">
                <div class="col-5 mb-3">
                    <label class="form-label">Τ.Κ.</label>
                    <input type="text" name="postal_code" class="form-control form-control-lg" maxlength="10" autocomplete="postal-code" value="<?= h(post('postal_code')) ?>">
                </div>
                <div class="col-7 mb-3">
                    <label class="form-label">Πόλη</label>
                    <input type="text" name="city" class="form-control form-control-lg" maxlength="100" autocomplete="address-level2" value="<?= h(post('city')) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Τηλέφωνο Οικίας</label>
                <input type="tel" name="home_phone" class="form-control form-control-lg" autocomplete="tel" inputmode="tel" value="<?= h(post('home_phone')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Τηλέφωνο Κινητό <span class="text-danger">*</span></label>
                <input type="tel" name="mobile_phone" class="form-control form-control-lg" required autocomplete="tel" inputmode="tel" value="<?= h(post('mobile_phone')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control form-control-lg" required autocomplete="email" value="<?= h(post('email')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Επαγγελματική ιδιότητα</label>
                <input type="text" name="occupation" class="form-control form-control-lg" maxlength="150" value="<?= h(post('occupation')) ?>">
            </div>

            <div class="aj-consent mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="gdpr_consent" value="1" id="ajGdprConsent" required>
                    <label class="form-check-label" for="ajGdprConsent">
                        <span class="fw-semibold">Συναινώ στην επεξεργασία των παραπάνω προσωπικών δεδομένων από την <?= h($orgName) ?> για τους σκοπούς αξιολόγησης της αίτησής μου.</span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-lg w-100 fw-bold text-white" style="background:#005596;">Υποβολή Αίτησης</button>
        </form>
    </div>

    <?php endif; ?>
</div>

</body>
</html>
