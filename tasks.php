<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Διαχείριση Εργασιών';
$user = getCurrentUser();

// Filters
$status = get('status', '');
$assignedToMe = get('assigned_to_me', '');
$page = max(1, (int)get('page', 1));
$perPage = 20;

// Build query
$where = ['1=1'];
$params = [];

if ($status) {
    $where[] = 't.status = ?';
    $params[] = $status;
}

// Show tasks assigned to current user if filter is on
if ($assignedToMe) {
    $where[] = 'EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
    $params[] = $user['id'];
}

// Non-admins see only their assigned tasks
if (!isAdmin()) {
    $where[] = 'EXISTS (SELECT 1 FROM task_assignments ta WHERE ta.task_id = t.id AND ta.user_id = ?)';
    $params[] = $user['id'];
}

$whereClause = implode(' AND ', $where);

// Count total
$total = dbFetchValue("SELECT COUNT(*) FROM tasks t WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

// Fetch tasks with assignment count and subtask progress (optimized with JOINs)
$tasks = dbFetchAll(
    "SELECT t.*,
            u.name as creator_name,
            COALESCE(ta.assigned_count, 0) as assigned_count,
            COALESCE(st.subtasks_total, 0) as subtasks_total,
            COALESCE(st.subtasks_completed, 0) as subtasks_completed,
            COALESCE(tc.comments_count, 0) as comments_count
     FROM tasks t
     LEFT JOIN users u ON t.created_by = u.id
     LEFT JOIN (SELECT task_id, COUNT(*) as assigned_count FROM task_assignments GROUP BY task_id) ta ON t.id = ta.task_id
     LEFT JOIN (SELECT task_id, COUNT(*) as subtasks_total, SUM(is_completed) as subtasks_completed FROM subtasks GROUP BY task_id) st ON t.id = st.task_id
     LEFT JOIN (SELECT task_id, COUNT(*) as comments_count FROM task_comments GROUP BY task_id) tc ON t.id = tc.task_id
     WHERE $whereClause
     ORDER BY 
        CASE t.status 
            WHEN 'TODO' THEN 1 
            WHEN 'IN_PROGRESS' THEN 2 
            WHEN 'COMPLETED' THEN 3 
            WHEN 'CANCELED' THEN 4 
        END,
        FIELD(t.priority, 'URGENT', 'HIGH', 'MEDIUM', 'LOW'),
        t.deadline ASC,
        t.created_at DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

// Status and Priority labels
$statusLabels = [
    'TODO' => 'Προς Εκτέλεση',
    'IN_PROGRESS' => 'Σε Εξέλιξη',
    'COMPLETED' => 'Ολοκληρωμένη',
    'CANCELED' => 'Ακυρωμένη'
];

$priorityLabels = [
    'LOW' => 'Χαμηλή',
    'MEDIUM' => 'Μεσαία',
    'HIGH' => 'Υψηλή',
    'URGENT' => 'Επείγον'
];

$priorityColors = [
    'LOW' => 'secondary',
    'MEDIUM' => 'info',
    'HIGH' => 'warning',
    'URGENT' => 'danger'
];

$statusColors = [
    'TODO' => 'secondary',
    'IN_PROGRESS' => 'primary',
    'COMPLETED' => 'success',
    'CANCELED' => 'dark'
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-list-task me-2"></i>Εργασίες
    </h1>
    <?php if (isAdmin()): ?>
        <a href="task-form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέα Εργασία
        </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Κατάσταση</label>
                <select class="form-select" name="status">
                    <option value="">Όλες</option>
                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Ανατεθειμένες σε μένα</label>
                <select class="form-select" name="assigned_to_me">
                    <option value="">Όλες</option>
                    <option value="1" <?= $assignedToMe ? 'selected' : '' ?>>Μόνο οι δικές μου</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-funnel me-1"></i>Φίλτρο
                </button>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <a href="tasks.php" class="btn btn-outline-secondary w-100">Καθαρισμός</a>
            </div>
        </form>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <?php
    $stats = [
        'TODO' => dbFetchValue("SELECT COUNT(*) FROM tasks WHERE status = 'TODO'"),
        'IN_PROGRESS' => dbFetchValue("SELECT COUNT(*) FROM tasks WHERE status = 'IN_PROGRESS'"),
        'COMPLETED' => dbFetchValue("SELECT COUNT(*) FROM tasks WHERE status = 'COMPLETED'")
    ];
    ?>
    <div class="col-md-4">
        <div class="card border-secondary">
            <div class="card-body">
                <h5 class="text-secondary">Προς Εκτέλεση</h5>
                <h2 class="mb-0"><?= $stats['TODO'] ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-body">
                <h5 class="text-primary">Σε Εξέλιξη</h5>
                <h2 class="mb-0"><?= $stats['IN_PROGRESS'] ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body">
                <h5 class="text-success">Ολοκληρωμένες</h5>
                <h2 class="mb-0"><?= $stats['COMPLETED'] ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- Tasks List -->
<?php if (empty($tasks)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
            <p class="text-muted mt-3">Δεν βρέθηκαν εργασίες.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($tasks as $task): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 shadow-sm hover-shadow">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="badge bg-<?= $priorityColors[$task['priority']] ?>">
                                <?= h($priorityLabels[$task['priority']]) ?>
                            </span>
                            <span class="badge bg-<?= $statusColors[$task['status']] ?>">
                                <?= h($statusLabels[$task['status']]) ?>
                            </span>
                        </div>
                        
                        <h5 class="card-title">
                            <a href="task-view.php?id=<?= $task['id'] ?>" class="text-decoration-none text-dark">
                                <?= h($task['title']) ?>
                            </a>
                        </h5>
                        
                        <?php if ($task['description']): ?>
                            <p class="card-text text-muted small">
                                <?= h(mb_substr($task['description'], 0, 100)) ?><?= mb_strlen($task['description']) > 100 ? '...' : '' ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Progress bar -->
                        <div class="mb-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted">Πρόοδος</small>
                                <small class="fw-bold"><?= $task['progress'] ?? 0 ?>%</small>
                            </div>
                            <?php 
                            $progress = $task['progress'] ?? 0;
                            $progressColor = $progress == 0 ? 'danger' : ($progress == 25 ? 'warning' : ($progress == 50 ? 'info' : ($progress == 75 ? 'primary' : 'success')));
                            ?>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-<?= $progressColor ?>" style="width: <?= $progress ?>%"></div>
                            </div>
                        </div>
                        
                        <!-- Progress bar for subtasks -->
                        <?php if ($task['subtasks_total'] > 0): ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small class="text-muted">Υποεργασίες</small>
                                    <small class="text-muted"><?= $task['subtasks_completed'] ?>/<?= $task['subtasks_total'] ?></small>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?= $task['subtasks_total'] > 0 ? ($task['subtasks_completed'] / $task['subtasks_total'] * 100) : 0 ?>%"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="text-muted small">
                                <?php if ($task['deadline']): ?>
                                    <i class="bi bi-calendar-event me-1"></i>
                                    <?php
                                    $deadline = strtotime($task['deadline']);
                                    $isOverdue = $deadline < time() && $task['status'] != 'COMPLETED';
                                    ?>
                                    <span class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>">
                                        <?= formatDate($task['deadline']) ?>
                                    </span>
                                <?php else: ?>
                                    <i class="bi bi-calendar-x me-1"></i>
                                    Χωρίς προθεσμία
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge bg-light text-dark" title="Ανατεθειμένοι">
                                    <i class="bi bi-people"></i> <?= $task['assigned_count'] ?>
                                </span>
                                <span class="badge bg-light text-dark" title="Σχόλια">
                                    <i class="bi bi-chat"></i> <?= $task['comments_count'] ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent">
                        <small class="text-muted">
                            Δημιουργήθηκε από <?= h($task['creator_name']) ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $pagination['total_pages']; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&status=<?= h($status) ?>&assigned_to_me=<?= h($assignedToMe) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<style>
.hover-shadow {
    transition: box-shadow 0.3s ease;
}
.hover-shadow:hover {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
