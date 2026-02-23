<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$examId = get('id');
$user = getCurrentUser();
$userId = $user['id'];

// Exams are only for trainee rescuers
if (!isAdmin() && !isTraineeRescuer($user)) {
    setFlash('error', 'Τα διαγωνίσματα είναι διαθέσιμα μόνο για Δόκιμους Διασώστες.');
    redirect('training.php');
}

if (empty($examId)) {
    setFlash('error', 'Μη έγκυρο διαγώνισμα.');
    redirect('training-exams.php');
}

// Fetch exam
$exam = dbFetchOne("
    SELECT te.*, tc.name as category_name
    FROM training_exams te
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE te.id = ? AND te.is_active = 1
", [$examId]);

if (!$exam) {
    setFlash('error', 'Το διαγώνισμα δεν βρέθηκε ή δεν είναι ενεργό.');
    redirect('training-exams.php');
}

// Handle form submission FIRST (before cleanup to preserve the attempt being submitted)
if (isPost()) {
    verifyCsrf();
    
    $attemptId = post('attempt_id');
    $startTime = post('start_time');
    
    // Get the selected questions from the attempt
    $attempt = dbFetchOne("SELECT selected_questions_json FROM exam_attempts WHERE id = ?", [$attemptId]);
    
    if (!$attempt) {
        setFlash('error', 'Η προσπάθεια διαγωνίσματος δεν βρέθηκε.');
        redirect('training-exams.php');
    }
    
    $selectedQuestionIds = json_decode($attempt['selected_questions_json'], true);
    
    // Fetch questions
    $placeholders = str_repeat('?,', count($selectedQuestionIds) - 1) . '?';
    $questions = dbFetchAll("
        SELECT * FROM training_exam_questions 
        WHERE id IN ($placeholders)
    ", $selectedQuestionIds);
    
    $score = 0;
    $totalQuestions = count($questions);
    
    foreach ($questions as $question) {
        $userAnswer = null;
        $isCorrect = 0;
        
        if ($question['question_type'] === QUESTION_TYPE_MC || $question['question_type'] === QUESTION_TYPE_TF) {
            $userAnswer = post('question_' . $question['id'], '');
            $isCorrect = ($userAnswer === $question['correct_option']) ? 1 : 0;
        } elseif ($question['question_type'] === QUESTION_TYPE_OPEN) {
            $userAnswer = post('question_' . $question['id'], '');
            $isCorrect = null; // Requires manual grading
        }
        
        if ($isCorrect) {
            $score++;
        }
        
        // Save answer
        dbInsert("
            INSERT INTO user_answers (attempt_id, attempt_type, question_id, selected_option, answer_text, is_correct)
            VALUES (?, 'EXAM', ?, ?, ?, ?)
        ", [$attemptId, $question['id'], $userAnswer, $userAnswer, $isCorrect]);
    }
    
    // Calculate pass/fail
    $percentage = $totalQuestions > 0 ? round(($score / $totalQuestions) * 100, 2) : 0;
    $passed = ($percentage >= $exam['passing_percentage']) ? 1 : 0;
    
    // Update attempt with final score
    $timeTaken = time() - $startTime;
    dbExecute("
        UPDATE exam_attempts 
        SET score = ?, total_questions = ?, passed = ?, completed_at = NOW(), time_taken_seconds = ?
        WHERE id = ?
    ", [$score, $totalQuestions, $passed, $timeTaken, $attemptId]);
    
    // Update user progress if passed
    if ($passed) {
        incrementExamsPassed($userId, $exam['category_id']);
    }
    
    // Redirect to results
    redirect('exam-results.php?attempt_id=' . $attemptId);
}

// Clean up any incomplete attempts (only when NOT submitting - to allow viewing the exam)
dbExecute("DELETE FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND completed_at IS NULL", [$examId, $userId]);

// Check if user has already completed this exam
$completedAttempt = dbFetchOne("SELECT id FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND completed_at IS NOT NULL", [$examId, $userId]);
if ($completedAttempt) {
    setFlash('error', 'Έχετε ήδη ολοκληρώσει αυτό το διαγώνισμα.');
    redirect('training-exams.php');
}

// Check exam availability
$availability = isExamAvailable($exam);
if (!$availability['available']) {
    setFlash('error', $availability['message']);
    redirect('training-exams.php');
}

// Initialize exam attempt - select random questions
if (!isset($_SESSION['exam_attempt_' . $examId])) {
    // Get random questions (from pool or exam-specific)
    if (!empty($exam['use_random_pool'])) {
        $questions = getRandomPoolQuestions($exam['category_id'], $exam['questions_per_attempt']);
    } else {
        $questions = getRandomExamQuestions($examId, $exam['questions_per_attempt']);
    }
    
    if (count($questions) < $exam['questions_per_attempt']) {
        setFlash('error', 'Δεν υπάρχουν αρκετές ερωτήσεις για αυτό το διαγώνισμα.');
        redirect('training-exams.php');
    }
    
    $selectedQuestionIds = array_column($questions, 'id');
    
    // Create attempt record
    $attemptId = dbInsert("
        INSERT INTO exam_attempts (exam_id, user_id, selected_questions_json, passing_percentage, started_at)
        VALUES (?, ?, ?, ?, NOW())
    ", [$examId, $userId, json_encode($selectedQuestionIds), $exam['passing_percentage']]);
    
    // Store in session
    $_SESSION['exam_attempt_' . $examId] = [
        'attempt_id' => $attemptId,
        'question_ids' => $selectedQuestionIds,
        'start_time' => time()
    ];
} else {
    // Resume existing attempt
    $attemptData = $_SESSION['exam_attempt_' . $examId];
    $attemptId = $attemptData['attempt_id'];
    $selectedQuestionIds = $attemptData['question_ids'];
}

// Fetch selected questions (shuffled order per user)
$placeholders = str_repeat('?,', count($selectedQuestionIds) - 1) . '?';
$questions = dbFetchAll("
    SELECT * FROM training_exam_questions 
    WHERE id IN ($placeholders)
", $selectedQuestionIds);
shuffle($questions);

$pageTitle = h($exam['title']);
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Exam Header -->
            <div class="card mb-4 bg-warning text-dark">
                <div class="card-body">
                    <h3 class="mb-1"><?= h($exam['title']) ?></h3>
                    <p class="mb-0"><?= h($exam['category_name']) ?> | Όριο Επιτυχίας: <?= $exam['passing_percentage'] ?>%</p>
                </div>
            </div>
            
            <!-- Warning Alert -->
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Προσοχή:</strong> Αυτό είναι επίσημο διαγώνισμα. Μπορείτε να το κάνετε μόνο μία φορά. 
                Βεβαιωθείτε ότι έχετε αρκετό χρόνο πριν ξεκινήσετε.
            </div>
            
            <!-- Timer (if applicable) -->
            <?php if ($exam['time_limit_minutes']): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-body text-center">
                        <h5 class="mb-0">
                            <i class="bi bi-clock"></i> Υπολοιπόμενος Χρόνος: 
                            <span id="timer" class="text-danger fw-bold"><?= $exam['time_limit_minutes'] ?>:00</span>
                        </h5>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Exam Form -->
            <form method="post" id="examForm">
                <?= csrfField() ?>
                <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
                <input type="hidden" name="start_time" value="<?= $_SESSION['exam_attempt_' . $examId]['start_time'] ?>">
                
                <?php foreach ($questions as $index => $question): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <div class="d-flex align-items-center">
                                <?= questionTypeBadge($question['question_type']) ?>
                                <span class="ms-auto text-muted">Ερώτηση <?= $index + 1 ?> από <?= count($questions) ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <h5 class="mb-3"><?= nl2br(h($question['question_text'])) ?></h5>
                            
                            <?php if ($question['question_type'] === QUESTION_TYPE_MC): ?>
                                <!-- Multiple Choice -->
                                <?php
                                $mcOptions = [
                                    ['key' => 'A', 'text' => $question['option_a']],
                                    ['key' => 'B', 'text' => $question['option_b']],
                                    ['key' => 'C', 'text' => $question['option_c']],
                                    ['key' => 'D', 'text' => $question['option_d']],
                                ];
                                // Don't shuffle - breaks answer validation
                                ?>
                                <?php foreach ($mcOptions as $oi => $opt): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" 
                                               name="question_<?= $question['id'] ?>" 
                                               id="q<?= $question['id'] ?>_<?= strtolower($opt['key']) ?>" 
                                               value="<?= $opt['key'] ?>" <?= $oi === 0 ? 'required' : '' ?>>
                                        <label class="form-check-label" for="q<?= $question['id'] ?>_<?= strtolower($opt['key']) ?>">
                                            <?= ($oi + 1) ?>. <?= h($opt['text']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            
                            <?php elseif ($question['question_type'] === QUESTION_TYPE_TF): ?>
                                <!-- True/False -->
                                <?php
                                $tfOptions = [
                                    ['key' => 'T', 'text' => 'Σωστό'],
                                    ['key' => 'F', 'text' => 'Λάθος'],
                                ];
                                // Don't shuffle - breaks answer validation
                                ?>
                                <?php foreach ($tfOptions as $tfi => $tfOpt): ?>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" 
                                               name="question_<?= $question['id'] ?>" 
                                               id="q<?= $question['id'] ?>_<?= strtolower($tfOpt['key']) ?>" 
                                               value="<?= $tfOpt['key'] ?>" <?= $tfi === 0 ? 'required' : '' ?>>
                                        <label class="form-check-label" for="q<?= $question['id'] ?>_<?= strtolower($tfOpt['key']) ?>">
                                            <?= $tfOpt['text'] ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            
                            <?php elseif ($question['question_type'] === QUESTION_TYPE_OPEN): ?>
                                <!-- Open Ended -->
                                <textarea name="question_<?= $question['id'] ?>" 
                                          class="form-control" rows="4" 
                                          placeholder="Γράψτε την απάντησή σας εδώ..."></textarea>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- Submit Button -->
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Είστε σίγουροι ότι θέλετε να υποβάλετε το διαγώνισμα; Δεν θα μπορείτε να το ξανακάνετε.');">
                            <i class="bi bi-check-circle"></i> Υποβολή Διαγωνίσματος
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($exam['time_limit_minutes']): ?>
<script>
// Timer countdown
let timeLeft = <?= $exam['time_limit_minutes'] * 60 ?>;
const timerDisplay = document.getElementById('timer');
const form = document.getElementById('examForm');

const timer = setInterval(() => {
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    timerDisplay.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    
    if (timeLeft <= 0) {
        clearInterval(timer);
        alert('Ο χρόνος έληξε! Το διαγώνισμα θα υποβληθεί αυτόματα.');
        form.submit();
    } else if (timeLeft <= 60) {
        timerDisplay.classList.add('text-danger');
    }
}, 1000);
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
