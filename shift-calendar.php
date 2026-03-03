<?php
/**
 * VolunteerOps - Ημερολόγιο Βάρδιων
 * Interactive FullCalendar view of all shifts with colour-coded fill rates.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Ημερολόγιο Βάρδιων';

// Data for filter dropdowns
$departments  = isAdmin()
    ? dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")
    : [];
$missionTypes = dbFetchAll("SELECT id, name, color FROM mission_types ORDER BY name");

// Default: volunteers see only their own shifts; admins see all
$defaultMine = isAdmin() ? '0' : '1';

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-calendar3 me-2"></i>Ημερολόγιο Βάρδιων
    </h1>
    <a href="shifts.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list-ul me-1"></i>Λίστα Βάρδιων
    </a>
</div>

<?php displayFlash(); ?>

<!-- ── Filter toolbar ──────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">

            <?php if (isAdmin()): ?>
            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">Τμήμα</label>
                <select id="filterDept" class="form-select form-select-sm" style="min-width:160px;">
                    <option value="">Όλα τα τμήματα</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-auto">
                <label class="form-label mb-0 small text-muted">Τύπος Αποστολής</label>
                <select id="filterType" class="form-select form-select-sm" style="min-width:180px;">
                    <option value="">Όλοι οι τύποι</option>
                    <?php foreach ($missionTypes as $mt): ?>
                        <option value="<?= $mt['id'] ?>"><?= h($mt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-auto d-flex align-items-end">
                <div class="form-check form-switch mb-0 mt-3">
                    <input class="form-check-input" type="checkbox" id="filterMine"
                           <?= $defaultMine === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="filterMine">
                        Μόνο οι βάρδιές μου
                    </label>
                </div>
            </div>

            <div class="col-auto d-flex align-items-end gap-2">
                <button id="btnRefresh" class="btn btn-sm btn-outline-primary mt-3">
                    <i class="bi bi-arrow-clockwise me-1"></i>Ανανέωση
                </button>
                <button id="btnExportIcs" class="btn btn-sm btn-outline-success mt-3" title="Εξαγωγή βαρδιών ως .ics (Google Calendar, Apple Calendar, Outlook)">
                    <i class="bi bi-calendar-plus me-1"></i>Εξαγωγή .ics
                </button>
            </div>

        </div>
    </div>
</div>

<!-- ── Legend ─────────────────────────────────────────────────────────────────-->
<div class="d-flex flex-wrap gap-3 mb-3 small">
    <span><span class="badge" style="background:#146c43;">●</span> Πλήρης (&ge;80%)</span>
    <span><span class="badge" style="background:#cc6c0a;">●</span> Μέτρια (50–79%)</span>
    <span><span class="badge" style="background:#b02a37;">●</span> Χαμηλή (&lt;50%)</span>
    <span><span class="badge" style="background:#495057;">●</span> Παρελθόν</span>
    <span><span class="badge" style="background:#6c757d;">●</span> Ακυρωμένη</span>
</div>

<!-- ── Calendar ───────────────────────────────────────────────────────────────-->
<div class="card">
    <div class="card-body p-2 p-md-3">
        <div id="shiftCalendar"></div>
    </div>
</div>

<!-- ── FullCalendar v6 (CDN global bundle — includes all locales & plugins) ───-->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<style>
/* Make events look a little tighter and not overflow */
#shiftCalendar { min-height: 620px; }
.fc .fc-event {
    cursor: pointer;
    font-size: 0.78rem;
    padding: 1px 3px;
    border-width: 2px;
}
.fc .fc-toolbar-title { font-size: 1.1rem; font-weight: 600; }
/* Compact month rows on small screens */
@media (max-width: 576px) {
    .fc .fc-toolbar { flex-direction: column; gap: 0.4rem; }
    .fc .fc-toolbar-title { font-size: 0.95rem; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Build API URL from current filter values ──────────────────────────── */
    function apiUrl(info) {
        const params = new URLSearchParams({
            start : info.startStr,
            end   : info.endStr,
            mine  : document.getElementById('filterMine').checked ? '1' : '0',
        });
        const dept = document.getElementById('filterDept');
        if (dept && dept.value) params.set('department_id', dept.value);
        const type = document.getElementById('filterType');
        if (type && type.value) params.set('mission_type_id', type.value);
        return 'api-shifts-calendar.php?' + params.toString();
    }

    /* ── FullCalendar initialisation ──────────────────────────────────────── */
    const calEl = document.getElementById('shiftCalendar');
    const calendar = new FullCalendar.Calendar(calEl, {
        locale       : 'el',
        initialView  : 'dayGridMonth',
        height       : 'auto',
        firstDay     : 1, // Monday first
        nowIndicator : true,
        headerToolbar: {
            left  : 'prev,next today',
            center: 'title',
            right : 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        buttonText: {
            today    : 'Σήμερα',
            month    : 'Μήνας',
            week     : 'Εβδομάδα',
            day      : 'Ημέρα',
            list     : 'Λίστα',
        },

        /* Fetch events from API */
        events: function (info, successCallback, failureCallback) {
            fetch(apiUrl(info))
                .then(r => r.json())
                .then(data => successCallback(data))
                .catch(err => {
                    console.error('Calendar fetch error', err);
                    failureCallback(err);
                });
        },

        /* Navigate to shift page on click */
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            if (info.event.url) {
                window.location.href = info.event.url;
            }
        },

        /* Add Bootstrap tooltip with shift details */
        eventDidMount: function (info) {
            const ep = info.event.extendedProps;
            const fillStr = ep.max_volunteers > 0
                ? ep.approved_count + '/' + ep.max_volunteers + ' εθελοντές'
                : ep.approved_count + ' εθελοντές';
            const pendStr   = ep.pending_count > 0 ? ` (+${ep.pending_count} εκκρεμείς)` : '';
            const typeStr   = ep.type_name ? `<br><small class="text-muted">${ep.type_name}</small>` : '';
            const locStr    = ep.location ? `<br><i class="bi bi-geo-alt"></i> ${ep.location}` : '';
            const myBadge   = ep.my_status
                ? `<br><span class="badge bg-info text-dark">Η συμμετοχή μου: ${myStatusLabel(ep.my_status)}</span>`
                : '';

            // Google Calendar direct link for this single event
            const gcalStart = info.event.start
                ? info.event.start.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'')
                : '';
            const gcalEnd = info.event.end
                ? info.event.end.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'')
                : gcalStart;
            const gcalUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                + '&text='    + encodeURIComponent(ep.mission_title + ' — Βάρδια #' + ep.shift_id)
                + '&dates='  + gcalStart + '/' + gcalEnd
                + '&details=' + encodeURIComponent('Βάρδια εθελοντισμού - ' + ep.mission_title)
                + '&location=' + encodeURIComponent(ep.location || '');
            const gcalBtn = `<br><a href="${gcalUrl}" target="_blank" class="btn btn-sm btn-outline-danger mt-1 py-0 px-1" style="font-size:0.72rem;">`
                + `<img src="https://ssl.gstatic.com/calendar/images/dynamiclogo_2020q4/calendar_16_2x.png" height="12" class="me-1" alt="">Προσθήκη στο Google Calendar</a>`;

            const tipContent = `<strong>${ep.mission_title}</strong>${typeStr}`
                + `<br>${fillStr}${pendStr}${locStr}${myBadge}${gcalBtn}`;

            const el = info.el;
            el.setAttribute('data-bs-toggle', 'popover');
            el.setAttribute('data-bs-trigger', 'hover focus');
            el.setAttribute('data-bs-html', 'true');
            el.setAttribute('data-bs-content', tipContent);
            el.setAttribute('data-bs-container', 'body');
            // Bootstrap 5 popover init
            new bootstrap.Popover(el, { sanitize: false, trigger: 'hover focus' });
        },

        /* Loading spinner */
        loading: function (isLoading) {
            document.getElementById('btnRefresh').disabled = isLoading;
            document.getElementById('btnRefresh').innerHTML = isLoading
                ? '<span class="spinner-border spinner-border-sm me-1"></span>Φόρτωση…'
                : '<i class="bi bi-arrow-clockwise me-1"></i>Ανανέωση';
        },
    });

    calendar.render();

    /* ── Refetch when any filter changes ────────────────────────────────────── */
    ['filterDept', 'filterType', 'filterMine'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('change', function () { calendar.refetchEvents(); });
        }
    });

    document.getElementById('btnRefresh').addEventListener('click', function () {
        calendar.refetchEvents();
    });

    /* ── ICS export ─────────────────────────────────────────────────────────── */
    document.getElementById('btnExportIcs').addEventListener('click', function () {
        const params = new URLSearchParams({ mine: document.getElementById('filterMine').checked ? '1' : '0' });
        const dept = document.getElementById('filterDept');
        if (dept && dept.value) params.set('department_id', dept.value);
        const type = document.getElementById('filterType');
        if (type && type.value) params.set('mission_type_id', type.value);
        window.location.href = 'api-shifts-calendar-ics.php?' + params.toString();
    });

    /* ── Helper: my_status Greek label ─────────────────────────────────────── */
    function myStatusLabel(status) {
        const labels = {
            'PENDING'             : 'Εκκρεμεί',
            'APPROVED'            : 'Εγκεκριμένη',
            'REJECTED'            : 'Απορρίφθηκε',
            'CANCELED_BY_USER'    : 'Ακυρώθηκε από εσάς',
            'CANCELED_BY_ADMIN'   : 'Ακυρώθηκε από διαχ.',
        };
        return labels[status] || status;
    }

});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
