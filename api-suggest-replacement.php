<?php
/**
 * AJAX endpoint — read-only list of active volunteers NOT currently deployed
 * on this mission, for an admin to manually call in as a replacement for a
 * flagged (long-continuous-shift) volunteer. POST only, login + Action Room
 * admin required. Deliberately separate from shift_swap_requests
 * (shift-swap.php, my-participations.php, shift-view.php's
 * approve_swap/reject_swap) — that flow is volunteer-initiated and requires
 * the shift to not have started yet; this is admin-initiated for someone
 * already mid-shift, and never writes anything (no swap row, no
 * notification, no shift reassignment) — it only returns a phone list for
 * the admin to act on manually.
 * Returns JSON: { volunteers: [{id, name, phone}, ...] } on success or { error } on failure.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Μη έγκυρη μέθοδος']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['error' => 'Μη έγκυρο αίτημα']);
    exit;
}

$missionId = (int) post('mission_id');
$volunteerId = (int) post('volunteer_id'); // the flagged volunteer this suggestion is for — context + self-exclusion only
if (!$missionId) {
    echo json_encode(['error' => 'Άγνωστη αποστολή']);
    exit;
}

$mission = dbFetchOne("SELECT id, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    echo json_encode(['error' => 'Άγνωστη αποστολή']);
    exit;
}

$user = getCurrentUser();
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id'])) {
    echo json_encode(['error' => 'Δεν έχετε δικαίωμα πρόσβασης']);
    exit;
}

// Same "currently on duty" computation the fatigue badge itself uses —
// nobody already mid-chain elsewhere on this mission gets suggested. Guests
// (is_external) excluded too — they're normally scoped to their own approved
// mission(s) only, so surfacing one as a call-in for an unrelated mission
// isn't operationally meaningful even though harmless to expose.
$onDutyVolunteerIds = array_keys(computeContinuousFieldMinutesByVolunteerId($missionId));
$excludeIds = array_values(array_unique(array_merge($onDutyVolunteerIds, [$volunteerId])));
$placeholders = implode(',', array_fill(0, count($excludeIds), '?'));

$volunteers = dbFetchAll(
    "SELECT u.id, u.name, u.phone FROM users u
     WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.is_external = 0
       AND u.id NOT IN ($placeholders)
     ORDER BY u.name ASC",
    $excludeIds
);

// Audit trail — this endpoint hands out phone numbers of active volunteers
// app-wide (not just this mission's roster) to anyone canManageActionRoom()
// admits, which includes a mission's sole responsible_user_id without any
// sitewide permission.
logAudit('suggest_replacement_viewed', 'missions', $missionId, 'for volunteer_id=' . $volunteerId);

echo json_encode(['volunteers' => array_map(fn($v) => [
    'id' => (int) $v['id'],
    'name' => $v['name'],
    'phone' => $v['phone'],
], $volunteers)]);
