export interface Payable {
  pay(repository: UserRepository): Invoice;
}

export interface Payable {
  readonly currency?: string;
}

export type PaymentId = string & { readonly __brand: unique symbol };

export class Invoice {}

export class UserRepository {
  save(invoice: Invoice): void {}
}

