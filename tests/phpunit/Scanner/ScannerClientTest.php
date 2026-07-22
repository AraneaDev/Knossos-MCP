<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\ScannerClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-client')]
final class ScannerClientTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $reflection = new \ReflectionClass(ScannerClient::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testDeclaresRequiredMethods(): void
    {
        $reflection = new \ReflectionClass(ScannerClient::class);
        $methods = array_map(
            static fn(\ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(),
        );

        assertArrayContains('initialize', $methods);
        assertArrayContains('discover', $methods);
        assertArrayContains('scan', $methods);
        assertArrayContains('cancel', $methods);
        assertArrayContains('shutdown', $methods);
    }
}
