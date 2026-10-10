<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * snapshot_diff decoded an archived payload whole, JSON and arrays, and held
 * it beside the rows it compared. It now reads the archive one table at a
 * time through SnapshotGraphReader's bounded inflation, so the working set is
 * the rows themselves; its answer is unchanged to the byte.
 */
final class SnapshotDiffArchiveTest extends KnossosTestCase
{
    /** Where the answer the whole-payload path gave for the fixture below is kept. */
    private const GOLDEN = __DIR__ . '/../../Fixtures/snapshot-diff/golden.json';

    /**
     * The diff of an archived baseline against the active graph, with added,
     * removed, changed and moved components and added, removed and changed
     * edges, is the one the whole-payload path produced. Only the two
     * snapshots' metadata (which carries the run's timestamps) is left out.
     */
    #[Group('query')]
    public function testTheDiffOfAnArchiveIsTheOneTheWholePayloadPathGave(): void
    {
        [$pdo, $ids] = $this->changedGraph();

        $diff = ArchitectureQueryService::forDatabase($pdo)->snapshotDiff($ids['project'], $ids['scan'], maxChanges: 100);
        $answer = ['summary' => $diff->summary, 'truncated' => $diff->truncated, 'warnings' => $diff->warnings,
            'changes' => $diff->data['changes'], 'confidence_changes' => $diff->data['confidence_changes'], 'bounds' => $diff->data['bounds']];

        self::assertSame((string) file_get_contents(self::GOLDEN), json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $counts = $diff->data['changes']['components']['counts'];
        self::assertSame([1, 1, 1, 1], [$counts['added'], $counts['removed'], $counts['changed'], $counts['moved']], 'The fixture exercises every kind of component change.');
    }

    /**
     * 12,000 components and 12,000 edges, each carrying the same 2 KB of
     * attributes, archived (about 56 MB of payload that compresses about a
     * thousandfold) and diffed against an unchanged active graph.
     *
     * Measured peak: 177,768,336 bytes decoding the payload whole and holding
     * every table of it through the diff; 123,680,384 bytes reading one table
     * at a time, which is the two snapshots' rows for the table being
     * compared. The 144 MiB bound sits between the two.
     */
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testAHighlyCompressibleArchiveIsDiffedWithoutHoldingItsPayload(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $note = str_repeat('x', 2048);
        for ($batch = 0; $batch < 12_000; $batch += 4_000) {
            $edges = [];
            for ($i = $batch; $i < min($batch + 4_000, 12_000); $i++) {
                $edges[] = [
                    'id' => StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'dense:' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                    'confidence' => 'certain', 'attributes' => ['note' => $note], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($edges, $ids): void {
                $repository->saveEdges($edges, $ids['project'], $ids['scan']);
            });
        }
        for ($batch = 0; $batch < 12_000; $batch += 4_000) {
            $nodes = [];
            for ($i = $batch; $i < min($batch + 4_000, 12_000); $i++) {
                $name = sprintf('App\\Dense%05d', $i);
                $nodes[] = [
                    'id' => StableId::symbol($ids['project'], 'php', 'class', $name), 'language' => 'php', 'kind' => 'class',
                    'canonical_name' => $name, 'display_name' => $name, 'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1,
                    'origin' => 'ast', 'confidence' => 'certain', 'attributes' => ['note' => $note], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($nodes, $ids): void {
                $repository->saveNodes($nodes, $ids['project'], $ids['scan']);
            });
        }
        unset($edges, $nodes);
        $next = $this->archiveAndOpen($repository, $ids);
        $repository->completeScan($ids['project'], $next);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $diff = $queries->snapshotDiff($ids['project'], $ids['scan']);
        $used = memory_get_peak_usage() - $before;

        self::assertSame(0, $diff->data['bounds']['total_changes']);
        self::assertTrue($used < 144 * 1024 * 1024, sprintf('snapshot_diff peaked at %d bytes.', $used));
    }

    /**
     * The fixture's graph archived with two more classes and edges, then
     * changed: one class added, one removed, one changed in place, one moved
     * to another file and edited; one edge added, one removed, one changed.
     *
     * @return array{0: PDO, 1: array<string, string>}
     */
    private function changedGraph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $gone = $this->classIn($repository, $ids, $ids['scan'], $ids['file'], 'App\\Gone', 'certain', 40);
        $stays = $this->classIn($repository, $ids, $ids['scan'], $ids['file'], 'App\\Stays', 'certain', 50);
        $goneEdge = $this->edge($repository, $ids, $ids['scan'], $stays, $gone, 'certain');
        $changedEdge = $this->edge($repository, $ids, $ids['scan'], $stays, $ids['checkout'], 'certain');
        $next = $this->archiveAndOpen($repository, $ids);
        $repository->pruneGraph($project, ['nodes' => [$gone => true], 'edges' => [$goneEdge => true]], ['nodes' => [], 'edges' => []]);
        $this->classIn($repository, $ids, $next, $ids['file'], 'App\\Added', 'certain', 60);
        $this->classIn($repository, $ids, $next, $ids['file'], 'App\\Stays', 'probable', 50);
        $moved = StableId::file($project, 'src/InvoiceService.php');
        $repository->saveFile($moved, $project, 'src/InvoiceService.php', hash('sha256', 'moved'), 40, 1, 'php', '0.1.0', $next);
        $repository->saveNode($ids['invoice'], $project, 'php', 'class', 'App\\InvoiceService', 'InvoiceService', null, $moved, 3, 17, 'ast', 'certain', [], 'php:file:src/InvoiceService.php', $next);
        $this->edge($repository, $ids, $next, $stays, $ids['invoice'], 'certain');
        $repository->saveEdge($changedEdge, $project, 'calls', $stays, $ids['checkout'], $ids['file'], 1, 1, 'ast', 'possible', [], 'php:file:src/Checkout.php', $next);
        $repository->completeScan($project, $next);

        return [$pdo, $ids];
    }

    /** @param array<string, string> $ids */
    private function classIn(GraphRepository $repository, array $ids, string $scan, string $file, string $name, string $confidence, int $line): string
    {
        $id = StableId::symbol($ids['project'], 'php', 'class', $name);
        $repository->saveNode($id, $ids['project'], 'php', 'class', $name, substr($name, 4), null, $file, $line, $line + 5, 'ast', $confidence, [], 'php:file:src/Checkout.php', $scan);
        return $id;
    }

    /** @param array<string, string> $ids */
    private function edge(GraphRepository $repository, array $ids, string $scan, string $source, string $target, string $confidence): string
    {
        $id = StableId::edge($ids['project'], 'calls', $source, $target, 'diff');
        $repository->saveEdge($id, $ids['project'], 'calls', $source, $target, $ids['file'], 1, 1, 'ast', $confidence, [], 'php:file:src/Checkout.php', $scan);
        return $id;
    }

    /**
     * Complete and archive the fixture's scan, and open the next one.
     *
     * @param array<string, string> $ids
     */
    private function archiveAndOpen(GraphRepository $repository, array $ids): string
    {
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        return $next;
    }
}
