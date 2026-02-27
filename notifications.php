<?php
/**
 * VolunteerOps - Ειδοποιήσεις χρήστη
 * View, mark-read, delete in-app notifications.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Ειδοποιήσεις';
$userId = getCurrentUserId();

// ── Handle single mark-read via GET (from bell dropdown click) ───────────────
$markId = (int) get('mark', 0);
if ($markId > 0) {
    dbExecute(
        "UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL",
        [$markId, $userId]
    );
    redirect('notifications.php');
}

// ── Handle mark-all-read via GET link ────────────────────────────────────────
if (get('action') === 'mark_all_read') {
    dbExecute(
        "UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL",
        [$userId]
    );
    setFlash('success', 'Όλες οι ειδοποιήσεις σημειώθηκαν ως αναγνωσμένες.');
    redirect('notifications.php');
}

// ── Handle POST actions ──────────────────────────────────────────────────────
$filter = get('filter', 'all'); // all | unread | read

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'mark_read') {
        $nId = (int) post('notification_id');
        dbExecute(
            "UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL",
            [$nId, $userId]
        );
    } elseif ($action === 'mark_unread') {
        $nId = (int) post('notification_id');
        dbExecute(
            "UPDATE notifications SET read_at = NULL WHERE id = ? AND user_id = ?",
            [$nId, $userId]
        );
    } elseif ($action === 'delete') {
        $nId = (int) post('notification_id');
        dbExecute(
            "DELETE FROM notifications WHERE id = ? AND user_id = ?",
            [$nId, $userId]
        );
        setFlash('success', 'Η ειδοποίηση διαγράφηκε.');
    } elseif ($action === 'delete_all_read') {
        $deleted = dbExecute(
            "DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL",
            [$userId]
        );
        setFlash('success', 'Διαγράφηκαν ' . $deleted . ' αναγνωσμένες ειδοποιήσεις.');
    }

    redirect('notifications.php' . ($filter ? '?filter=' . $filter : ''));
}

// ── Filter ───────────────────────────────────────────────────────────────────
// $filter already set above before POST handler

$whereClause = "WHERE user_id = ?";
$params = [$userId];
if ($filter === 'unread') {
    $whereClause .= " AND read_at IS NULL";
} elseif ($filter === 'read') {
    $whereClause .= " AND read_at IS NOT NULL";
}

// ── Pagination ───────────────────────────────────────────────────────────────
$total = (int) dbFetchValue("SELECT COUNT(*) FROM notifications $whereClause", $params);
$pagination = paginate($total, (int) get('page', 1), 20);
$notifications = dbFetchAll(
    "SELECT * FROM notifications $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

$unreadCount = (int) dbFetchValue(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL", [$userId]
);
$readCount = (int) dbFetchValue(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NOT NULL", [$userId]
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h2><i class="bi bi-bell me-2"></i>Ειδοποιήσεις</h2>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($unreadCount > 0): ?>
            <a href="notifications.php?action=mark_all_read" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-check-all me-1"></i>Ανάγνωση Όλων (<?= $unreadCount ?>)
            </a>
        <?php endif; ?>
        <?php if ($readCount > 0): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή όλων των αναγνωσμένων ειδοποιήσεων;');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_all_read">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i>Διαγραφή Αναγνωσμένων (<?= $readCount ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Filter tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" href="notifications.php?filter=all">
            Όλες <span class="badge bg-secondary"><?= $total ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'unread' ? 'active' : '' ?>" href="notifications.php?filter=unread">
            Μη Αναγνωσμένες <span class="badge bg-primary"><?= $unreadCount ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter === 'read' ? 'active' : '' ?>" href="notifications.php?filter=read">
            Αναγνωσμένες <span class="badge bg-success"><?= $readCount ?></span>
        </a>
    </li>
</ul>

<?php if (empty($notifications)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-bell-slash display-4 d-block mb-3"></i>
        <p class="fs-5">Δεν υπάρχουν ειδοποιήσεις<?= $filter !== 'all' ? ' σε αυτή την κατηγορία' : '' ?>.</p>
    </div>
<?php else: ?>
    <div class="list-group">
        <?php foreach ($notifications as $n): ?>
            <div class="list-group-item <?= empty($n['read_at']) ? 'list-group-item-light border-start border-primary border-3' : '' ?>">
                <div class="d-flex align-items-start">
                    <div class="me-3 mt-1">
                        <?php
                        $iconClass = match($n['type'] ?? 'info') {
                            'success' => 'bi-check-circle-fill text-success',
                            'warning' => 'bi-exclamation-triangle-fill text-warning',
                            'danger', 'error' => 'bi-x-circle-fill text-danger',
                            default => 'bi-info-circle-fill text-primary',
                        };
                        ?>
                        <i class="bi <?= $iconClass ?> fs-5"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start">
                            <h6 class="mb-1 <?= empty($n['read_at']) ? 'fw-bold' : 'text-muted' ?>">
                                <?= h($n['title']) ?>
                            </h6>
                            <small class="text-muted text-nowrap ms-2"><?= formatDateTime($n['created_at']) ?></small>
                        </div>
                        <p class="mb-1 <?= empty($n['read_at']) ? '' : 'text-muted' ?>"><?= h($n['message']) ?></p>
                        <div class="d-flex gap-1 mt-1">
                            <?php if (empty($n['read_at'])): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2" title="Σημείωση ως αναγνωσμένο">
                                        <i class="bi bi-check2"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_unread">
                                    <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2" title="Σημείωση ως μη αναγνωσμένο">
                                        <i class="bi bi-circle"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="notification_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Διαγραφή"
                                        onclick="return confirm('Διαγραφή ειδοποίησης;');">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-3">
        <?= paginationLinks($pagination) ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
