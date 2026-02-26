<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Διαχείριση Pool Ερωτήσεων';

// Get tab (exams or quizzes)
$tab = get('tab', 'exams');

// Get filters
$categoryFilter = get('category', 'all');
$statusFilter = get('status', 'all'); // all, orphan, assigned
$searchFilter = get('search', '');

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    // AJAX endpoint to get question data
    if ($action === 'get_question') {
        header('Content-Type: application/json');
        $id = post('id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if ($type === 'exam') {
            $question = dbFetchOne("SELECT * FROM training_exam_questions WHERE id = ?", [$id]);
        } else {
            $question = dbFetchOne("SELECT * FROM training_quiz_questions WHERE id = ?", [$id]);
        }
        
        echo json_encode($question);
        exit;
    }
    
    if ($action === 'add_question') {
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        $categoryId = post('category_id');
        $targetId = post('target_id'); // exam_id or quiz_id
        $questionType = post('question_type');
        $questionText = post('question_text');
        $optionA = post('option_a');
        $optionB = post('option_b');
        $optionC = post('option_c');
        $optionD = post('option_d');
        // Read correct_option from the right field based on question type
        if ($questionType === 'TRUE_FALSE') {
            $correctOption = post('correct_option_tf');
            $optionA = $optionB = $optionC = $optionD = null;
        } else {
            $correctOption = post('correct_option');
        }
        $explanation = post('explanation');
        
        if ($type === 'exam') {
            $newId = dbInsert("
                INSERT INTO training_exam_questions 
                (category_id, exam_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [$categoryId, $targetId, $questionType, $questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $explanation]);
            logAudit('create', 'training_exam_questions', $newId);
        } else {
            $newId = dbInsert("
                INSERT INTO training_quiz_questions 
                (category_id, quiz_id, question_type, question_text, option_a, option_b, option_c, option_d, correct_option, explanation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [$categoryId, $targetId, $questionType, $questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $explanation]);
            logAudit('create', 'training_quiz_questions', $newId);
        }
        
        setFlash('success', 'Η ερώτηση προστέθηκε επιτυχώς.');
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'edit_question') {
        $id = post('id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        $categoryId = post('category_id');
        $questionType = post('question_type');
        $questionText = post('question_text');
        $optionA = post('option_a');
        $optionB = post('option_b');
        $optionC = post('option_c');
        $optionD = post('option_d');
        // Read correct_option from the right field based on question type
        if ($questionType === 'TRUE_FALSE') {
            $correctOption = post('correct_option_tf');
            $optionA = $optionB = $optionC = $optionD = null;
        } else {
            $correctOption = post('correct_option');
        }
        $explanation = post('explanation');
        
        if ($type === 'exam') {
            dbExecute("
                UPDATE training_exam_questions 
                SET category_id = ?, question_type = ?, question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, explanation = ?
                WHERE id = ?
            ", [$categoryId, $questionType, $questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $explanation, $id]);
            logAudit('update', 'training_exam_questions', $id);
        } else {
            dbExecute("
                UPDATE training_quiz_questions 
                SET category_id = ?, question_type = ?, question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, explanation = ?
                WHERE id = ?
            ", [$categoryId, $questionType, $questionText, $optionA, $optionB, $optionC, $optionD, $correctOption, $explanation, $id]);
            logAudit('update', 'training_quiz_questions', $id);
        }
        
        setFlash('success', 'Η ερώτηση ενημερώθηκε.');
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'delete_question') {
        $id = post('id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if ($type === 'exam') {
            dbExecute("DELETE FROM training_exam_questions WHERE id = ?", [$id]);
            logAudit('delete', 'training_exam_questions', $id);
        } else {
            dbExecute("DELETE FROM training_quiz_questions WHERE id = ?", [$id]);
            logAudit('delete', 'training_quiz_questions', $id);
        }
        
        setFlash('success', 'Η ερώτηση διαγράφηκε.');
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'assign_question') {
        $id = post('id');
        $targetId = post('target_id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if ($type === 'exam') {
            dbExecute("UPDATE training_exam_questions SET exam_id = ? WHERE id = ?", [$targetId, $id]);
            logAudit('assign', 'training_exam_questions', $id);
        } else {
            dbExecute("UPDATE training_quiz_questions SET quiz_id = ? WHERE id = ?", [$targetId, $id]);
            logAudit('assign', 'training_quiz_questions', $id);
        }
        
        setFlash('success', 'Η ερώτηση ανατέθηκε επιτυχώς.');
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'bulk_delete') {
        $ids = post('selected_ids', []);
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if (!empty($ids) && is_array($ids)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            if ($type === 'exam') {
                dbExecute("DELETE FROM training_exam_questions WHERE id IN ($placeholders)", $ids);
            } else {
                dbExecute("DELETE FROM training_quiz_questions WHERE id IN ($placeholders)", $ids);
            }
            setFlash('success', count($ids) . ' ερωτήσεις διαγράφηκαν.');
        }
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'bulk_assign') {
        $ids = post('selected_ids', []);
        $targetId = post('target_id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if (!empty($ids) && is_array($ids) && !empty($targetId)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            if ($type === 'exam') {
                dbExecute("UPDATE training_exam_questions SET exam_id = ? WHERE id IN ($placeholders)", array_merge([$targetId], $ids));
            } else {
                dbExecute("UPDATE training_quiz_questions SET quiz_id = ? WHERE id IN ($placeholders)", array_merge([$targetId], $ids));
            }
            setFlash('success', count($ids) . ' ερωτήσεις ανατέθηκαν επιτυχώς.');
        }
        redirect('questions-pool.php?tab=' . $tab);
        
    } elseif ($action === 'bulk_change_category') {
        $ids = post('selected_ids', []);
        $categoryId = post('category_id');
        $type = $tab === 'exams' ? 'exam' : 'quiz';
        
        if (!empty($ids) && is_array($ids) && !empty($categoryId)) {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            if ($type === 'exam') {
                dbExecute("UPDATE training_exam_questions SET category_id = ? WHERE id IN ($placeholders)", array_merge([$categoryId], $ids));
            } else {
                dbExecute("UPDATE training_quiz_questions SET category_id = ? WHERE id IN ($placeholders)", array_merge([$categoryId], $ids));
            }
            setFlash('success', count($ids) . ' ερωτήσεις άλλαξαν κατηγορία.');
        }
        redirect('questions-pool.php?tab=' . $tab);
    }
}

// Fetch categories
$categories = dbFetchAll("SELECT * FROM training_categories WHERE is_active = 1 ORDER BY display_order, name");

// Fetch targets (exams or quizzes)
if ($tab === 'exams') {
    $targets = dbFetchAll("
        SELECT te.*, tc.name as category_name
        FROM training_exams te
        INNER JOIN training_categories tc ON te.category_id = tc.id
        ORDER BY te.title
    ");
} else {
    $targets = dbFetchAll("
        SELECT tq.*, tc.name as category_name
        FROM training_quizzes tq
        INNER JOIN training_categories tc ON tq.category_id = tc.id
        ORDER BY tq.title
    ");
}

// Build query for questions
if ($tab === 'exams') {
    $params = [];
    $sql = "
        SELECT teq.*, 
               tc.name as category_name,
               te.title as assigned_to
        FROM training_exam_questions teq
        LEFT JOIN training_exams te ON teq.exam_id = te.id
        LEFT JOIN training_categories tc ON teq.category_id = tc.id
        WHERE 1=1
    ";
    
    if ($categoryFilter !== 'all') {
        $sql .= " AND teq.category_id = " . (int)$categoryFilter;
    }
    
    if ($statusFilter === 'orphan') {
        $sql .= " AND teq.exam_id IS NULL";
    } elseif ($statusFilter === 'assigned') {
        $sql .= " AND teq.exam_id IS NOT NULL";
    }
    
    if (!empty($searchFilter)) {
        $sql .= " AND teq.question_text LIKE ?";
        $params[] = '%' . dbEscape($searchFilter) . '%';
    }
    
    $sql .= " ORDER BY teq.id DESC";
    $questions = dbFetchAll($sql, $params);
    
} else {
    $params2 = [];
    $sql = "
        SELECT tqq.*, 
               tc.name as category_name,
               tq.title as assigned_to
        FROM training_quiz_questions tqq
        LEFT JOIN training_quizzes tq ON tqq.quiz_id = tq.id
        LEFT JOIN training_categories tc ON tqq.category_id = tc.id
        WHERE 1=1
    ";
    
    if ($categoryFilter !== 'all') {
        $sql .= " AND tqq.category_id = " . (int)$categoryFilter;
    }
    
    if ($statusFilter === 'orphan') {
        $sql .= " AND tqq.quiz_id IS NULL";
    } elseif ($statusFilter === 'assigned') {
        $sql .= " AND tqq.quiz_id IS NOT NULL";
    }
    
    if (!empty($searchFilter)) {
        $sql .= " AND tqq.question_text LIKE ?";
        $params2[] = '%' . dbEscape($searchFilter) . '%';
    }
    
    $sql .= " ORDER BY tqq.id DESC";
    $questions = dbFetchAll($sql, $params2);
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-collection me-2"></i>Pool Ερωτήσεων
        </h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addQuestionModal">
            <i class="bi bi-plus-lg"></i> Νέα Ερώτηση
        </button>
    </div>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'exams' ? 'active' : '' ?>" href="?tab=exams">
                <i class="bi bi-award"></i> Ερωτήσεις Διαγωνισμάτων
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'quizzes' ? 'active' : '' ?>" href="?tab=quizzes">
                <i class="bi bi-puzzle"></i> Ερωτήσεις Κουίζ
            </a>
        </li>
    </ul>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3">
                <input type="hidden" name="tab" value="<?= h($tab) ?>">
                
                <div class="col-md-3">
                    <label class="form-label">Κατηγορία</label>
                    <select name="category" class="form-select" onchange="this.form.submit()">
                        <option value="all">Όλες οι Κατηγορίες</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                <?= h($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Κατάσταση</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Όλες</option>
                        <option value="orphan" <?= $statusFilter === 'orphan' ? 'selected' : '' ?>>Μη Ανατεθειμένες (Orphans)</option>
                        <option value="assigned" <?= $statusFilter === 'assigned' ? 'selected' : '' ?>>Ανατεθειμένες</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label class="form-label">Αναζήτηση</label>
                    <input type="text" name="search" class="form-control" placeholder="Αναζήτηση στο κείμενο ερώτησης..." value="<?= h($searchFilter) ?>">
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i> Φίλτρα
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Bulk Actions -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" id="bulkForm">
                <?= csrfField() ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Μαζικές Ενέργειες</label>
                        <select class="form-select" id="bulkAction">
                            <option value="">-- Επιλέξτε ενέργεια --</option>
                            <option value="delete">Διαγραφή επιλεγμένων</option>
                            <option value="assign">Ανάθεση σε <?= $tab === 'exams' ? 'Διαγώνισμα' : 'Κουίζ' ?></option>
                            <option value="change_category">Αλλαγή Κατηγορίας</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4" id="targetSelectContainer" style="display:none;">
                        <label class="form-label">Επιλογή <?= $tab === 'exams' ? 'Διαγωνίσματος' : 'Κουίζ' ?></label>
                        <select class="form-select" name="target_id" id="targetSelect">
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($targets as $target): ?>
                                <option value="<?= $target['id'] ?>">
                                    <?= h($target['title']) ?> (<?= h($target['category_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4" id="categorySelectContainer" style="display:none;">
                        <label class="form-label">Επιλογή Κατηγορίας</label>
                        <select class="form-select" name="category_id" id="categorySelect">
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <button type="button" class="btn btn-warning w-100" onclick="executeBulkAction()">
                            <i class="bi bi-lightning"></i> Εκτέλεση
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Questions Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($questions)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Δεν βρέθηκαν ερωτήσεις με τα επιλεγμένα φίλτρα.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>ID</th>
                                <th>Ερώτηση</th>
                                <th>Τύπος</th>
                                <th>Κατηγορία</th>
                                <th>Κατάσταση</th>
                                <th style="width: 200px;">Ενέργειες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="form-check-input question-checkbox" value="<?= $q['id'] ?>">
                                    </td>
                                    <td><?= $q['id'] ?></td>
                                    <td>
                                        <?= h(mb_substr($q['question_text'], 0, 100)) ?><?= mb_strlen($q['question_text']) > 100 ? '...' : '' ?>
                                    </td>
                                    <td>
                                        <?php
                                        $typeLabels = [
                                            'MULTIPLE_CHOICE' => 'Πολλαπλής',
                                            'TRUE_FALSE' => 'Σωστό/Λάθος',
                                            'OPEN_ENDED' => 'Ανοιχτή'
                                        ];
                                        echo '<span class="badge bg-secondary">' . h($typeLabels[$q['question_type']] ?? $q['question_type']) . '</span>';
                                        ?>
                                    </td>
                                    <td><span class="badge bg-warning"><?= h($q['category_name']) ?></span></td>
                                    <td>
                                        <?php if (empty($q['assigned_to'])): ?>
                                            <span class="badge bg-danger"><i class="bi bi-exclamation-triangle"></i> Orphan</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= h($q['assigned_to']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" onclick="viewQuestion(<?= $q['id'] ?>)" title="Προβολή">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-primary" onclick="editQuestion(<?= $q['id'] ?>)" title="Επεξεργασία">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button class="btn btn-success" onclick="assignQuestion(<?= $q['id'] ?>)" title="Ανάθεση">
                                                <i class="bi bi-link-45deg"></i>
                                            </button>
                                            <button class="btn btn-danger" onclick="deleteQuestion(<?= $q['id'] ?>)" title="Διαγραφή">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-3">
                    <p class="text-muted mb-0">Σύνολο: <?= count($questions) ?> ερωτήσεις</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Question Modal -->
<div class="modal fade" id="addQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_question">
                
                <div class="modal-header">
                    <h5 class="modal-title">Νέα Ερώτηση (<?= $tab === 'exams' ? 'Διαγώνισμα' : 'Κουίζ' ?>)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Κατηγορία *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">-- Επιλέξτε Κατηγορία --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Ανάθεση σε <?= $tab === 'exams' ? 'Διαγώνισμα' : 'Κουίζ' ?> *</label>
                        <select name="target_id" class="form-select" required>
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($targets as $target): ?>
                                <option value="<?= $target['id'] ?>">
                                    <?= h($target['title']) ?> (<?= h($target['category_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Η ερώτηση θα ανατεθεί αμέσως στο επιλεγμένο <?= $tab === 'exams' ? 'διαγώνισμα' : 'κουίζ' ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Τύπος Ερώτησης *</label>
                        <select name="question_type" class="form-select" id="questionTypeAdd" required>
                            <option value="MULTIPLE_CHOICE">Πολλαπλής Επιλογής</option>
                            <option value="TRUE_FALSE">Σωστό/Λάθος</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Κείμενο Ερώτησης *</label>
                        <textarea name="question_text" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div id="mcOptionsAdd">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή A</label>
                                <input type="text" name="option_a" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή B</label>
                                <input type="text" name="option_b" class="form-control">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή C</label>
                                <input type="text" name="option_c" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή D</label>
                                <input type="text" name="option_d" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση</label>
                            <select name="correct_option" class="form-select">
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="tfOptionsAdd" style="display:none;">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle"></i> <strong>Σωστό/Λάθος:</strong> Ο εθελοντής θα επιλέξει αν η πρόταση είναι σωστή ή λανθασμένη.
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση *</label>
                            <select name="correct_option_tf" class="form-select" required>
                                <option value="">-- Επιλέξτε --</option>
                                <option value="T">Σωστό</option>
                                <option value="F">Λάθος</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Επεξήγηση (προαιρετικό)</label>
                        <textarea name="explanation" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Δημιουργία Ερώτησης</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_question">
                <input type="hidden" name="id" id="assignQuestionId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Ανάθεση Ερώτησης</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Επιλογή <?= $tab === 'exams' ? 'Διαγωνίσματος' : 'Κουίζ' ?> *</label>
                        <select name="target_id" class="form-select" required>
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($targets as $target): ?>
                                <option value="<?= $target['id'] ?>">
                                    <?= h($target['title']) ?> (<?= h($target['category_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">Ανάθεση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Question Modal -->
<div class="modal fade" id="editQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_question">
                <input type="hidden" name="id" id="editQuestionId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Επεξεργασία Ερώτησης</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Κατηγορία *</label>
                        <select name="category_id" id="editCategoryId" class="form-select" required>
                            <option value="">-- Επιλέξτε Κατηγορία --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Τύπος Ερώτησης *</label>
                        <select name="question_type" class="form-select" id="questionTypeEdit" required>
                            <option value="MULTIPLE_CHOICE">Πολλαπλής Επιλογής</option>
                            <option value="TRUE_FALSE">Σωστό/Λάθος</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Κείμενο Ερώτησης *</label>
                        <textarea name="question_text" id="editQuestionText" class="form-control" rows="3" required></textarea>
                    </div>
                    
                    <div id="mcOptionsEdit">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή A</label>
                                <input type="text" name="option_a" id="editOptionA" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή B</label>
                                <input type="text" name="option_b" id="editOptionB" class="form-control">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή C</label>
                                <input type="text" name="option_c" id="editOptionC" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Επιλογή D</label>
                                <input type="text" name="option_d" id="editOptionD" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση</label>
                            <select name="correct_option" id="editCorrectOption" class="form-select">
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="tfOptionsEdit" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Σωστή Απάντηση</label>
                            <select name="correct_option_tf" id="editCorrectOptionTF" class="form-select">
                                <option value="T">Σωστό</option>
                                <option value="F">Λάθος</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Επεξήγηση (προαιρετικό)</label>
                        <textarea name="explanation" id="editExplanation" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση Αλλαγών</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Question Modal -->
<div class="modal fade" id="viewQuestionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Προβολή Ερώτησης</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body">
                <div class="mb-3">
                    <strong>ID:</strong> <span id="viewId"></span>
                </div>
                <div class="mb-3">
                    <strong>Τύπος:</strong> <span id="viewType"></span>
                </div>
                <div class="mb-3">
                    <strong>Ερώτηση:</strong>
                    <p id="viewQuestionText" class="border p-3 bg-light rounded"></p>
                </div>
                <div class="mb-3" id="viewOptionsContainer">
                    <strong>Επιλογές:</strong>
                    <ul id="viewOptions" class="list-group mt-2"></ul>
                </div>
                <div class="mb-3">
                    <strong>Σωστή Απάντηση:</strong> <span id="viewCorrectOption" class="badge bg-success"></span>
                </div>
                <div class="mb-3" id="viewExplanationContainer">
                    <strong>Επεξήγηση:</strong>
                    <p id="viewExplanation" class="border p-3 bg-light rounded"></p>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle question type options
document.getElementById('questionTypeAdd')?.addEventListener('change', function() {
    if (this.value === 'MULTIPLE_CHOICE') {
        document.getElementById('mcOptionsAdd').style.display = 'block';
        document.getElementById('tfOptionsAdd').style.display = 'none';
    } else {
        document.getElementById('mcOptionsAdd').style.display = 'none';
        document.getElementById('tfOptionsAdd').style.display = 'block';
    }
});

document.getElementById('questionTypeEdit')?.addEventListener('change', function() {
    if (this.value === 'MULTIPLE_CHOICE') {
        document.getElementById('mcOptionsEdit').style.display = 'block';
        document.getElementById('tfOptionsEdit').style.display = 'none';
    } else {
        document.getElementById('mcOptionsEdit').style.display = 'none';
        document.getElementById('tfOptionsEdit').style.display = 'block';
    }
});

// Select all checkboxes
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.question-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Bulk action dropdown
document.getElementById('bulkAction')?.addEventListener('change', function() {
    const action = this.value;
    document.getElementById('targetSelectContainer').style.display = action === 'assign' ? 'block' : 'none';
    document.getElementById('categorySelectContainer').style.display = action === 'change_category' ? 'block' : 'none';
});

function executeBulkAction() {
    const action = document.getElementById('bulkAction').value;
    if (!action) {
        alert('Επιλέξτε μια ενέργεια');
        return;
    }
    
    const selected = Array.from(document.querySelectorAll('.question-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        alert('Επιλέξτε τουλάχιστον μία ερώτηση');
        return;
    }
    
    if (action === 'delete' && !confirm('Θέλετε σίγουρα να διαγράψετε ' + selected.length + ' ερωτήσεις;')) {
        return;
    }
    
    if (action === 'assign') {
        const targetId = document.getElementById('targetSelect').value;
        if (!targetId) {
            alert('Επιλέξτε <?= $tab === 'exams' ? 'διαγώνισμα' : 'κουίζ' ?>');
            return;
        }
    }
    
    if (action === 'change_category') {
        const categoryId = document.getElementById('categorySelect').value;
        if (!categoryId) {
            alert('Επιλέξτε κατηγορία');
            return;
        }
    }
    
    const form = document.getElementById('bulkForm');
    form.querySelector('input[name="action"]')?.remove();
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'bulk_' + action;
    form.appendChild(actionInput);
    
    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    form.submit();
}

function assignQuestion(id) {
    document.getElementById('assignQuestionId').value = id;
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}

function deleteQuestion(id) {
    if (!confirm('Θέλετε σίγουρα να διαγράψετε αυτή την ερώτηση;')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'post';
    form.innerHTML = '<?= csrfField() ?>' +
        '<input type="hidden" name="action" value="delete_question">' +
        '<input type="hidden" name="id" value="' + id + '">';
    document.body.appendChild(form);
    form.submit();
}

function viewQuestion(id) {
    fetch('?tab=<?= $tab ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'get_question',
            id: id,
            csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
        })
    })
    .then(res => res.json())
    .then(q => {
        document.getElementById('viewId').textContent = q.id;
        
        const typeLabels = {
            'MULTIPLE_CHOICE': 'Πολλαπλής Επιλογής',
            'TRUE_FALSE': 'Σωστό/Λάθος'
        };
        document.getElementById('viewType').textContent = typeLabels[q.question_type] || q.question_type;
        document.getElementById('viewQuestionText').textContent = q.question_text;
        
        if (q.question_type === 'MULTIPLE_CHOICE') {
            document.getElementById('viewOptionsContainer').style.display = 'block';
            const optionsList = document.getElementById('viewOptions');
            optionsList.innerHTML = '';
            ['A', 'B', 'C', 'D'].forEach(letter => {
                const optionKey = 'option_' + letter.toLowerCase();
                if (q[optionKey]) {
                    const li = document.createElement('li');
                    li.className = 'list-group-item';
                    function escHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
                    if (q.correct_option === letter) {
                        li.classList.add('list-group-item-success');
                        li.innerHTML = '<strong>' + escHtml(letter) + ':</strong> ' + escHtml(q[optionKey]) + ' <span class="badge bg-success float-end">Σωστή</span>';
                    } else {
                        li.innerHTML = '<strong>' + escHtml(letter) + ':</strong> ' + escHtml(q[optionKey]);
                    }
                    optionsList.appendChild(li);
                }
            });
        } else {
            document.getElementById('viewOptionsContainer').style.display = 'none';
        }
        
        document.getElementById('viewCorrectOption').textContent = q.correct_option === 'T' ? 'Σωστό' : (q.correct_option === 'F' ? 'Λάθος' : q.correct_option);
        
        if (q.explanation) {
            document.getElementById('viewExplanationContainer').style.display = 'block';
            document.getElementById('viewExplanation').textContent = q.explanation;
        } else {
            document.getElementById('viewExplanationContainer').style.display = 'none';
        }
        
        new bootstrap.Modal(document.getElementById('viewQuestionModal')).show();
    })
    .catch(err => alert('Σφάλμα φόρτωσης ερώτησης'));
}

function editQuestion(id) {
    fetch('?tab=<?= $tab ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'get_question',
            id: id,
            csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>'
        })
    })
    .then(res => res.json())
    .then(q => {
        document.getElementById('editQuestionId').value = q.id;
        document.getElementById('editCategoryId').value = q.category_id;
        document.getElementById('questionTypeEdit').value = q.question_type;
        document.getElementById('editQuestionText').value = q.question_text;
        
        if (q.question_type === 'MULTIPLE_CHOICE') {
            document.getElementById('mcOptionsEdit').style.display = 'block';
            document.getElementById('tfOptionsEdit').style.display = 'none';
            document.getElementById('editOptionA').value = q.option_a || '';
            document.getElementById('editOptionB').value = q.option_b || '';
            document.getElementById('editOptionC').value = q.option_c || '';
            document.getElementById('editOptionD').value = q.option_d || '';
            document.getElementById('editCorrectOption').value = q.correct_option;
        } else {
            document.getElementById('mcOptionsEdit').style.display = 'none';
            document.getElementById('tfOptionsEdit').style.display = 'block';
            // Normalize TF value for the select
            var tfVal = (q.correct_option || 'T').toUpperCase().charAt(0);
            document.getElementById('editCorrectOptionTF').value = (tfVal === 'F') ? 'F' : 'T';
        }
        
        document.getElementById('editExplanation').value = q.explanation || '';
        
        new bootstrap.Modal(document.getElementById('editQuestionModal')).show();
    })
    .catch(err => alert('Σφάλμα φόρτωσης ερώτησης'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
