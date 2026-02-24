<?php
/**
 * VolunteerOps - Volunteer Form (Create/Edit)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = (int) get('id');
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

// Load extended profile (volunteer_profiles table)
$profile = $id ? dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$id]) : null;

// Get departments (only functional corps, not warehouse/branch departments)
$departments = dbFetchAll("SELECT id, name FROM departments WHERE (has_inventory = 0 OR has_inventory IS NULL) ORDER BY name");

// Get warehouses (departments with inventory)
$warehouses = dbFetchAll("SELECT id, name FROM departments WHERE has_inventory = 1 AND is_active = 1 ORDER BY name");

// Get volunteer positions
$volunteerPositions = dbFetchAll("SELECT id, name, color, icon FROM volunteer_positions WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $data = [
        'name' => post('name'),
        'email' => post('email'),
        'phone' => post('phone'),
        'role' => post('role', ROLE_VOLUNTEER),
        'department_id' => post('department_id') ?: null,
        'warehouse_id' => post('warehouse_id') ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'position_id' => post('position_id') ?: null,
        'id_card' => post('id_card') ?: null,
        'amka' => post('amka') ?: null,
        'driving_license' => post('driving_license') ?: null,
        'vehicle_plate' => post('vehicle_plate') ?: null,
        'pants_size' => post('pants_size') ?: null,
        'shirt_size' => post('shirt_size') ?: null,
        'blouse_size' => post('blouse_size') ?: null,
        'fleece_size' => post('fleece_size') ?: null,
        'registry_epidrasis' => post('registry_epidrasis') ?: null,
        'registry_ggpp' => post('registry_ggpp') ?: null,
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
        $volunteerType = post('volunteer_type', VTYPE_RESCUER);
        if (!in_array($volunteerType, [VTYPE_TRAINEE, VTYPE_RESCUER])) {
            $volunteerType = VTYPE_RESCUER;
        }
        
        $cohortYear = post('cohort_year') ? post('cohort_year') : null;
        
        if ($volunteer) {
            // Update
            dbExecute(
                "UPDATE users SET 
                 name = ?, email = ?, phone = ?, role = ?, department_id = ?, warehouse_id = ?, is_active = ?,
                 volunteer_type = ?, cohort_year = ?, position_id = ?,
                 id_card = ?, amka = ?, driving_license = ?, vehicle_plate = ?,
                 pants_size = ?, shirt_size = ?, blouse_size = ?, fleece_size = ?,
                 registry_epidrasis = ?, registry_ggpp = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['name'], $data['email'], $data['phone'],
                    $data['role'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $volunteerType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['pants_size'], $data['shirt_size'], $data['blouse_size'], $data['fleece_size'],
                    $data['registry_epidrasis'], $data['registry_ggpp'], $id
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
                 (name, email, password, phone, role, department_id, warehouse_id, is_active, volunteer_type, cohort_year, position_id,
                  id_card, amka, driving_license, vehicle_plate, pants_size, shirt_size, blouse_size, fleece_size,
                  registry_epidrasis, registry_ggpp, total_points, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())",
                [
                    $data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT),
                    $data['phone'], $data['role'], $data['department_id'], $data['warehouse_id'], $data['is_active'],
                    $volunteerType, $cohortYear, $data['position_id'],
                    $data['id_card'], $data['amka'], $data['driving_license'], $data['vehicle_plate'],
                    $data['pants_size'], $data['shirt_size'], $data['blouse_size'], $data['fleece_size'],
                    $data['registry_epidrasis'], $data['registry_ggpp']
                ]
            );
            logAudit('create', 'users', $id);
            setFlash('success', 'Ο εθελοντής δημιουργήθηκε.');
        }
        
        // Upsert volunteer_profiles (bio, address, emergency contact, availability, etc.)
        $profileFields = [
            'bio'                     => post('bio'),
            'address'                 => post('address'),
            'city'                    => post('city'),
            'postal_code'             => post('postal_code'),
            'emergency_contact_name'  => post('emergency_contact_name'),
            'emergency_contact_phone' => post('emergency_contact_phone'),
            'blood_type'              => post('blood_type'),
            'available_weekdays'      => isset($_POST['available_weekdays']) ? 1 : 0,
            'available_weekends'      => isset($_POST['available_weekends']) ? 1 : 0,
            'available_nights'        => isset($_POST['available_nights']) ? 1 : 0,
            'has_first_aid'           => isset($_POST['has_first_aid']) ? 1 : 0,
        ];
        $existingProfile = dbFetchOne("SELECT id FROM volunteer_profiles WHERE user_id = ?", [$id]);
        if ($existingProfile) {
            dbExecute(
                "UPDATE volunteer_profiles SET 
                 bio=?, address=?, city=?, postal_code=?,
                 emergency_contact_name=?, emergency_contact_phone=?, blood_type=?,
                 available_weekdays=?, available_weekends=?, available_nights=?, has_first_aid=?,
                 updated_at=NOW() WHERE user_id=?",
                array_merge(array_values($profileFields), [$id])
            );
        } else {
            dbInsert(
                "INSERT INTO volunteer_profiles 
                 (user_id, bio, address, city, postal_code,
                  emergency_contact_name, emergency_contact_phone, blood_type,
                  available_weekdays, available_weekends, available_nights, has_first_aid,
                  created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                array_merge([$id], array_values($profileFields))
            );
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
    'warehouse_id' => $currentUser['warehouse_id'] ?? null,
    'is_active' => 1,
    'volunteer_type' => VTYPE_RESCUER,
    'cohort_year' => null,
    'position_id' => null,
    'id_card' => '',
    'amka' => '',
    'driving_license' => '',
    'vehicle_plate' => '',
    'pants_size' => '',
    'shirt_size' => '',
    'blouse_size' => '',
    'fleece_size' => '',
    'registry_epidrasis' => '',
    'registry_ggpp' => '',
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
                            <option value="<?= $vt ?>" <?= ($form['volunteer_type'] ?? VTYPE_RESCUER) === $vt ? 'selected' : '' ?>>>
                                <?= (VOLUNTEER_TYPE_ICONS[$vt] ?? '') . ' ' . $vtLabel ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><i class="bi bi-person-badge me-1"></i>Θέση / Ρόλος στην Οργάνωση</label>
                    <select class="form-select" name="position_id">
                        <option value="">— Χωρίς θέση —</option>
                        <?php foreach ($volunteerPositions as $pos): ?>
                            <option value="<?= $pos['id'] ?>" <?= ($form['position_id'] ?? '') == $pos['id'] ? 'selected' : '' ?>>
                                <?= h($pos['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Οργανωτικός ρόλος εντός του σώματος. <a href="volunteer-positions.php" target="_blank">Διαχείριση θέσεων</a></small>
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
                    <label class="form-label"><i class="bi bi-shield me-1"></i>Σώμα</label>
                    <select class="form-select" name="department_id">
                        <option value="">- Χωρίς σώμα -</option>
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
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><i class="bi bi-geo-alt-fill me-1"></i>Παράρτημα / Πόλη</label>
                    <select class="form-select" name="warehouse_id">
                        <option value="">- Χωρίς παράρτημα -</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?= $wh['id'] ?>" <?= ($form['warehouse_id'] ?? '') == $wh['id'] ? 'selected' : '' ?>>
                                <?= h($wh['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Σε ποιο παράρτημα/πόλη ανήκει ο εθελοντής</small>
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
            <h5 class="mb-3"><i class="bi bi-card-heading me-2"></i>Προσωπικά Στοιχεία</h5>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Ταυτότητα</label>
                    <input type="text" class="form-control" name="id_card" value="<?= h($form['id_card'] ?? '') ?>" placeholder="Αριθμός Ταυτότητας">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Α.Μ.Κ.Α.</label>
                    <input type="text" class="form-control" name="amka" value="<?= h($form['amka'] ?? '') ?>" placeholder="11 ψηφία" maxlength="11">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Άδεια Οδήγησης</label>
                    <input type="text" class="form-control" name="driving_license" value="<?= h($form['driving_license'] ?? '') ?>" placeholder="Αριθμός Αδείας">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Αριθμός Κυκλοφορίας</label>
                    <input type="text" class="form-control" name="vehicle_plate" value="<?= h($form['vehicle_plate'] ?? '') ?>" placeholder="π.χ. ΑΒΓ-1234">
                </div>
            </div>
            
            <hr>
            <h5 class="mb-3"><i class="bi bi-person-badge me-2"></i>Μεγέθη Στολής</h5>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Παντελόνι</label>
                    <input type="text" class="form-control" name="pants_size" value="<?= h($form['pants_size'] ?? '') ?>" placeholder="π.χ. M, L, XL">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Χιτώνιο</label>
                    <input type="text" class="form-control" name="shirt_size" value="<?= h($form['shirt_size'] ?? '') ?>" placeholder="π.χ. M, L, XL">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Μπλούζα</label>
                    <input type="text" class="form-control" name="blouse_size" value="<?= h($form['blouse_size'] ?? '') ?>" placeholder="π.χ. M, L, XL">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Fleece</label>
                    <input type="text" class="form-control" name="fleece_size" value="<?= h($form['fleece_size'] ?? '') ?>" placeholder="π.χ. M, L, XL">
                </div>
            </div>
            
            <hr>
            <h5 class="mb-3"><i class="bi bi-journal-text me-2"></i>Μητρώα</h5>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Μητρώο ΕΠΙΔΡΑΣΙΣ</label>
                    <input type="text" class="form-control" name="registry_epidrasis" value="<?= h($form['registry_epidrasis'] ?? '') ?>" placeholder="Αριθμός Μητρώου">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Μητρώο Γ.Γ.Π.Π.</label>
                    <input type="text" class="form-control" name="registry_ggpp" value="<?= h($form['registry_ggpp'] ?? '') ?>" placeholder="Αριθμός Μητρώου">
                </div>
            </div>
            
            <hr>
            <h5 class="mb-3"><i class="bi bi-person-lines-fill me-2"></i>Προφίλ Εθελοντή</h5>

            <div class="mb-3">
                <label class="form-label">Σύντομο Βιογραφικό</label>
                <textarea class="form-control" name="bio" rows="3"><?= h($profile['bio'] ?? '') ?></textarea>
            </div>

            <div class="row">
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

            <h6 class="mb-2">Διεύθυνση</h6>
            <div class="mb-3">
                <label class="form-label">Οδός / Αριθμός</label>
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

            <h6 class="mb-2">Επαφή Έκτακτης Ανάγκης</h6>
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

            <h6 class="mb-2">Διαθεσιμότητα</h6>
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_weekdays" id="avail_weekdays"
                               <?= ($profile['available_weekdays'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_weekdays">Καθημερινές</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_weekends" id="avail_weekends"
                               <?= ($profile['available_weekends'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_weekends">Σαββατοκύριακα</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="available_nights" id="avail_nights"
                               <?= ($profile['available_nights'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="avail_nights">Νυχτερινές</label>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="has_first_aid" id="has_first_aid"
                           <?= ($profile['has_first_aid'] ?? 0) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="has_first_aid">Πιστοποίηση Πρώτων Βοηθειών</label>
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
