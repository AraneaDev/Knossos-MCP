export class OrderService {
  total(amounts: number[]): number {
    return amounts.reduce((sum, amount) => sum + amount, 0);
  }
}
