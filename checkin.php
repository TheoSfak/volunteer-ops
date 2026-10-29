<?php
/**
 * VolunteerOps - QR Self Check-in for Volunteers and guests
 *
 * Whoever scans the QR code printed/shown by their shift leader is checked in
 * by the scan itself: there is no button to press. The page then says what was
 * recorded (mission, day, time, shift, place) and offers the way into the
 * mission's Action Room. Opening it again only shows the same confirmation.
 */

require_once __DIR__ . '/bootstrap.php';

// Not signed in: requireLogin() remembers this page (rememberLoginReturnTo()),
// so login.php brings the volunteer straight back to it. This used to send
// login.php?returnUrl=..., a parameter login.php never read — the volunteer
// landed on the dashboard and had to scan the QR code a second time.
requireLogin();

$user    = getCurrentUser();
$userId  = getCurrentUserId();
$isGuest = isExternalGuest();

$pageTitle    = t('checkin.page_title');
$notice       = null;   // a message that replaces the page: nothing else can be shown
$shift        = null;
$pr           = null;
$otherShift   = false;  // approved on the mission, just not on the shift of this QR
$justRecorded = false;  // this very request recorded it, as opposed to an earlier one

if (getSetting('qr_checkin_enabled', '0') !== '1') {
    $notice = ['icon' => 'bi-qr-code', 'tone' => 'muted', 'title' => t('checkin.disabled_title'), 'text' => t('checkin.disabled_text')];
} else {
    $token = trim(get('token', ''));

    // Find the shift by token
    $shift = $token === '' ? null : dbFetchOne(
        "SELECT s.*, m.title AS mission_title, m.status AS mission_status, m.show_in_ops,
                m.location, m.start_datetime AS mission_start, m.end_datetime AS mission_end
         FROM shifts s
         JOIN missions m ON s.mission_id = m.id AND m.deleted_at IS NULL
         WHERE s.qr_token = ?",
        [$token]
    );

    if (!$shift) {
        $notice = ['icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'title' => t('checkin.invalid_title'), 'text' => t('checkin.invalid_text')];
    } elseif ($shift['mission_status'] !== STATUS_OPEN) {
        // Mission must be OPEN
        $notice = ['icon' => 'bi-clock-history', 'tone' => 'warning', 'title' => t('checkin.not_open_title'), 'text' => t('checkin.not_open_text')];
    }
}

if (!$notice) {
    // The volunteer's APPROVED participation request for this shift
    $pr = dbFetchOne(
        "SELECT * FROM participation_requests WHERE shift_id = ? AND volunteer_id = ? AND status = ?",
        [$shift['id'], $userId, PARTICIPATION_APPROVED]
    );

    if ($pr && empty($pr['attendance_confirmed_at'])) {
        if (recordQrCheckin((int) $pr['id'], (int) $userId)) {
            logAudit('qr_checkin', 'participation_requests', $pr['id'], 'Shift ID: ' . $shift['id']);
            $justRecorded = true;
        }
        // Re-fetch either way: when the UPDATE found it already confirmed (a
        // second scan racing the first, or a leader's attendance check) this
        // is what tells the page which of the states below it is.
        $pr = dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$pr['id']]);
    }

    if (!$pr) {
        // Added by hand to a different shift of the same mission is the likely
        // reason: say so, instead of leaving them to guess.
        $otherShift = (bool) dbFetchValue(
            "SELECT COUNT(*) FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
            [$shift['mission_id'], $userId, PARTICIPATION_APPROVED]
        );
    }
}

// The Action Room only opens for an open mission that is on the board — the
// same test war-room.php applies — and only to someone approved on it.
$roomOpen = $shift && ($pr || $otherShift)
    && $shift['mission_status'] === STATUS_OPEN && !empty($shift['show_in_ops']);

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center mt-<?= $notice ? '5' : '4' ?>">
    <div class="col-md-7 col-lg-5">

<?php if ($notice): ?>
        <div class="card text-center shadow-sm<?= $notice['tone'] === 'muted' ? '' : ' border-' . $notice['tone'] ?>">
            <div class="card-body py-5">
                <i class="bi <?= h($notice['icon']) ?> text-<?= $notice['tone'] === 'muted' ? 'muted' : $notice['tone'] ?>" style="font-size:3rem;"></i>
                <h4 class="mt-3"><?= h($notice['title']) ?></h4>
                <p class="text-muted"><?= h($notice['text']) ?></p>
                <?php if (!$isGuest): ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary mt-2">
                        <i class="bi bi-house me-1"></i><?= h(t('checkin.home')) ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
<?php else: ?>
        <!-- Mission / Shift info card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-qr-code me-2"></i><?= h(t('checkin.page_title')) ?></h5>
            </div>
            <div class="card-body">

                <?php if ($pr && !empty($pr['attendance_confirmed_at']) && !empty($pr['attended'])): ?>
                    <!-- Checked in: now, or by an earlier scan -->
                    <div class="text-center py-2">
                        <div class="text-success mb-3" style="font-size:4rem;"><i class="bi bi-check-circle-fill"></i></div>
                        <h4 class="text-success fw-bold"><?= h(t($justRecorded ? 'checkin.done_title' : 'checkin.already_title')) ?></h4>
                        <p class="mb-3"><?= h(t('checkin.declared', ['name' => $user['name'], 'mission' => $shift['mission_title']])) ?></p>
                    </div>
                    <ul class="list-group list-group-flush text-start mb-3">
                        <li class="list-group-item d-flex justify-content-between gap-3">
                            <span class="text-muted"><i class="bi bi-calendar-event me-2"></i><?= h(t('checkin.row_day')) ?></span>
                            <strong><?= formatDate($pr['attendance_confirmed_at']) ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between gap-3">
                            <span class="text-muted"><i class="bi bi-clock me-2"></i><?= h(t('checkin.row_time')) ?></span>
                            <strong><?= formatDateTime($pr['attendance_confirmed_at'], 'H:i') ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between gap-3">
                            <span class="text-muted"><i class="bi bi-person-badge me-2"></i><?= h(t('checkin.row_shift')) ?></span>
                            <strong class="text-end"><?= formatDateTime($shift['start_time']) ?> – <?= date('H:i', strtotime($shift['end_time'])) ?></strong>
                        </li>
                        <?php if (!empty($shift['location'])): ?>
                        <li class="list-group-item d-flex justify-content-between gap-3">
                            <span class="text-muted"><i class="bi bi-geo-alt me-2"></i><?= h(t('checkin.row_place')) ?></span>
                            <strong class="text-end"><?= h($shift['location']) ?></strong>
                        </li>
                        <?php endif; ?>
                    </ul>

                <?php else: ?>
                    <h5 class="fw-bold mb-1"><?= h($shift['mission_title']) ?></h5>
                    <div class="text-muted small mb-3">
                        <i class="bi bi-clock me-1"></i><?= formatDateTime($shift['start_time']) ?> – <?= date('H:i', strtotime($shift['end_time'])) ?>
                        <?php if ($shift['location']): ?>
                            <span class="ms-2"><i class="bi bi-geo-alt me-1"></i><?= h($shift['location']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$pr): ?>
                        <!-- No approved participation on this shift -->
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong><?= h(t('checkin.no_participation_title')) ?></strong>
                            <p class="mb-0 mt-1 small"><?= h(t($otherShift ? 'checkin.other_shift_text' : 'checkin.no_participation_text')) ?></p>
                        </div>
                    <?php else: ?>
                        <!-- Admin-confirmed absence: do not let QR override it -->
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-person-exclamation me-2"></i>
                            <strong><?= h(t('checkin.absent_title')) ?></strong>
                            <p class="mb-0 mt-1 small"><?= h(t('checkin.absent_text')) ?></p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($roomOpen): ?>
                    <a href="war-room.php?id=<?= (int) $shift['mission_id'] ?>" class="btn btn-primary btn-lg w-100 mt-3">
                        <i class="bi bi-broadcast me-2"></i><?= h(t('checkin.enter_room')) ?>
                    </a>
                <?php elseif ($pr && !empty($pr['attended'])): ?>
                    <p class="text-muted small text-center mb-0"><?= h(t('checkin.room_unavailable')) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isGuest): ?>
        <div class="text-center">
            <a href="my-participations.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-check me-1"></i><?= h(t('checkin.my_participations')) ?>
            </a>
        </div>
        <?php endif; ?>
<?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
