<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Στατιστικά Εξετάσεων';

// Get filters
$filterYear = get('year', 'all');
$filterCategory = get('category', 'all');
$filterType = get('type', 'all'); // 'all', 'exams', 'quizzes'
$filterStatus = get('status', 'all'); // 'all', 'passed', 'failed'
$searchTerm = get('search', '');
$page = max(1, (int)get('page', 1));
$perPage = 50;

// Get available years from cohort_year
$availableYears = dbFetchAll("
    SELECT DISTINCT cohort_year 
    FROM users 
    WHERE cohort_year IS NOT NULL 
    ORDER BY cohort_year DESC
");

// Get categories
$categories = dbFetchAll("SELECT id, name FROM training_categories ORDER BY name");

// Build filter conditions INSIDE each subquery (correct architecture)
// Each filter uses the correct table aliases for exams vs quizzes
$examFilters = [];
$quizFilters = [];
$examParams = [];
$quizParams = [];

if ($filterYear !== 'all') {
    $examFilters[] = "u.cohort_year = ?";
    $quizFilters[] = "u.cohort_year = ?";
    $examParams[] = $filterYear;
    $quizParams[] = $filterYear;
}

if ($filterCategory !== 'all') {
    $examFilters[] = "te.category_id = ?";
    $quizFilters[] = "tq.category_id = ?";
    $examParams[] = $filterCategory;
    $quizParams[] = $filterCategory;
}

if ($filterStatus === 'passed') {
    $examFilters[] = "ea.passed = 1";
    $quizFilters[] = "qa.passed = 1";
} elseif ($filterStatus === 'failed') {
    $examFilters[] = "ea.passed = 0";
    $quizFilters[] = "qa.passed = 0";
}

if (!empty($searchTerm)) {
    $searchParam = '%' . $searchTerm . '%';
    $examFilters[] = "(u.name LIKE ? OR te.title LIKE ?)";
    $quizFilters[] = "(u.name LIKE ? OR tq.title LIKE ?)";
    $examParams[] = $searchParam;
    $examParams[] = $searchParam;
    $quizParams[] = $searchParam;
    $quizParams[] = $searchParam;
}

// Build exam WHERE extension
$examWhere = '';
if (!empty($examFilters)) {
    $examWhere = ' AND ' . implode(' AND ', $examFilters);
}
$quizWhere = '';
if (!empty($quizFilters)) {
    $quizWhere = ' AND ' . implode(' AND ', $quizFilters);
}

// Base query for EXAMS (filters injected inside WHERE)
$examQuery = "
    SELECT 
        ea.id as attempt_id,
        'EXAM' as attempt_type,
        u.id as user_id,
        u.name as user_name,
        u.cohort_year,
        te.id as exam_id,
        te.title as exam_title,
        NULL as quiz_id,
        NULL as quiz_title,
        tc.name as category_name,
        ea.score,
        ea.total_questions,
        ea.passed,
        ea.completed_at,
        ea.time_taken_seconds,
        ROUND((ea.score / ea.total_questions * 100), 2) as percentage
    FROM exam_attempts ea
    INNER JOIN users u ON ea.user_id = u.id
    INNER JOIN training_exams te ON ea.exam_id = te.id
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE ea.completed_at IS NOT NULL $examWhere
";

// Base query for QUIZZES (filters injected inside WHERE)
$quizQuery = "
    SELECT 
        qa.id as attempt_id,
        'QUIZ' as attempt_type,
        u.id as user_id,
        u.name as user_name,
        u.cohort_year,
        NULL as exam_id,
        NULL as exam_title,
        tq.id as quiz_id,
        tq.title as quiz_title,
        tc.name as category_name,
        qa.score,
        qa.total_questions,
        qa.passed,
        qa.completed_at,
        qa.time_taken_seconds,
        ROUND((qa.score / qa.total_questions * 100), 2) as percentage
    FROM quiz_attempts qa
    INNER JOIN users u ON qa.user_id = u.id
    INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE qa.completed_at IS NOT NULL $quizWhere
";

// Build combined query and params based on filter type
if ($filterType === 'exams') {
    $combinedQuery = $examQuery;
    $params = $examParams;
} elseif ($filterType === 'quizzes') {
    $combinedQuery = $quizQuery;
    $params = $quizParams;
} else {
    $combinedQuery = "
        SELECT * FROM (
            $examQuery
            UNION ALL
            $quizQuery
        ) as combined
    ";
    $params = array_merge($examParams, $quizParams);
}

// Calculate summary statistics
$stats = [
    'total_attempts' => 0,
    'total_passed' => 0,
    'avg_score' => 0,
    'avg_time' => 0,
    'pass_rate' => 0
];

$allResultsQuery = "
    SELECT 
        COUNT(*) as total_attempts,
        SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as total_passed,
        AVG(percentage) as avg_percentage,
        AVG(time_taken_seconds) as avg_time_seconds
    FROM (" . $combinedQuery . ") as all_attempts
";

$statsRow = dbFetchOne($allResultsQuery, $params);
if ($statsRow) {
    $stats['total_attempts'] = (int)$statsRow['total_attempts'];
    $stats['total_passed'] = (int)$statsRow['total_passed'];
    $stats['avg_score'] = round($statsRow['avg_percentage'] ?? 0, 2);
    $stats['avg_time'] = round($statsRow['avg_time_seconds'] ?? 0);
    $stats['pass_rate'] = $stats['total_attempts'] > 0 
        ? round(($stats['total_passed'] / $stats['total_attempts']) * 100, 2) 
        : 0;
}

// Get paginated results for table
$offset = ($page - 1) * $perPage;
$resultsQuery = "SELECT * FROM (" . $combinedQuery . ") as r ORDER BY completed_at DESC LIMIT ? OFFSET ?";
$detailedParams = array_merge($params, [$perPage, $offset]);
$results = dbFetchAll($resultsQuery, $detailedParams);

// Count total for pagination
$countQuery = "SELECT COUNT(*) FROM (" . $combinedQuery . ") as counted";
$totalResults = (int)dbFetchValue($countQuery, $params);
$totalPages = ceil($totalResults / $perPage);

// Get leaderboards - reuse the combined subquery, no extra JOINs needed
// Top 10 by score (highest percentage)
$topScorersQuery = "
    SELECT 
        user_name,
        cohort_year,
        COALESCE(exam_title, quiz_title) as title,
        score,
        total_questions,
        percentage,
        completed_at
    FROM (" . $combinedQuery . ") as attempts
    ORDER BY percentage DESC, time_taken_seconds ASC
    LIMIT 10
";
$topScorers = dbFetchAll($topScorersQuery, $params);

// Top 10 fastest (with passing grade)
$fastestQuery = "
    SELECT 
        user_name,
        cohort_year,
        COALESCE(exam_title, quiz_title) as title,
        time_taken_seconds,
        percentage,
        completed_at
    FROM (" . $combinedQuery . ") as attempts
    WHERE passed = 1
    ORDER BY time_taken_seconds ASC
    LIMIT 10
";
$fastestCompletions = dbFetchAll($fastestQuery, $params);

// "Golden Eagle" - Combined metric (best overall performance)
// Formula: percentage * (60 / time_seconds), guards against division by zero
$goldenEagleQuery = "
    SELECT 
        user_name,
        cohort_year,
        COALESCE(exam_title, quiz_title) as title,
        score,
        total_questions,
        percentage,
        time_taken_seconds,
        ROUND(
            percentage * (60 / GREATEST(time_taken_seconds, 1))
        , 2) as golden_score
    FROM (" . $combinedQuery . ") as attempts
    WHERE passed = 1 AND time_taken_seconds > 0
    ORDER BY golden_score DESC
    LIMIT 10
";
$goldenEagles = dbFetchAll($goldenEagleQuery, $params);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-bar-chart-line me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="exam-statistics-export.php?<?= http_build_query($_GET) ?>" class="btn btn-success">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Εξαγωγή σε Excel
    </a>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Χρονιά Σειράς</label>
                <select name="year" class="form-select">
                    <option value="all" <?= $filterYear === 'all' ? 'selected' : '' ?>>Όλες οι Χρονιές</option>
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= h($y['cohort_year']) ?>" <?= $filterYear == $y['cohort_year'] ? 'selected' : '' ?>>
                            <?= h($y['cohort_year']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Κατηγορία</label>
                <select name="category" class="form-select">
                    <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>Όλες οι Κατηγορίες</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Τύπος</label>
                <select name="type" class="form-select">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>Όλα</option>
                    <option value="exams" <?= $filterType === 'exams' ? 'selected' : '' ?>>Διαγωνίσματα</option>
                    <option value="quizzes" <?= $filterType === 'quizzes' ? 'selected' : '' ?>>Κουίζ</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Κατάσταση</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Όλα</option>
                    <option value="passed" <?= $filterStatus === 'passed' ? 'selected' : '' ?>>Επιτυχία</option>
                    <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Αποτυχία</option>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-funnel me-1"></i>Φίλτρο
                </button>
            </div>
            
            <div class="col-12">
                <input type="search" name="search" class="form-control" placeholder="Αναζήτηση ονόματος ή τίτλου..." 
                       value="<?= h($searchTerm) ?>">
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="display-4 text-primary mb-0"><?= number_format($stats['total_attempts']) ?></h2>
                <p class="text-muted mb-0">Συνολικές Προσπάθειες</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="display-4 text-success mb-0"><?= $stats['pass_rate'] ?>%</h2>
                <p class="text-muted mb-0">Ποσοστό Επιτυχίας</p>
                <small class="text-muted"><?= $stats['total_passed'] ?> / <?= $stats['total_attempts'] ?></small>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="display-4 text-info mb-0"><?= $stats['avg_score'] ?>%</h2>
                <p class="text-muted mb-0">Μέσος Όρος Βαθμολογίας</p>
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="display-4 text-warning mb-0"><?= floor($stats['avg_time'] / 60) ?>λ</h2>
                <p class="text-muted mb-0">Μέσος Χρόνος Ολοκλήρωσης</p>
                <small class="text-muted"><?= $stats['avg_time'] % 60 ?> δευτερόλεπτα</small>
            </div>
        </div>
    </div>
</div>

<!-- Leaderboards -->
<div class="row g-3 mb-4">
    <!-- Top Scorers -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Κορυφαίοι σε Βαθμολογία</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($topScorers)): ?>
                        <div class="list-group-item text-muted text-center py-4">Δεν υπάρχουν δεδομένα</div>
                    <?php else: ?>
                        <?php foreach ($topScorers as $index => $scorer): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold">
                                        <?php if ($index === 0): ?>
                                            <i class="bi bi-award-fill text-warning"></i>
                                        <?php endif; ?>
                                        <?= h($scorer['user_name']) ?>
                                        <?php if ($scorer['cohort_year']): ?>
                                            <small class="text-muted">(<?= $scorer['cohort_year'] ?>)</small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= h($scorer['title']) ?></small>
                                </div>
                                <span class="badge bg-primary rounded-pill"><?= $scorer['percentage'] ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Fastest Completions -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Οι Πιο Γρήγοροι</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($fastestCompletions)): ?>
                        <div class="list-group-item text-muted text-center py-4">Δεν υπάρχουν δεδομένα</div>
                    <?php else: ?>
                        <?php foreach ($fastestCompletions as $index => $fastest): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold">
                                        <?php if ($index === 0): ?>
                                            <i class="bi bi-award-fill text-warning"></i>
                                        <?php endif; ?>
                                        <?= h($fastest['user_name']) ?>
                                        <?php if ($fastest['cohort_year']): ?>
                                            <small class="text-muted">(<?= $fastest['cohort_year'] ?>)</small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?= h($fastest['title']) ?> - <?= $fastest['percentage'] ?>%</small>
                                </div>
                                <span class="badge bg-success rounded-pill">
                                    <?= floor($fastest['time_taken_seconds'] / 60) ?>:<?= str_pad($fastest['time_taken_seconds'] % 60, 2, '0', STR_PAD_LEFT) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Golden Eagle (Best Overall) -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-star-fill me-2"></i>Χρυσός Αετός (Συνδυασμένη Επίδοση)</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($goldenEagles)): ?>
                        <div class="list-group-item text-muted text-center py-4">Δεν υπάρχουν δεδομένα</div>
                    <?php else: ?>
                        <?php foreach ($goldenEagles as $index => $eagle): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-bold">
                                        <?php if ($index === 0): ?>
                                            <i class="bi bi-trophy-fill text-warning"></i>
                                        <?php endif; ?>
                                        <?= h($eagle['user_name']) ?>
                                        <?php if ($eagle['cohort_year']): ?>
                                            <small class="text-muted">(<?= $eagle['cohort_year'] ?>)</small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= h($eagle['title']) ?> - <?= $eagle['percentage'] ?>% σε 
                                        <?= floor($eagle['time_taken_seconds'] / 60) ?>:<?= str_pad($eagle['time_taken_seconds'] % 60, 2, '0', STR_PAD_LEFT) ?>
                                    </small>
                                </div>
                                <span class="badge bg-warning text-dark rounded-pill"><?= $eagle['golden_score'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Results Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Αναλυτικά Αποτελέσματα</h5>
    </div>
    <div class="card-body">
        <?php if (empty($results)): ?>
            <p class="text-muted text-center py-4">Δεν βρέθηκαν αποτελέσματα με τα επιλεγμένα φίλτρα.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Εθελοντής</th>
                            <th>Χρονιά</th>
                            <th>Τύπος</th>
                            <th>Τίτλος</th>
                            <th>Κατηγορία</th>
                            <th>Ημερομηνία</th>
                            <th>Βαθμός</th>
                            <th>Κατάσταση</th>
                            <th>Χρόνος</th>
                            <th>Ενέργεια</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr>
                                <td>
                                    <a href="volunteer-view.php?id=<?= $result['user_id'] ?>">
                                        <?= h($result['user_name']) ?>
                                    </a>
                                </td>
                                <td><?= $result['cohort_year'] ? h($result['cohort_year']) : '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <?php if ($result['attempt_type'] === 'EXAM'): ?>
                                        <span class="badge bg-warning">Διαγώνισμα</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Κουίζ</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($result['exam_title'] ?? $result['quiz_title']) ?></td>
                                <td><small class="text-muted"><?= h($result['category_name']) ?></small></td>
                                <td><small><?= formatDateTime($result['completed_at']) ?></small></td>
                                <td>
                                    <strong><?= $result['score'] ?>/<?= $result['total_questions'] ?></strong>
                                    <small class="text-muted">(<?= $result['percentage'] ?>%)</small>
                                </td>
                                <td>
                                    <?php if ($result['passed']): ?>
                                        <span class="badge bg-success">Επιτυχία</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Αποτυχία</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>
                                        <?= floor($result['time_taken_seconds'] / 60) ?>:<?= str_pad($result['time_taken_seconds'] % 60, 2, '0', STR_PAD_LEFT) ?>
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    $resultsUrl = $result['attempt_type'] === 'EXAM' 
                                        ? 'exam-results.php?attempt_id=' . $result['attempt_id']
                                        : 'quiz-results.php?attempt_id=' . $result['attempt_id'];
                                    ?>
                                    <a href="<?= $resultsUrl ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Προβολή
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center mb-0">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
