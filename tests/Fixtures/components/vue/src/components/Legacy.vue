<template>
  <button @click="save">{{ label }}</button>
</template>
<script>
const util = require('../util');
export default {
  name: 'Legacy',
  data() { return { n: 0 }; },
  mounted() { this.helper(); },
  metaInfo() { return {}; },
  props: { items: { type: Array, default() { return []; }, validator(v) { return true; } }, plain: String },
  watch: { n(value) {}, total: 'recount', deep: { handler() {} } },
  computed: { label() { return ''; } },
  methods: { save() {}, helper() {}, recount() {}, unused() {} },
  components: { Card: require('./UserCard.vue').default },
  other: obj.require('./not'),
  [computedKey]: 1,
  ...spread,
};
</script>
