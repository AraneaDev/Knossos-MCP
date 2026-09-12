<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * change_impact's ranking and changed_files_impact's merge, on a graph built
 * for the purpose.
 *
 * Both tools were exercised only on the two-class shop fixture, where every
 * component shares one file and one distance, so the weights, the tie-breaks,
 * the merge across changed files and every bound could change with the suite
 * green. The graph here gives each of those a case where it decides the answer.
 */
final class ChangeImpactRankingTest extends KnossosTestCase
{
    private PDO $pdo;
    private SqliteGraphRepository $repository;
    /** @var array<string, string> */
    private array $ids;

    #[Group('query')]
    public function testChangeImpactAcceptsExactlyItsDocumentedRanges(): void
    {
        $queries = $this->graph()->queries();
        $target = $this->node('App\\Target', 'src/Target.php');
        $this->done();

        foreach ([['sinceDays' => 1], ['sinceDays' => 3650], ['maxCommits' => 1], ['maxCommits' => 5000]] as $inside) {
            assertSame($target, $queries->changeImpact($this->ids['project'], $target, ...$inside)->data['target']['id']);
        }
        foreach ([['sinceDays' => 0], ['sinceDays' => 3651], ['maxCommits' => 0], ['maxCommits' => 5001]] as $outside) {
            assertThrows(fn() => $queries->changeImpact($this->ids['project'], $target, ...$outside), InvalidArgumentException::class);
        }
    }

    /** A target that does not resolve says which way it failed. */
    #[Group('query')]
    public function testAnUnresolvedTargetSaysWhyGitWasNotConsulted(): void
    {
        $this->graph();
        $this->node('App\\Billing\\Ledger', 'src/Billing/Ledger.php');
        $this->node('App\\Stock\\Ledger', 'src/Stock/Ledger.php');
        $this->done();
        $queries = $this->queries(self::history([]));

        $unmatched = $queries->changeImpact($this->ids['project'], 'Nowhere');
        assertSame(['available' => false, 'reason' => 'unmatched_target'], $unmatched->data['git']);
        assertSame([], $unmatched->data['risk_ranking']);

        $ambiguous = $queries->changeImpact($this->ids['project'], 'Ledger');
        assertSame(['available' => false, 'reason' => 'ambiguous_target'], $ambiguous->data['git']);
    }

    /**
     * Churn weighs three per commit and one per author, nearness one per hop
     * not taken, and ties go to the nearer component, then to the name.
     */
    #[Group('query')]
    public function testTheRiskRankingWeighsChurnAuthorsAndNearness(): void
    {
        $this->graph();
        $target = $this->node('App\\Target', 'src/Target.php');
        $near = $this->node('App\\Near', 'src/Near.php');
        $alpha = $this->node('App\\Alpha', 'src/Alpha.php');
        $far = $this->node('App\\Far', 'src/Far.php');
        $aardvark = $this->node('App\\Aardvark', 'src/Aardvark.php');
        $unfiled = $this->node('App\\Unfiled', null);
        $this->edge($near, $target);
        $this->edge($alpha, $target);
        $this->edge($unfiled, $target);
        $this->edge($far, $near);
        $this->edge($aardvark, $near);
        $this->done();
        $queries = $this->queries(self::history([
            'src/Far.php' => ['commit_count' => 1, 'authors' => ['a@example.test'], 'last_changed_at' => '2026-09-01T00:00:00+00:00'],
            'src/Aardvark.php' => ['commit_count' => 0, 'authors' => ['b@example.test'], 'last_changed_at' => '2026-09-01T00:00:00+00:00'],
        ]));

        $result = $queries->changeImpact($this->ids['project'], $target, maxDepth: 2);
        $ranking = $result->data['risk_ranking'];

        // Far: 1 commit x3 + 1 author + 1 hop to spare = 5. Target: 3 hops to
        // spare. Near, Alpha and Unfiled: 2 each, so the name decides. Aardvark:
        // 1 author + 1 = 2 as well, but two hops out, so it follows all three
        // however its name sorts.
        assertSame(
            ['App\\Far', 'App\\Target', 'App\\Alpha', 'App\\Near', 'App\\Unfiled', 'App\\Aardvark'],
            array_map(static fn(array $entry): string => $entry['component']['canonical_name'], $ranking),
        );
        assertSame([5, 3, 2, 2, 2, 2], array_column($ranking, 'score'));
        assertSame(['commit_weight' => 3, 'author_weight' => 1, 'static_proximity_weight' => 1], $ranking[0]['factors']);
        assertSame(0, $ranking[1]['distance'], 'The target ranks as distance 0.');
        assertSame('Ranked 6 statically impacted components with recent Git change signals.', $result->summary);

        // One evidence record per ranked component with a file, pointing at its rank.
        $cited = array_values(array_filter($result->evidence, static fn(array $entry): bool => isset($entry['risk_index'])));
        assertSame([0, 1, 2, 3, 5], array_column($cited, 'risk_index'));
        assertSame($far, $cited[0]['component_id']);
    }

    #[Group('query')]
    public function testALoneTargetIsOneComponentAndHistoryTruncationIsReported(): void
    {
        $this->graph();
        $target = $this->node('App\\Target', 'src/Target.php');
        $this->done();

        $complete = $this->queries(self::history([]))->changeImpact($this->ids['project'], $target);
        assertSame('Ranked 1 statically impacted component with recent Git change signals.', $complete->summary);
        assertSame(false, $complete->truncated);
        assertSame(false, $this->queries()->changeImpact($this->ids['project'], $target)->truncated, 'No history, nothing cut short.');

        $cut = $this->queries(self::history([], truncated: true))->changeImpact($this->ids['project'], $target);
        assertSame(true, $cut->truncated, 'A history read that stopped early leaves the ranking incomplete.');
    }

    /** A failed history read is quoted, from its first byte, up to 500 bytes. */
    #[Group('query')]
    public function testAGitFailureIsQuotedUpToFiveHundredBytes(): void
    {
        $this->graph();
        $target = $this->node('App\\Target', 'src/Target.php');
        $this->done();
        $failing = new class implements GitHistoryProvider {
            public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
            {
                throw new RuntimeException('R' . str_repeat('x', 599));
            }
        };

        $git = $this->queries($failing)->changeImpact($this->ids['project'], $target)->data['git'];

        assertSame(false, $git['available']);
        assertSame('R' . str_repeat('x', 499), $git['reason']);
    }

    #[Group('query')]
    public function testChangedFilesImpactTakesUpToFiftyFiles(): void
    {
        $this->graph();
        $this->done();
        $paths = array_map(static fn(int $index): string => sprintf('src/F%02d.php', $index), range(1, 50));

        $result = $this->queries()->changedFilesImpact($this->ids['project'], $paths);

        assertSame(50, count($result->data['unresolved_files']));
        assertThrows(fn() => $this->queries()->changedFilesImpact($this->ids['project'], [...$paths, 'src/F51.php']), InvalidArgumentException::class);
    }

    /** With no working-tree provider the refusal names that, not the null call it would otherwise make. */
    #[Group('query')]
    public function testAWorkingTreeRequestWithoutAProviderSaysSo(): void
    {
        $this->graph();
        $this->done();

        $error = captureThrows(fn() => $this->queries()->changedFilesImpact($this->ids['project'], workingTree: true), InvalidArgumentException::class);

        assertSame('Working-tree change discovery is unavailable.', $error->getMessage());
    }

    /** The working tree is asked for at most the files one request may name, and its truncation carries through. */
    #[Group('query')]
    public function testTheWorkingTreeIsBoundedAndItsTruncationReported(): void
    {
        $this->graph();
        $this->node('App\\Changed', 'src/Changed.php');
        $this->done();
        $tree = new class implements GitWorkingTreeProvider {
            public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
            {
                assertSame(50, $maxFiles);

                return ['paths' => ['src/Changed.php'], 'renames' => [], 'truncated' => true];
            }
        };

        $fromTree = (new ArchitectureQueryService($this->pdo, gitWorkingTree: $tree))->changedFilesImpact($this->ids['project'], workingTree: true);
        assertSame(true, $fromTree->data['git']['truncated']);
        assertSame(true, $fromTree->truncated);

        $explicit = $this->queries()->changedFilesImpact($this->ids['project'], ['src/Changed.php']);
        assertSame(false, $explicit->data['git']['truncated']);
        assertSame(false, $explicit->truncated);
    }

    /**
     * A dependant two changed files both reach keeps the nearer path, and at
     * the same distance the surer one, whichever changed file sorts first.
     */
    #[Group('query')]
    public function testADependantReachedFromTwoChangedFilesKeepsItsNearestSurestPath(): void
    {
        $this->graph();
        $first = $this->node('App\\First', 'src/A.php');
        $second = $this->node('App\\Second', 'src/B.php');
        $upgraded = $this->node('App\\Upgraded', 'src/Upgraded.php');
        $kept = $this->node('App\\Kept', 'src/Kept.php');
        $route = $this->node('App\\LoginRoute', 'src/LoginRoute.php', 'route');
        $this->edge($upgraded, $first, 'possible');
        $this->edge($upgraded, $second, 'certain');
        $this->edge($kept, $first, 'certain');
        $this->edge($kept, $second, 'possible');
        $this->edge($route, $second);
        // Two hops out, and first by name, so only distance puts it last.
        $this->edge($this->node('App\\Aaa', 'src/Aaa.php'), $kept);
        $this->done();

        $result = $this->queries()->changedFilesImpact($this->ids['project'], ['src/B.php', 'src/A.php']);

        $confidence = array_column(array_map(static fn(array $record): array => [$record['node']['canonical_name'], $record['path_confidence']], $result->data['impacted_components']), 1, 0);
        assertSame('certain', $confidence['App\\Upgraded'], 'The surer path from the second file replaces the first one found.');
        assertSame('certain', $confidence['App\\Kept'], 'A weaker path found later does not replace a surer one.');
        // Ordered by distance, then by name.
        assertSame(['App\\Kept', 'App\\LoginRoute', 'App\\Upgraded', 'App\\Aaa'], array_map(static fn(array $record): string => $record['node']['canonical_name'], $result->data['impacted_components']));
        assertSame([$route], array_map(static fn(array $entry): string => $entry['node']['id'], $result->data['entry_points']));
    }

    #[Group('query')]
    public function testImpactedComponentsAreTruncatedOnlyPastTheLimit(): void
    {
        $this->graph();
        $changed = $this->node('App\\Changed', 'src/Changed.php');
        foreach (['App\\One', 'App\\Two'] as $name) {
            $this->edge($this->node($name, 'src/' . substr($name, 4) . '.php'), $changed);
        }
        $this->done();
        $queries = $this->queries();

        $atLimit = $queries->changedFilesImpact($this->ids['project'], ['src/Changed.php'], limit: 2);
        assertSame(2, count($atLimit->data['impacted_components']));
        assertSame(false, $atLimit->truncated);

        // One changed component whose own search stops at the limit: the merged
        // set fits, but the search that fed it was cut short.
        $cut = $queries->changedFilesImpact($this->ids['project'], ['src/Changed.php'], limit: 1);
        assertSame(1, count($cut->data['impacted_components']));
        assertSame(true, $cut->truncated);
    }

    /**
     * Up to 1,000 components a changed file maps to are searched and returned,
     * and past that the answer says it is incomplete. Evidence stops at 100.
     */
    #[Group('query')]
    public function testDirectComponentsAreBoundedAtOneThousand(): void
    {
        $this->graph();
        $this->pdo->beginTransaction();
        for ($index = 0; $index < 1000; ++$index) {
            $this->node(sprintf('App\\Big%04d', $index), 'src/Big.php');
        }
        $this->pdo->commit();
        $this->done();
        $queries = $this->queries();

        $whole = $queries->changedFilesImpact($this->ids['project'], ['src/Big.php']);
        assertSame(1000, count($whole->data['direct_components']));
        assertSame(false, $whole->truncated);
        assertSame(100, count($whole->evidence));

        // The 1,001st sorts last, and only it has a dependant, so the one
        // component past the bound is also the one that would have found it.
        $last = $this->node('App\\Big9999', 'src/Big.php');
        $this->edge($this->node('App\\Caller', 'src/Caller.php'), $last);
        $over = $queries->changedFilesImpact($this->ids['project'], ['src/Big.php']);
        assertSame(1000, count($over->data['direct_components']));
        assertSame(true, $over->truncated);
        assertSame([], $over->data['impacted_components']);
    }

    private function graph(): self
    {
        [$this->pdo, $this->repository, $this->ids] = $this->storeFixture();

        return $this;
    }

    private function queries(?GitHistoryProvider $history = null): ArchitectureQueryService
    {
        return new ArchitectureQueryService($this->pdo, gitHistory: $history);
    }

    private function done(): void
    {
        $this->repository->completeScan($this->ids['project'], $this->ids['scan']);
    }

    /** A node, in its own file when a path is given; returns its id. */
    private function node(string $name, ?string $path, string $kind = 'class'): string
    {
        $file = null;
        if ($path !== null) {
            $file = StableId::file($this->ids['project'], $path);
            $this->repository->saveFile($file, $this->ids['project'], $path, hash('sha256', $path), 10, 1, 'php', '0.1.0', $this->ids['scan']);
        }
        $id = StableId::symbol($this->ids['project'], 'php', $kind, $name);
        $this->repository->saveNode($id, $this->ids['project'], 'php', $kind, $name, substr($name, (int) strrpos($name, '\\') + 1), null, $file, 1, 2, 'ast', 'certain', [], 'test:' . $name, $this->ids['scan']);

        return $id;
    }

    private function edge(string $source, string $target, string $confidence = 'certain'): void
    {
        $this->repository->saveEdge(
            StableId::edge($this->ids['project'], 'calls', $source, $target, $source . '>' . $target),
            $this->ids['project'], 'calls', $source, $target, null, null, null, 'ast', $confidence, [], 'test:' . $source . '>' . $target, $this->ids['scan'],
        );
    }

    /** @param array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}> $files */
    private static function history(array $files, bool $truncated = false): GitHistoryProvider
    {
        return new class($files, $truncated) implements GitHistoryProvider {
            /** @param array<string, array{commit_count: int, authors: list<string>, last_changed_at: string}> $files */
            public function __construct(private array $files, private bool $truncated) {}

            public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
            {
                return ['files' => $this->files, 'commits_examined' => count($this->files), 'truncated' => $this->truncated];
            }
        };
    }
}
