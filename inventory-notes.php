<?php
/**
 * VolunteerOps - Inventory Notes Management (Admin)
 * Centralized view of all inventory notes/deficiencies.
 * Admin can filter, update status, resolve, and track issues.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$user = getCurrentUser();
$pageTitle = 'Σημειώσεις Υλικών';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // Update note status
    if ($action === 'update_status') {
        $noteId    = (int)post('note_id');
        $newStatus = post('new_status');
        $validStatuses = array_keys(NOTE_STATUS_LABELS);

        if (!in_array($newStatus, $validStatuses)) {
            setFlash('error', 'Μη έγκυρη κατάσταση.');
            redirect('inventory-notes.php');
        }

        $note = dbFetchOne("SELECT * FROM inventory_notes WHERE id = ?", [$noteId]);
        if (!$note) {
            setFlash('error', 'Η σημείωση δεν βρέθηκε.');
            redirect('inventory-notes.php');
        }

        // Build status history entry
        $history = !empty($note['status_history']) ? json_decode($note['status_history'], true) : [];
        $history[] = [
            'from'      => $note['status'],
            'to'        => $newStatus,
            'by'        => $user['name'],
            'by_id'     => $user['id'],
            'at'        => date('Y-m-d H:i:s'),
        ];

        $updateFields = [
            'status'         => $newStatus,
            'status_history' => json_encode($history, JSON_UNESCAPED_UNICODE),
        ];

        // If resolving, set resolution fields
        if ($newStatus === 'resolved') {
            $updateFields['resolved_at']         = date('Y-m-d H:i:s');
            $updateFields['resolved_by_user_id'] = $user['id'];
            $resNotes = trim(post('resolution_notes', ''));
            if ($resNotes) {
                $updateFields['resolution_notes'] = $resNotes;
            }
        }

        $setClauses = [];
        $params     = [];
        foreach ($updateFields as $field => $value) {
            $setClauses[] = "`{$field}` = ?";
            $params[]     = $value;
        }
        $params[] = $noteId;

        dbExecute(
            "UPDATE inventory_notes SET " . implode(', ', $setClauses) . " WHERE id = ?",
            $params
        );

        logAudit('inventory_note_status', 'inventory_notes', $noteId);
        setFlash('success', 'Η κατάσταση ενημερώθηκε σε: ' . NOTE_STATUS_LABELS[$newStatus]);
        redirect('inventory-notes.php?' . http_build_query(array_filter([
            'status'   => get('status'),
            'priority' => get('priority'),
            'type'     => get('type'),
            'page'     => get('page'),
        ])));
    }

    // Add resolution note
    if ($action === 'resolve') {
        $noteId         = (int)post('note_id');
        $resolutionNotes = trim(post('resolution_notes', ''));

        $note = dbFetchOne("SELECT * FROM inventory_notes WHERE id = ?", [$noteId]);
        if (!$note) {
            setFlash('error', 'Η σημείωση δεν βρέθηκε.');
            redirect('inventory-notes.php');
        }

        $history = !empty($note['status_history']) ? json_decode($note['status_history'], true) : [];
        $history[] = [
            'from'  => $note['status'],
            'to'    => 'resolved',
            'by'    => $user['name'],
            'by_id' => $user['id'],
            'at'    => date('Y-m-d H:i:s'),
            'notes' => $resolutionNotes,
        ];

        dbExecute("
            UPDATE inventory_notes 
            SET status = 'resolved',
                resolved_at = NOW(),
                resolved_by_user_id = ?,
                resolution_notes = ?,
                status_history = ?
            WHERE id = ?
        ", [$user['id'], $resolutionNotes, json_encode($history, JSON_UNESCAPED_UNICODE), $noteId]);

        logAudit('inventory_note_resolve', 'inventory_notes', $noteId);
        setFlash('success', 'Η σημείωση επιλύθηκε επιτυχώς.');
        redirect('inventory-notes.php?' . http_build_query(array_filter([
            'status'   => get('status'),
            'priority' => get('priority'),
            'type'     => get('type'),
        ])));
    }

    // Delete note
    if ($action === 'delete_note') {
        $noteId = (int)post('note_id');
        dbExecute("DELETE FROM inventory_notes WHERE id = ?", [$noteId]);
        logAudit('inventory_note_delete', 'inventory_notes', $noteId);
        setFlash('success', 'Η σημείωση διαγράφηκε.');
        redirect('inventory-notes.php');
    }
}

// Filters
$filterStatus   = get('status', '');
$filterPriority = get('priority', '');
$filterType     = get('type', '');
$filterSearch   = get('search', '');
$page           = max(1, (int)get('page', 1));
$perPage        = 20;

// Build query
$query  = "
    SELECT n.*, 
           i.barcode, i.name AS item_current_name, i.status AS item_status,
           u.name AS author_name,
           ru.name AS resolved_by_name
    FROM inventory_notes n
    LEFT JOIN inventory_items i ON n.item_id = i.id
    LEFT JOIN users u ON n.created_by_user_id = u.id
    LEFT JOIN users ru ON n.resolved_by_user_id = ru.id
    WHERE 1=1
";
$countQuery = "SELECT COUNT(*) FROM inventory_notes n WHERE 1=1";
$params      = [];
$countParams = [];

// Status filter
if ($filterStatus) {
    $query       .= " AND n.status = ?";
    $countQuery  .= " AND n.status = ?";
    $params[]     = $filterStatus;
    $countParams[] = $filterStatus;
}

// Priority filter
if ($filterPriority) {
    $query       .= " AND n.priority = ?";
    $countQuery  .= " AND n.priority = ?";
    $params[]     = $filterPriority;
    $countParams[] = $filterPriority;
}

// Type filter
if ($filterType) {
    $query       .= " AND n.note_type = ?";
    $countQuery  .= " AND n.note_type = ?";
    $params[]     = $filterType;
    $countParams[] = $filterType;
}

// Search
if ($filterSearch) {
    $query      .= " AND (n.content LIKE ? OR n.item_name LIKE ? OR i.barcode LIKE ?)";
    $countQuery .= " AND (n.content LIKE ? OR n.item_name LIKE ?)";
    $s = '%' . $filterSearch . '%';
    $params[]      = $s;
    $params[]      = $s;
    $params[]      = $s;
    $countParams[] = $s;
    $countParams[] = $s;
}

// Order: pending/urgent first, then by priority, then by date
$query .= " ORDER BY 
    CASE n.status 
        WHEN 'pending' THEN 1 
        WHEN 'acknowledged' THEN 2 
        WHEN 'in_progress' THEN 3 
        WHEN 'resolved' THEN 4 
        WHEN 'archived' THEN 5 
    END,
    CASE n.priority 
        WHEN 'urgent' THEN 1 
        WHEN 'high' THEN 2 
        WHEN 'medium' THEN 3 
        WHEN 'low' THEN 4 
    END,
    n.created_at DESC
";

$total      = (int)dbFetchValue($countQuery, $countParams);
$pagination = paginate($total, $page, $perPage);

$query .= " LIMIT " . (int)$pagination['per_page'] . " OFFSET " . (int)$pagination['offset'];

$notes = dbFetchAll($query, $params);

// Stats
$statsPending  = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_notes WHERE status IN ('pending','acknowledged')");
$statsProgress = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_notes WHERE status = 'in_progress'");
$statsResolved = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_notes WHERE status = 'resolved'");
$statsUrgent   = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_notes WHERE priority IN ('urgent','high') AND status NOT IN ('resolved','archived')");
$statsTotal    = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_notes");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-sticky me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Υλικά
    </a>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <a href="?status=pending" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $filterStatus === 'pending' ? 'border-warning border-2' : '' ?>">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning"><?= $statsPending ?></div>
                    <small class="text-muted">Εκκρεμείς</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <a href="?status=in_progress" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $filterStatus === 'in_progress' ? 'border-info border-2' : '' ?>">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-info"><?= $statsProgress ?></div>
                    <small class="text-muted">Σε Εξέλιξη</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <a href="?status=resolved" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $filterStatus === 'resolved' ? 'border-success border-2' : '' ?>">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-success"><?= $statsResolved ?></div>
                    <small class="text-muted">Επιλυμένες</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <a href="?priority=urgent" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 <?= $filterPriority === 'urgent' ? 'border-danger border-2' : '' ?>">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-danger"><?= $statsUrgent ?></div>
                    <small class="text-muted">Επείγοντα</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <a href="inventory-notes.php" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-dark"><?= $statsTotal ?></div>
                    <small class="text-muted">Σύνολο</small>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Αναζήτηση..." 
                       value="<?= h($filterSearch) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">Όλες οι καταστάσεις</option>
                    <?php foreach (NOTE_STATUS_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="priority">
                    <option value="">Όλες οι εν/κές</option>
                    <?php foreach (NOTE_PRIORITY_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterPriority === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select" name="type">
                    <option value="">Όλοι οι τύποι</option>
                    <?php foreach (NOTE_TYPE_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterType === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div class="col-md-2">
                <a href="inventory-notes.php" class="btn btn-outline-secondary w-100">Καθαρισμός</a>
            </div>
        </form>
    </div>
</div>

<!-- Notes List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Σημειώσεις (<?= $total ?>)</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($notes)): ?>
            <div class="text-center py-5">
                <i class="bi bi-sticky text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">Δεν βρέθηκαν σημειώσεις.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notes as $note): ?>
                <?php
                $borderColor = match($note['status']) {
                    'pending'      => 'border-warning',
                    'acknowledged' => 'border-info',
                    'in_progress'  => 'border-primary',
                    'resolved'     => 'border-success',
                    'archived'     => 'border-secondary',
                    default        => '',
                };
                $priorityIcon = match($note['priority']) {
                    'urgent' => '<i class="bi bi-exclamation-triangle-fill text-danger"></i>',
                    'high'   => '<i class="bi bi-exclamation-circle-fill text-warning"></i>',
                    'medium' => '<i class="bi bi-info-circle text-info"></i>',
                    'low'    => '<i class="bi bi-dash-circle text-secondary"></i>',
                    default  => '',
                };
                $noteTypeIcon = match($note['note_type']) {
                    'booking'     => '<i class="bi bi-box-arrow-right"></i>',
                    'return'      => '<i class="bi bi-box-arrow-in-left"></i>',
                    'maintenance' => '<i class="bi bi-wrench"></i>',
                    'damage'      => '<i class="bi bi-exclamation-diamond"></i>',
                    'general'     => '<i class="bi bi-chat-dots"></i>',
                    default       => '<i class="bi bi-chat"></i>',
                };
                ?>
                <div class="border-start border-4 <?= $borderColor ?> p-3 <?= $note !== end($notes) ? 'border-bottom' : '' ?>">
                    <div class="row">
                        <!-- Note Content -->
                        <div class="col-lg-7">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <?= $priorityIcon ?>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <a href="inventory-view.php?id=<?= $note['item_id'] ?>" class="fw-bold text-decoration-none">
                                                <?php if ($note['barcode']): ?>
                                                    <code class="text-primary me-1"><?= h($note['barcode']) ?></code>
                                                <?php endif; ?>
                                                <?= h($note['item_current_name'] ?? $note['item_name']) ?>
                                            </a>
                                        </div>
                                        <div class="d-flex gap-1">
                                            <?= notePriorityBadge($note['priority']) ?>
                                            <span class="badge bg-<?= match($note['status']) {
                                                'pending'      => 'warning text-dark',
                                                'acknowledged' => 'info',
                                                'in_progress'  => 'primary',
                                                'resolved'     => 'success',
                                                'archived'     => 'secondary',
                                                default        => 'secondary',
                                            } ?>"><?= h(NOTE_STATUS_LABELS[$note['status']] ?? $note['status']) ?></span>
                                        </div>
                                    </div>
                                    <p class="mb-1"><?= nl2br(h($note['content'])) ?></p>
                                    <small class="text-muted">
                                        <?= $noteTypeIcon ?> <?= h(NOTE_TYPE_LABELS[$note['note_type']] ?? $note['note_type']) ?>
                                        · <?= h($note['author_name'] ?? 'Σύστημα') ?>
                                        · <?= formatDateTime($note['created_at']) ?>
                                    </small>

                                    <?php if ($note['status'] === 'resolved' && $note['resolved_at']): ?>
                                        <div class="mt-2 p-2 bg-success bg-opacity-10 rounded">
                                            <small class="text-success">
                                                <i class="bi bi-check-circle-fill me-1"></i>
                                                Επιλύθηκε: <?= formatDateTime($note['resolved_at']) ?>
                                                <?php if ($note['resolved_by_name']): ?>
                                                    από <?= h($note['resolved_by_name']) ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php if ($note['resolution_notes']): ?>
                                                <br><small class="text-muted ms-3">→ <?= h($note['resolution_notes']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="col-lg-5">
                            <?php if ($note['status'] !== 'resolved' && $note['status'] !== 'archived'): ?>
                                <div class="d-flex flex-column gap-2">
                                    <!-- Quick Status Buttons -->
                                    <div class="d-flex gap-1 flex-wrap">
                                        <?php
                                        $nextStatuses = match($note['status']) {
                                            'pending'      => ['acknowledged' => 'info', 'in_progress' => 'primary'],
                                            'acknowledged' => ['in_progress' => 'primary'],
                                            'in_progress'  => [],
                                            default        => [],
                                        };
                                        foreach ($nextStatuses as $st => $color): ?>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $st ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-<?= $color ?>">
                                                    <?= h(NOTE_STATUS_LABELS[$st]) ?>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Resolve Form -->
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="resolve">
                                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control" name="resolution_notes" 
                                                   placeholder="Σχόλιο επίλυσης...">
                                            <button type="submit" class="btn btn-success" 
                                                    onclick="return confirm('Επιβεβαίωση επίλυσης;')">
                                                <i class="bi bi-check-lg me-1"></i>OK
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php else: ?>
                                <!-- Archive / Delete for resolved notes -->
                                <div class="d-flex gap-1">
                                    <?php if ($note['status'] === 'resolved'): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <input type="hidden" name="new_status" value="archived">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-archive me-1"></i>Αρχειοθέτηση
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (isSystemAdmin()): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Οριστική διαγραφή σημείωσης;')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_note">
                                            <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= paginationLinks($pagination, '?status=' . urlencode($filterStatus) . '&priority=' . urlencode($filterPriority) . '&type=' . urlencode($filterType) . '&search=' . urlencode($filterSearch) . '&') ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
