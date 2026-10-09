<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
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
     * Measured peak: 81,793,248 bytes reading `SELECT *` for both graphs,
     * 13,823,552 bytes through the reader. The 24 MB bound sits more than
     * three times below the first figure.
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
        self::assertLessThan(24 * 1024 * 1024, $used, sprintf('Quality gate retained %d bytes for 20001 edges.', $used));
    }

    /**
     * Measured peak: 81,812,672 bytes reading `SELECT *`, 13,851,360 bytes
     * through the reader. The 24 MB bound sits more than three times below
     * the first figure.
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

        self::assertSame(20_002, $trends->data['series'][1]['counts']['relationships']);
        self::assertLessThan(24 * 1024 * 1024, $used, sprintf('Trends retained %d bytes for 20001 edges.', $used));
    }

    /**
     * The fixture archived as the baseline, then a second scan adding 20,001
     * calls edges from Checkout to InvoiceService, each with 2 KB of attributes.
     *
     * @return array{0: \PDO, 1: array<string, string>}
     */
    private function graphWithHeavyEdges(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        $note = str_repeat('x', 2048);
        for ($batch = 0; $batch < 20_001; $batch += 5_000) {
            $edges = [];
            for ($i = $batch; $i < min($batch + 5_000, 20_001); $i++) {
                $edges[] = [
                    'id' => StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'heavy:' . $i),
                    'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                    'confidence' => 'certain', 'attributes' => ['note' => $note], 'owner_key' => 'php:file:src/Checkout.php',
                ];
            }
            $repository->bulkTransaction(static function ($repository) use ($edges, $ids, $next): void {
                $repository->saveEdges($edges, $ids['project'], $next);
            });
        }
        $repository->completeScan($ids['project'], $next);
        unset($edges);
        gc_collect_cycles();

        return [$pdo, $ids];
    }
}
