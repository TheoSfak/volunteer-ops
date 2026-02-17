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

// Build WHERE clauses
$whereConditions = [];
$params = [];

// Build unified query for export
$query = "
    SELECT 
        u.name as volunteer_name,
        u.cohort_year,
        'Διαγώνισμα' as type,
        te.title as exam_title,
        tc.name as category_name,
        ea.completed_at,
        ea.score,
        ea.total_questions,
        ROUND((ea.score / ea.total_questions * 100), 2) as percentage,
        CASE WHEN ea.passed = 1 THEN 'Επιτυχία' ELSE 'Αποτυχία' END as status,
        FLOOR(ea.time_taken_seconds / 60) as time_minutes,
        (ea.time_taken_seconds % 60) as time_seconds
    FROM exam_attempts ea
    INNER JOIN users u ON ea.user_id = u.id
    INNER JOIN training_exams te ON ea.exam_id = te.id
    INNER JOIN training_categories tc ON te.category_id = tc.id
    WHERE ea.completed_at IS NOT NULL
";

if ($filterType !== 'exams') {
    $query .= "
    UNION ALL
    SELECT 
        u.name as volunteer_name,
        u.cohort_year,
        'Κουίζ' as type,
        tq.title as exam_title,
        tc.name as category_name,
        qa.completed_at,
        qa.score,
        qa.total_questions,
        ROUND((qa.score / qa.total_questions * 100), 2) as percentage,
        CASE WHEN qa.passed = 1 THEN 'Επιτυχία' ELSE 'Αποτυχία' END as status,
        FLOOR(qa.time_taken_seconds / 60) as time_minutes,
        (qa.time_taken_seconds % 60) as time_seconds
    FROM quiz_attempts qa
    INNER JOIN users u ON qa.user_id = u.id
    INNER JOIN training_quizzes tq ON qa.quiz_id = tq.id
    INNER JOIN training_categories tc ON tq.category_id = tc.id
    WHERE qa.completed_at IS NOT NULL
    ";
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
