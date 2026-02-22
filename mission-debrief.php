<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

if (!isAdmin()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('missions.php');
}

$id = (int) get('id');
if (!$id) {
    redirect('missions.php');
}

$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('missions.php');
}

if ($mission['status'] !== STATUS_CLOSED) {
    setFlash('error', 'Μόνο κλειστές αποστολές μπορούν να ολοκληρωθούν με αναφορά.');
    redirect('mission-view.php?id=' . $id);
}

$pageTitle = 'Αναφορά Μετά την Αποστολή: ' . $mission['title'];

if (isPost()) {
    verifyCsrf();
    
    $summary = trim(post('summary'));
    $objectives_met = post('objectives_met');
    $incidents = trim(post('incidents'));
    $equipment_issues = trim(post('equipment_issues'));
    $rating = (int) post('rating');
    
    $errors = [];
    if (empty($summary)) $errors[] = 'Η σύνοψη είναι υποχρεωτική.';
    if (!in_array($objectives_met, ['YES', 'PARTIAL', 'NO'])) $errors[] = 'Επιλέξτε αν επιτεύχθηκαν οι στόχοι.';
    if ($rating < 1 || $rating > 5) $errors[] = 'Η βαθμολογία πρέπει να είναι από 1 έως 5.';
    
    if (empty($errors)) {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            
            // Insert debrief
            dbInsert(
                "INSERT INTO mission_debriefs (mission_id, submitted_by, summary, objectives_met, incidents, equipment_issues, rating) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$id, getCurrentUserId(), $summary, $objectives_met, $incidents ?: null, $equipment_issues ?: null, $rating]
            );
            
            // Update mission status
            dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_COMPLETED, $id]);
            
            $pdo->commit();
            
            logAudit('complete_with_debrief', 'missions', $id);
            
            // Notify participants
            $shifts = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$id]);
            $shiftIds = array_column($shifts, 'id');
            if (!empty($shiftIds)) {
                $ph = implode(',', array_fill(0, count($shiftIds), '?'));
                $participants = dbFetchAll(
                    "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                     FROM participation_requests pr
                     JOIN users u ON pr.volunteer_id = u.id
                     WHERE pr.shift_id IN ($ph) AND pr.status IN ('PENDING', 'APPROVED')",
                    $shiftIds
                );
                
                $missionUrl = rtrim(BASE_URL, '/') . '/mission-view.php?id=' . $id;
                $appName = getSetting('app_name', 'VolunteerOps');
                
                foreach ($participants as $p) {
                    // In-app notification
                    sendNotification(
                        $p['volunteer_id'],
                        'Ολοκλήρωση Αποστολής: ' . $mission['title'],
                        'Η αποστολή ολοκληρώθηκε επιτυχώς. Μπορείτε να δείτε την αναφορά (debrief) στη σελίδα της αποστολής.'
                    );
                    
                    // Email notification
                    if (!empty($p['email'])) {
                        $subject = '[' . $appName . '] Ολοκλήρωση Αποστολής: ' . $mission['title'];
                        $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto">
  <h2 style="color:#198754">Η Αποστολή Ολοκληρώθηκε</h2>
  <p>Γεια σου <strong>' . htmlspecialchars($p['name']) . '</strong>,</p>
  <p>Η αποστολή <strong>' . htmlspecialchars($mission['title']) . '</strong> ολοκληρώθηκε επιτυχώς.</p>
  <p>Ο υπεύθυνος της αποστολής έχει υποβάλει την τελική αναφορά (debrief). Μπορείτε να τη διαβάσετε κάνοντας κλικ στον παρακάτω σύνδεσμο:</p>
  <p style="margin-top:20px;">
    <a href="' . $missionUrl . '" style="background:#198754;color:#fff;padding:10px 20px;text-decoration:none;border-radius:5px;display:inline-block">Δείτε την Αναφορά</a>
  </p>
  <p style="color:#6c757d;font-size:0.9em;margin-top:30px;">&mdash; ' . $appName . '</p>
</div>';
                        sendEmail($p['email'], $subject, $body);
                    }
                }
            }
            
            setFlash('success', 'Η αναφορά υποβλήθηκε και η αποστολή ολοκληρώθηκε επιτυχώς.');
            redirect('mission-view.php?id=' . $id);
            
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Σφάλμα κατά την αποθήκευση: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        foreach ($errors as $err) {
            setFlash('error', $err);
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800">
            <i class="bi bi-clipboard-check text-success me-2"></i>
            Αναφορά Μετά την Αποστολή (Debrief)
        </h1>
        <a href="mission-view.php?id=<?= $id ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Επιστροφή
        </a>
    </div>

    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-light">
                    <h6 class="m-0 font-weight-bold text-primary">
                        Αποστολή: <?= h($mission['title']) ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Η υποβολή αυτής της αναφοράς θα αλλάξει την κατάσταση της αποστολής σε <strong>ΟΛΟΚΛΗΡΩΜΕΝΗ</strong> και θα ειδοποιήσει όλους τους συμμετέχοντες.
                    </div>

                    <form method="post" action="">
                        <?= csrfField() ?>
                        
                        <div class="mb-3">
                            <label for="summary" class="form-label fw-bold">Σύνοψη Αποστολής <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="summary" name="summary" rows="4" required placeholder="Περιγράψτε συνοπτικά πώς εξελίχθηκε η αποστολή..."><?= h(post('summary')) ?></textarea>
                            <div class="form-text">Τι πήγε καλά; Τι δυσκολίες αντιμετωπίσατε;</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Επιτεύχθηκαν οι στόχοι; <span class="text-danger">*</span></label>
                            <div class="d-flex gap-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="objectives_met" id="obj_yes" value="YES" <?= post('objectives_met') === 'YES' ? 'checked' : '' ?> required>
                                    <label class="form-check-label text-success" for="obj_yes">
                                        <i class="bi bi-check-circle-fill"></i> Ναι, πλήρως
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="objectives_met" id="obj_partial" value="PARTIAL" <?= post('objectives_met') === 'PARTIAL' ? 'checked' : '' ?>>
                                    <label class="form-check-label text-warning" for="obj_partial">
                                        <i class="bi bi-exclamation-circle-fill"></i> Μερικώς
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="objectives_met" id="obj_no" value="NO" <?= post('objectives_met') === 'NO' ? 'checked' : '' ?>>
                                    <label class="form-check-label text-danger" for="obj_no">
                                        <i class="bi bi-x-circle-fill"></i> Όχι
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="incidents" class="form-label fw-bold">Συμβάντα / Ατυχήματα</label>
                            <textarea class="form-control" id="incidents" name="incidents" rows="3" placeholder="Αναφέρετε τυχόν τραυματισμούς, ζημιές ή απρόοπτα συμβάντα..."><?= h(post('incidents')) ?></textarea>
                            <div class="form-text">Αφήστε κενό αν δεν υπήρξαν συμβάντα.</div>
                        </div>

                        <div class="mb-3">
                            <label for="equipment_issues" class="form-label fw-bold">Προβλήματα Εξοπλισμού</label>
                            <textarea class="form-control" id="equipment_issues" name="equipment_issues" rows="3" placeholder="Αναφέρετε ελλείψεις, βλάβες ή απώλειες εξοπλισμού..."><?= h(post('equipment_issues')) ?></textarea>
                            <div class="form-text">Αφήστε κενό αν δεν υπήρξαν προβλήματα.</div>
                        </div>

                        <div class="mb-4">
                            <label for="rating" class="form-label fw-bold">Συνολική Αξιολόγηση Αποστολής <span class="text-danger">*</span></label>
                            <select class="form-select" id="rating" name="rating" required>
                                <option value="">Επιλέξτε βαθμολογία...</option>
                                <option value="5" <?= post('rating') == '5' ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ (5/5) - Άριστη</option>
                                <option value="4" <?= post('rating') == '4' ? 'selected' : '' ?>>⭐⭐⭐⭐ (4/5) - Πολύ Καλή</option>
                                <option value="3" <?= post('rating') == '3' ? 'selected' : '' ?>>⭐⭐⭐ (3/5) - Ικανοποιητική</option>
                                <option value="2" <?= post('rating') == '2' ? 'selected' : '' ?>>⭐⭐ (2/5) - Μέτρια (Με προβλήματα)</option>
                                <option value="1" <?= post('rating') == '1' ? 'selected' : '' ?>>⭐ (1/5) - Κακή (Αποτυχία)</option>
                            </select>
                        </div>

                        <hr>
                        
                        <div class="d-flex justify-content-end gap-2">
                            <a href="mission-view.php?id=<?= $id ?>" class="btn btn-light border">Ακύρωση</a>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check2-all"></i> Υποβολή & Ολοκλήρωση Αποστολής
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>