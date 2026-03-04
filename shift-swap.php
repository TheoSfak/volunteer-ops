<?php
/**
 * VolunteerOps - Shift Swap / Cover Request
 * Εθελοντής ζητά αντικαταστάτη για εγκεκριμένη βάρδιά του
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Αίτημα Αντικατάστασης';
$user = getCurrentUser();

$prId = (int) get('participation_id');
if (!$prId) {
    setFlash('error', 'Μη έγκυρη αίτηση συμμετοχής.');
    redirect('my-participations.php');
}

// Verify participation belongs to current user, is APPROVED, shift is in the future
$participation = dbFetchOne(
    "SELECT pr.*, s.start_time, s.end_time, s.mission_id, s.max_volunteers,
            m.title as mission_title, m.location, m.status as mission_status
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$prId, $user['id'], PARTICIPATION_APPROVED]
);

if (!$participation) {
    setFlash('error', 'Δεν βρέθηκε εγκεκριμένη αίτηση συμμετοχής.');
    redirect('my-participations.php');
}

if (strtotime($participation['start_time']) <= time()) {
    setFlash('error', 'Δεν μπορείτε να ζητήσετε αντικατάσταση για βάρδια που έχει ήδη ξεκινήσει.');
    redirect('my-participations.php');
}

// Check no active swap request already exists for this participation
$existingSwap = dbFetchOne(
    "SELECT id, status FROM shift_swap_requests WHERE participation_id = ? AND status IN (?,?)",
    [$prId, SWAP_PENDING_RESPONSE, SWAP_ACCEPTED]
);

if ($existingSwap) {
    setFlash('warning', 'Υπάρχει ήδη ενεργό αίτημα αντικατάστασης για αυτή τη βάρδια.');
    redirect('my-participations.php');
}

// Handle POST — submit swap request
if (isPost()) {
    verifyCsrf();
    $toVolunteerId = (int) post('to_volunteer_id');
    $message       = trim(post('message', ''));

    if (!$toVolunteerId) {
        setFlash('error', 'Παρακαλώ επιλέξτε εθελοντή.');
        redirect('shift-swap.php?participation_id=' . $prId);
    }

    $replacement = dbFetchOne(
        "SELECT id, name, email FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL AND id != ?",
        [$toVolunteerId, $user['id']]
    );

    if (!$replacement) {
        setFlash('error', 'Μη έγκυρος εθελοντής.');
        redirect('shift-swap.php?participation_id=' . $prId);
    }

    // Verify they're not already in this shift
    $alreadyIn = dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE shift_id = ? AND volunteer_id = ? AND status IN (?,?)",
        [$participation['shift_id'], $toVolunteerId, PARTICIPATION_PENDING, PARTICIPATION_APPROVED]
    );

    if ($alreadyIn) {
        setFlash('error', 'Ο/Η ' . h($replacement['name']) . ' συμμετέχει ήδη σε αυτή τη βάρδια.');
        redirect('shift-swap.php?participation_id=' . $prId);
    }

    // Create swap request
    $swapId = dbInsert(
        "INSERT INTO shift_swap_requests 
         (participation_id, from_volunteer_id, to_volunteer_id, shift_id, message, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [$prId, $user['id'], $toVolunteerId, $participation['shift_id'], $message ?: null, SWAP_PENDING_RESPONSE]
    );

    logAudit('swap_requested', 'shift_swap_requests', $swapId);

    // Build action URL
    $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath   = dirname($_SERVER['SCRIPT_NAME'] ?? '/volunteerops');
    $actionUrl  = rtrim($proto . '://' . $host . $basePath, '/') . '/my-participations.php';

    // Email to replacement volunteer
    if (!empty($replacement['email']) && isNotificationEnabled('shift_swap_requested')) {
        sendNotificationEmail('shift_swap_requested', $replacement['email'], [
            'user_name'      => $replacement['name'],
            'requester_name' => $user['name'],
            'mission_title'  => $participation['mission_title'],
            'shift_date'     => formatDateTime($participation['start_time'], 'd/m/Y'),
            'shift_time'     => formatDateTime($participation['start_time'], 'H:i') . ' - ' . formatDateTime($participation['end_time'], 'H:i'),
            'location'       => $participation['location'] ?: 'Θα ανακοινωθεί',
            'message'        => $message,
            'action_url'     => $actionUrl,
        ]);
    }

    // In-app notification to replacement volunteer
    sendNotification(
        $toVolunteerId,
        'Αίτημα Αντικατάστασης',
        'Ο/Η ' . $user['name'] . ' σας ζητά να τον/την αντικαταστήσετε στη βάρδια: ' .
        $participation['mission_title'] . ' (' . formatDateTime($participation['start_time']) . ')'
    );

    setFlash('success', 'Το αίτημα στάλθηκε στον/ην ' . h($replacement['name']) . ' και αναμένει την αποδοχή του/της.');
    redirect('my-participations.php');
}

// Eligible volunteers: active, not already in the shift, not current user
$eligibleVolunteers = dbFetchAll(
    "SELECT u.id, u.name
     FROM users u
     WHERE u.is_active = 1
       AND u.deleted_at IS NULL
       AND u.id != ?
       AND u.id NOT IN (
           SELECT volunteer_id FROM participation_requests
           WHERE shift_id = ? AND status IN (?,?)
       )
     ORDER BY u.name ASC",
    [$user['id'], $participation['shift_id'], PARTICIPATION_PENDING, PARTICIPATION_APPROVED]
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-arrow-left-right me-2 text-purple"></i>Αίτημα Αντικατάστασης</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="my-participations.php">Οι Αιτήσεις μου</a></li>
                <li class="breadcrumb-item active">Αντικατάσταση</li>
            </ol>
        </nav>
    </div>
</div>

<?= displayFlash() ?>

<!-- Shift info card -->
<div class="card mb-4 border-success">
    <div class="card-header bg-success text-white">
        <h6 class="mb-0"><i class="bi bi-calendar-check me-1"></i>Η βάρδια σας</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Αποστολή</div>
                <strong><?= h($participation['mission_title']) ?></strong>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Ημερομηνία</div>
                <strong><?= formatDateTime($participation['start_time'], 'd/m/Y') ?></strong>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Ώρες</div>
                <strong><?= formatDateTime($participation['start_time'], 'H:i') ?> – <?= formatDateTime($participation['end_time'], 'H:i') ?></strong>
            </div>
            <?php if ($participation['location']): ?>
            <div class="col-sm-6">
                <div class="text-muted small text-uppercase fw-semibold mb-1">Τοποθεσία</div>
                <?= h($participation['location']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header" style="background:linear-gradient(135deg,#ede7f6,#f3e5f5);border-bottom:2px solid #8e44ad">
        <h5 class="mb-0" style="color:#6a1b9a"><i class="bi bi-person-fill-gear me-2"></i>Επιλογή Αντικαταστάτη</h5>
    </div>
    <div class="card-body">
        <?php if (empty($eligibleVolunteers)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Δεν υπάρχουν διαθέσιμοι εθελοντές για αντικατάσταση σε αυτή τη βάρδια.
            </div>
        <?php else: ?>
        <div class="alert alert-info d-flex gap-2">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
            <div>
                Επιλέξτε έναν εθελοντή και στείλτε του αίτημα. Αν αποδεχτεί, ο διαχειριστής θα εγκρίνει τελικά την αλλαγή.
            </div>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <div class="mb-3">
                <label for="to_volunteer_id" class="form-label fw-semibold">Εθελοντής αντικατάστασης <span class="text-danger">*</span></label>
                <select name="to_volunteer_id" id="to_volunteer_id" class="form-select" required>
                    <option value="">— Επιλέξτε εθελοντή —</option>
                    <?php foreach ($eligibleVolunteers as $v): ?>
                        <option value="<?= $v['id'] ?>"><?= h($v['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Εμφανίζονται μόνο ενεργοί εθελοντές που δεν συμμετέχουν ήδη στη βάρδια.</div>
            </div>
            <div class="mb-4">
                <label for="message" class="form-label fw-semibold">Μήνυμα <span class="text-muted">(προαιρετικό)</span></label>
                <textarea name="message" id="message" class="form-control" rows="3"
                          placeholder="π.χ. Έχω έκτακτη ανάγκη — ευχαριστώ αν μπορέσεις να με καλύψεις!"></textarea>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary" style="background:#8e44ad;border-color:#8e44ad">
                    <i class="bi bi-send me-1"></i>Αποστολή Αιτήματος
                </button>
                <a href="my-participations.php" class="btn btn-outline-secondary">Ακύρωση</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
