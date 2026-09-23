<script lang="ts" generics="T extends { id: number }">
  let { items, pick }: { items: T[]; pick: (item: T) => void } = $props();
  const first: T | undefined = items[0];
</script>

{#each items as item (item.id)}
  <button onclick={() => pick(item)}>{item.id}{first?.id}</button>
{/each}
