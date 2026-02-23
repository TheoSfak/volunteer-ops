<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Διαχείριση Διαγωνισμάτων & Κουίζ';

// Handle delete actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'delete_exam') {
        $id = post('id');
        dbExecute("DELETE FROM training_exams WHERE id = ?", [$id]);
        logAudit('delete', 'training_exams', $id);
        setFlash('success', 'Το διαγώνισμα διαγράφηκε.');
        redirect('exam-admin.php');
        
    } elseif ($action === 'launch_exam') {
        $id = post('id');
        $exam = dbFetchOne("SELECT * FROM training_exams WHERE id = ?", [$id]);
        if ($exam) {
            $timeLimit = (int)$exam['time_limit_minutes'];
            if ($timeLimit <= 0) {
                setFlash('error', 'Δεν μπορείτε να ξεκινήσετε διαγώνισμα χωρίς όριο χρόνου. Ορίστε πρώτα χρονικό όριο.');
                redirect('exam-admin.php');
            }
            $questionCount = dbFetchValue("SELECT COUNT(*) FROM training_exam_questions WHERE exam_id = ?", [$id]);
            if ($questionCount < $exam['questions_per_attempt']) {
                setFlash('error', 'Δεν υπάρχουν αρκετές ερωτήσεις (χρειάζονται τουλάχιστον ' . $exam['questions_per_attempt'] . ', υπάρχουν ' . $questionCount . ').');
                redirect('exam-admin.php');
            }
            // Set available_from = NOW, available_until = NOW + time_limit, is_active = 1
            dbExecute("
                UPDATE training_exams 
                SET is_active = 1, available_from = NOW(), available_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                WHERE id = ?
            ", [$timeLimit, $id]);
            logAudit('launch', 'training_exams', $id);
            
            // Send notification to all active volunteers
            $volunteers = dbFetchAll("SELECT id FROM users WHERE role = 'VOLUNTEER' AND is_active = 1");
            foreach ($volunteers as $vol) {
                sendNotification(
                    $vol['id'],
                    '📝 Νέο Διαγώνισμα Ξεκίνησε!',
                    'Το διαγώνισμα "' . $exam['title'] . '" είναι τώρα διαθέσιμο! Έχετε ' . $timeLimit . ' λεπτά. Πηγαίνετε στα Διαγωνίσματα για να ξεκινήσετε.',
                    'warning'
                );
            }
            
            setFlash('success', 'Το διαγώνισμα "' . h($exam['title']) . '" ξεκίνησε! Λήγει σε ' . $timeLimit . ' λεπτά. Ειδοποιήθηκαν ' . count($volunteers) . ' εθελοντές.');
        }
        redirect('exam-admin.php');
        
    } elseif ($action === 'stop_exam') {
        $id = post('id');
        dbExecute("
            UPDATE training_exams 
            SET is_active = 0, available_until = NOW()
            WHERE id = ?
        ", [$id]);
        logAudit('stop', 'training_exams', $id);
        setFlash('success', 'Το διαγώνισμα σταμάτησε.');
        redirect('exam-admin.php');
        
    } elseif ($action === 'delete_quiz') {
        $id = post('id');
        dbExecute("DELETE FROM training_quizzes WHERE id = ?", [$id]);
        logAudit('delete', 'training_quizzes', $id);
        setFlash('success', 'Το κουίζ διαγράφηκε.');
        redirect('exam-admin.php');
    }
}

// Get tab
$activeTab = get('tab', 'exams');

// Fetch exams
$exams = dbFetchAll("
    SELECT te.*, tc.name as category_name, u.name as created_by_name,
           (SELECT COUNT(*) FROM training_exam_questions WHERE exam_id = te.id) as question_count,
           (SELECT COUNT(*) FROM exam_attempts WHERE exam_id = te.id) as attempt_count
    FROM training_exams te
    INNER JOIN training_categories tc ON te.category_id = tc.id
    INNER JOIN users u ON te.created_by = u.id
    ORDER BY te.created_at DESC
");

// Fetch quizzes
$quizzes = dbFetchAll("
    SELECT tq.*, tc.name as category_name, u.name as created_by_name,
           (SELECT COUNT(*) FROM training_quiz_questions WHERE quiz_id = tq.id) as question_count,
           (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = tq.id) as attempt_count
    FROM training_quizzes tq
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    INNER JOIN users u ON tq.created_by = u.id
    ORDER BY tq.created_at DESC
");

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>Διαχείριση Διαγωνισμάτων & Κουίζ
        </h1>
    </div>
    
    <!-- Quick Action Cards -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-primary shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-award text-primary" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Διαχείριση Ερωτήσεων Διαγωνισμάτων</h5>
                    <p class="text-muted">Προσθέστε, επεξεργαστείτε ή διαγράψτε ερωτήσεις για τα διαγωνίσματά σας</p>
                    <?php if (!empty($exams)): ?>
                        <div class="list-group list-group-flush mt-3">
                            <?php foreach (array_slice($exams, 0, 5) as $exam): ?>
                                <a href="exam-questions-admin.php?exam_id=<?= $exam['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <span><?= h($exam['title']) ?></span>
                                    <span class="badge bg-primary rounded-pill"><?= $exam['question_count'] ?> ερωτήσεις</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($exams) > 5): ?>
                            <p class="text-muted small mt-2">Και άλλα <?= count($exams) - 5 ?> διαγωνίσματα παρακάτω...</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> Πρώτα δημιουργήστε ένα διαγώνισμα
                        </div>
                        <a href="exam-form.php" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-lg"></i> Δημιουργία Διαγώνισματος
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-success shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-puzzle text-success" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Διαχείριση Ερωτήσεων Κουίζ</h5>
                    <p class="text-muted">Προσθέστε, επεξεργαστείτε ή διαγράψτε ερωτήσεις για τα κουίζ σας</p>
                    <?php if (!empty($quizzes)): ?>
                        <div class="list-group list-group-flush mt-3">
                            <?php foreach (array_slice($quizzes, 0, 5) as $quiz): ?>
                                <a href="quiz-questions-admin.php?quiz_id=<?= $quiz['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <span><?= h($quiz['title']) ?></span>
                                    <span class="badge bg-success rounded-pill"><?= $quiz['question_count'] ?> ερωτήσεις</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($quizzes) > 5): ?>
                            <p class="text-muted small mt-2">Και άλλα <?= count($quizzes) - 5 ?> κουίζ παρακάτω...</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> Πρώτα δημιουργήστε ένα κουίζ
                        </div>
                        <a href="quiz-form.php" class="btn btn-success mt-2">
                            <i class="bi bi-plus-lg"></i> Δημιουργία Κουίζ
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'exams' ? 'active' : '' ?>" href="?tab=exams">
                <i class="bi bi-award"></i> Διαγωνίσματα
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'quizzes' ? 'active' : '' ?>" href="?tab=quizzes">
                <i class="bi bi-puzzle"></i> Κουίζ
            </a>
        </li>
    </ul>
    
    <?php if ($activeTab === 'exams'): ?>
        <!-- Exams Tab -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Διαγωνίσματα</h5>
                <a href="exam-form.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Νέο Διαγώνισμα
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($exams)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Δεν υπάρχουν διαγωνίσματα.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Τίτλος</th>
                                    <th>Κατηγορία</th>
                                    <th>Ερωτήσεις</th>
                                    <th>Προσπάθειες</th>                                    <th>Μέγ. Προσπάθειες</th>                                    <th>Όριο %</th>
                                    <th>Χρόνος</th>
                                    <th>Κατάσταση</th>
                                    <th>Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exams as $exam): ?>
                                    <tr>
                                        <td>
                                            <a href="exam-questions-admin.php?exam_id=<?= $exam['id'] ?>" class="fw-bold">
                                                <?= h($exam['title']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-warning"><?= h($exam['category_name']) ?></span></td>
                                        <td>
                                            <span class="badge bg-info"><?= $exam['question_count'] ?></span>
                                            (Χρησιμ: <?= $exam['questions_per_attempt'] ?>)
                                        </td>
                                        <td><?= $exam['attempt_count'] ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?= $exam['max_attempts'] ?? 1 ?></span>
                                        </td>
                                        <td><?= $exam['passing_percentage'] ?>%</td>
                                        <td><?= $exam['time_limit_minutes'] ? $exam['time_limit_minutes'] . '΄' : '<span class="text-muted">-</span>' ?></td>
                                        <td>
                                            <?php
                                            $examAvail = isExamAvailable($exam);
                                            if ($exam['is_active'] && $examAvail['available']) {
                                                $untilTs = !empty($exam['available_until']) ? strtotime($exam['available_until']) * 1000 : 0;
                                                echo '<span class="badge bg-success fs-6"><i class="bi bi-broadcast"></i> LIVE</span>';
                                                if ($untilTs > 0) {
                                                    echo ' <span class="badge bg-warning text-dark" data-countdown="' . $untilTs . '">...</span>';
                                                }
                                            } elseif ($exam['is_active'] && $examAvail['status'] === 'expired') {
                                                echo '<span class="badge bg-secondary"><i class="bi bi-clock-history"></i> Έληξε</span>';
                                            } elseif ($exam['is_active']) {
                                                echo '<span class="badge bg-info"><i class="bi bi-hourglass"></i> Αναμονή</span>';
                                            } else {
                                                echo '<span class="badge bg-secondary">Ανενεργό</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            $isLive = $exam['is_active'] && $examAvail['available'];
                                            ?>
                                            <?php if (!$isLive): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Θα ξεκινήσει το διαγώνισμα ΤΩΡΑ και θα ειδοποιηθούν όλοι οι εθελοντές. Συνέχεια;');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="launch_exam">
                                                    <input type="hidden" name="id" value="<?= $exam['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" title="Έναρξη Τώρα">
                                                        <i class="bi bi-play-fill"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Θα σταματήσει αμέσως το διαγώνισμα. Συνέχεια;');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="stop_exam">
                                                    <input type="hidden" name="id" value="<?= $exam['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="Διακοπή">
                                                        <i class="bi bi-stop-fill"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="exam-questions-admin.php?exam_id=<?= $exam['id'] ?>" class="btn btn-sm btn-info" title="Ερωτήσεις">
                                                <i class="bi bi-question-circle"></i>
                                            </a>
                                            <a href="exam-form.php?id=<?= $exam['id'] ?>" class="btn btn-sm btn-warning" title="Επεξεργασία">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή διαγωνίσματος;');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_exam">
                                                <input type="hidden" name="id" value="<?= $exam['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Quizzes Tab -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Κουίζ</h5>
                <a href="quiz-form.php" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Νέο Κουίζ
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($quizzes)): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Δεν υπάρχουν κουίζ.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Τίτλος</th>
                                    <th>Κατηγορία</th>
                                    <th>Ερωτήσεις</th>
                                    <th>Προσπάθειες</th>
                                    <th>Όριο Χρόνου</th>
                                    <th>Ενεργό</th>
                                    <th>Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizzes as $quiz): ?>
                                    <tr>
                                        <td>
                                            <a href="quiz-questions-admin.php?quiz_id=<?= $quiz['id'] ?>" class="fw-bold">
                                                <?= h($quiz['title']) ?>
                                            </a>
                                        </td>
                                        <td><span class="badge bg-info"><?= h($quiz['category_name']) ?></span></td>
                                        <td><span class="badge bg-primary"><?= $quiz['question_count'] ?></span></td>
                                        <td><?= $quiz['attempt_count'] ?></td>
                                        <td><?= $quiz['time_limit_minutes'] ? $quiz['time_limit_minutes'] . ' λεπτά' : 'Χωρίς' ?></td>
                                        <td><?= $quiz['is_active'] ? '<span class="badge bg-success">Ναι</span>' : '<span class="badge bg-secondary">Όχι</span>' ?></td>
                                        <td>
                                            <a href="quiz-questions-admin.php?quiz_id=<?= $quiz['id'] ?>" class="btn btn-sm btn-info" title="Ερωτήσεις">
                                                <i class="bi bi-question-circle"></i>
                                            </a>
                                            <a href="quiz-form.php?id=<?= $quiz['id'] ?>" class="btn btn-sm btn-warning" title="Επεξεργασία">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή κουίζ;');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_quiz">
                                                <input type="hidden" name="id" value="<?= $quiz['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Live countdown timers for LIVE exams
function updateCountdowns() {
    document.querySelectorAll('[data-countdown]').forEach(function(el) {
        var until = parseInt(el.getAttribute('data-countdown'));
        var now = Date.now();
        var diff = Math.max(0, until - now);
        if (diff <= 0) {
            el.textContent = 'Έληξε';
            el.className = 'badge bg-secondary';
            return;
        }
        var totalSecs = Math.floor(diff / 1000);
        var mins = Math.floor(totalSecs / 60);
        var secs = totalSecs % 60;
        el.textContent = mins + ':' + (secs < 10 ? '0' : '') + secs + ' απομένουν';
        // Color coding
        if (mins < 5) {
            el.className = 'badge bg-danger text-white';
        } else if (mins < 10) {
            el.className = 'badge bg-warning text-dark';
        } else {
            el.className = 'badge bg-info text-dark';
        }
    });
}
updateCountdowns();
setInterval(updateCountdowns, 1000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
