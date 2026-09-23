<script lang="ts">
  let { data } = $props();
  const hrefs = $derived(data.links.map((link) => link.href));
</script>

<nav>{hrefs}</nav>
