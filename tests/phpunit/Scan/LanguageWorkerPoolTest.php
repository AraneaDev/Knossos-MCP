<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
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

    /**
     * A ProjectScanService outlives one scan, so a worker can be cached under
     * a memory cap the next scan no longer asks for. The cap is baked into the
     * worker's command line, so only a fresh process picks up a new value.
     */
    public function testPrepareDiscardsWorkersWhenTheMemoryCapChanges(): void
    {
        $pool = new LanguageWorkerPool();
        $clients = new ReflectionProperty(LanguageWorkerPool::class, 'clients');

        $pool->prepare(new WorkerExecutionPolicy(30_000, workerMemoryMb: 1024));
        $clients->setValue($pool, ['typescript' => new class {
            public function shutdown(): void {}
        }]);
        $pool->prepare(new WorkerExecutionPolicy(30_000, workerMemoryMb: 512));

        assertSame([], $clients->getValue($pool));
    }

    /** An unchanged policy keeps the worker, including its program cache. */
    public function testPrepareKeepsWorkersWhenTheMemoryCapIsUnchanged(): void
    {
        $pool = new LanguageWorkerPool();
        $clients = new ReflectionProperty(LanguageWorkerPool::class, 'clients');
        $client = new class {
            public function shutdown(): void {}
        };

        $pool->prepare(new WorkerExecutionPolicy(30_000, workerMemoryMb: 1024));
        $clients->setValue($pool, ['typescript' => $client]);
        $pool->prepare(new WorkerExecutionPolicy(30_000, workerMemoryMb: 1024));

        assertSame(['typescript' => $client], $clients->getValue($pool));
    }

    /**
     * The descriptor default is expressed as a null cap. A first prepare() must
     * not read that null as "the cap changed" and shut down a pool that has
     * only just been primed.
     */
    public function testPrepareKeepsWorkersOnAFirstPolicyWithNoExplicitCap(): void
    {
        $pool = new LanguageWorkerPool();
        $clients = new ReflectionProperty(LanguageWorkerPool::class, 'clients');
        $client = new class {
            public function shutdown(): void {}
        };
        $clients->setValue($pool, ['php' => $client]);

        $pool->prepare(new WorkerExecutionPolicy(30_000));

        assertSame(['php' => $client], $clients->getValue($pool));
    }
}
