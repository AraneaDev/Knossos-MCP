<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The quality gate and the trend report read every column of the active graph
 * (`SELECT *`, attributes and owners included) and held it, beside the whole
 * decoded baseline, until the answer was built. They now read through
 * SnapshotGraphReader, which keeps only the columns a comparison uses, and the
 * gate lets the baseline go before it reads the active graph.
 *
 * Each test runs in a process of its own so the peak it measures is its own.
 */
final class QualityGateMemoryTest extends KnossosTestCase
{
    /**
     * Measured peaks on this graph: 222,021,544 bytes reading `SELECT *` for
     * both graphs; 59,971,912 bytes through the reader, one graph at a time;
     * 73,412,328 bytes through the reader with the baseline's rows still held
     * while the active graph is read. The 64 MiB bound sits between the last
     * two, so holding both graphs at once fails it.
     */
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testTheGateReadsOnlyTheColumnsItCompares(): void
    {
        [$pdo, $ids] = $this->graphWithHeavyEdges();
        $queries = new ArchitectureQueryService($pdo);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $gate = $queries->qualityGate($ids['project'], $ids['scan'], ['new_cycles' => 0, 'error_diagnostics' => 0], sarif: true);
        $used = memory_get_peak_usage() - $before;

        self::assertTrue($gate->data['passed']);
        self::assertLessThan(64 * 1024 * 1024, $used, sprintf('Quality gate retained %d bytes.', $used));
    }

    /**
     * Measured peaks on this graph: 142,151,264 bytes reading `SELECT *`;
     * 60,017,832 bytes through the reader. Same 64 MiB bound as the gate.
     */
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testTrendsReadOnlyTheColumnsTheyCount(): void
    {
        [$pdo, $ids] = $this->graphWithHeavyEdges();
        $queries = new ArchitectureQueryService($pdo);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $trends = $queries->architectureTrends($ids['project'], 2);
        $used = memory_get_peak_usage() - $before;

        self::assertSame(60_003, $trends->data['series'][1]['counts']['relationships']);
        self::assertLessThan(64 * 1024 * 1024, $used, sprintf('Trends retained %d bytes.', $used));
    }

    /**
     * Two large graphs: 40,001 calls edges from Checkout to InvoiceService
     * archived as the baseline, and 20,001 more with 2 KB of attributes each
     * added by a second scan. The baseline is many rows with a small archive,
     * so its rows, not its stored payload, decide what holding it costs: a
     * gate that kept them while reading the active graph shows in its peak.
     *
     * @return array{0: \PDO, 1: array<string, string>}
     */
    private function graphWithHeavyEdges(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->edges($repository, $ids, $ids['scan'], 'base', 40_001, false);
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $this->edges($repository, $ids, $next, 'branch', 20_001, true);
        $repository->completeScan($ids['project'], $next);
        gc_collect_cycles();

        return [$pdo, $ids];
    }

    /**
     * Save `$count` calls edges in batches, with 2 KB of attributes each when `$heavy`.
     *
     * The attributes are random hex, which compresses about as well as real
     * attributes do; a repeated character compresses a thousandfold, and one
     * slice of such an archive inflates to the whole graph at once.
     *
     * @param array<string, string> $ids
     */
    private function edges(GraphRepository $repository, array $ids, string $scan, string $tag, int $count, bool $heavy): void
    {
        for ($batch = 0; $batch < $count; $batch += 5_000) {
            $edges = [];
            for ($i = $batch; $i < min($batch + 5_000, $count); $i++) {
                $edges[] = [
                    'id' => StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], $tag . ':' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                    'confidence' => 'certain', 'attributes' => $heavy ? ['note' => bin2hex(random_bytes(1024))] : [], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($edges, $ids, $scan): void {
                $repository->saveEdges($edges, $ids['project'], $scan);
            });
        }
    }
}
