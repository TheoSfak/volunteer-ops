/**
 * Meshtastic serial protocol, just enough of it (v3.427.0) - the part of the
 * LoRa gateway (assets/js/mesh-gateway.js) that has to be right and can be
 * tested without a radio.
 *
 * A Meshtastic node on a USB cable speaks protobuf messages, each in a frame:
 *
 *     0x94 0xC3  <length, 2 bytes big-endian>  <protobuf, length bytes>
 *
 * Bytes between frames are the node's own debug text and are skipped. The
 * computer starts the conversation with a ToRadio message holding
 * `want_config_id`; the node answers with its settings and its node list, ends
 * them with `config_complete_id`, and from then on sends every packet it hears
 * as a FromRadio message.
 *
 * Only the fields the gateway needs are decoded, by hand, so that the page
 * needs no protobuf library and nothing from a CDN at a command post that may
 * have lost its own internet. Field numbers are from the Meshtastic protobufs
 * (mesh.proto, portnums.proto):
 *
 *   ToRadio    want_config_id = 3
 *   FromRadio  id = 1, packet = 2, my_info = 3, node_info = 4,
 *              config_complete_id = 7
 *   MeshPacket from = 1, to = 2, channel = 3, decoded = 4, encrypted = 5,
 *              id = 6, rx_time = 7, rx_snr = 8, hop_limit = 9, rx_rssi = 12,
 *              hop_start = 15
 *   Data       portnum = 1, payload = 2
 *   Position   latitude_i = 1, longitude_i = 2, altitude = 3, time = 4,
 *              ground_speed = 15, sats_in_view = 19, precision_bits = 23
 *
 * The node's settings (FromRadio.config) are never decoded or kept: they
 * contain the node's private key.
 *
 * The pure functions are exported for Node (tests/js/mesh-protocol.test.js).
 *
 * Over BLE (assets/js/mesh-ble.js) there is no 0x94 0xC3 frame at all: a GATT
 * characteristic read/write already carries exactly one message, so the same
 * ToRadio/FromRadio bytes go through MP.encodeVarint(3, id) / decodeFromRadio
 * directly - frame()/FrameReader are serial-only. See the BLE map below.
 */
(function (root) {
    'use strict';

    const START1 = 0x94;
    const START2 = 0xC3;
    // MAX_TO_FROM_RADIO_SIZE in the firmware: a longer "length" is line noise.
    const MAX_FRAME = 512;

    const PORT = {TEXT: 1, POSITION: 3, NODEINFO: 4, ALERT: 11, TELEMETRY: 67};

    // The node's GATT service and its three characteristics (from the official
    // meshtastic-sdk's transport-ble docs, not from any of their code: these are
    // just identifiers, the same way a URL or a port number is).
    const BLE = {
        SERVICE: '6ba1b218-15a8-461f-9fa8-5dcae273eafd',
        FROMRADIO: '2c55e69e-4993-11ed-b878-0242ac120002',   // read: one FromRadio per read, empty = queue drained
        TORADIO: 'f75c76d2-129e-4dad-a1dd-7866124401e7',      // write: one ToRadio per write
        FROMNUM: 'ed9da18c-a800-4f66-a670-aa7547e34453',      // notify: "something is waiting in fromradio"
    };

    // ── Wire format ─────────────────────────────────────────────────────────
    class Reader {
        constructor(bytes) {
            this.b = bytes;
            this.i = 0;
            this.dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
        }
        get done() { return this.i >= this.b.length; }
        // Low 32 bits of a varint. Every field read here fits in 32 bits; a
        // negative int32 is sent as ten bytes and its low word is the value.
        varint() {
            let lo = 0, shift = 0;
            for (let n = 0; n < 10; n++) {
                if (this.i >= this.b.length) throw new Error('truncated varint');
                const byte = this.b[this.i++];
                if (shift < 32) lo |= (byte & 0x7f) << shift;
                shift += 7;
                if (!(byte & 0x80)) return lo >>> 0;
            }
            throw new Error('varint too long');
        }
        fixed32() {
            if (this.i + 4 > this.b.length) throw new Error('truncated fixed32');
            const v = this.dv.getUint32(this.i, true);
            this.i += 4;
            return v;
        }
        take(n) {
            if (n < 0 || this.i + n > this.b.length) throw new Error('truncated field');
            const out = this.b.subarray(this.i, this.i + n);
            this.i += n;
            return out;
        }
        // The next field: {n: number, w: wire type, v: value}. v is a number for
        // varint (unsigned low word) and fixed32 (raw 32 bits), bytes otherwise.
        field() {
            const tag = this.varint();
            const n = tag >>> 3, w = tag & 7;
            if (w === 0) return {n, w, v: this.varint()};
            if (w === 5) return {n, w, v: this.fixed32()};
            if (w === 2) return {n, w, v: this.take(this.varint())};
            if (w === 1) { this.take(8); return {n, w, v: null}; }
            throw new Error('unsupported wire type ' + w);
        }
    }

    const asInt32 = v => v | 0;
    const f32 = raw => { const b = new DataView(new ArrayBuffer(4)); b.setUint32(0, raw >>> 0, true); return b.getFloat32(0, true); };

    // ── Frames ──────────────────────────────────────────────────────────────
    function frame(payload) {
        const out = new Uint8Array(4 + payload.length);
        out[0] = START1; out[1] = START2;
        out[2] = (payload.length >> 8) & 0xff; out[3] = payload.length & 0xff;
        out.set(payload, 4);
        return out;
    }

    /**
     * Cuts a stream of serial bytes into protobuf payloads. push() takes any
     * chunk, however the port happened to split it, and returns the frames
     * completed by it. Garbage between frames - the node prints debug lines - and
     * a header with an impossible length are skipped a byte at a time until the
     * next 0x94 0xC3.
     */
    class FrameReader {
        constructor() { this.buf = new Uint8Array(0); }
        push(chunk) {
            const joined = new Uint8Array(this.buf.length + chunk.length);
            joined.set(this.buf, 0);
            joined.set(chunk, this.buf.length);
            this.buf = joined;
            const frames = [];
            let pos = 0;
            for (;;) {
                while (pos < this.buf.length && this.buf[pos] !== START1) pos++;
                if (this.buf.length - pos < 4) break;
                if (this.buf[pos + 1] !== START2) { pos++; continue; }
                const len = (this.buf[pos + 2] << 8) | this.buf[pos + 3];
                if (len > MAX_FRAME) { pos++; continue; }
                if (this.buf.length - pos < 4 + len) break;
                frames.push(this.buf.slice(pos + 4, pos + 4 + len));
                pos += 4 + len;
            }
            this.buf = this.buf.slice(pos);
            return frames;
        }
    }

    // ── Decoding ────────────────────────────────────────────────────────────
    function decodePosition(bytes) {
        const r = new Reader(bytes);
        const p = {lat: null, lng: null, alt: null, time: null, precisionBits: null, groundSpeed: null, sats: null};
        let latI = null, lngI = null;
        while (!r.done) {
            const f = r.field();
            if (f.n === 1 && f.w === 5) latI = asInt32(f.v);
            else if (f.n === 2 && f.w === 5) lngI = asInt32(f.v);
            else if (f.n === 3 && f.w === 0) p.alt = asInt32(f.v);
            else if (f.n === 4 && f.w === 5) p.time = f.v;
            else if (f.n === 15 && f.w === 0) p.groundSpeed = f.v;
            else if (f.n === 19 && f.w === 0) p.sats = f.v;
            else if (f.n === 23 && f.w === 0) p.precisionBits = f.v;
        }
        // A node without a fix sends 0,0 (or nothing). Neither is a place.
        if (latI !== null && lngI !== null && !(latI === 0 && lngI === 0)) {
            p.lat = latI / 1e7;
            p.lng = lngI / 1e7;
        }
        return p;
    }

    function decodeMeshPacket(bytes) {
        const r = new Reader(bytes);
        const pkt = {
            from: null, to: null, id: null, channel: 0, rxTime: null, rxSnr: null, rxRssi: null,
            hopLimit: null, hopStart: null, portnum: null, payload: null, encrypted: false, position: null,
        };
        while (!r.done) {
            const f = r.field();
            if (f.n === 1 && f.w === 5) pkt.from = f.v;
            else if (f.n === 2 && f.w === 5) pkt.to = f.v;
            else if (f.n === 3 && f.w === 0) pkt.channel = f.v;
            else if (f.n === 4 && f.w === 2) {
                const d = new Reader(f.v);
                while (!d.done) {
                    const g = d.field();
                    if (g.n === 1 && g.w === 0) pkt.portnum = g.v;
                    else if (g.n === 2 && g.w === 2) pkt.payload = g.v;
                }
            }
            else if (f.n === 5 && f.w === 2) pkt.encrypted = true;
            else if (f.n === 6 && f.w === 5) pkt.id = f.v;
            else if (f.n === 7 && f.w === 5) pkt.rxTime = f.v;
            else if (f.n === 8 && f.w === 5) pkt.rxSnr = f32(f.v);
            else if (f.n === 9 && f.w === 0) pkt.hopLimit = f.v;
            else if (f.n === 12 && f.w === 0) pkt.rxRssi = asInt32(f.v);
            else if (f.n === 15 && f.w === 0) pkt.hopStart = f.v;
        }
        if (pkt.portnum === PORT.POSITION && pkt.payload) {
            try { pkt.position = decodePosition(pkt.payload); } catch (e) { pkt.position = null; }
        }
        return pkt;
    }

    function decodeNodeInfo(bytes) {
        const r = new Reader(bytes);
        const info = {num: null, longName: null, shortName: null, lastHeard: null, lat: null, lng: null};
        const text = b => new TextDecoder().decode(b);
        while (!r.done) {
            const f = r.field();
            if (f.n === 1 && f.w === 0) info.num = f.v;
            else if (f.n === 2 && f.w === 2) {
                const u = new Reader(f.v);
                while (!u.done) {
                    const g = u.field();
                    if (g.n === 2 && g.w === 2) info.longName = text(g.v);
                    else if (g.n === 3 && g.w === 2) info.shortName = text(g.v);
                }
            }
            else if (f.n === 3 && f.w === 2) {
                const p = decodePosition(f.v);
                info.lat = p.lat; info.lng = p.lng;
            }
            else if (f.n === 5 && f.w === 5) info.lastHeard = f.v;
        }
        return info;
    }

    /**
     * One FromRadio payload -> {type, ...}. Types: 'packet' (a MeshPacket heard
     * on the air), 'myInfo' (this node's own number), 'nodeInfo' (one entry of
     * the node's list), 'configComplete', 'other' (anything the gateway ignores,
     * settings included). A payload that cannot be read is {type: 'bad'}.
     */
    function decodeFromRadio(bytes) {
        try {
            const r = new Reader(bytes);
            while (!r.done) {
                const f = r.field();
                if (f.n === 2 && f.w === 2) return {type: 'packet', packet: decodeMeshPacket(f.v)};
                if (f.n === 3 && f.w === 2) {
                    const m = new Reader(f.v);
                    while (!m.done) {
                        const g = m.field();
                        if (g.n === 1 && g.w === 0) return {type: 'myInfo', nodeNum: g.v};
                    }
                    return {type: 'other'};
                }
                if (f.n === 4 && f.w === 2) return {type: 'nodeInfo', node: decodeNodeInfo(f.v)};
                if (f.n === 7 && f.w === 0) return {type: 'configComplete', id: f.v};
            }
            return {type: 'other'};
        } catch (e) {
            return {type: 'bad'};
        }
    }

    // ── Encoding (the one message the gateway sends; the rest is for tests) ─
    function varintBytes(value) {
        const out = [];
        let v = value >>> 0;
        while (v > 0x7f) { out.push((v & 0x7f) | 0x80); v >>>= 7; }
        out.push(v);
        return out;
    }
    function concat(parts) {
        const len = parts.reduce((n, p) => n + p.length, 0);
        const out = new Uint8Array(len);
        let o = 0;
        parts.forEach(p => { out.set(p, o); o += p.length; });
        return out;
    }
    const tagBytes = (n, w) => varintBytes((n << 3) | w);
    const encVarint = (n, v) => Uint8Array.from([...tagBytes(n, 0), ...varintBytes(v)]);
    const encFixed32 = (n, v) => {
        const b = new Uint8Array(4);
        new DataView(b.buffer).setUint32(0, v >>> 0, true);
        return concat([Uint8Array.from(tagBytes(n, 5)), b]);
    };
    const encBytes = (n, bytes) => concat([Uint8Array.from([...tagBytes(n, 2), ...varintBytes(bytes.length)]), bytes]);

    /** ToRadio{want_config_id}: asks the node to send its settings and node list. */
    function encodeWantConfig(id) {
        return frame(encVarint(3, id));
    }

    /**
     * ToRadio{heartbeat}: tells the node the computer is still there. A node on
     * a serial cable stops sending after about fifteen minutes of silence from
     * the host (the Python client sends one every five), so the gateway sends
     * one a minute.
     */
    function encodeHeartbeat() {
        return frame(Uint8Array.of(0x3a, 0x00));
    }

    /** ToRadio{disconnect}: a polite goodbye before the port is closed. */
    function encodeDisconnect() {
        return frame(Uint8Array.of(0x20, 0x01));
    }

    /** The 32 bytes of 0xC3 that wake a node that is asleep, sent before the first request. */
    function wakeBytes() {
        return new Uint8Array(32).fill(START2);
    }

    const enc = {
        varint: encVarint, fixed32: encFixed32, bytes: encBytes, concat,
        float32: (n, v) => { const b = new DataView(new ArrayBuffer(4)); b.setFloat32(0, v, true); return encFixed32(n, b.getUint32(0, true)); },
        int32: (n, v) => encVarint(n, v >>> 0),
    };

    /** Position protobuf, for building test packets. */
    function encodePosition(p) {
        const parts = [];
        if (p.lat != null) parts.push(enc.fixed32(1, Math.round(p.lat * 1e7) >>> 0));
        if (p.lng != null) parts.push(enc.fixed32(2, Math.round(p.lng * 1e7) >>> 0));
        if (p.alt != null) parts.push(enc.int32(3, p.alt));
        if (p.time != null) parts.push(enc.fixed32(4, p.time));
        if (p.groundSpeed != null) parts.push(enc.varint(15, p.groundSpeed));
        if (p.sats != null) parts.push(enc.varint(19, p.sats));
        if (p.precisionBits != null) parts.push(enc.varint(23, p.precisionBits));
        return concat(parts);
    }

    /** A MeshPacket carrying `payload` on `portnum`, wrapped in a FromRadio. */
    function encodeFromRadioPacket(p) {
        const data = concat([enc.varint(1, p.portnum), enc.bytes(2, p.payload)]);
        const parts = [enc.fixed32(1, p.from), enc.fixed32(2, p.to != null ? p.to : 0xffffffff), enc.bytes(4, data), enc.fixed32(6, p.id)];
        if (p.rxTime != null) parts.push(enc.fixed32(7, p.rxTime));
        if (p.rxSnr != null) parts.push(enc.float32(8, p.rxSnr));
        if (p.hopLimit != null) parts.push(enc.varint(9, p.hopLimit));
        if (p.rxRssi != null) parts.push(enc.int32(12, p.rxRssi));
        if (p.hopStart != null) parts.push(enc.varint(15, p.hopStart));
        return concat([enc.varint(1, p.fromRadioId || 1), enc.bytes(2, concat(parts))]);
    }

    // ── What the gateway makes of it ────────────────────────────────────────
    /** "!570dee8c": how Meshtastic shows a node number everywhere. */
    function nodeHex(num) {
        return '!' + (num >>> 0).toString(16).padStart(8, '0');
    }

    /** Hops a packet travelled: what it started with minus what is left. */
    function hopsOf(pkt) {
        return (pkt.hopStart != null && pkt.hopLimit != null && pkt.hopStart >= pkt.hopLimit)
            ? pkt.hopStart - pkt.hopLimit : null;
    }

    /**
     * A heard packet -> the record mesh-ingest.php takes, or null when it is not
     * a position with a place in it. Only positions leave the page: texts and
     * telemetry from strangers on a shared channel are none of our business.
     *
     * `nowMs` is the computer's clock. The position's own `time` (the node's
     * GPS clock) says how old the fix is; when it is missing, in the future or
     * more than two hours old the node's clock is not to be trusted and the fix
     * counts as just taken - the server drops anything older than half an hour
     * on its own account.
     */
    function positionRecord(pkt, nowMs) {
        if (!pkt || pkt.portnum !== PORT.POSITION || !pkt.position || pkt.position.lat === null) return null;
        if (pkt.from === null || pkt.id === null) return null;
        const p = pkt.position;
        let ageS = 0;
        if (p.time && p.time > 1.6e9) {
            const a = Math.round(nowMs / 1000 - p.time);
            if (a >= 0 && a <= 7200) ageS = a;
        }
        return {
            node: pkt.from >>> 0,
            id: pkt.id >>> 0,
            lat: p.lat,
            lng: p.lng,
            prec: p.precisionBits,
            age_s: ageS,
            speed: p.groundSpeed,
            snr: pkt.rxSnr,
            rssi: pkt.rxRssi,
            hops: hopsOf(pkt),
        };
    }

    const api = {
        START1, START2, MAX_FRAME, PORT, BLE,
        frame, FrameReader, encodeWantConfig, encodeHeartbeat, encodeDisconnect, wakeBytes,
        decodeFromRadio, decodeMeshPacket, decodePosition, decodeNodeInfo,
        nodeHex, hopsOf, positionRecord,
        // For tests: build the bytes a node would send.
        encodePosition, encodeFromRadioPacket, encodeFixed32: enc.fixed32, encodeVarint: enc.varint, encodeBytes: enc.bytes, concat,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.MeshProtocol = api;
})(typeof window !== 'undefined' ? window : globalThis);
