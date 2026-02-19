<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Φόρμα Εργασίας';
$user = getCurrentUser();

$id = (int) get('id');
$isEdit = !empty($id);
$task = null;

if ($isEdit) {
    $task = dbFetchOne("SELECT * FROM tasks WHERE id = ?", [$id]);
    if (!$task) {
        setFlash('error', 'Η εργασία δεν βρέθηκε.');
        redirect('tasks.php');
    }
    $pageTitle = 'Επεξεργασία Εργασίας';
}

// Get assigned users if editing
$assignedUsers = [];
if ($isEdit) {
    $assignedUsers = dbFetchAll("SELECT user_id FROM task_assignments WHERE task_id = ?", [$id]);
    $assignedUsers = array_column($assignedUsers, 'user_id');
}

// Get all active users for assignment
$allUsers = dbFetchAll(
    "SELECT id, name, role FROM users 
     WHERE is_active = 1 
     ORDER BY name"
);

// Priority labels for notifications
$priorityLabels = [
    'LOW' => 'Χαμηλή',
    'MEDIUM' => 'Μεσαία', 
    'HIGH' => 'Υψηλή',
    'URGENT' => 'Επείγουσα'
];

// Handle form submission
if (isPost()) {
    verifyCsrf();
    
    $title = post('title');
    $description = post('description', '');
    $priority = post('priority', 'MEDIUM');
    $status = post('status', 'TODO');
    $progress = (int)post('progress', 0);
    $deadline = post('deadline', '');
    $assignedTo = post('assigned_to', []);
    $responsibleUserId = post('responsible_user_id', '');
    
    // Validation
    $errors = [];
    if (empty($title)) {
        $errors[] = 'Ο τίτλος είναι υποχρεωτικός.';
    }
    
    if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])) {
        $errors[] = 'Μη έγκυρη προτεραιότητα.';
    }
    
    if (!in_array($status, ['TODO', 'IN_PROGRESS', 'COMPLETED', 'CANCELED'])) {
        $errors[] = 'Μη έγκυρη κατάσταση.';
    }
    
    if (!in_array($progress, [0, 25, 50, 75, 100])) {
        $errors[] = 'Μη έγκυρη πρόοδος.';
    }
    
    if (empty($errors)) {
        $deadlineValue = !empty($deadline) ? date('Y-m-d H:i:s', strtotime($deadline)) : null;
        
        if ($isEdit) {
            // Update task
            dbExecute(
                "UPDATE tasks SET title = ?, description = ?, priority = ?, status = ?, progress = ?, deadline = ?, responsible_user_id = ?, updated_at = NOW() WHERE id = ?",
                [$title, $description, $priority, $status, $progress, $deadlineValue, $responsibleUserId ?: null, $id]
            );
            
            // Update completed_at if status changed to COMPLETED
            if ($status === 'COMPLETED' && $task['status'] !== 'COMPLETED') {
                dbExecute("UPDATE tasks SET completed_at = NOW() WHERE id = ?", [$id]);
            } elseif ($status !== 'COMPLETED') {
                dbExecute("UPDATE tasks SET completed_at = NULL WHERE id = ?", [$id]);
            }
            
            // Send notification if status changed
            if ($status !== $task['status'] && isNotificationEnabled('task_status_changed')) {
                $statusLabels = ['TODO' => 'Προς Εκτέλεση', 'IN_PROGRESS' => 'Σε Εξέλιξη', 'COMPLETED' => 'Ολοκληρωμένη', 'CANCELED' => 'Ακυρωμένη'];
                $assignedUsers = dbFetchAll(
                    "SELECT u.* FROM users u INNER JOIN task_assignments ta ON u.id = ta.user_id WHERE ta.task_id = ?",
                    [$id]
                );
                foreach ($assignedUsers as $assignedUser) {
                    if ($assignedUser['email']) {
                        sendNotificationEmail('task_status_changed', $assignedUser['email'], [
                            'user_name' => $assignedUser['name'],
                            'task_title' => $title,
                            'old_status' => $statusLabels[$task['status']] ?? $task['status'],
                            'new_status' => $statusLabels[$status] ?? $status,
                            'changed_by' => $user['name']
                        ]);
                    }
                    sendNotification($assignedUser['id'], 'Αλλαγή Κατάστασης', "Η εργασία '{$title}' άλλαξε σε: {$statusLabels[$status]}");
                }
            }
            
            // Update assignments
            $oldAssignments = dbFetchAll("SELECT user_id FROM task_assignments WHERE task_id = ?", [$id]);
            $oldUserIds = array_column($oldAssignments, 'user_id');
            $newUserIds = array_diff($assignedTo, $oldUserIds);
            
            dbExecute("DELETE FROM task_assignments WHERE task_id = ?", [$id]);
            foreach ($assignedTo as $userId) {
                dbInsert(
                    "INSERT INTO task_assignments (task_id, user_id, assigned_by) VALUES (?, ?, ?)",
                    [$id, $userId, $user['id']]
                );
                
                // Send notification only to newly assigned users
                if (in_array($userId, $newUserIds) && isNotificationEnabled('task_assigned')) {
                    $assignedUser = dbFetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
                    if ($assignedUser && $assignedUser['email']) {
                        sendNotificationEmail('task_assigned', $assignedUser['email'], [
                            'user_name' => $assignedUser['name'],
                            'task_title' => $title,
                            'task_description' => $description ?: 'Χωρίς περιγραφή',
                            'task_priority' => $priorityLabels[$priority] ?? $priority,
                            'task_deadline' => $deadline ? formatDateTime($deadline) : 'Χωρίς προθεσμία',
                            'assigned_by' => $user['name']
                        ]);
                    }
                    sendNotification($userId, 'Νέα Εργασία', "Σας ανατέθηκε η εργασία: {$title}");
                }
            }
            
            logAudit('update_task', 'tasks', $id);
            setFlash('success', 'Η εργασία ενημερώθηκε επιτυχώς.');
            redirect('task-view.php?id=' . $id);
            
        } else {
            // Create new task
            $taskId = dbInsert(
                "INSERT INTO tasks (title, description, priority, status, progress, deadline, created_by, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$title, $description, $priority, $status, $progress, $deadlineValue, $user['id'], $responsibleUserId ?: null]
            );
            
            // Add assignments
            foreach ($assignedTo as $userId) {
                dbInsert(
                    "INSERT INTO task_assignments (task_id, user_id, assigned_by) VALUES (?, ?, ?)",
                    [$taskId, $userId, $user['id']]
                );
                
                // Send notification to assigned user
                if (isNotificationEnabled('task_assigned')) {
                    $assignedUser = dbFetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
                    if ($assignedUser && $assignedUser['email']) {
                        sendNotificationEmail('task_assigned', $assignedUser['email'], [
                            'user_name' => $assignedUser['name'],
                            'task_title' => $title,
                            'task_description' => $description ?: 'Χωρίς περιγραφή',
                            'task_priority' => $priorityLabels[$priority] ?? $priority,
                            'task_deadline' => $deadline ? formatDateTime($deadline) : 'Χωρίς προθεσμία',
                            'assigned_by' => $user['name']
                        ]);
                    }
                    sendNotification($userId, 'Νέα Εργασία', "Σας ανατέθηκε η εργασία: {$title}");
                }
            }
            
            logAudit('create_task', 'tasks', $taskId);
            setFlash('success', 'Η εργασία δημιουργήθηκε επιτυχώς.');
            redirect('task-view.php?id=' . $taskId);
        }
    } else {
        foreach ($errors as $error) {
            setFlash('error', $error);
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">
                    <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?> me-2"></i>
                    <?= h($pageTitle) ?>
                </h4>
            </div>
            <div class="card-body">
                <form method="post" id="taskForm">
                    <?= csrfField() ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Τίτλος <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" value="<?= h($task['title'] ?? '') ?>" required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="description" rows="4"><?= h($task['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Προτεραιότητα</label>
                            <select class="form-select" name="priority">
                                <option value="LOW" <?= ($task['priority'] ?? '') === 'LOW' ? 'selected' : '' ?>>Χαμηλή</option>
                                <option value="MEDIUM" <?= ($task['priority'] ?? 'MEDIUM') === 'MEDIUM' ? 'selected' : '' ?>>Μεσαία</option>
                                <option value="HIGH" <?= ($task['priority'] ?? '') === 'HIGH' ? 'selected' : '' ?>>Υψηλή</option>
                                <option value="URGENT" <?= ($task['priority'] ?? '') === 'URGENT' ? 'selected' : '' ?>>Επείγον</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Κατάσταση</label>
                            <select class="form-select" name="status">
                                <option value="TODO" <?= ($task['status'] ?? 'TODO') === 'TODO' ? 'selected' : '' ?>>Προς Εκτέλεση</option>
                                <option value="IN_PROGRESS" <?= ($task['status'] ?? '') === 'IN_PROGRESS' ? 'selected' : '' ?>>Σε Εξέλιξη</option>
                                <option value="COMPLETED" <?= ($task['status'] ?? '') === 'COMPLETED' ? 'selected' : '' ?>>Ολοκληρωμένη</option>
                                <option value="CANCELED" <?= ($task['status'] ?? '') === 'CANCELED' ? 'selected' : '' ?>>Ακυρωμένη</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Πρόοδος</label>
                            <select class="form-select" name="progress">
                                <option value="0" <?= ($task['progress'] ?? 0) == 0 ? 'selected' : '' ?>>0% - Δεν ξεκίνησε</option>
                                <option value="25" <?= ($task['progress'] ?? 0) == 25 ? 'selected' : '' ?>>25% - Αρχικό στάδιο</option>
                                <option value="50" <?= ($task['progress'] ?? 0) == 50 ? 'selected' : '' ?>>50% - Στη μέση</option>
                                <option value="75" <?= ($task['progress'] ?? 0) == 75 ? 'selected' : '' ?>>75% - Κοντά στην ολοκλήρωση</option>
                                <option value="100" <?= ($task['progress'] ?? 0) == 100 ? 'selected' : '' ?>>100% - Ολοκληρωμένη</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Προθεσμία</label>
                        <input type="datetime-local" class="form-control" name="deadline" 
                               value="<?= $task['deadline'] ? date('Y-m-d\TH:i', strtotime($task['deadline'])) : '' ?>">
                        <small class="text-muted">Προαιρετικό - Αφήστε κενό για εργασία χωρίς προθεσμία</small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Ανάθεση σε Εθελοντές</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="selectedVolunteersDisplay" 
                                   placeholder="Πατήστε για επιλογή εθελοντών..." readonly 
                                   style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#volunteerModal">
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#volunteerModal">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div id="selectedVolunteersList" class="mt-2"></div>
                        <small class="text-muted">Αναζητήστε και επιλέξτε εθελοντές - Μπορείτε να επιλέξετε πολλαπλούς</small>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Υπεύθυνος Εργασίας</label>
                        <select class="form-select" name="responsible_user_id" id="responsibleUser">
                            <option value="">Χωρίς υπεύθυνο</option>
                        </select>
                        <small class="text-muted">Επιλέξτε έναν υπεύθυνο από τους ανατεθειμένους εθελοντές</small>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle me-1"></i>
                            <?= $isEdit ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <a href="<?= $isEdit ? 'task-view.php?id=' . $id : 'tasks.php' ?>" class="btn btn-secondary">
                            <i class="bi bi-x-circle me-1"></i>Ακύρωση
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Volunteer Selection Modal (outside form, using form attribute) -->
<div class="modal fade" id="volunteerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Επιλογή Εθελοντών</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="volunteerSearch" placeholder="Αναζήτηση εθελοντή...">
                </div>
                <div class="list-group" id="volunteerList" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($allUsers as $u): ?>
                        <label class="list-group-item list-group-item-action" style="cursor: pointer;">
                            <div class="d-flex align-items-center">
                                <input class="form-check-input me-2 volunteer-checkbox" type="checkbox" 
                                       form="taskForm"
                                       name="assigned_to[]" value="<?= $u['id'] ?>" 
                                       data-name="<?= h($u['name']) ?>"
                                       data-role="<?= h($GLOBALS['ROLE_LABELS'][$u['role']]) ?>"
                                       <?= in_array($u['id'], $assignedUsers) ? 'checked' : '' ?>>
                                <div>
                                    <strong><?= h($u['name']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= h($GLOBALS['ROLE_LABELS'][$u['role']]) ?></small>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
            </div>
        </div>
    </div>
</div>
            </div>
        </div>
    </div>
</div>

<script>
// Selected volunteers management
const selectedVolunteers = new Set(<?= json_encode($assignedUsers) ?>);
const initialResponsible = <?= json_encode($task['responsible_user_id'] ?? '') ?>;
const allUsersData = <?php 
    $roleLabels = $GLOBALS['ROLE_LABELS'];
    echo json_encode(array_map(function($u) use ($roleLabels) {
        return ['id' => $u['id'], 'name' => $u['name'], 'role' => $roleLabels[$u['role']]];
    }, $allUsers)); 
?>;

function updateResponsibleDropdown() {
    const responsibleSelect = document.getElementById('responsibleUser');
    const currentValue = responsibleSelect.value;
    
    // Clear options except first
    responsibleSelect.innerHTML = '<option value="">Χωρίς υπεύθυνο</option>';
    
    // Add options for selected volunteers only
    const checked = document.querySelectorAll('.volunteer-checkbox:checked');
    checked.forEach(cb => {
        const option = document.createElement('option');
        option.value = cb.value;
        option.textContent = cb.dataset.name + ' (' + cb.dataset.role + ')';
        if (cb.value == currentValue || cb.value == initialResponsible) {
            option.selected = true;
        }
        responsibleSelect.appendChild(option);
    });
}

function updateSelectedDisplay() {
    const display = document.getElementById('selectedVolunteersDisplay');
    const list = document.getElementById('selectedVolunteersList');
    
    const checked = document.querySelectorAll('.volunteer-checkbox:checked');
    
    if (checked.length === 0) {
        display.value = 'Πατήστε για επιλογή εθελοντών...';
        list.innerHTML = '';
    } else {
        display.value = checked.length + ' εθελοντής/ές επιλεγμένος/οι';
        list.innerHTML = '';
        
        checked.forEach(cb => {
            const badge = document.createElement('span');
            badge.className = 'badge bg-primary me-2 mb-2';
            badge.innerHTML = cb.dataset.name + ' <i class="bi bi-x-circle ms-1" style="cursor:pointer;" onclick="removeVolunteer(' + cb.value + ')"></i>';
            list.appendChild(badge);
        });
    }
    
    updateResponsibleDropdown();
}

function removeVolunteer(id) {
    const checkbox = document.querySelector('.volunteer-checkbox[value="' + id + '"]');
    if (checkbox) {
        checkbox.checked = false;
        updateSelectedDisplay();
    }
}

// Search functionality
document.getElementById('volunteerSearch').addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    document.querySelectorAll('#volunteerList label').forEach(label => {
        const text = label.textContent.toLowerCase();
        label.style.display = text.includes(search) ? '' : 'none';
    });
});

// Update display on checkbox change
document.querySelectorAll('.volunteer-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedDisplay);
});

// Initial display update
updateSelectedDisplay();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
