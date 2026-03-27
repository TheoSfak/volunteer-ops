<?php
/**
 * VolunteerOps - Newsletter Template Form (Create / Edit)
 * Single body_html editor with {content} placeholder
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

    $name     = trim(post('name'));
    $bodyHtml = $_POST['body_html'] ?? '';

    if (empty($name))     $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    if (empty($bodyHtml)) $errors[] = 'Το περιεχόμενο είναι υποχρεωτικό.';
    if (!empty($bodyHtml) && strpos($bodyHtml, '{content}') === false) {
        $errors[] = 'Πρέπει να περιέχει το placeholder {content} για το περιεχόμενο του newsletter.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            dbExecute("UPDATE newsletter_templates SET name=?, body_html=?, updated_at=NOW() WHERE id=?",
                [$name, $bodyHtml, $id]);
            logAudit('newsletter_template_update', 'newsletter_templates', $id);
            setFlash('success', 'Το πρότυπο αποθηκεύτηκε.');
        } else {
            $newId = dbInsert("INSERT INTO newsletter_templates (name, body_html, created_at, updated_at) VALUES (?,?,NOW(),NOW())",
                [$name, $bodyHtml]);
            logAudit('newsletter_template_create', 'newsletter_templates', $newId);
            setFlash('success', 'Το πρότυπο δημιουργήθηκε.');
        }
        redirect('newsletter-templates.php');
    }

    // Re-populate on error
    $tpl = [
        'name'      => $name,
        'body_html' => $bodyHtml,
    ];
}

// Default template for new
if (!$tpl) {
    $defaultTpl = dbFetchOne("SELECT * FROM newsletter_templates WHERE is_default = 1 LIMIT 1");
    $tpl = [
        'name'      => '',
        'body_html' => $defaultTpl ? $defaultTpl['body_html'] : '{content}',
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

        <!-- Left: editor -->
        <div class="col-lg-8">

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <label class="form-label fw-semibold">Όνομα προτύπου <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" value="<?= h($tpl['name'] ?? '') ?>" placeholder="π.χ. Χριστουγεννιάτικο, Επίσημο, Απλό" required>
                </div>
            </div>

            <!-- Body HTML -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-file-earmark-code me-1"></i>HTML Προτύπου</strong>
                    <small class="text-muted">Χρησιμοποιήστε <code>{content}</code> όπου θα εμφανίζεται το κείμενο</small>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-0" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#bodyVisual" type="button">Visual</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#bodyCode" type="button"><i class="bi bi-code-slash me-1"></i>HTML</button></li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom">
                        <div class="tab-pane fade show active p-0" id="bodyVisual">
                            <textarea id="bodySummernote" class="summernote-editor"><?= h($tpl['body_html'] ?? '') ?></textarea>
                        </div>
                        <div class="tab-pane fade p-3" id="bodyCode">
                            <textarea id="bodyCodeEditor" class="form-control font-monospace" rows="20" style="font-size:0.8rem;"><?= h($tpl['body_html'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <input type="hidden" name="body_html" id="bodyHidden">
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
                    <p class="small text-muted mb-2">Κλικ για εισαγωγή στον editor:</p>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger placeholder-btn" data-tag="{content}">
                            <code>{content}</code><br><small class="text-muted">Περιεχόμενο newsletter <strong>(υποχρεωτικό)</strong></small>
                        </button>
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
                    <iframe id="tplPreview" style="width:100%;height:400px;border:0;" sandbox="allow-same-origin"></iframe>
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

    var sampleContent = '<p style="color:#2c3e50;padding:10px 32px;line-height:1.6;">Αγαπητέ/ή εθελοντή/τρια,<br><br>Αυτό είναι <strong>δείγμα περιεχομένου</strong> newsletter. Εδώ θα εμφανίζεται το κείμενο που γράφετε στη φόρμα του ενημερωτικού δελτίου.<br><br>Με εκτίμηση,<br>' + fromName + '</p>';

    var snConfig = {
        lang: 'el-GR',
        height: 350,
        dialogsInBody: true,
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

    $('#bodySummernote').summernote(snConfig);

    // Placeholder insertion
    $(document).on('mousedown', '.placeholder-btn', function(e) {
        e.preventDefault();
        var tag = $(this).data('tag');
        // If code tab is active, insert into code editor at cursor
        if ($('#bodyCode').hasClass('active')) {
            var el = document.getElementById('bodyCodeEditor');
            var start = el.selectionStart, end = el.selectionEnd;
            var val = el.value;
            el.value = val.substring(0, start) + tag + val.substring(end);
            el.selectionStart = el.selectionEnd = start + tag.length;
            el.focus();
        } else {
            $('#bodySummernote').summernote('insertText', tag);
        }
    });

    // Tab sync: Visual ↔ HTML Code
    $('button[data-bs-target="#bodyCode"]').on('shown.bs.tab', function() {
        $('#bodyCodeEditor').val($('#bodySummernote').summernote('code'));
    });
    $('button[data-bs-target="#bodyVisual"]').on('shown.bs.tab', function() {
        $('#bodySummernote').summernote('code', $('#bodyCodeEditor').val());
    });

    // Form submit: sync hidden field + validate {content}
    $('#tplForm').on('submit', function(e) {
        var codeActive = $('#bodyCode').hasClass('active');
        var html = codeActive ? $('#bodyCodeEditor').val() : $('#bodySummernote').summernote('code');
        $('#bodyHidden').val(html);

        if (html.indexOf('{content}') === -1) {
            e.preventDefault();
            alert('Πρέπει να περιέχει το placeholder {content} μέσα στο HTML του προτύπου.');
            return false;
        }
    });

    // Live preview
    window.updatePreview = function() {
        var codeActive = $('#bodyCode').hasClass('active');
        var body = codeActive ? $('#bodyCodeEditor').val() : $('#bodySummernote').summernote('code');

        body = body.replace(/\{from_name\}/g, fromName)
                   .replace(/\{logo_url\}/g, logoHtml)
                   .replace(/\{content\}/g, sampleContent);

        document.getElementById('tplPreview').srcdoc = body;
    };
    updatePreview();

    // Auto-refresh preview on change
    $('#bodySummernote').on('summernote.change', function() {
        clearTimeout(window._previewTimer);
        window._previewTimer = setTimeout(updatePreview, 500);
    });
    $('#bodyCodeEditor').on('input', function() {
        clearTimeout(window._previewTimer);
        window._previewTimer = setTimeout(updatePreview, 500);
    });
});
</script>
