<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Cancellation\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A module under a path the project ignores is left out by the Python worker
 * as it is by discovery, end to end through the real worker: the core sends
 * the project's exclusion rules with every request, so an import of an
 * ignored module resolves to nothing the worker read, and a change to that
 * module cannot change what the scan commits.
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

    public function testAnIgnoredModuleIsNeitherDiscoveredNorRead(): void
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
        // The module declares a class, but its bytes were never read, so the
        // base is only a name the importer used.
        assertSame(
            [['external_symbol', 'generated.models.Model']],
            $pdo->query("SELECT n.kind, n.canonical_name FROM edges e JOIN nodes n ON n.id = e.target_id WHERE e.kind = 'extends'")->fetchAll(\PDO::FETCH_NUM),
        );
    }

    /**
     * Changed at the service's last cancellation poll before validation, once
     * the worker has returned: nothing read the module, so nothing the scan
     * commits depends on it and the scan stands.
     */
    public function testAnIgnoredModuleChangedDuringTheScanLeavesItStanding(): void
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

        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, cancellation: $token);

        assertSame('full', $result->data['mode']);
        assertSame(4, $scanPolls);
        assertSame(['knossos.python:file:app.py'], $pdo->query('SELECT DISTINCT owner_key FROM nodes')->fetchAll(\PDO::FETCH_COLUMN));
    }
}
