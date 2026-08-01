<?php

declare(strict_types=1);

namespace Fixture;

final class Ledger
{
    public function record(string $entry): bool
    {
        return $entry !== '';
    }
}

final class Bookkeeper
{
    public function __construct(private Accountant $accountant) {}

    public function post(string $entry): bool
    {
        // A call on the result of a call on an injected collaborator: the
        // property names the type, the type names the method, the method names
        // its return type — but only the reconciler sees all three.
        return $this->accountant->ledgerFor()->record($entry);
    }
}

final class Accountant
{
    public function ledgerFor(): Ledger
    {
        return $this->ledger();
    }

    public function post(string $entry): bool
    {
        // The receiver's type is declared by the return type of the accessor,
        // and the accessor is declared *below* this call — a single-pass
        // visitor has not seen it yet when it reaches this line.
        $ledger = $this->ledger();

        return $ledger->record($entry);
    }

    public function postStatically(string $entry): bool
    {
        $ledger = self::sharedLedger();

        return $ledger->record($entry);
    }

    public function postReassigned(string $entry, Ledger $other): bool
    {
        $ledger = $this->ledger();
        // Reassignment to something untracked must invalidate the inferred
        // type rather than leave a stale edge behind.
        $ledger = $this->opaque();

        return $other->record($entry) && $ledger !== null;
    }

    public function postDirectly(string $entry): bool
    {
        // Chained: the receiver is the call itself, with no variable between.
        return $this->ledger()->record($entry);
    }

    public function postOptionally(string $entry, bool $enabled): bool
    {
        // The near-universal shape for an optional collaborator: a ternary that
        // is either a construction or null, then a nullsafe call.
        $ledger = $enabled ? new Ledger() : null;

        return $ledger?->record($entry) ?? false;
    }

    private function ledger(): Ledger
    {
        return new Ledger();
    }

    private static function sharedLedger(): Ledger
    {
        return new Ledger();
    }

    private function opaque(): mixed
    {
        return null;
    }
}
