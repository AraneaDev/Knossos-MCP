export async function loadOrders() {
  await fetch("/api/orders");
  await fetch("/api/orders", { method: "POST" });
}
