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
            assertSame(true, is_array((new \ReflectionMethod($reader, 'streamed'))->invoke($reader, $stored)));
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
}
