<?php
/**
 * VolunteerOps - Complaint View
 * Προβολή παραπόνου (εθελοντής & admin)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    setFlash('error', 'Μη έγκυρο παράπονο.');
    redirect('my-complaints.php');
}

$complaint = dbFetchOne(
    "SELECT c.*, 
            u.name as user_name, u.email as user_email,
            m.title as mission_title, m.id as mission_id,
            responder.name as responded_by_name
     FROM complaints c
     JOIN users u ON c.user_id = u.id
     LEFT JOIN missions m ON c.mission_id = m.id
     LEFT JOIN users responder ON c.responded_by = responder.id
     WHERE c.id = ?",
    [$id]
);

if (!$complaint) {
    setFlash('error', 'Δεν βρέθηκε το παράπονο.');
    redirect('my-complaints.php');
}

// Access control: owner or admin
$isOwner = ($complaint['user_id'] == getCurrentUserId());
if (!$isOwner && !isAdmin()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτό το παράπονο.');
    redirect('my-complaints.php');
}

$pageTitle = 'Παράπονο #' . $id;

// Handle admin response
if (isPost() && isAdmin()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'respond':
            $response = trim(post('admin_response'));
            $newStatus = post('new_status', $complaint['status']);
            
            if (empty($response)) {
                setFlash('error', 'Η απάντηση είναι υποχρεωτική.');
                redirect('complaint-view.php?id=' . $id);
            }
            
            if (!array_key_exists($newStatus, COMPLAINT_STATUS_LABELS)) {
                $newStatus = COMPLAINT_IN_REVIEW;
            }
            
            dbExecute(
                "UPDATE complaints SET admin_response = ?, status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?",
                [$response, $newStatus, getCurrentUserId(), $id]
            );
            
            logAudit('respond_complaint', 'complaints', $id);
            
            // Notify the volunteer
            sendNotification(
                $complaint['user_id'],
                'Απάντηση στο Παράπονο #' . $id,
                'Υπάρχει απάντηση στο παράπονό σας: ' . $complaint['subject'],
                'complaint_response'
            );
            
            setFlash('success', 'Η απάντηση αποθηκεύτηκε.');
            redirect('complaint-view.php?id=' . $id);
            break;
            
        case 'change_status':
            $newStatus = post('new_status');
            if (array_key_exists($newStatus, COMPLAINT_STATUS_LABELS)) {
                dbExecute("UPDATE complaints SET status = ? WHERE id = ?", [$newStatus, $id]);
                logAudit('change_complaint_status', 'complaints', $id);
                setFlash('success', 'Η κατάσταση ενημερώθηκε.');
            }
            redirect('complaint-view.php?id=' . $id);
            break;
    }
}

// Back URL depending on role
$backUrl = $isOwner && !isAdmin() ? 'my-complaints.php' : 'complaints.php';

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">
            
            <div class="d-flex align-items-center mb-4">
                <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h2 class="mb-0">
                    <i class="bi bi-chat-left-dots me-2"></i>Παράπονο #<?= $id ?>
                    <span class="badge bg-<?= COMPLAINT_STATUS_COLORS[$complaint['status']] ?? 'secondary' ?> ms-2">
                        <?= h(COMPLAINT_STATUS_LABELS[$complaint['status']] ?? $complaint['status']) ?>
                    </span>
                </h2>
            </div>
            
            <?= showFlash() ?>
            
            <!-- Complaint Details -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Στοιχεία Παραπόνου</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Υποβολή από</small>
                            <strong><?= h($complaint['user_name']) ?></strong>
                            <small class="text-muted">(<?= h($complaint['user_email']) ?>)</small>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Ημερομηνία</small>
                            <strong><?= formatDateTime($complaint['created_at']) ?></strong>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <small class="text-muted d-block">Κατηγορία</small>
                            <strong><?= h(COMPLAINT_CATEGORY_LABELS[$complaint['category']] ?? $complaint['category']) ?></strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Προτεραιότητα</small>
                            <span class="badge bg-<?= COMPLAINT_PRIORITY_COLORS[$complaint['priority']] ?? 'secondary' ?>">
                                <?= h(COMPLAINT_PRIORITY_LABELS[$complaint['priority']] ?? $complaint['priority']) ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block">Σχετική Αποστολή</small>
                            <?php if ($complaint['mission_title']): ?>
                                <a href="mission-view.php?id=<?= $complaint['mission_id'] ?>">
                                    <?= h($complaint['mission_title']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">— Καμία —</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="fw-bold"><?= h($complaint['subject']) ?></h6>
                    <div class="bg-light rounded p-3 mt-2" style="white-space: pre-wrap;"><?= h($complaint['body']) ?></div>
                </div>
            </div>
            
            <!-- Admin Response (if exists) -->
            <?php if (!empty($complaint['admin_response'])): ?>
                <div class="card shadow-sm mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-reply me-2"></i>Απάντηση Διοίκησης</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">
                                Από: <strong><?= h($complaint['responded_by_name'] ?? 'Διαχειριστής') ?></strong>
                            </small>
                            <small class="text-muted"><?= $complaint['responded_at'] ? formatDateTime($complaint['responded_at']) : '' ?></small>
                        </div>
                        <div class="bg-light rounded p-3" style="white-space: pre-wrap;"><?= h($complaint['admin_response']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Admin Actions -->
            <?php if (isAdmin()): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning bg-opacity-25">
                        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Ενέργειες Διαχειριστή</h5>
                    </div>
                    <div class="card-body">
                        <!-- Change Status -->
                        <div class="d-flex align-items-center mb-4">
                            <form method="post" class="d-flex align-items-center gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_status">
                                <label class="fw-semibold me-2 text-nowrap">Αλλαγή Κατάστασης:</label>
                                <select name="new_status" class="form-select form-select-sm" style="width:auto;">
                                    <?php foreach (COMPLAINT_STATUS_LABELS as $key => $label): ?>
                                        <option value="<?= h($key) ?>" <?= $complaint['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Ενημέρωση</button>
                            </form>
                        </div>
                        
                        <hr>
                        
                        <!-- Respond -->
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="respond">
                            
                            <div class="mb-3">
                                <label for="admin_response" class="form-label fw-semibold">
                                    <?= empty($complaint['admin_response']) ? 'Απάντηση' : 'Ενημέρωση Απάντησης' ?>
                                </label>
                                <textarea name="admin_response" id="admin_response" class="form-control" rows="5" required
                                    placeholder="Γράψτε την απάντησή σας..."><?= h($complaint['admin_response'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="row align-items-end">
                                <div class="col-md-4 mb-3">
                                    <label for="respond_status" class="form-label">Νέα Κατάσταση</label>
                                    <select name="new_status" id="respond_status" class="form-select">
                                        <?php foreach (COMPLAINT_STATUS_LABELS as $key => $label): ?>
                                            <option value="<?= h($key) ?>" <?= $complaint['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-send me-1"></i>Αποθήκευση Απάντησης
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
