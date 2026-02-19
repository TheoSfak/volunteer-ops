<?php
/**
 * VolunteerOps - Newsletter View & Send
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$user = getCurrentUser();
$id   = (int)get('id');
if (!$id) { redirect('newsletters.php'); }

$nl = dbFetchOne("
    SELECT n.*, u.name AS creator_name, d.name AS dept_name
    FROM newsletters n
    LEFT JOIN users u ON u.id = n.created_by
    LEFT JOIN departments d ON d.id = n.filter_dept_id
    WHERE n.id = ?
", [$id]);
if (!$nl) {
    setFlash('error', 'Το δελτίο δεν βρέθηκε.');
    redirect('newsletters.php');
}

$pageTitle = h($nl['title']);

// ── POST actions ──────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // ── Test send (only to current admin) ──
    if ($action === 'test_send') {
        $testUser = $user;
        $testUser['dept_name'] = dbFetchValue("SELECT name FROM departments WHERE id = ?", [$testUser['department_id'] ?? 0]) ?: '';
        $token   = generateUnsubscribeToken();
        $subject = replaceNewsletterTags($nl['subject'], $testUser, $token);
        $body    = wrapNewsletterBody(replaceNewsletterTags($nl['body_html'], $testUser, $token), $nl['title']);
        $result  = sendEmail($testUser['email'], $subject, $body);
        if ($result['success']) {
            setFlash('success', 'Δοκιμαστικό email στάλθηκε στο ' . h($testUser['email']));
        } else {
            setFlash('error', 'Αποτυχία: ' . h($result['message']));
        }
        redirect("newsletter-view.php?id={$id}");
    }

    // ── Real send ──
    if ($action === 'send' && $nl['status'] === 'draft') {
        set_time_limit(0);
        ignore_user_abort(true);

        $roles  = !empty($nl['filter_roles']) ? json_decode($nl['filter_roles'], true) : [];
        $deptId = (int)($nl['filter_dept_id'] ?? 0);

        [, $recipients] = buildRecipientQuery($roles, $deptId, false);

        // Mark as sending
        dbExecute("UPDATE newsletters SET status='sending', total_recipients=?, updated_at=NOW() WHERE id=?", [count($recipients), $id]);

        $sentCount   = 0;
        $failedCount = 0;

        foreach ($recipients as $recipient) {
            $token   = generateUnsubscribeToken();
            $sendId  = dbInsert("INSERT INTO newsletter_sends (newsletter_id, user_id, email, name, status) VALUES (?,?,?,?,'pending')",
                [$id, $recipient['id'], $recipient['email'], $recipient['name']]);

            // Pre-insert unsubscribe row (pending, not yet used)
            dbInsert("INSERT IGNORE INTO newsletter_unsubscribes (user_id, email, token, newsletter_id, created_at) VALUES (?,?,?,?,NOW())",
                [$recipient['id'], $recipient['email'], $token, $id]);

            $subject = replaceNewsletterTags($nl['subject'], $recipient, $token);
            $body    = wrapNewsletterBody(replaceNewsletterTags($nl['body_html'], $recipient, $token), $nl['title']);

            $result = sendEmail($recipient['email'], $subject, $body);

            if ($result['success']) {
                dbExecute("UPDATE newsletter_sends SET status='sent', sent_at=NOW() WHERE id=?", [$sendId]);
                $sentCount++;
            } else {
                dbExecute("UPDATE newsletter_sends SET status='failed', error_msg=?, sent_at=NOW() WHERE id=?",
                    [substr($result['message'], 0, 500), $sendId]);
                $failedCount++;
            }

            usleep(200000); // 200ms between sends
        }

        dbExecute("UPDATE newsletters SET status='sent', sent_count=?, failed_count=?, sent_at=NOW(), updated_at=NOW() WHERE id=?",
            [$sentCount, $failedCount, $id]);

        logAudit('newsletter_send', 'newsletters', $id);
        setFlash('success', "Αποστολή ολοκληρώθηκε: {$sentCount} emails στάλθηκαν" . ($failedCount ? ", {$failedCount} αποτυχίες." : '.'));
        redirect("newsletter-view.php?id={$id}");
    }

    // ── Resend failed ──
    if ($action === 'resend_failed' && $nl['status'] === 'sent') {
        set_time_limit(0);
        $failed = dbFetchAll("SELECT * FROM newsletter_sends WHERE newsletter_id=? AND status='failed'", [$id]);
        $fixed  = 0;
        foreach ($failed as $send) {
            // Find user for tags
            $recipient = dbFetchOne("
                SELECT u.*, d.name AS dept_name
                FROM users u LEFT JOIN departments d ON d.id = u.department_id
                WHERE u.id = ?
            ", [$send['user_id']]);
            if (!$recipient) {
                $recipient = ['name' => $send['name'], 'email' => $send['email'], 'role' => '', 'dept_name' => '', 'total_points' => 0];
            }
            $token  = generateUnsubscribeToken();
            $subject = replaceNewsletterTags($nl['subject'], $recipient, $token);
            $body    = wrapNewsletterBody(replaceNewsletterTags($nl['body_html'], $recipient, $token), $nl['title']);
            $result  = sendEmail($send['email'], $subject, $body);
            if ($result['success']) {
                dbExecute("UPDATE newsletter_sends SET status='sent', error_msg=NULL, sent_at=NOW() WHERE id=?", [$send['id']]);
                $fixed++;
            }
            usleep(200000);
        }
        // Update counts
        $newSent   = dbFetchValue("SELECT COUNT(*) FROM newsletter_sends WHERE newsletter_id=? AND status='sent'", [$id]);
        $newFailed = dbFetchValue("SELECT COUNT(*) FROM newsletter_sends WHERE newsletter_id=? AND status='failed'", [$id]);
        dbExecute("UPDATE newsletters SET sent_count=?, failed_count=? WHERE id=?", [$newSent, $newFailed, $id]);
        setFlash('success', "Επανάληψη: {$fixed} από " . count($failed) . " εστάλησαν.");
        redirect("newsletter-view.php?id={$id}");
    }
}

// ── Reload after redirect ──
$nl = dbFetchOne("
    SELECT n.*, u.name AS creator_name, d.name AS dept_name
    FROM newsletters n
    LEFT JOIN users u ON u.id = n.created_by
    LEFT JOIN departments d ON d.id = n.filter_dept_id
    WHERE n.id = ?
", [$id]);

// Decode roles filter for display
$filterRoles   = !empty($nl['filter_roles']) ? json_decode($nl['filter_roles'], true) : [];
$roleLabels    = [
    ROLE_VOLUNTEER        => 'Εθελοντές',
    ROLE_SHIFT_LEADER     => 'Αρχηγοί Βάρδιας',
    ROLE_DEPARTMENT_ADMIN => 'Διαχ. Τμήματος',
    ROLE_SYSTEM_ADMIN     => 'Διαχ. Συστήματος',
];
$rolesDisplay  = empty($filterRoles) ? 'Όλοι' : implode(', ', array_map(fn($r) => $roleLabels[$r] ?? $r, $filterRoles));

// Recipient count estimate
[$estCount,] = buildRecipientQuery($filterRoles, (int)($nl['filter_dept_id'] ?? 0), true);

// Send log
$sendLog = dbFetchAll("
    SELECT ns.*, u.name AS linked_user_name
    FROM newsletter_sends ns
    LEFT JOIN users u ON u.id = ns.user_id
    WHERE ns.newsletter_id = ?
    ORDER BY ns.sent_at DESC, ns.id DESC
    LIMIT 200
", [$id]);
$failedCount = dbFetchValue("SELECT COUNT(*) FROM newsletter_sends WHERE newsletter_id=? AND status='failed'", [$id]);

// ── Helper: wrap body in styled email envelope for display ──
function wrapNewsletterBody(string $body, string $title): string {
    $fromName = getSetting('smtp_from_name', 'VolunteerOps');
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.hdr{background:#c0392b;padding:24px 32px;color:#fff}.hdr h2{margin:0;font-size:22px}
.body{padding:32px;color:#333;line-height:1.6}.ftr{background:#f8f8f8;padding:16px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h2>' . h($fromName) . '</h2></div>
  <div class="body">' . $body . '</div>
  <div class="ftr"><p>Αυτό το email στάλθηκε από ' . h($fromName) . '.</p></div>
</div></body></html>';
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="newsletters.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i>Ενημερωτικά</a>
        <h1 class="h3 mb-0 mt-1"><?= h($nl['title']) ?></h1>
    </div>
    <?php if ($nl['status'] === 'draft'): ?>
    <div class="d-flex gap-2">
        <a href="newsletter-form.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Επεξεργασία
        </a>
    </div>
    <?php endif; ?>
</div>

<?= displayFlash() ?>

<div class="row g-4">
    <!-- Left: Details + Preview -->
    <div class="col-lg-8">

        <!-- Campaign info -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Θέμα email</small>
                        <strong><?= h($nl['subject']) ?></strong>
                    </div>
                    <div class="col-sm-3">
                        <small class="text-muted d-block">Κατάσταση</small>
                        <?php
                        $badges = ['draft'=>'secondary','sending'=>'warning','sent'=>'success','failed'=>'danger'];
                        $labels = ['draft'=>'Πρόχειρο','sending'=>'Αποστέλλεται…','sent'=>'Εστάλη','failed'=>'Αποτυχία'];
                        $b = $badges[$nl['status']] ?? 'secondary';
                        $l = $labels[$nl['status']] ?? $nl['status'];
                        ?><span class="badge bg-<?= $b ?> fs-6"><?= $l ?></span>
                    </div>
                    <div class="col-sm-3">
                        <small class="text-muted d-block">Δημιουργός</small>
                        <?= h($nl['creator_name'] ?? '—') ?>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Αποδέκτες (Φίλτρο)</small>
                        <i class="bi bi-person-check me-1 text-primary"></i><?= h($rolesDisplay) ?>
                        <?php if ($nl['dept_name']): ?>
                            <br><i class="bi bi-building me-1 text-muted"></i><?= h($nl['dept_name']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-sm-3">
                        <small class="text-muted d-block">Χρόνος αποστολής</small>
                        <?= $nl['sent_at'] ? formatDateTime($nl['sent_at']) : '—' ?>
                    </div>
                    <div class="col-sm-3">
                        <small class="text-muted d-block">Δημιουργήθηκε</small>
                        <?= formatDateTime($nl['created_at']) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Body preview -->
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Προεπισκόπηση</strong>
                <small class="text-muted">Τα {tags} εμφανίζονται ως έχουν στην προεπισκόπηση</small>
            </div>
            <div class="card-body p-0">
                <iframe id="previewFrame" style="width:100%;height:450px;border:0;" srcdoc=""></iframe>
            </div>
        </div>

        <!-- Send log table -->
        <?php if (!empty($sendLog)): ?>
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Αρχείο αποστολής</strong>
                <small class="text-muted"><?= count($sendLog) ?> εγγραφές (max 200)</small>
            </div>
            <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr><th>Παραλήπτης</th><th>Email</th><th>Κατάσταση</th><th>Ώρα</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sendLog as $row): ?>
                        <tr class="<?= $row['status'] === 'failed' ? 'table-danger' : '' ?>">
                            <td><?= h($row['name'] ?: ($row['linked_user_name'] ?? '—')) ?></td>
                            <td><?= h($row['email']) ?></td>
                            <td>
                                <?php if ($row['status'] === 'sent'): ?>
                                    <span class="badge bg-success">Εστάλη</span>
                                <?php elseif ($row['status'] === 'failed'): ?>
                                    <span class="badge bg-danger" title="<?= h($row['error_msg'] ?? '') ?>">Αποτυχία</span>
                                    <?php if ($row['error_msg']): ?>
                                        <small class="text-danger d-block"><?= h(mb_strimwidth($row['error_msg'], 0, 80, '…')) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Εκκρεμεί</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= $row['sent_at'] ? formatDateTime($row['sent_at']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Actions + Stats -->
    <div class="col-lg-4">

        <!-- Stats -->
        <?php if (in_array($nl['status'], ['sent','sending','failed'])): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Αποτελέσματα</strong></div>
            <div class="card-body">
                <div class="row text-center g-3">
                    <div class="col-4">
                        <div class="fs-3 fw-bold text-muted"><?= $nl['total_recipients'] ?></div>
                        <small class="text-muted">Σύνολο</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-3 fw-bold text-success"><?= $nl['sent_count'] ?></div>
                        <small class="text-muted">Εστάλησαν</small>
                    </div>
                    <div class="col-4">
                        <div class="fs-3 fw-bold text-danger"><?= $nl['failed_count'] ?></div>
                        <small class="text-muted">Αποτυχίες</small>
                    </div>
                </div>
                <?php if ($nl['total_recipients'] > 0): ?>
                <div class="mt-3">
                    <div class="progress" style="height:8px;">
                        <?php $pct = round(($nl['sent_count'] / $nl['total_recipients']) * 100) ?>
                        <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                    </div>
                    <small class="text-muted"><?= $pct ?>% παραδόθηκαν</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="card shadow-sm">
            <div class="card-header"><strong>Ενέργειες</strong></div>
            <div class="card-body d-grid gap-2">

                <?php if ($nl['status'] === 'draft'): ?>
                <!-- Estimated recipients -->
                <div class="alert alert-info py-2 text-center mb-2">
                    <div class="fs-5 fw-bold"><?= $estCount ?></div>
                    <small>εκτιμώμενοι αποδέκτες</small>
                </div>

                <!-- Test send -->
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_send">
                    <button type="submit" class="btn btn-outline-info w-100">
                        <i class="bi bi-send-check me-1"></i>Δοκιμαστική αποστολή (σε μένα)
                    </button>
                </form>

                <!-- Real send -->
                <button class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#sendModal">
                    <i class="bi bi-send-fill me-1"></i>Αποστολή σε <?= $estCount ?> αποδέκτες
                </button>
                <?php endif; ?>

                <?php if ($nl['status'] === 'sent' && $failedCount > 0): ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="resend_failed">
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Επανάληψη αποτυχιών (<?= $failedCount ?>)
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($nl['status'] === 'draft'): ?>
                <a href="newsletter-form.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-pencil me-1"></i>Επεξεργασία
                </a>
                <?php endif; ?>

                <a href="newsletters.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Πίσω στη λίστα
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Send Confirmation Modal -->
<div class="modal fade" id="sendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i>Επιβεβαίωση Αποστολής</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send">
                <div class="modal-body">
                    <p>Πρόκειται να σταλεί το δελτίο <strong><?= h($nl['title']) ?></strong> σε <strong><?= $estCount ?> αποδέκτες</strong>.</p>
                    <p class="text-muted small mb-0">Η αποστολή θα ξεκινήσει αμέσως. Μην κλείσετε τον browser μέχρι να ολοκληρωθεί.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-send-fill me-1"></i>Αποστολή τώρα</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Inject body preview into iframe
(function() {
    var body = <?= json_encode($nl['body_html']) ?>;
    var title = <?= json_encode($nl['title']) ?>;
    var fromName = <?= json_encode(getSetting('smtp_from_name', 'VolunteerOps')) ?>;
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}.hdr{background:#c0392b;padding:24px 32px;color:#fff}.hdr h2{margin:0;font-size:22px}.body{padding:32px;color:#333;line-height:1.6}.ftr{background:#f8f8f8;padding:16px 32px;font-size:12px;color:#aaa;text-align:center}</style></head><body>'
        + '<div class="wrap"><div class="hdr"><h2>' + fromName + '</h2></div><div class="body">' + body + '</div>'
        + '<div class="ftr"><p>Αυτό το email στάλθηκε από ' + fromName + '.</p></div></div></body></html>';
    var frame = document.getElementById('previewFrame');
    if (frame) { frame.srcdoc = html; }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
