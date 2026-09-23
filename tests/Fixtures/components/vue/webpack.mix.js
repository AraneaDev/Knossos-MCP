const path = require('path');
mix.webpackConfig({ resolve: { alias: { '~': path.join(__dirname, './src'), vue$: 'vue/dist/vue.esm.js', lodash: 'lodash-es' } } });
