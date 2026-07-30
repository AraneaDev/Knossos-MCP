<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PHPUnit\Framework\Attributes\Group;

/**
 * One unscannable file must cost that file, not the whole scan.
 *
 * Every worker used to validate each requested path up front and raise a
 * request-level error, so a single file the orchestrator believed was scannable
 * — deleted between discovery and scan, oversized, or of a shape the worker
 * refuses — discarded the facts for every other file in the batch. The scan
 * reported only `WORKER_RPC_ERROR` and produced no graph at all.
 *
 * The split is deliberate: a request that cannot be interpreted (files is not a
 * list, an entry is not a string, the limits are nonsense) is still a
 * request-level error, because the orchestrator is broken rather than the tree.
 */
final class UnscannableFileTest extends KnossosTestCase
{
    use WorkerClients;

    #[Group('php-scanner')]
    public function testThePhpWorkerReportsAMissingFileAndStillScansTheRest(): void
    {
        $client = $this->phpWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/php-scanner',
            'files' => ['src/Architecture.php', 'src/Missing.php'],
        ]));
        $client->shutdown();

        assertSame(['PHP_UNSCANNABLE_FILE'], $this->diagnosticCodesFor($contributions, 'src/Missing.php'));
        assertSame(true, count($this->nodesFor($contributions, 'src/Architecture.php')) > 0);
    }

    #[Group('php-scanner')]
    public function testThePhpWorkerStillRejectsAnUninterpretableRequest(): void
    {
        $client = $this->phpWorkerClient();
        $error = self::captureThrown(
            fn() => iterator_to_array($client->scan([
                'root' => self::repositoryRoot() . '/tests/Fixtures/php-scanner',
                'files' => [1],
            ])),
            \Knossos\Scanner\Worker\WorkerException::class,
        );
        $client->shutdown();

        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
    }

    #[Group('typescript-scanner')]
    public function testTheTypescriptWorkerReportsAMissingFileAndStillScansTheRest(): void
    {
        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/mixed',
            'files' => ['frontend/src/index.ts', 'frontend/src/missing.ts'],
        ]));
        $client->shutdown();

        assertSame(['TS_UNSCANNABLE_FILE'], $this->diagnosticCodesFor($contributions, 'frontend/src/missing.ts'));
        assertSame(true, count($this->nodesFor($contributions, 'frontend/src/index.ts')) > 0);
    }

    #[Group('python-scanner')]
    public function testThePythonWorkerReportsAMissingFileAndStillScansTheRest(): void
    {
        $client = $this->pythonWorkerClient();
        $contributions = iterator_to_array($client->scan([
            'root' => self::repositoryRoot() . '/tests/Fixtures/python',
            'files' => ['shop/service.py', 'shop/missing.py'],
        ]));
        $client->shutdown();

        assertSame(['PY_UNSCANNABLE_FILE'], $this->diagnosticCodesFor($contributions, 'shop/missing.py'));
        assertSame(true, count($this->nodesFor($contributions, 'shop/service.py')) > 0);
    }

    /**
     * The diagnostic codes the worker attributed to one requested path.
     *
     * @param list<ScanContribution> $contributions
     * @return list<string>
     */
    private function diagnosticCodesFor(array $contributions, string $relativePath): array
    {
        $codes = [];
        foreach ($contributions as $contribution) {
            if (!str_ends_with($contribution->ownerKey, ':file:' . $relativePath)) {
                continue;
            }
            foreach ($contribution->diagnostics as $diagnostic) {
                $codes[] = $diagnostic->code;
            }
        }

        return $codes;
    }

    /**
     * The nodes the worker attributed to one requested path.
     *
     * @param list<ScanContribution> $contributions
     * @return list<\Knossos\Scanner\Protocol\NodeFact>
     */
    private function nodesFor(array $contributions, string $relativePath): array
    {
        foreach ($contributions as $contribution) {
            if (str_ends_with($contribution->ownerKey, ':file:' . $relativePath)) {
                return $contribution->nodes;
            }
        }

        return [];
    }
}
