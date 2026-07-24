<?php
/**
 * VolunteerOps - Guest Mission Debrief
 * Self-service feedback form for guest/external-org accounts on a joint-
 * exercise mission they were an approved participant of, once it's closed
 * or completed. Deliberately separate from mission_debriefs (the single
 * admin/responsible-only official record) — a guest can only ever see and
 * edit their OWN submission here; command staff read all submissions from
 * a read-only section on mission-debrief.php instead of this page.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

if (!isExternalGuest()) {
    setFlash('error', t('guest_debrief.access_denied'));
    redirect('missions.php');
}

$currentUser = getCurrentUser();
$userId = (int) $currentUser['id'];
$missionId = (int) get('mission_id');

$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', t('guest_debrief.not_eligible'));
    redirect('missions.php');
}

$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$isApprovedParticipant || !in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true)) {
    setFlash('error', t('guest_debrief.not_eligible'));
    redirect('missions.php');
}

$existing = dbFetchOne("SELECT * FROM mission_guest_debriefs WHERE mission_id = ? AND user_id = ?", [$missionId, $userId]);
$isEdit = $existing !== null;

if (isPost()) {
    verifyCsrf();

    $rating = (int) post('rating');
    $wentWell = trim((string) post('what_went_well'));
    $couldImprove = trim((string) post('what_could_improve'));
    $notes = trim((string) post('additional_notes'));

    if ($rating < 1 || $rating > 5) {
        setFlash('error', t('guest_debrief.err_rating'));
    } else {
        if ($isEdit) {
            dbExecute(
                "UPDATE mission_guest_debriefs
                 SET rating = ?, what_went_well = ?, what_could_improve = ?, additional_notes = ?, updated_at = NOW()
                 WHERE id = ?",
                [$rating, $wentWell ?: null, $couldImprove ?: null, $notes ?: null, $existing['id']]
            );
            logAudit('edit_guest_debrief', 'mission_guest_debriefs', $existing['id']);
        } else {
            $newId = dbInsert(
                "INSERT INTO mission_guest_debriefs (mission_id, user_id, rating, what_went_well, what_could_improve, additional_notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [$missionId, $userId, $rating, $wentWell ?: null, $couldImprove ?: null, $notes ?: null]
            );
            logAudit('submit_guest_debrief', 'mission_guest_debriefs', $newId);
            notifyCommandStaffGuestDebriefSubmitted(
                $missionId,
                $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null,
                $userId,
                $currentUser['name'],
                $mission['title']
            );
        }
        setFlash('success', t('guest_debrief.saved'));
        redirect('profile.php');
    }
}

$pageTitle = ($isEdit ? t('guest_debrief.page_title_edit') : t('guest_debrief.page_title_new')) . ' — ' . $mission['title'];
include __DIR__ . '/includes/header.php';
?>

<div class="row">
<div class="col-lg-7 mx-auto">
    <a href="profile.php" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i><?= t('guest_debrief.back_to_profile') ?>
    </a>
    <h1 class="h4 mb-1"><i class="bi bi-chat-square-text text-success me-2"></i><?= $isEdit ? t('guest_debrief.page_title_edit') : t('guest_debrief.page_title_new') ?></h1>
    <p class="text-muted mb-3"><?= h($mission['title']) ?></p>

    <div class="card">
        <div class="card-body">
            <p class="text-muted"><?= t('guest_debrief.intro') ?></p>
            <form method="post">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= t('guest_debrief.label_rating') ?> <span class="text-danger">*</span></label>
                    <select name="rating" class="form-select" required style="max-width:160px;">
                        <option value="">—</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= (int) ($existing['rating'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?> / 5</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= t('guest_debrief.label_what_went_well') ?></label>
                    <textarea name="what_went_well" class="form-control" rows="3"><?= h($existing['what_went_well'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= t('guest_debrief.label_what_could_improve') ?></label>
                    <textarea name="what_could_improve" class="form-control" rows="3"><?= h($existing['what_could_improve'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><?= t('guest_debrief.label_notes') ?></label>
                    <textarea name="additional_notes" class="form-control" rows="3"><?= h($existing['additional_notes'] ?? '') ?></textarea>
                </div>
                <div class="d-flex justify-content-between">
                    <a href="profile.php" class="btn btn-outline-secondary"><?= t('guest_debrief.cancel_btn') ?></a>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i><?= $isEdit ? t('guest_debrief.update_btn') : t('guest_debrief.submit_btn') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
