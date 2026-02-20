<?php
/**
 * Export Exam & Quiz Statistics to Excel/CSV
 * Greek encoding with BOM for proper display in Excel
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

// Get same filters as statistics page
$filterYear = get('year', 'all');
$filterCategory = get('category', 'all');
$filterType = get('type', 'all');
$filterStatus = get('status', 'all');
$searchTerm = get('search', '');

// Build filter conditions INSIDE each subquery (same logic as exam-statistics.php)
$examFilters = [];
$quizFilters = [];
$examParams = [];
$quizParams = [];

if ($filterYear !== 'all') {
    $examFilters[] = "YEAR(u.created_at) = ?";
    $quizFilters[] = "YEAR(u.created_at) = ?";
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

$examWhere = !empty($examFilters) ? ' AND ' . implode(' AND ', $examFilters) : '';
$quizWhere = !empty($quizFilters) ? ' AND ' . implode(' AND ', $quizFilters) : '';

// Build query with filters injected inside each subquery
$query = "
    SELECT 
        u.name as volunteer_name,
        YEAR(u.created_at) as cohort_year,
        'Διαγώνισμα' as type,
        te.title as exam_title,
        tc.name as category_name,
        ea.submitted_at as completed_at,
        ea.score,
        te.questions_per_attempt as total_questions,
        ROUND((ea.score / NULLIF(te.questions_per_attempt, 0) * 100), 2) as percentage,
        CASE WHEN ea.passed = 1 THEN 'Επιτυχία' ELSE 'Αποτυχία' END as status,
        FLOOR(ea.time_taken_seconds / 60) as time_minutes,
        (ea.time_taken_seconds % 60) as time_seconds
    FROM exam_attempts ea
    INNER JOIN users u ON ea.user_id = u.id
    INNER JOIN training_exams te ON ea.exam_id = te.id
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE ea.submitted_at IS NOT NULL $examWhere
";
$params = $examParams;

if ($filterType === 'quizzes') {
    // Only quizzes
    $query = "
        SELECT 
            u.name as volunteer_name,
            YEAR(u.created_at) as cohort_year,
            'Κουίζ' as type,
            tq.title as exam_title,
            tc.name as category_name,
            qa.submitted_at as completed_at,
            qa.score,
            (SELECT COUNT(*) FROM training_quiz_questions tqqc WHERE tqqc.quiz_id = qa.quiz_id) as total_questions,
            ROUND((qa.score / NULLIF((SELECT COUNT(*) FROM training_quiz_questions tqqc2 WHERE tqqc2.quiz_id = qa.quiz_id), 0) * 100), 2) as percentage,
            'Κουίζ' as status,
            FLOOR(qa.time_taken_seconds / 60) as time_minutes,
            (qa.time_taken_seconds % 60) as time_seconds
        FROM quiz_attempts qa
        INNER JOIN users u ON qa.user_id = u.id
        INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
        INNER JOIN training_categories tc ON tq.category_id = tc.id
        WHERE qa.submitted_at IS NOT NULL $quizWhere
    ";
    $params = $quizParams;
} elseif ($filterType !== 'exams') {
    // Both types
    $query .= "
    UNION ALL
    SELECT 
        u.name as volunteer_name,
        YEAR(u.created_at) as cohort_year,
        'Κουίζ' as type,
        tq.title as exam_title,
        tc.name as category_name,
        qa.submitted_at as completed_at,
        qa.score,
        (SELECT COUNT(*) FROM training_quiz_questions tqqc WHERE tqqc.quiz_id = qa.quiz_id) as total_questions,
        ROUND((qa.score / NULLIF((SELECT COUNT(*) FROM training_quiz_questions tqqc2 WHERE tqqc2.quiz_id = qa.quiz_id), 0) * 100), 2) as percentage,
        'Κουίζ' as status,
        FLOOR(qa.time_taken_seconds / 60) as time_minutes,
        (qa.time_taken_seconds % 60) as time_seconds
    FROM quiz_attempts qa
    INNER JOIN users u ON qa.user_id = u.id
    INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE qa.submitted_at IS NOT NULL $quizWhere
    ";
    $params = array_merge($examParams, $quizParams);
}

$query .= " ORDER BY completed_at DESC";

// Execute query
$results = dbFetchAll($query, $params);

// Set headers for CSV download with Greek encoding
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="exam-statistics-' . date('Y-m-d') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Output UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

// Create output stream
$output = fopen('php://output', 'w');

// CSV Headers in Greek
fputcsv($output, [
    'Ονοματεπώνυμο',
    'Χρονιά Σειράς',
    'Τύπος',
    'Τίτλος Εξέτασης',
    'Κατηγορία',
    'Ημερομηνία',
    'Βαθμός',
    'Σύνολο Ερωτήσεων',
    'Ποσοστό %',
    'Κατάσταση',
    'Χρόνος (Λεπτά:Δευτερόλεπτα)'
]);

// Output data rows
foreach ($results as $row) {
    fputcsv($output, [
        $row['volunteer_name'],
        $row['cohort_year'] ?? 'Χωρίς Χρονιά',
        $row['type'],
        $row['exam_title'],
        $row['category_name'],
        date('d/m/Y H:i', strtotime($row['completed_at'])),
        $row['score'],
        $row['total_questions'],
        $row['percentage'] . '%',
        $row['status'],
        sprintf('%d:%02d', $row['time_minutes'], $row['time_seconds'])
    ]);
}

fclose($output);
exit;
