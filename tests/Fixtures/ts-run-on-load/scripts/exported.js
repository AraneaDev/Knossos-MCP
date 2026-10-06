// Calls itself on load, but exports: another file can require it.
const path = require('path');

function run() {
    return path.sep;
}

run();
module.exports = { run };
