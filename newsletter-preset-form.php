<?php
/**
 * VolunteerOps - Newsletter Preset Form (Create / Edit)
 * Summernote editor for reusable content presets
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

// Check if table exists (migration may not have run yet)
$tableReady = (bool)dbFetchOne(
    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_presets'"
);
if (!$tableReady) {
    setFlash('warning', 'Ο πίνακας newsletter_presets δεν έχει δημιουργηθεί ακόμα. Περιμένετε να ολοκληρωθεί η μετάβαση βάσης.');
    redirect('newsletters.php');
}

$id     = (int)get('id');
$isEdit = $id > 0;
$preset = null;

if ($isEdit) {
    $preset = dbFetchOne("SELECT * FROM newsletter_presets WHERE id = ?", [$id]);
    if (!$preset) {
        setFlash('error', 'Το πρότυπο περιεχομένου δεν βρέθηκε.');
        redirect('newsletter-presets.php');
    }
}

$pageTitle = $isEdit ? 'Επεξεργασία Προτύπου Περιεχομένου' : 'Νέο Πρότυπο Περιεχομένου';

$errors = [];

if (isPost()) {
    verifyCsrf();

    $name        = trim(post('name'));
    $description = trim(post('description'));
    $bodyHtml    = $_POST['body_html'] ?? '';

    if (empty($name))     $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    if (empty($bodyHtml)) $errors[] = 'Το περιεχόμενο είναι υποχρεωτικό.';

    if (empty($errors)) {
        if ($isEdit) {
            dbExecute("UPDATE newsletter_presets SET name=?, description=?, body_html=?, updated_at=NOW() WHERE id=?",
                [$name, $description ?: null, $bodyHtml, $id]);
            logAudit('newsletter_preset_update', 'newsletter_presets', $id);
            setFlash('success', 'Το πρότυπο περιεχομένου αποθηκεύτηκε.');
        } else {
            $newId = dbInsert("INSERT INTO newsletter_presets (name, description, body_html, created_by, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())",
                [$name, $description ?: null, $bodyHtml, getCurrentUserId()]);
            logAudit('newsletter_preset_create', 'newsletter_presets', $newId);
            setFlash('success', 'Το πρότυπο περιεχομένου δημιουργήθηκε.');
        }
        redirect('newsletter-presets.php');
    }

    // Re-populate on error
    $preset = [
        'name'        => $name,
        'description' => $description,
        'body_html'   => $bodyHtml,
    ];
}

// Default for new
if (!$preset) {
    $preset = [
        'name'        => '',
        'description' => '',
        'body_html'   => '',
    ];
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="newsletter-presets.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Πρότυπα Περιεχομένου</a>
        <h1 class="h3 mb-0 mt-1"><i class="bi bi-file-earmark-text me-2 text-success"></i><?= h($pageTitle) ?></h1>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?= displayFlash() ?>

<form method="post" id="presetForm">
    <?= csrfField() ?>
    <div class="row g-4">

        <!-- Left: editor -->
        <div class="col-lg-8">

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Όνομα προτύπου <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?= h($preset['name'] ?? '') ?>" placeholder="π.χ. Μηνιαία Ενημέρωση, Πρόσκληση Αποστολής" required>
                    </div>
                    <div>
                        <label class="form-label fw-semibold">Περιγραφή <small class="text-muted fw-normal">(προαιρετική)</small></label>
                        <input type="text" class="form-control" name="description" value="<?= h($preset['description'] ?? '') ?>" placeholder="Σύντομη περιγραφή χρήσης του προτύπου">
                    </div>
                </div>
            </div>

            <!-- Tag helper -->
            <div class="card shadow-sm mb-3 border-info">
                <div class="card-header bg-info bg-opacity-10 py-2">
                    <strong class="small"><i class="bi bi-braces me-1"></i>Διαθέσιμες ετικέτες</strong>
                    <small class="text-muted ms-2">Κλικ για εισαγωγή στο σώμα</small>
                </div>
                <div class="card-body py-2 d-flex flex-wrap gap-2">
                    <?php
                    $tags = [
                        '{name}'             => 'Όνομα παραλήπτη',
                        '{email}'            => 'Email παραλήπτη',
                        '{role}'             => 'Ρόλος',
                        '{department}'       => 'Τμήμα',
                        '{points}'           => 'Σύνολο πόντων',
                        '{unsubscribe_link}' => 'Σύνδεσμος διαγραφής',
                    ];
                    foreach ($tags as $tag => $desc): ?>
                    <button type="button" class="btn btn-sm btn-outline-info tag-insert" data-tag="<?= $tag ?>">
                        <code><?= $tag ?></code> <small class="text-muted"><?= $desc ?></small>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Body editor -->
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <strong><i class="bi bi-pencil-square me-1"></i>Περιεχόμενο <span class="text-danger">*</span></strong>
                </div>
                <div class="card-body">
                    <textarea id="bodyHtml" name="body_html" class="form-control" rows="18"><?= h($preset['body_html'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Right: actions -->
        <div class="col-lg-4">

            <!-- Actions -->
            <div class="card shadow-sm">
                <div class="card-body d-grid gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-floppy me-1"></i><?= $isEdit ? 'Αποθήκευση αλλαγών' : 'Δημιουργία Προτύπου' ?>
                    </button>
                    <a href="newsletter-presets.php" class="btn btn-outline-secondary">Ακύρωση</a>
                </div>
            </div>

            <!-- Info -->
            <div class="card shadow-sm mt-3">
                <div class="card-header bg-white">
                    <strong class="small"><i class="bi bi-info-circle me-1"></i>Πληροφορίες</strong>
                </div>
                <div class="card-body small text-muted">
                    <p class="mb-2">Τα πρότυπα περιεχομένου είναι έτοιμα κείμενα που μπορείτε να φορτώσετε στον editor κατά τη δημιουργία ενός newsletter.</p>
                    <p class="mb-0">Χρησιμοποιήστε τις <strong>ετικέτες</strong> (tags) για δυναμικό περιεχόμενο που αντικαθίσταται αυτόματα κατά την αποστολή.</p>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Summernote CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css">

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- Summernote JS -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>

<script>
$(function() {
    // Summernote init
    $('#bodyHtml').summernote({
        lang: 'el-GR',
        height: 420,
        dialogsInBody: true,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold','italic','underline','clear']],
            ['color', ['color']],
            ['para', ['ul','ol','paragraph']],
            ['table', ['table']],
            ['insert', ['link','picture','hr']],
            ['view', ['fullscreen','codeview']]
        ]
    });

    // Insert tag into Summernote
    $(document).on('mousedown', '.tag-insert', function(e) {
        e.preventDefault();
        $('#bodyHtml').summernote('insertText', $(this).data('tag'));
    });

    // Sync textarea before submit
    $('#presetForm').on('submit', function() {
        var html = $('#bodyHtml').summernote('code');
        $('textarea[name="body_html"]').val(html);
    });
});
</script>
