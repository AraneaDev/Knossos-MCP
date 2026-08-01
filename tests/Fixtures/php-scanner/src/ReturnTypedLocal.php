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

final class Accountant
{
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
