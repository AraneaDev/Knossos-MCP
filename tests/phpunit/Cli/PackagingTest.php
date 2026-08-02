<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Runtime\DoctorService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class PackagingTest extends KnossosTestCase
{
    #[Group('packaging')]
    public function testDoctorReportsRuntimesWorkersProtocolDatabaseAndMigrations(): void
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $report = (new DoctorService($pdo, self::repositoryRoot(), ':memory:'))->run();
        $byName = [];
        foreach ($report['checks'] as $check) {
            $byName[$check['name']] = $check;
        }
        foreach (['php.version', 'php.extension.pdo_sqlite', 'node.version', 'python.version', 'sqlite.integrity', 'sqlite.migrations', 'worker.php', 'worker.typescript', 'worker.python'] as $name) {
            assertSame(true, isset($byName[$name]));
        }
        assertSame('ok', $byName['worker.php']['status']);
        assertContains('knossos.php@0.5.0 protocol 1.0', $byName['worker.php']['detail']);
        assertSame('ok', $byName['worker.typescript']['status']);
        assertSame('ok', $byName['worker.python']['status']);
        assertContains('knossos.python@0.3.0 protocol 1.0', $byName['worker.python']['detail']);
        assertSame('13 applied', $byName['sqlite.migrations']['detail']);
        // The Node requirement is a floor with no ceiling: every release from 22
        // upwards passes, so a newer runtime than the one this release was built
        // against does not turn a working installation into a failed report.
        preg_match('/v(\d+)\./', $byName['node.version']['detail'], $nodeVersion);
        $nodeMajor = (int) ($nodeVersion[1] ?? 0);
        assertSame($nodeMajor >= 22 ? 'ok' : 'error', $byName['node.version']['status']);
    }
}
