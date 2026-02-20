<?php
/**
 * VolunteerOps - Inventory Categories Management
 * Admin page to manage inventory categories.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

if (!canManageInventory()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('inventory.php');
}

$pageTitle = '?at?????e? ??????';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create') {
        $name        = trim(post('name'));
        $description = post('description');
        $icon        = post('icon', '??');
        $color       = post('color', '#6c757d');
        $sortOrder   = (int)post('sort_order', 0);

        if (empty($name)) {
            setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
        } else {
            try {
                dbInsert("
                    INSERT INTO inventory_categories (name, description, icon, color, sort_order)
                    VALUES (?, ?, ?, ?, ?)
                ", [$name, $description, $icon, $color, $sortOrder]);
                logAudit('inventory_category_create', 'inventory_categories', 0);
                setFlash('success', '? ?at?????a d?�?????????e.');
            } catch (Exception $e) {
                setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
            }
        }
        redirect('inventory-categories.php');
    }

    if ($action === 'update') {
        $catId       = (int)post('category_id');
        $name        = trim(post('name'));
        $description = post('description');
        $icon        = post('icon', '??');
        $color       = post('color', '#6c757d');
        $sortOrder   = (int)post('sort_order', 0);

        if (empty($name)) {
            setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
        } else {
            try {
                dbExecute("
                    UPDATE inventory_categories 
                    SET name = ?, description = ?, icon = ?, color = ?, sort_order = ?
                    WHERE id = ?
                ", [$name, $description, $icon, $color, $sortOrder, $catId]);
                logAudit('inventory_category_update', 'inventory_categories', $catId);
                setFlash('success', '? ?at?????a e??�e?????e.');
            } catch (Exception $e) {
                setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
            }
        }
        redirect('inventory-categories.php');
    }

    if ($action === 'toggle') {
        $catId    = (int)post('category_id');
        $isActive = (int)post('is_active');
        dbExecute("UPDATE inventory_categories SET is_active = ? WHERE id = ?", [$isActive, $catId]);
        logAudit('inventory_category_toggle', 'inventory_categories', $catId);
        setFlash('success', $isActive ? '? ?at?????a e?e???p??????e.' : '? ?at?????a ape?e???p??????e.');
        redirect('inventory-categories.php');
    }
}

// Fetch all categories (including inactive for admin)
$categories = dbFetchAll("
    SELECT c.*, 
           (SELECT COUNT(*) FROM inventory_items i WHERE i.category_id = c.id AND i.is_active = 1) AS item_count
    FROM inventory_categories c
    ORDER BY c.sort_order, c.name
");

// Editing mode
$editId  = (int)get('edit', 0);
$editCat = null;
if ($editId) {
    foreach ($categories as $cat) {
        if ($cat['id'] == $editId) {
            $editCat = $cat;
            break;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-tags me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>??s? sta ?????
    </a>
</div>

<div class="row">
    <!-- Left: Create/Edit Form -->
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <?= $editCat ? '?pe?e??as?a ?at?????a?' : '??a ?at?????a' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editCat ? 'update' : 'create' ?>">
                    <?php if ($editCat): ?>
                        <input type="hidden" name="category_id" value="<?= $editCat['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">???�a *</label>
                        <input type="text" class="form-control" name="name" 
                               value="<?= h($editCat['name'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">?e????af?</label>
                        <textarea class="form-control" name="description" rows="2"
                        ><?= h($editCat['description'] ?? '') ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">??????d??</label>
                            <input type="text" class="form-control text-center fs-4" name="icon" 
                                   value="<?= h($editCat['icon'] ?? '??') ?>" maxlength="5">
                            <small class="form-text text-muted">Emoji</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">???�a</label>
                            <input type="color" class="form-control form-control-color w-100" name="color" 
                                   value="<?= h($editCat['color'] ?? '#6c757d') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Se???</label>
                            <input type="number" class="form-control" name="sort_order" 
                                   value="<?= (int)($editCat['sort_order'] ?? 0) ?>" min="0">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>
                            <?= $editCat ? '?p????e?s?' : '??�??????a' ?>
                        </button>
                        <?php if ($editCat): ?>
                            <a href="inventory-categories.php" class="btn btn-outline-secondary">?????s?</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Categories List -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-body p-0">
                <?php if (empty($categories)): ?>
                    <p class="text-muted text-center py-4">?e? ?p?????? ?at?????e?.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th>?at?????a</th>
                                    <th class="text-center">?????</th>
                                    <th class="text-center">?at?stas?</th>
                                    <th class="text-end">?????e?e?</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $cat): ?>
                                    <tr class="<?= !$cat['is_active'] ? 'table-secondary' : '' ?>">
                                        <td><?= (int)$cat['sort_order'] ?></td>
                                        <td>
                                            <span class="badge me-1" style="background-color: <?= h($cat['color']) ?>; font-size: 1.1em;">
                                                <?= $cat['icon'] ?>
                                            </span>
                                            <strong><?= h($cat['name']) ?></strong>
                                            <?php if ($cat['description']): ?>
                                                <br><small class="text-muted"><?= h($cat['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?= (int)$cat['item_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($cat['is_active']): ?>
                                                <span class="badge bg-success">??e???</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">??e?e???</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="inventory-categories.php?edit=<?= $cat['id'] ?>" 
                                                   class="btn btn-outline-secondary" title="?pe?e??as?a">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                                    <input type="hidden" name="is_active" value="<?= $cat['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $cat['is_active'] ? 'warning' : 'success' ?> btn-sm"
                                                            title="<?= $cat['is_active'] ? '?pe?e???p???s?' : '??e???p???s?' ?>">
                                                        <i class="bi bi-<?= $cat['is_active'] ? 'pause' : 'play' ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
