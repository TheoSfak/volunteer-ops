<?php
/**
 * VolunteerOps - Training Module Helper Functions
 * Extracted from functions.php for better organisation.
 */

defined('VOLUNTEEROPS') || exit;

/**
 * Get random questions from an exam question pool
 */
function getRandomExamQuestions($examId, $count) {
    $questions = dbFetchAll(
        "SELECT * FROM training_exam_questions WHERE exam_id = ? ORDER BY RAND() LIMIT ?",
        [$examId, $count]
    );
    return $questions;
}

/**
 * Get random questions from the entire category pool (all exams in same category)
 */
function getRandomPoolQuestions($categoryId, $count) {
    return dbFetchAll(
        "SELECT * FROM training_exam_questions WHERE category_id = ? ORDER BY RAND() LIMIT ?",
        [$categoryId, $count]
    );
}

/**
 * Get random questions from a quiz
 */
function getRandomQuizQuestions($quizId, $count = null) {
    if ($count === null) {
        return dbFetchAll(
            "SELECT * FROM training_quiz_questions WHERE quiz_id = ? ORDER BY id",
            [$quizId]
        );
    }
    return dbFetchAll(
        "SELECT * FROM training_quiz_questions WHERE quiz_id = ? ORDER BY RAND() LIMIT ?",
        [$quizId, $count]
    );
}

/**
 * Calculate score for an attempt
 */
function calculateAttemptScore($attemptId, $attemptType) {
    $answers = dbFetchAll(
        "SELECT * FROM user_answers WHERE attempt_id = ? AND attempt_type = ?",
        [$attemptId, $attemptType]
    );
    
    $correct = 0;
    foreach ($answers as $answer) {
        if ($answer['is_correct']) {
            $correct++;
        }
    }
    
    return [
        'correct' => $correct,
        'total' => count($answers),
        'percentage' => count($answers) > 0 ? round(($correct / count($answers)) * 100, 2) : 0
    ];
}

/**
 * Check if a user can take an exam (hasn't taken it yet)
 */
function canUserTakeExam($examId, $userId) {
    // Fetch exam to get max_attempts
    $exam = dbFetchOne("SELECT max_attempts FROM training_exams WHERE id = ?", [$examId]);
    $maxAttempts = (int) ($exam['max_attempts'] ?? 1);

    // Count completed attempts
    $completedCount = (int) dbFetchValue(
        "SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND completed_at IS NOT NULL",
        [$examId, $userId]
    );
    return $completedCount < $maxAttempts;
}

/**
 * Get user's exam attempt
 */
function getUserExamAttempt($examId, $userId) {
    return dbFetchOne(
        "SELECT * FROM exam_attempts WHERE exam_id = ? AND user_id = ?",
        [$examId, $userId]
    );
}

/**
 * Get question type badge HTML
 */
function questionTypeBadge($type) {
    $badges = [
        QUESTION_TYPE_MC => '<span class="badge bg-primary">Πολλαπλής Επιλογής</span>',
        QUESTION_TYPE_TF => '<span class="badge bg-info">Σωστό/Λάθος</span>',
        QUESTION_TYPE_OPEN => '<span class="badge bg-warning text-dark">Ανοιχτή</span>',
    ];
    return $badges[$type] ?? '<span class="badge bg-secondary">Άγνωστο</span>';
}

/**
 * Get pass/fail badge HTML
 */
function passFailBadge($passed) {
    return $passed 
        ? '<span class="badge bg-success">ΕΠΙΤΥΧΙΑ</span>' 
        : '<span class="badge bg-danger">ΑΠΟΤΥΧΙΑ</span>';
}

/**
 * Grade a user's answer for a question
 */
function gradeAnswer($question, $userAnswer) {
    if ($question['question_type'] === QUESTION_TYPE_OPEN) {
        // Open-ended questions require manual grading
        return null;
    }
    
    // For multiple choice and true/false
    return $userAnswer === $question['correct_option'];
}

/**
 * Format file size for display
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Get user's progress in a category
 */
function getUserCategoryProgress($userId, $categoryId) {
    $progress = dbFetchOne(
        "SELECT * FROM training_user_progress WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
    
    if (!$progress) {
        // Create initial progress record
        dbInsert(
            "INSERT INTO training_user_progress (user_id, category_id, materials_viewed_json, quizzes_completed, exams_passed) 
             VALUES (?, ?, '[]', 0, 0)",
            [$userId, $categoryId]
        );
        return [
            'materials_viewed' => [],
            'quizzes_completed' => 0,
            'exams_passed' => 0
        ];
    }
    
    return [
        'materials_viewed' => json_decode($progress['materials_viewed_json'] ?? '[]', true),
        'quizzes_completed' => (int)$progress['quizzes_completed'],
        'exams_passed' => (int)$progress['exams_passed']
    ];
}

/**
 * Update user progress for viewing a material
 */
function trackMaterialView($userId, $categoryId, $materialId) {
    $progress = getUserCategoryProgress($userId, $categoryId);
    $viewed = $progress['materials_viewed'];
    
    if (!in_array($materialId, $viewed)) {
        $viewed[] = $materialId;
        dbExecute(
            "UPDATE training_user_progress 
             SET materials_viewed_json = ?, last_activity = NOW() 
             WHERE user_id = ? AND category_id = ?",
            [json_encode($viewed), $userId, $categoryId]
        );
    }
}

/**
 * Increment quiz completion count
 */
function incrementQuizCompletion($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET quizzes_completed = quizzes_completed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Increment quizzes passed count (for when user passes with required percentage)
 */
function incrementQuizzesPassed($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET quizzes_passed = quizzes_passed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Increment exams passed count
 */
function incrementExamsPassed($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET exams_passed = exams_passed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Format time duration in seconds to readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . ' δευτερόλεπτα';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return $minutes . ' λεπτά' . ($secs > 0 ? ' ' . $secs . ' δευτερόλεπτα' : '');
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . ' ώρες' . ($minutes > 0 ? ' ' . $minutes . ' λεπτά' : '');
    }
}

/**
 * Check if an exam is currently available based on datetime restrictions
 */
function isExamAvailable($exam) {
    $now = time();
    
    // Check available_from
    if (!empty($exam['available_from'])) {
        $from = strtotime($exam['available_from']);
        if ($now < $from) {
            return [
                'available' => false,
                'status' => 'not_started',
                'message' => 'Το διαγώνισμα θα είναι διαθέσιμο από ' . formatDateTime($exam['available_from'])
            ];
        }
    }
    
    // Check available_until
    if (!empty($exam['available_until'])) {
        $until = strtotime($exam['available_until']);
        if ($now > $until) {
            return [
                'available' => false,
                'status' => 'expired',
                'message' => 'Το διαγώνισμα έληξε στις ' . formatDateTime($exam['available_until'])
            ];
        }
    }
    
    return [
        'available' => true,
        'status' => 'active',
        'message' => null
    ];
}

/**
 * Get exam availability status badge HTML
 */
function examAvailabilityBadge($exam) {
    $check = isExamAvailable($exam);
    
    if ($check['available']) {
        // Check if there's an end time to show countdown
        if (!empty($exam['available_until'])) {
            $until = strtotime($exam['available_until']);
            $remaining = $until - time();
            if ($remaining > 0 && $remaining < 3600) { // Less than 1 hour
                return '<span class="badge bg-warning"><i class="bi bi-clock"></i> Λήγει σε ' . formatDuration($remaining) . '</span>';
            }
        }
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Διαθέσιμο</span>';
    } elseif ($check['status'] === 'not_started') {
        return '<span class="badge bg-info"><i class="bi bi-hourglass"></i> Προγραμματισμένο</span>';
    } else {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Έληξε</span>';
    }
}
