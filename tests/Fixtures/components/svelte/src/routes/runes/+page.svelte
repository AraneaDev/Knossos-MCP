<script lang="ts">
  let { data } = $props();
  let rows = $state([{ n: 1 }, { n: 2 }]);
  const total = $derived(rows.map((r) => r.n).reduce((a, b) => a + b, 0));
  const loaded = $derived(data.items.map((item) => item.n));
</script>

<p>{total}{loaded}</p>
