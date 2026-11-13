/**
 * The BLE leg (project_lora_meshtastic_fallback_brainstorm Phase 2): connect
 * this device, over Bluetooth Low Energy, to the volunteer's own Meshtastic
 * node. M1 is the want_config handshake and the log of what the node says.
 * M2 added one button: send a real position of this device's own, over
 * `toradio`, so the command-post gateway (assets/js/mesh-gateway.js) can hear
 * it and mesh-ingest.php can record it - exactly the same path a heard radio
 * packet takes, just starting one hop earlier.
 *
 * M3 is the automatic field trigger (off by default, Settings > Action Room):
 * once a volunteer has connected ONE time by hand (M1's button - BLE requires
 * a real user gesture to grant a device, see autoConnect() below), this page
 * remembers that node (localStorage, like vitals-sensor.js's strap) and, from
 * then on, FallbackState watches war-room.php's own poll outcome. When the
 * normal connection has been down for a while it reconnects to the
 * remembered node on its own - no picker, no gesture needed for a device
 * already granted - and sends this device's live position every
 * MESH_SEND_CADENCE_MS until the normal connection is back for two polls
 * running. A node used this way is not bound to anyone in particular (see
 * includes/functions-mesh.php): until command staff tie a node to a person,
 * whatever it sends sits in the "heard, unbound" list and never reaches the
 * live map by itself.
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

    /**
     * NORMAL/MESH with hysteresis (project_lora_meshtastic_fallback_brainstorm's
     * "~60-90s entry, 2-success exit"): a few seconds of poll trouble must not
     * fire up the radio, and the moment the normal connection answers once more
     * must not drop it either - a flaky link would then switch back and forth
     * on every other poll. Knows nothing about BLE or the DOM; war-room.php
     * drives it with its own pollIsOffline() on every poll outcome.
     */
    class FallbackState {
        constructor(entryAfterMs, exitSuccesses) {
            this.entryAfterMs = entryAfterMs == null ? 75000 : entryAfterMs;
            this.exitSuccesses = exitSuccesses == null ? 2 : exitSuccesses;
            this.mode = 'normal';
            this.offlineSince = null;
            this.successStreak = 0;
        }
        /**
         * One poll's outcome. Returns 'entered' the instant MESH starts,
         * 'exited' the instant it ends, null the rest of the time - the
         * caller acts only on a non-null return, not on `mode` every tick.
         */
        tick(offline, now) {
            if (offline) {
                this.successStreak = 0;
                if (this.offlineSince === null) this.offlineSince = now;
                if (this.mode === 'normal' && (now - this.offlineSince) >= this.entryAfterMs) {
                    this.mode = 'mesh';
                    return 'entered';
                }
                return null;
            }
            this.offlineSince = null;
            if (this.mode !== 'mesh') return null;
            this.successStreak++;
            if (this.successStreak < this.exitSuccesses) return null;
            this.mode = 'normal';
            this.successStreak = 0;
            return 'exited';
        }
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

    // M3: how often a position goes out while in MESH mode. LoRa airtime is
    // shared and regulated, unlike the phone's own data connection, so this
    // is far slower than a normal ping - roughly how often a walking pace
    // meaningfully changes someone's position, not a tracking-grade rate.
    const MESH_SEND_CADENCE_MS = 90000;
    const DEVICE_STORAGE_KEY = 'vo_mesh_ble_device_id';
    let sendTimer = null;

    /** Which node to try reconnecting to without asking again - set the moment
     * a connectToDevice() succeeds, by hand or on its own, cleared only by a
     * deliberate disconnect (see disconnect() below; same rule as the heart-
     * rate strap in vitals-sensor.js: a dropped link must not forget it, or
     * walking out of range for a minute would permanently un-pair the node). */
    function savedDeviceId() {
        try { return root.localStorage.getItem(DEVICE_STORAGE_KEY); } catch (e) { return null; }
    }
    function rememberDevice(id) {
        try { root.localStorage.setItem(DEVICE_STORAGE_KEY, id); } catch (e) { /* private window */ }
    }
    function forgetDevice() {
        try { root.localStorage.removeItem(DEVICE_STORAGE_KEY); } catch (e) { /* private window */ }
    }

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
        stopAutoSending();
        device = null;
        setPhase('lost');
    }

    /** Writes unframed bytes to `toradio` - want_config today, a position for M2. */
    async function writeToRadio(bytes) {
        if (!device) throw new Error('not connected');
        if (device.kind === 'native') {
            await root.Capacitor.Plugins.BluetoothLe.write({
                deviceId: device.id, service: MP.BLE.SERVICE, characteristic: MP.BLE.TORADIO, value: bytesToHex(bytes),
            });
        } else {
            await device.toradio.writeValueWithResponse(bytes);
        }
    }

    /** A Promise wrapper around getCurrentPosition, field-mode.js's own style. */
    function getPosition() {
        return new Promise((resolve, reject) => {
            if (!root.navigator || !root.navigator.geolocation) { reject(new Error('no geolocation')); return; }
            root.navigator.geolocation.getCurrentPosition(
                pos => resolve(pos.coords),
                err => reject(err),
                {enableHighAccuracy: true, maximumAge: 60000, timeout: 10000}
            );
        });
    }

    /**
     * M2: this device's own position, written straight to `toradio`. The node
     * fills in `from` itself, so the resulting record cannot be confused with
     * anything the gateway heard over the air - it genuinely went out on this
     * link. Only possible once want_config has gone through (phase 'reading'
     * or 'done'): writing before that raced the node's own handshake in testing.
     */
    async function sendTestPosition() {
        if (state.phase !== 'reading' && state.phase !== 'done') return;
        try {
            log('getting this device\'s position…');
            const coords = await getPosition();
            const payload = MP.encodePosition({
                lat: coords.latitude, lng: coords.longitude,
                time: Math.round(Date.now() / 1000), precisionBits: 32,
            });
            const id = (Math.random() * 0xffffffff) >>> 0 || 1;
            const bytes = MP.encodeToRadioPacket({portnum: MP.PORT.POSITION, payload, id});
            await writeToRadio(bytes);
            log('test position sent (id ' + id + '): ' + coords.latitude.toFixed(5) + ',' + coords.longitude.toFixed(5));
        } catch (e) {
            log('test position failed: ' + (e && e.message ? e.message : e));
        }
    }

    /**
     * The handshake itself, shared by a manual connect() (a freshly PICKED
     * device, from the OS chooser) and autoConnect() (an ALREADY-GRANTED
     * device found again without asking). `pick` is {kind:'native', deviceId}
     * or {kind:'web', btDevice}. Resolves true/false; never throws.
     */
    async function connectToDevice(pick) {
        setPhase('connecting');
        state.log = [];
        state.myNum = null;
        try {
            const wantConfigId = (Math.random() * 0xffffffff) >>> 0 || 1;
            if (pick.kind === 'native') {
                const ble = root.Capacitor.Plugins.BluetoothLe;
                await ble.initialize();
                const deviceId = pick.deviceId;
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
                rememberDevice(deviceId);
                await writeToRadio(MP.encodeVarint(3, wantConfigId));
                setPhase('reading');
                log('want_config sent (id ' + wantConfigId + ')');
                // Belt-and-braces: a fromnum notification that fired before the
                // listener above was attached is lost, so drain once ourselves too.
                drainNative(deviceId);
            } else {
                const btDevice = pick.btDevice;
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
                rememberDevice(btDevice.id);
                await writeToRadio(MP.encodeVarint(3, wantConfigId));
                setPhase('reading');
                log('want_config sent (id ' + wantConfigId + ')');
                drainWeb(fromradio);
            }
            return true;
        } catch (e) {
            device = null;
            const cancelled = /cancel|user did not|no device selected/i.test(String(e && e.message));
            setPhase(cancelled ? 'idle' : 'error', cancelled ? '' : (e && e.message ? e.message : String(e)));
            return false;
        }
    }

    /** M1's button: pick a device from the OS chooser (needs a real click - see autoConnect()). */
    async function connect() {
        const transport = detectTransport();
        if (!transport) { setPhase('error', 'unsupported'); return; }
        setPhase('connecting');
        try {
            if (transport === 'native') {
                const ble = root.Capacitor.Plugins.BluetoothLe;
                await ble.initialize();
                const picked = await ble.requestDevice({services: [MP.BLE.SERVICE]});
                await connectToDevice({kind: 'native', deviceId: picked.deviceId});
            } else {
                const btDevice = await root.navigator.bluetooth.requestDevice({filters: [{services: [MP.BLE.SERVICE]}]});
                await connectToDevice({kind: 'web', btDevice});
            }
        } catch (e) {
            const cancelled = /cancel|user did not|no device selected/i.test(String(e && e.message));
            setPhase(cancelled ? 'idle' : 'error', cancelled ? '' : (e && e.message ? e.message : String(e)));
        }
    }

    /**
     * M3: reconnect to the remembered node WITHOUT a device picker. Web
     * Bluetooth will not show a chooser outside a real click, but it does not
     * need to here - navigator.bluetooth.getDevices() lists devices this
     * origin was ALREADY granted (by the earlier manual connect()), and
     * reconnecting to one of those needs no new gesture. Native has no such
     * distinction: a known deviceId simply connects. Resolves false (never
     * throws) if there is nothing remembered yet, or it cannot be reached.
     */
    async function autoConnect() {
        if (state.phase === 'connecting' || state.phase === 'reading' || state.phase === 'done') return false;
        const saved = savedDeviceId();
        if (!saved) return false;
        const transport = detectTransport();
        if (!transport) return false;
        let pick;
        try {
            if (transport === 'native') {
                pick = {kind: 'native', deviceId: saved};
            } else {
                const known = await root.navigator.bluetooth.getDevices();
                const match = known.find(d => d.id === saved);
                if (!match) return false;
                pick = {kind: 'web', btDevice: match};
            }
        } catch (e) { return false; }
        const ok = await connectToDevice(pick);
        if (ok) startAutoSending();
        return ok;
    }

    /** One MESH-mode send: the live fix war-room.php's own GPS tracking already holds, not a fresh request. */
    async function sendAutoPosition() {
        if (state.phase !== 'reading' && state.phase !== 'done') return;
        const fix = host && typeof host.getFix === 'function' ? host.getFix() : null;
        if (!fix) { log('auto: no recent fix to send'); return; }
        try {
            const payload = MP.encodePosition({
                lat: fix.lat, lng: fix.lng, time: Math.round((fix.time || Date.now()) / 1000), precisionBits: 32,
            });
            const id = (Math.random() * 0xffffffff) >>> 0 || 1;
            const bytes = MP.encodeToRadioPacket({portnum: MP.PORT.POSITION, payload, id});
            await writeToRadio(bytes);
            log('auto: position sent (id ' + id + '): ' + fix.lat.toFixed(5) + ',' + fix.lng.toFixed(5));
        } catch (e) {
            log('auto: send failed: ' + (e && e.message ? e.message : e));
        }
    }

    function startAutoSending() {
        stopAutoSending();
        sendTimer = root.setInterval(sendAutoPosition, MESH_SEND_CADENCE_MS);
        sendAutoPosition(); // entering MESH is itself news; do not wait a full cadence for the first one
    }

    function stopAutoSending() {
        if (sendTimer) { root.clearInterval(sendTimer); sendTimer = null; }
    }

    /**
     * `userInitiated`: a real click on the disconnect button - the only case
     * that forgets the remembered node. Leaving MESH mode because the normal
     * connection came back (war-room.php's own state machine) passes false:
     * the whole point of remembering it is to use it again next time, with no
     * new gesture. A link that merely dropped (onLost, which never calls this
     * at all) must not forget it either, or being out of BLE range for a
     * minute would permanently un-pair the node from this phone.
     */
    async function disconnect(userInitiated) {
        stopAutoSending();
        try {
            if (device && device.kind === 'native') await root.Capacitor.Plugins.BluetoothLe.disconnect({deviceId: device.id});
            else if (device && device.kind === 'web') await device.btDevice.gatt.disconnect();
        } catch (e) { /* already gone */ }
        device = null;
        if (userInitiated) forgetDevice();
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
        else {
            // Idle: say whether automatic fallback (M3) has a node to use at
            // all - otherwise the whole feature stays invisible until it
            // silently fails to find anything, the first time data drops.
            const saved = savedDeviceId();
            status = `<div class="text-muted">${esc(t('mesh_ble.state_idle'))}</div>`
                + `<div class="small text-muted">${esc(t(saved ? 'mesh_ble.remembered' : 'mesh_ble.none_remembered'))}</div>`;
        }

        const connected = state.phase === 'reading' || state.phase === 'done' || state.phase === 'connecting';
        const btn = !transport ? '' : connected
            ? `<button type="button" class="btn btn-sm btn-outline-secondary" id="meshBleDisconnectBtn">${esc(t('mesh_ble.disconnect'))}</button>`
            : `<button type="button" class="btn btn-sm btn-primary" id="meshBleConnectBtn"><i class="bi bi-bluetooth me-1"></i>${esc(t('mesh_ble.connect'))}</button>`;

        const sendBtn = (state.phase === 'reading' || state.phase === 'done')
            ? `<button type="button" class="btn btn-sm btn-outline-primary" id="meshBleSendBtn"><i class="bi bi-geo-alt me-1"></i>${esc(t('mesh_ble.send_position'))}</button>` : '';

        const logHtml = state.log.length
            ? state.log.map(esc).join('\n')
            : esc(t('mesh_ble.log_empty'));

        body.innerHTML = `
            <p class="small text-muted mb-2">${esc(t('mesh_ble.intro'))}</p>
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap"><div class="flex-grow-1">${status}</div>${sendBtn}${btn}</div>
            <pre class="small bg-body-secondary p-2 rounded" style="max-height:16rem;overflow-y:auto;white-space:pre-wrap">${logHtml}</pre>`;
    }

    function onClick(e) {
        if (e.target.closest('#meshBleConnectBtn')) { connect(); return; }
        if (e.target.closest('#meshBleDisconnectBtn')) { disconnect(true); return; }
        if (e.target.closest('#meshBleSendBtn')) { sendTestPosition(); return; }
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

    const api = {
        init, open, connect, disconnect, sendTestPosition, autoConnect, state, FallbackState,
        savedDeviceId, forgetDevice, logLineFor, pushLog, hexToBytes, bytesToHex, dataViewToBytes,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MeshBle = api;
})(typeof window !== 'undefined' ? window : globalThis);
