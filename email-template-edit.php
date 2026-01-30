<?php
/**
 * VolunteerOps - Email Template Edit with Summernote WYSIWYG Editor
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$id = (int)get('id', 0);

if (!$id) {
    setFlash('danger', 'Δεν βρέθηκε το template.');
    redirect('settings.php?tab=templates');
}

$template = dbFetchOne("SELECT * FROM email_templates WHERE id = ?", [$id]);

if (!$template) {
    setFlash('danger', 'Δεν βρέθηκε το template.');
    redirect('settings.php?tab=templates');
}

$pageTitle = 'Επεξεργασία Template: ' . $template['name'];

$errors = [];

if (isPost()) {
    verifyCsrf();
    
    $subject = post('subject', '');
    $bodyHtml = $_POST['body_html'] ?? ''; // Don't sanitize HTML
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($subject)) {
        $errors[] = 'Το θέμα είναι υποχρεωτικό.';
    }
    
    if (empty($bodyHtml)) {
        $errors[] = 'Το περιεχόμενο είναι υποχρεωτικό.';
    }
    
    if (empty($errors)) {
        dbExecute(
            "UPDATE email_templates SET subject = ?, body_html = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
            [$subject, $bodyHtml, $isActive, $id]
        );
        
        logAudit('update', 'email_templates', $id, $template['code']);
        setFlash('success', 'Το template αποθηκεύτηκε επιτυχώς.');
        redirect('settings.php?tab=templates');
    }
    
    // Update template with posted values for re-display
    $template['subject'] = $subject;
    $template['body_html'] = $bodyHtml;
    $template['is_active'] = $isActive;
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="settings.php?tab=templates" class="btn btn-outline-secondary btn-sm mb-2">
            <i class="bi bi-arrow-left me-1"></i>Πίσω στα Templates
        </a>
        <h1 class="h3 mb-0">
            <i class="bi bi-file-earmark-code me-2"></i><?= h($template['name']) ?>
        </h1>
    </div>
    <button type="button" class="btn btn-outline-secondary" onclick="previewEmail()">
        <i class="bi bi-eye me-1"></i>Προεπισκόπηση
    </button>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?= showFlash() ?>

<form method="post" id="templateForm">
    <?= csrfField() ?>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Περιεχόμενο Email</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Θέμα Email</label>
                        <input type="text" class="form-control" name="subject" 
                               value="<?= h($template['subject']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Περιεχόμενο (HTML)</label>
                        <ul class="nav nav-tabs" id="editorTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="visual-tab" data-bs-toggle="tab" data-bs-target="#visual-pane" 
                                        type="button" role="tab">
                                    <i class="bi bi-eye me-1"></i>Visual Editor
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="html-tab" data-bs-toggle="tab" data-bs-target="#html-pane" 
                                        type="button" role="tab">
                                    <i class="bi bi-code-slash me-1"></i>HTML Code
                                </button>
                            </li>
                        </ul>
                        <div class="tab-content border border-top-0 rounded-bottom">
                            <div class="tab-pane fade show active p-0" id="visual-pane" role="tabpanel">
                                <textarea id="summernote" name="body_html"><?= h($template['body_html']) ?></textarea>
                            </div>
                            <div class="tab-pane fade p-3" id="html-pane" role="tabpanel">
                                <textarea id="htmlEditor" class="form-control font-monospace" rows="20" 
                                          style="font-size: 13px;"><?= h($template['body_html']) ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                               <?= $template['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">
                            Ενεργό Template
                        </label>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                    <a href="settings.php?tab=templates" class="btn btn-outline-secondary">
                        Ακύρωση
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-braces me-1"></i>Διαθέσιμες Μεταβλητές</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Κάντε κλικ σε μια μεταβλητή για να την εισάγετε. Χρησιμοποιήστε διπλά άγκιστρα: <code>{{variable}}</code>
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <?php 
                        $variables = explode(', ', $template['available_variables'] ?? '');
                        foreach ($variables as $var): 
                            $var = trim($var);
                            if (empty($var)) continue;
                        ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary variable-btn" 
                                    onclick="insertVariable('<?= h($var) ?>')">
                                <?= h($var) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-1"></i>Πληροφορίες</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Κωδικός:</td>
                            <td><code><?= h($template['code']) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Περιγραφή:</td>
                            <td><?= h($template['description']) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Τελ. Ενημέρωση:</td>
                            <td><?= formatDateTime($template['updated_at']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Προεπισκόπηση Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewFrame" style="width: 100%; height: 500px; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<!-- Summernote JS (must be after jQuery from footer) -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/lang/summernote-el-GR.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Summernote
    $('#summernote').summernote({
        height: 400,
        lang: 'el-GR',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
            ['fontsize', ['fontsize']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'hr']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onChange: function(contents) {
                $('#htmlEditor').val(contents);
            }
        }
    });
});

// Sync HTML editor to Summernote when switching tabs
document.getElementById('visual-tab').addEventListener('shown.bs.tab', function() {
    $('#summernote').summernote('code', $('#htmlEditor').val());
});

// Sync Summernote to HTML editor when switching tabs
document.getElementById('html-tab').addEventListener('shown.bs.tab', function() {
    $('#htmlEditor').val($('#summernote').summernote('code'));
});

// Before submit, sync content
document.getElementById('templateForm').addEventListener('submit', function() {
    // If HTML tab is active, update summernote before submit
    if (document.getElementById('html-pane').classList.contains('active')) {
        $('#summernote').summernote('code', $('#htmlEditor').val());
    }
});

function insertVariable(variable) {
    if (document.getElementById('visual-pane').classList.contains('active')) {
        $('#summernote').summernote('insertText', variable);
    } else {
        const textarea = document.getElementById('htmlEditor');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        textarea.value = text.substring(0, start) + variable + text.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + variable.length;
        textarea.focus();
    }
}

function previewEmail() {
    // Get current content
    let content = '';
    if (document.getElementById('html-pane').classList.contains('active')) {
        content = document.getElementById('htmlEditor').value;
    } else {
        content = $('#summernote').summernote('code');
    }
    
    // Replace variables with sample data
    const sampleData = {
        'app_name': '<?= h(getSetting('app_name', 'VolunteerOps')) ?>',
        'user_name': 'Γιάννης Παπαδόπουλος',
        'user_email': 'giannis@example.com',
        'mission_title': 'Εθελοντική Δράση - Καθαρισμός Παραλίας',
        'mission_description': 'Εθελοντική δράση για τον καθαρισμό της παραλίας.',
        'shift_date': '15/02/2026',
        'shift_time': '09:00 - 14:00',
        'location': 'Παραλία Γλυφάδας',
        'start_date': '10/02/2026',
        'end_date': '20/02/2026',
        'points': '50',
        'total_points': '350',
        'login_url': '#',
        'mission_url': '#'
    };
    
    for (const [key, value] of Object.entries(sampleData)) {
        content = content.replace(new RegExp('\\{\\{' + key + '\\}\\}', 'g'), value);
    }
    
    // Display in iframe
    const iframe = document.getElementById('previewFrame');
    iframe.srcdoc = content;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>
