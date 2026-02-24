<?php
/**
 * VolunteerOps - Mission Create/Edit Form
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = (int) get('id');
$isEdit = !empty($id);
$pageTitle = $isEdit ? 'Επεξεργασία Αποστολής' : 'Νέα Αποστολή';

$user = getCurrentUser();
$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
$missionTypes = dbFetchAll("SELECT id, name, color, icon FROM mission_types WHERE is_active = 1 ORDER BY sort_order");

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
        'mission_type_id' => (int)post('mission_type_id', 1),
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
                        title = ?, description = ?, mission_type_id = ?, department_id = ?,
                        location = ?, location_details = ?, latitude = ?, longitude = ?,
                        start_datetime = ?, end_datetime = ?, requirements = ?, notes = ?,
                        is_urgent = ?, status = ?, responsible_user_id = ?, updated_at = NOW()
                        WHERE id = ?";
                dbExecute($sql, [
                    $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                    $data['location'], $data['location_details'], $data['latitude'], $data['longitude'],
                    $data['start_datetime'], $data['end_datetime'], $data['requirements'], $data['notes'],
                    $data['is_urgent'], $data['status'], $data['responsible_user_id'], $id
                ]);
                
                logAudit('update', 'missions', $id, $mission, $data);
                setFlash('success', 'Η αποστολή ενημερώθηκε επιτυχώς.');
            } else {
                $sql = "INSERT INTO missions 
                        (title, description, mission_type_id, department_id, location, location_details, 
                         latitude, longitude, start_datetime, end_datetime, requirements, notes,
                         is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $newId = dbInsert($sql, [
                    $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
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
$startDateIso = '';
$endDateIso = '';
if ($mission) {
    $startDate = formatDateTime($mission['start_datetime'], 'd/m/Y H:i');
    $endDate   = formatDateTime($mission['end_datetime'],   'd/m/Y H:i');
    $startDateIso = date('Y-m-d\TH:i', strtotime($mission['start_datetime']));
    $endDateIso   = date('Y-m-d\TH:i', strtotime($mission['end_datetime']));
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
                            <label for="mission_type_id" class="form-label">Τύπος Αποστολής</label>
                            <select class="form-select" id="mission_type_id" name="mission_type_id">
                                <?php foreach ($missionTypes as $mt): ?>
                                    <option value="<?= $mt['id'] ?>" <?= ($mission['mission_type_id'] ?? post('mission_type_id', 1)) == $mt['id'] ? 'selected' : '' ?>>
                                        <?= h($mt['name']) ?>
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
                            <label class="form-label">Έναρξη *</label>
                            <div class="input-group">
                                <input type="hidden" id="start_datetime" name="start_datetime"
                                       value="<?= h($startDate ?: post('start_datetime')) ?>" required>
                                <input type="text" id="start_datetime_display" class="form-control bg-white"
                                       value="<?= h($startDate ?: post('start_datetime')) ?>"
                                       placeholder="ηη/μμ/εεεε ωω:λλ" readonly
                                       style="cursor:pointer;" onclick="openDateModal('start')" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="openDateModal('start')" tabindex="-1">
                                    <i class="bi bi-calendar3"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Λήξη *</label>
                            <div class="input-group">
                                <input type="hidden" id="end_datetime" name="end_datetime"
                                       value="<?= h($endDate ?: post('end_datetime')) ?>" required>
                                <input type="text" id="end_datetime_display" class="form-control bg-white"
                                       value="<?= h($endDate ?: post('end_datetime')) ?>"
                                       placeholder="ηη/μμ/εεεε ωω:λλ" readonly
                                       style="cursor:pointer;" onclick="openDateModal('end')" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="openDateModal('end')" tabindex="-1">
                                    <i class="bi bi-calendar3"></i>
                                </button>
                            </div>
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

<!-- Date/Time Picker Modal -->
<div class="modal fade" id="datePickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
            <!-- Gradient header -->
            <div class="modal-header border-0 text-white py-4 px-4"
                 style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center"
                         style="width:42px;height:42px;background:rgba(255,255,255,0.2);">
                        <i class="bi bi-calendar3 fs-5"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold" id="datePickerModalLabel">Επιλογή Ημερομηνίας &amp; Ώρας</h5>
                        <small class="opacity-75" id="datePickerModalSub">Κάντε κλικ για να επιλέξετε</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <!-- Body -->
            <div class="modal-body px-4 py-4" style="background:#f8f7ff;">
                <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                    <i class="bi bi-clock me-1"></i>Ημερομηνία &amp; Ώρα
                </label>
                <input type="datetime-local" id="modalDateInput"
                       class="form-control form-control-lg text-center fw-semibold"
                       step="900"
                       style="font-size:1.4rem; border:2px solid #e0ddff; border-radius:12px;
                              background:#fff; color:#4f46e5; padding:18px 16px;
                              box-shadow:0 2px 8px rgba(79,70,229,0.08);
                              transition: border-color .2s, box-shadow .2s;"
                       onfocus="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.15)'"
                       onblur="this.style.borderColor='#e0ddff';this.style.boxShadow='0 2px 8px rgba(79,70,229,0.08)'">

                <!-- Duration section — shown only for Έναρξη -->
                <div id="durationSection" style="display:none;">
                    <hr class="my-3" style="border-color:#e0ddff;">
                    <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                        <i class="bi bi-hourglass-split me-1"></i>Διάρκεια αποστολής
                        <span class="text-muted fw-normal text-lowercase">(προαιρετικά — συμπληρώνει αυτόματα τη Λήξη)</span>
                    </label>
                    <!-- Quick-select pills -->
                    <div class="d-flex flex-wrap gap-2 mb-3" id="durationPills">
                        <?php foreach ([1,2,3,4,6,8,12,24] as $h): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 dp-pill"
                                data-hours="<?= $h ?>">
                            <?= $h ?>ώρ
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Custom hours input -->
                    <div class="input-group input-group-sm" style="max-width:180px;">
                        <span class="input-group-text bg-white" style="border:2px solid #e0ddff; border-right:0; border-radius:10px 0 0 10px;">
                            <i class="bi bi-pencil text-muted"></i>
                        </span>
                        <input type="number" id="durationCustom" min="0.5" max="72" step="0.5"
                               class="form-control text-center fw-semibold"
                               style="border:2px solid #e0ddff; border-left:0; border-radius:0 10px 10px 0; color:#4f46e5;"
                               placeholder="π.χ. 4.5">
                        <span class="input-group-text bg-white ms-1 border-0 text-muted small">ώρες</span>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <button type="button" class="btn btn-sm btn-link text-danger text-decoration-none p-0" id="clearDateBtn">
                        <i class="bi bi-x-circle me-1"></i>Καθαρισμός επιλογής
                    </button>
                </div>
            </div>
            <!-- Footer -->
            <div class="modal-footer border-0 px-4 pb-4 pt-0 gap-2" style="background:#f8f7ff;">
                <button type="button" class="btn btn-outline-secondary flex-fill py-2 rounded-3" data-bs-dismiss="modal">
                    Ακύρωση
                </button>
                <button type="button" class="btn flex-fill py-2 rounded-3 fw-semibold text-white" id="confirmDateBtn"
                        style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border:none;">
                    <i class="bi bi-check-lg me-1"></i>Επιλογή
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Pre-fill ISO values from PHP for edit mode
const _isoStart = <?= json_encode($startDateIso) ?>;
const _isoEnd   = <?= json_encode($endDateIso) ?>;
if (_isoStart && !document.getElementById('start_datetime').value) {
    document.getElementById('start_datetime').value = '';
}

window._dpField = null;
window._dpModal = null;

function openDateModal(field) {
    window._dpField = field;
    const hiddenId = field + '_datetime';
    const currentVal = document.getElementById(hiddenId).value;
    const isoVal = dmyToIso(currentVal);
    document.getElementById('modalDateInput').value = isoVal || '';
    document.getElementById('datePickerModalLabel').textContent =
        field === 'start' ? 'Ημερομηνία Έναρξης' : 'Ημερομηνία Λήξης';
    document.getElementById('datePickerModalSub').textContent =
        field === 'start' ? 'Πότε ξεκινά η αποστολή;' : 'Πότε λήγει η αποστολή;';
    // Show/hide duration section
    const durSection = document.getElementById('durationSection');
    durSection.style.display = field === 'start' ? '' : 'none';
    // Reset duration selection
    document.getElementById('durationCustom').value = '';
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    if (!window._dpModal) window._dpModal = new bootstrap.Modal(document.getElementById('datePickerModal'));
    window._dpModal.show();
    document.getElementById('datePickerModal').addEventListener('shown.bs.modal', function focusIt() {
        document.getElementById('modalDateInput').focus();
        document.getElementById('datePickerModal').removeEventListener('shown.bs.modal', focusIt);
    });
}

// Duration pill click
document.getElementById('durationPills').addEventListener('click', function(e) {
    const btn = e.target.closest('.dp-pill');
    if (!btn) return;
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-primary', 'text-white');
    document.getElementById('durationCustom').value = btn.dataset.hours;
});

// Custom input clears pill selection
document.getElementById('durationCustom').addEventListener('input', function() {
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        b.classList.remove('btn-primary', 'text-white');
        b.classList.add('btn-outline-secondary');
    });
    // Re-highlight matching pill if value matches
    const v = parseFloat(this.value);
    document.querySelectorAll('.dp-pill').forEach(function(b) {
        if (parseFloat(b.dataset.hours) === v) {
            b.classList.remove('btn-outline-secondary');
            b.classList.add('btn-primary', 'text-white');
        }
    });
});

document.getElementById('confirmDateBtn').addEventListener('click', function() {
    const isoVal = document.getElementById('modalDateInput').value;
    if (!isoVal) { return; }
    const dmy = isoToDmy(isoVal);
    document.getElementById(window._dpField + '_datetime').value = dmy;
    document.getElementById(window._dpField + '_datetime_display').value = dmy;
    // Auto-fill end datetime if duration is set and we're setting start
    if (window._dpField === 'start') {
        const hours = parseFloat(document.getElementById('durationCustom').value);
        if (hours > 0) {
            const startMs = new Date(isoVal).getTime();
            const endDate = new Date(startMs + hours * 3600 * 1000);
            const pad = n => String(n).padStart(2, '0');
            const endIso = endDate.getFullYear() + '-' + pad(endDate.getMonth()+1) + '-' + pad(endDate.getDate()) + 'T' + pad(endDate.getHours()) + ':' + pad(endDate.getMinutes());
            const endDmy = isoToDmy(endIso);
            document.getElementById('end_datetime').value = endDmy;
            document.getElementById('end_datetime_display').value = endDmy;
        }
    }
    window._dpModal.hide();
});

document.getElementById('clearDateBtn').addEventListener('click', function() {
    document.getElementById('modalDateInput').value = '';
    document.getElementById(window._dpField + '_datetime').value = '';
    document.getElementById(window._dpField + '_datetime_display').value = '';
    window._dpModal.hide();
});

document.getElementById('modalDateInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('confirmDateBtn').click(); }
});

// Helpers: convert between d/m/Y H:i and Y-m-dTH:i
function dmyToIso(dmy) {
    if (!dmy) return '';
    // expected: dd/mm/yyyy hh:ii
    const m = dmy.match(/^(\d{2})\/(\d{2})\/(\d{4})\s(\d{2}):(\d{2})/);
    if (!m) return '';
    return m[3] + '-' + m[2] + '-' + m[1] + 'T' + m[4] + ':' + m[5];
}
function isoToDmy(iso) {
    if (!iso) return '';
    // expected: yyyy-mm-ddThh:ii
    const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (!m) return '';
    return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
}
</script>

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
