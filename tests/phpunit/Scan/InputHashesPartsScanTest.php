<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * An `input_hashes` map larger than one frame, sent through the real TypeScript
 * and Python workers as `scan/input_hashes` parts.
 *
 * The map lists every file a request read, which for a TypeScript program is
 * the whole program however few files the batch names. On one result line it
 * outgrew the line cap on a program of about 9,000 files and degraded the
 * language on a tree nobody touched. Here the line cap is lowered to 300 KB so a
 * tree of 1,200 files with long names makes the same map, over 300 KB, without
 * generating tens of thousands of files.
 */
#[Group('scan')]
final class InputHashesPartsScanTest extends KnossosTestCase
{
    private const FILES = 1_200;
    private const LINE_BYTES = 300_000;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testATypeScriptProgramWhoseMapOutgrowsOneFrameScansWithoutDegrading(): void
    {
        $directory = 'src/' . str_repeat('long-directory-name-', 9);
        mkdir($this->root . '/' . $directory, 0o777, true);
        file_put_contents($this->root . '/tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}');
        $discovered = [];
        for ($i = 0; $i < self::FILES; ++$i) {
            // A language the descriptor does not claim: discovered, never
            // requested, only read by the program.
            $discovered[] = $this->write(sprintf('%s/module-%04d.ts', $directory, $i), sprintf("export const value%d = %d;\n", $i, $i), 'javascript');
        }
        $requested = $this->write('src/entry.ts', sprintf("import { value0 } from './%s/module-0000';\nexport const entry = value0;\n", substr($directory, 4)), 'typescript');
        $discovered[] = $requested;
        $descriptor = new LanguageDescriptor(key: 'typescript', stage: 'typescript-analysis', languages: ['typescript'], command: ['node', self::repositoryRoot() . '/workers/typescript/bin/worker.js']);
        $unit = new ProjectUnit('typescript', 'tsconfig.json', hash('sha256', (string) file_get_contents($this->root . '/tsconfig.json')));

        [$result, $inputHashes] = $this->runScan($descriptor, $discovered, [$unit]);

        assertSame([], $result->workerDiagnostics);
        assertSame(['src/entry.ts'], array_map(static fn($entry): string => $entry->filePath, $result->cacheEntries));
        $this->assertMapIsLargerThanOneFrameAndMatchesDiscovery($inputHashes, $discovered);
    }

    public function testAPythonRequestWhoseMapOutgrowsOneFrameScansWithoutDegrading(): void
    {
        $package = str_repeat('long_directory_name_', 9);
        mkdir($this->root . '/pkg/' . $package, 0o777, true);
        $discovered = [];
        $imports = [];
        for ($i = 0; $i < self::FILES; ++$i) {
            // Discovered, never requested: read only by the module index to
            // resolve the requested files' imports.
            $discovered[] = $this->write(sprintf('pkg/%s/module_%04d.py', $package, $i), sprintf("class Value%d:\n    pass\n", $i), 'javascript');
            // Spread over twelve importers, so no one contribution outgrows the
            // lowered line cap the way the map does.
            $imports[intdiv($i, 100)] = ($imports[intdiv($i, 100)] ?? '') . sprintf("from pkg.%s.module_%04d import Value%d\n", $package, $i, $i);
        }
        $apps = [];
        foreach ($imports as $index => $source) {
            $discovered[] = $this->write($apps[] = sprintf('app_%02d.py', $index), $source, 'python');
        }
        $descriptor = new LanguageDescriptor(key: 'python', stage: 'python-analysis', languages: ['python'], command: ['python3', '-I', '-B', self::repositoryRoot() . '/workers/python/bin/worker.py']);

        [$result, $inputHashes] = $this->runScan($descriptor, $discovered, []);

        assertSame([], $result->workerDiagnostics);
        assertSame($apps, array_map(static fn($entry): string => $entry->filePath, $result->cacheEntries));
        $this->assertMapIsLargerThanOneFrameAndMatchesDiscovery($inputHashes, $discovered);
    }

    /**
     * @param array<array-key, string|null> $inputHashes
     * @param list<DiscoveredFile> $discovered
     */
    private function assertMapIsLargerThanOneFrameAndMatchesDiscovery(array $inputHashes, array $discovered): void
    {
        // Larger than the lowered line cap, so it cannot have arrived on one line.
        assertSame(true, strlen((string) json_encode($inputHashes)) > self::LINE_BYTES);
        $expected = [];
        foreach ($discovered as $file) {
            $expected[$file->relativePath] = $file->contentHash;
        }
        $actual = [];
        $probes = [];
        foreach ($inputHashes as $path => $hash) {
            if (array_key_exists((string) $path, $expected)) {
                $actual[(string) $path] = $hash;
            } else {
                // Candidates probed and found absent, which discovery never reports.
                $probes[] = $hash;
            }
        }
        ksort($expected);
        ksort($actual);
        assertSame($expected, $actual);
        assertSame([], array_values(array_filter($probes, static fn(mixed $hash): bool => $hash !== null)));
    }

    private function write(string $relativePath, string $contents, string $language): DiscoveredFile
    {
        $absolute = $this->root . '/' . $relativePath;
        file_put_contents($absolute, $contents);

        return new DiscoveredFile($relativePath, $absolute, $language, strlen($contents), (int) filemtime($absolute), hash('sha256', $contents));
    }

    /**
     * @param list<DiscoveredFile> $discovered
     * @param list<ProjectUnit> $units
     * @return array{LanguageScanResult, array<array-key, string|null>}
     */
    private function runScan(LanguageDescriptor $descriptor, array $discovered, array $units): array
    {
        $client = new ProcessScannerClient($descriptor->command, new WorkerLimits(requestTimeoutMs: 120_000, maxLineBytes: self::LINE_BYTES));
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturn($client);
        $runner = new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService());
        $plan = new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: new DiscoveryResult(rootRealpath: (string) realpath($this->root), files: $discovered, units: $units, diagnostics: [], inputHash: '', configurationHash: ''),
                maxFiles: 100_000,
                maxFileBytes: 1_000_000,
                explicitBoundaries: [],
                requestedMode: 'full',
                snapshotRetention: 0,
                executionPolicy: new WorkerExecutionPolicy(),
                laravel: false,
                symfony: false,
                configurationHashes: ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py'],
                configurationMilliseconds: 0.0,
                discoveryMilliseconds: 0.0,
                planningMilliseconds: 0.0,
            ),
            projectId: 'input-hashes-parts',
            effectiveMode: 'full',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        try {
            $result = $runner->run($plan, new CancellationToken());

            return [$result, $client->lastScanResult()['input_hashes'] ?? []];
        } finally {
            $client->shutdown();
        }
    }
}
