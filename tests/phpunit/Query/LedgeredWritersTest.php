<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Discovery\AllowedRoots;
use Knossos\Mcp\McpServerAssembly;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

/**
 * Every writer a session can meet records its scans in the scan ledger: the
 * MCP `scan_project` the model calls and `knossos scan` from a shell (which
 * the live watcher's scans run as), each under the scan's own write lease.
 */
final class LedgeredWritersTest extends KnossosTestCase
{
    /** Appends a line to a fixture file, so the next scan changes it. */
    private static function touch(string $root): void
    {
        $file = $root . '/src/Core/Greeter.php';
        file_put_contents($file, file_get_contents($file) . "\n// touched\n");
    }

    /** @return list<array{from_snapshot: string, to_snapshot: string, changes_json: string}> */
    private static function entries(PDO $pdo): array
    {
        /** @var list<array{from_snapshot: string, to_snapshot: string, changes_json: string}> */
        return $pdo->query('SELECT from_snapshot, to_snapshot, changes_json FROM scan_ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    #[Group('query')]
    public function testTheModelsScanProjectIsRecorded(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $from = (new ScanLedger($pdo))->activeSnapshot($projectId);
            self::touch($root);
            $server = (new McpServerAssembly($pdo, self::repositoryRoot(), ':memory:', AllowedRoots::of([(string) realpath($root)])))->dispatcher();
            $answer = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'scan_project', 'arguments' => ['path' => $root]]]);
            assertNotNull($answer);
            $entries = self::entries($pdo);
            assertSame(1, count($entries), (string) json_encode($answer));
            assertSame($from, $entries[0]['from_snapshot']);
            assertSame((new ScanLedger($pdo))->activeSnapshot($projectId), $entries[0]['to_snapshot']);
            assertSame(['src/Core/Greeter.php'], array_keys(json_decode($entries[0]['changes_json'], true)['before']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testKnossosScanFromAShellIsRecorded(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        $database = $root . '/.data/knossos.sqlite';
        $scan = static function () use ($root, $database): void {
            $command = [PHP_BINARY, self::repositoryRoot() . '/bin/knossos', 'scan', $root, '--db=' . $database, '--json'];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assertNotNull($process);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            assertSame(0, proc_close($process));
        };
        try {
            mkdir($root . '/.data');
            $scan();
            $pdo = SqliteConnection::open($database);
            $projectId = (string) $pdo->query('SELECT id FROM projects')->fetchColumn();
            $from = (new ScanLedger($pdo))->activeSnapshot($projectId);
            // The first scan made the project: nothing earlier to describe, so nothing recorded.
            assertSame([], self::entries($pdo));
            self::touch($root);
            $scan();
            $entries = self::entries($pdo);
            assertSame(1, count($entries));
            assertSame($from, $entries[0]['from_snapshot']);
            assertSame((new ScanLedger($pdo))->activeSnapshot($projectId), $entries[0]['to_snapshot']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A caller holding the project's write lease scans under it, and keeps it until it lets go. */
    #[Group('query')]
    public function testAScanUnderAHeldLeaseLeavesTheLeaseToItsHolder(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            self::touch($root);
            $lease = (new ProjectWriterLock($pdo))->acquire($projectId);
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'incremental', lease: $lease);
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());
            assertSame(1, $lease->release());
        } finally {
            $this->removeTempTree($root);
        }
    }
}
