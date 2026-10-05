<?php

declare(strict_types=1);

namespace App\Tests;

use App\Greeter;

final class GreeterTest
{
    public function testGreets(): string
    {
        return (new Greeter())->greet('test');
    }
}
