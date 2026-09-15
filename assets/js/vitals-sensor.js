/**
 * Rescuer vitals — reading a heart-rate sensor in the field.
 *
 * Talks to any sensor implementing the standard Bluetooth LE Heart Rate
 * Service (0x180D, characteristic 0x2A37): chest straps, armbands, and Huawei
 * watches/bands with "HR Data Broadcast" switched on. It filters on that
 * service UUID and never on a vendor name, which is the whole reason a
 * €29 Cycplus armband and a €89 Polar H10 need no code of their own.
 *
 * Two transports, because the same page runs in two places:
 *   - Inside the Capacitor APK the WebView has NO Web Bluetooth at all (the
 *     WebBluetoothCG status page still lists Android WebView as "will be
 *     supported in the future"), so the radio is reached through the native
 *     @capacitor-community/bluetooth-le plugin over the Capacitor bridge.
 *     This is the operational path: the app already runs a foreground service
 *     for background GPS, so the process — and with it this connection —
 *     survives a locked screen.
 *   - In Chrome or Samsung Internet on Android, Web Bluetooth works and is
 *     used as a fallback. It is genuinely second-best: every connection needs
 *     a fresh user gesture, and the browser suspends it when the tab goes
 *     background, so it is for trying the feature out, not for an operation.
 * Anywhere else (desktop Safari, iOS, an old WebView) the card says the
 * device cannot do it and the rest of the Action Room is unaffected.
 *
 * Loaded as a plain <script src>, not a module, matching war-room-utils.js —
 * the pure functions at the top are exported for `node --test` and everything
 * else stays an ordinary global.
 */

const HR_SERVICE_UUID        = '0000180d-0000-1000-8000-00805f9b34fb';
const HR_MEASUREMENT_UUID    = '00002a37-0000-1000-8000-00805f9b34fb';

// Mirrors VITALS_MIN_BPM / VITALS_MAX_BPM in includes/functions-vitals.php.
// Filtering here as well as server-side is not redundant: a rejected sample
// still costs a row in the batch and a line in the response, and the live
// readout on the volunteer's own phone should never flash a 300 at them.
const VITALS_CLIENT_MIN_BPM = 25;
const VITALS_CLIENT_MAX_BPM = 240;

// The server accepts at most 240 samples per call (VITALS_MAX_BATCH) and
// refuses anything older than two hours (VITALS_MAX_BACKFILL_SECONDS), so
// there is no point holding more than two hours' worth locally either.
const VITALS_MAX_BATCH_CLIENT = 240;
const VITALS_MAX_BUFFER       = 1440;

/**
 * Parse one Heart Rate Measurement notification (characteristic 0x2A37) per
 * the Bluetooth SIG layout:
 *
 *   byte 0   flags: bit0 value is uint16 (else uint8), bits1-2 sensor contact
 *            status, bit3 energy expended follows, bit4 RR intervals follow
 *   byte 1.. the heart rate, then the optional fields in that order
 *
 * Returns null for a truncated frame rather than a zero, because a zero here
 * is indistinguishable from a real reading of a strap that has just lost skin
 * contact, and the two need opposite handling.
 */
function parseHeartRateMeasurement(view) {
    if (!view || view.byteLength < 2) return null;

    const flags = view.getUint8(0);
    const is16Bit = (flags & 0x01) !== 0;
    if (is16Bit && view.byteLength < 3) return null;

    const bpm = is16Bit ? view.getUint16(1, true) : view.getUint8(1);
    let offset = is16Bit ? 3 : 2;

    // 0/1 = the sensor cannot tell, 2 = it can and the strap is off the skin,
    // 3 = it can and contact is good. Only 2 is a reason to distrust the value.
    const contactBits = (flags >> 1) & 0x03;
    const contactSupported = contactBits >= 2;
    const contactDetected  = contactBits === 3;

    if ((flags & 0x08) !== 0) offset += 2; // energy expended, kJ — not used here

    const rr = [];
    if ((flags & 0x10) !== 0) {
        // RR intervals are in 1/1024 second units. Kept because they are the
        // raw material for heart-rate variability, which is the obvious next
        // question once anyone looks at this data seriously — nothing consumes
        // them yet.
        for (; offset + 1 < view.byteLength; offset += 2) {
            rr.push(Math.round(view.getUint16(offset, true) * 1000 / 1024));
        }
    }

    return { bpm, contactSupported, contactDetected, rr };
}

/**
 * Hex string -> DataView. The native side of the Capacitor plugin hands
 * notification payloads over the bridge as hex (its own JS wrapper does this
 * same conversion); the Web Bluetooth path already produces a DataView and
 * skips this entirely.
 */
function hexToDataView(hex) {
    const clean = String(hex || '').replace(/[^0-9a-fA-F]/g, '');
    const bytes = new Uint8Array(clean.length >> 1);
    for (let i = 0; i < bytes.length; i++) {
        bytes[i] = parseInt(clean.substr(i * 2, 2), 16);
    }
    return new DataView(bytes.buffer);
}

/**
 * Which aggregation window an instant belongs to. The sensor notifies about
 * once a second; one stored row per window is what keeps a six-hour shift from
 * becoming 21.600 rows per volunteer for a curve no eye could tell apart.
 */
function vitalsWindowIndex(tsMs, windowSeconds) {
    return Math.floor(tsMs / (Math.max(1, windowSeconds) * 1000));
}

/**
 * Collapse one window's readings into the row that gets stored. The average is
 * the headline, but the extremes travel with it: a window averaging 140 that
 * touched 170 is a different event from a flat 140, and the server keeps both
 * columns precisely so the report can tell them apart later.
 */
function summariseWindow(values) {
    if (!values || !values.length) return null;
    let sum = 0, min = values[0], max = values[0];
    for (const v of values) {
        sum += v;
        if (v < min) min = v;
        if (v > max) max = v;
    }
    return { bpm: Math.round(sum / values.length), min, max };
}

/* ────────────────────────────────────────────────────────────────────────── */

const VitalsSensor = {
    config: null,
    transport: null,       // 'native' | 'web' | null
    device: null,          // {id, name}
    connected: false,
    lastBpm: null,
    lastAt: null,

    windowIndex: null,
    windowValues: [],
    outbox: [],            // [[tMs, bpm, min, max], ...] not yet accepted by the server

    flushTimer: null,
    reconnectTimer: null,
    reconnectAttempt: 0,
    webCharacteristic: null,

    /**
     * $config comes from war-room.php: {enabled, sampleSeconds, flushSeconds,
     * shiftId, csrfToken, endpoint, strings}. Returns quietly when the feature
     * is off or this viewer has no shift, so the card simply never appears.
     */
    init(config) {
        this.config = config;
        if (!config || !config.enabled || !config.shiftId) return;

        this.transport = this.detectTransport();
        this.renderStatus();

        const connectBtn = document.getElementById('vitalsConnectBtn');
        const disconnectBtn = document.getElementById('vitalsDisconnectBtn');
        if (connectBtn) connectBtn.addEventListener('click', () => this.connect());
        if (disconnectBtn) disconnectBtn.addEventListener('click', () => this.disconnect(true));

        // Reconnect to the sensor this phone used last time without asking
        // again. Only the native path can: Web Bluetooth requires a fresh user
        // gesture for every connection by design, which is exactly why it is
        // the fallback and not the operational transport.
        const saved = this.savedDeviceId();
        if (this.transport === 'native' && saved) {
            this.connect(saved).catch(() => this.scheduleReconnect());
        }

        // A flush that only happens on a timer loses whatever is buffered when
        // the volunteer closes the tab or the phone sleeps mid-window. Both of
        // these fire in cases the interval never gets to.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) this.flush(true);
        });
        window.addEventListener('pagehide', () => this.flush(true));
    },

    detectTransport() {
        const cap = window.Capacitor;
        if (cap && cap.Plugins && cap.Plugins.BluetoothLe) return 'native';
        if (navigator.bluetooth && typeof navigator.bluetooth.requestDevice === 'function') return 'web';
        return null;
    },

    storageKey() {
        return 'vitals_device_id';
    },

    savedDeviceId() {
        try { return localStorage.getItem(this.storageKey()); } catch (e) { return null; }
    },

    rememberDevice(id, name) {
        try {
            localStorage.setItem(this.storageKey(), id);
            if (name) localStorage.setItem(this.storageKey() + '_name', name);
        } catch (e) { /* private mode — reconnect just won't be automatic */ }
    },

    forgetDevice() {
        try {
            localStorage.removeItem(this.storageKey());
            localStorage.removeItem(this.storageKey() + '_name');
        } catch (e) {}
    },

    async connect(knownDeviceId) {
        if (!this.transport) return;
        this.setStatus(this.t('vitals.sensor_connecting'), 'text-muted');
        try {
            if (this.transport === 'native') {
                await this.connectNative(knownDeviceId);
            } else {
                await this.connectWeb();
            }
            this.connected = true;
            this.reconnectAttempt = 0;
            this.startFlushLoop();
            this.renderStatus();
        } catch (err) {
            this.connected = false;
            // A user closing the device picker is not a failure worth shouting
            // about; anything else is.
            const cancelled = /cancel|user did not|no device selected/i.test(String(err && err.message));
            this.setStatus(cancelled ? this.t('vitals.sensor_idle') : this.t('vitals.sensor_error'), cancelled ? 'text-muted' : 'text-danger');
            if (!cancelled && knownDeviceId) this.scheduleReconnect();
        }
    },

    async connectNative(knownDeviceId) {
        const ble = window.Capacitor.Plugins.BluetoothLe;
        await ble.initialize();

        let deviceId = knownDeviceId;
        let deviceName = null;
        if (!deviceId) {
            const picked = await ble.requestDevice({ services: [HR_SERVICE_UUID] });
            deviceId = picked.deviceId;
            deviceName = picked.name || null;
        }

        // Registered before connect(), not after: the plugin emits this the
        // moment the link drops, including during the connect attempt itself.
        await ble.addListener('disconnected|' + deviceId, () => {
            this.connected = false;
            this.renderStatus();
            this.scheduleReconnect();
        });

        await ble.connect({ deviceId });

        const key = 'notification|' + deviceId + '|' + HR_SERVICE_UUID + '|' + HR_MEASUREMENT_UUID;
        await ble.addListener(key, (event) => {
            const value = event && event.value;
            this.onMeasurement(typeof value === 'string' ? hexToDataView(value) : value);
        });
        await ble.startNotifications({
            deviceId,
            service: HR_SERVICE_UUID,
            characteristic: HR_MEASUREMENT_UUID,
        });

        this.device = { id: deviceId, name: deviceName || this.savedName() };
        this.rememberDevice(deviceId, deviceName);
    },

    async connectWeb() {
        const device = await navigator.bluetooth.requestDevice({
            filters: [{ services: [HR_SERVICE_UUID] }],
        });
        device.addEventListener('gattserverdisconnected', () => {
            this.connected = false;
            this.renderStatus();
            // No scheduleReconnect(): Web Bluetooth would need another user
            // gesture, so silently retrying could only ever fail.
        });

        const server = await device.gatt.connect();
        const service = await server.getPrimaryService(HR_SERVICE_UUID);
        const characteristic = await service.getCharacteristic(HR_MEASUREMENT_UUID);
        await characteristic.startNotifications();
        characteristic.addEventListener('characteristicvaluechanged', (e) => {
            this.onMeasurement(e.target.value);
        });

        this.webCharacteristic = characteristic;
        this.device = { id: device.id, name: device.name || null };
    },

    async disconnect(userInitiated) {
        this.stopFlushLoop();
        clearTimeout(this.reconnectTimer);
        this.flush(true);

        try {
            if (this.transport === 'native' && this.device) {
                await window.Capacitor.Plugins.BluetoothLe.disconnect({ deviceId: this.device.id });
            } else if (this.webCharacteristic && this.webCharacteristic.service) {
                await this.webCharacteristic.service.device.gatt.disconnect();
            }
        } catch (e) { /* already gone */ }

        this.connected = false;
        this.lastBpm = null;
        // Only a deliberate disconnect forgets the sensor. A dropped link must
        // not, or walking out of range would permanently un-pair the strap.
        if (userInitiated) {
            this.forgetDevice();
            this.device = null;
        }
        this.renderStatus();
    },

    scheduleReconnect() {
        if (this.transport !== 'native') return;
        const deviceId = (this.device && this.device.id) || this.savedDeviceId();
        if (!deviceId) return;

        clearTimeout(this.reconnectTimer);
        // 5s, 10s, 20s, 40s, then every minute. A strap out of range or a
        // battery that died must not have this phone scanning flat out for the
        // rest of the shift — that costs the volunteer's own battery, which is
        // the thing keeping their GPS alive.
        const delays = [5000, 10000, 20000, 40000];
        const delay = delays[Math.min(this.reconnectAttempt, delays.length - 1)] || 60000;
        this.reconnectAttempt++;
        this.setStatus(this.t('vitals.sensor_reconnecting'), 'text-warning');
        this.reconnectTimer = setTimeout(() => {
            this.connect(deviceId).catch(() => this.scheduleReconnect());
        }, delay);
    },

    onMeasurement(view) {
        const reading = parseHeartRateMeasurement(view);
        if (!reading) return;

        // A strap that knows it has lost skin contact is reporting noise, and
        // noise here paints a red heart on the command screen. Drop it rather
        // than store it: a gap is honest, a wrong number is not.
        if (reading.contactSupported && !reading.contactDetected) return;
        if (reading.bpm < VITALS_CLIENT_MIN_BPM || reading.bpm > VITALS_CLIENT_MAX_BPM) return;

        const now = Date.now();
        const idx = vitalsWindowIndex(now, this.config.sampleSeconds);

        if (this.windowIndex === null) {
            this.windowIndex = idx;
        } else if (idx !== this.windowIndex) {
            this.closeWindow();
            this.windowIndex = idx;
        }
        this.windowValues.push(reading.bpm);

        this.lastBpm = reading.bpm;
        this.lastAt = now;
        this.renderStatus();
    },

    closeWindow() {
        const summary = summariseWindow(this.windowValues);
        this.windowValues = [];
        if (!summary) return;

        // Stamped with the START of the window, which is the instant the
        // server will reconstruct after correcting for clock skew — and, being
        // derived from the window index rather than from "now", it is the same
        // value on a retry, so the server's unique key can swallow duplicates.
        const startMs = this.windowIndex * this.config.sampleSeconds * 1000;
        this.outbox.push([startMs, summary.bpm, summary.min, summary.max]);

        if (this.outbox.length > VITALS_MAX_BUFFER) {
            this.outbox.splice(0, this.outbox.length - VITALS_MAX_BUFFER);
        }
    },

    startFlushLoop() {
        if (this.flushTimer) return;
        this.flushTimer = setInterval(() => this.flush(false), Math.max(5, this.config.flushSeconds) * 1000);
    },

    stopFlushLoop() {
        clearInterval(this.flushTimer);
        this.flushTimer = null;
    },

    /**
     * Send what has accumulated. On failure the batch stays in the outbox and
     * goes out with the next flush — a volunteer walking through a dead spot
     * should come back with their history intact, which is the entire reason
     * the server's ingest is idempotent.
     */
    async flush(isFinal) {
        if (isFinal) this.closeWindow();
        if (!this.outbox.length) return;

        const batch = this.outbox.slice(0, VITALS_MAX_BATCH_CLIENT);
        const body = new URLSearchParams({
            csrf_token: this.config.csrfToken,
            shift_id: String(this.config.shiftId),
            now: String(Date.now()),
            device: (this.device && this.device.name) || '',
            samples: JSON.stringify(batch),
        });

        // A page being closed gets one shot with sendBeacon, which survives
        // teardown where fetch() is cancelled. It reports only whether the
        // request was queued, so the outbox is cleared optimistically — losing
        // the last few seconds of a shift is a far better trade than blocking
        // the page from closing.
        if (isFinal && navigator.sendBeacon) {
            const ok = navigator.sendBeacon(this.config.endpoint, body);
            if (ok) this.outbox.splice(0, batch.length);
            return;
        }

        try {
            const res = await fetch(this.config.endpoint, { method: 'POST', body });
            const data = await res.json();
            if (data && data.ok) {
                this.outbox.splice(0, batch.length);
            }
        } catch (e) {
            // Offline or the server is unreachable — keep the batch.
        }
    },

    t(key, vars) {
        return (typeof window.t === 'function') ? window.t(key, vars || {}) : key;
    },

    savedName() {
        try { return localStorage.getItem(this.storageKey() + '_name'); } catch (e) { return null; }
    },

    setStatus(text, cls) {
        const el = document.getElementById('vitalsSensorStatus');
        if (!el) return;
        el.className = 'small ' + (cls || 'text-muted');
        el.textContent = text;
    },

    renderStatus() {
        const connectBtn = document.getElementById('vitalsConnectBtn');
        const disconnectBtn = document.getElementById('vitalsDisconnectBtn');
        const readout = document.getElementById('vitalsLiveReadout');

        if (!this.transport) {
            this.setStatus(this.t('vitals.sensor_unsupported'), 'text-muted');
            if (connectBtn) connectBtn.disabled = true;
            return;
        }

        if (connectBtn) connectBtn.classList.toggle('d-none', this.connected);
        if (disconnectBtn) disconnectBtn.classList.toggle('d-none', !this.connected);

        if (readout) {
            const show = this.connected && this.lastBpm !== null;
            readout.classList.toggle('d-none', !show);
            if (show) readout.innerHTML = '<span class="wr-hr-heart">&#9829;</span> ' + this.lastBpm + ' <small>' + this.t('vitals.unit_bpm') + '</small>';
        }

        if (this.connected) {
            const name = (this.device && this.device.name) || this.t('vitals.sensor_generic_name');
            this.setStatus(this.t('vitals.sensor_connected', { device: name }), 'text-success');
        } else if (!this.reconnectTimer) {
            this.setStatus(this.t('vitals.sensor_idle'), 'text-muted');
        }
    },
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        parseHeartRateMeasurement,
        hexToDataView,
        vitalsWindowIndex,
        summariseWindow,
        HR_SERVICE_UUID,
        HR_MEASUREMENT_UUID,
    };
}
