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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-person me-2"></i>Το Προφίλ μου
    </h1>
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
    <div class="col-md-3">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['total_shifts'] ?></h3>
                <small class="text-muted">Βάρδιες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($stats['total_hours'], 1) ?></h3>
                <small class="text-muted">Ώρες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($user['total_points']) ?></h3>
                <small class="text-muted">Πόντοι</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card danger">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['achievements'] ?></h3>
                <small class="text-muted">Επιτεύγματα</small>
            </div>
        </div>
    </div>
</div>

<!-- Exam Attempts History -->
<?php if (!empty($examAttempts)): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-award me-1"></i>Ιστορικό Διαγωνισμάτων</h5>
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

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person me-1"></i>Στοιχεία Προφίλ</h5>
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
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-camera me-1"></i>Φωτογραφία Προφίλ</h5>
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
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-lock me-1"></i>Αλλαγή Κωδικού</h5>
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
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-2">Ο ρόλος σας</h6>
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
