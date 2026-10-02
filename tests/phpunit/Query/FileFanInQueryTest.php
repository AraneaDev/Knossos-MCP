<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\FileFanInQuery;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;

use function PHPUnit\Framework\assertSame;

/**
 * Per-file fan-in: distinct dependent files, never edges, never the file itself.
 */
final class FileFanInQueryTest extends KnossosTestCase
{
    /** Two files depend on a.php (one through two edge kinds), and a same-file edge adds nothing. */
    #[Group('query')]
    public function testCountsDistinctDependentFilesExcludingTheFileItself(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        $rows = (new FileFanInQuery($pdo))->aboveThreshold($projectId, 1);
        assertSame(
            [
                ['path' => 'a.php', 'dependent_files' => 2, 'boundaries' => ['Core']],
                ['path' => 'b.php', 'dependent_files' => 1, 'boundaries' => []],
            ],
            $rows,
        );
    }

    /** The threshold and the cap each cut the list. */
    #[Group('query')]
    public function testTheThresholdAndCapBound(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        assertSame(['a.php'], array_column((new FileFanInQuery($pdo))->aboveThreshold($projectId, 2), 'path'));
        assertSame(['a.php'], array_column((new FileFanInQuery($pdo))->aboveThreshold($projectId, 1, 1), 'path'));
    }

    /** A file nobody uses reports zero, and the dependents list is alphabetical. */
    #[Group('query')]
    public function testForPathsReportsZeroForAFileNobodyUses(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        $rows = (new FileFanInQuery($pdo))->forPaths($projectId, ['c.php', 'a.php']);
        assertSame(0, $rows['c.php']['dependent_files']);
        assertSame(
            ['path' => 'c.php', 'dependent_files' => 0, 'boundaries' => [], 'top_dependents' => []],
            $rows['c.php'],
        );
        assertSame(2, $rows['a.php']['dependent_files']);
        assertSame(['b.php', 'c.php'], $rows['a.php']['top_dependents']);
        assertSame(['Core'], $rows['a.php']['boundaries']);
        assertSame(1, (new FileFanInQuery($pdo))->forPaths($projectId, ['b.php'])['b.php']['dependent_files']);
    }

    /** The dependents list honours its limit, and an empty path list asks nothing. */
    #[Group('query')]
    public function testForPathsLimitsDependentsAndAcceptsNoPaths(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        $query = new FileFanInQuery($pdo);
        assertSame(['b.php'], $query->forPaths($projectId, ['a.php'], 1)['a.php']['top_dependents']);
        assertSame([], $query->forPaths($projectId, []));
    }

    /** A boundary reached only through a non-impact edge is not reported, matching the dependent count. */
    #[Group('query')]
    public function testBoundariesComeFromImpactEdgesOnly(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        $row = (new FileFanInQuery($pdo))->forPaths($projectId, ['a.php'])['a.php'];
        assertSame(['Core'], $row['boundaries']);
        assertSame(['b.php', 'c.php'], $row['top_dependents']);
    }

    /** The documented defaults: 500 files in a listing, 5 dependents per file. */
    #[Group('query')]
    public function testTheDocumentedDefaults(): void
    {
        $cap = (new ReflectionMethod(FileFanInQuery::class, 'aboveThreshold'))->getParameters()[2];
        $top = (new ReflectionMethod(FileFanInQuery::class, 'forPaths'))->getParameters()[2];
        assertSame(500, $cap->getDefaultValue());
        assertSame(5, $top->getDefaultValue());
    }

    /** An edge kind outside the impact kinds does not count as a dependency. */
    #[Group('query')]
    public function testNonImpactEdgeKindsAreIgnored(): void
    {
        [$pdo, $projectId] = $this->fanInGraph();
        $pdo->exec("UPDATE edges SET kind = 'contains'");
        assertSame([], (new FileFanInQuery($pdo))->aboveThreshold($projectId, 1));
    }

    /**
     * Files a, b, c, d; nodes A and A2 in a, B in b, C in c, D in d. Edges B calls A,
     * C imports A, A2 calls A (same file), C calls B, D contains A (not an impact edge). Boundary Core holds B, Other holds D.
     *
     * @return array{0: PDO, 1: string} [pdo, projectId]
     */
    private function fanInGraph(): array
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $projectId = StableId::project('fan-in-' . bin2hex(random_bytes(4)));
        $scanId = StableId::scan($projectId, 'scan-1');
        $repository->saveProject($projectId, 'Fan In Fixture', '/tmp/knossos-fan-in');
        $repository->createScan($scanId, $projectId, 'full', hash('sha256', 'fan-in'));
        $nodeFiles = ['A' => 'a.php', 'A2' => 'a.php', 'B' => 'b.php', 'C' => 'c.php', 'D' => 'd.php'];
        $fileIds = [];
        foreach (['a.php', 'b.php', 'c.php', 'd.php'] as $path) {
            $fileIds[$path] = StableId::file($projectId, $path);
            $repository->saveFile($fileIds[$path], $projectId, $path, hash('sha256', $path), 1, 1, 'php', '0.1.0', $scanId);
        }
        foreach ($nodeFiles as $name => $path) {
            $repository->saveNode($name, $projectId, 'php', 'class', $name, $name, null, $fileIds[$path], 1, 2, 'scanner', 'certain', [], 'php', $scanId);
        }
        $edges = [['B', 'A', 'calls'], ['C', 'A', 'imports'], ['A2', 'A', 'calls'], ['C', 'B', 'calls'], ['D', 'A', 'contains']];
        foreach ($edges as [$source, $target, $kind]) {
            $repository->saveEdge("{$source}-{$kind}-{$target}", $projectId, $kind, $source, $target, null, null, null, 'scanner', 'certain', [], 'php', $scanId);
        }
        $boundaryId = StableId::boundary($projectId, 'Core', 'explicit');
        $repository->saveBoundary($boundaryId, $projectId, 'Core', [], 'explicit', $scanId);
        $repository->saveBoundaryMembership($boundaryId, $projectId, 'B', $scanId);
        $otherId = StableId::boundary($projectId, 'Other', 'explicit');
        $repository->saveBoundary($otherId, $projectId, 'Other', [], 'explicit', $scanId);
        $repository->saveBoundaryMembership($otherId, $projectId, 'D', $scanId);
        $repository->completeScan($projectId, $scanId);

        return [$pdo, $projectId];
    }
}
