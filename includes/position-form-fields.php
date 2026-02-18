<?php
/**
 * Partial: Position form fields (used by add + edit modals in volunteer-positions.php)
 * Variables: $pfName, $pfColor, $pfIcon, $pfDesc, $pfActive, $pfSort (all optional, default empty)
 */
$pfName   = $pfName   ?? '';
$pfColor  = $pfColor  ?? 'secondary';
$pfIcon   = $pfIcon   ?? '';
$pfDesc   = $pfDesc   ?? '';
$pfActive = $pfActive ?? 1;
$pfSort   = $pfSort   ?? '';
$colorOptions = $colorOptions ?? [
    'primary'   => 'Μπλε',
    'secondary' => 'Γκρι',
    'success'   => 'Πράσινο',
    'danger'    => 'Κόκκινο',
    'warning'   => 'Πορτοκαλί',
    'info'      => 'Γαλάζιο',
    'dark'      => 'Σκούρο',
];
?>
<div class="mb-3">
    <label class="form-label">Όνομα Θέσης <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="name" value="<?= h($pfName) ?>"
           required placeholder="π.χ. Υπεύθυνος Τμήματος">
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Χρώμα</label>
        <select class="form-select" name="color" id="colorSelect_<?= uniqid() ?>">
            <?php foreach ($colorOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $pfColor === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Bootstrap Icon <small class="text-muted">(προαιρετικό)</small></label>
        <input type="text" class="form-control" name="icon" value="<?= h($pfIcon) ?>"
               placeholder="π.χ. bi-person-lines-fill">
        <small class="text-muted"><a href="https://icons.getbootstrap.com/" target="_blank">Εύρεση icons</a></small>
    </div>
</div>
<div class="mb-3">
    <label class="form-label">Περιγραφή <small class="text-muted">(προαιρετικό)</small></label>
    <textarea class="form-control" name="description" rows="2"
              placeholder="Σύντομη περιγραφή της θέσης"><?= h($pfDesc) ?></textarea>
</div>
<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Σειρά Εμφάνισης</label>
        <input type="number" class="form-control" name="sort_order" value="<?= h($pfSort) ?>" min="0">
    </div>
    <div class="col-md-6 mb-3 d-flex align-items-end">
        <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="isActivePos"
                   <?= $pfActive ? 'checked' : '' ?>>
            <label class="form-check-label" for="isActivePos">Ενεργή θέση</label>
        </div>
    </div>
</div>
