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
$kitId = (int)get('kit_id');

$items = [];

if ($kitId > 0) {
    $kitObj = getInventoryKit($kitId);
    if ($kitObj) {
        // Format kit to look like an item for the label printer
        $items[] = [
            'id' => 'K' . $kitObj['id'],
            'barcode' => $kitObj['barcode'],
            'name' => 'ΣΕΤ: ' . $kitObj['name']
        ];
    }
} elseif ($id > 0) {
    $itemObj = getInventoryItem($id);
    if ($itemObj) $items[] = $itemObj;
} elseif (!empty($ids)) {
    $idList = array_filter(array_map('intval', explode(',', $ids)));
    foreach ($idList as $iid) {
        $it = getInventoryItem($iid);
        if ($it) $items[] = $it;
    }
} else {
    setFlash('error', 'Δεν επιλέχθηκε υλικό ή σετ για εκτύπωση.');
    redirect('inventory.php');
}

if (empty($items)) {
    setFlash('error', 'Δεν βρέθηκε κανένα υλικό ή σετ.');
    redirect('inventory.php');
}

$appName = getSetting('app_name', 'VolunteerOps');
$pageTitle = 'Εκτύπωση Ετικετών';

// Build JSON-safe item list for JS
$jsItems = [];
foreach ($items as $it) {
    $jsItems[] = [
        'barcode' => $it['barcode'] ?? '',
        'name'    => $it['name'] ?? '',
    ];
}
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
            gap: 10px;
            padding: 16px;
            justify-content: flex-start;
        }

        .label-card {
            border: 1.5px dashed #aaa;
            border-radius: 6px;
            width: 180px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 10px 8px 8px;
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
            margin-top: 4px;
            letter-spacing: 0.5px;
        }

        .label-card .label-name {
            font-size: 10px;
            font-weight: bold;
            color: #111;
            margin-top: 3px;
            line-height: 1.25;
            max-height: 30px;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
            word-break: break-word;
        }

        .label-card .label-app {
            font-size: 7px;
            color: #888;
            margin-top: 3px;
        }

        /* ── Print overrides ──────────────────────────────────────── */
        @media print {
            .no-print { display: none !important; }

            body { background: white; margin: 0; padding: 0; }

            @page {
                size: A4;
                margin: 12mm 8mm 10mm 8mm;
            }

            .labels-grid {
                display: grid;
                padding: 0;
                gap: 2mm;
            }

            /* Default: 4 columns */
            .labels-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
            .labels-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
            .labels-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
            .labels-grid.cols-1 { grid-template-columns: 1fr; }

            /* Size: small (default) */
            .labels-grid.size-small .label-card {
                border: 0.5pt solid #bbb;
                width: auto;
                height: auto;
                min-height: 34mm;
                padding: 2mm;
                border-radius: 2px;
            }
            .labels-grid.size-small .qr-wrap img { width: 20mm; height: 20mm; }
            .labels-grid.size-small .label-barcode { font-size: 6.5pt; }
            .labels-grid.size-small .label-name { font-size: 7pt; max-height: 8mm; }
            .labels-grid.size-small .label-app { font-size: 5.5pt; }

            /* Size: medium */
            .labels-grid.size-medium .label-card {
                border: 0.5pt solid #bbb;
                width: auto;
                height: auto;
                min-height: 42mm;
                padding: 2.5mm;
                border-radius: 2px;
            }
            .labels-grid.size-medium .qr-wrap img { width: 26mm; height: 26mm; }
            .labels-grid.size-medium .label-barcode { font-size: 7pt; }
            .labels-grid.size-medium .label-name { font-size: 8pt; max-height: 10mm; }
            .labels-grid.size-medium .label-app { font-size: 6pt; }

            /* Size: large */
            .labels-grid.size-large .label-card {
                border: 0.5pt solid #bbb;
                width: auto;
                height: auto;
                min-height: 55mm;
                padding: 3mm;
                border-radius: 2px;
            }
            .labels-grid.size-large .qr-wrap img { width: 34mm; height: 34mm; }
            .labels-grid.size-large .label-barcode { font-size: 8pt; }
            .labels-grid.size-large .label-name { font-size: 9pt; max-height: 12mm; }
            .labels-grid.size-large .label-app { font-size: 6.5pt; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ────────────────────────────────────────────────── -->
<div class="no-print bg-white border-bottom px-4 py-3 shadow-sm">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h5 class="mb-0"><i class="bi bi-printer me-2 text-primary"></i>Εκτύπωση Ετικετών</h5>
            <small class="text-muted"><?= count($items) ?> μοναδική(ές) ετικέτα(ες) — <span id="totalCount"><?= count($items) ?></span> συνολικά προς εκτύπωση</small>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-primary btn-lg">
                <i class="bi bi-printer me-1"></i>Εκτύπωση
            </button>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Πίσω
            </a>
        </div>
    </div>

    <!-- ── Options ─────────────────────────────────────────────────── -->
    <div class="row g-3 align-items-end">
        <!-- Label size -->
        <div class="col-auto">
            <label class="form-label fw-semibold mb-1"><i class="bi bi-aspect-ratio me-1"></i>Μέγεθος ετικέτας</label>
            <select id="optSize" class="form-select form-select-sm" style="width:160px">
                <option value="small">Μικρό (4×8)</option>
                <option value="medium" selected>Μεσαίο (3×6)</option>
                <option value="large">Μεγάλο (2×4)</option>
            </select>
        </div>

        <!-- Fill A4 -->
        <div class="col-auto">
            <label class="form-label fw-semibold mb-1"><i class="bi bi-file-earmark me-1"></i>Γέμισμα σελίδας A4</label>
            <select id="optFill" class="form-select form-select-sm" style="width:280px">
                <option value="none">Χωρίς επανάληψη — μόνο τα επιλεγμένα</option>
                <option value="fill" selected>Γέμισε τη σελίδα Α4 (επανάληψη)</option>
                <option value="x2">×2 αντίγραφα ανά ετικέτα</option>
                <option value="x4">×4 αντίγραφα ανά ετικέτα</option>
                <option value="x8">×8 αντίγραφα ανά ετικέτα</option>
            </select>
        </div>

        <!-- Show/hide elements -->
        <div class="col-auto">
            <label class="form-label fw-semibold mb-1"><i class="bi bi-eye me-1"></i>Εμφάνιση</label>
            <div class="d-flex gap-3">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="optShowName" checked>
                    <label class="form-check-label small" for="optShowName">Όνομα</label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="optShowBarcode" checked>
                    <label class="form-check-label small" for="optShowBarcode">Barcode</label>
                </div>
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="optShowApp" checked>
                    <label class="form-check-label small" for="optShowApp">Οργανισμός</label>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Info bar ──────────────────────────────────────────────────────── -->
<div class="no-print container-fluid px-4 py-2">
    <div class="alert alert-info alert-dismissible mb-2 py-2">
        <i class="bi bi-info-circle me-1"></i>
        Το QR code κωδικοποιεί τον αριθμό barcode του κάθε υλικού. Σαρώνοντάς το με κάμερα
        ή barcode scanner θα αναζητηθεί άμεσα το υλικό στο <strong><?= h($appName) ?></strong>.
        <br><small class="text-muted"><i class="bi bi-lightbulb me-1"></i>Συμβουλή: Στις ρυθμίσεις εκτύπωσης του browser, απενεργοποιήστε τα «Headers and Footers» για καλύτερο αποτέλεσμα.</small>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
</div>

<!-- ── Labels ─────────────────────────────────────────────────────────── -->
<div id="labelsGrid" class="labels-grid cols-3 size-medium">
    <!-- Labels populated by JS -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    const ITEMS = <?= json_encode($jsItems, JSON_UNESCAPED_UNICODE) ?>;
    const APP_NAME = <?= json_encode($appName, JSON_UNESCAPED_UNICODE) ?>;
    const grid = document.getElementById('labelsGrid');
    const totalSpan = document.getElementById('totalCount');

    // Labels per A4 page for each size
    const LABELS_PER_PAGE = { small: 32, medium: 18, large: 8 };
    const COLS = { small: 4, medium: 3, large: 2 };

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function buildLabels() {
        const size = document.getElementById('optSize').value;
        const fill = document.getElementById('optFill').value;
        const showName = document.getElementById('optShowName').checked;
        const showBarcode = document.getElementById('optShowBarcode').checked;
        const showApp = document.getElementById('optShowApp').checked;

        // Determine how many labels to render
        let labelsData = [];
        const perPage = LABELS_PER_PAGE[size] || 18;

        if (fill === 'none') {
            labelsData = [...ITEMS];
        } else if (fill === 'fill') {
            // Repeat items cyclically to fill one A4 page
            for (let i = 0; i < perPage; i++) {
                labelsData.push(ITEMS[i % ITEMS.length]);
            }
        } else {
            // x2, x4, x8
            const mult = parseInt(fill.replace('x', ''), 10) || 1;
            ITEMS.forEach(item => {
                for (let i = 0; i < mult; i++) labelsData.push(item);
            });
        }

        // Update grid classes
        grid.className = 'labels-grid cols-' + COLS[size] + ' size-' + size;

        // Build HTML
        let html = '';
        labelsData.forEach(item => {
            const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=4&data=' + encodeURIComponent(item.barcode);
            html += '<div class="label-card">'
                + '<div class="qr-wrap"><img src="' + escHtml(qrUrl) + '" alt="QR ' + escHtml(item.barcode) + '" loading="lazy"></div>'
                + (showBarcode ? '<div class="label-barcode">' + escHtml(item.barcode) + '</div>' : '')
                + (showName ? '<div class="label-name">' + escHtml(item.name) + '</div>' : '')
                + (showApp ? '<div class="label-app">' + escHtml(APP_NAME) + '</div>' : '')
                + '</div>';
        });

        grid.innerHTML = html;
        totalSpan.textContent = labelsData.length;
    }

    // Bind events
    ['optSize', 'optFill', 'optShowName', 'optShowBarcode', 'optShowApp'].forEach(id => {
        document.getElementById(id).addEventListener('change', buildLabels);
    });

    // Initial render
    buildLabels();
})();
</script>
</body>
</html>
