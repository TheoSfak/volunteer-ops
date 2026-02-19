<?php
/**
 * VolunteerOps - Newsletter Form (Create / Edit Draft)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$id     = (int)get('id');
$isEdit = $id > 0;
$nl     = null;

if ($isEdit) {
    $nl = dbFetchOne("SELECT * FROM newsletters WHERE id = ? AND status = 'draft'", [$id]);
    if (!$nl) {
        setFlash('error', 'Το πρόχειρο δεν βρέθηκε.');
        redirect('newsletters.php');
    }
}
$pageTitle = $isEdit ? 'Επεξεργασία Δελτίου' : 'Νέο Ενημερωτικό Δελτίο';

// Data
$departments  = dbFetchAll("SELECT id, name FROM departments ORDER BY name");
$allRoles     = [
    ROLE_VOLUNTEER          => 'Εθελοντές',
    ROLE_SHIFT_LEADER       => 'Αρχηγοί Βάρδιας',
    ROLE_DEPARTMENT_ADMIN   => 'Διαχειριστές Τμήματος',
    ROLE_SYSTEM_ADMIN       => 'Διαχειριστές Συστήματος',
];
$emailTemplates = dbFetchAll("SELECT id, name, subject, body_html FROM email_templates WHERE is_active = 1 ORDER BY name");

// AJAX: count recipients
if (get('action') === 'count_recipients') {
    header('Content-Type: application/json');
    $roles  = $_GET['roles'] ?? [];
    $deptId = (int)($_GET['dept_id'] ?? 0);
    [$cnt] = buildRecipientQuery($roles, $deptId, true);
    echo json_encode(['count' => (int)$cnt]);
    exit;
}

// Handle save
if (isPost()) {
    verifyCsrf();

    $title   = trim(post('title'));
    $subject = trim(post('subject'));
    $body    = post('body_html');
    $roles   = $_POST['filter_roles'] ?? [];
    $deptId  = (int)post('filter_dept_id');

    $errors = [];
    if (empty($title))   $errors[] = 'Ο τίτλος είναι υποχρεωτικός.';
    if (empty($subject)) $errors[] = 'Το θέμα email είναι υποχρεωτικό.';
    if (empty($body))    $errors[] = 'Το σώμα του email είναι υποχρεωτικό.';

    if (empty($errors)) {
        $rolesJson = !empty($roles) ? json_encode(array_values($roles)) : null;
        $deptStore = $deptId > 0 ? $deptId : null;

        if ($isEdit) {
            dbExecute("UPDATE newsletters SET title=?, subject=?, body_html=?, filter_roles=?, filter_dept_id=?, updated_at=NOW() WHERE id=?",
                [$title, $subject, $body, $rolesJson, $deptStore, $id]);
            logAudit('newsletter_update', 'newsletters', $id);
            setFlash('success', 'Το πρόχειρο αποθηκεύτηκε.');
            redirect("newsletter-view.php?id={$id}");
        } else {
            $newId = dbInsert("INSERT INTO newsletters (title, subject, body_html, filter_roles, filter_dept_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())",
                [$title, $subject, $body, $rolesJson, $deptStore, getCurrentUserId()]);
            logAudit('newsletter_create', 'newsletters', $newId);
            setFlash('success', 'Το πρόχειρο δημιουργήθηκε. Ελέγξτε και αποστείλετε.');
            redirect("newsletter-view.php?id={$newId}");
        }
    } else {
        // Re-populate from POST on error
        $nl = [
            'title'          => $title,
            'subject'        => $subject,
            'body_html'      => $body,
            'filter_roles'   => json_encode($roles),
            'filter_dept_id' => $deptId ?: null,
        ];
    }
}

// Decode saved roles for checkboxes
$savedRoles = [];
if ($nl && !empty($nl['filter_roles'])) {
    $decoded = json_decode($nl['filter_roles'], true);
    if (is_array($decoded)) $savedRoles = $decoded;
}
if (empty($savedRoles)) {
    // Default: all roles checked
    $savedRoles = array_keys($allRoles);
}

include __DIR__ . '/includes/header.php';

// Count helper JS URL
$countUrl = 'newsletter-form.php?action=count_recipients';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-envelope-paper-fill me-2 text-primary"></i><?= h($pageTitle) ?></h1>
    <a href="newsletters.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Πίσω</a>
</div>

<?= displayFlash() ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="newsletterForm">
    <?= csrfField() ?>
    <div class="row g-4">

        <!-- Left column: content -->
        <div class="col-lg-8">

            <!-- Load from template -->
            <div class="card shadow-sm mb-3">
                <div class="card-body py-2 d-flex align-items-center gap-3">
                    <label class="fw-semibold text-muted mb-0 small"><i class="bi bi-lightning me-1"></i>Φόρτωση από πρότυπο:</label>
                    <select id="loadTemplate" class="form-select form-select-sm w-auto">
                        <option value="">— επιλέξτε —</option>
                        <?php foreach ($emailTemplates as $tpl): ?>
                        <option value="<?= $tpl['id'] ?>"
                                data-subject="<?= h($tpl['subject']) ?>"
                                data-body="<?= h($tpl['body_html']) ?>">
                            <?= h($tpl['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Αντικαθιστά εντελώς τα παρακάτω πεδία.</small>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Τίτλος εκστρατείας <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" value="<?= h($nl['title'] ?? '') ?>" placeholder="π.χ. Ανακοίνωση Μαΐου 2026" required>
                        <small class="text-muted">Μόνο για εσωτερική χρήση, δεν φαίνεται στον παραλήπτη.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Θέμα email <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="subject" id="subjectField" value="<?= h($nl['subject'] ?? '') ?>" placeholder="π.χ. Νέες αποστολές - {month}" required>
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
                <div class="card-body">
                    <label class="form-label fw-semibold">Σώμα email <span class="text-danger">*</span></label>
                    <textarea id="bodyHtml" name="body_html" class="form-control" rows="18"><?= h($nl['body_html'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Right column: settings -->
        <div class="col-lg-4">

            <!-- Recipient filter -->
            <div class="card shadow-sm mb-3">
                <div class="card-header"><strong><i class="bi bi-people me-1"></i>Αποδέκτες</strong></div>
                <div class="card-body">
                    <label class="form-label fw-semibold small text-uppercase text-muted">Ρόλοι</label>
                    <?php foreach ($allRoles as $roleKey => $roleLabel): ?>
                    <div class="form-check mb-1">
                        <input class="form-check-input role-check" type="checkbox" name="filter_roles[]"
                               value="<?= $roleKey ?>" id="role_<?= $roleKey ?>"
                               <?= in_array($roleKey, $savedRoles) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="role_<?= $roleKey ?>"><?= $roleLabel ?></label>
                    </div>
                    <?php endforeach; ?>

                    <hr class="my-3">
                    <label class="form-label fw-semibold small text-uppercase text-muted">Τμήμα (προαιρετικό)</label>
                    <select class="form-select form-select-sm" name="filter_dept_id" id="deptFilter">
                        <option value="">Όλα τα τμήματα</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= ($nl['filter_dept_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                            <?= h($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <hr class="my-3">
                    <!-- Live recipient count -->
                    <div class="alert alert-light border mb-0 text-center">
                        <div class="fs-4 fw-bold text-primary" id="recipientCount">…</div>
                        <small class="text-muted">εκτιμώμενοι αποδέκτες</small>
                        <br><small class="text-muted">(εξαιρούνται unsubscribed)</small>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="card shadow-sm">
                <div class="card-body d-grid gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-floppy me-1"></i>
                        <?= $isEdit ? 'Αποθήκευση αλλαγών' : 'Αποθήκευση ως πρόχειρο' ?>
                    </button>
                    <a href="newsletters.php" class="btn btn-outline-secondary">Ακύρωση</a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Summernote CDN -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>

<script>
// Summernote init
$('#bodyHtml').summernote({
    lang: 'el-GR',
    height: 420,
    toolbar: [
        ['style', ['style']],
        ['font', ['bold','italic','underline','clear']],
        ['color', ['color']],
        ['para', ['ul','ol','paragraph']],
        ['table', ['table']],
        ['insert', ['link','picture','hr']],
        ['view', ['fullscreen','codeview']]
    ],
    callbacks: {
        onInit: function() { updateRecipientCount(); }
    }
});

// Insert tag into Summernote body
document.querySelectorAll('.tag-insert').forEach(function(btn) {
    btn.addEventListener('click', function() {
        $('#bodyHtml').summernote('insertText', this.dataset.tag);
    });
});

// Load from template
document.getElementById('loadTemplate').addEventListener('change', function() {
    var sel = this.options[this.selectedIndex];
    if (!sel.value) return;
    if (!confirm('Αντικατάσταση θέματος και σώματος από το πρότυπο "' + sel.text + '";')) return;
    document.getElementById('subjectField').value = sel.dataset.subject;
    $('#bodyHtml').summernote('code', sel.dataset.body);
    this.value = '';
});

// Live recipient count
function updateRecipientCount() {
    var roles = [];
    document.querySelectorAll('.role-check:checked').forEach(function(cb) { roles.push(cb.value); });
    var deptId = document.getElementById('deptFilter').value;
    var params = new URLSearchParams();
    roles.forEach(function(r) { params.append('roles[]', r); });
    if (deptId) params.append('dept_id', deptId);
    fetch('<?= $countUrl ?>&' + params.toString())
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('recipientCount').textContent = data.count;
        });
}
document.querySelectorAll('.role-check').forEach(function(cb) {
    cb.addEventListener('change', updateRecipientCount);
});
document.getElementById('deptFilter').addEventListener('change', updateRecipientCount);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
