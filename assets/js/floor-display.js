/**
 * Barometer floor display model (v3.426.0) - how the Action Room writes down
 * the floor a person is on.
 *
 * The server (includes/functions-baro.php) sends, per person who has a ground
 * level and a reading:
 *   {m: metres above the ground level, floor: the nearest floor (0 ground,
 *    negative a basement), conf: 'ok' | 'drift', at: 'H:i' of the reading,
 *    stale: true when the reading is old}
 * and this file turns that into the same chip everywhere it is shown: the
 * roster row, the console's member row and the map pin's popup (never the map
 * itself - the live map face gets no new numbers).
 *
 * Three states, said in words as well as colour:
 *   ok     "Floor +2 (+6 m)"                       - a reading, a ground level
 *   drift  the same with a warning                 - the ground level is old and no
 *          phone outside is correcting the weather; the metres may be off by several
 *   stale  "last known: Floor +2 (+6 m), 14:32"    - no recent reading (a phone in a
 *          collapse is silent); greyed so it is not read as the current floor
 *
 * A floor is always "about, give or take one" - smoke, doors and ventilation
 * move the pressure - so the metres are always printed next to it.
 *
 * Pure functions, no DOM: tests/js/floor-display.test.js runs them in Node. The
 * translator is passed in (the page's t()), so the file carries no strings.
 * Loaded as a plain <script src>, like war-room-utils.js.
 */

/** +2, -1, 0 */
function floorSigned(n) {
    const v = Math.round(Number(n) || 0);
    return v > 0 ? '+' + v : (v < 0 ? '-' + Math.abs(v) : '0');
}

/** "2nd floor" / "Ground floor" / "Basement -1" - the floor in words. */
function floorLabel(floor, tr) {
    const n = Math.round(Number(floor) || 0);
    if (n === 0) return tr('floor.label_ground', {});
    if (n > 0) return tr('floor.label_up', {n: n});
    return tr('floor.label_down', {n: Math.abs(n)});
}

/** "+6 m" */
function floorMetresText(m, tr) {
    return tr('floor.metres', {m: floorSigned(m)});
}

/** A time the server formatted as H:i; anything else is dropped rather than put into markup. */
function floorSafeTime(at) {
    return /^\d{1,2}:\d{2}$/.test(String(at || '')) ? String(at) : '';
}

function floorState(f) {
    if (f.stale) return 'stale';
    return f.conf === 'drift' ? 'drift' : 'ok';
}

/**
 * The chip for one person: {cls, html, title}, or null when there is no floor
 * to show. The caller puts cls on a span, html inside it and title on it, so
 * the roster (which updates a slot that is already in the page) and the
 * console (which builds a string) read it the same way.
 */
function floorChipModel(f, tr) {
    if (!f || typeof f !== 'object' || f.floor === undefined || f.floor === null) return null;
    const state = floorState(f);
    const label = floorLabel(f.floor, tr);
    const metres = floorMetresText(f.m, tr);
    const time = floorSafeTime(f.at);
    const title = state === 'stale' ? tr('floor.tip_stale', {label: label, m: metres, time: time})
        : (tr('floor.tip', {label: label, m: metres, time: time}) + (state === 'drift' ? ' ' + tr('floor.tip_drift', {}) : ''));
    const html = '<i class="bi bi-building" aria-hidden="true"></i> <b>' + floorSigned(f.floor) + '</b>'
        + ' <small>' + metres + '</small>'
        + (state === 'stale' && time ? ' <small>' + time + '</small>' : '')
        + (state === 'drift' ? ' <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>' : '');
    return {cls: 'floor-chip floor-' + state, html: html, title: title};
}

/** The chip as one string, for markup that is built as text (the console's member row). */
function floorChipHtml(f, tr, escape) {
    const chip = floorChipModel(f, tr);
    if (!chip) return '';
    return '<span class="' + chip.cls + '" title="' + escape(chip.title) + '">' + chip.html + '</span>';
}

/**
 * The line in a map pin's popup: floor and metres, and what is uncertain about
 * them. '' when there is no floor. $escape is the page's escapeHtml().
 */
function floorPopupLineHtml(f, tr, escape) {
    if (!f || typeof f !== 'object' || f.floor === undefined || f.floor === null) return '';
    const state = floorState(f);
    const label = floorLabel(f.floor, tr);
    const metres = floorMetresText(f.m, tr);
    const time = floorSafeTime(f.at);
    let text;
    if (state === 'stale') text = tr('floor.popup_stale', {label: label, m: metres, time: time});
    else text = tr('floor.popup', {label: label, m: metres}) + (state === 'drift' ? ' ' + tr('floor.popup_drift', {}) : '');
    const tone = state === 'ok' ? 'text-body' : (state === 'drift' ? 'text-warning' : 'text-muted');
    return '<br><span class="small ' + tone + '"><i class="bi bi-building me-1" aria-hidden="true"></i>' + escape(text) + '</span>';
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        floorSigned, floorLabel, floorMetresText, floorSafeTime, floorState,
        floorChipModel, floorChipHtml, floorPopupLineHtml,
    };
}
