<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Building a TypeScript program is silent: no fact can be sent before it is
 * built, and a large one outlasted the request timeout, so the language's
 * whole scan failed. The worker says it is busy while it works, and each
 * heartbeat restarts that timeout.
 */
#[Group('typescript-scanner')]
final class TypescriptHeartbeatTest extends KnossosTestCase
{
    public function testAProgramThatTakesLongerThanTheTimeoutToBuildStillScans(): void
    {
        $client = new ProcessScannerClient(
            ['env', 'KNOSSOS_WORKER_HEARTBEAT_MS=50', 'node', self::repositoryRoot() . '/workers/typescript/bin/worker.js'],
            // Far shorter than building the fixture's program takes.
            new WorkerLimits(requestTimeoutMs: 400, maxLineBytes: 2_000_000, maxOutputBytes: 30_000_000),
        );
        try {
            $contributions = iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/typescript-app',
                'files' => ['app/page.tsx', 'src/client.ts', 'src/hooks.ts'],
                'config_files' => ['tsconfig.json'],
            ]), false);
        } finally {
            $client->shutdown();
        }

        self::assertCount(3, $contributions);
    }
}
