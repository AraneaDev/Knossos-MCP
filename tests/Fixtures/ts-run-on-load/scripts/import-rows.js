// Run by hand with node: no shebang, no main guard, only a call at the end.
const path = require('path');

async function main() {
    return path.join('a', 'b');
}

main().catch(error => {
    console.error(error);
    process.exitCode = 1;
});
