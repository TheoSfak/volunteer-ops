<?php
/**
 * VolunteerOps - Shifts List
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Βάρδιες';

// Filters
$missionId = get('mission_id', '');
$status = get('status', '');
$date = get('date', '');
$page = max(1, (int) get('page', 1));
$perPage = 20;

// Build query
$where = ['1=1'];
$params = [];

if ($missionId) {
    $where[] = 's.mission_id = ?';
    $params[] = $missionId;
}

if ($status) {
    $now = date('Y-m-d H:i:s');
    switch ($status) {
        case 'upcoming':
            $where[] = 's.start_time > ?';
            $params[] = $now;
            break;
        case 'active':
            $where[] = 's.start_time <= ? AND s.end_time >= ?';
            $params[] = $now;
            $params[] = $now;
            break;
        case 'past':
            $where[] = 's.end_time < ?';
            $params[] = $now;
            break;
    }
}

if ($date) {
    $where[] = 'DATE(s.start_time) = ?';
    $params[] = $date;
}

// Non-admins: only see shifts from open/closed missions or their own participations
if (!isAdmin()) {
    $where[] = "(m.status IN ('OPEN', 'CLOSED', 'COMPLETED') OR 
                EXISTS (SELECT 1 FROM participation_requests pr2 WHERE pr2.shift_id = s.id AND pr2.volunteer_id = ?))";
    $params[] = getCurrentUser()['id'];
}

// Τ.Ε.Π.: κρύψε βάρδιες Τ.Ε.Π. από απλούς εθελοντές
if (!canSeeTep()) {
    $where[] = '(m.mission_type_id != ? OR m.responsible_user_id = ?)';
    $params[] = getTepMissionTypeId();
    $params[] = getCurrentUser()['id'];
}

$whereClause = implode(' AND ', $where);

// Count total
$total = dbFetchValue(
    "SELECT COUNT(*) FROM shifts s JOIN missions m ON s.mission_id = m.id WHERE $whereClause",
    $params
);

$pagination = paginate($total, $page, $perPage);

// Get shifts (optimized with JOINs)
$shifts = dbFetchAll(
    "SELECT s.*, m.title as mission_title, m.status as mission_status,
            COALESCE(pr_approved.count, 0) as approved_count,
            COALESCE(pr_pending.count, 0) as pending_count
     FROM shifts s
     JOIN missions m ON s.mission_id = m.id
     LEFT JOIN (SELECT shift_id, COUNT(*) as count FROM participation_requests WHERE status = '" . PARTICIPATION_APPROVED . "' GROUP BY shift_id) pr_approved ON s.id = pr_approved.shift_id
     LEFT JOIN (SELECT shift_id, COUNT(*) as count FROM participation_requests WHERE status = '" . PARTICIPATION_PENDING . "' GROUP BY shift_id) pr_pending ON s.id = pr_pending.shift_id
     WHERE $whereClause
     ORDER BY s.start_time DESC
     LIMIT {$pagination['offset']}, {$pagination['per_page']}",
    $params
);

// Get missions for filter
$missions = dbFetchAll(
    "SELECT id, title FROM missions WHERE deleted_at IS NULL ORDER BY start_datetime DESC"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-clock me-2"></i>Βάρδιες
    </h1>
    <?php if (isAdmin()): ?>
    <a href="shift-form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Νέα Βάρδια
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Αποστολή</label>
                <select name="mission_id" class="form-select">
                    <option value="">Όλες</option>
                    <?php foreach ($missions as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $missionId == $m['id'] ? 'selected' : '' ?>>
                            <?= h($m['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Κατάσταση</label>
                <select name="status" class="form-select">
                    <option value="">Όλες</option>
                    <option value="upcoming" <?= $status === 'upcoming' ? 'selected' : '' ?>>Επερχόμενες</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Σε εξέλιξη</option>
                    <option value="past" <?= $status === 'past' ? 'selected' : '' ?>>Παρελθούσες</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Ημερομηνία</label>
                <input type="date" name="date" class="form-control" value="<?= h($date) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Αναζήτηση
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (empty($shifts)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν βρέθηκαν βάρδιες.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Βάρδια</th>
                    <th>Αποστολή</th>
                    <th>Χρόνος</th>
                    <th>Εθελοντές</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shifts as $shift): ?>
                    <?php
                    $now = time();
                    $start = strtotime($shift['start_time']);
                    $end = strtotime($shift['end_time']);
                    $isPast = $end < $now;
                    $isActive = $start <= $now && $end >= $now;
                    $isUpcoming = $start > $now;
                    ?>
                    <tr class="<?= $isPast ? 'table-secondary' : '' ?>">
                        <td>
                            <strong><?= h(($shift['title'] ?? '') ?: 'Βάρδια #' . $shift['id']) ?></strong>
                            <?php if (!empty($shift['description'])): ?>
                                <br><small class="text-muted"><?= h(mb_substr($shift['description'], 0, 50)) ?>...</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="mission-view.php?id=<?= $shift['mission_id'] ?>">
                                <?= h($shift['mission_title']) ?>
                            </a>
                            <br><?= statusBadge($shift['mission_status']) ?>
                        </td>
                        <td>
                            <i class="bi bi-calendar me-1"></i><?= formatDate($shift['start_time']) ?><br>
                            <small class="text-muted">
                                <?= date('H:i', $start) ?> - <?= date('H:i', $end) ?>
                                (<?= round(($end - $start) / 3600, 1) ?> ώρες)
                            </small>
                        </td>
                        <td>
                            <span class="badge bg-success"><?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?></span>
                            <?php if ($shift['pending_count'] > 0): ?>
                                <span class="badge bg-warning"><?= $shift['pending_count'] ?> εκκρεμεί</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge bg-success"><i class="bi bi-play-fill me-1"></i>Σε εξέλιξη</span>
                            <?php elseif ($isUpcoming): ?>
                                <span class="badge bg-primary"><i class="bi bi-clock me-1"></i>Επερχόμενη</span>
                            <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-check me-1"></i>Ολοκληρώθηκε</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (isAdmin()): ?>
                            <a href="shift-form.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?= paginationLinks($pagination) ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>