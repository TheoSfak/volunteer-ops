<?php
/**
 * VolunteerOps - Εκτύπωση Υλικών Ραφιού
 * Καθαρή σελίδα εκτύπωσης για τα υλικά ραφιού (χωρίς sidebar, navbar, κουμπιά).
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

// Ensure shelf table exists before querying
try {
    dbFetchValue("SELECT 1 FROM inventory_shelf_items LIMIT 1");
} catch (\PDOException $e) {
    die('Ο πίνακας inventory_shelf_items δεν υπάρχει ακόμα. Ανοίξτε πρώτα τη σελίδα Υλικά Ραφιού.');
}

// Fetch ALL shelf items (no pagination)
$items = dbFetchAll("
    SELECT si.*, d.name AS dept_name
    FROM inventory_shelf_items si
    LEFT JOIN departments d ON si.department_id = d.id
    ORDER BY si.sort_order ASC, si.id ASC
");

// Calculate expiry status for each item (same logic as inventory-shelf.php)
$today = new DateTime();
foreach ($items as &$item) {
    $item['expiry_class'] = '';
    $item['expiry_label'] = '';
    $item['expiry_days']  = null;
    if (!empty($item['expiry_date'])) {
        $expiry   = new DateTime($item['expiry_date']);
        $diff     = $today->diff($expiry);
        $totalDays = (int) $diff->format('%r%a');
        $item['expiry_days'] = $totalDays;

        if ($totalDays < 0) {
            $item['expiry_class'] = 'danger';
            $item['expiry_label'] = 'Έληξε';
        } elseif ($totalDays <= 90) {
            $item['expiry_class'] = 'danger';
            $months = round($totalDays / 30);
            $item['expiry_label'] = $months <= 1 ? '< 1 μήνας' : "{$months} μήνες";
        } elseif ($totalDays <= 180) {
            $item['expiry_class'] = 'warning';
            $months = round($totalDays / 30);
            $item['expiry_label'] = "{$months} μήνες";
        } else {
            $item['expiry_class'] = 'success';
            $months = round($totalDays / 30);
            $item['expiry_label'] = "{$months} μήνες";
        }
    }
}
unset($item);

// Stats
$totalItems   = count($items);
$expiredCount = count(array_filter($items, fn($i) => isset($i['expiry_days']) && $i['expiry_days'] < 0));
$soonCount    = count(array_filter($items, fn($i) => isset($i['expiry_days']) && $i['expiry_days'] >= 0 && $i['expiry_days'] <= 90));
$warningCount = count(array_filter($items, fn($i) => $i['expiry_class'] === 'warning'));

$orgName   = getSetting('org_name', 'VolunteerOps');
$printDate = date('d/m/Y H:i');
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Υλικά Ραφιού - <?= h($orgName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #000;
            background: #fff;
            padding: 15mm 12mm;
        }

        /* ---- Screen toolbar (hidden when printing) ---- */
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

        /* ---- Header ---- */
        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin-bottom: 10px;
            margin-top: 40px; /* space below screen toolbar */
        }
        .print-header .org   { font-size: 8.5pt; color: #555; }
        .print-header .title { font-size: 14pt; font-weight: bold; margin: 2px 0; }
        .print-header .meta  { font-size: 8pt; color: #555; text-align: right; line-height: 1.8; }

        /* ---- Stats row ---- */
        .stats-row {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
            font-size: 8.5pt;
        }
        .stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 8px;
            border-radius: 3px;
            border: 1px solid #ccc;
        }
        .stat-pill.total   { border-color: #999; background: #f5f5f5; }
        .stat-pill.expired { border-color: #c82333; background: #fde8eb; color: #7b0e1e; }
        .stat-pill.soon    { border-color: #e07800; background: #fff3cd; color: #7a4000; }
        .stat-pill.warning { border-color: #d4a000; background: #fffbe6; color: #6b4f00; }
        .stat-pill.ok      { border-color: #28a745; background: #e8f8ed; color: #145c25; }

        /* ---- Table ---- */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead tr {
            background: #2c3e50;
            color: #fff;
        }
        thead th {
            padding: 5px 8px;
            text-align: left;
            font-size: 8.5pt;
            font-weight: bold;
            white-space: nowrap;
        }
        thead th.center { text-align: center; }
        tbody tr {
            border-bottom: 1px solid #ddd;
        }
        tbody tr:nth-child(even) { background: #f9f9f9; }

        /* Row highlight by expiry status */
        tbody tr.row-expired  { background: #fde8eb !important; }
        tbody tr.row-danger   { background: #fff0ee !important; }
        tbody tr.row-warning  { background: #fffbe6 !important; }

        tbody td {
            padding: 4px 8px;
            font-size: 9pt;
            vertical-align: middle;
        }
        .td-num   { color: #888; font-size: 8pt; width: 26px; }
        .td-name  { font-weight: 600; }
        .td-qty   { text-align: center; width: 70px; }
        .td-shelf { width: 90px; }
        .td-notes { font-size: 8.5pt; color: #555; }
        .td-expiry { width: 90px; white-space: nowrap; }
        .td-days  { text-align: center; width: 100px; white-space: nowrap; }
        .td-status { text-align: center; width: 100px; white-space: nowrap; }

        /* Expiry dot */
        .exp-dot {
            display: inline-block;
            width: 9px; height: 9px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }
        .dot-success { background: #28a745; }
        .dot-warning { background: #ffc107; border: 1px solid #d4a000; }
        .dot-danger  { background: #dc3545; }

        .days-danger  { color: #c82333; font-weight: bold; }
        .days-warning { color: #8a6d3b; font-weight: 600; }
        .days-success { color: #155724; }

        /* Expired badge */
        .badge-expired {
            display: inline-block;
            background: #dc3545;
            color: #fff;
            font-size: 7.5pt;
            font-weight: bold;
            padding: 1px 5px;
            border-radius: 3px;
        }

        /* ---- Legend ---- */
        .legend {
            margin-top: 10px;
            font-size: 8pt;
            color: #666;
            display: flex;
            gap: 16px;
        }

        /* ---- Footer ---- */
        .print-footer {
            margin-top: 14px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
            font-size: 8pt;
            color: #888;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; }
            .print-header { margin-top: 0; }
            @page { size: A4 portrait; margin: 12mm 10mm; }
        }
    </style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="screen-notice">
    <span>Προεπισκόπηση Εκτύπωσης &mdash; <?= $totalItems ?> υλικά ραφιού</span>
    <button onclick="window.print()">&#128438; Εκτύπωση</button>
    <button onclick="window.close()">&#10005; Κλείσιμο</button>
</div>

<!-- Print header -->
<div class="print-header">
    <div>
        <div class="org"><?= h($orgName) ?></div>
        <div class="title">&#128218; Υλικά Ραφιού</div>
    </div>
    <div class="meta">
        <div>Ημ/νία εκτύπωσης: <strong><?= $printDate ?></strong></div>
        <div>Σύνολο υλικών: <strong><?= $totalItems ?></strong></div>
    </div>
</div>

<!-- Stats row -->
<div class="stats-row">
    <span class="stat-pill total">&#128230; Σύνολο: <strong><?= $totalItems ?></strong></span>
    <?php if ($expiredCount > 0): ?>
        <span class="stat-pill expired">&#9888; Ληγμένα: <strong><?= $expiredCount ?></strong></span>
    <?php endif; ?>
    <?php if ($soonCount > 0): ?>
        <span class="stat-pill soon">&#128308; &lt; 3 μήνες: <strong><?= $soonCount ?></strong></span>
    <?php endif; ?>
    <?php if ($warningCount > 0): ?>
        <span class="stat-pill warning">&#128993; 3-6 μήνες: <strong><?= $warningCount ?></strong></span>
    <?php endif; ?>
    <?php
        $okCount = count(array_filter($items, fn($i) => $i['expiry_class'] === 'success'));
        if ($okCount > 0):
    ?>
        <span class="stat-pill ok">&#128994; &gt; 6 μήνες: <strong><?= $okCount ?></strong></span>
    <?php endif; ?>
</div>

<?php if (empty($items)): ?>
    <p style="margin-top:20px; color:#888; text-align:center;">Δεν υπάρχουν καταχωρημένα υλικά ραφιού.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th class="td-num">#</th>
            <th>Όνομα</th>
            <th class="center td-qty">Αριθμός</th>
            <th class="td-shelf">Ράφι</th>
            <th>Σημειώσεις</th>
            <th class="td-expiry">Ημ/νία Λήξης</th>
            <th class="center td-days">Ημέρες για Λήξη</th>
            <th class="center td-status">Κατάσταση</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $item):
            // Row CSS class for visual grouping
            $rowClass = '';
            if (isset($item['expiry_days'])) {
                if ($item['expiry_days'] < 0)          $rowClass = 'row-expired';
                elseif ($item['expiry_class'] === 'danger')  $rowClass = 'row-danger';
                elseif ($item['expiry_class'] === 'warning') $rowClass = 'row-warning';
            }
        ?>
        <tr class="<?= $rowClass ?>">
            <td class="td-num"><?= $i + 1 ?></td>
            <td class="td-name"><?= h($item['name']) ?></td>
            <td class="td-qty"><strong><?= (int)$item['quantity'] ?></strong></td>
            <td class="td-shelf"><?= !empty($item['shelf']) ? h($item['shelf']) : '<span style="color:#aaa;">—</span>' ?></td>
            <td class="td-notes"><?= !empty($item['notes']) ? h($item['notes']) : '<span style="color:#aaa;">—</span>' ?></td>
            <td class="td-expiry">
                <?php if (!empty($item['expiry_date'])): ?>
                    <?= formatDate($item['expiry_date']) ?>
                <?php else: ?>
                    <span style="color:#aaa;">—</span>
                <?php endif; ?>
            </td>
            <td class="td-days">
                <?php if (isset($item['expiry_days'])): ?>
                    <?php if ($item['expiry_days'] < 0): ?>
                        <span class="badge-expired">Έληξε <?= abs($item['expiry_days']) ?> ημ.</span>
                    <?php else: ?>
                        <span class="days-<?= $item['expiry_class'] ?>"><?= $item['expiry_days'] ?> ημ.</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#aaa;">—</span>
                <?php endif; ?>
            </td>
            <td class="td-status">
                <?php if (!empty($item['expiry_class'])): ?>
                    <span class="exp-dot dot-<?= $item['expiry_class'] ?>"></span><?= h($item['expiry_label']) ?>
                <?php else: ?>
                    <span style="color:#aaa;">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Legend -->
<div class="legend">
    <span><span class="exp-dot dot-danger" style="display:inline-block;"></span> Ληγμένο / &lt; 3 μήνες</span>
    <span><span class="exp-dot dot-warning" style="display:inline-block;"></span> 3–6 μήνες (προσοχή)</span>
    <span><span class="exp-dot dot-success" style="display:inline-block;"></span> &gt; 6 μήνες (OK)</span>
    <span style="color:#aaa;">Χωρίς ημ. λήξης = χωρίς σήμανση</span>
</div>
<?php endif; ?>

<!-- Footer -->
<div class="print-footer">
    <span><?= h($orgName) ?> &mdash; Υλικά Ραφιού</span>
    <span>Εκτυπώθηκε: <?= $printDate ?></span>
</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>
