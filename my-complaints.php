<?php
/**
 * VolunteerOps - My Complaints
 * Τα παράπονα του εθελοντή
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Τα Παράπονά μου';
$currentUser = getCurrentUser();
$userId = getCurrentUserId();

// Fetch user's complaints
$complaints = dbFetchAll(
    "SELECT c.*, 
            m.title as mission_title,
            responder.name as responded_by_name
     FROM complaints c
     LEFT JOIN missions m ON c.mission_id = m.id
     LEFT JOIN users responder ON c.responded_by = responder.id
     WHERE c.user_id = ?
     ORDER BY c.created_at DESC",
    [$userId]
);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-chat-left-dots me-2"></i>Τα Παράπονά μου</h2>
        <a href="complaint-form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέο Παράπονο
        </a>
    </div>
    
    <?= showFlash() ?>
    
    <?php if (empty($complaints)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-chat-left-dots text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3 mb-0">Δεν έχετε υποβάλει κανένα παράπονο.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Θέμα</th>
                            <th>Κατηγορία</th>
                            <th>Προτεραιότητα</th>
                            <th>Αποστολή</th>
                            <th>Κατάσταση</th>
                            <th>Ημ/νία</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaints as $c): ?>
                            <tr>
                                <td><?= $c['id'] ?></td>
                                <td>
                                    <strong><?= h($c['subject']) ?></strong>
                                    <?php if (!empty($c['admin_response'])): ?>
                                        <br><small class="text-success"><i class="bi bi-reply"></i> Υπάρχει απάντηση</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(COMPLAINT_CATEGORY_LABELS[$c['category']] ?? $c['category']) ?></td>
                                <td>
                                    <span class="badge bg-<?= COMPLAINT_PRIORITY_COLORS[$c['priority']] ?? 'secondary' ?>">
                                        <?= h(COMPLAINT_PRIORITY_LABELS[$c['priority']] ?? $c['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c['mission_title']): ?>
                                        <small><?= h($c['mission_title']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= COMPLAINT_STATUS_COLORS[$c['status']] ?? 'secondary' ?>">
                                        <?= h(COMPLAINT_STATUS_LABELS[$c['status']] ?? $c['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= formatDateTime($c['created_at']) ?></small></td>
                                <td>
                                    <a href="complaint-view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
