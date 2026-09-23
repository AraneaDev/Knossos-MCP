<script context="module" lang="ts">
  export const prerender = true;
</script>
<script lang="ts">
  import Button from '$components/Button.svelte';
  import { format, items, load } from '$lib/format';
  import { count, increment } from '$lib/count';
  let a = 1, b = 2;
  let html = '';
  let rest = {};
  let name = '';
  let x = 0;
</script>

<svelte:head><title>{format()}</title></svelte:head>
<!-- a comment with <Hidden /> and {hidden()} -->
{#if a<b}Don't stop{:else if b > a}other{:else}none{/if}
{#each items as item, i (item.id)}<p>{item.name}</p>{/each}
{#await load() then value}{value}{:catch error}{error}{/await}
{#key x}<p>k</p>{/key}
{@html html}
{@const total = a + b}
{@debug a}
{#snippet row(v)}<b>{v}</b>{/snippet}
{@render row(1)}
<input bind:value={name} on:input={increment} {...rest} class="x {name}" />
<div class={css({
  // the header's band
  color: "x",
})}>{$count} {$$props.y}</div>
<span title={`the site's own ${name}`}>{name}</span>
<Button label={format()} />
<p>{/* it's */ a}</p>
<p>{a > b ? 'x' : 'y'}</p>
