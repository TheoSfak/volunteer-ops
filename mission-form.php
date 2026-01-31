<?php
/**
 * VolunteerOps - Mission Create/Edit Form
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = get('id');
$isEdit = !empty($id);
$pageTitle = $isEdit ? 'Επεξεργασία Αποστολής' : 'Νέα Αποστολή';

$user = getCurrentUser();
$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get all active volunteers for responsible selection
$allVolunteers = dbFetchAll("SELECT id, name, role FROM users WHERE is_active = 1 AND role IN (?, ?, ?, ?) ORDER BY name", 
    [ROLE_VOLUNTEER, ROLE_SHIFT_LEADER, ROLE_DEPARTMENT_ADMIN, ROLE_SYSTEM_ADMIN]);

$mission = null;
if ($isEdit) {
    $mission = dbFetchOne("SELECT * FROM missions WHERE id = ?", [$id]);
    if (!$mission) {
        setFlash('error', 'Η αποστολή δεν βρέθηκε.');
        redirect('missions.php');
    }
    
    // Check permission
    if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $mission['department_id'] != $user['department_id']) {
        setFlash('error', 'Δεν έχετε δικαίωμα επεξεργασίας αυτής της αποστολής.');
        redirect('missions.php');
    }
}

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $data = [
        'title' => post('title'),
        'description' => post('description'),
        'type' => post('type'),
        'department_id' => post('department_id') ?: null,
        'location' => post('location'),
        'location_details' => post('location_details'),
        'latitude' => post('latitude') ?: null,
        'longitude' => post('longitude') ?: null,
        'start_datetime' => post('start_datetime'),
        'end_datetime' => post('end_datetime'),
        'requirements' => post('requirements'),
        'notes' => post('notes'),
        'is_urgent' => isset($_POST['is_urgent']) ? 1 : 0,
        'status' => post('status') ?: STATUS_DRAFT,
        'responsible_user_id' => post('responsible_user_id') ?: null,
    ];
    
    // Validation
    if (empty($data['title'])) $errors[] = 'Ο τίτλος είναι υποχρεωτικός.';
    if (empty($data['location'])) $errors[] = 'Η τοποθεσία είναι υποχρεωτική.';
    if (empty($data['start_datetime'])) $errors[] = 'Η ημερομηνία έναρξης είναι υποχρεωτική.';
    if (empty($data['end_datetime'])) $errors[] = 'Η ημερομηνία λήξης είναι υποχρεωτική.';
    
    // Convert date format
    if (!empty($data['start_datetime'])) {
        $data['start_datetime'] = DateTime::createFromFormat('d/m/Y H:i', $data['start_datetime'])->format('Y-m-d H:i:s');
    }
    if (!empty($data['end_datetime'])) {
        $data['end_datetime'] = DateTime::createFromFormat('d/m/Y H:i', $data['end_datetime'])->format('Y-m-d H:i:s');
    }
    
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $sql = "UPDATE missions SET 
                        title = ?, description = ?, type = ?, department_id = ?,
                        location = ?, location_details = ?, latitude = ?, longitude = ?,
                        start_datetime = ?, end_datetime = ?, requirements = ?, notes = ?,
                        is_urgent = ?, status = ?, responsible_user_id = ?, updated_at = NOW()
                        WHERE id = ?";
                dbExecute($sql, [
                    $data['title'], $data['description'], $data['type'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $id
                ]);
                
                logAudit('update', 'missions', $id, $mission, $data);
                setFlash('success', 'Η αποστολή ενημερώθηκε επιτυχώς.');
            } else {
                $sql = "INSERT INTO missions 
                        (title, description, type, department_id, location, location_details, 
                         latitude, longitude, start_datetime, end_datetime, requirements, notes,
                         is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $newId = dbInsert($sql, [
                    $data['title'], $data['description'], $data['type'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $user['id']
                ]);
                
                logAudit('create', 'missions', $newId, null, $data);
                setFlash('success', 'Η αποστολή δημιουργήθηκε επιτυχώς.');
                redirect('mission-view.php?id=' . $newId);
            }
            
            redirect('missions.php');
        } catch (Exception $e) {
            $errors[] = 'Σφάλμα αποθήκευσης: ' . $e->getMessage();
        }
    }
}

// Format dates for form
$startDate = '';
$endDate = '';
if ($mission) {
    $startDate = formatDateTime($mission['start_datetime'], 'd/m/Y H:i');
    $endDate = formatDateTime($mission['end_datetime'], 'd/m/Y H:i');
}

include __DIR__ . '/includes/header.php';
?>

<!-- Summernote CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-flag me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="missions.php" class="btn btn-outline-secondary">
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

<form method="post" action="">
    <?= csrfField() ?>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Βασικές Πληροφορίες</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">Τίτλος *</label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?= h($mission['title'] ?? post('title')) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Περιγραφή</label>
                        <textarea class="form-control summernote-basic" id="description" name="description"><?= h($mission['description'] ?? post('description')) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="type" class="form-label">Τύπος</label>
                            <select class="form-select" id="type" name="type">
                                <?php foreach ($GLOBALS['MISSION_TYPES'] as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($mission['type'] ?? post('type', 'VOLUNTEER')) === $key ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="department_id" class="form-label">Τμήμα</label>
                            <select class="form-select" id="department_id" name="department_id">
                                <option value="">-- Επιλέξτε --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>" <?= ($mission['department_id'] ?? post('department_id')) == $dept['id'] ? 'selected' : '' ?>>
                                        <?= h($dept['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Τοποθεσία & Χρόνος</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="location" class="form-label">Τοποθεσία *</label>
                        <input type="text" class="form-control" id="location" name="location" 
                               value="<?= h($mission['location'] ?? post('location')) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="location_details" class="form-label">Λεπτομέρειες Τοποθεσίας</label>
                        <input type="text" class="form-control" id="location_details" name="location_details" 
                               value="<?= h($mission['location_details'] ?? post('location_details')) ?>"
                               placeholder="π.χ. Είσοδος από την οδό...">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="latitude" class="form-label">Γεωγραφικό Πλάτος</label>
                            <input type="text" class="form-control" id="latitude" name="latitude" 
                                   value="<?= h($mission['latitude'] ?? post('latitude')) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="longitude" class="form-label">Γεωγραφικό Μήκος</label>
                            <input type="text" class="form-control" id="longitude" name="longitude" 
                                   value="<?= h($mission['longitude'] ?? post('longitude')) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_datetime" class="form-label">Έναρξη *</label>
                            <input type="text" class="form-control datetimepicker" id="start_datetime" name="start_datetime" 
                                   value="<?= h($startDate ?: post('start_datetime')) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_datetime" class="form-label">Λήξη *</label>
                            <input type="text" class="form-control datetimepicker" id="end_datetime" name="end_datetime" 
                                   value="<?= h($endDate ?: post('end_datetime')) ?>" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Επιπλέον Πληροφορίες</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="requirements" class="form-label">Απαιτήσεις</label>
                        <textarea class="form-control" id="requirements" name="requirements" rows="3"
                                  placeholder="π.χ. Απαιτείται ιατρική εκπαίδευση..."><?= h($mission['requirements'] ?? post('requirements')) ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?= h($mission['notes'] ?? post('notes')) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Κατάσταση</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="status" class="form-label">Κατάσταση</label>
                        <select class="form-select" id="status" name="status">
                            <?php 
                            $allowedStatuses = [STATUS_DRAFT, STATUS_OPEN];
                            if ($isEdit && in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED, STATUS_CANCELED])) {
                                $allowedStatuses[] = $mission['status'];
                            }
                            foreach ($allowedStatuses as $s): ?>
                                <option value="<?= $s ?>" <?= ($mission['status'] ?? post('status', STATUS_DRAFT)) === $s ? 'selected' : '' ?>>
                                    <?= h($GLOBALS['STATUS_LABELS'][$s]) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_urgent" name="is_urgent" 
                               <?= ($mission['is_urgent'] ?? post('is_urgent')) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_urgent">
                            <i class="bi bi-exclamation-triangle text-danger"></i> Επείγουσα Αποστολή
                        </label>
                    </div>
                    
                    <div class="mb-3">
                        <label for="responsible_user_id" class="form-label">Υπεύθυνος Αποστολής</label>
                        <select class="form-select" id="responsible_user_id" name="responsible_user_id">
                            <option value="">Χωρίς υπεύθυνο</option>
                            <?php foreach ($allVolunteers as $v): ?>
                                <option value="<?= $v['id'] ?>" <?= ($mission['responsible_user_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                                    <?= h($v['name']) ?> (<?= h($GLOBALS['ROLE_LABELS'][$v['role']]) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Επιλέξτε υπεύθυνο για την αποστολή</small>
                    </div>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-check-lg me-1"></i>
                    <?= $isEdit ? 'Αποθήκευση Αλλαγών' : 'Δημιουργία Αποστολής' ?>
                </button>
                <a href="missions.php" class="btn btn-outline-secondary">Ακύρωση</a>
            </div>
        </div>
    </div>
</form>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>
<script>
$(document).ready(function() {
    $('.summernote-basic').summernote({
        height: 200,
        lang: 'el-GR',
        toolbar: [
            ['style', ['bold', 'italic', 'underline']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link']],
            ['view', ['codeview']]
        ]
    });
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
