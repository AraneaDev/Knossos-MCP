<?php

declare(strict_types=1);

namespace Fixture;

interface Payable
{
    public function pay(UserRepository $repository): Invoice;
}

trait LogsPayments
{
    private static function audit(): void
    {
    }
}

final readonly class PaymentService implements Payable
{
    use LogsPayments;

    public function __construct(private UserRepository $repository)
    {
    }

    public function pay(UserRepository $repository): Invoice
    {
        $invoice = new Invoice();
        $repository->save($invoice);
        self::audit();

        return $invoice;
    }
}

final class UserRepository
{
    public function save(Invoice $invoice): void
    {
    }
}

final class Invoice
{
}

function runPayment(PaymentService $service): void
{
    $service->pay(new UserRepository());
}

