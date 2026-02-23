<?php
/**
 * VolunteerOps - Profile Page
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Το Προφίλ μου';
$user = getCurrentUser();

// Get volunteer profile
$profile = dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$user['id']]);

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
    'achievements' => dbFetchValue(
        "SELECT COUNT(*) FROM user_achievements WHERE user_id = ?",
        [$user['id']]
    ),
];

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

if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'update_profile':
            $name = post('name');
            $phone = post('phone');
            
            if (empty($name)) {
                $errors[] = 'Το όνομα είναι υποχρεωτικό.';
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
                $success = 'Το προφίλ ενημερώθηκε επιτυχώς.';
                
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
                $errors[] = 'Συμπληρώστε όλα τα πεδία.';
            } elseif ($newPass !== $confirmPass) {
                $errors[] = 'Οι νέοι κωδικοί δεν ταιριάζουν.';
            } elseif (strlen($newPass) < 6) {
                $errors[] = 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
            } else {
                $result = updatePassword($user['id'], $currentPass, $newPass);
                if ($result['success']) {
                    $success = 'Ο κωδικός άλλαξε επιτυχώς.';
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

        case 'update_photo':
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['photo'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowedMime)) {
                    $errors[] = 'Επιτρέπονται μόνο αρχεία εικόνας (JPG, PNG, GIF, WebP).';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Το αρχείο δεν μπορεί να είναι μεγαλύτερο από 5MB.';
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
                        $success = 'Η φωτογραφία προφίλ ενημερώθηκε.';
                    } else {
                        $errors[] = 'Αδυναμία επεξεργασίας της εικόνας.';
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
                $success = 'Η φωτογραφία διαγράφηκε.';
            }
            break;
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
@media (max-width: 768px) {
    .profile-hero { padding: 1rem; text-align: center; }
    .profile-hero .d-flex { flex-direction: column; gap: .75rem; }
    .pp-stat-card .card-body { padding: .65rem !important; }
    .pp-stat-card .stat-value { font-size: 1.15rem; }
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
            <h1 class="h4 mb-1 text-white fw-bold"><?= h($user['name']) ?></h1>
            <div style="opacity:.85">
                <i class="bi bi-envelope me-1"></i><?= h($user['email']) ?>
                <?php if ($user['phone']): ?>
                    <span class="ms-3"><i class="bi bi-telephone me-1"></i><?= h($user['phone']) ?></span>
                <?php endif; ?>
            </div>
            <div class="mt-1">
                <?= roleBadge($user['role']) ?>
                <?= volunteerTypeBadge($user['volunteer_type'] ?? VTYPE_VOLUNTEER) ?>
                <span class="badge bg-light text-dark ms-1" style="font-size:.72rem"><i class="bi bi-calendar3 me-1"></i>Μέλος από <?= formatDate($user['created_at']) ?></span>
            </div>
        </div>
    </div>
</div>

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
        <div class="table-responsive">
            <table class="table table-hover">
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
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Τύπος</th>
                        <th>Ημ. Έκδοσης</th>
                        <th>Ημ. Λήξης</th>
                        <th>Κατάσταση</th>
                        <th>Φορέας</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myCertificates as $cert):
                        $certBadge = '<span class="badge bg-secondary">Αόριστη</span>';
                        $rowClass = '';
                        if ($cert['expiry_date']) {
                            $daysLeft = (int) ((strtotime($cert['expiry_date']) - time()) / 86400);
                            if ($daysLeft < 0) {
                                $certBadge = '<span class="badge bg-danger">Ληγμένο (' . abs($daysLeft) . ' ημ.)</span>';
                                $rowClass = 'table-danger';
                            } elseif ($daysLeft <= 30) {
                                $certBadge = '<span class="badge bg-warning text-dark">Λήγει σε ' . $daysLeft . ' ημ.</span>';
                                $rowClass = 'table-warning';
                            } else {
                                $certBadge = '<span class="badge bg-success">Ενεργό</span>';
                            }
                        }
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="fw-semibold">
                            <?php if ($cert['is_required']): ?>
                                <i class="bi bi-exclamation-circle text-danger me-1" title="Υποχρεωτικό"></i>
                            <?php endif; ?>
                            <?= h($cert['type_name']) ?>
                        </td>
                        <td><?= formatDate($cert['issue_date']) ?></td>
                        <td><?= $cert['expiry_date'] ? formatDate($cert['expiry_date']) : '—' ?></td>
                        <td><?= $certBadge ?></td>
                        <td class="text-muted small"><?= h($cert['issuing_body'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Form -->
        <div class="card pp-card accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person-vcard text-primary me-2"></i>Στοιχεία Προφίλ</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ονοματεπώνυμο *</label>
                            <input type="text" class="form-control" name="name" value="<?= h($user['name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?= h($user['email']) ?>" disabled>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Τηλέφωνο</label>
                            <input type="tel" class="form-control" name="phone" value="<?= h($user['phone']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ομάδα Αίματος</label>
                            <select class="form-select" name="blood_type">
                                <option value="">-</option>
                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', '0+', '0-'] as $bt): ?>
                                    <option value="<?= $bt ?>" <?= ($profile['blood_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Σύντομο Βιογραφικό</label>
                        <textarea class="form-control" name="bio" rows="3"><?= h($profile['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">Διεύθυνση</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Διεύθυνση</label>
                        <input type="text" class="form-control" name="address" value="<?= h($profile['address'] ?? '') ?>">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Πόλη</label>
                            <input type="text" class="form-control" name="city" value="<?= h($profile['city'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Τ.Κ.</label>
                            <input type="text" class="form-control" name="postal_code" value="<?= h($profile['postal_code'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">Επαφή Έκτακτης Ανάγκης</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Όνομα</label>
                            <input type="text" class="form-control" name="emergency_contact_name" value="<?= h($profile['emergency_contact_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Τηλέφωνο</label>
                            <input type="tel" class="form-control" name="emergency_contact_phone" value="<?= h($profile['emergency_contact_phone'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr>
                    <h6 class="mb-3">Διαθεσιμότητα & Ικανότητες</h6>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_weekdays" id="weekdays" 
                                       <?= ($profile['available_weekdays'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="weekdays">Καθημερινές</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_weekends" id="weekends"
                                       <?= ($profile['available_weekends'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="weekends">Σαββατοκύριακα</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="available_nights" id="nights"
                                       <?= ($profile['available_nights'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="nights">Νυχτερινές</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_driving_license" id="license"
                                       <?= ($profile['has_driving_license'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="license">Έχω δίπλωμα οδήγησης</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_first_aid" id="firstaid"
                                       <?= ($profile['has_first_aid'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="firstaid">Πιστοποίηση Πρώτων Βοηθειών</label>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Profile Photo -->
        <div class="card pp-card accent-primary mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-camera text-primary me-2"></i>Φωτογραφία Προφίλ</h5>
            </div>
            <div class="card-body text-center">
                <?php if (!empty($user['profile_photo']) && file_exists(__DIR__ . '/uploads/avatars/' . $user['profile_photo'])): ?>
                    <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($user['profile_photo']) ?>?t=<?= time() ?>"
                         class="rounded-circle mb-3" style="width:120px;height:120px;object-fit:cover;" alt="Φωτογραφία Προφίλ">
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
                        <div class="form-text">JPG, PNG, GIF, WebP — μέγιστο 5MB</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-upload me-1"></i>Ανέβασμα
                    </button>
                </form>

                <?php if (!empty($user['profile_photo'])): ?>
                    <form method="post" class="mt-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_photo">
                        <input type="hidden" name="delete_photo" value="1">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                onclick="return confirm('Διαγραφή φωτογραφίας;')">
                            <i class="bi bi-trash me-1"></i>Διαγραφή
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card pp-card accent-danger mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-lock text-danger me-2"></i>Αλλαγή Κωδικού</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_password">
                    
                    <div class="mb-3">
                        <label class="form-label">Τρέχων Κωδικός</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Νέος Κωδικός</label>
                        <input type="password" class="form-control" name="new_password" minlength="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Επιβεβαίωση</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-key me-1"></i>Αλλαγή Κωδικού
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Role Badge -->
        <div class="card pp-card">
            <div class="card-body text-center py-4">
                <div class="mb-2" style="font-size:2.5rem;opacity:.7"><i class="bi bi-shield-check"></i></div>
                <?= roleBadge($user['role']) ?>
                <?= volunteerTypeBadge($user['volunteer_type'] ?? VTYPE_VOLUNTEER) ?>
                <p class="text-muted mt-2 mb-0 small">
                    Μέλος από <?= formatDate($user['created_at']) ?>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
