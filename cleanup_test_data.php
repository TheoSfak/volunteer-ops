<?php
/**
 * Production Test Data Cleanup Script
 * Deletes all test/debug users and test missions with related data
 * DANGER: This will permanently delete data - use with caution!
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Καθαρισμός Δεδομένων Δοκιμών';

// Action handling
$action = post('action');
$preview = true;

if ($action === 'delete_users') {
    verifyCsrf();
    $userIds = post('user_ids', []);
    
    if (!empty($userIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            
            // Delete related data
            dbExecute("DELETE FROM notifications WHERE user_id IN ($placeholders)", $userIds);
            dbExecute("DELETE FROM volunteer_points WHERE user_id IN ($placeholders)", $userIds);
            dbExecute("DELETE FROM participation_requests WHERE volunteer_id IN ($placeholders)", $userIds);
            
            // Delete users
            dbExecute("DELETE FROM users WHERE id IN ($placeholders)", $userIds);
            
            logAudit('DELETE', 'users', 'bulk_test_users');
            
            setFlash('success', 'Διαγράφηκαν ' . count($userIds) . ' χρήστες δοκιμών και τα σχετικά δεδομένα.');
        } catch (Exception $e) {
            setFlash('error', 'Σφάλμα κατά τη διαγραφή: ' . h($e->getMessage()));
        }
    }
    redirect('cleanup_test_data.php');
}

if ($action === 'delete_missions') {
    verifyCsrf();
    $missionIds = post('mission_ids', []);
    
    if (!empty($missionIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($missionIds), '?'));
            
            // Get shift IDs for these missions
            $shiftIds = dbFetchAll("SELECT id FROM shifts WHERE mission_id IN ($placeholders)", $missionIds);
            $shiftIdArray = array_column($shiftIds, 'id');
            
            if (!empty($shiftIdArray)) {
                $shiftPlaceholders = implode(',', array_fill(0, count($shiftIdArray), '?'));
                
                // Delete participation requests
                dbExecute("DELETE FROM participation_requests WHERE shift_id IN ($shiftPlaceholders)", $shiftIdArray);
                
                // Delete volunteer points
                dbExecute("DELETE FROM volunteer_points WHERE shift_id IN ($shiftPlaceholders)", $shiftIdArray);
            }
            
            // Delete shifts
            dbExecute("DELETE FROM shifts WHERE mission_id IN ($placeholders)", $missionIds);
            
            // Delete missions
            dbExecute("DELETE FROM missions WHERE id IN ($placeholders)", $missionIds);
            
            logAudit('DELETE', 'missions', 'bulk_test_missions');
            
            setFlash('success', 'Διαγράφηκαν ' . count($missionIds) . ' αποστολές δοκιμών και τα σχετικά δεδομένα.');
        } catch (Exception $e) {
            setFlash('error', 'Σφάλμα κατά τη διαγραφή: ' . h($e->getMessage()));
        }
    }
    redirect('cleanup_test_data.php');
}

// Find test users
$testUsers = dbFetchAll("
    SELECT id, name, email, role, created_at
    FROM users
    WHERE LOWER(name) LIKE '%test%'
       OR LOWER(name) LIKE '%debug%'
       OR LOWER(name) LIKE '%demo%'
       OR LOWER(email) LIKE '%test%'
       OR LOWER(email) LIKE '%debug%'
       OR LOWER(email) LIKE '%demo%'
    ORDER BY created_at DESC
");

// Count related data for test users
$testUserStats = [];
if (!empty($testUsers)) {
    foreach ($testUsers as $user) {
        $testUserStats[$user['id']] = [
            'participations' => dbFetchValue("SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ?", [$user['id']]),
            'points' => dbFetchValue("SELECT COUNT(*) FROM volunteer_points WHERE user_id = ?", [$user['id']]),
            'notifications' => dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$user['id']])
        ];
    }
}

// Find test missions
$testMissions = dbFetchAll("
    SELECT m.id, m.title, m.status, m.created_at, m.start_datetime,
           (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shifts_count,
           (SELECT COUNT(*) FROM participation_requests pr 
            JOIN shifts s ON pr.shift_id = s.id 
            WHERE s.mission_id = m.id) as participants_count
    FROM missions m
    WHERE LOWER(m.title) LIKE '%test%'
       OR LOWER(m.title) LIKE '%δοκιμή%'
       OR LOWER(m.title) LIKE '%demo%'
       OR LOWER(m.description) LIKE '%test%'
       OR LOWER(m.description) LIKE '%δοκιμή%'
    ORDER BY m.created_at DESC
");

include __DIR__ . '/includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>ΠΡΟΣΟΧΗ:</strong> Αυτό το script διαγράφει ΜΟΝΙΜΑ δεδομένα από την παραγωγική βάση. 
            Βεβαιωθείτε ότι έχετε δημιουργήσει backup πριν προχωρήσετε!
        </div>
    </div>
</div>

<?php displayFlash(); ?>

<!-- Test Users Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">
                    <i class="bi bi-people"></i> Χρήστες Δοκιμών
                    <?php if (!empty($testUsers)): ?>
                        <span class="badge bg-danger"><?= count($testUsers) ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($testUsers)): ?>
                    <p class="text-success mb-0">
                        <i class="bi bi-check-circle"></i> Δεν βρέθηκαν χρήστες δοκιμών.
                    </p>
                <?php else: ?>
                    <p class="text-muted">
                        Βρέθηκαν <?= count($testUsers) ?> χρήστες με "test", "debug" ή "demo" στο όνομα/email:
                    </p>
                    
                    <form method="post" onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν ΜΟΝΙΜΑ <?= count($testUsers) ?> χρήστες και ΟΛΑ τα σχετικά δεδομένα τους. Είστε σίγουροι;');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_users">
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select-all-users" checked></th>
                                        <th>ID</th>
                                        <th>Όνομα</th>
                                        <th>Email</th>
                                        <th>Ρόλος</th>
                                        <th>Δημιουργήθηκε</th>
                                        <th>Συμμετοχές</th>
                                        <th>Πόντοι</th>
                                        <th>Ειδοποιήσεις</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testUsers as $user): ?>
                                        <?php $stats = $testUserStats[$user['id']]; ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="user_ids[]" value="<?= h($user['id']) ?>" 
                                                       class="user-checkbox" checked>
                                            </td>
                                            <td><?= h($user['id']) ?></td>
                                            <td><?= h($user['name']) ?></td>
                                            <td><?= h($user['email']) ?></td>
                                            <td><?= roleBadge($user['role']) ?></td>
                                            <td><?= formatDateTime($user['created_at']) ?></td>
                                            <td>
                                                <?php if ($stats['participations'] > 0): ?>
                                                    <span class="badge bg-warning"><?= $stats['participations'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($stats['points'] > 0): ?>
                                                    <span class="badge bg-info"><?= $stats['points'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($stats['notifications'] > 0): ?>
                                                    <span class="badge bg-secondary"><?= $stats['notifications'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Διαγραφή Επιλεγμένων Χρηστών
                            </button>
                            <a href="volunteers.php" class="btn btn-secondary">Ακύρωση</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Test Missions Section -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">
                    <i class="bi bi-flag"></i> Αποστολές Δοκιμών
                    <?php if (!empty($testMissions)): ?>
                        <span class="badge bg-danger"><?= count($testMissions) ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($testMissions)): ?>
                    <p class="text-success mb-0">
                        <i class="bi bi-check-circle"></i> Δεν βρέθηκαν αποστολές δοκιμών.
                    </p>
                <?php else: ?>
                    <p class="text-muted">
                        Βρέθηκαν <?= count($testMissions) ?> αποστολές με "test", "δοκιμή" ή "demo" στον τίτλο/περιγραφή:
                    </p>
                    
                    <form method="post" onsubmit="return confirm('ΠΡΟΣΟΧΗ: Θα διαγραφούν ΜΟΝΙΜΑ <?= count($testMissions) ?> αποστολές και ΟΛΑ τα σχετικά δεδομένα (βάρδιες, συμμετοχές, εργασίες). Είστε σίγουροι;');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_missions">
                        
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select-all-missions" checked></th>
                                        <th>ID</th>
                                        <th>Τίτλος</th>
                                        <th>Κατάσταση</th>
                                        <th>Ημερομηνία Έναρξης</th>
                                        <th>Δημιουργήθηκε</th>
                                        <th>Βάρδιες</th>
                                        <th>Συμμετέχοντες</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testMissions as $mission): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="mission_ids[]" value="<?= h($mission['id']) ?>" 
                                                       class="mission-checkbox" checked>
                                            </td>
                                            <td><?= h($mission['id']) ?></td>
                                            <td><?= h($mission['title']) ?></td>
                                            <td><?= statusBadge($mission['status']) ?></td>
                                            <td><?= formatDateTime($mission['start_datetime']) ?></td>
                                            <td><?= formatDateTime($mission['created_at']) ?></td>
                                            <td>
                                                <?php if ($mission['shifts_count'] > 0): ?>
                                                    <span class="badge bg-info"><?= $mission['shifts_count'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($mission['participants_count'] > 0): ?>
                                                    <span class="badge bg-warning"><?= $mission['participants_count'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">0</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Διαγραφή Επιλεγμένων Αποστολών
                            </button>
                            <a href="missions.php" class="btn btn-secondary">Ακύρωση</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Select all users checkbox
document.getElementById('select-all-users')?.addEventListener('change', function() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = this.checked);
});

// Select all missions checkbox
document.getElementById('select-all-missions')?.addEventListener('change', function() {
    document.querySelectorAll('.mission-checkbox').forEach(cb => cb.checked = this.checked);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
