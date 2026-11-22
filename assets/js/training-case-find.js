/**
 * Finding the victim (v3.506.0): the phone's side of «Είσαι κοντά» / «Βρέθηκε». Pure rules, exported for Node
 * (tests/js/training-case-find.test.js) and mirrored by includes/functions-training-case-runs.php, which re-checks every
 * report itself (trainingCaseProximity()): what this page decides is a courtesy, the server's answer is the record.
 *
 * Within CLOSE_M of the spot the phone says «κοντά» (never a distance or a direction); within FOUND_M it says «βρέθηκε».
 * A fix's reported accuracy is credited to the volunteer, capped at ACCURACY_CREDIT_M so one bad fix cannot find the
 * victim from afar. The spot of an area order is hidden: nothing here ever draws it.
 */
(function (root) {
    'use strict';

    const FOUND_M = 10;
    const CLOSE_M = 50;
    const ACCURACY_CREDIT_M = 15;

    function num(v) { return typeof v === 'number' && isFinite(v) ? v : (typeof v === 'string' && v.trim() !== '' && isFinite(Number(v)) ? Number(v) : null); }

    /** Great-circle distance in metres between two {lat, lng} points (the same sphere as gpsDistanceMeters() in PHP). */
    function distanceM(a, b) {
        const R = 6371000;
        const rad = Math.PI / 180;
        const dLat = (b.lat - a.lat) * rad;
        const dLng = (b.lng - a.lng) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    /** 'found', 'close' or 'far' for a distance in metres and the fix's accuracy in metres (missing or negative credits nothing). */
    function classify(distance, accuracy) {
        const acc = num(accuracy);
        const credit = Math.min(Math.max(acc === null ? 0 : acc, 0), ACCURACY_CREDIT_M);
        const effective = distance - credit;
        if (effective <= FOUND_M) return 'found';
        return effective <= CLOSE_M ? 'close' : 'far';
    }

    /** The cards worth watching in a poll's dispatch list: mine to play, with a spot, not found yet, not finished. */
    function watchList(dispatches) {
        const out = [];
        (Array.isArray(dispatches) ? dispatches : []).forEach(d => {
            const c = d && d.case;
            if (!c || !c.can_play || c.found || c.state === 'done' || !c.spot) return;
            if (num(c.spot.lat) === null || num(c.spot.lng) === null) return;
            out.push({dispatch_id: d.id, placement_id: c.placement_id, spot: c.spot, briefing: c.briefing || ''});
        });
        return out;
    }

    /**
     * Remembers what has been announced per placement, so a GPS that jitters around 50 m does not buzz again and again and a
     * later bad fix never un-finds. fix = {lat, lng, acc}.
     */
    function createTracker(handlers) {
        const closeSent = {};
        const foundSent = {};
        function report(e) { if (typeof console !== 'undefined' && console.error) console.error('[training-cases] find handler failed', e); }
        function check(cards, fix) {
            if (!Array.isArray(cards) || !fix) return;
            const lat = num(fix.lat);
            const lng = num(fix.lng);
            if (lat === null || lng === null) return;
            cards.forEach(card => {
                if (!card || !card.spot || foundSent[card.placement_id]) return;
                const sLat = num(card.spot.lat);
                const sLng = num(card.spot.lng);
                if (sLat === null || sLng === null) return;
                const d = distanceM({lat: lat, lng: lng}, {lat: sLat, lng: sLng});
                const where = classify(d, fix.acc);
                // A handler that throws (a full storage while queuing the report, say) must not abort the other cards.
                if (where === 'found') {
                    foundSent[card.placement_id] = true;
                    try {
                        if (handlers && handlers.onFound) handlers.onFound(card, {lat: lat, lng: lng, acc: num(fix.acc)}, d);
                    } catch (e) {
                        delete foundSent[card.placement_id]; // nothing was recorded: the next fix tries again
                        report(e);
                    }
                } else if (where === 'close' && !closeSent[card.placement_id]) {
                    closeSent[card.placement_id] = true;
                    try {
                        if (handlers && handlers.onClose) handlers.onClose(card, d);
                    } catch (e) {
                        report(e);
                    }
                }
            });
        }
        /** Takes back what was announced for one placement (the server refused the find): it is watched afresh. */
        function forget(placementId) {
            delete closeSent[placementId];
            delete foundSent[placementId];
        }
        return {check: check, forget: forget};
    }

    /**
     * What the AR screen may show for an order's case (v3.507.0). The one place that decides it, so the hidden spot of an area
     * order never reaches the drawing code. 'marker': the victim's place is shown (a point order), drawn at the order's own
     * place, and `found` says the team has found it or arrived; 'close': a hidden victim the volunteer is near, with no spot,
     * distance or direction; 'none': draw nothing about it. card is the order's `case` object from the poll, fix {lat, lng, acc};
     * foundLocally: this phone has already reported the find (the poll may not show it yet), which ends the hidden cue.
     */
    function victimView(card, fix, foundLocally) {
        const none = {mode: 'none'};
        if (!card || !card.can_play || card.state === 'done' || !card.spot) return none;
        const sLat = num(card.spot.lat);
        const sLng = num(card.spot.lng);
        if (sLat === null || sLng === null) return none;
        if (!card.hidden) return {mode: 'marker', found: !!(card.found || card.available || foundLocally)};
        if (card.found || foundLocally) return none;
        const lat = fix ? num(fix.lat) : null;
        const lng = fix ? num(fix.lng) : null;
        if (lat === null || lng === null) return none;
        return classify(distanceM({lat: lat, lng: lng}, {lat: sLat, lng: sLng}), fix.acc) === 'far' ? none : {mode: 'close'};
    }

    /** Whether the AR shows «Άνοιγμα περιστατικού»: playable, open to my team or found on this phone, and not finished. */
    function canOpen(card, foundLocally) {
        return !!(card && card.can_play && card.state !== 'done' && (card.available || foundLocally));
    }

    const api = {FOUND_M, CLOSE_M, ACCURACY_CREDIT_M, distanceM, classify, watchList, createTracker, victimView, canOpen};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoCaseFind = api;
})(typeof window !== 'undefined' ? window : globalThis);
