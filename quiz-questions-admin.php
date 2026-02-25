<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$quizId = get('quiz_id');
if (empty($quizId)) {
    setFlash('error', 'Δεν βρέθηκε το κουίζ.');
    redirect('exam-admin.php?tab=quizzes');
}

$quiz = dbFetchOne("
    SELECT q.*, c.name as category_name 
    FROM training_quizzes q
    JOIN training_categories c ON q.category_id = c.id
    WHERE q.id = ?
", [$quizId]);

if (!$quiz) {
    setFlash('error', 'Το κουίζ δεν βρέθηκε.');
    redirect('exam-admin.php?tab=quizzes');
}

if (isPost()) {
    verifyCsrf();
    
    $action = post('action');
    
    if ($action === 'add_question') {
        $questionText = post('question_text');
        $questionType = post('question_type');
        $explanation = post('explanation');
        
        // Get correct_option from the right field based on question type
        if ($questionType === QUESTION_TYPE_TF) {
            $correctOption = post('correct_option_tf');
        } else {
            $correctOption = post('correct_option');
        }
        
        $errors = [];
        if (empty($questionText)) $errors[] = 'Το πεδίο ερώτηση είναι υποχρεωτικό.';
        if (empty($questionType)) $errors[] = 'Επιλέξτε τύπο ερώτησης.';
        
        if ($questionType === QUESTION_TYPE_MC) {
            $optionA = post('option_a');
            $optionB = post('option_b');
            $optionC = post('option_c');
            $optionD = post('option_d');
            
            if (empty($optionA) || empty($optionB) || empty($optionC) || empty($optionD)) {
                $errors[] = 'Συμπληρώστε όλες τις επιλογές για πολλαπλής επιλογής.';
            }
            if (empty($correctOption) || !in_array($correctOption, ['A', 'B', 'C', 'D'])) {
                $errors[] = 'Επιλέξτε τη σωστή απάντηση (A, B, C ή D).';
            }
        } elseif ($questionType === QUESTION_TYPE_TF) {
            if (!in_array($correctOption, ['T', 'F'])) {
                $errors[] = 'Επιλέξτε τη σωστή απάντηση (Σωστό ή Λάθος).';
            }
        }
        
        if (empty($errors)) {
            $insertData = [
                $quizId,
                $quiz['category_id'],
                $questionText,
                $questionType,
                $correctOption,
                $questionType === QUESTION_TYPE_MC ? post('option_a') : null,
                $questionType === QUESTION_TYPE_MC ? post('option_b') : null,
                $questionType === QUESTION_TYPE_MC ? post('option_c') : null,
                $questionType === QUESTION_TYPE_MC ? post('option_d') : null,
                $explanation
            ];
            
            $newId = dbInsert("
                INSERT INTO training_quiz_questions 
                (quiz_id, category_id, question_text, question_type, correct_option, option_a, option_b, option_c, option_d, explanation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", $insertData);
            
            logAudit('create', 'training_quiz_questions', $newId);
            setFlash('success', 'Η ερώτηση προστέθηκε.');
        } else {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        }
        redirect('quiz-questions-admin.php?quiz_id=' . $quizId);
    }
    
    if ($action === 'delete_question') {
        $questionId = post('question_id');
        dbExecute("DELETE FROM training_quiz_questions WHERE id = ? AND quiz_id = ?", [$questionId, $quizId]);
        logAudit('delete', 'training_quiz_questions', $questionId);
        setFlash('success', 'Η ερώτηση διαγράφηκε.');
        redirect('quiz-questions-admin.php?quiz_id=' . $quizId);
    }
}

$questions = dbFetchAll("SELECT * FROM training_quiz_questions WHERE quiz_id = ? ORDER BY id", [$quizId]);
$questionCount = count($questions);

$pageTitle = 'Ερωτήσεις Κουίζ';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-question-circle me-2"></i><?= h($quiz['title']) ?>
            </h1>
            <p class="text-muted mb-0">
                <i class="bi bi-folder"></i> <?= h($quiz['category_name']) ?> 
                <span class="mx-2">•</span>
                <?= $questionCount ?> ερωτήσεις
            </p>
        </div>
        <div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                <i class="bi bi-plus-circle"></i> Νέα Ερώτηση
            </button>
            <a href="exam-admin.php?tab=quizzes" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Επιστροφή
            </a>
        </div>
    </div>
    
    <?php if ($questionCount === 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> Δεν υπάρχουν ερωτήσεις ακόμα. Προσθέστε την πρώτη ερώτηση!
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($questions as $idx => $q): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="badge bg-secondary">#<?= $idx + 1 ?></span>
                                        <?= questionTypeBadge($q['question_type']) ?>
                                    </div>
                                    <h6><?= h($q['question_text']) ?></h6>
                                    
                                    <?php if ($q['question_type'] === QUESTION_TYPE_MC): ?>
                                        <ul class="list-unstyled mb-0 mt-2">
                                            <li class="<?= $q['correct_option'] === 'A' ? 'text-success fw-bold' : '' ?>">
                                                A. <?= h($q['option_a']) ?>
                                            </li>
                                            <li class="<?= $q['correct_option'] === 'B' ? 'text-success fw-bold' : '' ?>">
                                                B. <?= h($q['option_b']) ?>
                                            </li>
                                            <li class="<?= $q['correct_option'] === 'C' ? 'text-success fw-bold' : '' ?>">
                                                C. <?= h($q['option_c']) ?>
                                            </li>
                                            <li class="<?= $q['correct_option'] === 'D' ? 'text-success fw-bold' : '' ?>">
                                                D. <?= h($q['option_d']) ?>
                                            </li>
                                        </ul>
                                    <?php elseif ($q['question_type'] === QUESTION_TYPE_TF): ?>
                                        <p class="mb-0 text-success fw-bold">
                                            <i class="bi bi-check-circle"></i> 
                                            Σωστή απάντηση: <?= normalizeTfOption($q['correct_option']) === 'T' ? 'Σωστό' : 'Λάθος' ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($q['explanation'])): ?>
                                        <div class="mt-2 text-muted small">
                                            <i class="bi bi-lightbulb"></i> <strong>Επεξήγηση:</strong> <?= h($q['explanation']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ms-3">
                                    <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $q['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add Question Modal -->
<div class="modal fade" id="addQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_question">
                
                <div class="modal-header">
                    <h5 class="modal-title">Νέα Ερώτηση</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Τύπος Ερώτησης *</label>
                        <select name="question_type" id="questionType" class="form-select" required>
                            <option value="">-- Επιλέξτε --</option>
                            <option value="<?= QUESTION_TYPE_MC ?>">Πολλαπλής Επιλογής (4 επιλογές)</option>
                            <option value="<?= QUESTION_TYPE_TF ?>">Σωστό / Λάθος</option>
                        </select>
                        <small class="text-muted">Τα κουίζ υποστηρίζουν μόνο αυτόματα βαθμολογούμενες ερωτήσεις</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Ερώτηση *</label>
                        <textarea name="question_text" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <!-- Multiple Choice Options -->
                    <div id="mcOptions" style="display: none;">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i> <strong>Πολλαπλή Επιλογή:</strong> Συμπληρώστε 4 πιθανές απαντήσεις. Ο εθελοντής θα επιλέξει μία.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή A *</label>
                            <input type="text" name="option_a" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή B *</label>
                            <input type="text" name="option_b" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή C *</label>
                            <input type="text" name="option_c" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή D *</label>
                            <input type="text" name="option_d" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση *</label>
                            <select name="correct_option" class="form-select">
                                <option value="">-- Επιλέξτε --</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- True/False Options -->
                    <div id="tfOptions" style="display: none;">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i> <strong>Σωστό/Λάθος:</strong> Ο εθελοντής θα επιλέξει αν η πρόταση είναι σωστή ή λανθασμένη.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση *</label>
                            <select name="correct_option_tf" id="correctOptionTf" class="form-select">
                                <option value="">-- Επιλέξτε --</option>
                                <option value="T">Σωστό</option>
                                <option value="F">Λάθος</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Επεξήγηση (προαιρετικό)</label>
                        <textarea name="explanation" class="form-control" rows="2" 
                                  placeholder="Εμφανίζεται μετά την υποβολή για να εξηγήσει τη σωστή απάντηση"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Προσθήκη
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="deleteForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_question">
                <input type="hidden" name="question_id" id="deleteQuestionId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Επιβεβαίωση Διαγραφής</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <p>Είστε σίγουροι ότι θέλετε να διαγράψετε αυτήν την ερώτηση;</p>
                    <p class="text-danger mb-0">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Η ενέργεια αυτή δεν μπορεί να αναιρεθεί.
                    </p>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Show/hide question type specific fields
document.getElementById('questionType').addEventListener('change', function() {
    const type = this.value;
    const isMC = type === '<?= QUESTION_TYPE_MC ?>';
    const isTF = type === '<?= QUESTION_TYPE_TF ?>';
    document.getElementById('mcOptions').style.display = isMC ? 'block' : 'none';
    document.getElementById('tfOptions').style.display = isTF ? 'block' : 'none';
    // Sync correct_option for TF: copy TF value into a hidden input
    // MC select keeps name="correct_option", TF has name="correct_option_tf"
});

// Trigger on page load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('questionType').dispatchEvent(new Event('change'));
});

function confirmDelete(questionId) {
    document.getElementById('deleteQuestionId').value = questionId;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
