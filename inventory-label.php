<?php
/**
 * VolunteerOps - Inventory Label Printer
 * Generates printable QR code labels for inventory items.
 * Usage: ?id=X  (single item) or ?ids=X,Y,Z  (multiple items)
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

if (!canManageInventory()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('inventory.php');
}

$id  = (int)get('id');
$ids = get('ids');

if ($id > 0) {
    $itemObj = getInventoryItem($id);
    $items   = $itemObj ? [$itemObj] : [];
} elseif (!empty($ids)) {
    $idList = array_filter(array_map('intval', explode(',', $ids)));
    $items  = [];
    foreach ($idList as $iid) {
        $it = getInventoryItem($iid);
        if ($it) $items[] = $it;
    }
} else {
    setFlash('error', 'Δεν επιλέχθηκε υλικό για εκτύπωση.');
    redirect('inventory.php');
}

if (empty($items)) {
    setFlash('error', 'Δεν βρέθηκε κανένα υλικό.');
    redirect('inventory.php');
}

$appName = getSetting('app_name', 'VolunteerOps');
$pageTitle = 'Εκτύπωση Ετικετών';
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> — <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body { background: #f4f6f9; font-family: Arial, sans-serif; }

        /* ── Label grid on screen ──────────────────────────────────── */
        .labels-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 16px;
        }

        .label-card {
            border: 1.5px dashed #aaa;
            border-radius: 6px;
            width: 170px;
            height: 130px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 8px 6px 6px;
            background: #fff;
            text-align: center;
            box-sizing: border-box;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .label-card .qr-wrap img {
            width: 80px;
            height: 80px;
            display: block;
        }

        .label-card .label-barcode {
            font-family: 'Courier New', monospace;
            font-size: 9px;
            color: #333;
            margin-top: 3px;
            letter-spacing: 0.5px;
        }

        .label-card .label-name {
            font-size: 9px;
            font-weight: bold;
            color: #111;
            margin-top: 2px;
            line-height: 1.2;
            max-height: 24px;
            overflow: hidden;
            width: 100%;
        }

        .label-card .label-app {
            font-size: 7px;
            color: #888;
            margin-top: 2px;
        }

        /* ── Print overrides ──────────────────────────────────────── */
        @media print {
            .no-print { display: none !important; }

            body { background: white; margin: 0; padding: 0; }

            .labels-grid {
                padding: 0;
                gap: 4mm;
            }

            .label-card {
                border: 0.5pt solid #bbb;
                width: 46mm;
                height: 34mm;
                padding: 2mm;
            }

            .label-card .qr-wrap img {
                width: 22mm;
                height: 22mm;
            }

            .label-card .label-barcode { font-size: 6.5pt; }
            .label-card .label-name    { font-size: 6.5pt; max-height: 7mm; }
            .label-card .label-app     { font-size: 5.5pt; }

            @page { size: A4; margin: 10mm; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ────────────────────────────────────────────────── -->
<div class="no-print bg-white border-bottom px-4 py-2 d-flex align-items-center justify-content-between shadow-sm">
    <div>
        <h5 class="mb-0"><i class="bi bi-printer me-2 text-primary"></i>Εκτύπωση Ετικετών</h5>
        <small class="text-muted"><?= count($items) ?> ετικέτα(ες)</small>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="bi bi-printer me-1"></i>Εκτύπωση
        </button>
        <a href="javascript:history.back()" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    </div>
</div>

<!-- ── Info bar ──────────────────────────────────────────────────────── -->
<div class="no-print container-fluid px-4 py-2">
    <div class="alert alert-info alert-dismissible mb-2 py-2">
        <i class="bi bi-info-circle me-1"></i>
        Το QR code κωδικοποιεί τον αριθμό barcode του κάθε υλικού. Σαρώνοντάς το με κάμερα
        ή barcode scanner θα αναζητηθεί άμεσα το υλικό στο <strong><?= h($appName) ?></strong>.
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
</div>

<!-- ── Labels ─────────────────────────────────────────────────────────── -->
<div class="labels-grid">
    <?php foreach ($items as $it):
        $barcode    = $it['barcode'] ?? '';
        $name       = $it['name'] ?? '';
        $qrUrl      = 'https://api.qrserver.com/v1/create-qr-code/'
                    . '?size=200x200&margin=4&data=' . urlencode($barcode);
    ?>
    <div class="label-card">
        <div class="qr-wrap">
            <img src="<?= h($qrUrl) ?>" alt="QR <?= h($barcode) ?>" loading="lazy">
        </div>
        <div class="label-barcode"><?= h($barcode) ?></div>
        <div class="label-name"><?= h($name) ?></div>
        <div class="label-app"><?= h($appName) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
