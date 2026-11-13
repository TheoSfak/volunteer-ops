/**
 * M1 of the BLE leg (project_lora_meshtastic_fallback_brainstorm Phase 2):
 * connect this device, over Bluetooth Low Energy, to the volunteer's own
 * Meshtastic node, and log what the node tells us. Nothing is sent to the
 * node beyond the standard want_config handshake, and nothing reaches the
 * server - this is a manual test harness for command staff to confirm the
 * handshake actually works against real hardware, before any phone tries to
 * send its own position over it (that is M2).
 *
 * Same split as assets/js/mesh-gateway.js: the bytes are assets/js/mesh-protocol.js
 * (MP.BLE has the service/characteristic UUIDs), this file is just the transport.
 * Same native/web Bluetooth split as assets/js/vitals-sensor.js, because this
 * runs both inside the APK (Capacitor BluetoothLe plugin) and in a plain
 * Chrome/Edge tab (Web Bluetooth) - the latter is the faster way to try a
 * laptop-side node without rebuilding the app.
 *
 * Unlike the serial gateway, BLE GATT reads/writes are already one whole
 * message each: no frame() / FrameReader here, see mesh-protocol.js.
 */
(function (root) {
    'use strict';

    const MP = root.MeshProtocol || (typeof require === 'function' ? require('./mesh-protocol.js') : null);

    const MAX_LOG = 50;

    // ── Pure logic (testable without any Bluetooth stack) ──────────────────────
    /** One human-readable line for a decoded FromRadio message. */
    function logLineFor(msg) {
        if (!msg) return 'empty read (queue drained)';
        if (msg.type === 'myInfo') return 'myInfo: ' + MP.nodeHex(msg.nodeNum);
        if (msg.type === 'nodeInfo') {
            const n = msg.node;
            return 'nodeInfo: ' + (n.longName || n.shortName || '(no name)') + ' ' + MP.nodeHex(n.num);
        }
        if (msg.type === 'configComplete') return 'configComplete';
        if (msg.type === 'packet') {
            const p = msg.packet;
            const pos = p.position && p.position.lat !== null ? ' pos ' + p.position.lat.toFixed(5) + ',' + p.position.lng.toFixed(5) : '';
            return 'packet from ' + MP.nodeHex(p.from) + pos;
        }
        if (msg.type === 'bad') return 'bad frame (could not decode)';
        return 'other (ignored: settings, etc.)';
    }

    /** Appends a line, keeping only the most recent MAX_LOG. Returns a new array. */
    function pushLog(log, line) {
        const next = log.concat([line]);
        return next.length > MAX_LOG ? next.slice(next.length - MAX_LOG) : next;
    }

    /** Hex string (what the native plugin's bridge hands back) -> Uint8Array. */
    function hexToBytes(hex) {
        const clean = String(hex || '').replace(/[^0-9a-fA-F]/g, '');
        const bytes = new Uint8Array(clean.length >> 1);
        for (let i = 0; i < bytes.length; i++) bytes[i] = parseInt(clean.substr(i * 2, 2), 16);
        return bytes;
    }

    /** Uint8Array -> hex string: the only value shape the plugin's native side takes for a write. */
    function bytesToHex(bytes) {
        let out = '';
        for (let i = 0; i < bytes.length; i++) out += bytes[i].toString(16).padStart(2, '0');
        return out;
    }

    /** Web Bluetooth hands back a DataView already; make it a Uint8Array. */
    function dataViewToBytes(dv) {
        return new Uint8Array(dv.buffer, dv.byteOffset, dv.byteLength);
    }

    // ── The test harness ────────────────────────────────────────────────────
    const state = {
        phase: 'idle',   // idle | connecting | reading | done | lost | error
        error: '',
        log: [],
        myNum: null,
    };
    let host = null;
    let device = null;    // {kind: 'native', id} or {kind: 'web', btDevice, toradio, fromradio}
    let draining = false;

    function setPhase(phase, error) {
        state.phase = phase;
        state.error = error || '';
        render();
    }

    function log(line) {
        state.log = pushLog(state.log, line);
        render();
    }

    function detectTransport() {
        const cap = root.Capacitor;
        if (cap && cap.Plugins && cap.Plugins.BluetoothLe) return 'native';
        if (root.navigator && root.navigator.bluetooth && typeof root.navigator.bluetooth.requestDevice === 'function') return 'web';
        return null;
    }

    async function readOneNative(deviceId) {
        const ble = root.Capacitor.Plugins.BluetoothLe;
        const res = await ble.read({deviceId, service: MP.BLE.SERVICE, characteristic: MP.BLE.FROMRADIO});
        return hexToBytes(res && res.value);
    }

    async function drainNative(deviceId) {
        if (draining) return;
        draining = true;
        try {
            for (;;) {
                const bytes = await readOneNative(deviceId);
                if (!bytes.length) break;
                log(logLineFor(MP.decodeFromRadio(bytes)));
            }
        } catch (e) {
            log('read failed: ' + (e && e.message ? e.message : e));
        } finally {
            draining = false;
        }
    }

    async function drainWeb(fromradioChar) {
        if (draining) return;
        draining = true;
        try {
            for (;;) {
                const dv = await fromradioChar.readValue();
                const bytes = dataViewToBytes(dv);
                if (!bytes.length) break;
                log(logLineFor(MP.decodeFromRadio(bytes)));
            }
        } catch (e) {
            log('read failed: ' + (e && e.message ? e.message : e));
        } finally {
            draining = false;
        }
    }

    function onLost() {
        device = null;
        setPhase('lost');
    }

    async function connect() {
        const transport = detectTransport();
        if (!transport) { setPhase('error', 'unsupported'); return; }
        setPhase('connecting');
        state.log = [];
        state.myNum = null;
        try {
            const wantConfigId = (Math.random() * 0xffffffff) >>> 0 || 1;
            if (transport === 'native') {
                const ble = root.Capacitor.Plugins.BluetoothLe;
                await ble.initialize();
                const picked = await ble.requestDevice({services: [MP.BLE.SERVICE]});
                const deviceId = picked.deviceId;
                await ble.addListener('disconnected|' + deviceId, onLost);
                await ble.connect({deviceId});
                // Warmup read (meshtastic-sdk's own transport-ble does this before
                // anything else): fromradio is encrypted, so this is what actually
                // triggers bonding/pairing on most platforms. Skipping it, writing
                // want_config went through with no error, but the node never
                // answered - it was still waiting on this read to consider the link
                // ready. Result is discarded; only the side effect matters here.
                await readOneNative(deviceId).catch(() => {});
                await ble.addListener('notification|' + deviceId + '|' + MP.BLE.SERVICE + '|' + MP.BLE.FROMNUM, () => drainNative(deviceId));
                await ble.startNotifications({deviceId, service: MP.BLE.SERVICE, characteristic: MP.BLE.FROMNUM});
                device = {kind: 'native', id: deviceId};
                await ble.write({deviceId, service: MP.BLE.SERVICE, characteristic: MP.BLE.TORADIO, value: bytesToHex(MP.encodeVarint(3, wantConfigId))});
                setPhase('reading');
                log('want_config sent (id ' + wantConfigId + ')');
                // Belt-and-braces: a fromnum notification that fired before the
                // listener above was attached is lost, so drain once ourselves too.
                drainNative(deviceId);
            } else {
                const btDevice = await root.navigator.bluetooth.requestDevice({filters: [{services: [MP.BLE.SERVICE]}]});
                btDevice.addEventListener('gattserverdisconnected', onLost);
                const server = await btDevice.gatt.connect();
                const service = await server.getPrimaryService(MP.BLE.SERVICE);
                const toradio = await service.getCharacteristic(MP.BLE.TORADIO);
                const fromradio = await service.getCharacteristic(MP.BLE.FROMRADIO);
                const fromnum = await service.getCharacteristic(MP.BLE.FROMNUM);
                // Warmup read, see the native branch above for why.
                await fromradio.readValue().catch(() => {});
                await fromnum.startNotifications();
                fromnum.addEventListener('characteristicvaluechanged', () => drainWeb(fromradio));
                device = {kind: 'web', btDevice, toradio, fromradio};
                await toradio.writeValueWithResponse(MP.encodeVarint(3, wantConfigId));
                setPhase('reading');
                log('want_config sent (id ' + wantConfigId + ')');
                drainWeb(fromradio);
            }
        } catch (e) {
            device = null;
            const cancelled = /cancel|user did not|no device selected/i.test(String(e && e.message));
            setPhase(cancelled ? 'idle' : 'error', cancelled ? '' : (e && e.message ? e.message : String(e)));
        }
    }

    async function disconnect() {
        try {
            if (device && device.kind === 'native') await root.Capacitor.Plugins.BluetoothLe.disconnect({deviceId: device.id});
            else if (device && device.kind === 'web') await device.btDevice.gatt.disconnect();
        } catch (e) { /* already gone */ }
        device = null;
        setPhase('idle');
    }

    // ── The window ──────────────────────────────────────────────────────────
    function el(id) { return document.getElementById(id); }

    function render() {
        const body = el('meshBleBody');
        if (!body || !host) return;
        const t = host.t, esc = host.esc;
        const transport = detectTransport();

        let status;
        if (!transport) status = `<div class="alert alert-warning small mb-0">${esc(t('mesh_ble.unsupported'))}</div>`;
        else if (state.phase === 'reading' || state.phase === 'done') status = `<div class="text-success"><i class="bi bi-bluetooth me-1"></i>${esc(t('mesh_ble.state_' + state.phase))}</div>`;
        else if (state.phase === 'connecting') status = `<div class="text-muted">${esc(t('mesh_ble.state_connecting'))}</div>`;
        else if (state.phase === 'lost') status = `<div class="text-danger">${esc(t('mesh_ble.state_lost'))}</div>`;
        else if (state.phase === 'error') status = `<div class="text-danger">${esc(t('mesh_ble.state_error', {error: state.error}))}</div>`;
        else status = `<div class="text-muted">${esc(t('mesh_ble.state_idle'))}</div>`;

        const connected = state.phase === 'reading' || state.phase === 'done' || state.phase === 'connecting';
        const btn = !transport ? '' : connected
            ? `<button type="button" class="btn btn-sm btn-outline-secondary" id="meshBleDisconnectBtn">${esc(t('mesh_ble.disconnect'))}</button>`
            : `<button type="button" class="btn btn-sm btn-primary" id="meshBleConnectBtn"><i class="bi bi-bluetooth me-1"></i>${esc(t('mesh_ble.connect'))}</button>`;

        const logHtml = state.log.length
            ? state.log.map(esc).join('\n')
            : esc(t('mesh_ble.log_empty'));

        body.innerHTML = `
            <p class="small text-muted mb-2">${esc(t('mesh_ble.intro'))}</p>
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap"><div class="flex-grow-1">${status}</div>${btn}</div>
            <pre class="small bg-body-secondary p-2 rounded" style="max-height:16rem;overflow-y:auto;white-space:pre-wrap">${logHtml}</pre>`;
    }

    function onClick(e) {
        if (e.target.closest('#meshBleConnectBtn')) { connect(); return; }
        if (e.target.closest('#meshBleDisconnectBtn')) { disconnect(); return; }
    }

    function open() {
        const modal = el('meshBleModal');
        if (!modal) return;
        render();
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function init(h) {
        host = h;
        const modal = el('meshBleModal');
        if (modal) modal.addEventListener('click', onClick);
    }

    const api = {init, open, connect, disconnect, state, logLineFor, pushLog, hexToBytes, bytesToHex, dataViewToBytes};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MeshBle = api;
})(typeof window !== 'undefined' ? window : globalThis);
