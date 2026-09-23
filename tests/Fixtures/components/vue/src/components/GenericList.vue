<script setup lang="ts" generic="T extends { id: number }, K = string">
defineProps<{ items: T[] }>();
const first = (items: T[]): T | undefined => items[0];
const label: K | undefined = undefined;
</script>

<template>
  <p>{{ first }}{{ label }}</p>
</template>
