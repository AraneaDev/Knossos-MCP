<template><p>{{ total }}</p></template>
<script lang="ts">
import { defineComponent } from 'vue';
export default defineComponent({ computed: { total(): number { return 1; } } });
</script>
