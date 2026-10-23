/**
 * Trail display model - what the «Πορεία Ομάδων» map draws from a person's
 * stored fixes (v3.404.0).
 *
 * Shared by war-room.php (the live Action Room) and mission-archive.php (the
 * read-only archive), which draw the same trail from the same endpoint
 * (mission-track.php) and must not read it differently. Pure functions, no
 * DOM and no Leaflet, so tests/js/trail-display.test.js can run them in Node.
 * Loaded as a plain <script src>, like war-room-utils.js.
 *
 * Two things the raw polyline got wrong:
 *
 *  1. A person who stands still leaves a ball of yarn - one dot per fix, on top
 *     of each other, with a line scribbled through them. Twenty minutes at a
 *     20 s cadence is sixty dots. trailFindStops() folds such a run into one
 *     STOP: a point at the accuracy-weighted centre, with how long it lasted.
 *
 *  2. A solid line is a claim that the person walked it. Across a stretch where
 *     no fix arrived (a tunnel, a gorge, a dead battery, a phone that lost its
 *     signal) the polyline drew a confident straight line through ground nobody
 *     saw. trailDisplayModel() cuts the line there and hands back the missing
 *     stretch as a GAP, to be drawn dashed.
 *
 * Neither changes a stored fix. Both only change how a trail that is already
 * on screen is drawn, and the page can switch the stop folding off to see
 * every fix again.
 */

// A stop is a run of fixes that stay inside this radius of their own centre...
const TRAIL_STOP_RADIUS_M = 20;
// ...for at least this long, and over at least this many fixes. Three fixes,
// not two: a stop is a claim about a stretch of time, and two samples are not
// a stretch (at the default 3-minute cadence three fixes are already 6 min).
const TRAIL_STOP_MIN_SECONDS = 180;
const TRAIL_STOP_MIN_POINTS = 3;

function trailMetersBetween(a, b) {
    const R = 6371000, rad = Math.PI / 180;
    const dLat = (b.lat - a.lat) * rad, dLng = (b.lng - a.lng) * rad;
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(s)));
}

/**
 * A fix with an accuracy counts by 1/accuracy^2, floored at 3 m like the
 * server's own filter (gpsFilterStep), so a phone claiming 0.5 m cannot
 * outvote the rest. No accuracy at all is read as a middling 10 m rather than
 * as perfect or as useless.
 */
function trailPointWeight(p) {
    const acc = (p.acc === null || p.acc === undefined) ? 10 : Math.max(Number(p.acc), 3);
    return 1 / (acc * acc);
}

/**
 * Only a trail that was recorded continuously can have a "gap". It means an
 * expected fix that never came, and that is only expected from the automatic
 * stream. A trail of manual taps (the default view, automatic fixes off) is
 * sparse by nature - minutes between two taps is how it is made - and would
 * be dashed from end to end.
 */
function trailRecordsContinuously(points) {
    return points.some(p => p.source === 'auto');
}

/**
 * The silence that counts as a gap FOR THIS TRAIL: the app's configured limit
 * (mission-track.php), raised to three times the trail's own typical interval
 * between fixes when that is longer.
 *
 * The configured limit assumes the configured cadence. A trail recorded at a
 * slower rhythm - the cadence was changed halfway through the mission, an
 * older phone build, a battery saver, a vehicle that the speed gate records
 * once a minute - would otherwise be dashed from end to end, which says
 * nothing: a gap is a silence that is unusual for that person, not one that
 * is longer than a setting. The median interval ignores the gaps themselves.
 * Computed from the person's WHOLE trail, not from the part the replay
 * scrubber is showing, so the dashes do not come and go while it plays.
 * Fewer than five intervals say nothing about a rhythm; the limit stands.
 */
function trailEffectiveGapSeconds(allPoints, configuredSeconds) {
    if (!(configuredSeconds > 0)) return 0;
    const intervals = [];
    for (let i = 1; i < allPoints.length; i++) {
        const d = allPoints[i].ts - allPoints[i - 1].ts;
        if (d > 0) intervals.push(d);
    }
    if (intervals.length < 5) return configuredSeconds;
    intervals.sort((a, b) => a - b);
    const median = intervals[Math.floor((intervals.length - 1) / 2)];
    return Math.max(configuredSeconds, 3 * median);
}

/**
 * The stops in a trail's fixes (oldest first, as mission-track.php sends
 * them): [{from, to, lat, lng, startTs, endTs, count, kept}], from/to being
 * indexes of the first and last fix of the run, count the fixes folded into
 * it and kept the indexes of fixes inside the run that were NOT folded in.
 *
 * Greedy and forward-only: from each fix, take on the following ones while
 * each lies inside the radius of the centre of what has been taken so far.
 * The run is a stop when it is long enough, otherwise the scan moves on by one
 * fix. A run never crosses a gap (consecutive fixes more than gapSeconds apart
 * - the time between them was not seen, so it cannot be counted as standing).
 *
 * A fix the caller wants to keep as a mark of its own (opts.keep - the pages
 * pass a heart-rate alarm) is never folded in and does not count towards the
 * stop or move its centre, but it does not end the stop either: somebody who
 * stood for twenty minutes with a racing pulse in the middle of them stood for
 * twenty minutes. It is listed in `kept` and the model draws it as its own dot.
 * (A kept fix that lies outside the radius is a real excursion and ends the
 * run like any other.) A run starts and ends on a folded fix.
 */
function trailFindStops(points, opts) {
    const o = Object.assign({
        radiusM: TRAIL_STOP_RADIUS_M, minSeconds: TRAIL_STOP_MIN_SECONDS,
        minPoints: TRAIL_STOP_MIN_POINTS, gapSeconds: 0, keep: null,
    }, opts || {});
    const keeps = p => !!(o.keep && o.keep(p));
    const stops = [];
    let i = 0;
    while (i < points.length) {
        if (keeps(points[i])) { i++; continue; }
        let sw = 0, sLat = 0, sLng = 0, j = i, folded = 0, kept = [];
        while (j < points.length) {
            const p = points[j];
            if (j > i) {
                if (o.gapSeconds > 0 && p.ts - points[j - 1].ts > o.gapSeconds) break;
                if (trailMetersBetween({lat: sLat / sw, lng: sLng / sw}, p) > o.radiusM) break;
            }
            if (keeps(p)) {
                kept.push(j);
            } else {
                const w = trailPointWeight(p);
                sw += w; sLat += w * p.lat; sLng += w * p.lng; folded++;
            }
            j++;
        }
        // Trailing kept fixes belong after the stop, not inside it.
        while (kept.length && kept[kept.length - 1] === j - 1) { kept.pop(); j--; }
        if (folded >= o.minPoints && points[j - 1].ts - points[i].ts >= o.minSeconds) {
            stops.push({
                from: i, to: j - 1, lat: sLat / sw, lng: sLng / sw,
                startTs: points[i].ts, endTs: points[j - 1].ts, count: folded, kept,
            });
            i = j;
        } else {
            i++;
        }
    }
    return stops;
}

/**
 * The badge that stands for a stop on the map: a small pill in the team's
 * colour saying how long the person stayed. Returned as the HTML of a Leaflet
 * divIcon of size [0, 0] - the pill centres itself on the stop's point, or, for
 * a stop that is still going (the person's pin sits right above that point),
 * hangs just below it. Both pages build it here so they cannot drift apart.
 */
function trailStopBadgeHtml(color, label, ongoing) {
    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    return `<span style="position:absolute;left:0;top:0;transform:translate(-50%,${ongoing ? '6px' : '-50%'});display:inline-flex;align-items:center;gap:3px;background:#fff;color:#1f2937;border:2px solid ${esc(color)};padding:0 6px;border-radius:10px;font-weight:700;font-size:.72rem;line-height:1.35;white-space:nowrap;box-shadow:0 1px 3px #0006;"><i class="bi bi-pause-fill"></i>${esc(label)}</span>`;
}

/** Whole hours and minutes of a duration; never under one minute. */
function trailDurationParts(seconds) {
    const mins = Math.max(1, Math.round(seconds / 60));
    return {h: Math.floor(mins / 60), m: mins % 60};
}

/**
 * Everything a page needs to draw one person's trail.
 *
 *   points  the person's fixes, oldest first, each {lat, lng, ts, source,
 *           acc, hr_zone, ...} (mission-track.php), already cut to whatever
 *           instant the replay scrubber is at.
 *   opts    {collapseStops: bool (default true),
 *            gapSeconds: the longest silence still drawn as a continuous
 *                        line (mission-track.php sends the app's own figure);
 *                        absent or 0 = no gap is ever cut,
 *            keep: fn(point) -> true for a fix that must stay a dot of its
 *                  own (the pages pass the heart-rate alarm test)}
 *
 * Returns
 *   items   what to mark, in order: {type:'point', index, point, lat, lng,
 *           startTs, endTs, firstPoint, lastPoint} for a lone fix (with
 *           inStop:true when it is a kept fix lying inside a stop), or
 *           {type:'stop', stop, lat, lng, startTs, endTs, firstPoint,
 *           lastPoint} for a folded run (lat/lng = its centre);
 *   solid   the stretches to draw as a normal line, each a list of [lat,lng]
 *           (a stretch of one vertex has nothing to draw and is left out);
 *   gaps    the silences to draw dashed: {from:[lat,lng], to:[lat,lng],
 *           seconds, fromPoint, toPoint};
 *   lastIndex  index of the newest fix - where the person is, which is drawn
 *           as the pin even when it sits inside a stop that is still going.
 */
function trailDisplayModel(points, opts) {
    const o = opts || {};
    const gapSeconds = (o.gapSeconds > 0 && trailRecordsContinuously(points)) ? o.gapSeconds : 0;
    const stops = o.collapseStops === false ? [] : trailFindStops(points, {gapSeconds, keep: o.keep});

    const items = [];
    let k = 0, s = 0;
    while (k < points.length) {
        if (s < stops.length && stops[s].from === k) {
            const stop = stops[s++];
            items.push({
                type: 'stop', stop, lat: stop.lat, lng: stop.lng,
                startTs: stop.startTs, endTs: stop.endTs,
                firstPoint: points[stop.from], lastPoint: points[stop.to],
            });
            // A fix kept as a mark of its own inside the stop (a heart-rate
            // alarm) is still drawn, right after the stop's badge.
            stop.kept.forEach(index => {
                const p = points[index];
                items.push({
                    type: 'point', index, point: p, lat: p.lat, lng: p.lng,
                    startTs: p.ts, endTs: p.ts, firstPoint: p, lastPoint: p, inStop: true,
                });
            });
            k = stop.to + 1;
        } else {
            const p = points[k];
            items.push({
                type: 'point', index: k, point: p, lat: p.lat, lng: p.lng,
                startTs: p.ts, endTs: p.ts, firstPoint: p, lastPoint: p,
            });
            k++;
        }
    }

    // The line goes through what is drawn on the way; a fix kept inside a stop
    // sits on top of the stop's own vertex and is not a step along the path
    // (and its time lies inside the stop's, so it must not be judged as one).
    const path = items.filter(it => !it.inStop);
    const solid = [], gaps = [];
    let run = [];
    path.forEach((it, n) => {
        const before = path[n - 1];
        if (n > 0 && gapSeconds > 0 && it.startTs - before.endTs > gapSeconds) {
            solid.push(run);
            gaps.push({
                from: [before.lat, before.lng], to: [it.lat, it.lng],
                seconds: it.startTs - before.endTs, fromPoint: before.lastPoint, toPoint: it.firstPoint,
            });
            run = [];
        }
        run.push([it.lat, it.lng]);
    });
    if (run.length) solid.push(run);

    return {items, solid: solid.filter(r => r.length > 1), gaps, lastIndex: points.length - 1};
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        TRAIL_STOP_RADIUS_M, TRAIL_STOP_MIN_SECONDS, TRAIL_STOP_MIN_POINTS,
        trailMetersBetween, trailPointWeight, trailRecordsContinuously,
        trailEffectiveGapSeconds, trailFindStops, trailDurationParts, trailStopBadgeHtml, trailDisplayModel,
    };
}
