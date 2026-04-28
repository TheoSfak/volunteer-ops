<?php
/**
 * VolunteerOps - QR Self Check-in for Volunteers
 * Volunteer scans the QR code printed/shown by their shift leader → self check-in.
 */

require_once __DIR__ . '/bootstrap.php';

// Redirect to login preserving the return URL
if (!isLoggedIn()) {
    $returnUrl = urlencode('checkin.php?token=' . htmlspecialchars(get('token', ''), ENT_QUOTES));
    redirect('login.php?returnUrl=' . $returnUrl);
}

// Feature gate
if (getSetting('qr_checkin_enabled', '0') !== '1') {
    $pageTitle = 'QR Check-in';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6 col-lg-4">
            <div class="card text-center shadow-sm">
                <div class="card-body py-5">
                    <i class="bi bi-qr-code text-muted" style="font-size:3rem;"></i>
                    <h4 class="mt-3">QR Check-in απενεργοποιημένο</h4>
                    <p class="text-muted">Το QR check-in παρουσίας δεν είναι ενεργό αυτή τη στιγμή.</p>
                    <a href="dashboard.php" class="btn btn-outline-primary mt-2">
                        <i class="bi bi-house me-1"></i>Αρχική
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$token = trim(get('token', ''));

if (empty($token)) {
    redirect('dashboard.php');
}

// Find the shift by token
$shift = dbFetchOne(
    "SELECT s.*, m.title AS mission_title, m.status AS mission_status,
            m.location, m.start_datetime AS mission_start, m.end_datetime AS mission_end
     FROM shifts s
     JOIN missions m ON s.mission_id = m.id
     WHERE s.qr_token = ?",
    [$token]
);

if (!$shift) {
    $pageTitle = 'QR Check-in';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6 col-lg-4">
            <div class="card text-center shadow-sm border-danger">
                <div class="card-body py-5">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size:3rem;"></i>
                    <h4 class="mt-3">Μη έγκυρος QR κωδικός</h4>
                    <p class="text-muted">Αυτός ο σύνδεσμος δεν αντιστοιχεί σε ενεργή βάρδια.</p>
                    <a href="dashboard.php" class="btn btn-outline-secondary mt-2">
                        <i class="bi bi-house me-1"></i>Αρχική
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Mission must be OPEN
if ($shift['mission_status'] !== STATUS_OPEN) {
    $pageTitle = 'QR Check-in';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6 col-lg-4">
            <div class="card text-center shadow-sm border-warning">
                <div class="card-body py-5">
                    <i class="bi bi-clock-history text-warning" style="font-size:3rem;"></i>
                    <h4 class="mt-3">Η αποστολή δεν είναι ανοιχτή</h4>
                    <p class="text-muted">Δεν είναι δυνατό το check-in αυτή τη στιγμή.</p>
                    <a href="dashboard.php" class="btn btn-outline-secondary mt-2">
                        <i class="bi bi-house me-1"></i>Αρχική
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$userId = getCurrentUserId();

// Find the volunteer's APPROVED participation request for this shift
$pr = dbFetchOne(
    "SELECT * FROM participation_requests WHERE shift_id = ? AND volunteer_id = ? AND status = ?",
    [$shift['id'], $userId, PARTICIPATION_APPROVED]
);

$pageTitle = 'QR Check-in — ' . $shift['mission_title'];

// Handle POST (check-in confirmation)
if (isPost()) {
    verifyCsrf();

    if (!$pr) {
        setFlash('error', 'Δεν βρέθηκε εγκεκριμένη συμμετοχή για αυτή τη βάρδια.');
        redirect('checkin.php?token=' . urlencode($token));
    }

    if (!empty($pr['attendance_confirmed_at'])) {
        // Already checked in — just redirect back to show the state
        redirect('checkin.php?token=' . urlencode($token));
    }

    dbExecute(
        "UPDATE participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         SET pr.attended = 1,
             pr.actual_hours = COALESCE(pr.actual_hours, ROUND(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60, 2)),
             pr.actual_start_time = COALESCE(pr.actual_start_time, TIME(s.start_time)),
             pr.actual_end_time = COALESCE(pr.actual_end_time, TIME(s.end_time)),
             pr.attendance_confirmed_at = NOW(),
             pr.attendance_confirmed_by = ?,
             pr.updated_at = NOW()
         WHERE pr.id = ?",
        [$userId, $pr['id']]
    );

    logAudit('qr_checkin', 'participation_requests', $pr['id'], 'Shift ID: ' . $shift['id']);

    // Re-fetch to show updated state
    $pr = dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$pr['id']]);

    redirect('checkin.php?token=' . urlencode($token));
}

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-4">
    <div class="col-md-7 col-lg-5">

        <!-- Mission / Shift info card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-qr-code me-2"></i>Check-in Παρουσίας</h5>
            </div>
            <div class="card-body">
                <h5 class="fw-bold mb-1"><?= h($shift['mission_title']) ?></h5>
                <div class="text-muted small mb-3">
                    <i class="bi bi-clock me-1"></i><?= formatDateTime($shift['start_time']) ?> – <?= date('H:i', strtotime($shift['end_time'])) ?>
                    <?php if ($shift['location']): ?>
                        <span class="ms-2"><i class="bi bi-geo-alt me-1"></i><?= h($shift['location']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!$pr): ?>
                    <!-- Volunteer has no approved participation -->
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Δεν βρέθηκε εγκεκριμένη συμμετοχή</strong>
                        <p class="mb-0 mt-1 small">Δεν έχετε εγκεκριμένη αίτηση για αυτή τη βάρδια. Επικοινωνήστε με τον υπεύθυνο βάρδιας.</p>
                    </div>

                <?php elseif (!empty($pr['attendance_confirmed_at'])): ?>
                    <!-- Already checked in -->
                    <div class="text-center py-3">
                        <div class="text-success mb-3" style="font-size:4rem;"><i class="bi bi-check-circle-fill"></i></div>
                        <h4 class="text-success fw-bold">Check-in ολοκληρώθηκε!</h4>
                        <p class="text-muted mb-1">Η παρουσία σας καταγράφηκε με επιτυχία.</p>
                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= formatDateTime($pr['attendance_confirmed_at']) ?></small>
                    </div>

                <?php else: ?>
                    <!-- Ready to check in -->
                    <div class="text-center py-2 mb-3">
                        <div class="text-primary mb-2" style="font-size:3rem;"><i class="bi bi-person-check"></i></div>
                        <p class="mb-0">Είστε <strong><?= h(getCurrentUser()['name']) ?></strong></p>
                        <p class="text-muted small">Πατήστε το κουμπί για να καταγράψετε την παρουσία σας.</p>
                    </div>
                    <form method="post">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-success btn-lg w-100">
                            <i class="bi bi-check2-circle me-2"></i>Είμαι Παρών/Παρούσα
                        </button>
                    </form>

                <?php endif; ?>
            </div>
        </div>

        <div class="text-center">
            <a href="my-participations.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-check me-1"></i>Οι συμμετοχές μου
            </a>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
