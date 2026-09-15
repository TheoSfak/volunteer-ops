<?php
/**
 * VolunteerOps - Ειδοποιήσεις Εθελοντών
 *
 * Who has switched an operational notification off, so someone can go and talk
 * to them. Built after a real mission announcement reported "Failed:1" for a
 * volunteer who had simply muted new_mission (see sendMissionOpenedNotifications
 * in includes/email.php) — the admin could see that one person existed, but had
 * no way to find out who.
 *
 * The important subtlety, and the reason this page cannot just SELECT the
 * table: user_notification_preferences only holds a row once someone has
 * actively saved their preferences. isUserNotifEnabled() (includes/email.php)
 * treats a MISSING row as opted-in. So every name here is a deliberate choice,
 * and the silent majority with no row at all is reported as a count instead.
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('volunteers_manage');

$pageTitle   = 'Ειδοποιήσεις Εθελοντών';
$currentPage = 'volunteer-notifications';
$user        = getCurrentUser();

/**
 * Codes that decide whether a volunteer finds out they are needed, or turns up
 * to something that was called off. Muting one of these is worth a phone call.
 * Everything else — the Action Room traffic that fires continuously during an
 * operation — is listed separately, because muting that is reasonable and
 * treating it as equally serious would bury the cases that matter.
 */
const NOTIF_CRITICAL_CODES = [
    'new_mission',
    'mission_needs_volunteers',
    'mission_reminder',
    'mission_canceled',
    'mission_dispatch_point',
    'shift_reminder',
    'shift_canceled',
    'participation_approved',
];

$search = trim((string) get('search', ''));

// Same department scoping volunteers.php applies: a department admin sees only
// their own people, a system admin sees everyone.
$scopeWhere  = ['u.deleted_at IS NULL'];
$scopeParams = [];
if ($user['role'] === ROLE_DEPARTMENT_ADMIN) {
    $scopeWhere[]  = 'u.department_id = ?';
    $scopeParams[] = $user['department_id'];
}
if ($search !== '') {
    $scopeWhere[]  = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($scopeParams, $like, $like, $like);
}
$scopeSql = implode(' AND ', $scopeWhere);

// Every opt-out row, with the notification's human label. Ordered so the same
// person's entries stay together.
$rows = dbFetchAll(
    "SELECT u.id, u.name, u.email, u.phone, u.role,
            d.name AS department_name,
            p.notification_code, p.email_enabled, p.in_app_enabled, p.push_enabled, p.updated_at,
            ns.name AS notification_name
     FROM user_notification_preferences p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN notification_settings ns ON ns.code = p.notification_code
     WHERE $scopeSql
       AND (p.email_enabled = 0 OR p.in_app_enabled = 0 OR p.push_enabled = 0)
     ORDER BY u.name, p.notification_code",
    $scopeParams
);

$critical = [];
$other    = [];
foreach ($rows as $r) {
    if (in_array($r['notification_code'], NOTIF_CRITICAL_CODES, true)) {
        $critical[] = $r;
    } else {
        $other[] = $r;
    }
}

// The silent majority: no preference row at all means opted in to everything,
// by default rather than by choice. A count is the useful form — listing them
// would just be the roster again.
$totalVolunteers = (int) dbFetchValue("SELECT COUNT(*) FROM users u WHERE $scopeSql", $scopeParams);
$withAnyPrefs    = (int) dbFetchValue(
    "SELECT COUNT(DISTINCT p.user_id) FROM user_notification_preferences p
     JOIN users u ON u.id = p.user_id WHERE $scopeSql",
    $scopeParams
);
$neverConfigured = max(0, $totalVolunteers - $withAnyPrefs);
$criticalPeople  = count(array_unique(array_column($critical, 'id')));

// Admin-level switches. These override every personal preference: if one is
// off, nobody receives that notification regardless of what they chose, and it
// produces the identical "skipped" result inside sendNotificationEmail(). Worth
// surfacing here so a silent global toggle is not mistaken for a user's choice.
$globallyOff = dbFetchAll(
    "SELECT code, name, email_enabled FROM notification_settings WHERE email_enabled = 0 ORDER BY code"
);

$channelBadges = function (array $r): string {
    $out = '';
    if (!$r['push_enabled'])   $out .= '<span class="badge bg-danger me-1">Push OFF</span>';
    if (!$r['email_enabled'])  $out .= '<span class="badge bg-warning text-dark me-1">Email OFF</span>';
    if (!$r['in_app_enabled']) $out .= '<span class="badge bg-secondary me-1">In-app OFF</span>';
    return $out;
};

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-bell-slash me-2 text-secondary"></i>Ειδοποιήσεις Εθελοντών
    </h1>
    <a href="settings.php?tab=notifications" class="btn btn-outline-primary">
        <i class="bi bi-sliders me-1"></i>Ρυθμίσεις Ειδοποιήσεων
    </a>
</div>

<?= showFlash() ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100 <?= $criticalPeople > 0 ? 'border-danger' : '' ?>">
            <div class="card-body">
                <div class="text-muted small">Έχουν κλείσει κρίσιμη ειδοποίηση</div>
                <div class="h2 mb-0 <?= $criticalPeople > 0 ? 'text-danger' : 'text-success' ?>"><?= $criticalPeople ?></div>
                <div class="small text-muted">εθελοντές — ενδέχεται να μη μάθουν για μια κλήση</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Άλλες ειδοποιήσεις κλειστές</div>
                <div class="h2 mb-0"><?= count($other) ?></div>
                <div class="small text-muted">κυρίως κίνηση Action Room — συνήθως εντάξει</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Δεν έχουν αλλάξει ποτέ ρυθμίσεις</div>
                <div class="h2 mb-0"><?= $neverConfigured ?></div>
                <div class="small text-muted">από <?= $totalVolunteers ?> — λαμβάνουν τα πάντα (προεπιλογή)</div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($globallyOff)): ?>
<div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>Κλειστές από τον διαχειριστή (για όλους):</strong>
    <?php foreach ($globallyOff as $g): ?>
        <span class="badge bg-dark me-1"><?= h($g['name'] ?: $g['code']) ?></span>
    <?php endforeach; ?>
    <div class="small mt-1">Αυτές δεν στέλνονται σε κανέναν, ανεξάρτητα από τις προσωπικές ρυθμίσεις. Δεν είναι επιλογή των εθελοντών.</div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Αναζήτηση</label>
                <input type="text" class="form-control" name="search" value="<?= h($search) ?>" placeholder="Όνομα, email, τηλέφωνο...">
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Αναζήτηση
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Κρίσιμες -->
<div class="card mb-4">
    <div class="card-header bg-danger-subtle">
        <h5 class="mb-0"><i class="bi bi-telephone-x me-1"></i>Κρίσιμες ειδοποιήσεις — αξίζει ένα τηλέφωνο</h5>
    </div>
    <?php if (empty($critical)): ?>
        <div class="card-body text-success mb-0">
            <i class="bi bi-check-circle me-1"></i>Κανένας εθελοντής δεν έχει κλείσει ειδοποίηση κλήσης, βάρδιας ή εντολής.
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr>
                <th>Εθελοντής</th><th>Επικοινωνία</th><th>Ειδοποίηση</th><th>Κανάλια</th><th>Άλλαξε</th>
            </tr></thead>
            <tbody>
            <?php foreach ($critical as $r): ?>
                <tr>
                    <td>
                        <a href="volunteer-view.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
                        <?php if (!empty($r['department_name'])): ?>
                            <small class="text-muted d-block"><?= h($r['department_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($r['phone'])): ?>
                            <a href="tel:<?= h($r['phone']) ?>"><?= h($r['phone']) ?></a><br>
                        <?php endif; ?>
                        <span class="text-muted"><?= h($r['email']) ?></span>
                    </td>
                    <td><?= h($r['notification_name'] ?: $r['notification_code']) ?></td>
                    <td><?= $channelBadges($r) ?></td>
                    <td class="small text-muted"><?= formatDate($r['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Υπόλοιπες -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-volume-mute me-1"></i>Υπόλοιπες ειδοποιήσεις (<?= count($other) ?>)</h5>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#otherOptOuts">
            Εμφάνιση
        </button>
    </div>
    <div class="collapse" id="otherOptOuts">
        <?php if (empty($other)): ?>
            <div class="card-body text-muted mb-0">Καμία άλλη ειδοποίηση κλειστή.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead><tr><th>Εθελοντής</th><th>Ειδοποίηση</th><th>Κανάλια</th><th>Άλλαξε</th></tr></thead>
                <tbody>
                <?php foreach ($other as $r): ?>
                    <tr>
                        <td><a href="volunteer-view.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
                        <td class="small"><?= h($r['notification_name'] ?: $r['notification_code']) ?></td>
                        <td><?= $channelBadges($r) ?></td>
                        <td class="small text-muted"><?= formatDate($r['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>
    Οι εθελοντές που δεν εμφανίζονται εδώ λαμβάνουν κανονικά τις ειδοποιήσεις. Μια ρύθμιση
    αποθηκεύεται μόνο όταν κάποιος την αλλάξει ο ίδιος από τις
    <em>Ρυθμίσεις Ειδοποιήσεων</em> του λογαριασμού του — όσοι δεν την άγγιξαν ποτέ είναι
    ενεργοί σε όλα από προεπιλογή. Το <strong>Push</strong> είναι το κανάλι που φτάνει
    πιο γρήγορα στο κινητό· κλειστό Email με ανοιχτό Push συνήθως δεν είναι πρόβλημα.
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
