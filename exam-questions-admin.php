<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$examId = get('exam_id');
if (empty($examId)) {
    setFlash('error', 'Μη έγκυρο διαγώνισμα.');
    redirect('exam-admin.php');
}

// Fetch exam
$exam = dbFetchOne("
    SELECT te.*, tc.name as category_name
    FROM training_exams te
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE te.id = ?
", [$examId]);

if (!$exam) {
    setFlash('error', 'Το διαγώνισμα δεν βρέθηκε.');
    redirect('exam-admin.php');
}

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'add_question') {
        $type = post('question_type');
        $text = post('question_text');
        $explanation = post('explanation');
        
        $optionA = post('option_a');
        $optionB = post('option_b');
        $optionC = post('option_c');
        $optionD = post('option_d');
        $correctOption = post('correct_option');
        
        $newId = dbInsert("
            INSERT INTO training_exam_questions 
            (exam_id, category_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$examId, $exam['category_id'], $type, $text, $optionA, $optionB, $optionC, $optionD, $correctOption, $explanation]);
        
        logAudit('create', 'training_exam_questions', $newId);
        setFlash('success', 'Η ερώτηση προστέθηκε.');
        redirect('exam-questions-admin.php?exam_id=' . $examId);
        
    } elseif ($action === 'delete_question') {
        $id = post('id');
        dbExecute("DELETE FROM training_exam_questions WHERE id = ?", [$id]);
        logAudit('delete', 'training_exam_questions', $id);
        setFlash('success', 'Η ερώτηση διαγράφηκε.');
        redirect('exam-questions-admin.php?exam_id=' . $examId);
    }
}

// Fetch questions
$questions = dbFetchAll("
    SELECT * FROM training_exam_questions 
    WHERE exam_id = ? 
    ORDER BY id
", [$examId]);

$pageTitle = 'Ερωτήσεις: ' . h($exam['title']);
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="bi bi-question-circle me-2"></i><?= h($exam['title']) ?>
            </h1>
            <p class="text-muted mb-0">
                <?= h($exam['category_name']) ?> | <?= count($questions) ?> ερωτήσεις (Χρησιμ: <?= $exam['questions_per_attempt'] ?>)
            </p>
        </div>
        <a href="exam-admin.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Επιστροφή
        </a>
    </div>
    
    <!-- Pool Mode Banner -->
    <?php if (!empty($exam['use_random_pool'])): ?>
        <div class="alert alert-primary mb-4">
            <i class="bi bi-shuffle me-2"></i>
            <strong>Τυχαία επιλογή από pool είναι ενεργή.</strong>
            Κατά την έναρξη του διαγωνίσματος, ο συστημζ θα επιλέξει αυτόματα
            <strong><?= $exam['questions_per_attempt'] ?> τυχαίες ερωτήσεις</strong> από
            <em>όλες τις ερωτήσεις της κατηγορίας "<?= h($exam['category_name']) ?>"</em>.
            Μπορείτε να προσθέσετε ερωτήσεις εδώ ή από το <a href="questions-pool.php">σύνολο pool ερωτήσεων</a> — θα συμπεριληφθούν άμεσα.
        </div>
    <?php endif; ?>

    <!-- Add Question Button -->
    <div class="card mb-4">
        <div class="card-body text-center">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
                <i class="bi bi-plus-lg"></i> Προσθήκη Ερώτησης
            </button>
        </div>
    </div>
    
    <!-- Questions List -->
    <?php if (empty($questions)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> Δεν υπάρχουν ερωτήσεις. Προσθέστε τουλάχιστον <?= $exam['questions_per_attempt'] ?> ερωτήσεις.
        </div>
    <?php else: ?>
        <?php foreach ($questions as $index => $q): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <span class="badge bg-secondary me-2">#<?= $index + 1 ?></span>
                        <?= questionTypeBadge($q['question_type']) ?>
                    </div>
                    <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή ερώτησης;');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_question">
                        <input type="hidden" name="id" value="<?= $q['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">
                            <i class="bi bi-trash"></i> Διαγραφή
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <h5 class="mb-3"><?= nl2br(h($q['question_text'])) ?></h5>
                    
                    <?php if ($q['question_type'] === QUESTION_TYPE_MC): ?>
                        <!-- Multiple Choice -->
                        <div class="mb-2 <?= $q['correct_option'] === 'A' ? 'text-success fw-bold' : '' ?>">
                            A. <?= h($q['option_a']) ?> <?= $q['correct_option'] === 'A' ? '✓' : '' ?>
                        </div>
                        <div class="mb-2 <?= $q['correct_option'] === 'B' ? 'text-success fw-bold' : '' ?>">
                            B. <?= h($q['option_b']) ?> <?= $q['correct_option'] === 'B' ? '✓' : '' ?>
                        </div>
                        <div class="mb-2 <?= $q['correct_option'] === 'C' ? 'text-success fw-bold' : '' ?>">
                            C. <?= h($q['option_c']) ?> <?= $q['correct_option'] === 'C' ? '✓' : '' ?>
                        </div>
                        <div class="mb-2 <?= $q['correct_option'] === 'D' ? 'text-success fw-bold' : '' ?>">
                            D. <?= h($q['option_d']) ?> <?= $q['correct_option'] === 'D' ? '✓' : '' ?>
                        </div>
                    
                    <?php elseif ($q['question_type'] === QUESTION_TYPE_TF): ?>
                        <!-- True/False -->
                        <div class="mb-2 <?= $q['correct_option'] === 'T' ? 'text-success fw-bold' : '' ?>">
                            Σωστό <?= $q['correct_option'] === 'T' ? '✓' : '' ?>
                        </div>
                        <div class="mb-2 <?= $q['correct_option'] === 'F' ? 'text-success fw-bold' : '' ?>">
                            Λάθος <?= $q['correct_option'] === 'F' ? '✓' : '' ?>
                        </div>
                    
                    <?php elseif ($q['question_type'] === QUESTION_TYPE_OPEN): ?>
                        <!-- Open Ended -->
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Ανοιχτή ερώτηση - Απαιτεί χειροκίνητη αξιολόγηση
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($q['explanation'])): ?>
                        <div class="alert alert-light mt-3">
                            <strong><i class="bi bi-lightbulb"></i> Επεξήγηση:</strong>
                            <p class="mb-0 mt-2"><?= nl2br(h($q['explanation'])) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Add Question Modal -->
<div class="modal fade" id="addQuestionModal">
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
                            <option value="<?= QUESTION_TYPE_MC ?>">Πολλαπλής Επιλογής (4 επιλογές, 1 σωστή)</option>
                            <option value="<?= QUESTION_TYPE_TF ?>">Σωστό / Λάθος</option>
                            <option value="<?= QUESTION_TYPE_OPEN ?>">Ανοιχτή Ερώτηση (χειροκίνητη βαθμολόγηση)</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Κείμενο Ερώτησης *</label>
                        <textarea name="question_text" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <!-- Multiple Choice Options -->
                    <div id="mcOptions" style="display: none;">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i> <strong>Πολλαπλή Επιλογή:</strong> Συμπληρώστε 4 πιθανές απαντήσεις. Ο εθελοντής θα επιλέξει μία.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή A</label>
                            <input type="text" name="option_a" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή B</label>
                            <input type="text" name="option_b" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή C</label>
                            <input type="text" name="option_c" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Επιλογή D</label>
                            <input type="text" name="option_d" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση (MC)</label>
                            <select name="correct_option" id="correctOptionMC" class="form-select">
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
                            <label class="form-label">Σωστή Απάντηση (T/F)</label>
                            <select name="correct_option_tf" id="correctOptionTF" class="form-select">
                                <option value="T">Σωστό</option>
                                <option value="F">Λάθος</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Open-ended Note -->
                    <div id="openNote" style="display: none;">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> <strong>Ανοιχτή Ερώτηση:</strong> Οι εθελοντές θα γράψουν ελεύθερο κείμενο. <strong>Απαιτείται χειροκίνητη βαθμολόγηση</strong> από τον διαχειριστή.
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Επεξήγηση (προαιρετικό)</label>
                        <textarea name="explanation" class="form-control" rows="2"></textarea>
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

<script>
document.getElementById('questionType').addEventListener('change', function() {
    const type = this.value;
    const mcOptions = document.getElementById('mcOptions');
    const tfOptions = document.getElementById('tfOptions');
    const openNote = document.getElementById('openNote');
    const correctOptionMC = document.getElementById('correctOptionMC');
    const correctOptionTF = document.getElementById('correctOptionTF');
    
    if (type === '<?= QUESTION_TYPE_MC ?>') {
        mcOptions.style.display = 'block';
        tfOptions.style.display = 'none';
        openNote.style.display = 'none';
        correctOptionMC.name = 'correct_option';
        correctOptionTF.name = '';
    } else if (type === '<?= QUESTION_TYPE_TF ?>') {
        mcOptions.style.display = 'none';
        tfOptions.style.display = 'block';
        openNote.style.display = 'none';
        correctOptionMC.name = '';
        correctOptionTF.name = 'correct_option';
    } else {
        mcOptions.style.display = 'none';
        tfOptions.style.display = 'none';
        openNote.style.display = type === '<?= QUESTION_TYPE_OPEN ?>' ? 'block' : 'none';
        correctOptionMC.name = '';
        correctOptionTF.name = '';
    }
});

// Trigger on page load to show correct fields
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('questionType').dispatchEvent(new Event('change'));
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
