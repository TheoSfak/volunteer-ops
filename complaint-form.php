<?php
/**
 * VolunteerOps - Complaint Form
 * Φόρμα υποβολής παραπόνου εθελοντή
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Αναφορά Παραπόνου';
$currentUser = getCurrentUser();

// Handle form submission
if (isPost()) {
    verifyCsrf();
    
    $category = post('category', COMPLAINT_CAT_OTHER);
    $priority = post('priority', COMPLAINT_PRIORITY_MEDIUM);
    $subject = trim(post('subject'));
    $body = trim(post('body'));
    $missionId = post('mission_id') ?: null;
    
    // Validation
    $errors = [];
    if (empty($subject)) {
        $errors[] = 'Το θέμα είναι υποχρεωτικό.';
    }
    if (empty($body)) {
        $errors[] = 'Το κείμενο παραπόνου είναι υποχρεωτικό.';
    }
    if (!array_key_exists($category, COMPLAINT_CATEGORY_LABELS)) {
        $errors[] = 'Μη έγκυρη κατηγορία.';
    }
    if (!array_key_exists($priority, COMPLAINT_PRIORITY_LABELS)) {
        $errors[] = 'Μη έγκυρη προτεραιότητα.';
    }
    // Validate mission_id if provided
    if ($missionId) {
        $mission = dbFetchOne("SELECT id FROM missions WHERE id = ?", [(int)$missionId]);
        if (!$mission) {
            $errors[] = 'Η αποστολή δεν βρέθηκε.';
            $missionId = null;
        }
    }
    
    if (!empty($errors)) {
        setFlash('error', implode(' ', $errors));
    } else {
        $id = dbInsert(
            "INSERT INTO complaints (user_id, mission_id, category, priority, subject, body, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [getCurrentUserId(), $missionId, $category, $priority, $subject, $body, COMPLAINT_NEW]
        );
        
        logAudit('create_complaint', 'complaints', $id);
        
        // Notify admins via in-app notification
        $admins = dbFetchAll("SELECT id FROM users WHERE role IN (?, ?)", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
        foreach ($admins as $admin) {
            sendNotification(
                $admin['id'],
                'Νέο Παράπονο',
                'Ο/Η ' . $currentUser['name'] . ' υπέβαλε παράπονο: ' . $subject,
                'complaint'
            );
        }
        
        setFlash('success', 'Το παράπονό σας υποβλήθηκε επιτυχώς. Θα εξεταστεί από τη διοίκηση.');
        redirect('my-complaints.php');
    }
}

// Fetch missions for the search dropdown (recent missions the user participated in + all open ones)
$userMissions = dbFetchAll(
    "SELECT DISTINCT m.id, m.title, m.start_datetime
     FROM missions m
     LEFT JOIN shifts s ON s.mission_id = m.id
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id AND pr.volunteer_id = ?
     WHERE pr.id IS NOT NULL OR m.status IN (?, ?)
     ORDER BY m.start_datetime DESC
     LIMIT 100",
    [getCurrentUserId(), STATUS_OPEN, STATUS_COMPLETED]
);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">
            
            <div class="d-flex align-items-center mb-4">
                <a href="my-complaints.php" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h2 class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Αναφορά Παραπόνου</h2>
            </div>
            
            <?= showFlash() ?>
            
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="post">
                        <?= csrfField() ?>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="category" class="form-label fw-semibold">Κατηγορία <span class="text-danger">*</span></label>
                                <select name="category" id="category" class="form-select" required>
                                    <?php foreach (COMPLAINT_CATEGORY_LABELS as $key => $label): ?>
                                        <option value="<?= h($key) ?>" <?= post('category') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="priority" class="form-label fw-semibold">Προτεραιότητα <span class="text-danger">*</span></label>
                                <select name="priority" id="priority" class="form-select" required>
                                    <?php foreach (COMPLAINT_PRIORITY_LABELS as $key => $label): ?>
                                        <option value="<?= h($key) ?>" <?= (post('priority', COMPLAINT_PRIORITY_MEDIUM) === $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="mission_search" class="form-label fw-semibold">
                                Σχετική Αποστολή <span class="text-muted fw-normal">(προαιρετικό)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="mission_search" class="form-control" placeholder="Αναζήτηση αποστολής..." autocomplete="off">
                            </div>
                            <input type="hidden" name="mission_id" id="mission_id" value="<?= h(post('mission_id')) ?>">
                            <div id="mission_selected" class="mt-2" style="display:none;">
                                <span class="badge bg-info fs-6 py-2 px-3">
                                    <span id="mission_selected_text"></span>
                                    <button type="button" class="btn-close btn-close-white ms-2" id="mission_clear" style="font-size:0.6em;"></button>
                                </span>
                            </div>
                            <div id="mission_results" class="list-group mt-1 position-absolute shadow" style="z-index:1050; display:none; max-height:250px; overflow-y:auto;"></div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="subject" class="form-label fw-semibold">Θέμα <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="subject" class="form-control" 
                                   value="<?= h(post('subject')) ?>" 
                                   placeholder="Σύντομη περιγραφή του παραπόνου" required maxlength="255">
                        </div>
                        
                        <div class="mb-4">
                            <label for="body" class="form-label fw-semibold">Κείμενο Παραπόνου <span class="text-danger">*</span></label>
                            <textarea name="body" id="body" class="form-control" rows="8" 
                                      placeholder="Περιγράψτε αναλυτικά το παράπονό σας..." required><?= h(post('body')) ?></textarea>
                            <div class="form-text">Περιγράψτε λεπτομερώς το πρόβλημα ή την κατάσταση.</div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="my-complaints.php" class="btn btn-secondary">
                                <i class="bi bi-x-lg me-1"></i>Ακύρωση
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Υποβολή Παραπόνου
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</div>

<script>
// Mission search autocomplete
const missions = <?= json_encode(array_map(function($m) {
    return [
        'id' => $m['id'],
        'title' => $m['title'],
        'date' => date('d/m/Y', strtotime($m['start_datetime']))
    ];
}, $userMissions), JSON_UNESCAPED_UNICODE) ?>;

const searchInput = document.getElementById('mission_search');
const resultsDiv = document.getElementById('mission_results');
const missionIdInput = document.getElementById('mission_id');
const selectedDiv = document.getElementById('mission_selected');
const selectedText = document.getElementById('mission_selected_text');
const clearBtn = document.getElementById('mission_clear');

searchInput.addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    if (query.length < 2) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    const filtered = missions.filter(m => 
        m.title.toLowerCase().includes(query) || m.date.includes(query)
    ).slice(0, 10);
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="list-group-item text-muted">Δεν βρέθηκαν αποστολές</div>';
    } else {
        resultsDiv.innerHTML = filtered.map(m => 
            `<a href="#" class="list-group-item list-group-item-action" data-id="${m.id}" data-title="${m.title}">
                <strong>${m.title}</strong> <small class="text-muted">(${m.date})</small>
            </a>`
        ).join('');
    }
    resultsDiv.style.display = 'block';
});

resultsDiv.addEventListener('click', function(e) {
    e.preventDefault();
    const item = e.target.closest('[data-id]');
    if (item) {
        missionIdInput.value = item.dataset.id;
        selectedText.textContent = item.dataset.title;
        selectedDiv.style.display = 'block';
        searchInput.value = '';
        searchInput.style.display = 'none';
        searchInput.parentElement.style.display = 'none';
        resultsDiv.style.display = 'none';
    }
});

clearBtn.addEventListener('click', function() {
    missionIdInput.value = '';
    selectedDiv.style.display = 'none';
    searchInput.style.display = '';
    searchInput.parentElement.style.display = '';
    searchInput.value = '';
});

// Close results on outside click
document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
        resultsDiv.style.display = 'none';
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
