export async function loadCard() {
  const { default: Card } = await import("./components/UserCard.vue");
  return Card;
}
