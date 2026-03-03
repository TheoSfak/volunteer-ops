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
$missionTypes = dbFetchAll("SELECT id, name FROM mission_types ORDER BY name");

// Default: volunteers see only their own shifts; admins see all
$defaultMine = isAdmin() ? '0' : '1';

include __DIR__ . '/includes/header.php';
?>

<!-- ── FullCalendar v6 (CDN global bundle) ────────────────────────────────── -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" />
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<style>
/* ══════════════════════════════════════════════
   HERO HEADER
══════════════════════════════════════════════ */
.cal-hero {
    background: linear-gradient(135deg, #1a1f5e 0%, #2d3561 50%, #1e3a5f 100%);
    border-radius: 16px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
}
.cal-hero::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(255,255,255,0.05);
    pointer-events: none;
}
.cal-hero::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -40px;
    width: 160px; height: 160px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}
.cal-hero-title {
    font-size: 1.65rem;
    font-weight: 700;
    color: #fff;
    margin: 0 0 0.25rem;
    letter-spacing: -0.02em;
}
.cal-hero-sub {
    font-size: 0.875rem;
    color: rgba(255,255,255,0.65);
    margin: 0;
}

/* ══════════════════════════════════════════════
   FILTER CARD
══════════════════════════════════════════════ */
.filter-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 14px;
    padding: 1rem 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.filter-card .form-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #6c757d;
    margin-bottom: 4px;
}
.filter-card .form-select {
    border-radius: 8px;
    font-size: 0.85rem;
    border-color: #dee2e6;
}
.filter-card .form-select:focus {
    border-color: #8BA3D9;
    box-shadow: 0 0 0 3px rgba(45,53,97,0.08);
}
.filter-divider {
    width: 1px;
    height: 36px;
    background: #e9ecef;
    align-self: flex-end;
    margin-bottom: 2px;
}

/* ══════════════════════════════════════════════
   LEGEND PILLS
══════════════════════════════════════════════ */
.legend-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
    align-items: center;
}
.legend-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px 4px 8px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 500;
    color: #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
.legend-pill .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    flex-shrink: 0;
}
.lp-green   { background: #146c43; }
.lp-orange  { background: #cc6c0a; }
.lp-red     { background: #b02a37; }
.lp-dgrey   { background: #495057; }
.lp-grey    { background: #6c757d; }

/* ══════════════════════════════════════════════
   CALENDAR CARD
══════════════════════════════════════════════ */
.cal-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.06);
    overflow: hidden;
    padding: 1.25rem 1.5rem 1.5rem;
}

/* ── FullCalendar overrides ── */
.fc { font-family: inherit; }
.fc .fc-toolbar { gap: 0.5rem; flex-wrap: wrap; }
.fc .fc-toolbar-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1a1f5e;
    letter-spacing: -0.01em;
}
.fc .fc-button {
    background: #f0f2f5 !important;
    border: 1px solid #dee2e6 !important;
    color: #495057 !important;
    border-radius: 8px !important;
    font-size: 0.8rem !important;
    font-weight: 500 !important;
    padding: 0.35rem 0.75rem !important;
    transition: all 0.15s !important;
    text-transform: none !important;
    box-shadow: none !important;
}
.fc .fc-button:hover { background: #e2e6ea !important; color: #1a1f5e !important; }
.fc .fc-button-active,
.fc .fc-button-primary:not(:disabled).fc-button-active {
    background: #2d3561 !important;
    border-color: #2d3561 !important;
    color: #fff !important;
}
.fc .fc-today-button { background: #2d3561 !important; border-color: #2d3561 !important; color: #fff !important; }
.fc .fc-today-button:disabled { background: #8b95c9 !important; border-color: #8b95c9 !important; opacity: 1 !important; }
.fc .fc-col-header-cell {
    background: #f8f9fb;
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6c757d;
    padding: 0.5rem 0;
}
.fc .fc-day-today { background: rgba(45,53,97,0.04) !important; }
.fc .fc-day-today .fc-daygrid-day-number {
    background: #2d3561;
    color: #fff;
    border-radius: 50%;
    width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 600;
}
.fc .fc-daygrid-day-number { font-size: 0.8rem; color: #495057; padding: 6px 8px; }
.fc .fc-event {
    border-radius: 6px !important;
    border: none !important;
    cursor: pointer;
    transition: transform 0.12s, box-shadow 0.12s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.18);
    margin: 1px 2px !important;
}
.fc .fc-event:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.28) !important;
    z-index: 10;
}
.fc .fc-event-main { padding: 2px 5px !important; }

/* Custom event inner */
.cal-event-inner { display: flex; flex-direction: column; gap: 1px; min-height: 26px; }
.cal-event-inner .ev-title {
    font-size: 0.72rem; font-weight: 600; color: #fff;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.3;
}
.cal-event-inner .ev-meta { display: flex; align-items: center; gap: 4px; }
.cal-event-inner .ev-count { font-size: 0.65rem; color: rgba(255,255,255,0.85); white-space: nowrap; }
.cal-event-inner .ev-bar-wrap {
    height: 3px; border-radius: 2px;
    background: rgba(255,255,255,0.25); overflow: hidden; flex: 1;
}
.cal-event-inner .ev-bar { height: 100%; border-radius: 2px; background: rgba(255,255,255,0.78); }

/* List view */
.fc .fc-list-event:hover td { background: #f0f3ff !important; }
.fc .fc-list-day-cushion {
    background: #f8f9fb !important;
    font-size: 0.8rem; font-weight: 600; color: #2d3561;
}

/* Scrollbar */
.fc-scroller::-webkit-scrollbar { width: 5px; height: 5px; }
.fc-scroller::-webkit-scrollbar-track { background: #f1f3f5; }
.fc-scroller::-webkit-scrollbar-thumb { background: #ced4da; border-radius: 4px; }

/* ── Popover overrides ── */
.cal-popover.popover {
    max-width: 285px;
    border: none;
    border-radius: 12px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    font-size: 0.82rem;
}
.cal-popover .popover-header {
    background: #2d3561; color: #fff;
    border-radius: 12px 12px 0 0;
    font-weight: 600; font-size: 0.83rem; border: none;
}
.cal-popover .popover-body { padding: 0.65rem 0.85rem; color: #343a40; }
.cal-popover .popover-arrow::before { border-bottom-color: #2d3561; }
.cal-popover .popover-arrow::after  { border-bottom-color: #2d3561; }
.cal-pop-row {
    display: flex; align-items: flex-start;
    gap: 6px; margin-bottom: 4px;
    font-size: 0.79rem; color: #495057;
}
.cal-pop-row i { width: 14px; flex-shrink: 0; color: #6c757d; margin-top: 1px; }
.cal-pop-fill-bar {
    height: 5px; border-radius: 3px;
    background: #e9ecef; margin: 4px 0 6px; overflow: hidden;
}
.cal-pop-fill-bar .fill-inner { height: 100%; border-radius: 3px; }
.cal-pop-gcal {
    display: flex; align-items: center; gap: 5px;
    margin-top: 8px; padding-top: 8px; border-top: 1px solid #f0f0f0;
    font-size: 0.76rem; color: #1a73e8; text-decoration: none; font-weight: 500;
}
.cal-pop-gcal:hover { color: #1558b0; }

/* Responsive */
@media (max-width: 576px) {
    .cal-hero { padding: 1.25rem; }
    .cal-hero-title { font-size: 1.3rem; }
    .cal-card { padding: 0.75rem; }
    .fc .fc-toolbar { flex-direction: column; align-items: flex-start; }
    .fc .fc-toolbar-chunk { display: flex; gap: 0.25rem; flex-wrap: wrap; }
}
</style>

<!-- ── Hero header ─────────────────────────────────────────────────────────── -->
<div class="cal-hero d-flex justify-content-between align-items-center">
    <div style="position:relative;z-index:1;">
        <p class="cal-hero-sub mb-1"><i class="bi bi-calendar3 me-1"></i>Ημερολόγιο</p>
        <h1 class="cal-hero-title">Βάρδιες Αποστολών</h1>
        <p class="cal-hero-sub">Εποπτεία και διαχείριση βαρδιών εθελοντισμού</p>
    </div>
    <div class="d-none d-md-flex align-items-center gap-2" style="position:relative;z-index:1;">
        <a href="shifts.php" class="btn btn-sm btn-light fw-semibold">
            <i class="bi bi-list-ul me-1"></i>Λίστα
        </a>
        <button id="btnExportIcs" class="btn btn-sm fw-semibold"
                style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);"
                title="Λήψη .ics — Google Calendar, Apple Calendar, Outlook">
            <i class="bi bi-calendar-plus me-1"></i>Εξαγωγή .ics
        </button>
    </div>
</div>

<?php displayFlash(); ?>

<!-- ── Filter card ─────────────────────────────────────────────────────────── -->
<div class="filter-card">
    <div class="d-flex flex-wrap align-items-end gap-3">

        <?php if (isAdmin()): ?>
        <div>
            <label class="form-label">Τμήμα</label>
            <select id="filterDept" class="form-select form-select-sm" style="min-width:155px;">
                <option value="">Όλα τα τμήματα</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-divider d-none d-md-block"></div>
        <?php endif; ?>

        <div>
            <label class="form-label">Τύπος Αποστολής</label>
            <select id="filterType" class="form-select form-select-sm" style="min-width:170px;">
                <option value="">Όλοι οι τύποι</option>
                <?php foreach ($missionTypes as $mt): ?>
                    <option value="<?= $mt['id'] ?>"><?= h($mt['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-divider d-none d-md-block"></div>

        <div class="d-flex align-items-center gap-2 pb-1">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="filterMine"
                       <?= $defaultMine === '1' ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="filterMine" style="font-size:0.83rem;">
                    Μόνο οι βάρδιές μου
                </label>
            </div>
        </div>

        <div class="ms-auto d-flex gap-2 align-items-end pb-1">
            <button id="btnExportIcsMobile" class="btn btn-sm btn-outline-success d-md-none"
                    title="Εξαγωγή .ics">
                <i class="bi bi-calendar-plus"></i>
            </button>
            <button id="btnRefresh" class="btn btn-sm fw-semibold"
                    style="background:#2d3561;color:#fff;border-radius:8px;border:none;">
                <i class="bi bi-arrow-clockwise me-1"></i>Ανανέωση
            </button>
        </div>
    </div>
</div>

<!-- ── Legend ─────────────────────────────────────────────────────────────── -->
<div class="legend-strip">
    <span style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6c757d;">Πληρότητα:</span>
    <span class="legend-pill lp-green"><span class="dot"></span>Πλήρης &ge;80%</span>
    <span class="legend-pill lp-orange"><span class="dot"></span>Μέτρια 50–79%</span>
    <span class="legend-pill lp-red"><span class="dot"></span>Χαμηλή &lt;50%</span>
    <span class="legend-pill lp-dgrey"><span class="dot"></span>Παρελθόν</span>
    <span class="legend-pill lp-grey"><span class="dot"></span>Ακυρωμένη</span>
</div>

<!-- ── Calendar ───────────────────────────────────────────────────────────── -->
<div class="cal-card">
    <div id="shiftCalendar"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Helpers ─────────────────────────────────────────────────────────── */
    function filterParams(info) {
        const p = new URLSearchParams({ mine: document.getElementById('filterMine').checked ? '1' : '0' });
        if (info) { p.set('start', info.startStr); p.set('end', info.endStr); }
        const dept = document.getElementById('filterDept');
        if (dept && dept.value) p.set('department_id', dept.value);
        const type = document.getElementById('filterType');
        if (type && type.value) p.set('mission_type_id', type.value);
        return p;
    }
    function icsExport() {
        window.location.href = 'api-shifts-calendar-ics.php?' + filterParams(null).toString();
    }
    function myStatusLabel(s) {
        return ({PENDING:'Εκκρεμεί', APPROVED:'Εγκεκριμένη', REJECTED:'Απορρίφθηκε',
                 CANCELED_BY_USER:'Ακυρώθηκε από εσάς', CANCELED_BY_ADMIN:'Ακυρώθηκε από διαχ.'})[s] || s;
    }

    /* ── Calendar init ───────────────────────────────────────────────────── */
    const calendar = new FullCalendar.Calendar(document.getElementById('shiftCalendar'), {
        locale        : 'el',
        initialView   : 'dayGridMonth',
        height        : 'auto',
        firstDay      : 1,
        nowIndicator  : true,
        eventMinHeight: 28,
        headerToolbar : {
            left  : 'prev,next today',
            center: 'title',
            right : 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
        },
        buttonText: { today:'Σήμερα', month:'Μήνας', week:'Εβδομάδα', day:'Ημέρα', list:'Λίστα' },

        /* Custom event HTML — fill bar + volunteer count */
        eventContent: function (arg) {
            const ep  = arg.event.extendedProps;
            const pct = Math.min(ep.fill_pct || 0, 100);
            const countStr = ep.max_volunteers > 0
                ? ep.approved_count + '/' + ep.max_volunteers
                : String(ep.approved_count);
            const urgentIcon = ep.is_urgent ? '<span style="color:#ffe066;margin-right:2px;">⚡</span>' : '';
            // Strip the 🔴 prefix already in title (we use ⚡ instead)
            const cleanTitle = arg.event.title.replace(/^🔴 /, '');
            return { html:
                '<div class="cal-event-inner">' +
                  '<div class="ev-title">' + urgentIcon + cleanTitle + '</div>' +
                  '<div class="ev-meta">' +
                    '<span class="ev-count"><i class="bi bi-people-fill" style="font-size:0.6rem;margin-right:2px;"></i>' + countStr + '</span>' +
                    '<div class="ev-bar-wrap"><div class="ev-bar" style="width:' + pct + '%"></div></div>' +
                  '</div>' +
                '</div>'
            };
        },

        /* Fetch events from API */
        events: function (info, ok, fail) {
            fetch('api-shifts-calendar.php?' + filterParams(info).toString())
                .then(r => r.json()).then(ok)
                .catch(e => { console.error('Calendar fetch error', e); fail(e); });
        },

        /* Click → navigate to shift */
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            if (info.event.url) window.location.href = info.event.url;
        },

        /* Styled popover on mount */
        eventDidMount: function (info) {
            const ep  = info.event.extendedProps;
            const pct = Math.min(ep.fill_pct || 0, 100);
            const barColor = ep.color || '#6c757d';

            const countStr = ep.max_volunteers > 0
                ? ep.approved_count + '/' + ep.max_volunteers + ' εθελοντές'
                : ep.approved_count + ' εθελοντές';
            const pendStr  = ep.pending_count > 0
                ? ' <span style="color:#cc6c0a;font-size:0.75rem;">(+' + ep.pending_count + ' εκκρεμείς)</span>' : '';
            const typeRow  = ep.type_name
                ? '<div class="cal-pop-row"><i class="bi bi-tag"></i>' + ep.type_name + '</div>' : '';
            const locRow   = ep.location
                ? '<div class="cal-pop-row"><i class="bi bi-geo-alt"></i>' + ep.location + '</div>' : '';
            const myRow    = ep.my_status
                ? '<div class="cal-pop-row"><i class="bi bi-person-check"></i>Συμμετοχή: <strong>' + myStatusLabel(ep.my_status) + '</strong></div>' : '';
            const notesRow = ep.notes
                ? '<div class="cal-pop-row"><i class="bi bi-chat-text"></i><em>' + ep.notes + '</em></div>' : '';

            // Google Calendar link
            const gs = info.event.start ? info.event.start.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'') : '';
            const ge = info.event.end   ? info.event.end.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'') : gs;
            const gcalUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                + '&text='     + encodeURIComponent(ep.mission_title + ' — Βάρδια #' + ep.shift_id)
                + '&dates='    + gs + '/' + ge
                + '&details='  + encodeURIComponent('Βάρδια εθελοντισμού - ' + ep.mission_title)
                + '&location=' + encodeURIComponent(ep.location || '');

            const body =
                '<div class="cal-pop-row"><i class="bi bi-people"></i>' + countStr + pendStr + '</div>' +
                '<div class="cal-pop-fill-bar"><div class="fill-inner" style="width:' + pct + '%;background:' + barColor + ';"></div></div>' +
                typeRow + locRow + myRow + notesRow +
                '<a href="' + gcalUrl + '" target="_blank" class="cal-pop-gcal">' +
                  '<svg width="14" height="14" viewBox="0 0 24 24" fill="#1a73e8"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg>' +
                  'Προσθήκη στο Google Calendar' +
                '</a>';

            new bootstrap.Popover(info.el, {
                title      : (ep.is_urgent ? '⚡ ' : '') + ep.mission_title,
                content    : body,
                html       : true,
                trigger    : 'hover focus',
                placement  : 'top',
                container  : 'body',
                sanitize   : false,
                customClass: 'cal-popover',
            });
        },

        /* Loading indicator */
        loading: function (busy) {
            const btn = document.getElementById('btnRefresh');
            btn.disabled = busy;
            btn.innerHTML = busy
                ? '<span class="spinner-border spinner-border-sm me-1"></span>Φόρτωση…'
                : '<i class="bi bi-arrow-clockwise me-1"></i>Ανανέωση';
        },
    });

    calendar.render();

    /* ── Filter change listeners ─────────────────────────────────────────── */
    ['filterDept','filterType','filterMine'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', function () { calendar.refetchEvents(); });
    });
    document.getElementById('btnRefresh').addEventListener('click', function () { calendar.refetchEvents(); });

    /* ── ICS export ──────────────────────────────────────────────────────── */
    document.getElementById('btnExportIcs').addEventListener('click', icsExport);
    const mob = document.getElementById('btnExportIcsMobile');
    if (mob) mob.addEventListener('click', icsExport);

});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
