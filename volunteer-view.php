<?php
/**
 * VolunteerOps - Volunteer View
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$id = (int) get('id');
if (!$id) {
    redirect('volunteers.php');
}

$volunteer = dbFetchOne(
    "SELECT u.*, d.name as department_name, wh.name as warehouse_name,
            vp.name as position_name, vp.color as position_color, vp.icon as position_icon
     FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     LEFT JOIN departments wh ON u.warehouse_id = wh.id 
     LEFT JOIN volunteer_positions vp ON u.position_id = vp.id
     WHERE u.id = ?",
    [$id]
);

if (!$volunteer) {
    setFlash('error', 'Ο εθελοντής δεν βρέθηκε.');
    redirect('volunteers.php');
}

$pageTitle = $volunteer['name'];

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'delete_personal_data') {
        // GDPR-compliant personal data deletion
        // Only system admins can delete personal data
        if (!isSystemAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        
        // Anonymize user data
        $anonymizedEmail = 'deleted_' . $id . '_' . time() . '@deleted.local';
        dbExecute(
            "UPDATE users SET 
             name = ?, 
             email = ?, 
             phone = NULL, 
             is_active = 0,
             updated_at = NOW() 
             WHERE id = ?",
            ['[Διαγραμμένος Χρήστης]', $anonymizedEmail, $id]
        );
        
        // Delete volunteer profile
        dbExecute("DELETE FROM volunteer_profiles WHERE user_id = ?", [$id]);
        
        // Delete user skills
        dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$id]);
        
        // Delete user achievements
        dbExecute("DELETE FROM user_achievements WHERE user_id = ?", [$id]);
        
        // Delete notifications
        dbExecute("DELETE FROM notifications WHERE user_id = ?", [$id]);
        
        logAudit('delete_personal_data', 'users', $id, 'GDPR data deletion');
        setFlash('success', 'Τα προσωπικά δεδομένα διαγράφηκαν επιτυχώς.');
        redirect('volunteers.php');
    }

    if ($action === 'upload_document') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        if (empty($_FILES['doc_files']['name'][0])) {
            setFlash('error', 'Παρακαλώ επιλέξτε τουλάχιστον ένα αρχείο.');
            redirect('volunteer-view.php?id=' . $id . '#documents');
        }
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif',
                        'image/webp', 'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $allowedExt  = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx'];
        $destDir     = __DIR__ . '/uploads/volunteer-docs/';
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        $uploadedCount = 0;
        $errors        = [];
        $fileCount     = count($_FILES['doc_files']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if (empty($_FILES['doc_files']['tmp_name'][$i])) continue;
            $tmpName  = $_FILES['doc_files']['tmp_name'][$i];
            $origName = basename($_FILES['doc_files']['name'][$i]);
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mime     = $finfo->file($tmpName);
            if (!in_array($ext, $allowedExt) || !in_array($mime, $allowedMime)) {
                $errors[] = 'Μη επιτρεπτός τύπος: ' . $origName;
                continue;
            }
            $maxSize = 15 * 1024 * 1024; // 15MB
            if ($_FILES['doc_files']['size'][$i] > $maxSize) {
                $errors[] = 'Υπέρβαση μεγέθους (15MB): ' . $origName;
                continue;
            }
            $label      = pathinfo($origName, PATHINFO_FILENAME);
            $storedName = 'vdoc_' . $id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmpName, $destDir . $storedName)) {
                $errors[] = 'Αποτυχία αποθήκευσης: ' . $origName;
                continue;
            }
            dbInsert(
                "INSERT INTO volunteer_documents (user_id, label, original_name, stored_name, mime_type, file_size, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [$id, $label, $origName, $storedName, $mime, $_FILES['doc_files']['size'][$i], getCurrentUserId()]
            );
            logAudit('upload_document', 'volunteer_documents', $id, $origName);
            $uploadedCount++;
        }
        if ($uploadedCount > 0) {
            $msg = 'Ανέβηκαν επιτυχώς ' . $uploadedCount . ' αρχεία.';
            if (!empty($errors)) $msg .= ' Σφάλματα: ' . implode(', ', $errors);
            setFlash('success', $msg);
        } else {
            setFlash('error', 'Κανένα αρχείο δεν ανέβηκε.' . (!empty($errors) ? ' ' . implode(', ', $errors) : ''));
        }
        redirect('volunteer-view.php?id=' . $id . '#documents');
    }

    if ($action === 'delete_document') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        $docId = (int) post('doc_id');
        $doc   = dbFetchOne("SELECT * FROM volunteer_documents WHERE id = ? AND user_id = ?", [$docId, $id]);
        if ($doc) {
            $filePath = __DIR__ . '/uploads/volunteer-docs/' . $doc['stored_name'];
            if (file_exists($filePath)) unlink($filePath);
            dbExecute("DELETE FROM volunteer_documents WHERE id = ?", [$docId]);
            logAudit('delete_document', 'volunteer_documents', $docId, $doc['label']);
            setFlash('success', 'Το αρχείο διαγράφηκε.');
        }
        redirect('volunteer-view.php?id=' . $id . '#documents');
    }

    if ($action === 'update_skills') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$id]);
        if (!empty($_POST['skills'])) {
            foreach ($_POST['skills'] as $skillId => $level) {
                if (!empty($level)) {
                    dbInsert(
                        "INSERT INTO user_skills (user_id, skill_id, level) VALUES (?, ?, ?)",
                        [$id, (int)$skillId, $level]
                    );
                }
            }
        }
        logAudit('update_skills', 'users', $id);
        setFlash('success', 'Οι δεξιότητες ενημερώθηκαν.');
        redirect('volunteer-view.php?id=' . $id . '#skills');
    }

    if ($action === 'upload_photo') {
        if (!isAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowedMime)) {
                setFlash('error', 'Επιτρέπονται μόνο αρχεία εικόνας (JPG, PNG, GIF, WebP).');
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                setFlash('error', 'Το αρχείο δεν μπορεί να είναι μεγαλύτερο από 5MB.');
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
                    $cropSize = min($srcW, $srcH);
                    $cropX = (int)(($srcW - $cropSize) / 2);
                    $cropY = (int)(($srcH - $cropSize) / 2);
                    $dst = imagecreatetruecolor(250, 250);
                    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, 250, 250, $cropSize, $cropSize);
                    $filename = $id . '.jpg';
                    $savePath = __DIR__ . '/uploads/avatars/' . $filename;
                    $avatarDir = dirname($savePath);
                    if (!is_dir($avatarDir)) {
                        mkdir($avatarDir, 0755, true);
                    }
                    imagejpeg($dst, $savePath, 90);
                    imagedestroy($src);
                    imagedestroy($dst);
                    dbExecute("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?", [$filename, $id]);
                    logAudit('upload_photo', 'users', $id);
                    setFlash('success', 'Η φωτογραφία προφίλ ενημερώθηκε.');
                } else {
                    setFlash('error', 'Αδυναμία επεξεργασίας της εικόνας.');
                }
            }
        } elseif (post('delete_photo') === '1') {
            $oldFile = __DIR__ . '/uploads/avatars/' . $id . '.jpg';
            if (file_exists($oldFile)) unlink($oldFile);
            dbExecute("UPDATE users SET profile_photo = NULL, updated_at = NOW() WHERE id = ?", [$id]);
            logAudit('delete_photo', 'users', $id);
            setFlash('success', 'Η φωτογραφία διαγράφηκε.');
        }
        redirect('volunteer-view.php?id=' . $id);
    }
}

// Get profile
$profile = dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$id]);

// Get documents
$documents = dbFetchAll(
    "SELECT vd.*, u.name as uploader_name FROM volunteer_documents vd
     LEFT JOIN users u ON vd.uploaded_by = u.id
     WHERE vd.user_id = ? ORDER BY vd.created_at DESC",
    [$id]
);

// Get skills
$skills = dbFetchAll(
    "SELECT s.*, us.level FROM skills s 
     JOIN user_skills us ON s.id = us.skill_id 
     WHERE us.user_id = ?
     ORDER BY s.category, s.name",
    [$id]
);

// All available skills for the admin edit form
$allSkills = dbFetchAll("SELECT * FROM skills ORDER BY category, name");

// Build quick lookup: skill_id => level for current user
$userSkillMap = [];
foreach ($skills as $sk) {
    $userSkillMap[$sk['id']] = $sk['level'];
}

// Get achievements
$achievements = dbFetchAll(
    "SELECT a.*, ua.earned_at FROM achievements a
     JOIN user_achievements ua ON a.id = ua.achievement_id
     WHERE ua.user_id = ?
     ORDER BY ua.earned_at DESC",
    [$id]
);

// Get recent participations
$participations = dbFetchAll(
    "SELECT pr.*, s.start_time, s.end_time, m.title as mission_title
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.volunteer_id = ?
     ORDER BY s.start_time DESC
     LIMIT 20",
    [$id]
);

// Get stats
$stats = [
    'total_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = '" . PARTICIPATION_APPROVED . "'",
        [$id]
    ),
    'attended_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND attended = 1",
        [$id]
    ),
    'total_hours' => dbFetchValue(
        "SELECT COALESCE(SUM(actual_hours), 0) FROM participation_requests WHERE volunteer_id = ? AND attended = 1",
        [$id]
    ),
    'pending_requests' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = '" . PARTICIPATION_PENDING . "'",
        [$id]
    ),
];

// Points history
$pointsHistory = dbFetchAll(
    "SELECT vp.*, 
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN s.id ELSE NULL END as shift_id,
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN m.title ELSE NULL END as shift_title
     FROM volunteer_points vp
     LEFT JOIN shifts s ON vp.pointable_type = 'App\\\\Models\\\\Shift' AND vp.pointable_id = s.id
     LEFT JOIN missions m ON s.mission_id = m.id
     WHERE vp.user_id = ?
     ORDER BY vp.created_at DESC
     LIMIT 10",
    [$id]
);

// Get exam and quiz attempts
$examAttempts = dbFetchAll(
    "SELECT ea.*, te.title as exam_title, tc.name as category_name,
            te.questions_per_attempt as total_questions, te.passing_percentage,
            ROUND((ea.score / NULLIF(te.questions_per_attempt, 0) * 100), 2) as percentage
     FROM exam_attempts ea
     INNER JOIN training_exams te ON ea.exam_id = te.id
     INNER JOIN training_categories tc ON te.category_id = tc.id
     WHERE ea.user_id = ? AND ea.completed_at IS NOT NULL
     ORDER BY ea.completed_at DESC",
    [$id]
);

$quizAttempts = dbFetchAll(
    "SELECT qa.*, tq.title as quiz_title, tc.name as category_name,
            (SELECT COUNT(*) FROM training_quiz_questions tqqc WHERE tqqc.quiz_id = qa.quiz_id) as total_questions,
            ROUND((qa.score / NULLIF((SELECT COUNT(*) FROM training_quiz_questions tqqc2 WHERE tqqc2.quiz_id = qa.quiz_id), 0) * 100), 2) as percentage
     FROM quiz_attempts qa
     INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
     INNER JOIN training_categories tc ON tq.category_id = tc.id
     WHERE qa.user_id = ? AND qa.completed_at IS NOT NULL
     ORDER BY qa.completed_at DESC",
    [$id]
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><?= h($volunteer['name']) ?><?= volunteerTypeBadge($volunteer['volunteer_type'] ?? VTYPE_VOLUNTEER) ?>
            <?php if (!empty($volunteer['position_name'])): ?>
                <span class="badge bg-<?= h($volunteer['position_color'] ?? 'secondary') ?> ms-1">
                    <?php if ($volunteer['position_icon']): ?><i class="<?= h($volunteer['position_icon']) ?> me-1"></i><?php endif; ?>
                    <?= h($volunteer['position_name']) ?>
                </span>
            <?php endif; ?>
        </h1>
        <small class="text-muted"><?= h($volunteer['email']) ?></small>
    </div>
    <div>
        <a href="volunteer-form.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Επεξεργασία
        </a>
        <a href="volunteer-report.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1"></i>Αναφορά
        </a>        <?php if (isSystemAdmin()): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteDataModal">
                <i class="bi bi-shield-x me-1"></i>Διαγραφή Προσωπικών Δεδομένων
            </button>
        <?php endif; ?>        <a href="volunteers.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['total_shifts'] ?></h3>
                <small class="text-muted">Βάρδιες (εγκεκ.)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['attended_shifts'] ?></h3>
                <small class="text-muted">Παρουσίες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card info">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($stats['total_hours'], 1) ?></h3>
                <small class="text-muted">Ώρες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($volunteer['total_points']) ?></h3>
                <small class="text-muted">Πόντοι</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Info -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person me-1"></i>Στοιχεία Προφίλ</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Τηλέφωνο:</strong> <?= h($volunteer['phone'] ?: '-') ?></p>
                        <p><strong>Σώμα:</strong> <?= h($volunteer['department_name'] ?: '-') ?></p>
                        <p><strong>Παράρτημα:</strong> 
                            <?php if ($volunteer['warehouse_name']): ?>
                                <span class="badge bg-info"><i class="bi bi-geo-alt-fill me-1"></i><?= h($volunteer['warehouse_name']) ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </p>
                        <p><strong>Ρόλος:</strong> <?= roleBadge($volunteer['role']) ?></p>
                        <p><strong>Κατάσταση:</strong> 
                            <?php if ($volunteer['is_active']): ?>
                                <span class="badge bg-success">Ενεργός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργός</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Ταυτότητα:</strong> <?= h($volunteer['id_card'] ?: '-') ?></p>
                        <?php if (!empty($volunteer['position_name'])): ?>
                        <p><strong>Θέση / Ρόλος:</strong>
                            <span class="badge bg-<?= h($volunteer['position_color'] ?? 'secondary') ?>">
                                <?php if ($volunteer['position_icon']): ?><i class="<?= h($volunteer['position_icon']) ?> me-1"></i><?php endif; ?>
                                <?= h($volunteer['position_name']) ?>
                            </span>
                        </p>
                        <?php endif; ?>
                        <p><strong>Α.Μ.Κ.Α.:</strong> <?= h($volunteer['amka'] ?: '-') ?></p>
                        <p><strong>Άδεια Οδήγησης:</strong> <?= h($volunteer['driving_license'] ?: '-') ?></p>
                        <p><strong>Αρ. Κυκλοφορίας:</strong> <?= h($volunteer['vehicle_plate'] ?: '-') ?></p>
                    </div>
                </div>
                
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2"><i class="bi bi-person-badge me-1"></i>Μεγέθη Στολής</h6>
                        <p><strong>Παντελόνι:</strong> <?= h($volunteer['pants_size'] ?: '-') ?></p>
                        <p><strong>Χιτώνιο:</strong> <?= h($volunteer['shirt_size'] ?: '-') ?></p>
                        <p><strong>Μπλούζα:</strong> <?= h($volunteer['blouse_size'] ?: '-') ?></p>
                        <p><strong>Fleece:</strong> <?= h($volunteer['fleece_size'] ?: '-') ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-2"><i class="bi bi-journal-text me-1"></i>Μητρώα</h6>
                        <p><strong>Μητρώο ΕΠΙΔΡΑΣΙΣ:</strong> <?= h($volunteer['registry_epidrasis'] ?: '-') ?></p>
                        <p><strong>Μητρώο Γ.Γ.Π.Π.:</strong> <?= h($volunteer['registry_ggpp'] ?: '-') ?></p>
                    </div>
                </div>

                <?php if ($profile): ?>
                <hr>
                <h6 class="text-muted mb-3"><i class="bi bi-person-lines-fill me-1"></i>Προφίλ Εθελοντή</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Ομάδα Αίματος:</strong> <?= h(($profile['blood_type'] ?? '') ?: '-') ?></p>
                        <p><strong>Διεύθυνση:</strong>
                            <?php
                                $addrParts = array_filter([
                                    $profile['address'] ?? '',
                                    $profile['city'] ?? '',
                                    $profile['postal_code'] ?? '',
                                ]);
                                echo $addrParts ? h(implode(', ', $addrParts)) : '-';
                            ?>
                        </p>
                        <?php if (!empty($profile['bio'])): ?>
                        <p><strong>Βιογραφικό:</strong><br><?= nl2br(h($profile['bio'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Επαφή Έκτακτης Ανάγκης:</strong><br>
                            <?= h(($profile['emergency_contact_name'] ?? '') ?: '-') ?>
                            <?= !empty($profile['emergency_contact_phone']) ? ' (' . h($profile['emergency_contact_phone']) . ')' : '' ?>
                        </p>
                        <p><strong>Διαθεσιμότητα:</strong><br>
                            <?php if ($profile['available_weekdays'] ?? 0): ?><span class="badge bg-info me-1">Καθημερινές</span><?php endif; ?>
                            <?php if ($profile['available_weekends'] ?? 0): ?><span class="badge bg-info me-1">Σαββατοκύριακα</span><?php endif; ?>
                            <?php if ($profile['available_nights'] ?? 0): ?><span class="badge bg-secondary me-1">Νυχτερινές</span><?php endif; ?>
                            <?php if (!($profile['available_weekdays'] ?? 0) && !($profile['available_weekends'] ?? 0) && !($profile['available_nights'] ?? 0)): ?><span class="text-muted">-</span><?php endif; ?>
                        </p>
                        <p><strong>Πιστοποιήσεις:</strong><br>
                            <?php if ($profile['has_driving_license'] ?? 0): ?><span class="badge bg-secondary me-1">Δίπλωμα Οδήγησης</span><?php endif; ?>
                            <?php if ($profile['has_first_aid'] ?? 0): ?><span class="badge bg-success me-1">Πρώτες Βοήθειες</span><?php endif; ?>
                            <?php if (!($profile['has_driving_license'] ?? 0) && !($profile['has_first_aid'] ?? 0)): ?><span class="text-muted">-</span><?php endif; ?>
                        </p>
                    </div>
                </div>
                <?php else: ?>
                <hr>
                <h6 class="text-muted mb-3"><i class="bi bi-person-lines-fill me-1"></i>Προφίλ Εθελοντή</h6>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Ομάδα Αίματος:</strong> -</p>
                        <p><strong>Διεύθυνση:</strong> -</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Επαφή Έκτακτης Ανάγκης:</strong> -</p>
                        <p><strong>Διαθεσιμότητα:</strong> -</p>
                        <p><strong>Πιστοποιήσεις:</strong> -</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Participations -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-1"></i>Πρόσφατες Συμμετοχές</h5>
            </div>
            <div class="card-body">
                <?php if (empty($participations)): ?>
                    <p class="text-muted">Δεν υπάρχουν συμμετοχές.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Βάρδια</th>
                                    <th>Αποστολή</th>
                                    <th>Ημ/νία</th>
                                    <th>Κατάσταση</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participations as $p): ?>
                                    <tr>
                                        <td>
                                            <a href="shift-view.php?id=<?= $p['shift_id'] ?>">
                                                <?= h($p['mission_title']) ?> - <?= formatDateTime($p['start_time'], 'H:i') ?>
                                            </a>
                                        </td>
                                        <td><?= h($p['mission_title']) ?></td>
                                        <td><?= formatDate($p['start_time']) ?></td>
                                        <td>
                                            <?= statusBadge($p['status']) ?>
                                            <?php if ($p['attended']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Exam & Quiz History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-file-earmark-text me-1"></i>Ιστορικό Εξετάσεων & Κουίζ</h5>
            </div>
            <div class="card-body">
                <?php if (empty($examAttempts) && empty($quizAttempts)): ?>
                    <p class="text-muted">Δεν υπάρχουν εξετάσεις ή κουίζ.</p>
                <?php else: ?>
                    <?php if (!empty($examAttempts)): ?>
                        <h6 class="text-muted mb-2"><i class="bi bi-award"></i> Διαγωνίσματα</h6>
                        <div class="list-group list-group-flush mb-3">
                            <?php foreach ($examAttempts as $exam): ?>
                                <a href="exam-results.php?attempt_id=<?= $exam['id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?= h($exam['exam_title']) ?></h6>
                                            <small class="text-muted"><?= h($exam['category_name']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($exam['passed']): ?>
                                                <span class="badge bg-success">Επιτυχία</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Αποτυχία</span>
                                            <?php endif; ?>
                                            <div class="mt-1">
                                                <strong><?= $exam['percentage'] ?>%</strong>
                                                <small class="text-muted">(<?= $exam['score'] ?>/<?= $exam['total_questions'] ?>)</small>
                                            </div>
                                            <small class="text-muted d-block"><?= formatDateTime($exam['completed_at']) ?></small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($quizAttempts)): ?>
                        <h6 class="text-muted mb-2"><i class="bi bi-question-circle"></i> Κουίζ</h6>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($quizAttempts, 0, 5) as $quiz): ?>
                                <a href="quiz-results.php?attempt_id=<?= $quiz['id'] ?>" class="list-group-item list-group-item-action">
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?= h($quiz['quiz_title']) ?></h6>
                                            <small class="text-muted"><?= h($quiz['category_name']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="mt-1">
                                                <strong><?= $quiz['percentage'] ?>%</strong>
                                                <small class="text-muted">(<?= $quiz['score'] ?>/<?= $quiz['total_questions'] ?>)</small>
                                            </div>
                                            <small class="text-muted d-block"><?= formatDateTime($quiz['completed_at']) ?></small>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($quizAttempts) > 5): ?>
                                <div class="list-group-item text-center text-muted">
                                    +<?= count($quizAttempts) - 5 ?> ακόμα κουίζ
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
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
                <?php
                $volunteerPhoto = $volunteer['profile_photo'] ?? null;
                $photoExists = $volunteerPhoto && file_exists(__DIR__ . '/uploads/avatars/' . $volunteerPhoto);
                ?>
                <?php if ($photoExists): ?>
                    <img src="<?= BASE_URL ?>/uploads/avatars/<?= h($volunteerPhoto) ?>?t=<?= time() ?>"
                         class="rounded-circle mb-3" style="width:120px;height:120px;object-fit:cover;" alt="">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
                         style="width:120px;height:120px;font-size:3rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                <?php endif; ?>
                <h5 class="mb-1"><?= h($volunteer['name']) ?></h5>
                <small class="text-muted d-block mb-3"><?= h($volunteer['email']) ?></small>

                <?php if (isAdmin()): ?>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="upload_photo">
                        <div class="mb-2">
                            <input type="file" class="form-control form-control-sm" name="photo" accept="image/*" required>
                            <div class="form-text">JPG, PNG, GIF, WebP — μέγιστο 5MB</div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-upload me-1"></i>Ανέβασμα
                        </button>
                    </form>
                    <?php if ($photoExists): ?>
                        <form method="post" class="mt-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="upload_photo">
                            <input type="hidden" name="delete_photo" value="1">
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                    onclick="return confirm('Διαγραφή φωτογραφίας;')">
                                <i class="bi bi-trash me-1"></i>Διαγραφή
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Skills -->
        <div class="card mb-4" id="skills">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-tools me-1"></i>Δεξιότητες</h5>
                <?php if (isAdmin()): ?>
                <button class="btn btn-sm btn-outline-primary" type="button"
                        data-bs-toggle="collapse" data-bs-target="#skillsEditForm">
                    <i class="bi bi-pencil me-1"></i>Επεξεργασία
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <!-- Current skills display -->
                <div id="skillsDisplay">
                    <?php if (empty($skills)): ?>
                        <p class="text-muted mb-0">Δεν έχουν καταχωρηθεί δεξιότητες.</p>
                    <?php else: ?>
                        <?php
                        $currentCat = '';
                        foreach ($skills as $sk):
                            if ($sk['category'] !== $currentCat):
                                $currentCat = $sk['category'];
                        ?>
                            <strong class="text-muted small d-block mt-2"><?= h($currentCat) ?></strong>
                        <?php endif; ?>
                            <span class="badge bg-primary mb-1">
                                <?= h($sk['name']) ?>
                                <?php if ($sk['level']): ?>
                                    <span class="badge bg-light text-dark"><?= h($sk['level']) ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if (isAdmin()): ?>
                <!-- Edit form (collapsed by default) -->
                <div class="collapse mt-3" id="skillsEditForm">
                    <hr>
                    <form method="post" action="volunteer-view.php?id=<?= $id ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_skills">
                        <?php if (empty($allSkills)): ?>
                            <p class="text-muted">Δεν υπάρχουν διαθέσιμες δεξιότητες. <a href="skills.php">Διαχείριση δεξιοτήτων</a></p>
                        <?php else: ?>
                            <?php
                            $catGrouped = [];
                            foreach ($allSkills as $s) {
                                $catGrouped[$s['category'] ?: 'Γενικά'][] = $s;
                            }
                            ?>
                            <?php foreach ($catGrouped as $cat => $catSkills): ?>
                                <h6 class="text-muted mt-3 mb-2"><?= h($cat) ?></h6>
                                <div class="row">
                                    <?php foreach ($catSkills as $s): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <select class="form-select form-select-sm" name="skills[<?= $s['id'] ?>]">
                                                <option value="">— <?= h($s['name']) ?> —</option>
                                                <?php foreach (['Αρχάριος','Μέτριος','Προχωρημένος','Εμπειρογνώμων'] as $lvl): ?>
                                                    <option value="<?= $lvl ?>" <?= (($userSkillMap[$s['id']] ?? '') === $lvl) ? 'selected' : '' ?>>
                                                        <?= $lvl ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="bi bi-check-lg me-1"></i>Αποθήκευση Δεξιοτήτων
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        data-bs-toggle="collapse" data-bs-target="#skillsEditForm">Ακύρωση</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Achievements -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-trophy me-1"></i>Επιτεύγματα</h5>
            </div>
            <div class="card-body">
                <?php if (empty($achievements)): ?>
                    <p class="text-muted">Δεν υπάρχουν επιτεύγματα.</p>
                <?php else: ?>
                    <?php foreach ($achievements as $ach): ?>
                        <div class="d-flex align-items-center mb-2">
                            <span class="fs-4 me-2"><?= h($ach['icon'] ?: '🏆') ?></span>
                            <div>
                                <strong><?= h($ach['name']) ?></strong>
                                <br><small class="text-muted"><?= formatDate($ach['earned_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Documents -->
        <?php
            $docCount   = count($documents);
            $docsPreview = array_slice($documents, 0, 5);
        ?>
        <div class="card mb-4" id="documents">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-folder2-open me-1"></i>Αρχεία & Έγγραφα
                    <?php if ($docCount > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $docCount ?></span>
                    <?php endif; ?>
                </h5>
                <div class="d-flex gap-2">
                    <?php if ($docCount > 5): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#allDocsModal">
                        <i class="bi bi-list-ul me-1"></i>Όλα (<?= $docCount ?>)
                    </button>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                        <i class="bi bi-upload me-1"></i>Ανέβασμα
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($documents)): ?>
                    <p class="text-muted p-3 mb-0">Δεν υπάρχουν αρχεία.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($docsPreview as $doc): ?>
                            <?php
                                $isImage = str_starts_with($doc['mime_type'], 'image/');
                                $icon    = $isImage ? 'bi-file-image text-info' : 'bi-file-pdf text-danger';
                                if ($doc['mime_type'] === 'application/msword' || $doc['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                                    $icon = 'bi-file-word text-primary';
                                }
                                $sizeKb = round($doc['file_size'] / 1024);
                            ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="text-truncate me-2" style="max-width:220px">
                                        <i class="bi <?= $icon ?> me-1"></i>
                                        <a href="volunteer-doc-download.php?id=<?= $doc['id'] ?>&volunteer=<?= $id ?>" target="_blank" class="fw-semibold text-decoration-none">
                                            <?= h($doc['original_name']) ?>
                                        </a>
                                        <br>
                                        <small class="text-muted"><?= $sizeKb ?>KB &middot; <?= formatDate($doc['created_at']) ?></small>
                                    </div>
                                    <?php if (isAdmin()): ?>
                                    <form method="post" onsubmit="return confirm('Διαγραφή αρχείου;')" class="flex-shrink-0">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($docCount > 5): ?>
                    <div class="p-2 text-center border-top">
                        <button type="button" class="btn btn-sm btn-link text-secondary" data-bs-toggle="modal" data-bs-target="#allDocsModal">
                            <i class="bi bi-chevron-down me-1"></i>Εμφάνιση όλων των <?= $docCount ?> αρχείων
                        </button>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Points History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-star me-1"></i>Ιστορικό Πόντων</h5>
            </div>
            <div class="card-body">
                <?php if (empty($pointsHistory)): ?>
                    <p class="text-muted">Δεν υπάρχουν πόντοι.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($pointsHistory as $ph): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <strong class="text-success">+<?= $ph['points'] ?> πόντοι</strong>
                                <br><small class="text-muted">
                                    <?= h($ph['shift_title'] ?: $ph['description']) ?>
                                    <br><?= formatDate($ph['created_at']) ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="uploadDocModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Ανέβασμα Αρχείων</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="upload_document">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Αρχεία <span class="text-danger">*</span></label>
                        <input type="file" name="doc_files[]" id="docFilesInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" multiple required>
                        <div class="form-text">Επιτρεπτοί τύποι: PDF, JPG, PNG, DOC, DOCX. Μέγιστο μέγεθος ανά αρχείο: 15MB. Μπορείτε να επιλέξετε πολλά αρχεία ταυτόχρονα.</div>
                    </div>
                    <div id="selectedFilesList" class="d-none">
                        <label class="form-label fw-semibold text-muted small">Επιλεγμένα αρχεία:</label>
                        <ul class="list-unstyled small mb-0" id="fileNamesUl"></ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Ανέβασμα</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- All Documents Modal -->
<?php if (!empty($documents)): ?>
<div class="modal fade" id="allDocsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder2-open me-2"></i>Όλα τα Αρχεία <span class="badge bg-secondary"><?= $docCount ?></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Search -->
                <div class="p-3 border-bottom bg-light">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="docSearchInput" class="form-control" placeholder="Αναζήτηση αρχείου..." autocomplete="off">
                        <span class="input-group-text text-muted" id="docSearchCount"><?= $docCount ?> αρχεία</span>
                    </div>
                </div>
                <!-- File table -->
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="allDocsTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40%">Αρχείο</th>
                                <th>Μέγεθος</th>
                                <th>Ημερομηνία</th>
                                <th>Ανέβηκε από</th>
                                <?php if (isAdmin()): ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php
                                $isImage = str_starts_with($doc['mime_type'], 'image/');
                                $icon    = $isImage ? 'bi-file-image text-info' : 'bi-file-pdf text-danger';
                                if ($doc['mime_type'] === 'application/msword' || $doc['mime_type'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
                                    $icon = 'bi-file-word text-primary';
                                }
                                $sizeKb = round($doc['file_size'] / 1024);
                            ?>
                            <tr class="doc-row">
                                <td>
                                    <i class="bi <?= $icon ?> me-1"></i>
                                    <a href="volunteer-doc-download.php?id=<?= $doc['id'] ?>&volunteer=<?= $id ?>" target="_blank" class="fw-semibold text-decoration-none doc-name">
                                        <?= h($doc['original_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted small align-middle"><?= $sizeKb ?>KB</td>
                                <td class="text-muted small align-middle"><?= formatDate($doc['created_at']) ?></td>
                                <td class="text-muted small align-middle"><?= h($doc['uploader_name']) ?></td>
                                <?php if (isAdmin()): ?>
                                <td class="align-middle">
                                    <form method="post" onsubmit="return confirm('Διαγραφή αρχείου;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_document">
                                        <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="noDocsFound" class="d-none text-center text-muted py-4"><i class="bi bi-search me-1"></i>Δεν βρέθηκαν αρχεία.</div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted">Σύνολο: <?= $docCount ?> αρχεία</small>
                <?php if (isAdmin()): ?>
                <button type="button" class="btn btn-sm btn-success" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#uploadDocModal">
                    <i class="bi bi-upload me-1"></i>Ανέβασμα νέων
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Personal Data Modal -->
<div class="modal fade" id="deleteDataModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-shield-x me-2"></i>Διαγραφή Προσωπικών Δεδομένων (GDPR)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Προειδοποίηση:</strong> Αυτή η ενέργεια είναι μόνιμη και δεν μπορεί να αναιρεθεί!
                </div>
                <p><strong>Θα διαγραφούν τα ακόλουθα δεδομένα:</strong></p>
                <ul>
                    <li>Όνομα και στοιχεία επικοινωνίας (θα αντικατασταθούν με "[Διαγραμμένος Χρήστης]")</li>
                    <li>Προφίλ εθελοντή (βιογραφικό, διεύθυνση, επαφή έκτακτης ανάγκης)</li>
                    <li>Δεξιότητες και επιτεύγματα</li>
                    <li>Ειδοποιήσεις</li>
                </ul>
                <p class="text-muted"><small><i class="bi bi-info-circle me-1"></i>Οι συμμετοχές σε αποστολές και βάρδιες θα διατηρηθούν για στατιστικούς λόγους, αλλά θα συνδεθούν με ανωνυμοποιημένο χρήστη.</small></p>
                <p class="mb-0">Είστε σίγουροι ότι θέλετε να διαγράψετε τα προσωπικά δεδομένα του <strong><?= h($volunteer['name']) ?></strong>;</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Ακύρωση
                </button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_personal_data">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-shield-x me-1"></i>Οριστική Διαγραφή Δεδομένων
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // --- Upload preview ---
    const uploadInput = document.getElementById('docFilesInput');
    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            const list = document.getElementById('selectedFilesList');
            const ul   = document.getElementById('fileNamesUl');
            ul.innerHTML = '';
            if (this.files.length > 0) {
                Array.from(this.files).forEach(function (f) {
                    const li = document.createElement('li');
                    li.innerHTML = '<i class="bi bi-file-earmark me-1"></i>' + f.name +
                        ' <span class="text-muted">(' + (f.size > 1048576
                            ? (f.size / 1048576).toFixed(1) + ' MB'
                            : Math.round(f.size / 1024) + ' KB') + ')</span>';
                    ul.appendChild(li);
                });
                list.classList.remove('d-none');
            } else {
                list.classList.add('d-none');
            }
        });
    }

    // --- All-docs modal live search ---
    const searchInput = document.getElementById('docSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q       = this.value.trim().toLowerCase();
            const rows    = document.querySelectorAll('#allDocsTable .doc-row');
            const counter = document.getElementById('docSearchCount');
            const noFound = document.getElementById('noDocsFound');
            let visible   = 0;
            rows.forEach(function (row) {
                const name = row.querySelector('.doc-name').textContent.trim().toLowerCase();
                if (!q || name.includes(q)) {
                    row.classList.remove('d-none');
                    visible++;
                } else {
                    row.classList.add('d-none');
                }
            });
            counter.textContent = visible + ' αρχεία';
            noFound.classList.toggle('d-none', visible > 0);
            document.querySelector('#allDocsTable').classList.toggle('d-none', visible === 0);
        });

        // Reset search when modal closes
        const allDocsModal = document.getElementById('allDocsModal');
        if (allDocsModal) {
            allDocsModal.addEventListener('hidden.bs.modal', function () {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            });
        }
    }

});
</script>
