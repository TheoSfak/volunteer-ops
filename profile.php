<?php
/**
 * VolunteerOps - Profile Page
 *
 * i18n scope (Action Room English support, see includes/i18n.php): only the
 * "core account" parts of this page are translated via t() — hero header,
 * the profile-edit form, password change, avatar upload, and the booked-
 * equipment table. Everything else (annual subscription/IRIS, certificates,
 * exam history, achievements, TEP hours) is Greek-only BY DESIGN, not an
 * oversight — the user explicitly scoped it out since this page turned out
 * far bigger than expected. Look for the "i18n scope" HTML comments below
 * marking where the translated section starts/ends before adding new text
 * to either side.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/subscription-iris.php';
requireLogin();

$pageTitle = t('profile.page_title');
$user = getCurrentUser();

// Get volunteer profile
$profile = dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$user['id']]);
$mySubscription = dbFetchOne("SELECT * FROM volunteer_subscriptions WHERE user_id = ? ORDER BY expiry_date DESC, id DESC LIMIT 1", [$user['id']]);
$subscriptionHistory = dbFetchAll("SELECT * FROM volunteer_subscriptions WHERE user_id = ? ORDER BY payment_date DESC, id DESC", [$user['id']]);

// Personal documents uploaded by the administration for this volunteer.
$myDocuments = dbFetchAll(
    "SELECT id, label, original_name, stored_name, mime_type, file_size, created_at
     FROM volunteer_documents
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC",
    [$user['id']]
);

// Get user skills
$userSkills = dbFetchAll(
    "SELECT s.*, us.level FROM skills s 
     JOIN user_skills us ON s.id = us.skill_id 
     WHERE us.user_id = ?",
    [$user['id']]
);

// Get all skills for selection
$allSkills = dbFetchAll("SELECT * FROM skills ORDER BY category, name");

// Get stats
$stats = [
    'total_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = '" . PARTICIPATION_APPROVED . "'",
        [$user['id']]
    ),
    'total_hours' => dbFetchValue(
        "SELECT COALESCE(SUM(
            CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
            ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END
        ), 0)
        FROM participation_requests pr
        JOIN shifts s ON pr.shift_id = s.id
        WHERE pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1",
        [$user['id']]
    ),
    'achievements' => (getSetting('achievements_enabled', '1') === '1') ? dbFetchValue(
        "SELECT COUNT(*) FROM user_achievements WHERE user_id = ?",
        [$user['id']]
    ) : 0,
];

// Mission attendance for current year (only Υγειονομική + Διασωστική count)
$currentYear = date('Y');
$attTypeIds = getAttendanceMissionTypeIds();
$attPlaceholders = implode(',', array_fill(0, count($attTypeIds), '?'));
$missionAttendance = (int) dbFetchValue(
    "SELECT COUNT(DISTINCT m.id)
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.volunteer_id = ? AND pr.attended = 1
     AND YEAR(m.start_datetime) = ?
     AND m.mission_type_id IN ($attPlaceholders)",
    array_merge([$user['id'], $currentYear], $attTypeIds)
);
$attendanceGoal = (int) getSetting('prereq_attendance_goal', '10');
$attendancePct = $attendanceGoal > 0 ? min(100, round(($missionAttendance / $attendanceGoal) * 100)) : 0;
$attendanceColor = $missionAttendance >= $attendanceGoal ? 'success' : ($missionAttendance >= 7 ? 'info' : ($missionAttendance >= 4 ? 'warning' : 'danger'));

// Τ.Ε.Π. hours (only for TRAINEE_RESCUER)
$tepHours = 0;
$tepGoal = (int) getSetting('prereq_tep_hours_goal', '40');
$tepPct = 0;
$tepColor = 'danger';
if (isTraineeRescuer()) {
    $tepHours = (float) dbFetchValue(
        "SELECT COALESCE(SUM(
            CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
            ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END
        ), 0)
        FROM participation_requests pr
        JOIN shifts s ON pr.shift_id = s.id
        JOIN missions m ON s.mission_id = m.id
        WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1
          AND m.mission_type_id = ?",
        [$user['id'], PARTICIPATION_APPROVED, getTepMissionTypeId()]
    );
    $tepPct = $tepGoal > 0 ? min(100, round(($tepHours / $tepGoal) * 100)) : 0;
    $tepColor = $tepHours >= $tepGoal ? 'success' : ($tepHours >= 25 ? 'info' : ($tepHours >= 10 ? 'warning' : 'danger'));
}

// Educational missions (only for RESCUER)
$eduMissions = 0;
$eduGoal = (int) getSetting('prereq_edu_attendance_goal', '2');
$eduPct = 0;
$eduColor = 'danger';
if (($user['volunteer_type'] ?? '') === VTYPE_RESCUER) {
    $eduMissions = (int) dbFetchValue(
        "SELECT COUNT(DISTINCT m.id)
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.volunteer_id = ? AND pr.attended = 1
           AND YEAR(m.start_datetime) = ?
           AND m.mission_type_id = ?",
        [$user['id'], $currentYear, getEduMissionTypeId()]
    );
    $eduPct = $eduGoal > 0 ? min(100, round(($eduMissions / $eduGoal) * 100)) : 0;
    $eduColor = $eduMissions >= $eduGoal ? 'success' : ($eduMissions >= 1 ? 'warning' : 'danger');
}

// Get exam attempts history
$examAttempts = dbFetchAll("
    SELECT ea.id, ea.exam_id, ea.score, ea.passed, ea.time_taken_seconds, ea.completed_at,
           te.title as exam_title, te.questions_per_attempt as total_questions,
           te.passing_percentage,
           tc.name as category_name, tc.icon as category_icon
    FROM exam_attempts ea
    INNER JOIN training_exams te ON ea.exam_id = te.id
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE ea.user_id = ?
    ORDER BY ea.completed_at DESC
", [$user['id']]);

$errors = [];
$success = '';
$openIrisModal = false;

if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'update_profile':
            $name = post('name');
            $phone = post('phone');
            
            if (empty($name)) {
                $errors[] = t('profile.name_required');
            }
            
            if (empty($errors)) {
                dbExecute(
                    "UPDATE users SET name = ?, phone = ?, updated_at = NOW() WHERE id = ?",
                    [$name, $phone, $user['id']]
                );
                
                // Update profile
                $profileData = [
                    'bio' => post('bio'),
                    'address' => post('address'),
                    'city' => post('city'),
                    'postal_code' => post('postal_code'),
                    'emergency_contact_name' => post('emergency_contact_name'),
                    'emergency_contact_phone' => post('emergency_contact_phone'),
                    'blood_type' => post('blood_type'),
                    'available_weekdays' => isset($_POST['available_weekdays']) ? 1 : 0,
                    'available_weekends' => isset($_POST['available_weekends']) ? 1 : 0,
                    'available_nights' => isset($_POST['available_nights']) ? 1 : 0,
                    'has_driving_license' => isset($_POST['has_driving_license']) ? 1 : 0,
                    'has_first_aid' => isset($_POST['has_first_aid']) ? 1 : 0,
                ];
                
                if ($profile) {
                    dbExecute(
                        "UPDATE volunteer_profiles SET 
                         bio = ?, address = ?, city = ?, postal_code = ?,
                         emergency_contact_name = ?, emergency_contact_phone = ?,
                         blood_type = ?, available_weekdays = ?, available_weekends = ?,
                         available_nights = ?, has_driving_license = ?, has_first_aid = ?,
                         updated_at = NOW()
                         WHERE user_id = ?",
                        array_merge(array_values($profileData), [$user['id']])
                    );
                } else {
                    dbInsert(
                        "INSERT INTO volunteer_profiles 
                         (user_id, bio, address, city, postal_code, emergency_contact_name,
                          emergency_contact_phone, blood_type, available_weekdays, available_weekends,
                          available_nights, has_driving_license, has_first_aid, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        array_merge([$user['id']], array_values($profileData))
                    );
                }
                
                logAudit('update_profile', 'users', $user['id']);
                $success = t('profile.updated_success');
                
                // Refresh data
                $user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$user['id']]);
                $profile = dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$user['id']]);
            }
            break;
            
        case 'update_password':
            $currentPass = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPass) || empty($newPass)) {
                $errors[] = t('profile.fill_all_fields');
            } elseif ($newPass !== $confirmPass) {
                $errors[] = t('profile.passwords_dont_match');
            } elseif (strlen($newPass) < 6) {
                $errors[] = t('profile.password_too_short');
            } else {
                $result = updatePassword($user['id'], $currentPass, $newPass);
                if ($result['success']) {
                    $success = t('profile.password_changed_success');
                } else {
                    $errors[] = $result['message'];
                }
            }
            break;
            
        case 'update_skills':
            // Remove existing skills
            dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$user['id']]);
            
            // Add new skills
            if (!empty($_POST['skills'])) {
                foreach ($_POST['skills'] as $skillId => $level) {
                    if (!empty($level)) {
                        dbInsert(
                            "INSERT INTO user_skills (user_id, skill_id, level) VALUES (?, ?, ?)",
                            [$user['id'], $skillId, $level]
                        );
                    }
                }
            }
            
            logAudit('update_skills', 'users', $user['id']);
            $success = 'Οι δεξιότητες ενημερώθηκαν.';
            $userSkills = dbFetchAll(
                "SELECT s.*, us.level FROM skills s 
                 JOIN user_skills us ON s.id = us.skill_id 
                 WHERE us.user_id = ?",
                [$user['id']]
            );
            break;

        case 'prepare_iris_renewal':
            try {
                $request = subscriptionIrisPrepare((int)$user['id'], $mySubscription ?: [], (int)post('coverage_years', 1));
                logAudit('prepare_subscription_iris_renewal', 'subscription_iris_requests', (int)$request['id']);
                $success = 'Εμφανίστηκαν οι οδηγίες πληρωμής IRIS για ' . (int)$request['coverage_years'] . ' έτη.';
                $openIrisModal = true;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
            break;

        case 'report_iris_payment':
            try {
                $request = subscriptionIrisReportPayment((int)$user['id'], $mySubscription ?: []);
                logAudit('report_subscription_iris_payment', 'subscription_iris_requests', (int)$request['id']);
                $success = 'Η ενημέρωση πληρωμής IRIS στάλθηκε στη διοίκηση.';
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
            break;

        case 'update_photo':
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['photo'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowedMime)) {
                    $errors[] = t('profile.photo_invalid_type');
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = t('profile.photo_too_large');
                } else {
                    $src = match($mime) {
                        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
                        'image/png'  => imagecreatefrompng($file['tmp_name']),
                        'image/gif'  => imagecreatefromgif($file['tmp_name']),
                        'image/webp' => imagecreatefromwebp($file['tmp_name']),
                        default      => false,
                    };
                    if ($src) {
                        $srcW = imagesx($src);
                        $srcH = imagesy($src);
                        // Center-crop to square, then resize to 250×250
                        $cropSize = min($srcW, $srcH);
                        $cropX = (int)(($srcW - $cropSize) / 2);
                        $cropY = (int)(($srcH - $cropSize) / 2);
                        $dst = imagecreatetruecolor(250, 250);
                        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 250, 250, $cropSize, $cropSize);
                        $filename = $user['id'] . '.jpg';
                        $avatarDir = __DIR__ . '/uploads/avatars/';
                        if (!is_dir($avatarDir)) {
                            mkdir($avatarDir, 0755, true);
                        }
                        $savePath = $avatarDir . $filename;
                        imagejpeg($dst, $savePath, 90);
                        imagedestroy($src);
                        imagedestroy($dst);
                        dbExecute("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?", [$filename, $user['id']]);
                        logAudit('update_photo', 'users', $user['id']);
                        $user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$user['id']]);
                        $success = t('profile.photo_updated');
                    } else {
                        $errors[] = t('profile.photo_processing_failed');
                    }
                }
            } elseif (post('delete_photo') === '1') {
                if (!empty($user['profile_photo'])) {
                    $oldFile = __DIR__ . '/uploads/avatars/' . $user['profile_photo'];
                    if (file_exists($oldFile)) unlink($oldFile);
                }
                dbExecute("UPDATE users SET profile_photo = NULL, updated_at = NOW() WHERE id = ?", [$user['id']]);
                logAudit('delete_photo', 'users', $user['id']);
                $user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$user['id']]);
                $success = t('profile.photo_deleted');
            }
            break;
    }
}

// Active inventory bookings for this user
$activeBookings = [];
if (function_exists('inventoryTablesExist') && inventoryTablesExist()) {
    $activeBookings = getUserActiveBookings($user['id']);
} elseif (file_exists(__DIR__ . '/includes/inventory-functions.php')) {
    require_once __DIR__ . '/includes/inventory-functions.php';
    if (inventoryTablesExist()) {
        $activeBookings = getUserActiveBookings($user['id']);
    }
}

include __DIR__ . '/includes/header.php';
?>

<style>
/* Profile Page Beautification */
.profile-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 1rem;
    padding: 1.5rem;
    color: #fff;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 32px rgba(102,126,234,.25);
    position: relative;
    overflow: hidden;
}
.profile-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: rgba(255,255,255,.06);
    border-radius: 50%;
}
.profile-hero .hero-avatar {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(255,255,255,.4);
    box-shadow: 0 4px 15px rgba(0,0,0,.2);
}
.profile-hero .hero-avatar-placeholder {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    background: rgba(255,255,255,.15);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    border: 3px solid rgba(255,255,255,.4);
}
.pp-stat-card {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    transition: transform .2s, box-shadow .2s;
    overflow: hidden;
}
.pp-stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 20px rgba(0,0,0,.1);
}
.pp-stat-card .stat-icon {
    width: 40px;
    height: 40px;
    border-radius: .6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    color: #fff;
    flex-shrink: 0;
}
.pp-stat-card .stat-value {
    font-size: 1.4rem;
    font-weight: 700;
    line-height: 1;
}
.pp-card {
    border: none;
    border-radius: .75rem;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    transition: box-shadow .2s;
    overflow: hidden;
}
.pp-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.09); }
.pp-card .card-header {
    background: #fff;
    border-bottom: 2px solid #eee;
    padding: .75rem 1rem;
}
.pp-card .card-header h5 { font-size: .95rem; font-weight: 600; }
.pp-card.accent-primary .card-header { border-bottom-color: #667eea; }
.pp-card.accent-success .card-header { border-bottom-color: #10b981; }
.pp-card.accent-warning .card-header { border-bottom-color: #f59e0b; }
.pp-card.accent-danger .card-header { border-bottom-color: #ef4444; }
.iris-renewal-disabled { position: relative; }
.iris-renewal-disabled:hover::after, .iris-renewal-disabled:focus-within::after { content: attr(data-tooltip); position: absolute; z-index: 1080; top: calc(100% + .45rem); right: 0; width: 250px; padding: .5rem .65rem; background: #fff5f5; border: 1px solid #dc3545; border-radius: .35rem; color: #dc3545; font-size: .8rem; font-weight: 700; line-height: 1.25; box-shadow: 0 .25rem .6rem rgba(0,0,0,.15); }
@media (max-width: 768px) {
    .profile-hero { padding: 1rem; text-align: center; }
    .profile-hero .d-flex { flex-direction: column; gap: .75rem; }
    .pp-stat-card .card-body { padding: .65rem !important; }
    .pp-stat-card .stat-value { font-size: 1.15rem; }
    .pp-card .card-header { flex-wrap: wrap; gap: .5rem; }
    .pp-card .card-header > .d-flex { flex-wrap: wrap; width: 100%; }
    .pp-card .card-header > .d-flex .btn,
    .pp-card .card-header > .d-flex form,
    .pp-card .card-header > .d-flex .iris-renewal-disabled { width: 100%; }
    .pp-card .card-header > .d-flex .btn { white-space: normal; }
    .pp-mobile-table-wrap { overflow: visible; }
    .pp-mobile-table thead { display: none; }
    .pp-mobile-table, .pp-mobile-table tbody, .pp-mobile-table tr, .pp-mobile-table td { display: block; width: 100%; }
    .pp-mobile-table tbody { padding: .75rem; }
    .pp-mobile-table tr { margin-bottom: .75rem; padding: .35rem .8rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .pp-mobile-table td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; border-bottom: 1px solid var(--bs-border-color-translucent); text-align: right !important; white-space: normal !important; overflow-wrap: anywhere; }
    .pp-mobile-table td:last-child { border: 0; }
    .pp-mobile-table td::before { flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .pp-subscription-table td:nth-child(1)::before { content: "Πληρωμή"; }
    .pp-subscription-table td:nth-child(2)::before { content: "Λήξη κάλυψης"; }
    .pp-subscription-table td:nth-child(3)::before { content: "Έτη"; }
    .pp-subscription-table td:nth-child(4)::before { content: "Ποσό"; }
    .pp-subscription-table td:nth-child(5)::before { content: "Τρόπος"; }
    .pp-subscription-table td:nth-child(6)::before { content: "Αρ. απόδειξης"; }
    .pp-subscription-table td:nth-child(7)::before { content: "Απόδειξη"; }
    .pp-exams-table td:nth-child(1)::before { content: "Διαγώνισμα"; }
    .pp-exams-table td:nth-child(2)::before { content: "Κατηγορία"; }
    .pp-exams-table td:nth-child(3)::before { content: "Βαθμός"; }
    .pp-exams-table td:nth-child(4)::before { content: "Ποσοστό"; }
    .pp-exams-table td:nth-child(5)::before { content: "Αποτέλεσμα"; }
    .pp-exams-table td:nth-child(6)::before { content: "Ημερομηνία"; }
    .pp-exams-table td:nth-child(7)::before { content: "Ενέργειες"; }
    .pp-bookings-table td:nth-child(1)::before { content: "Barcode"; }
    .pp-bookings-table td:nth-child(2)::before { content: "Υλικό"; }
    .pp-bookings-table td:nth-child(3)::before { content: "Ημ. χρέωσης"; }
    .pp-bookings-table td:nth-child(4)::before { content: "Αναμ. επιστροφή"; }
    .pp-bookings-table td:nth-child(5)::before { content: "Κατάσταση"; }
}
</style>

<!-- Profile Hero Header -->
<div class="profile-hero">
    <div class="d-flex align-items-center gap-3">
        <?php
        $myPhoto = $user['profile_photo'] ?? null;
        $myPhotoExists = $myPhoto && file_exists(__DIR__ . '/uploads/avatars/' . $myPhoto);
        ?>
        <?php if ($myPhotoExists): ?>
            <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($myPhoto) ?>?t=<?= time() ?>" class="hero-avatar" alt="">
        <?php else: ?>
            <div class="hero-avatar-placeholder"><i class="bi bi-person-fill"></i></div>
        <?php endif; ?>
        <div class="flex-grow-1">
            <h1 class="h4 mb-1 text-white fw-bold"><?= guestNameHtml($user['name'], (bool)$user['is_external'], $user['guest_org_name']) ?></h1>
            <div style="opacity:.85">
                <i class="bi bi-envelope me-1"></i><?= h($user['email']) ?>
                <?php if ($user['phone']): ?>
                    <span class="ms-3"><i class="bi bi-telephone me-1"></i><?php if ($user['phone']): ?><a href="tel:<?= h($user['phone']) ?>" style="color:#fff !important; text-decoration:none;"><?= h($user['phone']) ?></a><?php else: ?>-<?php endif; ?></span>
                <?php endif; ?>
            </div>
            <div class="mt-1">
                <?= roleBadge($user['role']) ?>
                <?= volunteerTypeBadge($user['volunteer_type'] ?? VTYPE_RESCUER) ?>
                <span class="badge bg-light text-dark ms-1" style="font-size:.72rem"><i class="bi bi-calendar3 me-1"></i><?= t('profile.member_since_prefix', ['date' => formatDate($user['created_at'])]) ?></span>
                <a href="volunteer-report.php?id=<?= $user['id'] ?>" target="_blank" class="badge bg-info text-white ms-1 text-decoration-none" style="font-size:.72rem"><i class="bi bi-file-earmark-text me-1"></i><?= t('profile.report_link') ?></a>
            </div>
        </div>
    </div>
</div>

<!-- i18n scope: everything from here down to the "i18n scope: core account
     section resumes" marker below is Greek-only by design (IRIS/subscription,
     certificates, exam history, achievements, TEP) — do not add t() calls here
     without first checking includes/lang/war-room.php's profile.php section. -->
<?php if (!isExternalGuest()): ?>
<!-- Guest accounts skip this whole block: subscription/IRIS, hours/points/
     achievements tiles, exam history, attendance/TEP/training-mission goals
     are all concepts that don't apply to a partner-org volunteer visiting for
     one mission — none of it is meaningful or should be tracked for them. -->
<!-- Annual Subscription -->
<?php $subscriptionDays = $mySubscription ? (int)floor((strtotime($mySubscription['expiry_date']) - strtotime(date('Y-m-d'))) / 86400) : null; $subscriptionColor = $subscriptionDays === null ? 'secondary' : ($subscriptionDays < 0 ? 'danger' : ($subscriptionDays <= 7 ? 'danger' : ($subscriptionDays <= 30 ? 'warning' : ($subscriptionDays <= 90 ? 'info' : 'success')))); ?>
<?php $myIrisRequest = subscriptionIrisLatestRequest((int)$user['id']); $canIrisRenew = subscriptionIrisIsEligible($mySubscription); ?>
<div class="card pp-card accent-<?= $subscriptionColor ?> mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-cash-coin text-<?= $subscriptionColor ?> me-2"></i>Η Ετήσια Συνδρομή μου</h5>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary fw-bold" data-bs-toggle="modal" data-bs-target="#myPaymentHistoryModal">
                <i class="bi bi-clock-history me-1"></i>Πληρωμές <span class="badge text-bg-primary ms-1"><?= count($subscriptionHistory) ?></span>
            </button>
            <?php if ($canIrisRenew): ?>
                <button type="button" class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#irisRenewalModal"><i class="bi bi-arrow-repeat me-1"></i>Ανανέωση συνδρομής</button>
                <form method="post" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="report_iris_payment"><button class="btn btn-success fw-bold" <?= !$myIrisRequest || $myIrisRequest['status'] !== 'PREPARED' ? 'disabled title="Επιλέξτε πρώτα τη διάρκεια ανανέωσης"' : '' ?>><i class="bi bi-check2-circle me-1"></i>Ενημέρωση πληρωμής</button></form>
            <?php else: ?>
                <span class="d-inline-block iris-renewal-disabled" tabindex="0" data-tooltip="Η ανανέωση ενεργοποιείται 3 μήνες πριν από τη λήξη της συνδρομής."><button type="button" class="btn btn-outline-secondary fw-bold" disabled><i class="bi bi-arrow-repeat me-1"></i>Ανανέωση συνδρομής</button></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$mySubscription): ?>
            <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Δεν υπάρχει καταχωρημένη ετήσια συνδρομή. Επικοινωνήστε με τη διοίκηση.</div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-2"><strong>Λήξη: <?= formatDate($mySubscription['expiry_date']) ?></strong><span class="badge bg-<?= $subscriptionColor ?>"><?= $subscriptionDays < 0 ? 'Ληγμένη' : ($subscriptionDays === 0 ? 'Λήγει σήμερα' : 'Ενεργή για ' . $subscriptionDays . ' ημέρες') ?></span></div>
            <div class="progress" style="height:8px"><div class="progress-bar bg-<?= $subscriptionColor ?>" style="width:<?= $subscriptionDays < 0 ? 100 : min(100, max(8, round($subscriptionDays / 365 * 100))) ?>%"></div></div>
            <div class="small text-muted mt-2">Τελευταία πληρωμή: <?= formatDate($mySubscription['payment_date']) ?></div>
            <?php if (!empty($mySubscription['receipt_stored_name']) && is_file(__DIR__ . '/uploads/subscription-receipts/' . basename($mySubscription['receipt_stored_name']))): ?>
                <?php $myReceiptIsImage = in_array(strtolower(pathinfo($mySubscription['receipt_stored_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true); ?>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-3 subscription-history-receipt-btn" data-receipt-modal="myPaymentReceiptModal" data-preview-url="subscription-receipt.php?id=<?= (int)$mySubscription['id'] ?>" data-preview-type="<?= $myReceiptIsImage ? 'image' : 'pdf' ?>" data-preview-name="<?= h($mySubscription['receipt_original_name'] ?: 'Η απόδειξή μου') ?>">
                    <i class="bi bi-file-earmark-text me-1"></i>Προβολή απόδειξης
                </button>
            <?php elseif (!empty($mySubscription['receipt_stored_name'])): ?>
                <div class="small text-danger mt-3"><i class="bi bi-exclamation-triangle me-1"></i>Το αρχείο της απόδειξης δεν είναι διαθέσιμο. Επικοινωνήστε με τη διοίκηση.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
$paymentHistoryRows = $subscriptionHistory;
$paymentHistoryModalId = 'myPaymentHistoryModal';
$paymentReceiptModalId = 'myPaymentReceiptModal';
$paymentHistoryTitle = 'Οι πληρωμές συνδρομής μου';
include __DIR__ . '/includes/subscription-payment-history-modal.php';
?>

<!-- Personal Documents -->
<div class="card pp-card accent-primary mb-4" id="documents">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-folder2-open text-primary me-2"></i>Τα Αρχεία &amp; Έγγραφά μου</h5>
        <span class="badge bg-primary rounded-pill"><?= count($myDocuments) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($myDocuments)): ?>
            <p class="text-muted p-3 mb-0">Δεν υπάρχουν καταχωρημένα αρχεία.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($myDocuments as $document): ?>
                    <?php
                    $documentIsImage = str_starts_with((string)$document['mime_type'], 'image/');
                    $documentIcon = $documentIsImage ? 'bi-file-image text-info' : 'bi-file-earmark-pdf text-danger';
                    if (in_array($document['mime_type'], ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
                        $documentIcon = 'bi-file-earmark-word text-primary';
                    }
                    $documentExists = !empty($document['stored_name'])
                        && is_file(__DIR__ . '/uploads/volunteer-docs/' . basename($document['stored_name']));
                    ?>
                    <li class="list-group-item py-3">
                        <div class="d-flex align-items-center gap-3">
                            <i class="bi <?= $documentIcon ?> fs-4 flex-shrink-0"></i>
                            <div class="flex-grow-1 overflow-hidden">
                                <?php if ($documentExists): ?>
                                    <a href="volunteer-doc-download.php?id=<?= (int)$document['id'] ?>&amp;volunteer=<?= (int)$user['id'] ?>"
                                       target="_blank" class="fw-semibold text-decoration-none text-break">
                                        <?= h($document['original_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="fw-semibold text-break"><?= h($document['original_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($document['label']) && $document['label'] !== $document['original_name']): ?>
                                    <div class="small text-muted text-break"><?= h($document['label']) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted">
                                    <?= number_format(((int)$document['file_size']) / 1024, 0, ',', '.') ?> KB
                                    &middot; <?= formatDate($document['created_at']) ?>
                                </div>
                            </div>
                            <?php if ($documentExists): ?>
                                <a href="volunteer-doc-download.php?id=<?= (int)$document['id'] ?>&amp;volunteer=<?= (int)$user['id'] ?>"
                                   target="_blank" class="btn btn-sm btn-outline-primary flex-shrink-0" aria-label="Προβολή <?= h($document['original_name']) ?>">
                                    <i class="bi bi-eye me-1"></i><span class="d-none d-sm-inline">Προβολή</span>
                                </a>
                            <?php else: ?>
                                <span class="badge bg-danger flex-shrink-0">Μη διαθέσιμο</span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($canIrisRenew): ?>
<div class="modal fade" id="irisRenewalModal" tabindex="-1" aria-labelledby="irisRenewalModalTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post">
<?= csrfField() ?><input type="hidden" name="action" value="prepare_iris_renewal"><div class="modal-header"><h5 class="modal-title" id="irisRenewalModalTitle"><i class="bi bi-phone-vibrate me-2"></i>Ανανέωση με IRIS</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button></div>
<div class="modal-body"><p class="mb-2">Η ανανέωση είναι διαθέσιμη έως <strong><?= subscriptionIrisRenewalDays() ?> ημέρες πριν από τη λήξη</strong> της συνδρομής, όχι νωρίτερα.</p><div class="alert alert-info"><strong>Πληρωμή IRIS στο ΑΦΜ:</strong> <span class="fs-5"><?= h(subscriptionIrisTaxId()) ?></span></div><label class="form-label fw-semibold" for="irisCoverageYears">Διάρκεια ανανέωσης</label><select class="form-select" id="irisCoverageYears" name="coverage_years"><?php for ($irisYear = 1; $irisYear <= 5; $irisYear++): ?><option value="<?= $irisYear ?>" <?= (int)($myIrisRequest['coverage_years'] ?? 1) === $irisYear ? 'selected' : '' ?>><?= $irisYear ?> <?= $irisYear === 1 ? 'έτος' : 'έτη' ?></option><?php endfor; ?></select><div class="alert alert-success mt-3 mb-0"><strong>Ποσό προς πληρωμή: <span id="irisRenewalAmount"></span></strong><div class="small mt-1"><?= number_format(subscriptionIrisAnnualAmount(), 2, ',', '.') ?> € ανά έτος.</div></div><?php if ($myIrisRequest && $myIrisRequest['status'] === 'PREPARED'): ?><div class="small text-muted mt-3">Αφού ολοκληρώσετε την πληρωμή, πατήστε «Ενημέρωση πληρωμής» στην κάρτα συνδρομής.</div><?php endif; ?></div>
<div class="alert alert-warning mx-3 mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>Σημαντικό:</strong> Μετά την πληρωμή IRIS, μην ξεχάσετε να πατήσετε το κουμπί «Ενημέρωση πληρωμής» στην κάρτα συνδρομής.</div>
<?php if ($myIrisRequest && $myIrisRequest['status'] === 'PREPARED'): ?><div class="modal-footer"><button type="button" class="btn btn-primary fw-bold" data-bs-dismiss="modal"><i class="bi bi-check-lg me-1"></i>Κατάλαβα τις οδηγίες</button></div><?php else: ?><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Ακύρωση</button><button class="btn btn-primary fw-bold">Εμφάνιση οδηγιών IRIS</button></div><?php endif; ?></form></div></div></div>
<script>document.addEventListener('DOMContentLoaded', () => { const years = document.getElementById('irisCoverageYears'); const amount = document.getElementById('irisRenewalAmount'); const annual = <?= json_encode(subscriptionIrisAnnualAmount()) ?>; const update = () => amount.textContent = (Number(years.value) * annual).toLocaleString('el-GR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €'; years.addEventListener('change', update); update(); <?php if ($openIrisModal): ?>bootstrap.Modal.getOrCreateInstance(document.getElementById('irisRenewalModal')).show();<?php endif; ?> });</script>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<!-- Annual Mission Attendance Progress -->
<div class="card pp-card mb-4" style="border-left: 4px solid var(--bs-<?= $attendanceColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-calendar-check text-<?= $attendanceColor ?> me-1"></i>
                <strong>Παρουσίες Αποστολών <?= $currentYear ?></strong>
                <span class="text-muted ms-2 d-none d-md-inline">(στόχος: <?= $attendanceGoal ?>)</span>
            </div>
            <div>
                <span class="badge bg-<?= $attendanceColor ?> fs-6"><?= $missionAttendance ?> / <?= $attendanceGoal ?></span>
                <?php if ($missionAttendance >= $attendanceGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Πληροί την προϋπόθεση!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $attendanceColor ?><?= $missionAttendance < $attendanceGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $attendancePct ?>%"
                 aria-valuenow="<?= $missionAttendance ?>" aria-valuemin="0" aria-valuemax="<?= $attendanceGoal ?>">
                <?= $attendancePct ?>%
            </div>
        </div>
        <?php if ($missionAttendance < $attendanceGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <strong><?= $attendanceGoal - $missionAttendance ?></strong> παρουσίες για να παραμείνετε ενεργό μέλος το <?= $currentYear + 1 ?></small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Πληροίτε την προϋπόθεση παραμονής ενεργού μέλους για το <?= $currentYear + 1 ?></small>
        <?php endif; ?>
    </div>
</div>

<?php if (isTraineeRescuer()): ?>
<!-- Τ.Ε.Π. Hours Progress Bar (only for trainees) -->
<div class="card pp-card mb-4" style="border-left: 4px solid var(--bs-<?= $tepColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-hospital text-<?= $tepColor ?> me-1"></i>
                <strong>Ώρες Τ.Ε.Π.</strong>
                <span class="text-muted ms-2 d-none d-md-inline">(στόχος: <?= $tepGoal ?> ώρες)</span>
            </div>
            <div>
                <span class="badge bg-<?= $tepColor ?> fs-6"><?= round($tepHours, 1) ?> / <?= $tepGoal ?></span>
                <?php if ($tepHours >= $tepGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Πληροί την προϋπόθεση!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $tepColor ?><?= $tepHours < $tepGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $tepPct ?>%"
                 aria-valuenow="<?= round($tepHours, 1) ?>" aria-valuemin="0" aria-valuemax="<?= $tepGoal ?>">
                <?= $tepPct ?>%
            </div>
        </div>
        <?php if ($tepHours < $tepGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <strong><?= round($tepGoal - $tepHours, 1) ?></strong> ώρες Τ.Ε.Π. για ολοκλήρωση</small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Έχετε ολοκληρώσει τις απαιτούμενες ώρες Τ.Ε.Π.!</small>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (($user['volunteer_type'] ?? '') === VTYPE_RESCUER): ?>
<!-- Εκπαιδευτικές Αποστολές Progress Bar (only for rescuers) -->
<div class="card pp-card mb-4" style="border-left: 4px solid var(--bs-<?= $eduColor ?>)">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <i class="bi bi-mortarboard text-<?= $eduColor ?> me-1"></i>
                <strong>Εκπαιδευτικές Αποστολές <?= $currentYear ?></strong>
                <span class="text-muted ms-2 d-none d-md-inline">(απαιτούνται: <?= $eduGoal ?>)</span>
            </div>
            <div>
                <span class="badge bg-<?= $eduColor ?> fs-6"><?= $eduMissions ?> / <?= $eduGoal ?></span>
                <?php if ($eduMissions >= $eduGoal): ?>
                    <i class="bi bi-check-circle-fill text-success ms-1" title="Πληροίς την προϋπόθεση!"></i>
                <?php endif; ?>
            </div>
        </div>
        <div class="progress" style="height: 22px;">
            <div class="progress-bar bg-<?= $eduColor ?><?= $eduMissions < $eduGoal ? ' progress-bar-striped progress-bar-animated' : '' ?>"
                 role="progressbar" style="width: <?= $eduPct ?>%"
                 aria-valuenow="<?= $eduMissions ?>" aria-valuemin="0" aria-valuemax="<?= $eduGoal ?>">
                <?= $eduPct ?>%
            </div>
        </div>
        <?php if ($eduMissions < $eduGoal): ?>
            <small class="text-muted mt-1 d-block">Απομένουν <strong><?= $eduGoal - $eduMissions ?></strong> εκπαιδευτικές αποστολές από τις <?= $eduGoal ?> απαιτούμενες</small>
        <?php else: ?>
            <small class="text-success mt-1 d-block"><i class="bi bi-check-lg"></i> Πληροίτε την προϋπόθεση συμμετοχής σε <?= $eduGoal ?> εκπαιδευτικές αποστολές!</small>
        <?php endif; ?>
    </div>
</div>
<?php endif; // VTYPE_RESCUER ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card pp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#667eea,#764ba2)"><i class="bi bi-calendar2-check"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= $stats['total_shifts'] ?></div>
                    <small class="text-muted">Βάρδιες</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card pp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= number_format($stats['total_hours'], 1) ?></div>
                    <small class="text-muted">Ώρες</small>
                </div>
            </div>
        </div>
    </div>
    <?php if (getSetting('points_enabled', '1') === '1'): ?>
    <div class="col-6 col-md-3">
        <div class="card pp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="bi bi-star-fill"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= number_format($user['total_points']) ?></div>
                    <small class="text-muted">Πόντοι</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (getSetting('achievements_enabled', '1') === '1'): ?>
    <div class="col-6 col-md-3">
        <div class="card pp-stat-card">
            <div class="card-body d-flex align-items-center gap-3 py-3 px-3">
                <div class="stat-icon" style="background:linear-gradient(135deg,#ef4444,#dc2626)"><i class="bi bi-trophy"></i></div>
                <div>
                    <div class="stat-value text-dark"><?= $stats['achievements'] ?></div>
                    <small class="text-muted">Επιτεύγματα</small>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Exam Attempts History -->
<?php if (!empty($examAttempts)): ?>
<div class="card pp-card accent-warning mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-award text-warning me-2"></i>Ιστορικό Διαγωνισμάτων</h5>
        <a href="training-exams.php" class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-right"></i> Όλα τα Διαγωνίσματα
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive pp-mobile-table-wrap">
            <table class="table table-hover pp-mobile-table pp-exams-table table-mobile-opt-out">
                <thead>
                    <tr>
                        <th>Διαγώνισμα</th>
                        <th>Κατηγορία</th>
                        <th>Βαθμός</th>
                        <th>Ποσοστό</th>
                        <th>Αποτέλεσμα</th>
                        <th>Ημερομηνία</th>
                        <th>Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($examAttempts as $attempt): ?>
                        <?php 
                        $percentage = $attempt['total_questions'] > 0 
                            ? round(($attempt['score'] / $attempt['total_questions']) * 100, 1) 
                            : 0;
                        ?>
                        <tr>
                            <td><?= h($attempt['exam_title']) ?></td>
                            <td>
                                <span class="badge bg-warning">
                                    <?= h($attempt['category_icon']) ?> <?= h($attempt['category_name']) ?>
                                </span>
                            </td>
                            <td><strong><?= $attempt['score'] ?> / <?= $attempt['total_questions'] ?></strong></td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-<?= $percentage >= $attempt['passing_percentage'] ? 'success' : 'danger' ?>" 
                                         style="width: <?= $percentage ?>%">
                                        <?= $percentage ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($attempt['passed']): ?>
                                    <span class="badge bg-success">Επιτυχία</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Αποτυχία</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($attempt['completed_at']) ?>
                                <?php if ($attempt['time_taken_seconds']): ?>
                                    <?php $mins = floor($attempt['time_taken_seconds'] / 60); $secs = $attempt['time_taken_seconds'] % 60; ?>
                                    <br><small class="text-muted"><?= $mins ?>λ <?= $secs ?>δ</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="exam-results.php?attempt_id=<?= $attempt['id'] ?>" class="btn btn-sm btn-info">
                                    <i class="bi bi-eye"></i> Προβολή
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- My Certificates -->
<?php
$myCertificates = dbFetchAll(
    "SELECT vc.*, ct.name as type_name, ct.is_required
     FROM volunteer_certificates vc
     JOIN certificate_types ct ON vc.certificate_type_id = ct.id
     WHERE vc.user_id = ?
     ORDER BY ct.is_required DESC, ct.name",
    [$user['id']]
);
$myRequiredMissing = dbFetchAll(
    "SELECT ct.name FROM certificate_types ct
     WHERE ct.is_required = 1 AND ct.is_active = 1
       AND ct.id NOT IN (SELECT certificate_type_id FROM volunteer_certificates WHERE user_id = ?)
     ORDER BY ct.name",
    [$user['id']]
);
?>
<?php if (!empty($myCertificates) || !empty($myRequiredMissing)): ?>
<div class="card pp-card accent-success mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-award text-success me-2"></i>Τα Πιστοποιητικά μου</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($myCertificates)): ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
            <?php foreach ($myCertificates as $cert):
                $certBadge = '<span class="badge bg-secondary">Αόριστη</span>';
                $borderColor = '#6c757d';
                if ($cert['expiry_date']) {
                    $daysLeft = (int) ((strtotime($cert['expiry_date']) - time()) / 86400);
                    if ($daysLeft < 0) {
                        $certBadge = '<span class="badge bg-danger">Ληγμένο (' . abs($daysLeft) . ' ημ.)</span>';
                        $borderColor = '#dc3545';
                    } elseif ($daysLeft <= 30) {
                        $certBadge = '<span class="badge bg-warning text-dark">Λήγει σε ' . $daysLeft . ' ημ.</span>';
                        $borderColor = '#ffc107';
                    } else {
                        $certBadge = '<span class="badge bg-success">Ενεργό</span>';
                        $borderColor = '#198754';
                    }
                }
            ?>
            <div class="col">
                <div class="card h-100 shadow-sm" style="border-left: 4px solid <?= $borderColor ?>; border-radius: .6rem;">
                    <div class="card-body py-3 px-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="mb-0 fw-semibold">
                                <?php if ($cert['is_required']): ?>
                                    <i class="bi bi-exclamation-circle text-danger me-1" title="Υποχρεωτικό"></i>
                                <?php endif; ?>
                                <?= h($cert['type_name']) ?>
                            </h6>
                            <?= $certBadge ?>
                        </div>
                        <div class="small text-muted">
                            <div class="mb-1"><i class="bi bi-calendar-check me-1"></i>Έκδοση: <?= formatDate($cert['issue_date']) ?></div>
                            <div class="mb-1"><i class="bi bi-calendar-x me-1"></i>Λήξη: <?= $cert['expiry_date'] ? formatDate($cert['expiry_date']) : 'Αόριστη' ?></div>
                            <?php if (!empty($cert['issuing_body'])): ?>
                            <div><i class="bi bi-building me-1"></i><?= h($cert['issuing_body']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($myRequiredMissing)): ?>
        <div class="alert alert-warning <?= !empty($myCertificates) ? 'mt-3' : '' ?> mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>Ελλείποντα υποχρεωτικά:</strong>
            <?php foreach ($myRequiredMissing as $mr): ?>
                <span class="badge bg-danger ms-1"><?= h($mr['name']) ?></span>
            <?php endforeach; ?>
            <div class="form-text mt-1">Επικοινωνήστε με τη διοίκηση για την καταχώρησή τους.</div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; // !isExternalGuest() ?>

<!-- i18n scope: core account section resumes (translated via t() — hero above
     plus everything from here down: edit-profile form, booked equipment,
     password change, avatar upload). New user-facing strings in this stretch
     must use t() with both 'el' and 'en' entries in includes/lang/war-room.php,
     per the app's Action Room i18n convention. -->
<div class="row">
    <div class="col-lg-8">
        <!-- Profile Form -->
        <div class="card pp-card accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person-vcard text-primary me-2"></i><?= t('profile.section_title') ?></h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= t('profile.field_name') ?></label>
                            <input type="text" class="form-control" name="name" value="<?= h($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= t('profile.field_phone') ?></label>
                            <input type="tel" class="form-control" name="phone" value="<?= h($user['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= t('profile.field_blood_type') ?></label>
                            <select class="form-select" name="blood_type">
                                <option value="">-</option>
                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'] as $bt): ?>
                                    <option value="<?= $bt ?>" <?= ($profile['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= t('profile.field_bio') ?></label>
                        <textarea class="form-control" name="bio" rows="3"><?= h($profile['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><?= t('profile.field_address') ?></h6>

                    <div class="mb-3">
                        <label class="form-label"><?= t('profile.field_address') ?></label>
                        <input type="text" class="form-control" name="address" value="<?= h($profile['address'] ?? '') ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label"><?= t('profile.field_city') ?></label>
                            <input type="text" class="form-control" name="city" value="<?= h($profile['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= t('profile.field_postal_code') ?></label>
                            <input type="text" class="form-control" name="postal_code" value="<?= h($profile['postal_code'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><?= t('profile.section_emergency_contact') ?></h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= t('profile.field_contact_name') ?></label>
                            <input type="text" class="form-control" name="emergency_contact_name" value="<?= h($profile['emergency_contact_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= t('profile.field_phone') ?></label>
                            <input type="tel" class="form-control" name="emergency_contact_phone" value="<?= h($profile['emergency_contact_phone'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3"><?= t('profile.section_availability') ?></h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_weekdays" id="weekdays" 
                                       <?= ($profile['available_weekdays'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="weekdays"><?= t('profile.avail_weekdays') ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_weekends" id="weekends"
                                       <?= ($profile['available_weekends'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="weekends"><?= t('profile.avail_weekends') ?></label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_nights" id="nights"
                                       <?= ($profile['available_nights'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="nights"><?= t('profile.avail_nights') ?></label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_driving_license" id="license"
                                       <?= ($profile['has_driving_license'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="license"><?= t('profile.has_license') ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_first_aid" id="firstaid"
                                       <?= ($profile['has_first_aid'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="firstaid"><?= t('profile.has_first_aid') ?></label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= t('common.save') ?>
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($activeBookings)): ?>
        <!-- Χρεωμένα Υλικά -->
        <div class="card pp-card accent-primary mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-box-seam text-primary me-2"></i><?= t('profile.bookings_title') ?></h5>
                <span class="badge bg-primary rounded-pill"><?= count($activeBookings) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive pp-mobile-table-wrap">
                    <table class="table table-sm table-hover align-middle mb-0 pp-mobile-table pp-bookings-table table-mobile-opt-out">
                        <thead class="table-light">
                            <tr>
                                <th>Barcode</th>
                                <th><?= t('profile.bookings_col_item') ?></th>
                                <th><?= t('profile.bookings_col_checkout_date') ?></th>
                                <th><?= t('profile.bookings_col_expected_return') ?></th>
                                <th><?= t('profile.bookings_col_status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activeBookings as $bk):
                                $overdue = $bk['status'] === 'overdue';
                                $expectedReturn = $bk['expected_return_date'] ?? null;
                                $daysOverdue = $overdue ? (int)((time() - strtotime($bk['created_at'])) / 86400) : 0;
                            ?>
                            <tr class="<?= $overdue ? 'table-danger' : '' ?>">
                                <td><code class="text-primary"><?= h($bk['barcode']) ?></code></td>
                                <td>
                                    <a href="inventory-view.php?id=<?= $bk['item_id'] ?>" class="text-decoration-none fw-medium">
                                        <?= h($bk['item_name']) ?>
                                    </a>
                                </td>
                                <td><small><?= formatDate($bk['created_at']) ?></small></td>
                                <td>
                                    <?php if ($expectedReturn): ?>
                                        <small class="<?= $overdue ? 'text-danger fw-bold' : '' ?>">
                                            <?= formatDate($expectedReturn) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($overdue): ?>
                                        <span class="badge bg-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= t('profile.bookings_overdue') ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-primary"><?= t('profile.bookings_active') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Profile Photo -->
        <div class="card pp-card accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-camera text-primary me-2"></i><?= t('profile.photo_card_title') ?></h5>
            </div>
            <div class="card-body text-center">
                <?php if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/uploads/avatars/' . $user['profile_photo'])): ?>
                    <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($user['profile_photo']) ?>?t=<?= time() ?>"
                         class="rounded-circle mb-3" style="width:120px;height:120px;object-fit:cover;" alt="<?= t('profile.photo_card_title') ?>">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
                         style="width:120px;height:120px;font-size:3rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_photo">
                    <div class="mb-2">
                        <input type="file" class="form-control form-control-sm" name="photo" accept="image/*" required>
                        <div class="form-text"><?= t('profile.photo_help_text') ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-upload me-1"></i><?= t('profile.upload_btn') ?>
                    </button>
                </form>

                <?php if (!empty($user['profile_photo'])): ?>
                    <form method="post" class="mt-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_photo">
                        <input type="hidden" name="delete_photo" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                onclick="return confirm('<?= h(addslashes(t('profile.delete_photo_confirm'))) ?>')">
                            <i class="bi bi-trash me-1"></i><?= t('common.delete') ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card pp-card accent-danger mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-lock text-danger me-2"></i><?= t('profile.change_password_title') ?></h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="mb-3">
                        <label class="form-label"><?= t('profile.current_password_label') ?></label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('profile.new_password_label') ?></label>
                        <input type="password" class="form-control" name="new_password" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('profile.confirm_password_label') ?></label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-key me-1"></i><?= t('profile.change_password_title') ?>
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Role Badge -->
        <div class="card pp-card">
            <div class="card-body text-center py-4">
                <div class="mb-2" style="font-size:2.5rem;opacity:.7"><i class="bi bi-shield-check"></i></div>
                <?= roleBadge($user['role']) ?>
                <?= volunteerTypeBadge($user['volunteer_type'] ?? VTYPE_RESCUER) ?>
                <p class="text-muted mt-2 mb-0 small">
                    <?= t('profile.member_since_prefix', ['date' => formatDate($user['created_at'])]) ?>
                </p>
            </div>
        </div>

        <!-- User Manual -->
        <div class="card pp-card">
            <div class="card-body text-center py-4">
                <div class="mb-2" style="font-size:2.5rem;opacity:.7"><i class="bi bi-book"></i></div>
                <h6 class="fw-bold mb-3"><?= t('profile.manual_title') ?></h6>
                <?php if (isAdmin()): ?>
                    <a href="docs/manual-admin.html" target="_blank" class="btn btn-outline-primary w-100">
                        <i class="bi bi-book me-1"></i><?= t('profile.manual_admin') ?>
                    </a>
                <?php else: ?>
                    <a href="docs/manual-user.html" target="_blank" class="btn btn-outline-primary w-100">
                        <i class="bi bi-book me-1"></i><?= t('profile.manual_volunteer') ?>
                    </a>
                <?php endif; ?>
                <p class="text-muted mt-2 mb-0 small">
                    <i class="bi bi-printer me-1"></i><?= t('profile.manual_print_hint') ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
