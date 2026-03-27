<?php
/**
 * VolunteerOps - Newsletter Template Form (Create / Edit)
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$id     = (int)get('id');
$isEdit = $id > 0;
$tpl    = null;

if ($isEdit) {
    $tpl = dbFetchOne("SELECT * FROM newsletter_templates WHERE id = ?", [$id]);
    if (!$tpl) {
        setFlash('error', 'Το πρότυπο δεν βρέθηκε.');
        redirect('newsletter-templates.php');
    }
}

$pageTitle = $isEdit ? 'Επεξεργασία Προτύπου' : 'Νέο Πρότυπο Newsletter';

$errors = [];

if (isPost()) {
    verifyCsrf();

    $name       = trim(post('name'));
    $headerHtml = $_POST['header_html'] ?? '';
    $footerHtml = $_POST['footer_html'] ?? '';

    if (empty($name))       $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    if (empty($headerHtml)) $errors[] = 'Το Header HTML είναι υποχρεωτικό.';
    if (empty($footerHtml)) $errors[] = 'Το Footer HTML είναι υποχρεωτικό.';

    if (empty($errors)) {
        if ($isEdit) {
            dbExecute("UPDATE newsletter_templates SET name=?, header_html=?, footer_html=?, updated_at=NOW() WHERE id=?",
                [$name, $headerHtml, $footerHtml, $id]);
            logAudit('newsletter_template_update', 'newsletter_templates', $id);
            setFlash('success', 'Το πρότυπο αποθηκεύτηκε.');
        } else {
            $newId = dbInsert("INSERT INTO newsletter_templates (name, header_html, footer_html, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())",
                [$name, $headerHtml, $footerHtml]);
            logAudit('newsletter_template_create', 'newsletter_templates', $newId);
            setFlash('success', 'Το πρότυπο δημιουργήθηκε.');
        }
        redirect('newsletter-templates.php');
    }

    // Re-populate on error
    $tpl = [
        'name'        => $name,
        'header_html' => $headerHtml,
        'footer_html' => $footerHtml,
    ];
}

// Default template for new
if (!$tpl) {
    $defaultTpl = dbFetchOne("SELECT * FROM newsletter_templates WHERE is_default = 1 LIMIT 1");
    $tpl = [
        'name'        => '',
        'header_html' => $defaultTpl ? $defaultTpl['header_html'] : '',
        'footer_html' => $defaultTpl ? $defaultTpl['footer_html'] : '',
    ];
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="newsletter-templates.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Πρότυπα</a>
        <h1 class="h3 mb-0 mt-1"><i class="bi bi-palette me-2 text-info"></i><?= h($pageTitle) ?></h1>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?= displayFlash() ?>

<form method="post" id="tplForm">
    <?= csrfField() ?>
    <div class="row g-4">

        <!-- Left: editors -->
        <div class="col-lg-8">

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <label class="form-label fw-semibold">Όνομα προτύπου <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?= h($tpl['name'] ?? '') ?>" placeholder="π.χ. Χριστουγεννιάτικο, Επίσημο, Απλό" required>
                </div>
            </div>

            <!-- Header HTML -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-chevron-bar-up me-1"></i>Header HTML</strong>
                    <small class="text-muted">Εμφανίζεται πριν το περιεχόμενο του newsletter</small>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-0" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#headerVisual" type="button">Visual</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#headerCode" type="button"><i class="bi bi-code-slash me-1"></i>HTML</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom">
                        <div class="tab-pane fade show active p-0" id="headerVisual">
                            <textarea id="headerSummernote" class="summernote-editor"><?= h($tpl['header_html'] ?? '') ?></textarea>
                        </div>
                        <div class="tab-pane fade p-3" id="headerCode">
                            <textarea id="headerCodeEditor" class="form-control font-monospace" rows="14" style="font-size:0.8rem;"><?= h($tpl['header_html'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="header_html" id="headerHidden">
                </div>
            </div>

            <!-- Footer HTML -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-chevron-bar-down me-1"></i>Footer HTML</strong>
                    <small class="text-muted">Εμφανίζεται μετά το περιεχόμενο του newsletter</small>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-0" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#footerVisual" type="button">Visual</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#footerCode" type="button"><i class="bi bi-code-slash me-1"></i>HTML</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom">
                        <div class="tab-pane fade show active p-0" id="footerVisual">
                            <textarea id="footerSummernote" class="summernote-editor"><?= h($tpl['footer_html'] ?? '') ?></textarea>
                        </div>
                        <div class="tab-pane fade p-3" id="footerCode">
                            <textarea id="footerCodeEditor" class="form-control font-monospace" rows="10" style="font-size:0.8rem;"><?= h($tpl['footer_html'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="footer_html" id="footerHidden">
                </div>
            </div>

        </div>

        <!-- Right: placeholders + preview + actions -->
        <div class="col-lg-4">

            <!-- Placeholders -->
            <div class="card shadow-sm mb-3 border-info">
                <div class="card-header bg-info bg-opacity-10 py-2">
                    <strong class="small"><i class="bi bi-braces me-1"></i>Διαθέσιμα placeholders</strong>
                </div>
                <div class="card-body py-2">
                    <p class="small text-muted mb-2">Κλικ για εισαγωγή στο ενεργό editor:</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-info placeholder-btn" data-tag="{from_name}">
                            <code>{from_name}</code><br><small class="text-muted">Όνομα αποστολέα</small>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info placeholder-btn" data-tag="{logo_url}">
                            <code>{logo_url}</code><br><small class="text-muted">Logo εφαρμογής</small>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Live preview -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-eye me-1"></i>Προεπισκόπηση</strong>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="updatePreview()">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
                <div class="card-body p-0">
                    <iframe id="tplPreview" style="width:100%;height:350px;border:0;" sandbox="allow-same-origin"></iframe>
                </div>
            </div>

            <!-- Actions -->
            <div class="card shadow-sm">
                <div class="card-body d-grid gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-floppy me-1"></i><?= $isEdit ? 'Αποθήκευση αλλαγών' : 'Δημιουργία Προτύπου' ?>
                    </button>
                    <a href="newsletter-templates.php" class="btn btn-outline-secondary">Ακύρωση</a>
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
    var fromName = <?= json_encode(getSetting('smtp_from_name', 'VolunteerOps')) ?>;
    var logoUrl  = <?= json_encode(getSetting('app_logo', '')) ?>;
    var logoHtml = logoUrl ? '<img src="uploads/logos/' + logoUrl + '" alt="" style="max-height:50px;margin-bottom:10px;">' : '';

    var snConfig = {
        lang: 'el-GR',
        height: 250,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'hr']],
            ['view', ['fullscreen', 'codeview']]
        ]
    };

    // Init Summernote editors
    $('#headerSummernote').summernote(snConfig);
    $('#footerSummernote').summernote($.extend({}, snConfig, { height: 180 }));

    // Track which editor was last focused
    var lastFocusedEditor = '#headerSummernote';
    $('#headerSummernote').on('summernote.focus', function() { lastFocusedEditor = '#headerSummernote'; });
    $('#footerSummernote').on('summernote.focus', function() { lastFocusedEditor = '#footerSummernote'; });

    // Placeholder insertion
    $(document).on('mousedown', '.placeholder-btn', function(e) {
        e.preventDefault();
        $(lastFocusedEditor).summernote('insertText', $(this).data('tag'));
    });

    // Tab sync: Visual ↔ HTML Code
    // Header
    $('button[data-bs-target="#headerCode"]').on('shown.bs.tab', function() {
        $('#headerCodeEditor').val($('#headerSummernote').summernote('code'));
    });
    $('button[data-bs-target="#headerVisual"]').on('shown.bs.tab', function() {
        $('#headerSummernote').summernote('code', $('#headerCodeEditor').val());
    });
    // Footer
    $('button[data-bs-target="#footerCode"]').on('shown.bs.tab', function() {
        $('#footerCodeEditor').val($('#footerSummernote').summernote('code'));
    });
    $('button[data-bs-target="#footerVisual"]').on('shown.bs.tab', function() {
        $('#footerSummernote').summernote('code', $('#footerCodeEditor').val());
    });

    // Form submit: sync hidden fields
    $('#tplForm').on('submit', function() {
        // If code tab is active, use its value; otherwise use Summernote
        var headerActive = $('#headerCode').hasClass('active');
        var footerActive = $('#footerCode').hasClass('active');
        $('#headerHidden').val(headerActive ? $('#headerCodeEditor').val() : $('#headerSummernote').summernote('code'));
        $('#footerHidden').val(footerActive ? $('#footerCodeEditor').val() : $('#footerSummernote').summernote('code'));
    });

    // Live preview
    window.updatePreview = function() {
        var headerActive = $('#headerCode').hasClass('active');
        var footerActive = $('#footerCode').hasClass('active');
        var header = headerActive ? $('#headerCodeEditor').val() : $('#headerSummernote').summernote('code');
        var footer = footerActive ? $('#footerCodeEditor').val() : $('#footerSummernote').summernote('code');

        header = header.replace(/\{from_name\}/g, fromName).replace(/\{logo_url\}/g, logoHtml);
        footer = footer.replace(/\{from_name\}/g, fromName).replace(/\{logo_url\}/g, logoHtml);

        var html = header
            + '<p style="color:#2c3e50;padding:10px 32px;line-height:1.6;">Αγαπητέ/ή εθελοντή/τρια,<br><br>Αυτό είναι <strong>δείγμα περιεχομένου</strong> newsletter. Εδώ θα εμφανίζεται το κείμενο που γράφετε στη φόρμα του ενημερωτικού δελτίου.<br><br>Με εκτίμηση,<br>' + fromName + '</p>'
            + footer;

        document.getElementById('tplPreview').srcdoc = html;
    };
    updatePreview();

    // Auto-refresh preview on Summernote change
    $('#headerSummernote, #footerSummernote').on('summernote.change', function() {
        clearTimeout(window._previewTimer);
        window._previewTimer = setTimeout(updatePreview, 500);
    });
    $('#headerCodeEditor, #footerCodeEditor').on('input', function() {
        clearTimeout(window._previewTimer);
        window._previewTimer = setTimeout(updatePreview, 500);
    });
});
</script>
