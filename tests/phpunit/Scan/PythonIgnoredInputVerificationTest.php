<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Python worker reports the modules its index reads under a path the
 * project ignores, and the scan re-reads them before it commits, end to end
 * through the real worker.
 *
 * The project's ignore patterns never reach the worker: its module index
 * resolves an import to whatever file the layout holds, so an ignored module
 * feeds the importer's facts while discovery never hashes it. Only the
 * worker's report of the bytes it read and the pre-commit re-read can tell
 * that such a module changed after the worker read it.
 */
#[Group('scan')]
final class PythonIgnoredInputVerificationTest extends KnossosTestCase
{
    private const MODULE = 'generated/models.py';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/generated', 0o777, true);
        file_put_contents($this->root . '/knossos.json', (string) json_encode(['version' => 1, 'ignores' => ['generated/**']]));
        file_put_contents($this->root . '/app.py', "from generated.models import Model\n\n\nclass App(Model):\n    pass\n");
        file_put_contents($this->root . '/' . self::MODULE, "class Model:\n    pass\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAStableProjectReadingAnIgnoredModuleScansFullWithTheModuleUndiscovered(): void
    {
        $pdo = $this->freshTestDatabase();

        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        assertSame('full', $result->data['mode']);
        assertSame([], $result->data['degraded_languages']);
        assertSame(1, $result->data['parsed_files']);
        assertSame(
            ['app.py'],
            $pdo->query('SELECT relative_path FROM files ORDER BY relative_path')->fetchAll(\PDO::FETCH_COLUMN),
        );
        assertSame(
            ['knossos.python:file:app.py'],
            $pdo->query('SELECT DISTINCT owner_key FROM nodes ORDER BY owner_key')->fetchAll(\PDO::FETCH_COLUMN),
        );
        // The ignored module's bytes decided the importer's facts: it declares
        // a class, so the base is one (a plain assignment gives a symbol).
        assertSame(
            [['external_class', 'generated.models.Model']],
            $pdo->query("SELECT n.kind, n.canonical_name FROM edges e JOIN nodes n ON n.id = e.target_id WHERE e.kind = 'extends'")->fetchAll(\PDO::FETCH_NUM),
        );
    }

    /**
     * Changed at the service's last cancellation poll before validation, once
     * the worker has returned, and left changed: discovery ignored the module,
     * so the discovered tree validates and only the re-read of the worker's
     * read can fail the scan.
     */
    public function testAnIgnoredModuleChangedAfterTheWorkerReadItFailsTheScanBeforeAnythingIsWritten(): void
    {
        $pdo = $this->freshTestDatabase();
        $path = $this->root . '/' . self::MODULE;
        $scanPolls = 0;
        $token = new CancellationToken(function () use (&$scanPolls, $path): bool {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3] ?? [];
            if (($caller['class'] ?? null) === ProjectScanService::class && ++$scanPolls === 4) {
                file_put_contents($path, "class Changed:\n    pass\n");
            }

            return false;
        });

        $error = captureThrows(
            fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, cancellation: $token),
            ScanSnapshotChangedException::class,
        );

        assertSame(ScanSnapshotChangedException::inputChangedAfterRead(self::MODULE)->getMessage(), $error->getMessage());
        assertSame(4, $scanPolls);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }
}
