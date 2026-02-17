<?php
/**
 * VolunteerOps - Volunteer Form (Create/Edit)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = get('id');
$volunteer = null;
$pageTitle = 'Νέος Εθελοντής';
$currentUser = getCurrentUser();

if ($id) {
    $volunteer = dbFetchOne("SELECT * FROM users WHERE id = ?", [$id]);
    if (!$volunteer) {
        setFlash('error', 'Ο εθελοντής δεν βρέθηκε.');
        redirect('volunteers.php');
    }
    $pageTitle = 'Επεξεργασία: ' . $volunteer['name'];
}

// Get departments
$departments = dbFetchAll("SELECT id, name FROM departments ORDER BY name");

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $data = [
        'name' => post('name'),
        'email' => post('email'),
        'phone' => post('phone'),
        'role' => post('role', ROLE_VOLUNTEER),
        'department_id' => post('department_id') ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    
    // Validation
    if (empty($data['name'])) {
        $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    }
    if (empty($data['email'])) {
        $errors[] = 'Το email είναι υποχρεωτικό.';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Μη έγκυρο email.';
    } else {
        // Check unique email
        $existingEmail = dbFetchOne(
            "SELECT id FROM users WHERE email = ? AND id != ?",
            [$data['email'], $id ?: 0]
        );
        if ($existingEmail) {
            $errors[] = 'Το email χρησιμοποιείται ήδη.';
        }
    }
    
    // Only system admin can create other system admins
    if ($data['role'] === ROLE_SYSTEM_ADMIN && $currentUser['role'] !== ROLE_SYSTEM_ADMIN) {
        $errors[] = 'Δεν έχετε δικαίωμα για αυτόν τον ρόλο.';
    }
    
    // Dept admin can only assign their department
    if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && $data['department_id'] != $currentUser['department_id']) {
        $data['department_id'] = $currentUser['department_id'];
    }
    
    // Password for new users
    $password = post('password');
    if (!$volunteer && empty($password)) {
        $errors[] = 'Ο κωδικός είναι υποχρεωτικός για νέους χρήστες.';
    } elseif (!$volunteer && strlen($password) < 6) {
        $errors[] = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
    }
    
    if (empty($errors)) {
        try {
        $volunteerType = post('volunteer_type', VTYPE_VOLUNTEER);
        if (!in_array($volunteerType, [VTYPE_VOLUNTEER, VTYPE_TRAINEE, VTYPE_RESCUER])) {
            $volunteerType = VTYPE_VOLUNTEER;
        }
        
        $cohortYear = post('cohort_year') ? post('cohort_year') : null;
        
        if ($volunteer) {
            // Update
            dbExecute(
                "UPDATE users SET 
                 name = ?, email = ?, phone = ?, role = ?, department_id = ?, is_active = ?,
                 volunteer_type = ?, cohort_year = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['name'], $data['email'], $data['phone'],
                    $data['role'], $data['department_id'], $data['is_active'],
                    $volunteerType, $cohortYear, $id
                ]
            );
            
            // Update password if provided
            if (!empty($password)) {
                dbExecute(
                    "UPDATE users SET password = ? WHERE id = ?",
                    [password_hash($password, PASSWORD_DEFAULT), $id]
                );
            }
            
            logAudit('update', 'users', $id);
            setFlash('success', 'Ο εθελοντής ενημερώθηκε.');
        } else {
            // Create
            $id = dbInsert(
                "INSERT INTO users 
                 (name, email, password, phone, role, department_id, is_active, volunteer_type, cohort_year, total_points, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())",
                [
                    $data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT),
                    $data['phone'], $data['role'], $data['department_id'], $data['is_active'],
                    $volunteerType, $cohortYear
                ]
            );
            logAudit('create', 'users', $id);
            setFlash('success', 'Ο εθελοντής δημιουργήθηκε.');
        }
        redirect('volunteer-view.php?id=' . $id);
        } catch (Exception $e) {
            error_log('Volunteer form error: ' . $e->getMessage());
            setFlash('error', 'Παρουσιάστηκε σφάλμα κατά την αποθήκευση. Παρακαλώ δοκιμάστε ξανά.');
            redirect('volunteers.php');
        }
    }
}

// Form values
$form = $volunteer ?: [
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => ROLE_VOLUNTEER,
    'department_id' => $currentUser['department_id'],
    'is_active' => 1,
    'volunteer_type' => VTYPE_VOLUNTEER,
    'cohort_year' => null,
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= h($pageTitle) ?></h1>
    <a href="volunteers.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
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

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ονοματεπώνυμο *</label>
                    <input type="text" class="form-control" name="name" value="<?= h($form['name']) ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email *</label>
                    <input type="email" class="form-control" name="email" value="<?= h($form['email']) ?>" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τηλέφωνο</label>
                    <input type="tel" class="form-control" name="phone" value="<?= h($form['phone']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Κωδικός <?= $volunteer ? '(αφήστε κενό για να διατηρηθεί)' : '*' ?></label>
                    <input type="password" class="form-control" name="password" minlength="6" <?= $volunteer ? '' : 'required' ?>>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ρόλος *</label>
                    <select class="form-select" name="role" required>
                        <?php foreach (ROLE_LABELS as $r => $label): ?>
                            <?php 
                            // Dept admins can't create system admins
                            if ($r === ROLE_SYSTEM_ADMIN && $currentUser['role'] !== ROLE_SYSTEM_ADMIN) continue;
                            ?>
                            <option value="<?= $r ?>" <?= $form['role'] === $r ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τύπος Εθελοντή</label>
                    <select class="form-select" name="volunteer_type">
                        <?php foreach (VOLUNTEER_TYPE_LABELS as $vt => $vtLabel): ?>
                            <option value="<?= $vt ?>" <?= ($form['volunteer_type'] ?? VTYPE_VOLUNTEER) === $vt ? 'selected' : '' ?>>
                                <?= (VOLUNTEER_TYPE_ICONS[$vt] ?? '') . ' ' . $vtLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Χρονιά Σειράς</label>
                    <input type="number" class="form-control" name="cohort_year" 
                           value="<?= h($form['cohort_year'] ?? '') ?>" 
                           placeholder="π.χ. 2026" min="2020" max="2099"
                           title="Χρονιά σειράς δοκίμων (προαιρετικό)">
                    <small class="text-muted">Για δόκιμους διασώστες - χρησιμοποιείται για στατιστικά ανά χρονιά</small>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Τμήμα</label>
                    <select class="form-select" name="department_id">
                        <option value="">- Χωρίς τμήμα -</option>
                        <?php foreach ($departments as $d): ?>
                            <?php 
                            // Dept admins see only their department
                            if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && $d['id'] != $currentUser['department_id']) continue;
                            ?>
                            <option value="<?= $d['id'] ?>" <?= $form['department_id'] == $d['id'] ? 'selected' : '' ?>>
                                <?= h($d['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                           <?= $form['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active">
                        Ενεργός λογαριασμός
                    </label>
                </div>
            </div>
            
            <hr>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Αποθήκευση
            </button>
            <a href="volunteers.php" class="btn btn-outline-secondary">Ακύρωση</a>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
