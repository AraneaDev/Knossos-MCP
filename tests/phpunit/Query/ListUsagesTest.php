<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ListUsagesTest extends KnossosTestCase
{
    #[Group('query')]
    public function testListsEveryUsageOccurrenceWithEvidence(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Second call site from Checkout to InvoiceService at a different line:
        // occurrence-level edges mean this is a distinct row, not a merge.
        $second = StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:15');
        $repository->saveEdge($second, $ids['project'], 'calls', $ids['checkout'], $ids['invoice'], $ids['file'], 15, 15, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        // A containment edge that must NOT appear as a usage.
        $contains = StableId::edge($ids['project'], 'contains', $ids['checkout'], $ids['invoice'], 'c:1');
        $repository->saveEdge($contains, $ids['project'], 'contains', $ids['checkout'], $ids['invoice'], $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $result = $queries->listUsages($ids['project'], 'App\\InvoiceService');
        assertSame('App\\InvoiceService', $result->data['target']['canonical_name']);
        assertSame(2, count($result->data['usages']));
        assertSame([12, 15], array_column($result->data['usages'], 'start_line'));
        assertSame('src/Checkout.php', $result->data['usages'][0]['path']);
        assertSame('App\\Checkout', $result->data['usages'][0]['source']['canonical_name']);
        assertSame('calls', $result->data['usages'][0]['kind']);
        // Deterministic: identical on repeat.
        assertSame($result->jsonSerialize(), $queries->listUsages($ids['project'], 'App\\InvoiceService')->jsonSerialize());

        $limited = $queries->listUsages($ids['project'], 'App\\InvoiceService', limit: 1);
        assertSame(1, count($limited->data['usages']));
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);

        assertThrows(fn() => $queries->listUsages($ids['project'], 'App\\InvoiceService', limit: 501), InvalidArgumentException::class);
        assertThrows(fn() => $queries->listUsages($ids['project'], 'App\\InvoiceService', edgeKinds: ['contains']), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testAmbiguousAndMissingSymbolsReportCandidates(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $missing = $queries->listUsages($ids['project'], 'App\\Nothing');
        assertSame(false, array_key_exists('target', $missing->data));
        assertSame([], $missing->data['candidates']);

        // Both fixture classes share the 'App\' prefix; a bare prefix is ambiguous.
        $ambiguous = $queries->listUsages($ids['project'], 'App\\');
        assertSame(true, $ambiguous->data['ambiguous']);
        assertSame(2, count($ambiguous->data['candidates']));
    }

    #[Group('query')]
    public function testDispatchThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('list_usages', ['project_id' => $ids['project'], 'symbol' => 'App\\InvoiceService']);
        assertSame(1, count($result->data['usages']));
        assertThrows(fn() => $tools->call('list_usages', ['project_id' => $ids['project'], 'symbol' => 'X', 'limit' => 0]), InvalidArgumentException::class);
    }
}
