<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Sdk\ConformanceArgumentAnchor;
use Knossos\Scanner\Sdk\FixtureBuilder;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ScannerSdkTest extends KnossosTestCase
{
    #[Group('scanner-sdk')]
    public function testScannerSdkFixturesCapabilitiesSchemasAndConformanceRunnerAgree(): void
    {
        $node = FixtureBuilder::node('demo:class:Checkout', 'class', 'Demo\\Checkout', 'Checkout', 'src/Checkout.demo', 2, 4);
        $edge = FixtureBuilder::edge('calls', 'demo:function:run', 'demo:class:Checkout', 'src/Checkout.demo', 6);
        $contribution = FixtureBuilder::contribution('demo:file:src/Checkout.demo', [$node], [$edge]);
        $decoded = \Knossos\Scanner\Worker\ContributionDecoder::decode($contribution);
        assertSame('Demo\\Checkout', $decoded->nodes[0]->canonicalName);
        assertSame('calls', $decoded->edges[0]->kind);

        foreach (['manifest.schema.json', 'contribution.schema.json'] as $schema) {
            $decodedSchema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/' . $schema), true, 512, JSON_THROW_ON_ERROR);
            assertSame('https://json-schema.org/draft/2020-12/schema', $decodedSchema['$schema']);
        }
        $golden = json_decode((string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/scanner-sdk/golden.json'), true, 512, JSON_THROW_ON_ERROR);
        assertSame(Protocol::VERSION, $golden['initialize']['protocol_version']);

        $client = $this->fakeWorkerClient('compliant');
        assertSame('knossos.fake', $client->requireCapabilities(['partial_ast'])->id);
        $client->shutdown();
        $error = captureThrows(fn() => $this->fakeWorkerClient('compliant')->requireCapabilities(['incremental']), WorkerException::class);
        assertSame('WORKER_CAPABILITY_MISMATCH', $error->diagnosticCode);

        [$exitCode, $report] = $this->runConformance(['--require=partial_ast'], 'compliant');
        if ($exitCode !== 0) {
            throw new RuntimeException('Conformance runner failed: ' . json_encode($report));
        }
        assertSame(true, $report['conformant']);
        assertSame(['initialize', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
    }

    /**
     * @param list<string> $options
     * @param list<string> $extraArguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runConformance(array $options, string $mode, bool $relativeWorkerPath = false, array $extraArguments = []): array
    {
        $worker = 'tests/Fixtures/workers/fake-worker.php';
        $process = proc_open([
            PHP_BINARY,
            self::repositoryRoot() . '/tools/scanner-conformance',
            ...$options,
            '--',
            PHP_BINARY,
            $relativeWorkerPath ? $worker : self::repositoryRoot() . '/' . $worker,
            $mode,
            ...$extraArguments,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::repositoryRoot());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start scanner conformance runner.');
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, json_decode($stdout === false ? '' : $stdout, true, 512, JSON_THROW_ON_ERROR)];
    }

    #[Group('scanner-sdk')]
    public function testConformanceChecksTheContentHashOfAWorkerThatDeclaresIt(): void
    {
        [$exit, $report] = $this->runConformance([], 'hash_honest');
        assertSame(0, $exit);
        assertSame(['initialize', 'empty_scan', 'content_hash', 'shutdown'], array_column($report['checks'], 'name'));

        [$exit, $report] = $this->runConformance([], 'hash_missing');
        assertSame(1, $exit);
        assertSame(false, $report['conformant']);
        assertSame('fail', array_column($report['checks'], 'status', 'name')['content_hash']);

        // The fixture starts with a byte-order mark, so hashing decoded text fails.
        [$exit, $report] = $this->runConformance([], 'hash_decoded');
        assertSame(1, $exit);
        assertSame('fail', array_column($report['checks'], 'status', 'name')['content_hash']);

        // A worker that does not declare it is not asked.
        [$exit, $report] = $this->runConformance([], 'compliant');
        assertSame(0, $exit);
        assertSame(['initialize', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
    }

    #[Group('scanner-sdk')]
    public function testConformanceChecksTheInputHashesOfAWorkerThatDeclaresThem(): void
    {
        [$exit, $report] = $this->runConformance([], 'inputs_honest');
        assertSame(0, $exit, json_encode($report, JSON_THROW_ON_ERROR));
        assertSame(true, $report['conformant']);
        assertSame(
            ['initialize', 'empty_scan', 'input_hashes_empty', 'content_hash', 'input_hashes', 'shutdown'],
            array_column($report['checks'], 'name'),
        );

        [$exit, $report] = $this->runConformance([], 'inputs_missing');
        assertSame(1, $exit);
        assertSame(false, $report['conformant']);
        $statusByName = array_column($report['checks'], 'status', 'name');
        assertSame('fail', $statusByName['input_hashes_empty']);
        assertSame('fail', $statusByName['input_hashes']);
        // The missing behaviour is scoped to input_hashes; content_hash is unaffected.
        assertSame('pass', $statusByName['content_hash']);

        // The fixture's own hash matches, but another key is one the core
        // refuses, so the map fails its check against the fixture's discovery.
        [$exit, $report] = $this->runConformance([], 'inputs_escaping_key');
        assertSame(1, $exit);
        $statusByName = array_column($report['checks'], 'status', 'name');
        assertSame('pass', $statusByName['input_hashes_empty']);
        assertSame('pass', $statusByName['content_hash']);
        assertSame('fail', $statusByName['input_hashes']);
    }

    /**
     * The worker runs with the system temporary directory as its working
     * directory (not the fixture root the conformance tool creates inside it),
     * so the documented `-- python3 worker.py` form only works because the
     * tool anchors a relative path to the directory it was started from.
     */
    #[Group('scanner-sdk')]
    public function testConformanceAcceptsAWorkerPathRelativeToTheCallersDirectory(): void
    {
        [$exit, $report] = $this->runConformance([], 'hash_honest', relativeWorkerPath: true);

        assertSame(0, $exit, json_encode($report, JSON_THROW_ON_ERROR));
        assertSame(true, $report['conformant']);
        assertSame(['initialize', 'empty_scan', 'content_hash', 'shutdown'], array_column($report['checks'], 'name'));
    }

    #[Group('scanner-sdk')]
    public function testConformanceLeavesAnEmptyOrBareArgumentUnanchored(): void
    {
        // An extra argument the mode ignores still must not crash the runner
        // when it is empty or names nothing anchoring would touch.
        [$exit, $report] = $this->runConformance([], 'hash_honest', extraArguments: ['']);
        assertSame(0, $exit, json_encode($report, JSON_THROW_ON_ERROR));
        assertSame(true, $report['conformant']);
    }

    #[Group('scanner-sdk')]
    public function testConformanceArgumentAnchorRewritesOnlyArgumentsThatLookLikePaths(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-anchor-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        mkdir($directory . '/sub');
        touch($directory . '/worker.php');
        touch($directory . '/plainname');
        touch($directory . '/sub/worker.php');
        try {
            // Every case below places the argument under test after another
            // one (as `php worker.php mode` does), not alone: a command's
            // first element gets special treatment nowhere in this rule, but
            // did in the version this replaces, so testing only a one-element
            // command would miss that regression entirely.
            $anchored = static fn(string $argument): string => ConformanceArgumentAnchor::anchor(['php', $argument], $directory)[1];

            // An empty string is not a path: file_exists($directory . '/') is
            // true for any existing directory, so a naive check would rewrite
            // it to the directory itself.
            assertSame('', $anchored(''));
            // A bare token with no slash and no recognised script extension is
            // left alone even when it happens to name a real entry: only the
            // worker resolves what its own arguments mean.
            assertSame('plainname', $anchored('plainname'));
            // A flag is never anchored, whatever it happens to match.
            assertSame('-plainname', $anchored('-plainname'));
            // A flag's value is anchored under the same rule as an argument.
            assertSame('--config=' . $directory . '/sub/worker.php', $anchored('--config=sub/worker.php'));
            assertSame('--script=' . $directory . '/worker.php', $anchored('--script=worker.php'));
            assertSame('--mode=plainname', $anchored('--mode=plainname'));
            assertSame('--config=missing/worker.php', $anchored('--config=missing/worker.php'));
            assertSame('--config=/worker.php', $anchored('--config=/worker.php'));
            assertSame('--config=', $anchored('--config='));
            assertSame('--flag', $anchored('--flag'));
            // Only a flag's value is split off: a plain argument with an `=` in
            // it is one path, which names nothing here.
            assertSame('name=sub/worker.php', $anchored('name=sub/worker.php'));
            // A relative path containing a slash is anchored when it exists there.
            assertSame($directory . '/sub/worker.php', $anchored('sub/worker.php'));
            // A bare name ending in a recognised script extension is anchored too.
            assertSame($directory . '/worker.php', $anchored('worker.php'));
            // An already-absolute path is never rewritten.
            assertSame('/worker.php', $anchored('/worker.php'));
            // A path-shaped argument that names nothing in the directory is left alone.
            assertSame('missing.php', $anchored('missing.php'));
        } finally {
            @unlink($directory . '/sub/worker.php');
            @rmdir($directory . '/sub');
            @unlink($directory . '/worker.php');
            @unlink($directory . '/plainname');
            @rmdir($directory);
        }
    }
}
