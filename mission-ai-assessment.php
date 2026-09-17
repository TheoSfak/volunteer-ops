<?php
/**
 * VolunteerOps — generate (or delete) the AI observer assessment for a
 * finished mission.
 *
 * On-demand only, modelled on mission-sector-coverage.php: expensive work
 * happens when someone asks for it, never on render and never on a poll. The
 * Action Room's 5-second poll must never reach anything in here — a
 * 20-second upstream call would hold one database connection per open tab.
 *
 * session_write_close() runs before the provider call for the same reason it
 * does in war-room.php's ajax branch: PHP's session file is locked for the
 * life of the request, so a 30-second AI call would otherwise freeze every
 * other request from the same admin's browser, including the page they are
 * staring at.
 *
 * POST only. Same permission gate as mission-stats.php itself, so this
 * endpoint can never widen who sees a mission's assessment.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ai-observer.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

$respond = function (bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(false, 'Μη έγκυρη μέθοδος.');
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    $respond(false, 'Μη έγκυρο αίτημα.');
}

$userId    = getCurrentUserId();
$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    $respond(false, 'Η αποστολή δεν βρέθηκε.');
}

$canManageMissions = hasPagePermission('missions_manage');
$isResponsible     = !empty($mission['responsible_user_id']) && (int) $mission['responsible_user_id'] === $userId;
if (!$canManageMissions && !$isResponsible) {
    $respond(false, 'Δεν έχετε δικαίωμα σε αυτή την ενέργεια.');
}
if (!in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true)) {
    $respond(false, 'Η ανάλυση είναι διαθέσιμη μόνο για κλειστές ή ολοκληρωμένες αποστολές.');
}

// ── delete ───────────────────────────────────────────────────────────────────
if (post('action') === 'delete') {
    dbExecute("DELETE FROM mission_ai_assessments WHERE mission_id = ?", [$missionId]);
    $respond(true, 'Η ανάλυση διαγράφηκε.');
}

// ── generate ─────────────────────────────────────────────────────────────────
if (!aiIsConfigured()) {
    $respond(false, 'Η τεχνητή νοημοσύνη δεν είναι ρυθμισμένη. Δείτε Ρυθμίσεις → Τεχνητή Νοημοσύνη.');
}

// The provider call is the slow part and it is bounded by aiChat()'s own
// timeout; this only stops PHP's own limit from firing first on a shared host
// where max_execution_time is 30.
@set_time_limit(240);

$report = computeMissionResponseReport($missionId);
$score  = computeMissionScore($missionId, $report);

// Everything that needed the session has been read. Release the lock before
// the upstream call, not after.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$result = generateMissionAiAssessment($missionId, $mission, $score, $report, $userId);

if (!$result['ok']) {
    $respond(false, $result['error'] ?? 'Η ανάλυση απέτυχε.');
}

$respond(true, 'Η ανάλυση ολοκληρώθηκε.', [
    'generated_at' => $result['assessment']['generated_at'] ?? null,
]);
