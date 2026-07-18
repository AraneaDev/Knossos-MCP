import { useOrders } from "../src/hooks";

export default function OrdersPage() {
  useOrders();
  return <main>Orders</main>;
}
