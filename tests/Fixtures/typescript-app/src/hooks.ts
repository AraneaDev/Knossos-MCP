import { useState } from "react";

export function useOrders() {
  const [orders] = useState([]);
  return orders;
}
