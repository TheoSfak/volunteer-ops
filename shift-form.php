<?php
/**
 * VolunteerOps - Shift Form (Create/Edit)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$id = get('id');
$missionId = get('mission_id');
$shift = null;
$pageTitle = 'Νέα Βάρδια';

if ($id) {
    $shift = dbFetchOne("SELECT * FROM shifts WHERE id = ?", [$id]);
    if (!$shift) {
        setFlash('error', 'Η βάρδια δεν βρέθηκε.');
        redirect('shifts.php');
    }
    $missionId = $shift['mission_id'];
    $pageTitle = 'Επεξεργασία Βάρδιας';
}

// Get mission
$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('missions.php');
}

// Get skills for requirements
$skills = dbFetchAll("SELECT * FROM skills ORDER BY category, name");

// Parse existing required skills
$requiredSkills = [];
if ($shift && $shift['required_skills']) {
    $requiredSkills = json_decode($shift['required_skills'], true) ?: [];
}

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    // Get and validate inputs
    $startDate = post('start_date');
    $startHour = post('start_time_hour');
    $endDate = post('end_date');
    $endHour = post('end_time_hour');
    $maxVolunteers = post('max_volunteers', 5);
    $minVolunteers = post('min_volunteers', 1);
    $notes = post('notes');
    
    // Validation using new helpers
    $errors = validateFields([
        ['validateRequired', $startDate, 'Ημερομηνία έναρξης'],
        ['validateRequired', $startHour, 'Ώρα έναρξης'],
        ['validateRequired', $endDate, 'Ημερομηνία λήξης'],
        ['validateRequired', $endHour, 'Ώρα λήξης'],
        ['validateNumber', $maxVolunteers, 1, 100, 'Μέγιστος αριθμός εθελοντών'],
        ['validateNumber', $minVolunteers, 1, 100, 'Ελάχιστος αριθμός εθελοντών'],
    ]);
    
    if (empty($errors)) {
        $data = [
            'start_time' => $startDate . ' ' . $startHour . ':00',
            'end_time' => $endDate . ' ' . $endHour . ':00',
            'max_volunteers' => (int) $maxVolunteers,
            'min_volunteers' => (int) $minVolunteers,
            'notes' => $notes,
        ];
        
        // Additional validation
        if ($data['start_time'] >= $data['end_time']) {
            $errors[] = 'Η λήξη πρέπει να είναι μετά την έναρξη.';
        }
        if ($data['min_volunteers'] > $data['max_volunteers']) {
            $errors[] = 'Ο ελάχιστος αριθμός δεν μπορεί να υπερβαίνει τον μέγιστο.';
        }
    }
    
    if (empty($errors)) {
        if ($shift) {
            // Update
            dbExecute(
                "UPDATE shifts SET 
                 start_time = ?, end_time = ?, max_volunteers = ?, min_volunteers = ?, notes = ?,
                 updated_at = NOW()
                 WHERE id = ?",
                [
                    $data['start_time'], $data['end_time'], $data['max_volunteers'],
                    $data['min_volunteers'], $data['notes'], $id
                ]
            );
            logAudit('update', 'shifts', $id);
            setFlash('success', 'Η βάρδια ενημερώθηκε.');
        } else {
            // Create
            $id = dbInsert(
                "INSERT INTO shifts 
                 (mission_id, start_time, end_time, max_volunteers, min_volunteers, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $missionId, $data['start_time'], $data['end_time'], $data['max_volunteers'],
                    $data['min_volunteers'], $data['notes']
                ]
            );
            logAudit('create', 'shifts', $id);
            setFlash('success', 'Η βάρδια δημιουργήθηκε.');
        }
        redirect('mission-view.php?id=' . $missionId);
    }
}

// Prepare form values
$defaultDate = isset($mission['start_datetime']) ? substr($mission['start_datetime'], 0, 10) : date('Y-m-d');
$form = $shift ?: [
    'start_time' => $defaultDate . ' 09:00:00',
    'end_time' => $defaultDate . ' 17:00:00',
    'max_volunteers' => 5,
    'min_volunteers' => 1,
    'notes' => '',
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><?= h($pageTitle) ?></h1>
        <small class="text-muted">Αποστολή: <?= h($mission['title']) ?></small>
    </div>
    <a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-outline-secondary">
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
                <div class="col-md-6">
                    <label class="form-label">Έναρξη *</label>
                    <div class="row">
                        <div class="col-7 mb-3">
                            <input type="date" class="form-control" name="start_date" 
                                   value="<?= date('Y-m-d', strtotime($form['start_time'])) ?>" required>
                        </div>
                        <div class="col-5 mb-3">
                            <input type="time" class="form-control" name="start_time_hour" 
                                   value="<?= date('H:i', strtotime($form['start_time'])) ?>" required>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Λήξη *</label>
                    <div class="row">
                        <div class="col-7 mb-3">
                            <input type="date" class="form-control" name="end_date" 
                                   value="<?= date('Y-m-d', strtotime($form['end_time'])) ?>" required>
                        </div>
                        <div class="col-5 mb-3">
                            <input type="time" class="form-control" name="end_time_hour" 
                                   value="<?= date('H:i', strtotime($form['end_time'])) ?>" required>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Ελάχ. Εθελοντές</label>
                    <input type="number" class="form-control" name="min_volunteers" 
                           value="<?= $form['min_volunteers'] ?>" min="1" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Μέγ. Εθελοντές</label>
                    <input type="number" class="form-control" name="max_volunteers" 
                           value="<?= $form['max_volunteers'] ?>" min="1" required>
                </div>
            </div>
            
            <?php if (!empty($skills)): ?>
            <div class="mb-3">
                <label class="form-label">Απαιτούμενες Δεξιότητες</label>
                <div class="row">
                    <?php 
                    $currentCategory = '';
                    foreach ($skills as $skill): 
                        if ($skill['category'] !== $currentCategory):
                            $currentCategory = $skill['category'];
                    ?>
                        <div class="col-12"><strong class="text-muted small"><?= h($currentCategory) ?></strong></div>
                    <?php endif; ?>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="skills[]" 
                                       value="<?= $skill['id'] ?>" id="skill<?= $skill['id'] ?>"
                                       <?= in_array($skill['id'], $requiredSkills) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="skill<?= $skill['id'] ?>">
                                    <?= h($skill['name']) ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mb-3">
                <label class="form-label">Σημειώσεις (εσωτερικές)</label>
                <textarea class="form-control" name="notes" rows="2"><?= h($form['notes']) ?></textarea>
            </div>
            
            <hr>
            
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Αποθήκευση
            </button>
            <a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-outline-secondary">Ακύρωση</a>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
