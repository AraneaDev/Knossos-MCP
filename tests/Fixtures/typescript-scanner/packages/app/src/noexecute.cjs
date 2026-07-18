require('node:fs').writeFileSync(__dirname + '/EXECUTED', 'Scanner executed project code.');

class NoExecute {}

module.exports = { NoExecute };
