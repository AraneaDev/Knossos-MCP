const modules = require.context('./modules', false, /\.js$/gi);
const all = require.context('../layouts');
const broken = require.context('./modules', true, /x/q);
const alias = require.context('~/layouts', true, /\.vue$/);
export default [modules, all, broken, alias];
