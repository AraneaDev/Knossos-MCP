<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use Knossos\Scanner\Protocol\Protocol;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class ProtocolTest extends TestCase
{
    public function testConstants(): void
    {
        assertSame('1.0', Protocol::VERSION);
        assertSame('1.0', Protocol::OUTPUT_SCHEMA_VERSION);
        assertSame('initialize', Protocol::METHOD_INITIALIZE);
        assertSame('discover', Protocol::METHOD_DISCOVER);
        assertSame('scan', Protocol::METHOD_SCAN);
        assertSame('cancel', Protocol::METHOD_CANCEL);
        assertSame('shutdown', Protocol::METHOD_SHUTDOWN);
    }

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(Protocol::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
