<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class WorkerExceptionTest extends TestCase
{
    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(WorkerException::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testConstructorStoresDiagnosticCode(): void
    {
        $e = new WorkerException('WORKER_TOO_SLOW', 'Worker timed out.');

        assertSame('WORKER_TOO_SLOW', $e->diagnosticCode);
        assertSame('Worker timed out.', $e->getMessage());
        assertSame(0, $e->getCode());
        assertSame(null, $e->getPrevious());
    }

    public function testConstructorAcceptsPreviousException(): void
    {
        $previous = new \RuntimeException('root cause');
        $e = new WorkerException('WORKER_FAILED', 'msg', $previous);

        assertSame($previous, $e->getPrevious());
    }

    public function testExtendsRuntimeException(): void
    {
        $e = new WorkerException('C', 'm');
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }
}
