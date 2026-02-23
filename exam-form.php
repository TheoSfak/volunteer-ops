<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$id = (int) get('id');
$isEdit = !empty($id);
$exam = null;

if ($isEdit) {
    $exam = dbFetchOne("SELECT * FROM training_exams WHERE id = ?", [$id]);
    if (!$exam) {
        setFlash('error', 'Το διαγώνισμα δεν βρέθηκε.');
        redirect('exam-admin.php');
    }
}

// Handle form submission
if (isPost()) {
    verifyCsrf();
    
    $title = post('title');
    $description = post('description');
    $categoryId = post('category_id');
    $questionsPerAttempt = post('questions_per_attempt');
    $passingPercentage = post('passing_percentage');
    $timeLimit = post('time_limit_minutes');
    $maxAttempts = max(1, (int) post('max_attempts', 1));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    $errors = [];
    if (empty($title)) $errors[] = 'Το πεδίο τίτλος είναι υποχρεωτικό.';
    if (empty($categoryId)) $errors[] = 'Επιλέξτε κατηγορία.';
    if ($questionsPerAttempt < 1) $errors[] = 'Ο αριθμός ερωτήσεων πρέπει να είναι τουλάχιστον 1.';
    if ($passingPercentage < 0 || $passingPercentage > 100) $errors[] = 'Το όριο επιτυχίας πρέπει να είναι 0-100%.';
    
    if (empty($errors)) {
        if ($isEdit) {
            dbExecute("
                UPDATE training_exams 
                SET title = ?, description = ?, category_id = ?, questions_per_attempt = ?, 
                    passing_percentage = ?, time_limit_minutes = ?, max_attempts = ?, is_active = ?,
                    updated_at = NOW()
                WHERE id = ?
            ", [$title, $description, $categoryId, $questionsPerAttempt, $passingPercentage, $timeLimit, $maxAttempts, $isActive, $id]);
            logAudit('update', 'training_exams', $id);
            setFlash('success', 'Το διαγώνισμα ενημερώθηκε.');
        } else {
            $newId = dbInsert("
                INSERT INTO training_exams (title, description, category_id, questions_per_attempt, 
                    passing_percentage, time_limit_minutes, max_attempts, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [$title, $description, $categoryId, $questionsPerAttempt, $passingPercentage, $timeLimit, $maxAttempts, $isActive, getCurrentUserId()]);
            logAudit('create', 'training_exams', $newId);
            setFlash('success', 'Το διαγώνισμα δημιουργήθηκε.');
            redirect('exam-questions-admin.php?exam_id=' . $newId);
        }
        redirect('exam-admin.php');
    } else {
        foreach ($errors as $error) {
            setFlash('error', $error);
        }
    }
}

$categories = dbFetchAll("SELECT * FROM training_categories WHERE is_active = 1 ORDER BY display_order, name");

$pageTitle = $isEdit ? 'Επεξεργασία Διαγωνίσματος' : 'Νέο Διαγώνισμα';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-award me-2"></i><?= $pageTitle ?>
        </h1>
        <a href="exam-admin.php" class="btn btn-outline-secondary">
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
                                   value="<?= h($exam['title'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Περιγραφή</label>
                            <textarea name="description" class="form-control" rows="4"><?= h($exam['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Κατηγορία *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">-- Επιλέξτε --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= (($exam['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>>
                                        <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ερωτήσεις ανά Προσπάθεια *</label>
                                <input type="number" name="questions_per_attempt" class="form-control" min="1" 
                                       value="<?= h($exam['questions_per_attempt'] ?? 10) ?>" required>
                                <small class="text-muted">Τυχαίες ερωτήσεις από το pool</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Όριο Επιτυχίας (%) *</label>
                                <input type="number" name="passing_percentage" class="form-control" min="0" max="100" 
                                       value="<?= h($exam['passing_percentage'] ?? 70) ?>" required>
                                <small class="text-muted">Ελάχιστο % για επιτυχία</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Χρονικό Όριο (λεπτά) <small class="text-muted">— Διάρκεια countdown κατά την εκκίνηση</small></label>
                            <input type="number" name="time_limit_minutes" class="form-control" min="1" 
                                   value="<?= h($exam['time_limit_minutes'] ?? '') ?>" placeholder="π.χ. 30">
                            <small class="text-muted">Απαιτείται για να ξεκινήσετε διαγώνισμα με Countdown. Αφήστε κενό για απεριόριστο.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Μέγιστος Αριθμός Προσπαθειών *</label>
                            <input type="number" name="max_attempts" class="form-control" min="1" max="10"
                                   value="<?= h($exam['max_attempts'] ?? 1) ?>" required>
                            <small class="text-muted">Πόσες φορές μπορεί να δώσει ο εθελοντής αυτό το διαγώνισμα (1 = μία μόνο φορά)</small>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                   <?= ($exam['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Ενεργό (ορατό σε εθελοντές)
                            </label>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Αποθήκευση
                            </button>
                            <a href="exam-admin.php" class="btn btn-secondary">
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
                        <a href="exam-questions-admin.php?exam_id=<?= $id ?>" class="btn btn-info w-100 mb-2">
                            <i class="bi bi-question-circle"></i> Διαχείριση Ερωτήσεων
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
