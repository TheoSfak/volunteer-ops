<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Προβολή Εργασίας';
$user = getCurrentUser();
$id = (int) get('id');

if (!$id) {
    setFlash('error', 'Δεν καθορίστηκε εργασία.');
    redirect('tasks.php');
}

$task = dbFetchOne("SELECT t.*, u.name as creator_name, r.name as responsible_name FROM tasks t LEFT JOIN users u ON t.created_by = u.id LEFT JOIN users r ON t.responsible_user_id = r.id WHERE t.id = ?", [$id]);

if (!$task) {
    setFlash('error', 'Η εργασία δεν βρέθηκε.');
    redirect('tasks.php');
}

// Check if user has access (admins or assigned members)
$isAssigned = dbFetchValue("SELECT COUNT(*) FROM task_assignments WHERE task_id = ? AND user_id = ?", [$id, $user['id']]);
$hasAccess = isAdmin() || $isAssigned;

if (!$hasAccess) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή την εργασία.');
    redirect('tasks.php');
}

// Get assigned users
$assignedUsers = dbFetchAll("SELECT u.* FROM users u INNER JOIN task_assignments ta ON u.id = ta.user_id WHERE ta.task_id = ? ORDER BY u.name", [$id]);

// Get subtasks
$subtasks = dbFetchAll("SELECT s.*, u.name as completed_by_name FROM subtasks s LEFT JOIN users u ON s.completed_by = u.id WHERE s.task_id = ? ORDER BY s.sort_order, s.created_at", [$id]);

// Get comments
$comments = dbFetchAll("SELECT c.*, u.name as user_name FROM task_comments c LEFT JOIN users u ON c.user_id = u.id WHERE c.task_id = ? ORDER BY c.created_at ASC", [$id]);

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'add_subtask':
            if (isAdmin()) {
                $subtaskTitle = post('subtask_title');
                if (!empty($subtaskTitle)) {
                    dbInsert("INSERT INTO subtasks (task_id, title) VALUES (?, ?)", [$id, $subtaskTitle]);
                    setFlash('success', 'Η υποεργασία προστέθηκε.');
                }
            }
            break;
            
        case 'toggle_subtask':
            $subtaskId = post('subtask_id');
            $subtask = dbFetchOne("SELECT * FROM subtasks WHERE id = ? AND task_id = ?", [$subtaskId, $id]);
            if ($subtask) {
                $newStatus = $subtask['is_completed'] ? 0 : 1;
                $completedAt = $newStatus ? 'NOW()' : 'NULL';
                $completedBy = $newStatus ? $user['id'] : 'NULL';
                dbExecute("UPDATE subtasks SET is_completed = ?, completed_at = $completedAt, completed_by = $completedBy WHERE id = ?", 
                         [$newStatus, $subtaskId]);
                
                // Send notification if subtask was completed (not uncompleted)
                if ($newStatus && isNotificationEnabled('task_subtask_completed')) {
                    // Notify responsible user
                    if ($task['responsible_user_id'] && $task['responsible_user_id'] != $user['id']) {
                        $responsible = dbFetchOne("SELECT * FROM users WHERE id = ?", [$task['responsible_user_id']]);
                        if ($responsible && $responsible['email']) {
                            sendNotificationEmail('task_subtask_completed', $responsible['email'], [
                                'user_name' => $responsible['name'],
                                'task_title' => $task['title'],
                                'subtask_title' => $subtask['title'],
                                'completed_by' => $user['name']
                            ]);
                            sendNotification($responsible['id'], 'Υποεργασία Ολοκληρώθηκε', "Ολοκληρώθηκε η υποεργασία '{$subtask['title']}' στην εργασία '{$task['title']}'");
                        }
                    }
                    
                    // Notify all assigned users except completer
                    $assignedUsers = dbFetchAll(
                        "SELECT u.* FROM users u INNER JOIN task_assignments ta ON u.id = ta.user_id WHERE ta.task_id = ? AND u.id != ?",
                        [$id, $user['id']]
                    );
                    foreach ($assignedUsers as $assignedUser) {
                        if ($assignedUser['email']) {
                            sendNotificationEmail('task_subtask_completed', $assignedUser['email'], [
                                'user_name' => $assignedUser['name'],
                                'task_title' => $task['title'],
                                'subtask_title' => $subtask['title'],
                                'completed_by' => $user['name']
                            ]);
                        }
                        sendNotification($assignedUser['id'], 'Υποεργασία Ολοκληρώθηκε', "Ολοκληρώθηκε η υποεργασία '{$subtask['title']}' στην εργασία '{$task['title']}'");
                    }
                    
                    // Notify creator if not already notified
                    $notifiedIds = array_column($assignedUsers, 'id');
                    if ($task['responsible_user_id']) $notifiedIds[] = $task['responsible_user_id'];
                    if ($task['created_by'] && !in_array($task['created_by'], $notifiedIds) && $task['created_by'] != $user['id']) {
                        $creator = dbFetchOne("SELECT * FROM users WHERE id = ?", [$task['created_by']]);
                        if ($creator && $creator['email']) {
                            sendNotificationEmail('task_subtask_completed', $creator['email'], [
                                'user_name' => $creator['name'],
                                'task_title' => $task['title'],
                                'subtask_title' => $subtask['title'],
                                'completed_by' => $user['name']
                            ]);
                        }
                        sendNotification($creator['id'], 'Υποεργασία Ολοκληρώθηκε', "Ολοκληρώθηκε η υποεργασία '{$subtask['title']}' στην εργασία '{$task['title']}'");
                    }
                }
                
                setFlash('success', 'Η υποεργασία ενημερώθηκε.');
            }
            break;
            
        case 'delete_subtask':
            if (isAdmin()) {
                $subtaskId = post('subtask_id');
                dbExecute("DELETE FROM subtasks WHERE id = ? AND task_id = ?", [$subtaskId, $id]);
                setFlash('success', 'Η υποεργασία διαγράφηκε.');
            }
            break;
            
        case 'add_comment':
            $comment = post('comment');
            if (!empty($comment)) {
                dbInsert("INSERT INTO task_comments (task_id, user_id, comment) VALUES (?, ?, ?)", [$id, $user['id'], $comment]);
                
                // Send notification to all assigned users (except commenter)
                if (isNotificationEnabled('task_comment')) {
                    $assignedUsers = dbFetchAll(
                        "SELECT u.* FROM users u INNER JOIN task_assignments ta ON u.id = ta.user_id WHERE ta.task_id = ? AND u.id != ?",
                        [$id, $user['id']]
                    );
                    foreach ($assignedUsers as $assignedUser) {
                        if ($assignedUser['email']) {
                            sendNotificationEmail('task_comment', $assignedUser['email'], [
                                'user_name' => $assignedUser['name'],
                                'task_title' => $task['title'],
                                'comment' => $comment,
                                'commented_by' => $user['name']
                            ]);
                        }
                        sendNotification($assignedUser['id'], 'Νέο Σχόλιο', "Νέο σχόλιο στην εργασία: {$task['title']}");
                    }
                    
                    // Also notify responsible user if exists
                    if ($task['responsible_user_id'] && $task['responsible_user_id'] != $user['id']) {
                        $responsible = dbFetchOne("SELECT * FROM users WHERE id = ?", [$task['responsible_user_id']]);
                        if ($responsible && $responsible['email']) {
                            sendNotificationEmail('task_comment', $responsible['email'], [
                                'user_name' => $responsible['name'],
                                'task_title' => $task['title'],
                                'comment' => $comment,
                                'commented_by' => $user['name']
                            ]);
                            sendNotification($responsible['id'], 'Νέο Σχόλιο', "Νέο σχόλιο στην εργασία: {$task['title']}");
                        }
                    }
                    
                    // Notify creator if not already notified
                    $notifiedCommentIds = array_column($assignedUsers, 'id');
                    if ($task['responsible_user_id']) $notifiedCommentIds[] = $task['responsible_user_id'];
                    if ($task['created_by'] && !in_array($task['created_by'], $notifiedCommentIds) && $task['created_by'] != $user['id']) {
                        $creator = dbFetchOne("SELECT * FROM users WHERE id = ?", [$task['created_by']]);
                        if ($creator && $creator['email']) {
                            sendNotificationEmail('task_comment', $creator['email'], [
                                'user_name' => $creator['name'],
                                'task_title' => $task['title'],
                                'comment' => $comment,
                                'commented_by' => $user['name']
                            ]);
                        }
                        sendNotification($creator['id'], 'Νέο Σχόλιο', "Νέο σχόλιο στην εργασία: {$task['title']}");
                    }
                }
                
                setFlash('success', 'Το σχόλιο προστέθηκε.');
            }
            break;
            
        case 'delete_task':
            if (isAdmin()) {
                dbExecute("DELETE FROM tasks WHERE id = ?", [$id]);
                logAudit('delete_task', 'tasks', $id);
                setFlash('success', 'Η εργασία διαγράφηκε.');
                redirect('tasks.php');
            }
            break;
    }
    
    redirect('task-view.php?id=' . $id);
}

$statusLabels = ['TODO' => 'Προς Εκτέλεση', 'IN_PROGRESS' => 'Σε Εξέλιξη', 'COMPLETED' => 'Ολοκληρωμένη', 'CANCELED' => 'Ακυρωμένη'];
$priorityLabels = ['LOW' => 'Χαμηλή', 'MEDIUM' => 'Μεσαία', 'HIGH' => 'Υψηλή', 'URGENT' => 'Επείγον'];
$priorityColors = ['LOW' => 'secondary', 'MEDIUM' => 'info', 'HIGH' => 'warning', 'URGENT' => 'danger'];
$statusColors = ['TODO' => 'secondary', 'IN_PROGRESS' => 'primary', 'COMPLETED' => 'success', 'CANCELED' => 'dark'];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-card-checklist me-2"></i><?= h($task['title']) ?>
    </h1>
    <div class="d-flex gap-2">
        <a href="tasks.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
        <?php if (isAdmin()): ?>
            <a href="task-form.php?id=<?= $id ?>" class="btn btn-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                <i class="bi bi-trash me-1"></i>Διαγραφή
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <!-- Left Column - Main Info -->
    <div class="col-lg-8">
        <!-- Task Details Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex gap-2 mb-3">
                    <span class="badge bg-<?= $priorityColors[$task['priority']] ?>">
                        <?= h($priorityLabels[$task['priority']]) ?>
                    </span>
                    <span class="badge bg-<?= $statusColors[$task['status']] ?>">
                        <?= h($statusLabels[$task['status']]) ?>
                    </span>
                </div>
                
                <?php
                // Progress bar color
                $progressColor = 'danger';
                if ($task['progress'] >= 100) $progressColor = 'success';
                elseif ($task['progress'] >= 75) $progressColor = 'primary';
                elseif ($task['progress'] >= 50) $progressColor = 'info';
                elseif ($task['progress'] >= 25) $progressColor = 'warning';
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <small class="text-muted">Πρόοδος</small>
                        <small class="text-muted"><strong><?= $task['progress'] ?>%</strong></small>
                    </div>
                    <div class="progress" style="height: 10px;">
                        <div class="progress-bar bg-<?= $progressColor ?>" role="progressbar" 
                             style="width: <?= $task['progress'] ?>%" 
                             aria-valuenow="<?= $task['progress'] ?>" 
                             aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                </div>
                
                <?php if ($task['description']): ?>
                    <h6 class="text-muted">Περιγραφή</h6>
                    <p class="mb-3"><?= nl2br(h($task['description'])) ?></p>
                <?php endif; ?>
                
                <div class="row text-muted small">
                    <div class="col-md-6">
                        <i class="bi bi-person me-1"></i><strong>Δημιουργήθηκε από:</strong> <?= h($task['creator_name']) ?>
                    </div>
                    <div class="col-md-6">
                        <i class="bi bi-calendar me-1"></i><strong>Ημ/νία:</strong> <?= formatDateTime($task['created_at']) ?>
                    </div>
                    <?php if ($task['responsible_user_id']): ?>
                        <div class="col-md-6 mt-2">
                            <i class="bi bi-star me-1 text-warning"></i><strong>Υπεύθυνος:</strong> 
                            <span class="badge bg-warning text-dark"><?= h($task['responsible_name']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($task['deadline']): ?>
                        <div class="col-md-6 mt-2">
                            <?php
                            $isOverdue = strtotime($task['deadline']) < time() && $task['status'] != 'COMPLETED';
                            ?>
                            <i class="bi bi-alarm me-1"></i><strong>Προθεσμία:</strong> 
                            <span class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>">
                                <?= formatDateTime($task['deadline']) ?>
                                <?= $isOverdue ? '(Καθυστερημένη!)' : '' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($task['completed_at']): ?>
                        <div class="col-md-6 mt-2">
                            <i class="bi bi-check-circle me-1"></i><strong>Ολοκληρώθηκε:</strong> <?= formatDateTime($task['completed_at']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Subtasks Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Υποεργασίες</h5>
                <?php if (isAdmin()): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSubtaskModal">
                        <i class="bi bi-plus"></i> Προσθήκη
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($subtasks)): ?>
                    <p class="text-muted text-center mb-0">Δεν υπάρχουν υποεργασίες.</p>
                <?php else: ?>
                    <?php
                    $completedCount = count(array_filter($subtasks, fn($s) => $s['is_completed']));
                    $totalCount = count($subtasks);
                    $progress = ($totalCount > 0) ? ($completedCount / $totalCount * 100) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small text-muted">Πρόοδος</span>
                            <span class="small fw-bold"><?= $completedCount ?>/<?= $totalCount ?> (<?= round($progress) ?>%)</span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-success" style="width: <?= $progress ?>%"></div>
                        </div>
                    </div>
                    
                    <ul class="list-group list-group-flush">
                        <?php foreach ($subtasks as $subtask): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <form method="post" class="d-flex align-items-center flex-grow-1">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_subtask">
                                    <input type="hidden" name="subtask_id" value="<?= $subtask['id'] ?>">
                                    <button type="submit" class="btn btn-link p-0 text-decoration-none me-2">
                                        <i class="bi bi-<?= $subtask['is_completed'] ? 'check-square-fill text-success' : 'square' ?> fs-5"></i>
                                    </button>
                                    <span class="<?= $subtask['is_completed'] ? 'text-decoration-line-through text-muted' : '' ?>">
                                        <?= h($subtask['title']) ?>
                                    </span>
                                </form>
                                <?php if (isAdmin()): ?>
                                    <form method="post" onsubmit="return confirm('Σίγουρα θέλετε να διαγράψετε αυτή την υποεργασία;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_subtask">
                                        <input type="hidden" name="subtask_id" value="<?= $subtask['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Comments Card -->
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Σχόλια</h5>
            </div>
            <div class="card-body">
                <?php if (empty($comments)): ?>
                    <p class="text-muted text-center">Δεν υπάρχουν σχόλια ακόμα.</p>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <div class="mb-3 pb-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-start">
                                <strong><?= h($comment['user_name']) ?></strong>
                                <small class="text-muted"><?= formatDateTime($comment['created_at']) ?></small>
                            </div>
                            <p class="mb-0 mt-2"><?= nl2br(h($comment['comment'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Add Comment Form -->
                <form method="post" class="mt-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_comment">
                    <div class="mb-3">
                        <label class="form-label">Προσθήκη Σχολίου</label>
                        <textarea class="form-control" name="comment" rows="3" required placeholder="Γράψτε το σχόλιό σας..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Αποστολή
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Right Column - Sidebar -->
    <div class="col-lg-4">
        <!-- Assigned Members Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Ανατεθειμένοι Εθελοντές</h6>
            </div>
            <div class="card-body">
                <?php if (empty($assignedUsers)): ?>
                    <p class="text-muted small mb-0">Δεν έχει ανατεθεί σε κανέναν.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($assignedUsers as $assignedUser): ?>
                            <li class="mb-2">
                                <i class="bi bi-person-circle me-2"></i>
                                <?= h($assignedUser['name']) ?>
                                <small class="text-muted">(<?= h($GLOBALS['ROLE_LABELS'][$assignedUser['role']]) ?>)</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Subtask Modal -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="addSubtaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_subtask">
                <div class="modal-header">
                    <h5 class="modal-title">Προσθήκη Υποεργασίας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Τίτλος</label>
                        <input type="text" class="form-control" name="subtask_title" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Προσθήκη</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Task Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_task">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Επιβεβαίωση Διαγραφής</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Είστε σίγουροι ότι θέλετε να διαγράψετε αυτή την εργασία;</p>
                    <p class="text-danger fw-bold">Θα διαγραφούν επίσης όλες οι υποεργασίες και τα σχόλια!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
