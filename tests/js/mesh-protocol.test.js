// Run with: node --test tests/js
//
// assets/js/mesh-protocol.js: the Meshtastic serial protocol as the LoRa
// gateway reads it. No radio is needed - the bytes a node sends are built with
// the same field numbers (mesh.proto) and read back.

const test = require('node:test');
const assert = require('node:assert/strict');

const M = require('../../assets/js/mesh-protocol.js');

const NOW = Date.UTC(2026, 10, 2, 12, 0, 0);
const NOW_S = NOW / 1000;

function positionPacket(over = {}) {
    return M.frame(M.encodeFromRadioPacket(Object.assign({
        from: 0x570dee8c, id: 0xdeadbeef, portnum: M.PORT.POSITION,
        payload: M.encodePosition({lat: 35.3387, lng: 25.1442, alt: 120, time: NOW_S - 30, precisionBits: 32, groundSpeed: 2, sats: 9}),
        rxTime: NOW_S, rxSnr: 6.5, rxRssi: -97, hopStart: 3, hopLimit: 2,
    }, over)));
}

test('a frame is 0x94 0xC3, a big-endian length and the payload', () => {
    const f = M.frame(Uint8Array.from([1, 2, 3]));
    assert.deepEqual(Array.from(f), [0x94, 0xc3, 0, 3, 1, 2, 3]);
    const long = M.frame(new Uint8Array(300));
    assert.equal(long[2], 1);
    assert.equal(long[3], 44);
});

test('the request that starts the conversation is ToRadio{want_config_id}', () => {
    // field 3, varint: tag 0x18, then the id.
    assert.deepEqual(Array.from(M.encodeWantConfig(5)), [0x94, 0xc3, 0, 2, 0x18, 5]);
    // A large id takes more than one varint byte.
    assert.deepEqual(Array.from(M.encodeWantConfig(300)), [0x94, 0xc3, 0, 3, 0x18, 0xac, 0x02]);
});

test('the reader returns frames however the port splits the bytes', () => {
    const a = positionPacket({id: 1});
    const b = positionPacket({id: 2});
    const all = M.concat([a, b]);
    // One byte at a time.
    const r = new M.FrameReader();
    let frames = [];
    for (const byte of all) frames = frames.concat(r.push(Uint8Array.of(byte)));
    assert.equal(frames.length, 2);
    assert.equal(M.decodeFromRadio(frames[0]).packet.id, 1);
    assert.equal(M.decodeFromRadio(frames[1]).packet.id, 2);
    // Both in one chunk, and a half frame carried over to the next chunk.
    const r2 = new M.FrameReader();
    assert.equal(r2.push(all.slice(0, a.length + 5)).length, 1);
    assert.equal(r2.push(all.slice(a.length + 5)).length, 1);
});

test('debug text and a frame header with an impossible length are skipped', () => {
    const text = new TextEncoder().encode('INFO  | 12:00:00 5 [Router] got something\r\n');
    const noise = Uint8Array.from([0x94, 0xc3, 0xff, 0xff]);
    const r = new M.FrameReader();
    const frames = r.push(M.concat([text, noise, positionPacket({id: 7}), text]));
    assert.equal(frames.length, 1);
    assert.equal(M.decodeFromRadio(frames[0]).packet.id, 7);
});

test('a heard position decodes to place, node, signal and hops', () => {
    const [payload] = new M.FrameReader().push(positionPacket());
    const msg = M.decodeFromRadio(payload);
    assert.equal(msg.type, 'packet');
    const p = msg.packet;
    assert.equal(p.from, 0x570dee8c);
    assert.equal(p.id, 0xdeadbeef);
    assert.equal(p.portnum, M.PORT.POSITION);
    assert.equal(p.rxSnr, 6.5);
    assert.equal(p.rxRssi, -97);
    assert.equal(M.hopsOf(p), 1);
    assert.ok(Math.abs(p.position.lat - 35.3387) < 1e-6);
    assert.ok(Math.abs(p.position.lng - 25.1442) < 1e-6);
    assert.equal(p.position.alt, 120);
    assert.equal(p.position.precisionBits, 32);
    assert.equal(p.position.groundSpeed, 2);
    assert.equal(p.position.sats, 9);
});

test('a negative coordinate and a negative RSSI survive the round trip', () => {
    const [payload] = new M.FrameReader().push(positionPacket({
        payload: M.encodePosition({lat: -33.8688, lng: -70.6693, time: NOW_S}),
        rxRssi: -120,
    }));
    const p = M.decodeFromRadio(payload).packet;
    assert.ok(Math.abs(p.position.lat + 33.8688) < 1e-6);
    assert.ok(Math.abs(p.position.lng + 70.6693) < 1e-6);
    assert.equal(p.rxRssi, -120);
});

test('a node number is shown the way Meshtastic shows it', () => {
    assert.equal(M.nodeHex(0x570dee8c), '!570dee8c');
    assert.equal(M.nodeHex(0x1234), '!00001234');
    // 32 bits unsigned: no negative number leaks out of a high node number.
    assert.equal(M.nodeHex(0xfffffffe), '!fffffffe');
});

test('positionRecord keeps what the server needs and nothing else', () => {
    const [payload] = new M.FrameReader().push(positionPacket());
    const rec = M.positionRecord(M.decodeFromRadio(payload).packet, NOW);
    assert.equal(rec.node, 0x570dee8c);
    assert.equal(rec.id, 0xdeadbeef);
    assert.equal(rec.prec, 32);
    assert.equal(rec.age_s, 30);
    assert.equal(rec.snr, 6.5);
    assert.equal(rec.rssi, -97);
    assert.equal(rec.hops, 1);
    assert.equal(rec.speed, 2);
    assert.deepEqual(Object.keys(rec).sort(), ['age_s', 'hops', 'id', 'lat', 'lng', 'node', 'prec', 'rssi', 'snr', 'speed']);
});

test('a position with no time, or a time the node cannot know, counts as just taken', () => {
    const rec = time => {
        const [payload] = new M.FrameReader().push(positionPacket({payload: M.encodePosition({lat: 35.1, lng: 25.1, time})}));
        return M.positionRecord(M.decodeFromRadio(payload).packet, NOW);
    };
    assert.equal(rec(undefined).age_s, 0);          // no clock on the node
    assert.equal(rec(12345).age_s, 0);              // clock never set (1970)
    assert.equal(rec(NOW_S + 3600).age_s, 0);       // in the future
    assert.equal(rec(NOW_S - 3 * 3600).age_s, 0);   // older than two hours: not trusted
    assert.equal(rec(NOW_S - 90).age_s, 90);
});

test('only a position with a place in it leaves the page', () => {
    const decode = bytes => M.decodeFromRadio(new M.FrameReader().push(bytes)[0]).packet;
    // No fix: the node sends 0,0.
    assert.equal(M.positionRecord(decode(positionPacket({payload: M.encodePosition({lat: 0, lng: 0, time: NOW_S})})), NOW), null);
    // A text message is not a position.
    const text = M.frame(M.encodeFromRadioPacket({from: 1, id: 2, portnum: M.PORT.TEXT, payload: new TextEncoder().encode('hello')}));
    assert.equal(M.positionRecord(decode(text), NOW), null);
    // A packet this node could not decrypt has no portnum.
    assert.equal(M.positionRecord({portnum: null, position: null}, NOW), null);
    assert.equal(M.positionRecord(null, NOW), null);
});

test('the other FromRadio messages the gateway cares about', () => {
    // my_info{my_node_num = 0x570dee8c}
    const myInfo = M.concat([M.encodeVarint(1, 1), M.encodeBytes(3, M.encodeVarint(1, 0x570dee8c))]);
    assert.deepEqual(M.decodeFromRadio(myInfo), {type: 'myInfo', nodeNum: 0x570dee8c});
    // config_complete_id
    assert.deepEqual(M.decodeFromRadio(M.encodeVarint(7, 42)), {type: 'configComplete', id: 42});
    // node_info{num, user{long_name, short_name}, position}
    const user = M.concat([M.encodeBytes(2, new TextEncoder().encode('Meshtastic ee8')), M.encodeBytes(3, new TextEncoder().encode('ee8c'))]);
    const info = M.concat([
        M.encodeVarint(1, 0x570dee8c), M.encodeBytes(2, user),
        M.encodeBytes(3, M.encodePosition({lat: 35.5, lng: 24.0})),
        M.encodeFixed32(5, NOW_S - 600),
    ]);
    const n = M.decodeFromRadio(M.encodeBytes(4, info)).node;
    assert.equal(n.num, 0x570dee8c);
    assert.equal(n.longName, 'Meshtastic ee8');
    assert.equal(n.shortName, 'ee8c');
    assert.ok(Math.abs(n.lat - 35.5) < 1e-6);
    assert.equal(n.lastHeard, NOW_S - 600);
});

test('the node settings, which hold its private key, are never decoded', () => {
    // FromRadio.config (field 5) carrying arbitrary bytes.
    const config = M.encodeBytes(5, new TextEncoder().encode('private-key-bytes'));
    assert.deepEqual(M.decodeFromRadio(config), {type: 'other'});
});

test('bytes that are not a message do not throw', () => {
    assert.equal(M.decodeFromRadio(Uint8Array.from([0x12, 0xff, 0xff])).type, 'bad');
    assert.equal(M.decodeFromRadio(Uint8Array.from([0x12])).type, 'bad');
    assert.equal(M.decodeFromRadio(new Uint8Array(0)).type, 'other');
});

// Bytes produced by the real Meshtastic protobuf definitions (the `meshtastic`
// Python package, mesh_pb2), not by the encoder above - the one check that the
// field numbers in mesh-protocol.js are the firmware's and not only its own.
const REAL = {
    position: '080712470d8cee0d5715ffffffff221d080312190df84110151550b3fc0e187825227be86a7802980109b8012035efbeadde3d407be86a450000d0404802609fffffffffffffffff017803',
    blurred: '12280dcdab341222110803120d0d0008d0eb1578b8e0d5b8010d35630000006088ffffffffffffffff01',
    myInfo: '1a06088cddb7b805',
    configComplete: '382a',
    nodeInfo: '222f088cddb7b8051216120e4d657368746173746963206565381a04656538631a0a0dc0de281515001c4e0e2de878e86a',
    wantConfig: '18ac02',
};
const hex = s => Uint8Array.from(s.match(/../g).map(h => parseInt(h, 16)));

test('bytes written by the real Meshtastic definitions decode field for field', () => {
    const p = M.decodeFromRadio(hex(REAL.position)).packet;
    assert.equal(p.from, 0x570dee8c);
    assert.equal(p.to, 0xffffffff);
    assert.equal(p.id, 0xdeadbeef);
    assert.equal(p.rxTime, 1793620800);
    assert.equal(p.rxSnr, 6.5);
    assert.equal(p.rxRssi, -97);
    assert.equal(p.hopStart, 3);
    assert.equal(p.hopLimit, 2);
    assert.equal(p.portnum, M.PORT.POSITION);
    assert.ok(Math.abs(p.position.lat - 35.3387) < 1e-6);
    assert.ok(Math.abs(p.position.lng - 25.1442) < 1e-6);
    assert.equal(p.position.alt, 120);
    assert.equal(p.position.time, 1793620770);
    assert.equal(p.position.groundSpeed, 2);
    assert.equal(p.position.sats, 9);
    assert.equal(p.position.precisionBits, 32);

    const blurred = M.decodeFromRadio(hex(REAL.blurred)).packet;
    assert.equal(blurred.from, 0x1234abcd);
    assert.equal(blurred.rxRssi, -120);
    assert.equal(blurred.position.precisionBits, 13);
    assert.ok(Math.abs(blurred.position.lat + 33.8688) < 1e-6);

    assert.deepEqual(M.decodeFromRadio(hex(REAL.myInfo)), {type: 'myInfo', nodeNum: 0x570dee8c});
    assert.deepEqual(M.decodeFromRadio(hex(REAL.configComplete)), {type: 'configComplete', id: 42});
    const n = M.decodeFromRadio(hex(REAL.nodeInfo)).node;
    assert.equal(n.num, 0x570dee8c);
    assert.equal(n.longName, 'Meshtastic ee8');
    assert.equal(n.shortName, 'ee8c');
    assert.equal(n.lastHeard, 1793620200);
    assert.ok(Math.abs(n.lat - 35.5) < 1e-6);
});

test('the start-of-conversation request matches what the real definitions write', () => {
    const framed = M.encodeWantConfig(300);
    assert.deepEqual(Array.from(framed.slice(4)), Array.from(hex(REAL.wantConfig)));
});

test('heartbeat, goodbye and wake-up bytes match what the real Meshtastic client sends', () => {
    // ToRadio{heartbeat} = 3a 00 and ToRadio{disconnect: true} = 20 01 as the
    // Python client's protobufs serialise them; its wake-up is 32 x 0xC3.
    assert.deepEqual(Array.from(M.encodeHeartbeat()), [0x94, 0xc3, 0, 2, 0x3a, 0x00]);
    assert.deepEqual(Array.from(M.encodeDisconnect()), [0x94, 0xc3, 0, 2, 0x20, 0x01]);
    const wake = M.wakeBytes();
    assert.equal(wake.length, 32);
    assert.ok(wake.every(b => b === 0xc3));
});
