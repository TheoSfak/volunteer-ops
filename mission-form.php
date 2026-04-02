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

    // Recurring fields (create-only)
    $isRecurring = !$isEdit && isset($_POST['is_recurring']);
    $recurType    = post('recur_type');      // 'weekly' | 'random_days' | 'interval'
    $recurEndDate = post('recur_end_date');  // Y-m-d
    if ($isRecurring) {
        if (!in_array($recurType, ['weekly', 'random_days', 'interval'])) {
            $errors[] = 'Επιλέξτε έγκυρο τύπο επανάληψης.';
        }
        if (empty($recurEndDate)) {
            $errors[] = 'Η ημερομηνία λήξης σειράς είναι υποχρεωτική.';
        }
    }
    
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
                if ($isRecurring) {
                    // ── RECURRING MISSIONS ──────────────────────────────────────────
                    $startDT       = new DateTime($data['start_datetime']);
                    $endDT         = new DateTime($data['end_datetime']);
                    $startDateOnly = new DateTime($startDT->format('Y-m-d'));
                    $endDateSeries = new DateTime($recurEndDate);
                    $startTime     = $startDT->format('H:i:s');
                    $endTime       = $endDT->format('H:i:s');
                    $recurDates    = [];
                    $meta          = [];

                    if ($recurType === 'weekly') {
                        $rawWeekdays = array_unique(array_map('intval', array_filter(
                            (array)($_POST['recur_weekdays'] ?? []),
                            fn($d) => $d >= 1 && $d <= 7
                        )));
                        if (empty($rawWeekdays)) {
                            throw new Exception('Επιλέξτε τουλάχιστον μία ημέρα εβδομάδας.');
                        }
                        $cursor = clone $startDateOnly;
                        while ($cursor <= $endDateSeries) {
                            if (in_array((int)$cursor->format('N'), $rawWeekdays)) {
                                $recurDates[] = $cursor->format('Y-m-d');
                            }
                            $cursor->modify('+1 day');
                        }
                        $meta = ['weekdays' => array_values($rawWeekdays)];

                    } elseif ($recurType === 'random_days') {
                        $rawDates = post('recur_random_dates');
                        foreach (array_filter(array_map('trim', explode(',', $rawDates))) as $d) {
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                                $dt = new DateTime($d);
                                if ($dt >= $startDateOnly && $dt <= $endDateSeries) {
                                    $recurDates[] = $dt->format('Y-m-d');
                                }
                            }
                        }
                        $recurDates = array_values(array_unique($recurDates));
                        sort($recurDates);
                        if (empty($recurDates)) {
                            throw new Exception('Επιλέξτε τουλάχιστον μία ημερομηνία στο καθορισμένο εύρος.');
                        }
                        $meta = ['random_dates' => $recurDates];

                    } else { // interval
                        $intervalDays = max(1, min(6, (int)post('recur_interval_days', 1)));
                        $cursor = clone $startDateOnly;
                        while ($cursor <= $endDateSeries) {
                            $recurDates[] = $cursor->format('Y-m-d');
                            $cursor->modify('+' . $intervalDays . ' days');
                        }
                        $meta = [
                            'interval_days'       => $intervalDays,
                            'interval_start_date' => $startDateOnly->format('Y-m-d'),
                        ];
                    }

                    if (count($recurDates) > 100) {
                        throw new Exception('Υπέρβαση ορίου 100 αποστολών ανά σειρά (βρέθηκαν ' . count($recurDates) . '). Μειώστε το εύρος ημερομηνιών.');
                    }
                    if (empty($recurDates)) {
                        throw new Exception('Δεν βρέθηκαν ημερομηνίες στο εύρος που ορίσατε.');
                    }

                    // Insert recurrence record
                    $recurrenceId = dbInsert(
                        "INSERT INTO mission_recurrences (type, weekdays, random_dates, interval_days, interval_start_date, end_date, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [
                            $recurType,
                            isset($meta['weekdays'])      ? json_encode($meta['weekdays'])      : null,
                            isset($meta['random_dates'])  ? json_encode($meta['random_dates'])  : null,
                            isset($meta['interval_days']) ? $meta['interval_days']              : null,
                            isset($meta['interval_start_date']) ? $meta['interval_start_date']  : null,
                            $recurEndDate,
                            $user['id'],
                        ]
                    );

                    // Insert one mission + one default shift per date
                    $createdCount = 0;
                    foreach ($recurDates as $instanceDate) {
                        $instStart = $instanceDate . ' ' . $startTime;
                        $instEnd   = $instanceDate . ' ' . $endTime;
                        $missionId = dbInsert(
                            "INSERT INTO missions
                             (title, description, mission_type_id, department_id, location, location_details,
                              latitude, longitude, start_datetime, end_datetime, requirements, notes,
                              is_urgent, status, responsible_user_id, created_by, recurrence_id, recurrence_instance_date, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                            [
                                $data['title'], $data['description'], $data['mission_type_id'], $data['department_id'],
                                $data['location'], $data['location_details'], $data['latitude'], $data['longitude'],
                                $instStart, $instEnd, $data['requirements'], $data['notes'],
                                $data['is_urgent'], STATUS_OPEN, $data['responsible_user_id'], $user['id'],
                                $recurrenceId, $instanceDate,
                            ]
                        );
                        dbInsert(
                            "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                             VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                            [$missionId, $instStart, $instEnd]
                        );
                        logAudit('create', 'missions', $missionId, null, [
                            'title'        => $data['title'],
                            'recurrence_id' => $recurrenceId,
                        ]);
                        $createdCount++;
                    }

                    $firstDate = formatDateGreek($recurDates[0]);
                    $lastDate  = formatDateGreek(end($recurDates));
                    setFlash('success', 'Δημιουργήθηκαν ' . $createdCount . ' αποστολές σε νέα σειρά (' . $firstDate . ' – ' . $lastDate . ').');
                    redirect('missions.php');

                } else {
                    // ── SINGLE MISSION ───────────────────────────────────────────────
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

            <?php if (!$isEdit): ?>
            <div class="card mb-4 border-info" id="recurringCard">
                <div class="card-header bg-info bg-opacity-10">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring"
                               onchange="toggleRecurring(this.checked)">
                        <label class="form-check-label fw-semibold" for="is_recurring">
                            <i class="bi bi-arrow-repeat me-1 text-info"></i>Επαναλαμβανόμενη Αποστολή
                        </label>
                        <small class="text-muted d-block ms-4">Δημιουργεί αυτόματα πολλές αποστολές με βάση χρονοδιάγραμμα</small>
                    </div>
                </div>
                <div class="card-body" id="recurringBody" style="display:none;">

                    <!-- Type selector -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Τύπος Επανάληψης</label>
                        <div class="d-flex flex-wrap gap-2">
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeWeekly"
                                       value="weekly" checked onchange="switchRecurType('weekly')">
                                <label class="form-check-label" for="recurTypeWeekly">
                                    <i class="bi bi-calendar-week me-1"></i>Εβδομαδιαία
                                </label>
                            </div>
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeRandom"
                                       value="random_days" onchange="switchRecurType('random_days')">
                                <label class="form-check-label" for="recurTypeRandom">
                                    <i class="bi bi-calendar3 me-1"></i>Επιλεγμένες ημερομηνίες
                                </label>
                            </div>
                            <div class="form-check form-check-inline border rounded px-3 py-2">
                                <input class="form-check-input" type="radio" name="recur_type" id="recurTypeInterval"
                                       value="interval" onchange="switchRecurType('interval')">
                                <label class="form-check-label" for="recurTypeInterval">
                                    <i class="bi bi-arrow-right-circle me-1"></i>Κάθε N ημέρες
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Weekly panel -->
                    <div id="recurPanelWeekly" class="mb-3">
                        <label class="form-label fw-semibold">Ημέρες Εβδομάδας</label>
                        <div class="d-flex flex-wrap gap-2" id="weekdayChips">
                            <?php
                            $greekDays = [1=>'Δευτ',2=>'Τρίτ',3=>'Τετ',4=>'Πέμπ',5=>'Παρ',6=>'Σάββ',7=>'Κυρ'];
                            foreach ($greekDays as $iso => $label): ?>
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3 wday-chip"
                                    data-day="<?= $iso ?>" onclick="toggleWeekday(this)">
                                <?= $label ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div id="weekdayHiddenInputs"></div>
                    </div>

                    <!-- Random days panel (calendar) -->
                    <div id="recurPanelRandom" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold">Επιλέξτε Ημερομηνίες</label>
                        <input type="hidden" id="recur_random_dates" name="recur_random_dates">
                        <div id="recurCalContainer"></div>
                    </div>

                    <!-- Interval panel -->
                    <div id="recurPanelInterval" class="mb-3" style="display:none;">
                        <label class="form-label fw-semibold">Επανάληψη κάθε</label>
                        <div class="d-flex align-items-center gap-2" style="max-width:260px;">
                            <select class="form-select" name="recur_interval_days" id="recurIntervalDays">
                                <option value="1">1 ημέρα (καθημερινά)</option>
                                <option value="2">2 ημέρες</option>
                                <option value="3">3 ημέρες</option>
                                <option value="4">4 ημέρες</option>
                                <option value="5">5 ημέρες</option>
                                <option value="6">6 ημέρες</option>
                            </select>
                        </div>
                    </div>

                    <!-- Series end date -->
                    <div class="mb-2">
                        <label for="recur_end_date" class="form-label fw-semibold">
                            <i class="bi bi-calendar-x me-1 text-danger"></i>Τέλος σειράς
                        </label>
                        <input type="date" class="form-control" id="recur_end_date" name="recur_end_date">
                        <small class="text-muted">Τελευταία ημερομηνία για δημιουργία αποστολών</small>
                    </div>

                    <div class="alert alert-info py-2 mb-0 mt-3" id="recurPreviewAlert" style="display:none;">
                        <i class="bi bi-info-circle me-1"></i>
                        <span id="recurPreviewText"></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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
                <div class="row g-3">
                    <div class="col-7">
                        <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                            <i class="bi bi-calendar3 me-1"></i>Ημερομηνία
                        </label>
                        <input type="date" id="modalDatePart"
                               class="form-control form-control-lg text-center fw-semibold"
                               style="font-size:1.1rem; border:2px solid #e0ddff; border-radius:12px;
                                      background:#fff; color:#4f46e5; padding:14px 10px;
                                      box-shadow:0 2px 8px rgba(79,70,229,0.08); transition:border-color .2s,box-shadow .2s;"
                               onfocus="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.15)'"
                               onblur="this.style.borderColor='#e0ddff';this.style.boxShadow='0 2px 8px rgba(79,70,229,0.08)'">
                    </div>
                    <div class="col-5">
                        <label class="form-label text-muted small fw-semibold text-uppercase mb-2">
                            <i class="bi bi-clock me-1"></i>Ώρα
                        </label>
                        <input type="time" id="modalTimePart"
                               class="form-control form-control-lg text-center fw-semibold"
                               step="900"
                               style="font-size:1.1rem; border:2px solid #e0ddff; border-radius:12px;
                                      background:#fff; color:#4f46e5; padding:14px 10px;
                                      box-shadow:0 2px 8px rgba(79,70,229,0.08); transition:border-color .2s,box-shadow .2s;"
                               onfocus="this.style.borderColor='#4f46e5';this.style.boxShadow='0 0 0 4px rgba(79,70,229,0.15)'"
                               onblur="this.style.borderColor='#e0ddff';this.style.boxShadow='0 2px 8px rgba(79,70,229,0.08)'">
                    </div>
                </div>

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
    const isoVal = dmyToIso(currentVal); // yyyy-mm-ddTHH:ii
    document.getElementById('modalDatePart').value = isoVal ? isoVal.slice(0,10) : '';
    document.getElementById('modalTimePart').value = isoVal ? isoVal.slice(11,16) : '';
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
        document.getElementById('modalDatePart').focus();
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
    const datePart = document.getElementById('modalDatePart').value; // yyyy-mm-dd
    const timePart = document.getElementById('modalTimePart').value; // HH:ii
    if (!datePart || !timePart) { return; }
    const isoVal = datePart + 'T' + timePart;
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
    document.getElementById('modalDatePart').value = '';
    document.getElementById('modalTimePart').value = '';
    document.getElementById(window._dpField + '_datetime').value = '';
    document.getElementById(window._dpField + '_datetime_display').value = '';
    window._dpModal.hide();
});

document.getElementById('modalTimePart').addEventListener('keydown', function(e) {
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
        dialogsInBody: true,
        toolbar: [
            ['style', ['bold', 'italic', 'underline']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['insert', ['link']],
            ['view', ['codeview']]
        ],

    });
});
</script>

<style>
/* ── Recurring Calendar ─────────────────────────── */
.recur-cal-wrap { display: flex; flex-wrap: wrap; gap: 20px; }
.recur-cal-month { min-width: 210px; }
.recur-cal-grid  { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.recur-cal-hdr   { text-align:center; font-size:.68rem; font-weight:600; color:#6c757d; padding:3px 1px; }
.recur-cal-day   { text-align:center; padding:5px 2px; font-size:.78rem; border-radius:5px; cursor:pointer; transition:background .15s; }
.recur-cal-day:hover         { background:#dbeafe; }
.recur-cal-day.selected      { background:#4f46e5; color:#fff; font-weight:600; }
.recur-cal-day.disabled      { color:#ccc; cursor:default; pointer-events:none; }
.recur-cal-day.today         { outline:2px solid #4f46e5; outline-offset:-2px; }
.recur-cal-month-header      { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-size:.85rem; font-weight:600; }
</style>

<script>
// ══ RECURRING MISSIONS JS ════════════════════════════════════════════════════

function toggleRecurring(on) {
    document.getElementById('recurringBody').style.display = on ? '' : 'none';
    if (on && window._recurCalInstance) window._recurCalInstance.render();
    if (on) updateRecurPreview();
}

function switchRecurType(type) {
    document.getElementById('recurPanelWeekly').style.display   = type === 'weekly'      ? '' : 'none';
    document.getElementById('recurPanelRandom').style.display   = type === 'random_days' ? '' : 'none';
    document.getElementById('recurPanelInterval').style.display = type === 'interval'    ? '' : 'none';
    if (type === 'random_days') {
        if (!window._recurCalInstance) {
            window._recurCalInstance = new RecurCalendar('recurCalContainer', 'recur_random_dates');
        } else {
            window._recurCalInstance.render();
        }
    }
    updateRecurPreview();
}

// ── Weekday chips ───────────────────────────────────────────────────────────
function toggleWeekday(btn) {
    btn.classList.toggle('btn-primary');
    btn.classList.toggle('text-white');
    btn.classList.toggle('btn-outline-secondary');
    rebuildWeekdayInputs();
    updateRecurPreview();
}

function rebuildWeekdayInputs() {
    const wrap = document.getElementById('weekdayHiddenInputs');
    wrap.innerHTML = '';
    document.querySelectorAll('.wday-chip.btn-primary').forEach(function(b) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'recur_weekdays[]';
        inp.value = b.dataset.day;
        wrap.appendChild(inp);
    });
}

// ── Count preview ────────────────────────────────────────────────────────────
document.getElementById('recur_end_date').addEventListener('change', updateRecurPreview);
document.getElementById('recurIntervalDays').addEventListener('change', updateRecurPreview);

function getSeriesStartDate() {
    const startVal = document.getElementById('start_datetime').value;
    if (!startVal) return null;
    const m = startVal.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (!m) return null;
    return new Date(parseInt(m[3]), parseInt(m[2])-1, parseInt(m[1]));
}

function updateRecurPreview() {
    const endDateVal = document.getElementById('recur_end_date').value;
    if (!endDateVal) { hidePreview(); return; }
    const endDate = new Date(endDateVal + 'T00:00:00');
    const startDate = getSeriesStartDate();
    if (!startDate || endDate < startDate) { hidePreview(); return; }

    const type = document.querySelector('input[name="recur_type"]:checked')?.value;
    let count = 0;

    if (type === 'weekly') {
        const selectedDays = Array.from(document.querySelectorAll('.wday-chip.btn-primary'))
                                  .map(b => parseInt(b.dataset.day));
        if (!selectedDays.length) { hidePreview(); return; }
        const cursor = new Date(startDate);
        while (cursor <= endDate) {
            const dow = cursor.getDay() === 0 ? 7 : cursor.getDay(); // ISO
            if (selectedDays.includes(dow)) count++;
            cursor.setDate(cursor.getDate() + 1);
            if (count > 100) break;
        }
    } else if (type === 'random_days') {
        const raw = document.getElementById('recur_random_dates').value;
        count = raw ? raw.split(',').filter(Boolean).length : 0;
    } else if (type === 'interval') {
        const n = parseInt(document.getElementById('recurIntervalDays').value) || 1;
        const cursor = new Date(startDate);
        while (cursor <= endDate) {
            count++;
            cursor.setDate(cursor.getDate() + n);
            if (count > 100) break;
        }
    }

    if (count === 0) { hidePreview(); return; }
    const alert = document.getElementById('recurPreviewAlert');
    alert.style.display = '';
    if (count > 100) {
        alert.className = 'alert alert-danger py-2 mb-0 mt-3';
        document.getElementById('recurPreviewText').textContent = 'Υπέρβαση ορίου: > 100 αποστολές. Μειώστε το εύρος.';
    } else {
        alert.className = 'alert alert-info py-2 mb-0 mt-3';
        document.getElementById('recurPreviewText').textContent = 'Θα δημιουργηθούν ' + count + ' αποστολές.';
    }
}

function hidePreview() {
    document.getElementById('recurPreviewAlert').style.display = 'none';
}

// ══ VANILLA JS CALENDAR ══════════════════════════════════════════════════════
class RecurCalendar {
    constructor(containerId, hiddenInputId) {
        this.container = document.getElementById(containerId);
        this.hidden    = document.getElementById(hiddenInputId);
        this.selected  = new Set(this.hidden.value ? this.hidden.value.split(',').filter(Boolean) : []);
        const now = new Date();
        this.year  = now.getFullYear();
        this.month = now.getMonth(); // 0-based
        this.render();
    }

    getStartLimit() {
        const d = getSeriesStartDate();
        return d;
    }

    getEndLimit() {
        const v = document.getElementById('recur_end_date').value;
        return v ? new Date(v + 'T00:00:00') : null;
    }

    renderMonth(year, month) {
        const startLimit = this.getStartLimit();
        const endLimit   = this.getEndLimit();
        const firstDay   = new Date(year, month, 1);
        const lastDay    = new Date(year, month + 1, 0);
        const monthNames = ['Ιανουάριος','Φεβρουάριος','Μάρτιος','Απρίλιος','Μάιος','Ιούνιος',
                            'Ιούλιος','Αύγουστος','Σεπτέμβριος','Οκτώβριος','Νοέμβριος','Δεκέμβριος'];
        const dayNames = ['Δε','Τρ','Τε','Πε','Πα','Σα','Κυ'];
        const today    = new Date(); today.setHours(0,0,0,0);
        const pad = n => String(n).padStart(2,'0');
        const self = this;

        let html = '<div class="recur-cal-month"><div class="recur-cal-month-header">';
        html += '<strong>' + monthNames[month] + ' ' + year + '</strong></div>';
        html += '<div class="recur-cal-grid">';
        dayNames.forEach(function(d) { html += '<div class="recur-cal-hdr">' + d + '</div>'; });

        // Leading blanks (Mon=0)
        let startDow = firstDay.getDay();
        startDow = startDow === 0 ? 6 : startDow - 1;
        for (let i = 0; i < startDow; i++) html += '<div></div>';

        for (let d = 1; d <= lastDay.getDate(); d++) {
            const dt  = new Date(year, month, d);
            const key = year + '-' + pad(month+1) + '-' + pad(d);
            const isToday    = dt.getTime() === today.getTime();
            const isPast     = (startLimit && dt < startLimit) || (endLimit && dt > endLimit);
            const isSelected = self.selected.has(key);
            let cls = 'recur-cal-day';
            if (isPast) cls += ' disabled';
            else if (isSelected) cls += ' selected';
            if (isToday) cls += ' today';
            const click = isPast ? '' : ' onclick="window._recurCalInstance.toggle(\'' + key + '\')"';
            html += '<div class="' + cls + '" data-date="' + key + '"' + click + '>' + d + '</div>';
        }
        html += '</div></div>';
        return html;
    }

    render() {
        let nm = this.month + 1, ny = this.year;
        if (nm > 11) { nm = 0; ny++; }

        let html = '<div class="recur-cal-wrap">';
        html += this.renderMonth(this.year, this.month);
        html += this.renderMonth(ny, nm);
        html += '</div>';
        html += '<div class="mt-2 d-flex gap-2 align-items-center flex-wrap">';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._recurCalInstance.prevMonths()"><i class="bi bi-chevron-left"></i> Προηγ.</button>';
        html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="window._recurCalInstance.nextMonths()">Επόμ. <i class="bi bi-chevron-right"></i></button>';
        if (this.selected.size > 0) {
            html += '<button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="window._recurCalInstance.clearAll()"><i class="bi bi-x-circle me-1"></i>Καθαρισμός</button>';
        }
        html += '<span class="ms-auto text-muted small">' + this.selected.size + ' επιλεγμένες</span>';
        html += '</div>';
        this.container.innerHTML = html;
        updateRecurPreview();
    }

    toggle(key) {
        if (this.selected.has(key)) this.selected.delete(key);
        else this.selected.add(key);
        this.hidden.value = Array.from(this.selected).sort().join(',');
        this.render();
    }

    clearAll() {
        this.selected.clear();
        this.hidden.value = '';
        this.render();
    }

    prevMonths() {
        this.month--;
        if (this.month < 0) { this.month = 11; this.year--; }
        this.render();
    }

    nextMonths() {
        this.month++;
        if (this.month > 11) { this.month = 0; this.year++; }
        this.render();
    }
}
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
