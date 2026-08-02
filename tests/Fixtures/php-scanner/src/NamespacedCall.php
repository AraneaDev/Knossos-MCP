<?php

declare(strict_types=1);

namespace Fixture;

function localHelper(): void {}

final class NamespacedCaller
{
    public function run(): void
    {
        // Unqualified inside a namespace: PHP prefers Fixture\localHelper and
        // falls back to a global localHelper only if that does not exist.
        localHelper();
        // Unqualified, and this namespace declares no such function, so PHP
        // does fall back to the global one.
        absentHelper();
        // Already unambiguous, and must stay that way.
        \strlen('x');
    }
}
