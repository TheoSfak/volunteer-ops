<?php
/**
 * Fix corrupted TF questions - run once on production then delete
 * Checks and fixes TF questions that have correct_option = A/B/C/D (from the questions-pool.php bug)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Διόρθωση Ερωτήσεων Σωστό/Λάθος';
include __DIR__ . '/includes/header.php';
?>
<div class="container-fluid">
    <h2 class="mb-4"><i class="bi bi-wrench"></i> Διόρθωση Ερωτήσεων Σωστό/Λάθος</h2>

<?php
// Show current DB schema version
$schemaVersion = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
echo '<div class="alert alert-info"><strong>DB Schema Version:</strong> ' . h($schemaVersion) . '</div>';

// Check for corrupted quiz questions
$corruptQuiz = dbFetchAll("
    SELECT tqq.id, tqq.quiz_id, tqq.question_text, tqq.correct_option, tqq.question_type,
           tq.title as quiz_title
    FROM training_quiz_questions tqq
    LEFT JOIN training_quizzes tq ON tqq.quiz_id = tq.id
    WHERE tqq.question_type = 'TRUE_FALSE'
");

$corruptExam = dbFetchAll("
    SELECT teq.id, teq.exam_id, teq.question_text, teq.correct_option, teq.question_type,
           te.title as exam_title
    FROM training_exam_questions teq
    LEFT JOIN training_exams te ON teq.exam_id = te.id
    WHERE teq.question_type = 'TRUE_FALSE'
");

echo '<h4>Ερωτήσεις Κουίζ (Σωστό/Λάθος)</h4>';
if (empty($corruptQuiz)) {
    echo '<div class="alert alert-warning">Δεν βρέθηκαν ερωτήσεις Σωστό/Λάθος σε κουίζ.</div>';
} else {
    echo '<table class="table table-bordered table-sm">';
    echo '<thead><tr><th>ID</th><th>Κουίζ</th><th>Ερώτηση</th><th>correct_option</th><th>Κατάσταση</th></tr></thead><tbody>';
    foreach ($corruptQuiz as $q) {
        $isBad = !in_array($q['correct_option'], ['T', 'F']);
        $class = $isBad ? 'table-danger' : 'table-success';
        $status = $isBad ? '<span class="badge bg-danger">ΛΑΘΟΣ: ' . h($q['correct_option']) . '</span>' : '<span class="badge bg-success">OK</span>';
        echo '<tr class="' . $class . '">';
        echo '<td>' . $q['id'] . '</td>';
        echo '<td>' . h($q['quiz_title'] ?? 'N/A') . '</td>';
        echo '<td>' . h(mb_substr($q['question_text'], 0, 80)) . '</td>';
        echo '<td><code>' . h($q['correct_option']) . '</code></td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

echo '<h4 class="mt-4">Ερωτήσεις Εξετάσεων (Σωστό/Λάθος)</h4>';
if (empty($corruptExam)) {
    echo '<div class="alert alert-warning">Δεν βρέθηκαν ερωτήσεις Σωστό/Λάθος σε εξετάσεις.</div>';
} else {
    echo '<table class="table table-bordered table-sm">';
    echo '<thead><tr><th>ID</th><th>Εξέταση</th><th>Ερώτηση</th><th>correct_option</th><th>Κατάσταση</th></tr></thead><tbody>';
    foreach ($corruptExam as $q) {
        $isBad = !in_array($q['correct_option'], ['T', 'F']);
        $class = $isBad ? 'table-danger' : 'table-success';
        $status = $isBad ? '<span class="badge bg-danger">ΛΑΘΟΣ: ' . h($q['correct_option']) . '</span>' : '<span class="badge bg-success">OK</span>';
        echo '<tr class="' . $class . '">';
        echo '<td>' . $q['id'] . '</td>';
        echo '<td>' . h($q['exam_title'] ?? 'N/A') . '</td>';
        echo '<td>' . h(mb_substr($q['question_text'], 0, 80)) . '</td>';
        echo '<td><code>' . h($q['correct_option']) . '</code></td>';
        echo '<td>' . $status . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

// Handle fix action
if (isPost() && post('action') === 'fix_all') {
    verifyCsrf();
    
    $fixed1 = dbExecute("
        UPDATE training_quiz_questions 
        SET correct_option = 'T' 
        WHERE question_type = 'TRUE_FALSE' 
          AND correct_option NOT IN ('T', 'F')
    ");
    $fixed2 = dbExecute("
        UPDATE training_exam_questions 
        SET correct_option = 'T' 
        WHERE question_type = 'TRUE_FALSE' 
          AND correct_option NOT IN ('T', 'F')
    ");
    
    echo '<div class="alert alert-success mt-3">';
    echo '<strong>Διορθώθηκαν:</strong> ' . $fixed1 . ' ερωτήσεις κουίζ + ' . $fixed2 . ' ερωτήσεις εξετάσεων.';
    echo '<br><strong>ΣΗΜΕΙΩΣΑ:</strong> Όλες οι διορθωθείσες ερωτήσεις τέθηκαν σε <strong>Σωστό (T)</strong>. Ελέγξτε μία-μία αν κάποια πρέπει να είναι Λάθος (F).';
    echo '</div>';
}

// Count bad questions
$badQuiz = 0;
$badExam = 0;
foreach ($corruptQuiz as $q) { if (!in_array($q['correct_option'], ['T', 'F'])) $badQuiz++; }
foreach ($corruptExam as $q) { if (!in_array($q['correct_option'], ['T', 'F'])) $badExam++; }

if ($badQuiz > 0 || $badExam > 0) {
    echo '<div class="alert alert-danger mt-3">';
    echo '<strong>Βρέθηκαν ' . ($badQuiz + $badExam) . ' ερωτήσεις με λάθος correct_option!</strong>';
    echo '<form method="post" class="mt-2">';
    echo csrfField();
    echo '<input type="hidden" name="action" value="fix_all">';
    echo '<button type="submit" class="btn btn-danger btn-lg">';
    echo '<i class="bi bi-wrench"></i> Διόρθωση Όλων σε "Σωστό" (T)';
    echo '</button>';
    echo '<p class="mt-2 text-muted">Μετά τη διόρθωση, ελέγξτε κάθε ερώτηση και αλλάξτε όσες πρέπει να είναι "Λάθος" (F)</p>';
    echo '</form>';
    echo '</div>';
} else {
    echo '<div class="alert alert-success mt-3"><i class="bi bi-check-circle"></i> Όλες οι ερωτήσεις Σωστό/Λάθος είναι σωστές!</div>';
}
?>

    <div class="mt-4">
        <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Επιστροφή</a>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
