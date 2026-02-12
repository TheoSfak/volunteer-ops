<?php
/**
 * Delete all test and debug users from database
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

// Find all test users
$testUsers = dbFetchAll(
    "SELECT id, name, email FROM users 
     WHERE LOWER(name) LIKE '%test%' 
        OR LOWER(name) LIKE '%δοκιμ%' 
        OR LOWER(name) LIKE '%δοκή%' 
        OR LOWER(email) LIKE '%test%'
        OR LOWER(email) LIKE '%debug%'
     ORDER BY name"
);

if (isPost()) {
    verifyCsrf();
    
    if (empty($testUsers)) {
        setFlash('info', 'Δεν βρέθηκαν χρήστες για διαγραφή.');
        redirect('volunteers.php');
    }
    
    $deletedCount = 0;
    
    foreach ($testUsers as $user) {
        // Don't delete yourself
        if ($user['id'] == getCurrentUserId()) {
            continue;
        }
        
        // Delete related data first
        dbExecute("DELETE FROM volunteer_profiles WHERE user_id = ?", [$user['id']]);
        dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$user['id']]);
        dbExecute("DELETE FROM user_achievements WHERE user_id = ?", [$user['id']]);
        dbExecute("DELETE FROM notifications WHERE user_id = ?", [$user['id']]);
        dbExecute("DELETE FROM volunteer_points WHERE user_id = ?", [$user['id']]);
        dbExecute("DELETE FROM participation_requests WHERE volunteer_id = ?", [$user['id']]);
        dbExecute("DELETE FROM task_assignments WHERE user_id = ?", [$user['id']]);
        
        // Delete user
        dbExecute("DELETE FROM users WHERE id = ?", [$user['id']]);
        
        logAudit('delete_test_user', 'users', $user['id'], 'Deleted test user: ' . $user['email']);
        $deletedCount++;
    }
    
    setFlash('success', "Διαγράφηκαν {$deletedCount} χρήστες δοκιμών.");
    redirect('volunteers.php');
}

$pageTitle = 'Διαγραφή Χρηστών Δοκιμών';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-trash me-2"></i>Διαγραφή Χρηστών Δοκιμών</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($testUsers)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            Δεν βρέθηκαν χρήστες δοκιμών στη βάση δεδομένων.
                        </div>
                        <a href="volunteers.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Επιστροφή
                        </a>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Προειδοποίηση:</strong> Θα διαγραφούν μόνιμα <?= count($testUsers) ?> χρήστες δοκιμών και όλα τα σχετικά δεδομένα τους.
                        </div>
                        
                        <h6>Χρήστες προς διαγραφή:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Όνομα</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testUsers as $user): ?>
                                        <tr>
                                            <td><?= $user['id'] ?></td>
                                            <td><?= h($user['name']) ?></td>
                                            <td><?= h($user['email']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <hr>
                        
                        <p class="mb-3"><strong>Θα διαγραφούν επίσης:</strong></p>
                        <ul>
                            <li>Προφίλ εθελοντών</li>
                            <li>Δεξιότητες και επιτεύγματα</li>
                            <li>Ειδοποιήσεις</li>
                            <li>Ιστορικό πόντων</li>
                            <li>Συμμετοχές σε βάρδιες</li>
                            <li>Αναθέσεις εργασιών</li>
                        </ul>
                        
                        <div class="d-flex gap-2 mt-4">
                            <form method="post">
                                <?= csrfField() ?>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-trash me-1"></i>Διαγραφή Όλων των Χρηστών Δοκιμών
                                </button>
                            </form>
                            <a href="volunteers.php" class="btn btn-secondary">
                                <i class="bi bi-x me-1"></i>Ακύρωση
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
