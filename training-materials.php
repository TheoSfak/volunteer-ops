<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Εκπαιδευτικό Υλικό';
$user = getCurrentUser();

// Get filter
$categoryFilter = get('category', '');

// Build query
$where = ['1=1'];
$params = [];

if (!empty($categoryFilter)) {
    $where[] = 'tm.category_id = ?';
    $params[] = $categoryFilter;
}

// Fetch materials
$materials = dbFetchAll("
    SELECT tm.*, tc.name as category_name, tc.icon as category_icon,
           u.name as uploaded_by_name
    FROM training_materials tm
    INNER JOIN training_categories tc ON tm.category_id = tc.id
    INNER JOIN users u ON tm.uploaded_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY tm.created_at DESC
", $params);

// Get categories for filter
$categories = dbFetchAll("
    SELECT id, name, icon 
    FROM training_categories 
    WHERE is_active = 1 
    ORDER BY display_order, name
");

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <?php displayFlash(); ?>
    
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-file-earmark-pdf me-2"></i>Εκπαιδευτικό Υλικό
        </h1>
        <a href="training.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Επιστροφή
        </a>
    </div>
    
    <!-- Filters -->
    <?php if (!empty($categories)): ?>
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Κατηγορία</label>
                        <select name="category" class="form-select">
                            <option value="">Όλες οι Κατηγορίες</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['icon']) ?> <?= h($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-funnel"></i> Φιλτράρισμα
                        </button>
                        <a href="training-materials.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Καθαρισμός
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Materials List -->
    <?php if (empty($materials)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Δεν βρέθηκαν εκπαιδευτικά υλικά <?= $categoryFilter ? 'για την επιλεγμένη κατηγορία' : '' ?>.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($materials as $material): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body">
                            <!-- Icon and Category -->
                            <div class="d-flex align-items-center mb-3">
                                <div class="text-danger" style="font-size: 3rem;">
                                    <i class="bi bi-file-earmark-pdf-fill"></i>
                                </div>
                                <div class="ms-auto">
                                    <span class="badge bg-primary">
                                        <?= h($material['category_icon']) ?> <?= h($material['category_name']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Title -->
                            <h5 class="card-title mb-2"><?= h($material['title']) ?></h5>
                            
                            <!-- Description -->
                            <?php if (!empty($material['description'])): ?>
                                <p class="text-muted small mb-3"><?= nl2br(h($material['description'])) ?></p>
                            <?php endif; ?>
                            
                            <!-- Metadata -->
                            <div class="text-muted small mb-3">
                                <div><i class="bi bi-hdd"></i> <?= formatFileSize($material['file_size']) ?></div>
                                <div><i class="bi bi-person"></i> Ανέβηκε από: <?= h($material['uploaded_by_name']) ?></div>
                                <div><i class="bi bi-calendar"></i> <?= formatDate($material['created_at']) ?></div>
                            </div>
                            
                            <!-- Download Button -->
                            <a href="training-material-download.php?id=<?= $material['id'] ?>" 
                               class="btn btn-primary w-100" 
                               target="_blank">
                                <i class="bi bi-download"></i> Κατέβασμα / Προβολή
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
