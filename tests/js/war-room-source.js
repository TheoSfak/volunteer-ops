'use strict';
// The one place the Node tests read the war-room page's source from (the PHP twin is tests/WarRoomSource.php).
// full() = the page followed by every file its script was moved to, in the order the browser runs them.
// Today that is war-room.php itself, byte for byte. A test that opens war-room.php directly would silently stop
// guarding code that moved out of it, and still pass - so do not; require this file instead.
// (Not named *.test.js on purpose: the CI glob `node --test tests/js/*.test.js` must not run it as a test.)

const fs = require('node:fs');
const path = require('node:path');

function full() {
    return fs.readFileSync(path.join(__dirname, '../../war-room.php'), 'utf8');
}

module.exports = { full };
