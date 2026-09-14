<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\LanguageWorkerPool;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scan\ScanSnapshotValidator;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Manifests and configuration files discovery hashes as project units are
 * verified like source files: a worker's read of one is checked against
 * discovery's hash, and one still changed when the workers return fails the
 * post-worker snapshot check.
 *
 * Before this, the core ignored `input_hashes` keys for anything but a
 * discovered source file, so a package.json changed while module resolution
 * read it and restored before validation left facts resolved from bytes no
 * stored hash describes in a graph reported fresh.
 */
#[Group('scan')]
final class ProjectUnitVerificationTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testHashedPathsCoverFilesUnitsAndUnparsedManifestsWithTheFileKept(): void
    {
        $discovery = new DiscoveryResult(
            rootRealpath: $this->root,
            files: [new DiscoveredFile('vite.config.ts', $this->root . '/vite.config.ts', 'typescript', 1, 0, 'file-hash')],
            units: [new ProjectUnit('tool_config', 'vite.config.ts', 'unit-hash'), new ProjectUnit('node', 'package.json', 'node-hash')],
            diagnostics: [],
            inputHash: '',
            configurationHash: '',
            unparsedManifestHashes: ['broken/package.json' => 'broken-hash'],
        );

        $hashes = array_map(static fn(object $hashed): string => $hashed->contentHash, $discovery->hashedPaths());
        ksort($hashes);

        assertSame(['broken/package.json' => 'broken-hash', 'package.json' => 'node-hash', 'vite.config.ts' => 'file-hash'], $hashes);
    }

    public function testDiscoveryKeepsTheHashOfAManifestThatDoesNotParse(): void
    {
        $this->write('package.json', '{"name": ');
        $this->write('composer.json', '{"name": "fixture/ok"}');

        $discovery = $this->discover();

        assertSame(['package.json' => hash('sha256', '{"name": ')], $discovery->unparsedManifestHashes);
        assertSame(['composer.json'], array_map(static fn(ProjectUnit $unit): string => $unit->configPath, $discovery->units));
    }

    /**
     * The reproduction: package.json is changed before the TypeScript worker's
     * module resolution reads it and restored before anything re-reads it.
     * Nothing but the worker's own report of the bytes it read can tell.
     */
    public function testAPackageJsonChangedAndRestoredAroundModuleResolutionFailsTheScan(): void
    {
        $this->typescriptTree();
        $discovery = $this->discover();
        $original = (string) file_get_contents($this->root . '/package.json');

        file_put_contents($this->root . '/package.json', '{"type": "commonjs"}' . "\n");
        try {
            $error = captureThrows(fn() => $this->runTypescript($discovery), ScanSnapshotChangedException::class);
        } finally {
            file_put_contents($this->root . '/package.json', $original);
        }

        assertSame(ScanSnapshotChangedException::inputReadDifferently('package.json')->getMessage(), $error->getMessage());
        // Restored: the post-worker check alone would have passed this tree.
        (new ScanSnapshotValidator())->validateDiscovery($this->discover());
    }

    public function testATsconfigChangedAndRestoredAroundTheWorkersReadFailsTheScan(): void
    {
        $this->typescriptTree();
        $discovery = $this->discover();
        $original = (string) file_get_contents($this->root . '/tsconfig.json');

        file_put_contents($this->root . '/tsconfig.json', str_replace('"src"', '"src", "other"', $original));
        try {
            $error = captureThrows(fn() => $this->runTypescript($discovery), ScanSnapshotChangedException::class);
        } finally {
            file_put_contents($this->root . '/tsconfig.json', $original);
        }

        assertSame(ScanSnapshotChangedException::inputReadDifferently('tsconfig.json')->getMessage(), $error->getMessage());
    }

    /** A Cargo.toml changed and restored around the Rust worker's read of it, for the crate name. */
    public function testACargoManifestChangedAndRestoredAroundTheRustWorkersReadFailsTheScan(): void
    {
        $this->write('rust/Cargo.toml', "[package]\nname = \"units\"\n");
        $this->write('rust/src/lib.rs', "pub fn units() {}\n");
        $discovery = $this->discover();
        $original = (string) file_get_contents($this->root . '/rust/Cargo.toml');

        file_put_contents($this->root . '/rust/Cargo.toml', "[package]\nname = \"renamed\"\n");
        try {
            $error = captureThrows(
                fn() => $this->runLanguage($discovery, new LanguageDescriptor(key: 'rust', stage: 'rust-analysis', languages: ['rust'], command: [self::rustWorkerBinary()])),
                ScanSnapshotChangedException::class,
            );
        } finally {
            file_put_contents($this->root . '/rust/Cargo.toml', $original);
        }

        assertSame(ScanSnapshotChangedException::inputReadDifferently('rust/Cargo.toml')->getMessage(), $error->getMessage());
    }

    /** The same tree left alone scans with the real worker and nothing degrades. */
    public function testAnUnchangedPackageJsonAndTsconfigScanWithTheRealWorker(): void
    {
        $this->typescriptTree();

        $result = $this->runTypescript($this->discover());

        assertSame([], $result->workerDiagnostics);
        assertSame(['src/a.ts', 'sub/s.ts'], array_map(static fn($entry): string => $entry->filePath, $result->cacheEntries));
    }

    /**
     * A manifest still changed when every worker has returned fails the
     * post-worker check with the wording a source file gets, for a unit the
     * TypeScript worker reads and one the Rust worker reads.
     */
    public function testAManifestStillChangedWhenTheWorkersReturnFailsTheScan(): void
    {
        $this->stableTreeWithEveryUnitKind();
        foreach (['tsconfig.json', 'rust/Cargo.toml'] as $manifest) {
            $pdo = $this->freshTestDatabase();
            $path = $this->root . '/' . $manifest;
            $original = (string) file_get_contents($path);
            $scanPolls = 0;
            $token = new CancellationToken(function () use (&$scanPolls, $path, $original): bool {
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3] ?? [];
                if (($caller['class'] ?? null) === ProjectScanService::class && ++$scanPolls >= 3) {
                    file_put_contents($path, $original . "\n");
                }

                return false;
            });

            try {
                $error = captureThrows(
                    fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, cancellation: $token),
                    ScanSnapshotChangedException::class,
                );
            } finally {
                file_put_contents($path, $original);
            }

            assertSame(ScanSnapshotChangedException::contentChanged($manifest)->getMessage(), $error->getMessage());
            assertSame(3, $scanPolls);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
        }
    }

    /** The no-change fast path runs no worker, so only the post-worker check can see a manifest move. */
    public function testTheFastPathFailsOnAManifestChangedDuringTheScan(): void
    {
        $this->stableTreeWithEveryUnitKind();
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $service->scan($this->root);
        $this->backdateDirectories($this->root, 10);
        assertSame('no_change', $service->scan($this->root)->data['fast_path']);
        $path = $this->root . '/composer.json';
        $original = (string) file_get_contents($path);
        $polls = 0;
        $token = new CancellationToken(function () use (&$polls, $path, $original): bool {
            if (++$polls > 1) {
                file_put_contents($path, $original . "\n");
            }

            return false;
        });

        $error = captureThrows(fn() => $service->scan($this->root, cancellation: $token), ScanSnapshotChangedException::class);

        assertSame(ScanSnapshotChangedException::contentChanged('composer.json')->getMessage(), $error->getMessage());
    }

    /** A unit that vanished, and a manifest that did not parse and then changed, both fail validation. */
    public function testValidationCoversRemovedUnitsAndUnparsedManifests(): void
    {
        $this->write('package.json', '{"name": ');
        $this->write('pyproject.toml', "[project]\nname = \"app\"\n");
        $discovery = $this->discover();
        $validator = new ScanSnapshotValidator();
        $validator->validateDiscovery($discovery);

        file_put_contents($this->root . '/package.json', '{"name": "x"}');
        $changed = captureThrows(fn() => $validator->validateDiscovery($discovery), ScanSnapshotChangedException::class);
        assertSame(ScanSnapshotChangedException::contentChanged('package.json')->getMessage(), $changed->getMessage());

        file_put_contents($this->root . '/package.json', '{"name": ');
        unlink($this->root . '/pyproject.toml');
        $removed = captureThrows(fn() => $validator->validateDiscovery($discovery), ScanSnapshotChangedException::class);
        assertSame(ScanSnapshotChangedException::disappeared('pyproject.toml')->getMessage(), $removed->getMessage());
    }

    /**
     * Every unit kind a bundled worker can read, on a tree nobody touches: the
     * scan completes in full, degrades no language, and a rescan takes the
     * fast path, so verifying units costs a stable tree nothing.
     */
    public function testAStableTreeWithEveryUnitKindScansFullWithNothingDegraded(): void
    {
        $this->stableTreeWithEveryUnitKind();
        $pdo = $this->freshTestDatabase();
        $service = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);

        $first = $service->scan($this->root);
        $this->backdateDirectories($this->root, 10);
        $second = $service->scan($this->root);

        assertSame('full', $first->data['mode']);
        assertSame([], $first->data['degraded_languages']);
        assertSame(true, $first->data['parsed_files'] >= 5);
        $units = array_map(static fn(ProjectUnit $unit): string => $unit->configPath, $this->discover()->units);
        sort($units);
        assertSame(['composer.json', 'package.json', 'pyproject.toml', 'rust/Cargo.toml', 'tsconfig.json'], $units);
        assertSame('no_change', $second->data['fast_path']);
        assertSame([], $second->data['degraded_languages']);
    }

    private function typescriptTree(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"module": "nodenext", "moduleResolution": "nodenext"}, "include": ["src", "sub"]}' . "\n");
        $this->write('package.json', '{"type": "module"}' . "\n");
        $this->write('src/a.ts', "import { s } from '../sub/s.js';\nexport const a = s;\n");
        $this->write('sub/s.ts', "export const s = 1;\n");
    }

    private function stableTreeWithEveryUnitKind(): void
    {
        $this->typescriptTree();
        $this->write('composer.json', '{"name": "fixture/units", "autoload": {"psr-4": {"Fixture\\\\": "php/"}}}' . "\n");
        $this->write('php/Service.php', "<?php\n\nnamespace Fixture;\n\nfinal class Service {}\n");
        $this->write('pyproject.toml', "[project]\nname = \"units\"\n");
        $this->write('app.py', "class App:\n    pass\n");
        $this->write('rust/Cargo.toml', "[package]\nname = \"units\"\nversion = \"0.1.0\"\n");
        $this->write('rust/src/lib.rs', "pub fn units() {}\n");
    }

    private function write(string $relativePath, string $contents): void
    {
        $absolute = $this->root . '/' . $relativePath;
        @mkdir(dirname($absolute), 0o777, true);
        file_put_contents($absolute, $contents);
    }

    private function discover(): DiscoveryResult
    {
        return (new ProjectDiscoverer(new DiscoveryConfig([$this->root])))->discover($this->root);
    }

    private function runTypescript(DiscoveryResult $discovery): LanguageScanResult
    {
        return $this->runLanguage($discovery, new LanguageDescriptor(key: 'typescript', stage: 'typescript-analysis', languages: ['typescript'], command: ['node', self::repositoryRoot() . '/workers/typescript/bin/worker.js']));
    }

    private function runLanguage(DiscoveryResult $discovery, LanguageDescriptor $descriptor): LanguageScanResult
    {
        $client = new ProcessScannerClient($descriptor->command, new WorkerLimits(requestTimeoutMs: 60_000));
        $pool = $this->createStub(LanguageWorkerPool::class);
        $pool->method('client')->willReturn($client);
        $plan = new ScanPlan(
            preparation: new ScanPreparation(
                configuration: new ProjectConfiguration(),
                discovery: $discovery,
                maxFiles: 100_000,
                maxFileBytes: 2_000_000,
                explicitBoundaries: [],
                requestedMode: 'full',
                snapshotRetention: 0,
                executionPolicy: new WorkerExecutionPolicy(),
                laravel: false,
                symfony: false,
                configurationHashes: ['php' => 'cfg-php', 'typescript' => 'cfg-ts', 'python' => 'cfg-py', 'rust' => 'cfg-rs'],
                configurationMilliseconds: 0.0,
                discoveryMilliseconds: 0.0,
                planningMilliseconds: 0.0,
            ),
            projectId: 'project-unit-verification',
            effectiveMode: 'full',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        try {
            return (new LanguageScanRunner([$descriptor], $pool, new ContributionCacheService()))->run($plan, new CancellationToken());
        } finally {
            $client->shutdown();
        }
    }
}
