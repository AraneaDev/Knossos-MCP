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
        assertArrayContains('scan', $methods);
        assertArrayContains('cancel', $methods);
        assertArrayContains('shutdown', $methods);
    }

    public function testScannerClientDeclaresNoDiscoverMethod(): void
    {
        // The scan pipeline discovers files itself; the worker-side discover was
        // three implementations with no caller.
        self::assertFalse(method_exists(ScannerClient::class, 'discover'));
    }

    public function testCancelAcceptsAnIntegerRequestId(): void
    {
        $parameter = (new \ReflectionMethod(ScannerClient::class, 'cancel'))->getParameters()[0];
        self::assertStringContainsString('int', (string) $parameter->getType());
    }
}
