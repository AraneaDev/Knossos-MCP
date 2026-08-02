<?php

declare(strict_types=1);

// Deliberately unnamespaced: this mirrors a procedural tools script, which is
// the shape whose file-scope calls were being dropped.

function knossosFixtureBootstrap(): void {}

class KnossosFixtureBootstrapper
{
    public function boot(): void {}
}

knossosFixtureBootstrap();
(new KnossosFixtureBootstrapper())->boot();
