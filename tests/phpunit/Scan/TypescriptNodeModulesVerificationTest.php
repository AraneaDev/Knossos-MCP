<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanRunner;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionProperty;

/**
 * The TypeScript worker reports the `node_modules` files it reads, and the
 * scan re-reads them before it commits, end to end through the real worker.
 *
 * Discovery never hashes a `node_modules` file, so only the worker's report of
 * the bytes it read and the pre-commit re-read can tell that a declaration an
 * import resolved through changed and was restored during the scan.
 *
 * TypeScript is forced to one file per request, so the same package paths are
 * reported by several requests, each reaching them differently: a bare import
 * probes and realpaths them through a pnpm-style link, a `/// <reference>` reads
 * them through the link's name. The core fails a scan whose requests report one
 * path with two values, and re-reads every reported path at commit, so a stable
 * tree only scans if every request agrees and each value is the one its path
 * has: a directory link read below goes in as null, never as the hash of the
 * file below it.
 */
#[Group('scan')]
final class TypescriptNodeModulesVerificationTest extends KnossosTestCase
{
    private const PNPM = 'node_modules/.pnpm/dep@1.0.0/node_modules/dep';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->write('src/a.ts', "import { Dep } from 'dep';\nexport class A extends Dep {}\n");
        $this->write('src/b.ts', "/// <reference path=\"../node_modules/dep/index.d.ts\" />\nexport const b = 1;\n");
        $this->write('src/c.ts', "/// <reference path=\"../node_modules/dep/extra.d.ts\" />\nexport const c = 1;\n");
        $this->write('src/d.ts', "import type { Dep } from 'dep';\nexport type D = Dep;\n");
        $this->write('src/e.ts', "import { Plain } from 'plain';\nexport class E extends Plain {}\n");
        $this->write(self::PNPM . '/package.json', '{"name": "dep", "version": "1.0.0", "types": "index.d.ts"}' . "\n");
        $this->write(self::PNPM . '/index.d.ts', "export declare class Dep {}\n");
        $this->write(self::PNPM . '/extra.d.ts', "export declare class Extra {}\n");
        symlink('.pnpm/dep@1.0.0/node_modules/dep', $this->root . '/node_modules/dep');
        $this->write('node_modules/plain/package.json', '{"name": "plain", "types": "index.d.ts"}' . "\n");
        $this->write('node_modules/plain/index.d.ts', "export declare class Plain {}\n");
        // A directory link followed by `..`: only the link itself is keyed
        // below the name as written, and it names a directory, whatever was read.
        $this->write('src/f.ts', "/// <reference path=\"../node_modules/entry.d.ts\" />\nexport const f = 1;\n");
        symlink('.pnpm/dep@1.0.0/node_modules', $this->root . '/node_modules/scope');
        symlink('scope/../node_modules/dep/extra.d.ts', $this->root . '/node_modules/entry.d.ts');
        // A link to a file, read as itself in one request and walked below as
        // if it were a directory in another: both must key it by that file.
        $this->write('src/g.ts', "/// <reference path=\"../node_modules/types.d.ts\" />\nexport const g = 1;\n");
        $this->write('src/h.ts', "/// <reference path=\"../node_modules/types.d.ts/x.d.ts\" />\nexport const h = 1;\n");
        symlink('plain/index.d.ts', $this->root . '/node_modules/types.d.ts');
        // A FIFO a reference reaches, directly and through a link: opening it
        // for reading would block the worker, so both are reported as null.
        $this->write('src/i.ts', "/// <reference path=\"../node_modules/pipe.d.ts\" />\n/// <reference path=\"../node_modules/piped.d.ts\" />\nexport const i = 1;\n");
        if (!function_exists('posix_mkfifo') || !posix_mkfifo($this->root . '/node_modules/pipe.d.ts', 0o644)) {
            self::markTestSkipped('FIFOs are not available on this platform.');
        }
        symlink('pipe.d.ts', $this->root . '/node_modules/piped.d.ts');
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAStableProjectWithPlainAndPnpmPackagesScansFullAcrossRequestsWithNothingDegraded(): void
    {
        $pdo = $this->freshTestDatabase();

        $result = $this->service($pdo)->scan($this->root);

        assertSame('full', $result->data['mode']);
        assertSame([], $result->data['degraded_languages']);
        assertSame(9, $result->data['parsed_files']);
        assertSame(1, $result->data['worker_execution']['scan_batches']['knossos.typescript']['files']);
        // Only the keys changed: a node_modules file still owns no contribution.
        $owners = $pdo->query('SELECT DISTINCT owner_key FROM nodes ORDER BY owner_key')->fetchAll(\PDO::FETCH_COLUMN);
        assertSame([], array_values(array_filter($owners, static fn(string $owner): bool => str_contains($owner, 'node_modules'))));
        assertSame(true, in_array('knossos.typescript:file:src/a.ts', $owners, true));
        assertSame(
            ['src/a.ts', 'src/b.ts', 'src/c.ts', 'src/d.ts', 'src/e.ts', 'src/f.ts', 'src/g.ts', 'src/h.ts', 'src/i.ts'],
            $pdo->query('SELECT file_path FROM contribution_cache ORDER BY file_path')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    public function testAPlainPackageDeclarationChangedBeforeTheWorkerReadsItAndRestoredBeforeCommitFailsTheScan(): void
    {
        $this->assertChangedAndRestoredFailsTheScan('node_modules/plain/index.d.ts', ['node_modules/plain/index.d.ts']);
    }

    public function testAPnpmPackageDeclarationChangedBeforeTheWorkerReadsItAndRestoredBeforeCommitFailsTheScan(): void
    {
        $this->assertChangedAndRestoredFailsTheScan(self::PNPM . '/index.d.ts', [self::PNPM . '/index.d.ts', 'node_modules/dep/index.d.ts']);
    }

    /**
     * Discovery ignores `node_modules`, so the file is changed before the scan
     * starts: every worker read sees the changed bytes. It is restored at the
     * service's last cancellation poll before validation, once every worker has
     * returned, so the discovered tree validates and only the re-read of the
     * worker's reads can fail the scan.
     *
     * @param list<string> $keys the keys the worker reports the file under
     */
    private function assertChangedAndRestoredFailsTheScan(string $relativePath, array $keys): void
    {
        $pdo = $this->freshTestDatabase();
        $path = $this->root . '/' . $relativePath;
        $original = (string) file_get_contents($path);
        file_put_contents($path, "export declare class Changed {}\n");
        $scanPolls = 0;
        $token = new CancellationToken(function () use (&$scanPolls, $path, $original): bool {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3] ?? [];
            if (($caller['class'] ?? null) === ProjectScanService::class && ++$scanPolls === 3) {
                file_put_contents($path, $original);
            }

            return false;
        });

        try {
            $error = captureThrows(fn() => $this->service($pdo)->scan($this->root, cancellation: $token), ScanSnapshotChangedException::class);
        } finally {
            file_put_contents($path, $original);
        }

        $messages = array_map(static fn(string $key): string => ScanSnapshotChangedException::inputChangedAfterRead($key)->getMessage(), $keys);
        assertSame(true, in_array($error->getMessage(), $messages, true), $error->getMessage());
        assertSame(3, $scanPolls);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    /**
     * The packaged service with its TypeScript descriptor limited to one file
     * per request. The service builds its runner from the installed
     * descriptors, which fix TypeScript at 2,000 files, so the copy is built
     * without the constructor and given every collaborator of a real one.
     */
    private function service(\PDO $pdo): ProjectScanService
    {
        $real = new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]);
        $class = new ReflectionClass(ProjectScanService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach ($class->getProperties() as $property) {
            if ($property->getName() !== 'languageRunner') {
                $property->setValue($service, $property->getValue($real));
            }
        }
        $descriptors = array_map(
            static fn(LanguageDescriptor $descriptor): LanguageDescriptor => $descriptor->key !== 'typescript' ? $descriptor : new LanguageDescriptor(
                key: $descriptor->key,
                languages: $descriptor->languages,
                command: $descriptor->command,
                stage: $descriptor->stage,
                scanBatchFiles: 1,
                scanBatchSourceBytes: $descriptor->scanBatchSourceBytes,
                optional: $descriptor->optional,
                workerMemoryMb: $descriptor->workerMemoryMb,
            ),
            LanguageDescriptor::installed(self::repositoryRoot()),
        );
        (new ReflectionProperty(ProjectScanService::class, 'languageRunner'))->setValue(
            $service,
            new LanguageScanRunner($descriptors, (new ReflectionProperty(ProjectScanService::class, 'workerPool'))->getValue($real), new ContributionCacheService()),
        );

        return $service;
    }

    private function write(string $relativePath, string $contents): void
    {
        $absolute = $this->root . '/' . $relativePath;
        @mkdir(dirname($absolute), 0o777, true);
        file_put_contents($absolute, $contents);
    }
}
