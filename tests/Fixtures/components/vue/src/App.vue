<template>
  <!-- <Hidden /> {{ hidden() }} -->
  <main :class="{ active: isActive }" v-if="ready" class="plain">
    <template v-if="ready"><p>{{ formatDate() }}</p></template>
    <UserCard v-for="(user, index) in users" :key="user.id" @click="
      select(user);
      track()
    " />
    <user-list v-for="u of users" />
    <data-table v-slot="{ row }" #cell="{ value }" :rows=bare>{{ row }}</data-table>
    <b @click="broken(">x</b>
    <Nav.Item v-else-if="other" v-else />
    <svg:rect />
    <b @click
      ="split">y</b>
  </main>
</template>

<script setup lang="ts">
import UserCard from '@/components/UserCard.vue';
import UserList from './components/UserList.vue';
import DataTable from './components/DataTable.vue';
import { formatDate } from './util';
const users: { id: number }[] = [];
const ready = true;
const isActive = false;
const other = false;
const bare = 1;
const Nav = { Item: {} };
function select(user: unknown): void {}
function track(): void {}
const props = defineProps<{ title?: string }>();
const n: number = "x";
</script>

<style scoped>
main { color: red; }
</style>

<i18n>
{ "en": {} }
</i18n>
