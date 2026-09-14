<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\OversizedBatch;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The TypeScript compiler recurses once per import while it builds a program,
 * so a long enough import chain overflows the stack. The worker runs the
 * scanner in a thread with a larger stack, keeps a program that still
 * overflows to that program's own files, and turns a thread that dies into the
 * process exit the core already knows how to read.
 *
 * The stack and heap variables are the worker's test-only knobs; the core never
 * passes them, since the supervisor starts workers with an allowlisted
 * environment.
 */
#[Group('typescript-scanner')]
final class TypescriptDeepImportChainTest extends KnossosTestCase
{
    private const STACK_MB = 'KNOSSOS_TYPESCRIPT_TEST_SCAN_THREAD_STACK_MB';
    private const HEAP_MB = 'KNOSSOS_TYPESCRIPT_TEST_SCAN_THREAD_HEAP_MB';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    /** On the default stack this chain threw out of the whole request. */
    public function testAThreeThousandFileImportChainScansWithFacts(): void
    {
        $this->writeChain(3000);

        $client = $this->typescriptWorkerClient();
        $contributions = iterator_to_array($client->scan($this->chainRequest()));
        $client->shutdown();

        $head = $this->contributionFor($contributions, 'src/m0.ts');
        assertSame([], $this->codes($head));
        assertSame(true, $head->nodes !== [], 'The head of the chain must keep its facts.');
        assertSame(true, $head->contentHash !== null);
        assertSame(false, in_array('TS_PROGRAM_TOO_DEEP', $this->allCodes($contributions), true));
    }

    /**
     * A stack too small for the chain costs that program's files only: the
     * file outside every config still gets its facts from the fallback program.
     */
    public function testAProgramThatStillOverflowsReportsItsFilesAndTheRestOfTheScanContinues(): void
    {
        $this->writeChain(1000);
        mkdir($this->root . '/other', 0o755, true);
        file_put_contents($this->root . '/other/ok.ts', "export class Ok {}\n");

        $client = $this->typescriptWorkerClient([self::STACK_MB => '0.5']);
        $contributions = iterator_to_array($client->scan($this->chainRequest(['src/m0.ts', 'other/ok.ts'])));
        $result = $client->lastScanResult();
        $client->shutdown();

        $head = $this->contributionFor($contributions, 'src/m0.ts');
        assertSame(['TS_PROGRAM_TOO_DEEP'], $this->codes($head));
        assertSame('error', $head->diagnostics[0]->severity);
        assertSame([], $head->nodes);
        assertSame([], $head->edges);
        assertSame(null, $head->contentHash, 'No facts were built from the bytes, so no hash vouches for them.');

        $other = $this->contributionFor($contributions, 'other/ok.ts');
        assertSame([], $this->codes($other));
        assertSame(true, $other->nodes !== []);
        assertSame(2, $result['files_scanned']);
        assertSame(1, $result['programs']);
    }

    /**
     * A thread out of heap reports an error event rather than V8's fatal
     * message; the worker must still exit with the text the core splits a
     * batch on.
     */
    public function testAScannerThreadOutOfHeapExitsTheWayTheCoreRetries(): void
    {
        $this->writeChain(2);

        $error = $this->scanFailure([self::HEAP_MB => '8'], $this->chainRequest(['src/m0.ts'], []));

        assertSame('WORKER_EXITED', $error->diagnosticCode);
        assertSame(true, str_contains($error->getMessage(), 'JavaScript heap out of memory'), $error->getMessage());
        assertSame(true, OversizedBatch::signalledBy($this->typescriptDescriptor(), $error, ['config_files' => []]));
    }

    /** Any other thread crash ends the process with a line saying so, never a hang. */
    public function testAScannerThreadThatCrashesEndsTheWorker(): void
    {
        $this->writeChain(2);

        $error = $this->scanFailure([self::STACK_MB => '0.25'], $this->chainRequest(['src/m0.ts'], []));

        assertSame('WORKER_EXITED', $error->diagnosticCode);
        assertSame(true, str_contains($error->getMessage(), 'TypeScript scanner thread crashed'), $error->getMessage());
        assertSame(false, OversizedBatch::signalledBy($this->typescriptDescriptor(), $error, ['config_files' => []]));
    }

    /**
     * @param array<string, string> $environment
     * @param array<string, mixed> $request
     */
    private function scanFailure(array $environment, array $request): WorkerException
    {
        $client = $this->typescriptWorkerClient($environment);
        try {
            iterator_to_array($client->scan($request));
        } catch (WorkerException $error) {
            return $error;
        } finally {
            $client->shutdown();
        }
        self::fail('The scan was expected to fail.');
    }

    private function writeChain(int $length): void
    {
        mkdir($this->root . '/src', 0o755, true);
        for ($i = 0; $i < $length; ++$i) {
            $next = $i + 1;
            file_put_contents(
                $this->root . "/src/m{$i}.ts",
                $next < $length
                    ? "import { v{$next} } from \"./m{$next}\";\nexport const v{$i} = v{$next} + 1;\n"
                    : "export const v{$i} = 0;\n",
            );
        }
        file_put_contents($this->root . '/tsconfig.json', json_encode(['include' => ['src']]));
    }

    /**
     * @param list<string> $files
     * @param list<string> $configFiles
     * @return array<string, mixed>
     */
    private function chainRequest(array $files = ['src/m0.ts'], array $configFiles = ['tsconfig.json']): array
    {
        return ['root' => $this->root, 'files' => $files, 'config_files' => $configFiles];
    }

    private function typescriptDescriptor(): LanguageDescriptor
    {
        foreach (LanguageDescriptor::defaults(self::repositoryRoot()) as $descriptor) {
            if ($descriptor->key === 'typescript') {
                return $descriptor;
            }
        }
        self::fail('No TypeScript descriptor.');
    }

    /** @return list<string> */
    private function codes(ScanContribution $contribution): array
    {
        return array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $contribution->diagnostics);
    }

    /**
     * @param array<int, ScanContribution> $contributions
     * @return list<string>
     */
    private function allCodes(array $contributions): array
    {
        return array_merge([], ...array_map($this->codes(...), array_values($contributions)));
    }

    /** @param array<int, ScanContribution> $contributions */
    private function contributionFor(array $contributions, string $relativePath): ScanContribution
    {
        foreach ($contributions as $contribution) {
            if ($contribution->ownerKey === 'knossos.typescript:file:' . $relativePath) {
                return $contribution;
            }
        }
        self::fail(sprintf('No contribution found for %s.', $relativePath));
    }
}
