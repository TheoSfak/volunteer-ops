<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = get('id');
$isEdit = !empty($id);
$quiz = null;

if ($isEdit) {
    $quiz = dbFetchOne("SELECT * FROM training_quizzes WHERE id = ?", [$id]);
    if (!$quiz) {
        setFlash('error', 'Το κουίζ δεν βρέθηκε.');
        redirect('exam-admin.php?tab=quizzes');
    }
}

if (isPost()) {
    verifyCsrf();
    
    $title = post('title');
    $description = post('description');
    $categoryId = post('category_id');
    $timeLimit = post('time_limit_minutes');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $errors = [];
    if (empty($title)) $errors[] = 'Το πεδίο τίτλος είναι υποχρεωτικό.';
    if (empty($categoryId)) $errors[] = 'Επιλέξτε κατηγορία.';
    
    if (empty($errors)) {
        if ($isEdit) {
            dbExecute("
                UPDATE training_quizzes 
                SET title = ?, description = ?, category_id = ?, time_limit_minutes = ?, is_active = ?, updated_at = NOW()
                WHERE id = ?
            ", [$title, $description, $categoryId, $timeLimit, $isActive, $id]);
            logAudit('update', 'training_quizzes', $id);
            setFlash('success', 'Το κουίζ ενημερώθηκε.');
        } else {
            $newId = dbInsert("
                INSERT INTO training_quizzes (title, description, category_id, time_limit_minutes, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$title, $description, $categoryId, $timeLimit, $isActive, getCurrentUserId()]);
            logAudit('create', 'training_quizzes', $newId);
            setFlash('success', 'Το κουίζ δημιουργήθηκε.');
            redirect('quiz-questions-admin.php?quiz_id=' . $newId);
        }
        redirect('exam-admin.php?tab=quizzes');
    } else {
        foreach ($errors as $error) {
            setFlash('error', $error);
        }
    }
}

$categories = dbFetchAll("SELECT * FROM training_categories WHERE is_active = 1 ORDER BY display_order, name");

$pageTitle = $isEdit ? 'Επεξεργασία Κουίζ' : 'Νέο Κουίζ';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-puzzle me-2"></i><?= $pageTitle ?>
        </h1>
        <a href="exam-admin.php?tab=quizzes" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Επιστροφή
        </a>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form method="post">
                        <?= csrfField() ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Τίτλος *</label>
                            <input type="text" name="title" class="form-control" 
                                   value="<?= h($quiz['title'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Περιγραφή</label>
                            <textarea name="description" class="form-control" rows="4"><?= h($quiz['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Κατηγορία *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Επιλέξτε --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= (($quiz['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>>
                                        <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Όριο Χρόνου (λεπτά)</label>
                            <input type="number" name="time_limit_minutes" class="form-control" min="0" 
                                   value="<?= h($quiz['time_limit_minutes'] ?? '') ?>">
                            <small class="text-muted">Αφήστε κενό για απεριόριστο χρόνο</small>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                   <?= ($quiz['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Ενεργό (ορατό σε εθελοντές)
                            </label>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Αποθήκευση
                            </button>
                            <a href="exam-admin.php?tab=quizzes" class="btn btn-secondary">
                                Ακύρωση
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <?php if ($isEdit): ?>
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Επόμενα Βήματα</h6>
                    </div>
                    <div class="card-body">
                        <p>Αφού αποθηκεύσετε τις ρυθμίσεις:</p>
                        <a href="quiz-questions-admin.php?quiz_id=<?= $id ?>" class="btn btn-info w-100 mb-2">
                            <i class="bi bi-question-circle"></i> Διαχείριση Ερωτήσεων
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
