export async function loadButton() {
  const { default: Button } = await import("../components/Button.svelte");
  return Button;
}
