import { OrderService } from '../service.js';

export function checkTotal(): boolean {
  return new OrderService().total([1, 2]) === 3;
}
