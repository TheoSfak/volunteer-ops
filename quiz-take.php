<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$quizId = get('id');
$user = getCurrentUser();
$userId = $user['id'];

if (empty($quizId)) {
    setFlash('error', 'Μη έγκυρο κουίζ.');
    redirect('training-quizzes.php');
}

// Fetch quiz
$quiz = dbFetchOne("
    SELECT tq.*, tc.name as category_name
    FROM training_quizzes tq
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE tq.id = ? AND tq.is_active = 1
", [$quizId]);

if (!$quiz) {
    setFlash('error', 'Το κουίζ δεν βρέθηκε ή δεν είναι ενεργό.');
    redirect('training-quizzes.php');
}

// Handle form submission
if (isPost()) {
    verifyCsrf();
    
    // Create attempt record
    $startTime = post('start_time'); // Hidden field from form
    $attemptId = dbInsert("
        INSERT INTO quiz_attempts (quiz_id, user_id, started_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ", [$quizId, $userId, $startTime]);
    
    // Get all questions to grade
    $questions = dbFetchAll("SELECT * FROM training_quiz_questions WHERE quiz_id = ?", [$quizId]);
    
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
            VALUES (?, 'QUIZ', ?, ?, ?, ?)
        ", [$attemptId, $question['id'], $userAnswer, $userAnswer, $isCorrect]);
    }
    
    // Update attempt with final score and completion time
    $timeTaken = time() - $startTime;
    dbExecute("
        UPDATE quiz_attempts 
        SET score = ?, total_questions = ?, completed_at = NOW(), time_taken_seconds = ?
        WHERE id = ?
    ", [$score, $totalQuestions, $timeTaken, $attemptId]);
    
    // Update user progress
    incrementQuizCompletion($userId, $quiz['category_id']);
    
    // Redirect to results
    redirect('quiz-results.php?attempt_id=' . $attemptId);
}

// Fetch questions
$questions = dbFetchAll("
    SELECT * FROM training_quiz_questions 
    WHERE quiz_id = ?
", [$quizId]);
shuffle($questions);

if (empty($questions)) {
    setFlash('error', 'Αυτό το κουίζ δεν έχει ερωτήσεις.');
    redirect('training-quizzes.php');
}

$pageTitle = h($quiz['title']);
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Quiz Header -->
            <div class="card mb-4 bg-info text-white">
                <div class="card-body">
                    <h3 class="mb-1"><?= h($quiz['title']) ?></h3>
                    <p class="mb-0"><?= h($quiz['category_name']) ?></p>
                </div>
            </div>
            
            <!-- Timer (if applicable) -->
            <?php if ($quiz['time_limit_minutes']): ?>
                <div class="card mb-4 border-warning">
                    <div class="card-body text-center">
                        <h5 class="mb-0">
                            <i class="bi bi-clock"></i> Υπολοιπόμενος Χρόνος: 
                            <span id="timer" class="text-danger fw-bold"><?= $quiz['time_limit_minutes'] ?>:00</span>
                        </h5>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Quiz Form -->
            <form method="post" id="quizForm">
                <?= csrfField() ?>
                <input type="hidden" name="start_time" value="<?= time() ?>">
                
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
                                <!-- Multiple Choice (shuffled options) -->
                                <?php
                                $mcOptions = [
                                    ['key' => 'A', 'text' => $question['option_a']],
                                    ['key' => 'B', 'text' => $question['option_b']],
                                    ['key' => 'C', 'text' => $question['option_c']],
                                    ['key' => 'D', 'text' => $question['option_d']],
                                ];
                                shuffle($mcOptions);
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
                                <!-- True/False (shuffled) -->
                                <?php
                                $tfOptions = [
                                    ['key' => 'T', 'text' => 'Σωστό'],
                                    ['key' => 'F', 'text' => 'Λάθος'],
                                ];
                                shuffle($tfOptions);
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
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-check-circle"></i> Υποβολή Κουίζ
                        </button>
                        <a href="training-quizzes.php" class="btn btn-outline-secondary btn-lg ms-2">
                            <i class="bi bi-x-circle"></i> Ακύρωση
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($quiz['time_limit_minutes']): ?>
<script>
// Timer countdown
let timeLeft = <?= $quiz['time_limit_minutes'] * 60 ?>;
const timerDisplay = document.getElementById('timer');
const form = document.getElementById('quizForm');

const timer = setInterval(() => {
    timeLeft--;
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    timerDisplay.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    
    if (timeLeft <= 0) {
        clearInterval(timer);
        alert('Ο χρόνος έληξε! Το κουίζ θα υποβληθεί αυτόματα.');
        form.submit();
    } else if (timeLeft <= 60) {
        timerDisplay.classList.add('text-danger');
    }
}, 1000);
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
