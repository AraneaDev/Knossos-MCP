<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageWorkerPool;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[Group('language-worker-pool')]
final class LanguageWorkerPoolTest extends TestCase
{
    public function testShutdownSwallowsThrowableFromAClientShutdownAndStillClearsPool(): void
    {
        $pool = new LanguageWorkerPool();
        // A stand-in for ProcessScannerClient whose shutdown() misbehaves — the pool
        // must tolerate one bad worker without failing to shut the rest down.
        $misbehavingClient = new class {
            public function shutdown(): void
            {
                throw new RuntimeException('worker refused to die');
            }
        };

        // setAccessible() has been a no-op since PHP 8.1 and is deprecated from 8.5.
        $clients = new ReflectionProperty(LanguageWorkerPool::class, 'clients');
        $clients->setValue($pool, ['php' => $misbehavingClient]);

        $pool->shutdown();

        assertSame([], $clients->getValue($pool));
    }
}
