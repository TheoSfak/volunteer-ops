<?php
/**
 * VolunteerOps - Mission notification recipient picker (shared form partial)
 *
 * Rendered by BOTH ways a mission ever goes live: mission-view.php's
 * "Δημοσίευση" panel (publishing an existing DRAFT) and mission-form.php's
 * "Κατάσταση" card (creating — or saving — a mission straight as Ανοιχτή).
 * Both post the same field names and both are read back by the single
 * sendMissionOpenedNotifications() in includes/email.php, so this markup and
 * that reader are the whole contract. Don't hand-roll a third copy of these
 * inputs: the reason this partial exists is that the two pages drifted apart
 * once already, and only one of them had the guest/visitor exclusion.
 *
 * Element ids are fixed, so include this at most once per page.
 *
 * Optional caller variable, set before the include:
 *   $missionNotifyChecked  bool  initial state of the master checkbox (default true)
 */

if (!isset($missionNotifyChecked)) {
    $missionNotifyChecked = true;
}

// Queried here rather than by each caller: a partial that silently renders an
// empty "Ανά Θέση" group because one page forgot to run the query is a worse
// failure than one extra cheap read.
$missionNotifyPositions = dbFetchAll(
    "SELECT id, name FROM volunteer_positions WHERE is_active = 1 ORDER BY name"
);
?>
<!-- Notify toggle -->
<div class="form-check mb-2">
    <input class="form-check-input" type="checkbox" name="notify_volunteers"
           id="notifyVolunteers" value="1" <?= $missionNotifyChecked ? 'checked' : '' ?>
           onchange="toggleNotifyPanel(this.checked)">
    <label class="form-check-label small fw-semibold" for="notifyVolunteers">
        <i class="bi bi-envelope me-1"></i>Αποστολή ειδοποίησης Email
    </label>
</div>

<!-- Targeting panel (visible when checked) -->
<div id="notifyPanel" class="border rounded p-2 mb-3 bg-light small"<?= $missionNotifyChecked ? '' : ' style="display:none;"' ?>>
    <div class="mb-2 text-muted fw-semibold">Παραλήπτες:</div>

    <!-- All -->
    <div class="form-check mb-1">
        <input class="form-check-input" type="radio" name="notify_target"
               id="targetAll" value="all" checked
               onchange="toggleTargetGroups()">
        <label class="form-check-label" for="targetAll">
            <i class="bi bi-people me-1 text-primary"></i>Όλοι οι ενεργοί χρήστες
        </label>
    </div>

    <!-- By Role -->
    <div class="form-check mb-1">
        <input class="form-check-input" type="radio" name="notify_target"
               id="targetRoles" value="roles"
               onchange="toggleTargetGroups()">
        <label class="form-check-label" for="targetRoles">
            <i class="bi bi-shield-check me-1 text-success"></i>Ανά Ρόλο
        </label>
    </div>
    <div id="rolesGroup" class="ps-3 mb-2 d-none">
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SHIFT_LEADER" id="roleShiftLeader">
            <label class="form-check-label" for="roleShiftLeader">Αρχηγοί Βάρδιας</label>
        </div>
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_roles[]" value="VOLUNTEER" id="roleVolunteer">
            <label class="form-check-label" for="roleVolunteer">Εθελοντές</label>
        </div>
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_roles[]" value="DEPARTMENT_ADMIN" id="roleDeptAdmin">
            <label class="form-check-label" for="roleDeptAdmin">Διαχ. Τμήματος</label>
        </div>
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SYSTEM_ADMIN" id="roleSysAdmin">
            <label class="form-check-label" for="roleSysAdmin">Διαχ. Συστήματος</label>
        </div>
    </div>

    <!-- By Volunteer Type -->
    <div class="form-check mb-1">
        <input class="form-check-input" type="radio" name="notify_target"
               id="targetVtypes" value="vtypes"
               onchange="toggleTargetGroups()">
        <label class="form-check-label" for="targetVtypes">
            <i class="bi bi-person-badge me-1 text-warning"></i>Ανά Τύπο Εθελοντή
        </label>
    </div>
    <div id="vtypesGroup" class="ps-3 d-none">
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="TRAINEE_RESCUER" id="vtypeTrainee">
            <label class="form-check-label" for="vtypeTrainee">Δόκιμοι Διασώστες</label>
        </div>
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="RESCUER" id="vtypeRescuer">
            <label class="form-check-label" for="vtypeRescuer">Εθελοντές Διασώστες</label>
        </div>
    </div>

    <?php if (!empty($missionNotifyPositions)): ?>
    <!-- By Position -->
    <div class="form-check mb-1">
        <input class="form-check-input" type="radio" name="notify_target"
               id="targetPositions" value="positions"
               onchange="toggleTargetGroups()">
        <label class="form-check-label" for="targetPositions">
            <i class="bi bi-briefcase me-1 text-danger"></i>Ανά Θέση
        </label>
    </div>
    <div id="positionsGroup" class="ps-3 d-none">
        <?php foreach ($missionNotifyPositions as $pos): ?>
        <div class="form-check form-check-sm">
            <input class="form-check-input" type="checkbox"
                   name="notify_positions[]" value="<?= (int)$pos['id'] ?>"
                   id="pos<?= (int)$pos['id'] ?>">
            <label class="form-check-label" for="pos<?= (int)$pos['id'] ?>">
                <?= h($pos['name']) ?>
            </label>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="text-muted mt-2 pt-2 border-top" style="font-size:.75rem;">
        <i class="bi bi-info-circle me-1"></i>Δεν περιλαμβάνονται ποτέ φιλοξενούμενοι
        συνεργαζόμενων ομάδων και επισκέπτες αποστολής.
    </div>
</div>
<script>
function toggleNotifyPanel(checked) {
    document.getElementById('notifyPanel').style.display = checked ? '' : 'none';
}
function toggleTargetGroups() {
    var target = document.querySelector('input[name="notify_target"]:checked').value;
    document.getElementById('rolesGroup').classList.toggle('d-none', target !== 'roles');
    document.getElementById('vtypesGroup').classList.toggle('d-none', target !== 'vtypes');
    var pg = document.getElementById('positionsGroup');
    if (pg) pg.classList.toggle('d-none', target !== 'positions');
}
</script>
