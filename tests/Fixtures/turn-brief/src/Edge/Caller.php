<?php

declare(strict_types=1);

namespace App;

final class Caller
{
    public function run(): string
    {
        return (new \App\Greeter())->greet('world');
    }
}
