/**
 * The LoRa gateway at the command post (v3.427.0): the Action Room page holds
 * a Meshtastic node on its USB cable, over Web Serial, and forwards the
 * positions the node hears to mesh-ingest.php. Nothing is installed; the page
 * is the gateway. See includes/functions-mesh.php for what the server does
 * with them and assets/js/mesh-protocol.js for the bytes.
 *
 * The page stays the gateway for as long as it is open and the cable is in:
 * closing the window of this feature changes nothing, and a port that was
 * granted once is reopened by itself when the page loads again.
 *
 * Web Serial gives one program the port at a time. While this page holds it, a
 * second program (the Meshtastic app, a flasher, `meshtastic --info`) gets
 * "access denied" - and the other way round.
 *
 * The page that loads this file (war-room.php) calls MeshGateway.init() with
 * what it owns: the mission, a post() for its CSRF-protected endpoints, t() and
 * escapeHtml(). The pure bookkeeping is exported for Node
 * (tests/js/mesh-gateway.test.js).
 */
(function (root) {
    'use strict';

    const MP = root.MeshProtocol || (typeof require === 'function' ? require('./mesh-protocol.js') : null);

    const BAUD_RATE = 115200;
    const FLUSH_EVERY_MS = 4000;
    const HEARTBEAT_EVERY_MS = 60000;
    const NODES_EVERY_MS = 10000;
    const BATCH_SIZE = 50;
    // The same radio packet is heard again when another node repeats it; only
    // this many recent ones are remembered, which is hours of traffic.
    const SEEN_MAX = 2000;
    // A page that cannot reach the server keeps this many records waiting; past
    // it the oldest go, as the fix of an hour ago is worth less than the new one.
    const QUEUE_MAX = 500;
    const AUTO_KEY = 'vo_mesh_gateway_auto';

    // ── Pure bookkeeping ────────────────────────────────────────────────────
    /** Remembers which (node, packet id) pairs were already taken. */
    class SeenSet {
        constructor(max) { this.max = max || SEEN_MAX; this.set = new Set(); }
        // True the first time a pair is offered, false after.
        add(node, id) {
            const key = (node >>> 0) + ':' + (id >>> 0);
            if (this.set.has(key)) return false;
            this.set.add(key);
            if (this.set.size > this.max) this.set.delete(this.set.values().next().value);
            return true;
        }
    }

    /** Records waiting for the server: take() hands out a batch, giveBack() returns it after a failure. */
    class SendQueue {
        constructor(max) { this.max = max || QUEUE_MAX; this.items = []; }
        push(rec) {
            this.items.push(rec);
            if (this.items.length > this.max) this.items.splice(0, this.items.length - this.max);
        }
        get length() { return this.items.length; }
        take(n) { return this.items.splice(0, n); }
        giveBack(batch) { this.items.unshift(...batch); if (this.items.length > this.max) this.items.splice(0, this.items.length - this.max); }
    }

    /** "12 s", "4 min", "2 h" from seconds. Language-neutral on purpose; the units are universal. */
    function ageText(seconds) {
        if (seconds === null || seconds === undefined) return '';
        if (seconds < 60) return Math.max(0, Math.round(seconds)) + ' s';
        if (seconds < 3600) return Math.round(seconds / 60) + ' min';
        return Math.round(seconds / 3600) + ' h';
    }

    /** "SNR 6.5 dB · −97 dBm · 1 hop" with whatever is known. */
    function signalText(node) {
        const parts = [];
        if (node.snr !== null && node.snr !== undefined) parts.push('SNR ' + node.snr + ' dB');
        if (node.rssi !== null && node.rssi !== undefined) parts.push(node.rssi + ' dBm');
        if (node.hops !== null && node.hops !== undefined) parts.push(node.hops + ' hop');
        return parts.join(' · ');
    }

    // ── The gateway ─────────────────────────────────────────────────────────
    const seen = new SeenSet();
    const queue = new SendQueue();
    const state = {
        phase: 'idle',      // idle | connecting | listening | lost | error
        error: '',
        ownNode: null,      // {num, name}
        heard: 0,           // positions the radio heard
        sent: 0,            // records the server acknowledged
        recorded: 0,        // ...of which became a position
        lastSummary: null,
        bound: [], heardUnbound: [], people: [],
    };
    let host = null;
    let port = null, reader = null, writer = null;
    let frames = null;
    let timers = {flush: null, heartbeat: null, nodes: null};
    let flushing = false, closing = false;
    let myNum = null;

    function setPhase(phase, error) {
        state.phase = phase;
        state.error = error || '';
        render();
    }

    async function write(bytes) {
        if (!writer) return;
        await writer.write(bytes);
    }

    async function connect(existingPort) {
        if (!('serial' in navigator)) return;
        closing = false;
        setPhase('connecting');
        try {
            port = existingPort || await navigator.serial.requestPort();
            await port.open({baudRate: BAUD_RATE});
            // Some boards reset when DTR/RTS toggle; leave both low.
            try { await port.setSignals({dataTerminalReady: false, requestToSend: false}); } catch (e) { /* not every adapter has them */ }
            frames = new MP.FrameReader();
            writer = port.writable.getWriter();
            reader = port.readable.getReader();
            try { localStorage.setItem(AUTO_KEY, '1'); } catch (e) { /* private window */ }
            await write(MP.wakeBytes());
            await write(MP.encodeWantConfig((Math.random() * 0xffffffff) >>> 0 || 1));
            timers.heartbeat = setInterval(() => { write(MP.encodeHeartbeat()).catch(() => {}); }, HEARTBEAT_EVERY_MS);
            timers.flush = setInterval(flush, FLUSH_EVERY_MS);
            setPhase('listening');
            readLoop();
        } catch (e) {
            await release();
            setPhase('error', e && e.message ? e.message : String(e));
        }
    }

    async function readLoop() {
        const mine = reader;
        try {
            for (;;) {
                const {value, done} = await mine.read();
                if (done) break;
                if (value && value.length) handleBytes(value);
            }
        } catch (e) {
            if (!closing) { await release(); setPhase('lost', e && e.message ? e.message : ''); return; }
        }
        if (!closing) { await release(); setPhase('lost'); }
    }

    function handleBytes(chunk) {
        frames.push(chunk).forEach(payload => {
            const msg = MP.decodeFromRadio(payload);
            if (msg.type === 'myInfo') {
                myNum = msg.nodeNum;
                state.ownNode = {num: myNum, name: MP.nodeHex(myNum)};
                render();
            } else if (msg.type === 'nodeInfo' && msg.node.num === myNum && myNum !== null) {
                state.ownNode = {num: myNum, name: (msg.node.longName || msg.node.shortName || '') + ' ' + MP.nodeHex(myNum)};
                render();
            } else if (msg.type === 'packet') {
                const rec = MP.positionRecord(msg.packet, Date.now());
                // The gateway's own node reports itself too; its position is
                // the command post's, not a rescuer's.
                if (!rec || rec.node === myNum || !seen.add(rec.node, rec.id)) return;
                state.heard++;
                queue.push(rec);
                render();
            }
        });
    }

    async function release() {
        clearInterval(timers.heartbeat); clearInterval(timers.flush);
        timers.heartbeat = timers.flush = null;
        try { if (reader) { await reader.cancel(); reader.releaseLock(); } } catch (e) { /* already gone */ }
        try { if (writer) { await writer.close(); } } catch (e) { /* already gone */ }
        try { if (port) await port.close(); } catch (e) { /* already gone */ }
        reader = writer = port = null;
    }

    async function disconnect() {
        closing = true;
        try { await write(MP.encodeDisconnect()); } catch (e) { /* the cable may be out */ }
        await release();
        try { localStorage.removeItem(AUTO_KEY); } catch (e) { /* private window */ }
        state.ownNode = null; myNum = null;
        setPhase('idle');
    }

    function flush() {
        if (flushing || !queue.length || !host) return;
        flushing = true;
        const batch = queue.take(BATCH_SIZE);
        host.post({action: 'ingest', packets: JSON.stringify(batch)}).then(res => {
            if (!res || !res.ok) { queue.giveBack(batch); return; }
            state.sent += batch.length;
            state.recorded += res.recorded || 0;
            state.lastSummary = res;
            render();
        }).catch(() => queue.giveBack(batch)).finally(() => { flushing = false; });
    }

    function loadNodes() {
        if (!host) return Promise.resolve();
        return host.post({action: 'nodes'}).then(res => {
            if (!res || !res.ok) return;
            state.bound = res.bound || [];
            state.heardUnbound = res.heard || [];
            state.people = res.people || [];
            render();
        });
    }

    // ── The window ──────────────────────────────────────────────────────────
    function el(id) { return document.getElementById(id); }

    function render() {
        // The buttons that open the window show whether the radio is up.
        document.querySelectorAll('[data-mesh-btn]').forEach(b => {
            b.classList.toggle('text-success', state.phase === 'listening');
            b.classList.toggle('text-danger', state.phase === 'lost');
        });
        const body = el('meshGatewayBody');
        if (!body || !host) return;
        const t = host.t, esc = host.esc;
        const supported = 'serial' in navigator;

        let status;
        if (!supported) status = `<div class="alert alert-warning small mb-0">${esc(t('mesh.unsupported'))}</div>`;
        else if (state.phase === 'listening') status = `<div class="text-success"><i class="bi bi-broadcast me-1"></i>${esc(t('mesh.state_listening', {name: state.ownNode ? state.ownNode.name : '…'}))}</div>`;
        else if (state.phase === 'connecting') status = `<div class="text-muted">${esc(t('mesh.state_connecting'))}</div>`;
        else if (state.phase === 'lost') status = `<div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>${esc(t('mesh.state_lost'))}</div>`;
        else if (state.phase === 'error') status = `<div class="text-danger">${esc(t('mesh.state_error', {error: state.error}))}<div class="small text-muted mt-1">${esc(t('mesh.hint_busy'))}</div></div>`;
        else status = `<div class="text-muted">${esc(t('mesh.state_idle'))}</div>`;

        const connectBtn = !supported ? ''
            : (state.phase === 'listening' || state.phase === 'connecting')
                ? `<button type="button" class="btn btn-sm btn-outline-secondary" id="meshDisconnectBtn">${esc(t('mesh.disconnect'))}</button>`
                : `<button type="button" class="btn btn-sm btn-primary" id="meshConnectBtn"><i class="bi bi-usb-symbol me-1"></i>${esc(t('mesh.connect'))}</button>`;

        const counts = state.phase === 'listening' || state.heard
            ? `<div class="small text-muted mt-1">${esc(t('mesh.counts', {heard: state.heard, sent: state.sent, recorded: state.recorded, waiting: queue.length}))}</div>` : '';

        const ageOf = n => n.age_s === null || n.age_s === undefined ? esc(t('mesh.never')) : esc(t('mesh.heard_ago', {age: ageText(n.age_s)}));
        const bound = state.bound.length
            ? state.bound.map(n => `<div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                    <span class="fw-semibold">${esc(n.name)}</span>
                    <code class="small">${esc(MP.nodeHex(n.node))}</code>${n.label ? `<span class="small text-muted">${esc(n.label)}</span>` : ''}
                    <span class="small text-muted ms-auto">${ageOf(n)}${signalText(n) ? ' · ' + esc(signalText(n)) : ''}</span>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 mesh-unbind" data-node="${n.node}" title="${esc(t('mesh.unbind_btn'))}"><i class="bi bi-x-circle"></i></button>
                </div>`).join('')
            : `<div class="small text-muted">${esc(t('mesh.bound_none'))}</div>`;

        const options = `<option value="">${esc(t('mesh.pick_person'))}</option>` + state.people.map(g =>
            `<optgroup label="${esc(g.label || t('mesh.no_team'))}">${g.members.map(m => `<option value="${m.user_id}">${esc(m.name)}</option>`).join('')}</optgroup>`).join('');
        const heard = state.heardUnbound.length
            ? state.heardUnbound.map(n => `<div class="d-flex align-items-center gap-2 mb-1 flex-wrap" data-node="${n.node}">
                    <code class="small">${esc(MP.nodeHex(n.node))}</code>
                    <span class="small text-muted">${ageOf(n)}${signalText(n) ? ' · ' + esc(signalText(n)) : ''}</span>
                    <select class="form-select form-select-sm ms-auto mesh-person" style="max-width:14rem">${options}</select>
                    <button type="button" class="btn btn-sm btn-outline-primary mesh-bind">${esc(t('mesh.bind_btn'))}</button>
                </div>`).join('')
            : `<div class="small text-muted">${esc(t('mesh.heard_none'))}</div>`;

        // Keep a half-made choice while the lists refresh under it.
        const picks = {};
        body.querySelectorAll('[data-node] .mesh-person').forEach(s => { picks[s.closest('[data-node]').dataset.node] = s.value; });

        body.innerHTML = `
            <p class="small text-muted mb-2">${esc(t('mesh.intro'))}</p>
            <div class="d-flex align-items-center gap-3 mb-1 flex-wrap"><div class="flex-grow-1">${status}${counts}</div>${connectBtn}</div>
            <h6 class="mt-3 mb-2">${esc(t('mesh.bound_title'))}</h6>${bound}
            <h6 class="mt-3 mb-1">${esc(t('mesh.heard_title'))}</h6>
            <div class="small text-muted mb-2">${esc(t('mesh.heard_hint'))}</div>${heard}
            <div id="meshGatewayMsg" class="small mt-3" role="status"></div>`;
        body.querySelectorAll('[data-node] .mesh-person').forEach(s => {
            const v = picks[s.closest('[data-node]').dataset.node];
            if (v) s.value = v;
        });
    }

    function message(text, cls) {
        const m = el('meshGatewayMsg');
        if (m) { m.className = 'small mt-3 ' + (cls || ''); m.textContent = text || ''; }
    }

    function onClick(e) {
        const t = host.t;
        if (e.target.closest('#meshConnectBtn')) { connect(); return; }
        if (e.target.closest('#meshDisconnectBtn')) { disconnect(); return; }
        const bind = e.target.closest('.mesh-bind');
        if (bind) {
            const row = bind.closest('[data-node]');
            const userId = Number(row.querySelector('.mesh-person').value);
            if (!userId) { message(t('mesh.pick_person'), 'text-danger'); return; }
            host.post({action: 'bind', node: row.dataset.node, user_id: userId}).then(res => {
                if (!res || !res.ok) { message((res && res.error) || t('common.failed'), 'text-danger'); return; }
                message(t('mesh.toast_bound'), 'text-success');
                loadNodes();
            });
            return;
        }
        const unbind = e.target.closest('.mesh-unbind');
        if (unbind) {
            host.post({action: 'unbind', node: unbind.dataset.node}).then(res => {
                if (!res || !res.ok) { message((res && res.error) || t('common.failed'), 'text-danger'); return; }
                message(t('mesh.toast_unbound'), 'text-success');
                loadNodes();
            });
        }
    }

    function open() {
        const modal = el('meshGatewayModal');
        if (!modal) return;
        render();
        bootstrap.Modal.getOrCreateInstance(modal).show();
        loadNodes();
    }

    function init(h) {
        host = h;
        const modal = el('meshGatewayModal');
        if (modal) {
            modal.addEventListener('click', onClick);
            // Fresh lists while the window is open; the gateway itself needs no window.
            modal.addEventListener('shown.bs.modal', () => { clearInterval(timers.nodes); timers.nodes = setInterval(loadNodes, NODES_EVERY_MS); });
            modal.addEventListener('hidden.bs.modal', () => { clearInterval(timers.nodes); timers.nodes = null; });
        }
        if (!('serial' in navigator)) return;
        navigator.serial.addEventListener('disconnect', () => { if (state.phase === 'listening') { release().then(() => setPhase('lost')); } });
        // A cable that was granted once reopens by itself, so a page reload at
        // the command post does not silently end the gateway.
        let wanted = false;
        try { wanted = localStorage.getItem(AUTO_KEY) === '1'; } catch (e) { /* private window */ }
        if (wanted) {
            navigator.serial.getPorts().then(ports => { if (ports.length) connect(ports[0]); }).catch(() => {});
        }
    }

    const api = {init, open, connect, disconnect, state, SeenSet, SendQueue, ageText, signalText};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MeshGateway = api;
})(typeof window !== 'undefined' ? window : globalThis);
