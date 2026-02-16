<?php
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Διαχείριση Εκπαίδευσης';
$user = getCurrentUser();

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'add_category') {
        $name = post('name');
        $description = post('description');
        $icon = post('icon', '📚');
        $order = post('display_order', 0);
        
        $newId = dbInsert("INSERT INTO training_categories (name, description, icon, display_order) VALUES (?, ?, ?, ?)",
            [$name, $description, $icon, $order]);
        logAudit('create', 'training_categories', $newId);
        setFlash('success', 'Η κατηγορία προστέθηκε επιτυχώς.');
        redirect('training-admin.php');
        
    } elseif ($action === 'edit_category') {
        $id = post('id');
        $name = post('name');
        $description = post('description');
        $icon = post('icon', '📚');
        $order = post('display_order', 0);
        
        dbExecute("UPDATE training_categories SET name = ?, description = ?, icon = ?, display_order = ? WHERE id = ?",
            [$name, $description, $icon, $order, $id]);
        logAudit('update', 'training_categories', $id);
        setFlash('success', 'Η κατηγορία ενημερώθηκε επιτυχώς.');
        redirect('training-admin.php');
        
    } elseif ($action === 'delete_category') {
        $id = post('id');
        dbExecute("DELETE FROM training_categories WHERE id = ?", [$id]);
        logAudit('delete', 'training_categories', $id);
        setFlash('success', 'Η κατηγορία διαγράφηκε.');
        redirect('training-admin.php');
        
    } elseif ($action === 'upload_material') {
        $categoryId = post('category_id');
        $title = post('title');
        $description = post('description');
        
        // Handle file upload
        if (!empty($_FILES['file']['name'])) {
            $file = $_FILES['file'];
            
            // Validate
            if ($file['size'] > TRAINING_MAX_FILE_SIZE) {
                setFlash('error', 'Το αρχείο είναι πολύ μεγάλο. Μέγιστο μέγεθος: ' . formatFileSize(TRAINING_MAX_FILE_SIZE));
                redirect('training-admin.php');
            }
            
            if (!in_array($file['type'], TRAINING_ALLOWED_TYPES)) {
                setFlash('error', 'Μόνο PDF αρχεία επιτρέπονται.');
                redirect('training-admin.php');
            }
            
            // Create directory if needed
            if (!is_dir(TRAINING_UPLOAD_PATH)) {
                mkdir(TRAINING_UPLOAD_PATH, 0755, true);
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = 'material_' . time() . '_' . uniqid() . '.' . strtolower($ext);
            
            if (move_uploaded_file($file['tmp_name'], TRAINING_UPLOAD_PATH . $newFilename)) {
                // Save to database
                $newId = dbInsert("
                    INSERT INTO training_materials (category_id, title, description, file_path, file_type, file_size, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ", [$categoryId, $title, $description, $newFilename, $file['type'], $file['size'], $user['id']]);
                
                logAudit('create', 'training_materials', $newId);
                setFlash('success', 'Το υλικό ανέβηκε επιτυχώς.');
            } else {
                setFlash('error', 'Σφάλμα κατά το ανέβασμα του αρχείου.');
            }
        }
        redirect('training-admin.php');
        
    } elseif ($action === 'delete_material') {
        $id = post('id');
        $material = dbFetchOne("SELECT * FROM training_materials WHERE id = ?", [$id]);
        if ($material) {
            // Delete file
            $filePath = TRAINING_UPLOAD_PATH . $material['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            // Delete from DB
            dbExecute("DELETE FROM training_materials WHERE id = ?", [$id]);
            logAudit('delete', 'training_materials', $id);
            setFlash('success', 'Το υλικό διαγράφηκε.');
        }
        redirect('training-admin.php');
    }
}

// Get active tab
$activeTab = get('tab', 'categories');

// Fetch data
$categories = dbFetchAll("SELECT * FROM training_categories ORDER BY display_order, name");
$materials = dbFetchAll("
    SELECT tm.*, tc.name as category_name, u.name as uploaded_by_name
    FROM training_materials tm
    INNER JOIN training_categories tc ON tm.category_id = tc.id
    INNER JOIN users u ON tm.uploaded_by = u.id
    ORDER BY tm.created_at DESC
");

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-gear me-2"></i>Διαχείριση Εκπαίδευσης
        </h1>
    </div>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'categories' ? 'active' : '' ?>" href="?tab=categories">
                <i class="bi bi-folder"></i> Κατηγορίες
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'materials' ? 'active' : '' ?>" href="?tab=materials">
                <i class="bi bi-file-earmark-pdf"></i> Εκπαιδευτικό Υλικό
            </a>
        </li>
    </ul>
    
    <?php if ($activeTab === 'categories'): ?>
        <!-- Categories Tab -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Κατηγορίες</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-plus-lg"></i> Νέα Κατηγορία
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Icon</th>
                                <th>Όνομα</th>
                                <th>Περιγραφή</th>
                                <th>Σειρά</th>
                                <th>Ενεργή</th>
                                <th>Ενέργειες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?= h($cat['icon']) ?></td>
                                    <td><?= h($cat['name']) ?></td>
                                    <td><?= h($cat['description']) ?></td>
                                    <td><?= $cat['display_order'] ?></td>
                                    <td><?= $cat['is_active'] ? '<span class="badge bg-success">Ναι</span>' : '<span class="badge bg-secondary">Όχι</span>' ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" onclick="editCategory(<?= htmlspecialchars(json_encode($cat)) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="deleteCategory(<?= $cat['id'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Materials Tab -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Εκπαιδευτικό Υλικό</h5>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadMaterialModal">
                    <i class="bi bi-upload"></i> Ανέβασμα Υλικού
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Τίτλος</th>
                                <th>Κατηγορία</th>
                                <th>Μέγεθος</th>
                                <th>Ανέβηκε από</th>
                                <th>Ημερομηνία</th>
                                <th>Ενέργειες</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $mat): ?>
                                <tr>
                                    <td><?= h($mat['title']) ?></td>
                                    <td><span class="badge bg-primary"><?= h($mat['category_name']) ?></span></td>
                                    <td><?= formatFileSize($mat['file_size']) ?></td>
                                    <td><?= h($mat['uploaded_by_name']) ?></td>
                                    <td><?= formatDate($mat['created_at']) ?></td>
                                    <td>
                                        <a href="training-material-download.php?id=<?= $mat['id'] ?>" class="btn btn-sm btn-info" target="_blank">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή υλικού;');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_material">
                                            <input type="hidden" name="id" value="<?= $mat['id'] ?>">
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
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_category">
                <div class="modal-header">
                    <h5 class="modal-title">Νέα Κατηγορία</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Icon (emoji)</label>
                        <input type="text" name="icon" class="form-control" value="📚">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Σειρά Εμφάνισης</label>
                        <input type="number" name="display_order" class="form-control" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_category">
                <input type="hidden" name="id" id="edit_cat_id">
                <div class="modal-header">
                    <h5 class="modal-title">Επεξεργασία Κατηγορίας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα *</label>
                        <input type="text" name="name" id="edit_cat_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea name="description" id="edit_cat_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Icon</label>
                        <input type="text" name="icon" id="edit_cat_icon" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Σειρά</label>
                        <input type="number" name="display_order" id="edit_cat_order" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Ενημέρωση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload Material Modal -->
<div class="modal fade" id="uploadMaterialModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="upload_material">
                <div class="modal-header">
                    <h5 class="modal-title">Ανέβασμα Υλικού</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Κατηγορία *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">-- Επιλέξτε --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= h($cat['icon']) ?> <?= h($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Τίτλος *</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Αρχείο PDF *</label>
                        <input type="file" name="file" class="form-control" accept=".pdf" required>
                        <small class="text-muted">Μέγιστο μέγεθος: <?= formatFileSize(TRAINING_MAX_FILE_SIZE) ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Ανέβασμα</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCategory(cat) {
    document.getElementById('edit_cat_id').value = cat.id;
    document.getElementById('edit_cat_name').value = cat.name;
    document.getElementById('edit_cat_desc').value = cat.description || '';
    document.getElementById('edit_cat_icon').value = cat.icon;
    document.getElementById('edit_cat_order').value = cat.display_order;
    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
}

function deleteCategory(id) {
    if (confirm('Διαγραφή κατηγορίας; Θα διαγραφούν όλα τα σχετικά υλικά!')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<?= csrfField() ?>' +
            '<input type="hidden" name="action" value="delete_category">' +
            '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
