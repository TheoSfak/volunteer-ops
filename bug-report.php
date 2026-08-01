<?php
/**
 * VolunteerOps - Bug Report Form
 * Αναφορά προβλήματος στον προγραμματιστή
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Αποστολή Bug';
$currentUser = getCurrentUser();

// Captured on the GET that lands here from wherever the user actually hit the bug —
// on the POST below the referer would just be bug-report.php itself, so it has to
// survive as a hidden field rather than being re-read from the request each time.
$pageUrlOnLoad = $_SERVER['HTTP_REFERER'] ?? '';

if (isPost()) {
    verifyCsrf();

    $description = trim(post('description'));
    $pageUrl = mb_substr(trim(post('page_url')), 0, 500);

    $errors = [];
    if (empty($description)) {
        $errors[] = 'Η περιγραφή είναι υποχρεωτική.';
    }

    // Optional screenshot
    $screenshotFilename = null;
    if (!empty($_FILES['screenshot']['name'])) {
        $file = $_FILES['screenshot'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Σφάλμα κατά τη μεταφόρτωση της εικόνας.';
        } else {
            // Detect MIME from actual file content, not the browser-supplied header
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($file['tmp_name']);
            } else {
                $detectedMime = mime_content_type($file['tmp_name']);
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($detectedMime, $allowedTypes) || !in_array($ext, $allowedExtensions)) {
                $errors[] = 'Μη αποδεκτός τύπος εικόνας. Επιτρέπονται: JPG, PNG, GIF, WebP.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Η εικόνα είναι πολύ μεγάλη. Μέγιστο μέγεθος: 5MB.';
            } else {
                $screenshotDir = __DIR__ . '/uploads/bug_reports/';
                if (!is_dir($screenshotDir)) {
                    mkdir($screenshotDir, 0755, true);
                }
                $screenshotFilename = 'bug_' . time() . '_' . uniqid() . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $screenshotDir . $screenshotFilename)) {
                    $errors[] = 'Σφάλμα κατά την αποθήκευση της εικόνας.';
                    $screenshotFilename = null;
                }
            }
        }
    }

    if (!empty($errors)) {
        setFlash('error', implode(' ', $errors));
    } else {
        $id = dbInsert(
            "INSERT INTO bug_reports (user_id, description, screenshot, page_url, app_version, status)
             VALUES (?, ?, ?, ?, ?, ?)",
            [getCurrentUserId(), $description, $screenshotFilename, $pageUrl ?: null, APP_VERSION, BUG_REPORT_NEW]
        );

        logAudit('create_bug_report', 'bug_reports', $id);

        $bugReportUrl = rtrim(BASE_URL, '/') . '/bug-report-view.php?id=' . $id;

        // In-app notification to every SYSTEM_ADMIN (mirrors how complaints notify their
        // admin pool) — mandatory/non-configurable (empty code), same as complaints.
        $admins = dbFetchAll("SELECT id FROM users WHERE role = ? AND is_active = 1 AND deleted_at IS NULL", [ROLE_SYSTEM_ADMIN]);
        foreach ($admins as $admin) {
            sendNotification(
                $admin['id'],
                'Νέο Bug Report',
                'Ο/Η ' . $currentUser['name'] . ' ανέφερε πρόβλημα: ' . mb_strimwidth($description, 0, 80, '…'),
                'bug_report',
                '',
                ['url' => $bugReportUrl]
            );
        }

        // Also email the configured developer directly — this recipient is a raw
        // address from settings, not necessarily a "user" the per-user notification
        // preference system knows about, so this goes through sendEmail() directly
        // rather than sendNotificationEmail()'s per-user-preference path.
        $developerEmail = getSetting('developer_email', '');
        if (!empty($developerEmail)) {
            $emailBody = '<p><strong>' . h($currentUser['name']) . '</strong> (' . h($currentUser['email']) . ') ανέφερε πρόβλημα στην εφαρμογή:</p>'
                . '<p style="white-space:pre-wrap;">' . h($description) . '</p>'
                . '<p><strong>Σελίδα:</strong> ' . h($pageUrl ?: '—') . '<br>'
                . '<strong>Έκδοση:</strong> ' . h(APP_VERSION) . '</p>'
                . '<p><a href="' . h($bugReportUrl) . '">Προβολή στην εφαρμογή</a></p>';
            sendEmail($developerEmail, 'Νέο Bug Report: ' . mb_strimwidth($description, 0, 60, '…'), $emailBody);
        }

        setFlash('success', 'Το πρόβλημα καταγράφηκε — ευχαριστούμε για την αναφορά!');
        redirect('my-bug-reports.php');
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-8 col-xl-7">

            <div class="d-flex align-items-center mb-4">
                <a href="dashboard.php" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h2 class="mb-0"><i class="bi bi-bug me-2"></i>Αποστολή Bug</h2>
            </div>

            <?= displayFlash() ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="card shadow-sm">
                <div class="card-body">
                    <p class="text-muted">
                        Βρήκατε κάτι που δεν δουλεύει σωστά; Περιγράψτε το πρόβλημα παρακάτω — θα σταλεί
                        κατευθείαν στον προγραμματιστή μαζί με τη σελίδα και την έκδοση της εφαρμογής.
                    </p>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="page_url" value="<?= h($pageUrlOnLoad) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Περιγραφή προβλήματος <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" rows="6" required
                                placeholder="Τι κάνατε, τι περιμένατε να γίνει, και τι έγινε τελικά..."><?= h($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Στιγμιότυπο οθόνης (προαιρετικό)</label>
                            <input type="file" class="form-control" name="screenshot" accept="image/*">
                            <small class="text-muted">Μέγιστο: 5MB. Τύποι: JPG, PNG, GIF, WebP</small>
                        </div>

                        <?php if ($pageUrlOnLoad): ?>
                        <div class="mb-3">
                            <small class="text-muted"><i class="bi bi-link-45deg me-1"></i>Σελίδα: <?= h($pageUrlOnLoad) ?></small>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Αποστολή
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
