<?php
/**
 * VolunteerOps - Inventory Print View
 * Clean print-friendly page showing all items matching current filters.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

// Filters from GET params
$status     = get('status');
$categoryId = (int)get('category_id', 0);
$search     = get('search');

$filters = [];
if ($status)     $filters['status']      = $status;
if ($categoryId) $filters['category_id'] = $categoryId;
if ($search)     $filters['search']      = $search;

// Fetch ALL items (no pagination)
$items = getInventoryItems($filters);
$total = count($items);

// Category name for header
$categoryName = null;
if ($categoryId) {
    $categories = getInventoryCategories();
    foreach ($categories as $cat) {
        if ($cat['id'] == $categoryId) {
            $categoryName = $cat['icon'] . ' ' . $cat['name'];
            break;
        }
    }
}

// Status label for header
$statusLabel = null;
if ($status) {
    $statusLabel = INVENTORY_STATUS_LABELS[$status] ?? $status;
}

// Organization name
$orgName = getSetting('org_name', 'VolunteerOps');

// Active filters description
$activeFilters = [];
if ($statusLabel)   $activeFilters[] = 'Κατάσταση: ' . $statusLabel;
if ($categoryName)  $activeFilters[] = 'Κατηγορία: ' . $categoryName;
if ($search)        $activeFilters[] = 'Αναζήτηση: "' . h($search) . '"';

$printDate = date('d/m/Y H:i');
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Λίστα Υλικών - <?= h($orgName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            padding: 15mm 12mm;
        }

        /* ---- Header ---- */
        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .print-header .org { font-size: 8.5pt; color: #555; }
        .print-header .title { font-size: 14pt; font-weight: bold; margin: 2px 0; }
        .print-header .meta { font-size: 8pt; color: #555; text-align: right; line-height: 1.6; }

        /* ---- Filter pills ---- */
        .filter-row {
            margin-bottom: 8px;
            font-size: 8.5pt;
            color: #444;
        }
        .filter-pill {
            display: inline-block;
            background: #f0f0f0;
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 1px 6px;
            margin-right: 5px;
        }

        /* ---- Table ---- */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }
        thead tr {
            background: #2c3e50;
            color: #fff;
        }
        thead th {
            padding: 5px 7px;
            text-align: left;
            font-size: 8.5pt;
            font-weight: bold;
            white-space: nowrap;
        }
        tbody tr {
            border-bottom: 1px solid #e0e0e0;
        }
        tbody tr:nth-child(even) {
            background: #f9f9f9;
        }
        tbody td {
            padding: 4px 7px;
            font-size: 9pt;
            vertical-align: middle;
        }
        .td-num { color: #888; font-size: 8pt; width: 30px; }
        .td-barcode { font-family: 'Courier New', monospace; font-size: 8.5pt; color: #1a56cc; white-space: nowrap; }
        .td-name { font-weight: 500; }
        .td-cat { white-space: nowrap; }
        .td-status { white-space: nowrap; }

        /* Status color dots */
        .status-dot {
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }
        .dot-available   { background: #28a745; }
        .dot-booked      { background: #007bff; }
        .dot-maintenance { background: #fd7e14; }
        .dot-damaged     { background: #dc3545; }

        /* ---- Footer ---- */
        .print-footer {
            margin-top: 12px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
            font-size: 8pt;
            color: #888;
            display: flex;
            justify-content: space-between;
        }

        /* ---- No-print notice (only shown on screen) ---- */
        .screen-notice {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: #17375e;
            color: #fff;
            text-align: center;
            padding: 8px 16px;
            font-size: 10pt;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }
        .screen-notice button {
            background: #fff;
            color: #17375e;
            border: none;
            padding: 4px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 9pt;
            font-weight: bold;
        }
        .screen-notice button:hover { background: #e0e8f5; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; }
            @page { size: A4 landscape; margin: 12mm 10mm; }
        }
    </style>
</head>
<body>

<!-- Screen-only toolbar (hidden when printing) -->
<div class="screen-notice">
    <span>Προεπισκόπηση Εκτύπωσης &mdash; <?= $total ?> εγγραφές</span>
    <button onclick="window.print()">&#128438; Εκτύπωση</button>
    <button onclick="window.close()">&#10005; Κλείσιμο</button>
</div>

<!-- Print header -->
<div class="print-header" style="margin-top: 36px;">
    <div>
        <div class="org"><?= h($orgName) ?></div>
        <div class="title">&#128230; Λίστα Υλικών &amp; Εξοπλισμού</div>
        <?php if ($activeFilters): ?>
            <div style="margin-top:4px;">
                <?php foreach ($activeFilters as $f): ?>
                    <span class="filter-pill"><?= $f ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="meta">
        <div>Ημ/νία εκτύπωσης: <strong><?= $printDate ?></strong></div>
        <div>Σύνολο εγγραφών: <strong><?= $total ?></strong></div>
        <?php if (!$activeFilters): ?><div>Εμφανίζονται: Όλα τα υλικά</div><?php endif; ?>
    </div>
</div>

<?php if (empty($items)): ?>
    <p style="margin-top:20px; color:#888; text-align:center;">Δεν βρέθηκαν υλικά με τα επιλεγμένα φίλτρα.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th class="td-num">#</th>
            <th>Barcode</th>
            <th>Όνομα</th>
            <th>Κατηγορία</th>
            <th>Τοποθεσία</th>
            <th>Κατάσταση</th>
            <th>Χρεωμένο σε</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $item):
            $statusDotClass = match($item['status']) {
                'available'   => 'dot-available',
                'booked'      => 'dot-booked',
                'maintenance' => 'dot-maintenance',
                'damaged'     => 'dot-damaged',
                default       => ''
            };
            $statusLabel = INVENTORY_STATUS_LABELS[$item['status']] ?? $item['status'];
        ?>
        <tr>
            <td class="td-num"><?= $i + 1 ?></td>
            <td class="td-barcode"><?= h($item['barcode']) ?></td>
            <td class="td-name"><?= h($item['name']) ?></td>
            <td class="td-cat">
                <?php if ($item['category_name']): ?>
                    <?= h($item['category_icon']) ?> <?= h($item['category_name']) ?>
                <?php else: ?>
                    <span style="color:#aaa;">—</span>
                <?php endif; ?>
            </td>
            <td><?= h($item['location_name'] ?? '—') ?></td>
            <td class="td-status">
                <span class="status-dot <?= $statusDotClass ?>"></span><?= h($statusLabel) ?>
            </td>
            <td><?= h($item['booked_by_name'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Footer -->
<div class="print-footer">
    <span><?= h($orgName) ?> &mdash; Λίστα Υλικών</span>
    <span>Εκτυπώθηκε: <?= $printDate ?></span>
</div>

<script>
    // Auto-open print dialog after short delay (so page renders first)
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>
