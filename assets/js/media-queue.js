/**
 * Photos and videos that could not be sent (v3.423.0).
 *
 * The text queue in war-room.php keeps small JSON in localStorage, which holds
 * about 5 MB and throws on the first video. A photo or a clip therefore had no
 * queue at all: with no signal the upload failed, the progress window closed,
 * and the file was gone from the page. This module keeps the file itself in
 * IndexedDB (hundreds of MB, same API on Safari, Chrome and the Android
 * WebView) and leaves ONE small pointer in the text queue, {kind:'media',
 * mediaId}, so everything the queue already does - order, the cross-tab lock,
 * the banner, the failure notices - applies to media without a second copy of
 * it.
 *
 * Nothing here touches the page: war-room.php supplies the csrf token, the
 * XHR and the rendering. What IS here is the part that can be checked with
 * Node (tests/js/media-queue.test.js): the form a record is sent as, which
 * failures are worth retrying, which queued item goes next, and the iPhone
 * test the picker buttons depend on.
 */
(function (root) {
    'use strict';

    const DB_NAME = 'vo_media_queue';
    const DB_VERSION = 1;
    const STORE = 'media';

    // Replies that are neither JSON nor an outage (a PHP fatal, a proxy page)
    // are retried this many times in one page session, then given up on. An
    // outage, an expired login or a busy server are not counted: they end by
    // themselves, and giving up on a clip because the server was busy would
    // lose the one thing the queue exists to keep.
    const MAX_COUNTED_TRIES = 5;

    // iPadOS 13+ calls itself a Mac; a real Mac has no multi-touch screen.
    function isIosDevice(nav) {
        if (!nav) return false;
        if (/iP(hone|ad|od)/.test(nav.userAgent || '')) return true;
        return nav.platform === 'MacIntel' && (nav.maxTouchPoints || 0) > 1;
    }

    // The server accepts [A-Za-z0-9-]{8,64}. randomUUID() needs a secure
    // context, which the app always has, but the fallback costs nothing.
    function newMediaId(cryptoObj) {
        const c = cryptoObj || (typeof crypto !== 'undefined' ? crypto : null);
        if (c && typeof c.randomUUID === 'function') return c.randomUUID();
        const bytes = new Uint8Array(16);
        if (c && typeof c.getRandomValues === 'function') c.getRandomValues(bytes);
        else for (let i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
        return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
    }

    const EXTENSION_BY_TYPE = {
        'image/jpeg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp',
        'video/mp4': 'mp4', 'video/webm': 'webm', 'video/quicktime': 'mov', 'video/x-m4v': 'm4v',
    };

    // mission-photo.php decides the type from the extension AND the bytes, so
    // a blob that came back from IndexedDB with no usable name still needs one.
    function mediaFileName(file) {
        const name = file && file.name ? String(file.name) : '';
        if (/\.(jpe?g|png|gif|webp|mp4|webm|mov|m4v)$/i.test(name)) return name;
        const type = ((file && file.type) || '').split(';')[0].toLowerCase();
        const video = type.indexOf('video/') === 0;
        return 'field-' + (video ? 'video' : 'photo') + '.' + (EXTENSION_BY_TYPE[type] || (video ? 'mp4' : 'jpg'));
    }

    function mediaKind(file) {
        return file && /^video\//i.test(file.type || '') ? 'video' : 'photo';
    }

    // Everything one upload needs, in the shape IndexedDB stores. `fix` is the
    // page's {lat, lng, acc} or null; `capturedAt` is when the person took it
    // (not when the network came back), which the server keeps as the time.
    function makeSpec(o) {
        const fix = o.fix || null;
        return {
            id: newMediaId(),
            missionId: o.missionId,
            kind: mediaKind(o.file),
            file: o.file,
            fileName: mediaFileName(o.file),
            thumb: o.thumb || null,
            lat: fix ? fix.lat : null,
            lng: fix ? fix.lng : null,
            accuracy: fix && fix.acc !== undefined ? fix.acc : null,
            waypointId: o.waypointId === undefined || o.waypointId === null ? null : Number(o.waypointId),
            isPoi: !!o.isPoi,
            note: o.note || '',
            capturedAt: o.capturedAt || new Date().toISOString(),
        };
    }

    // The same fields on the first attempt and on every replay, so there is
    // one place that knows what mission-photo.php expects. client_uuid is the
    // server's duplicate guard: a reply lost on the way back leaves a file the
    // server already has, and the replay must not add it twice. A replay also
    // carries the capture time; a first attempt does not, because "now" is
    // already right for it.
    function buildFormData(spec, csrfToken, queued) {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('action', 'upload');
        data.append('mission_id', String(spec.missionId));
        data.append('client_uuid', spec.id);
        data.append('media', spec.file, spec.fileName);
        if (spec.thumb) data.append('thumb', spec.thumb, 'thumb.jpg');
        if (spec.lat !== null && spec.lat !== undefined && spec.lng !== null && spec.lng !== undefined) {
            data.append('lat', spec.lat);
            data.append('lng', spec.lng);
            if (spec.accuracy !== null && spec.accuracy !== undefined) data.append('accuracy', spec.accuracy);
        }
        if (spec.waypointId !== null && spec.waypointId !== undefined) data.append('route_waypoint_id', String(spec.waypointId));
        if (spec.isPoi) {
            data.append('is_poi', '1');
            data.append('note', spec.note || '');
        }
        if (queued) {
            data.append('reported_at', spec.capturedAt);
            data.append('queued', '1');
        }
        return data;
    }

    // What an upload that threw (postFormDataWithProgress rejects whenever the
    // server's answer was not JSON) means:
    //   network → no connection, or it stopped moving
    //   retry   → login expired (the XHR was redirected to the login page) or
    //             the server is busy/behind a proxy that is
    //   reject  → the server will never take this file (413: over its limit)
    //   count   → something else came back; try a few more times, then stop
    function classifyFailure(err) {
        if (!err) return 'count';
        if (err.wrConnection) return 'network';
        if (err.wrRedirected) return 'retry';
        const status = err.wrStatus || 0;
        if (status === 413) return 'reject';
        if (status === 502 || status === 503 || status === 504) return 'retry';
        return 'count';
    }

    // What is still waiting for this route point, read from the text queue.
    function queuedMediaFor(queue, waypointId) {
        const out = {photo: false, video: false, any: false};
        (queue || []).forEach(q => {
            if (q.kind !== 'media' || q.waypointId === null || q.waypointId === undefined) return;
            if (String(q.waypointId) !== String(waypointId)) return;
            if (q.mediaType === 'video') out.video = true; else out.photo = true;
            out.any = true;
        });
        return out;
    }

    function countQueuedMedia(queue) {
        return (queue || []).filter(q => q.kind === 'media').length;
    }

    // Two lanes share the one queue so a clip crawling up a weak uplink never
    // holds an SOS behind it:
    //   'fast'  every item that is not a file, in order
    //   'media' the files, in order
    // The one cross-lane rule: «Ολοκληρώθηκε» at a route point waits for that
    // point's photo/video still ahead of it in the queue (the server refuses
    // to complete a point whose required file is missing), and while it waits
    // the route actions behind it wait too, so the route stays in sequence.
    // Anything that is not a route action - an SOS, a triage card - does not.
    // Returns the index in `queue`, or -1 when this lane has nothing to send.
    function nextQueueIndex(queue, lane) {
        const waiting = new Set();
        let routeHeld = false;
        for (let i = 0; i < queue.length; i++) {
            const q = queue[i];
            if (q.kind === 'media') {
                if (lane === 'media') return i;
                if (q.waypointId !== null && q.waypointId !== undefined) waiting.add(String(q.waypointId));
                continue;
            }
            if (lane === 'media') continue;
            if (q.kind === 'route') {
                if (routeHeld) continue;
                if (q.action === 'complete' && waiting.has(String(q.id))) { routeHeld = true; continue; }
            }
            return i;
        }
        return -1;
    }

    // ── IndexedDB ───────────────────────────────────────────────────────────
    // One connection, reopened if the browser drops it. Every call rejects
    // when IndexedDB is missing or refuses (private mode, a full disk): the
    // caller then tells the person plainly that the file was NOT kept.
    let dbPromise = null;
    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            if (typeof indexedDB === 'undefined' || !indexedDB) { reject(new Error('IndexedDB is not available')); return; }
            let request;
            try { request = indexedDB.open(DB_NAME, DB_VERSION); } catch (e) { reject(e); return; }
            request.onupgradeneeded = () => { request.result.createObjectStore(STORE, {keyPath: 'id'}); };
            request.onsuccess = () => {
                const db = request.result;
                db.onclose = () => { dbPromise = null; };
                db.onversionchange = () => { db.close(); dbPromise = null; };
                resolve(db);
            };
            request.onerror = () => reject(request.error);
            request.onblocked = () => reject(new Error('IndexedDB is blocked'));
        });
        dbPromise.catch(() => { dbPromise = null; });
        return dbPromise;
    }

    // Resolves on the transaction's `complete`, not on the request's success:
    // only then is a write durable, and a queued clip is only safe once it is.
    function run(mode, work) {
        return openDb().then(db => new Promise((resolve, reject) => {
            let tx, request;
            try {
                tx = db.transaction(STORE, mode);
                request = work(tx.objectStore(STORE));
            } catch (e) { reject(e); return; }
            tx.oncomplete = () => resolve(request ? request.result : undefined);
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('IndexedDB transaction aborted'));
        }));
    }

    const put = spec => run('readwrite', store => store.put(spec));
    const get = id => run('readonly', store => store.get(id));
    const remove = id => run('readwrite', store => store.delete(id));

    // Asks the browser not to evict the origin's storage under pressure. A
    // hint (Safari and Chrome grant it by their own rules); never waited for.
    function requestPersistence() {
        try {
            if (typeof navigator !== 'undefined' && navigator.storage && navigator.storage.persist) {
                navigator.storage.persist().catch(() => {});
            }
        } catch (e) { /* a hint, nothing depends on it */ }
    }

    const api = {
        MAX_COUNTED_TRIES, isIosDevice, newMediaId, mediaFileName, mediaKind, makeSpec, buildFormData,
        classifyFailure, queuedMediaFor, countQueuedMedia, nextQueueIndex,
        put, get, remove, requestPersistence,
    };
    root.VoMediaQueue = api;
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof window !== 'undefined' ? window : globalThis);
