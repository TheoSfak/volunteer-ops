<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/import-functions.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Εισαγωγή Εθελοντών από CSV';
$step = get('step', '1');

// Step 2: Preview
if ($step == '2' && isPost()) {
    verifyCsrf();
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Σφάλμα κατά το ανέβασμα του αρχείου.');
        redirect('import-volunteers.php');
    }
    
    $tempFile = $_FILES['csv_file']['tmp_name'];
    $result = parseCsvFile($tempFile);
    
    if (!$result['success']) {
        setFlash('error', $result['error']);
        redirect('import-volunteers.php');
    }
    
    // Validate all rows
    $validationErrors = [];
    foreach ($result['rows'] as $index => $row) {
        $errors = validateVolunteerData($row, $index + 2);
        if (!empty($errors)) {
            $validationErrors = array_merge($validationErrors, $errors);
        }
    }
    
    // Store data in session for step 3
    $_SESSION['import_data'] = $result['rows'];
    $_SESSION['import_errors'] = $validationErrors;
}

// Step 3: Import
if ($step == '3' && isPost()) {
    verifyCsrf();
    
    if (!isset($_SESSION['import_data'])) {
        setFlash('error', 'Δεν βρέθηκαν δεδομένα για εισαγωγή.');
        redirect('import-volunteers.php');
    }
    
    $rows = $_SESSION['import_data'];
    $result = importVolunteersFromCsv($rows, false);
    
    $_SESSION['import_results'] = $result;
    unset($_SESSION['import_data']);
    unset($_SESSION['import_errors']);
    
    redirect('import-volunteers.php?step=4');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-upload"></i> <?= h($pageTitle) ?></h4>
                </div>
                <div class="card-body">
                    
                    <!-- Progress Steps -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="progress" style="height: 3px;">
                                <div class="progress-bar" style="width: <?= ($step * 25) ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-2">
                                <small class="<?= $step == '1' ? 'text-primary fw-bold' : 'text-muted' ?>">1. Ανέβασμα</small>
                                <small class="<?= $step == '2' ? 'text-primary fw-bold' : 'text-muted' ?>">2. Προεπισκόπηση</small>
                                <small class="<?= $step == '3' ? 'text-primary fw-bold' : 'text-muted' ?>">3. Επιβεβαίωση</small>
                                <small class="<?= $step == '4' ? 'text-primary fw-bold' : 'text-muted' ?>">4. Αποτελέσματα</small>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($step == '1'): ?>
                        <!-- Step 1: Upload -->
                        <h5>Βήμα 1: Επιλέξτε αρχείο CSV</h5>
                        <p class="text-muted">Ανεβάστε ένα CSV αρχείο με τους εθελοντές που θέλετε να εισάγετε.</p>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> 
                            <strong>Μορφή αρχείου:</strong> CSV με headers: Όνομα, Email, Τηλέφωνο, Τμήμα ID, Ρόλος
                            <br>
                            <a href="templates/volunteers_template.csv" class="alert-link">
                                <i class="bi bi-download"></i> Κατέβασμα Υποδείγματος
                            </a>
                        </div>
                        
                        <form method="post" action="import-volunteers.php?step=2" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Αρχείο CSV</label>
                                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-arrow-right"></i> Επόμενο
                                </button>
                                <a href="<?= SITE_URL ?>/volunteers.php" class="btn btn-secondary">Ακύρωση</a>
                            </div>
                        </form>
                        
                    <?php elseif ($step == '2'): ?>
                        <!-- Step 2: Preview -->
                        <h5>Βήμα 2: Προεπισκόπηση & Επικύρωση</h5>
                        
                        <?php
                        $rows = $_SESSION['import_data'] ?? [];
                        $errors = $_SESSION['import_errors'] ?? [];
                        $validRows = count($rows) - count($errors);
                        ?>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="bi bi-exclamation-triangle"></i> Βρέθηκαν <?= count($errors) ?> σφάλματα</h6>
                                <ul class="mb-0">
                                    <?php foreach (array_slice($errors, 0, 10) as $error): ?>
                                        <li><?= h($error) ?></li>
                                    <?php endforeach; ?>
                                    <?php if (count($errors) > 10): ?>
                                        <li><em>...και <?= count($errors) - 10 ?> ακόμα</em></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            
                            <p class="text-danger">
                                Διορθώστε τα σφάλματα και δοκιμάστε ξανά.
                            </p>
                            <a href="import-volunteers.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Πίσω
                            </a>
                            
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> 
                                Όλες οι <?= count($rows) ?> εγγραφές είναι έγκυρες!
                            </div>
                            
                            <h6>Δείγμα δεδομένων (πρώτες 5 εγγραφές):</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Όνομα</th>
                                            <th>Email</th>
                                            <th>Τηλέφωνο</th>
                                            <th>Τμήμα ID</th>
                                            <th>Ρόλος</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($rows, 0, 5) as $row): ?>
                                            <?php $values = array_values((array) $row); ?>
                                            <tr>
                                                <td><?= h($values[0]) ?></td>
                                                <td><?= h($values[1]) ?></td>
                                                <td><?= h($values[2] ?? '') ?></td>
                                                <td><?= h($values[3]) ?></td>
                                                <td><?= h($values[4]) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <form method="post" action="import-volunteers.php?step=3">
                                <?= csrfField() ?>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-check-circle"></i> Επιβεβαίωση & Εισαγωγή
                                    </button>
                                    <a href="import-volunteers.php" class="btn btn-secondary">
                                        <i class="bi bi-arrow-left"></i> Ακύρωση
                                    </a>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                    <?php elseif ($step == '4'): ?>
                        <!-- Step 4: Results -->
                        <h5>Βήμα 4: Αποτελέσματα</h5>
                        
                        <?php
                        $results = $_SESSION['import_results'] ?? ['success' => 0, 'failed' => 0, 'errors' => [], 'passwords' => []];
                        ?>
                        
                        <?php if ($results['success'] > 0): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> 
                                <strong>Επιτυχία!</strong> Εισήχθησαν <?= $results['success'] ?> εθελοντές.
                            </div>
                            
                            <?php if (!empty($results['passwords'])): ?>
                                <div class="alert alert-warning">
                                    <h6><i class="bi bi-key"></i> Κωδικοί Πρόσβασης</h6>
                                    <p>Αποθηκεύστε τους κωδικούς και στείλτε τους στους εθελοντές:</p>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Email</th>
                                                    <th>Κωδικός</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($results['passwords'] as $pwd): ?>
                                                    <tr>
                                                        <td><?= h($pwd['email']) ?></td>
                                                        <td><code><?= h($pwd['password']) ?></code></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($results['failed'] > 0): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> 
                                Αποτυχία εισαγωγής για <?= $results['failed'] ?> εγγραφές.
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($results['errors'] as $error): ?>
                                        <li><?= h($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <div class="d-flex gap-2">
                            <a href="<?= SITE_URL ?>/volunteers.php" class="btn btn-primary">
                                <i class="bi bi-people"></i> Προβολή Εθελοντών
                            </a>
                            <a href="import-volunteers.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Νέα Εισαγωγή
                            </a>
                        </div>
                        
                        <?php unset($_SESSION['import_results']); ?>
                        
                    <?php endif; ?>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
