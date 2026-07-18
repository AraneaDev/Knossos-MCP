import type { Payable } from '@fixture/shared/contracts.js';
import { Invoice, UserRepository } from '@fixture/shared/contracts.js';
import type { Observable } from 'rxjs';

export class PaymentService implements Payable {
  constructor(private readonly repository: UserRepository) {}

  pay(repository: UserRepository): Invoice {
    const invoice = new Invoice();
    repository.save(invoice);
    return invoice;
  }

  format(id: string): string;
  format(id: number): string;
  format(id: string | number): string {
    return String(id);
  }

  observe(value: Observable<string>): void {
    void value;
  }
}

