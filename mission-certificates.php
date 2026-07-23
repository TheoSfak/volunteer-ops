<?php
/**
 * VolunteerOps - Mission Certificates
 * Issues bilingual participation certificates (signed by the org's President
 * and General Secretary) to anyone — regular volunteer or guest/external-org
 * account — who took part in this mission, e.g. a joint exercise with a
 * partner organization. Deliberately standalone from certificate_types/
 * volunteer_certificates (external-qualification tracking, one row per type
 * per person forever) and citizen_certificate_types/citizen_certificates
 * (a registry of non-account members of the public) — those model something
 * else entirely; this is its own new table (mission_certificates).
 *
 * Recipients can be picked from this mission's own approved roster (guests
 * included — they flow through the same participation_requests path as
 * everyone else) or from any active user in the system. A single "issue" POST
 * can target multiple recipients at once; each gets its own row/notification/
 * certificate_number, all sharing the same language/citation text.
 *
 * Same permission gate as every other mission-scoped War Room admin page.
 * The actual document lives at mission-certificate-print.php, which a
 * recipient (including guests, via a bootstrap.php allow-list entry) can
 * reach directly to view/download their own certificate.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name, r.name AS responsible_name
     FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     LEFT JOIN users r ON r.id = m.responsible_user_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int) $mission['responsible_user_id'] === (int) $userId;
if (!$canManageWarRoom) {
    setFlash('error', 'Η έκδοση πιστοποιητικών είναι διαθέσιμη μόνο σε διαχειριστές.');
    redirect('mission-view.php?id=' . $missionId);
}

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'issue') {
        $recipientIds = array_values(array_unique(array_filter(array_map('intval', post('recipient_ids') ?: []))));
        $language = post('language') === 'en' ? 'en' : 'el';
        $citationText = trim((string) post('citation_text'));
        $citationText = $citationText !== '' ? $citationText : null;

        if (empty($recipientIds)) {
            setFlash('error', 'Επιλέξτε τουλάχιστον έναν παραλήπτη.');
            redirect('mission-certificates.php?mission_id=' . $missionId);
        }

        $issued = [];
        db()->beginTransaction();
        try {
            foreach ($recipientIds as $recipientId) {
                $certId = dbInsert(
                    "INSERT INTO mission_certificates (mission_id, recipient_user_id, language, citation_text, issued_by, issued_at)
                     VALUES (?, ?, ?, ?, ?, NOW())",
                    [$missionId, $recipientId, $language, $citationText, $userId]
                );
                // Derived from the row's own auto-increment id, never by
                // rescanning existing certificate_number values — the
                // inventory module's barcode generator does the latter and
                // has a real reuse-after-delete bug (deleting the
                // highest-numbered row would let the next issuance collide
                // with an already-handed-out number).
                $certNumber = 'EPI-' . date('Y') . '-' . str_pad((string) $certId, 6, '0', STR_PAD_LEFT);
                dbExecute("UPDATE mission_certificates SET certificate_number = ? WHERE id = ?", [$certNumber, $certId]);
                $issued[] = ['id' => $certId, 'recipient_id' => $recipientId];
            }
            db()->commit();
        } catch (Exception $e) {
            db()->rollBack();
            setFlash('error', 'Σφάλμα κατά την έκδοση πιστοποιητικών. Παρακαλώ δοκιμάστε ξανά.');
            redirect('mission-certificates.php?mission_id=' . $missionId);
        }

        // Notifications fire only after the transaction commits, so a
        // mid-batch DB error can never send a notification for a row that
        // ended up rolled back.
        foreach ($issued as $row) {
            sendNotification(
                $row['recipient_id'],
                'Εκδόθηκε Πιστοποιητικό Συμμετοχής',
                'Εκδόθηκε πιστοποιητικό συμμετοχής σας στην αποστολή: ' . $mission['title'],
                'success',
                'mission_certificate_issued',
                ['url' => 'mission-certificate-print.php?id=' . $row['id']]
            );
            logAudit('issue_mission_certificate', 'mission_certificates', $row['id']);
        }

        $count = count($issued);
        setFlash('success', $count === 1 ? 'Το πιστοποιητικό εκδόθηκε επιτυχώς.' : "Εκδόθηκαν $count πιστοποιητικά επιτυχώς.");
        redirect('mission-certificates.php?mission_id=' . $missionId);
    }

    if ($action === 'delete') {
        $certId = (int) post('certificate_id');
        $cert = dbFetchOne("SELECT id FROM mission_certificates WHERE id = ? AND mission_id = ?", [$certId, $missionId]);
        if ($cert) {
            dbExecute("DELETE FROM mission_certificates WHERE id = ?", [$certId]);
            logAudit('delete_mission_certificate', 'mission_certificates', $certId);
            setFlash('success', 'Το πιστοποιητικό διαγράφηκε.');
        }
        redirect('mission-certificates.php?mission_id=' . $missionId);
    }
}

// Already-issued certificates for this mission.
$issuedCertificates = dbFetchAll(
    "SELECT mc.*, u.name AS recipient_name, u.is_external AS recipient_is_external, u.guest_org_name AS recipient_guest_org_name
     FROM mission_certificates mc
     JOIN users u ON u.id = mc.recipient_user_id
     WHERE mc.mission_id = ?
     ORDER BY mc.issued_at DESC",
    [$missionId]
);
$alreadyCertifiedIds = array_column($issuedCertificates, 'recipient_user_id');

// Combined recipient picker — every active user, this mission's own approved
// participants (guests included, same participation_requests path as
// everyone else) sorted first.
$pickableUsers = dbFetchAll(
    "SELECT u.id, u.name, u.email, u.is_external, u.guest_org_name,
            (mp.volunteer_id IS NOT NULL) AS is_participant
     FROM users u
     LEFT JOIN (
         SELECT DISTINCT pr.volunteer_id
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?
     ) mp ON mp.volunteer_id = u.id
     WHERE u.is_active = 1
     ORDER BY is_participant DESC, u.name",
    [$missionId, PARTICIPATION_APPROVED]
);

$pageTitle = 'Πιστοποιητικά — ' . $mission['title'];
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="missions.php">Αποστολές</a></li>
                <li class="breadcrumb-item"><a href="mission-view.php?id=<?= $missionId ?>"><?= h($mission['title']) ?></a></li>
                <li class="breadcrumb-item active">Πιστοποιητικά</li>
            </ol>
        </nav>
        <h1 class="h4 mt-2 mb-0"><i class="bi bi-patch-check text-success me-2"></i>Πιστοποιητικά Συμμετοχής</h1>
    </div>
    <a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω στην Αποστολή
    </a>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-plus-circle me-1"></i>Έκδοση Νέου Πιστοποιητικού</h5>
    </div>
    <div class="card-body">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="issue">

            <div class="mb-3">
                <input type="text" class="form-control" id="certRecipientSearch" placeholder="Αναζήτηση με όνομα ή email...">
            </div>
            <div class="mb-3 d-flex justify-content-between align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="certSelectAll">
                    <label class="form-check-label fw-bold" for="certSelectAll">Επιλογή Όλων</label>
                </div>
                <span class="badge bg-primary" id="certSelectedCount">0 επιλεγμένοι</span>
            </div>
            <div class="list-group mb-3" style="max-height: 320px; overflow-y: auto;" id="certRecipientList">
                <?php foreach ($pickableUsers as $u): ?>
                    <label class="list-group-item d-flex gap-2 align-items-center cert-recipient-item">
                        <input class="form-check-input flex-shrink-0 cert-recipient-checkbox" type="checkbox" name="recipient_ids[]" value="<?= $u['id'] ?>">
                        <span class="flex-grow-1">
                            <span class="cert-recipient-name fw-bold"><?= guestNameHtml($u['name'], (bool) $u['is_external'], $u['guest_org_name']) ?></span>
                            <small class="text-muted cert-recipient-email d-block"><?= h($u['email']) ?></small>
                        </span>
                        <?php if ($u['is_participant']): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">Συμμετείχε</span>
                        <?php endif; ?>
                        <?php if (in_array((int) $u['id'], array_map('intval', $alreadyCertifiedIds), true)): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis" title="Έχει ήδη πιστοποιητικό για αυτή την αποστολή">Ήδη πιστοποιημένος</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold">Γλώσσα Πιστοποιητικού</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="language" value="el" id="certLangEl" checked>
                            <label class="form-check-label" for="certLangEl">Ελληνικά</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="language" value="en" id="certLangEn">
                            <label class="form-check-label" for="certLangEn">English</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Προσωπικό Σχόλιο / Αιτιολογία <span class="text-muted fw-normal">(προαιρετικό, κοινό για όλους τους επιλεγμένους)</span></label>
                <textarea class="form-control" name="citation_text" rows="2" placeholder="π.χ. σε αναγνώριση της εξαιρετικής συμβολής κατά την κοινή άσκηση"></textarea>
            </div>

            <button type="submit" class="btn btn-success" id="certSubmitBtn" disabled>
                <i class="bi bi-patch-check me-1"></i>Έκδοση Πιστοποιητικών
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-list-check me-1"></i>Εκδοθέντα Πιστοποιητικά <span class="badge bg-secondary rounded-pill"><?= count($issuedCertificates) ?></span></h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($issuedCertificates)): ?>
            <p class="text-muted p-3 mb-0">Δεν έχουν εκδοθεί πιστοποιητικά για αυτή την αποστολή ακόμα.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Παραλήπτης</th>
                            <th>Γλώσσα</th>
                            <th>Αριθμός</th>
                            <th>Ημ. Έκδοσης</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($issuedCertificates as $c): ?>
                        <tr>
                            <td><?= guestNameHtml($c['recipient_name'], (bool) $c['recipient_is_external'], $c['recipient_guest_org_name']) ?></td>
                            <td><?= $c['language'] === 'en' ? 'English' : 'Ελληνικά' ?></td>
                            <td><code><?= h($c['certificate_number']) ?></code></td>
                            <td><?= formatDateTime($c['issued_at']) ?></td>
                            <td class="text-end">
                                <a href="mission-certificate-print.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-eye me-1"></i>Προβολή
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή αυτού του πιστοποιητικού;');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="certificate_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('certRecipientSearch');
    const selectAllCheckbox = document.getElementById('certSelectAll');
    const items = document.querySelectorAll('.cert-recipient-item');
    const checkboxes = document.querySelectorAll('.cert-recipient-checkbox');
    const countBadge = document.getElementById('certSelectedCount');
    const submitBtn = document.getElementById('certSubmitBtn');

    searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase();
        items.forEach(item => {
            const name = item.querySelector('.cert-recipient-name').textContent.toLowerCase();
            const email = item.querySelector('.cert-recipient-email').textContent.toLowerCase();
            item.style.display = (name.includes(term) || email.includes(term)) ? '' : 'none';
        });
        updateSelectAllState();
    });

    selectAllCheckbox.addEventListener('change', function () {
        const isChecked = this.checked;
        items.forEach(item => {
            if (item.style.display !== 'none') {
                item.querySelector('.cert-recipient-checkbox').checked = isChecked;
            }
        });
        updateCount();
    });

    checkboxes.forEach(cb => cb.addEventListener('change', function () {
        updateCount();
        updateSelectAllState();
    }));

    function updateCount() {
        const count = document.querySelectorAll('.cert-recipient-checkbox:checked').length;
        countBadge.textContent = count + (count === 1 ? ' επιλεγμένος' : ' επιλεγμένοι');
        submitBtn.disabled = count === 0;
    }

    function updateSelectAllState() {
        const visible = Array.from(items).filter(item => item.style.display !== 'none');
        const visibleChecked = visible.filter(item => item.querySelector('.cert-recipient-checkbox').checked);
        if (visible.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (visibleChecked.length === visible.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else if (visibleChecked.length > 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
