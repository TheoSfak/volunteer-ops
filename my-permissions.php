<?php
/**
 * VolunteerOps - My Permissions
 * Shows the current user's granted page permissions (custom role users only).
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

// Only custom-role users see this page
if (empty($user['custom_role_id'])) {
    setFlash('error', 'Αυτή η σελίδα είναι διαθέσιμη μόνο για χρήστες με προσαρμοσμένο ρόλο.');
    redirect('dashboard.php');
}

$role = dbFetchOne(
    "SELECT * FROM custom_roles WHERE id = ?",
    [(int) $user['custom_role_id']]
);

if (!$role) {
    setFlash('error', 'Ο ρόλος σας δεν βρέθηκε. Επικοινωνήστε με τον διαχειριστή.');
    redirect('dashboard.php');
}

$grantedRows = dbFetchAll(
    "SELECT page_slug FROM custom_role_permissions WHERE role_id = ?",
    [(int) $role['id']]
);
$grantedDirect = array_column($grantedRows, 'page_slug');

// Expand implied permissions
$implications = [
    'missions_view'   => ['missions_manage'],
    'complaints_view' => ['complaints_manage'],
    'training_view'   => ['training_manage', 'questions_manage'],
    'citizens_view'   => ['citizens_manage'],
    'inventory_view'  => ['inventory_manage'],
    'volunteers_view' => ['volunteers_manage'],
];
$grantedEffective = $grantedDirect;
foreach ($implications as $impliedSlug => $grantingSlugs) {
    foreach ($grantingSlugs as $gs) {
        if (in_array($gs, $grantedDirect, true) && !in_array($impliedSlug, $grantedEffective, true)) {
            $grantedEffective[] = $impliedSlug;
        }
    }
}

$permissionMap = getPermissionMap();
$pageTitle = 'Τα Δικαιώματά μου';

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4" style="max-width: 900px;">
    <div class="d-flex align-items-center mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-shield-check me-2"></i>Τα Δικαιώματά μου</h1>
            <p class="text-muted mb-0">Δείτε σε ποιες ενότητες έχετε πρόσβαση με τον τρέχοντα ρόλο σας.</p>
        </div>
    </div>

    <!-- Role badge -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center gap-3 py-3">
            <span class="badge fs-6 px-3 py-2 rounded-pill"
                  style="background-color: <?= h($role['color']) ?>; color: #fff;">
                <?= h($role['name']) ?>
            </span>
            <?php if ($role['description']): ?>
                <span class="text-muted"><?= h($role['description']) ?></span>
            <?php endif; ?>
            <span class="ms-auto badge bg-light text-dark border">
                <?= count($grantedDirect) ?> δικαιώματα
            </span>
        </div>
    </div>

    <!-- Permission sections -->
    <div class="row g-3">
        <?php foreach ($permissionMap as $section => $perms): ?>
            <?php
            $sectionSlugs   = array_column($perms, 'slug');
            $sectionGranted = array_filter($sectionSlugs, fn($s) => in_array($s, $grantedEffective, true));
            $hasAny         = !empty($sectionGranted);
            ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100 <?= $hasAny ? '' : 'opacity-50' ?>">
                    <div class="card-header d-flex align-items-center gap-2 py-2 <?= $hasAny ? 'bg-success bg-opacity-10' : 'bg-light' ?>">
                        <i class="bi <?= $hasAny ? 'bi-unlock-fill text-success' : 'bi-lock-fill text-muted' ?>"></i>
                        <strong class="<?= $hasAny ? '' : 'text-muted' ?>"><?= h($section) ?></strong>
                        <span class="badge ms-auto <?= $hasAny ? 'bg-success' : 'bg-secondary' ?>">
                            <?= count($sectionGranted) ?>/<?= count($sectionSlugs) ?>
                        </span>
                    </div>
                    <div class="card-body py-3">
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($perms as $perm): ?>
                                <?php
                                $isGranted  = in_array($perm['slug'], $grantedEffective, true);
                                $isDirect   = in_array($perm['slug'], $grantedDirect, true);
                                $isImplied  = $isGranted && !$isDirect;
                                ?>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if ($isGranted): ?>
                                        <i class="bi bi-check-circle-fill text-success flex-shrink-0"></i>
                                    <?php else: ?>
                                        <i class="bi bi-x-circle text-muted flex-shrink-0"></i>
                                    <?php endif; ?>
                                    <span class="<?= $isGranted ? '' : 'text-muted' ?>"><?= h($perm['label']) ?></span>
                                    <?php if ($isImplied): ?>
                                        <span class="badge bg-info bg-opacity-75 text-dark ms-auto" title="Χορηγείται αυτόματα από άλλο δικαίωμα" style="font-size:.7rem;">implied</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p class="text-muted small mt-4 text-center">
        <i class="bi bi-info-circle me-1"></i>
        Για αλλαγή δικαιωμάτων, επικοινωνήστε με τον Διαχειριστή Συστήματος.
    </p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
