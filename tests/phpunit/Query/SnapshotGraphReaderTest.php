<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\SnapshotGraphReader;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\SnapshotPayload;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

/**
 * A snapshot's graph read with only the columns a comparison uses: the same
 * rows whether the graph is the active one or an archived payload, however
 * that payload was stored.
 */
final class SnapshotGraphReaderTest extends KnossosTestCase
{
    /**
     * The fixture scanned with its snapshot kept: the database, the project
     * and the stored payload of its graph. A rescan archives the graph it
     * replaces; the change it scans (a blank line at a file's end) moves
     * nothing a comparison reads, so the archive and the active graph hold
     * the same rows.
     *
     * @return array{0: PDO, 1: string, 2: string, 3: string}
     */
    private function scanned(): array
    {
        [$pdo, , $root] = $this->scanTempFixture('turn-brief');
        // A change, so the rescan replaces the graph and archives it.
        file_put_contents($root . '/src/Edge/Caller.php', (string) file_get_contents($root . '/src/Edge/Caller.php') . "\n");
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, snapshotRetention: 20);
        $row = $pdo->query('SELECT project_id, payload_json FROM scan_snapshots ORDER BY rowid DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);

        return [$pdo, $root, (string) $row['project_id'], (string) $row['payload_json']];
    }

    #[Group('query')]
    public function testItReadsOnlyTheColumnsAComparisonUses(): void
    {
        [$pdo, $root, $project] = $this->scanned();
        try {
            $facts = (new SnapshotGraphReader($pdo))->active($project, 'active');
            assertSame(array_keys(SnapshotGraphReader::COLUMNS), array_keys($facts));
            foreach (SnapshotGraphReader::COLUMNS as $table => $columns) {
                foreach ($facts[$table] as $row) {
                    assertSame($columns, array_keys($row), $table);
                }
            }
            assertGreaterThan(0, count($facts['edges']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnArchivedPayloadGivesTheSameRowsAsTheActiveGraph(): void
    {
        [$pdo, $root, $project, $stored] = $this->scanned();
        try {
            $reader = new SnapshotGraphReader($pdo);
            $active = $reader->active($project, 'active');
            // As the archive stores it (compressed, read as it inflates), as earlier versions stored it (plain JSON),
            // and compressed but laid out another way (read whole): every way, the rows the active graph has.
            $json = SnapshotPayload::decode($stored);
            $pretty = json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            // The archive's own layout is read as it inflates, never decoded whole.
            $slices = (new \ReflectionMethod($reader, 'slices'))->invoke(null, $stored);
            assertSame(true, is_array((new \ReflectionMethod($reader, 'streamed'))->invoke($reader, $slices, SnapshotGraphReader::COLUMNS)));
            foreach (['compressed' => $stored, 'plain' => $json, 'laid out otherwise' => SnapshotPayload::encode($pretty)] as $how => $payload) {
                $archived = $reader->archived($payload, 'scan_test');
                foreach (SnapshotGraphReader::COLUMNS as $table => $columns) {
                    $keyed = static fn(array $rows): array => array_map(static fn(array $row): array => array_merge(array_fill_keys($columns, null), $row), $rows);
                    assertSame($keyed($active[$table]), $keyed($archived[$table]), $how . ' ' . $table);
                }
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPayloadWithoutFactsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Snapshot archive payload is invalid: scan_x');
        (new SnapshotGraphReader(new PDO('sqlite::memory:')))->archived(SnapshotPayload::encode('{"schema":1}'), 'scan_x');
    }

    /**
     * The gate, the trends and the identity of a component across two graphs
     * need a node's language, the boundaries, and an import's attributes (they
     * say whether it is erased at runtime); every other edge's attributes stay
     * behind, active and archived alike.
     */
    #[Group('query')]
    public function testItKeepsLanguageBoundariesAndOnlyAnImportsAttributes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $module = \Knossos\Store\StableId::symbol($project, 'ts', 'module', 'web#types');
        $repository->saveNode($module, $project, 'ts', 'module', 'web#types', 'types', null, $ids['file'], 1, 1, 'ast', 'certain', [], 'ts:file:types.ts', $ids['scan']);
        $repository->saveEdge(\Knossos\Store\StableId::edge($project, 'imports', $ids['checkout'], $module, 'type'), $project, 'imports', $ids['checkout'], $module, $ids['file'], 2, 2, 'ast', 'certain', ['type_only' => true], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(\Knossos\Store\StableId::edge($project, 'calls', $ids['checkout'], $module, 'call'), $project, 'calls', $ids['checkout'], $module, $ids['file'], 3, 3, 'ast', 'certain', ['note' => 'kept nowhere'], 'php:file:src/Checkout.php', $ids['scan']);
        $boundary = \Knossos\Store\StableId::boundary($project, 'Web', 'explicit');
        $repository->saveBoundary($boundary, $project, 'Web', ['path_prefix' => 'web'], 'explicit', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);
        $repository->archiveActiveSnapshot($project, hash('sha256', '{}'), 5);
        $stored = (string) $pdo->query('SELECT payload_json FROM scan_snapshots ORDER BY rowid DESC LIMIT 1')->fetchColumn();
        $reader = new SnapshotGraphReader($pdo);

        foreach (['active' => $reader->active($project, $ids['scan']), 'archived' => $reader->archived($stored, $ids['scan'])] as $how => $facts) {
            $languages = array_column($facts['nodes'], 'language', 'id');
            assertSame('ts', $languages[$module], $how);
            assertSame('php', $languages[$ids['checkout']], $how);
            assertSame([['id' => $boundary]], $facts['boundaries'], $how);
            $attributes = [];
            foreach ($facts['edges'] as $edge) {
                $attributes[$edge['kind'] . ($edge['target_id'] === $module ? ':module' : '')] = $edge['attributes_json'];
            }
            assertSame(null, $attributes['calls:module'], $how . ': a call keeps no attributes.');
            assertSame(['type_only' => true], json_decode((string) $attributes['imports:module'], true), $how . ': an import keeps them.');
        }
    }

    /**
     * The archive was inflated a megabyte of compressed input at a time, so
     * how much one step produced depended on the compression ratio. 20,001
     * edges carrying the same 2 KB of attributes compress about a
     * thousandfold, and one step inflated the whole 41 MB payload at once.
     * Each step now takes a few kilobytes of input, which bounds its output
     * at zlib's maximum ratio whatever the payload holds.
     *
     * Measured peak: 69,333,248 bytes inflating a megabyte of input per step,
     * 15,895,200 bytes at 4 KB of base64 per step, most of it the 20,002 edge
     * rows read. The 24 MiB bound sits well below the 41 MB payload and the
     * first figure.
     */
    #[Group('query')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testAHighlyCompressibleArchiveIsReadInBoundedMemory(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $note = str_repeat('x', 2048);
        for ($batch = 0; $batch < 20_001; $batch += 5_000) {
            $edges = [];
            for ($i = $batch; $i < min($batch + 5_000, 20_001); $i++) {
                $edges[] = [
                    'id' => \Knossos\Store\StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'dense:' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                    'confidence' => 'certain', 'attributes' => ['note' => $note], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($edges, $ids): void {
                $repository->saveEdges($edges, $ids['project'], $ids['scan']);
            });
        }
        unset($edges);
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $stored = (string) $pdo->query('SELECT payload_json FROM scan_snapshots ORDER BY rowid DESC LIMIT 1')->fetchColumn();
        $reader = new SnapshotGraphReader($pdo);
        assertGreaterThan(40_000_000, strlen(SnapshotPayload::decode($stored)), 'The fixture is the 41 MB payload it describes.');

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $facts = $reader->archived($stored, $ids['scan']);
        $used = memory_get_peak_usage() - $before;

        assertSame(20_002, count($facts['edges']));
        assertSame(true, $used < 24 * 1024 * 1024, sprintf('Reading the archive peaked at %d bytes.', $used));
    }

    /**
     * Read from the store, the payload gives the rows the stored string gives,
     * whichever byte source the runtime offers: a blob stream on PHP 8.4 and
     * later, the fetched string on 8.3.
     */
    #[Group('query')]
    public function testAnArchiveReadFromTheStoreGivesTheRowsItsStringGives(): void
    {
        [$pdo, $root, $project, $stored] = $this->scanned();
        try {
            $scan = (string) $pdo->query('SELECT scan_id FROM scan_snapshots ORDER BY rowid DESC LIMIT 1')->fetchColumn();
            $reader = new SnapshotGraphReader($pdo);

            assertSame($reader->archived($stored, $scan), $reader->archivedById($scan));
            // The fetched-string source, forced so it runs on every runtime,
            // not only where there is no blob stream.
            $fetched = new SnapshotGraphReader($pdo, blobReads: false);
            assertSame(null, (new \ReflectionMethod($fetched, 'payloadBlob'))->invoke($fetched, $scan));
            assertSame($reader->archivedById($scan), $fetched->archivedById($scan));
            assertSame($reader->archivedTablesById($scan, ['edges']), $fetched->archivedTablesById($scan, ['edges']));
            assertSame(true, $reader->isStreamable($scan));
            $whole = json_decode(SnapshotPayload::decode($stored), true, 512, JSON_THROW_ON_ERROR)['facts'];
            foreach (['nodes', 'edges', 'boundary_memberships'] as $table) {
                assertSame($whole[$table], $reader->archivedTablesById($scan, [$table])[$table], $table);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The two byte sources, side by side, on one archive. */
    #[Group('query')]
    public function testTheBlobStreamAndTheFetchedStringGiveTheSameRows(): void
    {
        [$pdo, $root, , $stored] = $this->scanned();
        try {
            self::requireBlobReads($pdo);
            $scan = (string) $pdo->query('SELECT scan_id FROM scan_snapshots ORDER BY rowid DESC LIMIT 1')->fetchColumn();
            $reader = new SnapshotGraphReader($pdo);
            $blob = (new \ReflectionMethod($reader, 'payloadBlob'))->invoke($reader, $scan);
            assertSame(true, is_resource($blob), 'The blob stream is the byte source here.');
            fclose($blob);

            assertSame($reader->archived($stored, $scan), $reader->archivedById($scan));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Through the blob stream the stored payload is never held whole: 20,001
     * edges with 2 KB of random attributes make a payload of tens of megabytes
     * even compressed, and the read peaks below it. Fetching it as one string
     * costs at least its own length, so this fails on that path.
     *
     * Measured on PHP 8.5 with a 33.7 MB payload: 15,834,480 bytes through the
     * blob stream (mostly the 20,002 edge rows read), 49,513,752 bytes with the
     * payload fetched as one string. The bound is three quarters of the
     * payload's length.
     */
    #[Group('query')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTheBlobStreamNeverHoldsThePayloadWhole(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        self::requireBlobReads($pdo);
        for ($batch = 0; $batch < 20_001; $batch += 5_000) {
            $edges = [];
            for ($i = $batch; $i < min($batch + 5_000, 20_001); $i++) {
                $edges[] = [
                    'id' => \Knossos\Store\StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'random:' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                    'confidence' => 'certain', 'attributes' => ['note' => bin2hex(random_bytes(1024))], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($edges, $ids): void {
                $repository->saveEdges($edges, $ids['project'], $ids['scan']);
            });
        }
        unset($edges);
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $size = (int) $pdo->query("SELECT length(payload_json) FROM scan_snapshots WHERE scan_id = '" . $ids['scan'] . "'")->fetchColumn();
        assertGreaterThan(20_000_000, $size, 'The stored payload is tens of megabytes.');
        $reader = new SnapshotGraphReader($pdo);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $facts = $reader->archivedById($ids['scan']);
        $used = memory_get_peak_usage() - $before;

        assertSame(20_002, count($facts['edges']));
        assertSame(true, $used < intdiv($size * 3, 4), sprintf('Reading a %d-byte payload peaked at %d bytes.', $size, $used));
    }

    /** Skips where SQLite's incremental blob reader is absent: PHP 8.3, or a connection that is not Pdo\Sqlite. */
    private static function requireBlobReads(PDO $pdo): void
    {
        if (!is_callable([$pdo, 'openBlob'])) {
            self::markTestSkipped('Pdo\\Sqlite::openBlob() is unavailable (PHP ' . PHP_VERSION . ', ' . $pdo::class . '); it arrived in PHP 8.4.');
        }
    }

    /**
     * openBlob() warns before returning false on a value it cannot open (a
     * NULL, or a row gone since its id was read). The reader must fall back
     * to the fetched string without a warning: the suite fails on any.
     */
    #[Group('query')]
    public function testAPayloadTheBlobReaderCannotOpenFallsBackWithoutAWarning(): void
    {
        $pdo = \Knossos\Store\SqliteConnection::open(':memory:');
        self::requireBlobReads($pdo);
        $pdo->exec('CREATE TABLE scan_snapshots (scan_id TEXT PRIMARY KEY, payload_json TEXT NULL)');
        $pdo->exec("INSERT INTO scan_snapshots VALUES ('scan_null', NULL)");
        $reader = new SnapshotGraphReader($pdo);
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $blob = (new \ReflectionMethod($reader, 'payloadBlob'))->invoke($reader, 'scan_null');
            $gone = (new \ReflectionMethod($reader, 'payloadBlob'))->invoke($reader, 'scan_missing');
            $error = null;
            try {
                $reader->archivedById('scan_null');
            } catch (InvalidArgumentException $caught) {
                $error = $caught;
            }
        } finally {
            restore_error_handler();
        }

        assertSame(null, $blob);
        assertSame(null, $gone);
        assertSame('Snapshot facts are not retained: scan_null', $error?->getMessage());
        assertSame([], $warnings);
    }
}
