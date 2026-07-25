<?php

declare(strict_types=1);

namespace Fixture;

final class Mailer
{
    public function send(string $body): bool
    {
        return $body !== '';
    }
}

final class Dispatcher
{
    public function dispatch(string $body): bool
    {
        // The receiver is constructed and called in one expression, so there is
        // no variable whose declared or inferred type carries the call.
        return (new Mailer())->send($body);
    }

    public function anonymous(): bool
    {
        // An anonymous class has no resolvable name; the call must be skipped
        // rather than edged to a bogus target.
        return (new class {
            public function ping(): bool
            {
                return true;
            }
        })->ping();
    }
}

final class Registry
{
    public function __construct(private ?Mailer $mailer = null)
    {
    }

    public function notify(?Mailer $mailer, string $body): bool
    {
        // A nullsafe call is a distinct expression node from a plain `->` call,
        // on both a variable and a property receiver.
        return ($mailer?->send($body) ?? false)
            && ($this->mailer?->send($body) ?? false);
    }
}
