<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\BranchDiffService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/**
 * What a branch did to the architecture: the graph now against the retained
 * snapshot taken where the branch left its default branch, found through git.
 */
final class BranchDiffServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** @param list<string> $args */
    private function git(string $root, array $args): void
    {
        $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
    }

    /** A scan that keeps its snapshot, as the watcher's do. */
    private function rescan(PDO $pdo, string $root): void
    {
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, snapshotRetention: 20);
    }

    /**
     * The fixture in a repository on `main`, Core forbidden to depend on Edge,
     * scanned with its snapshot kept at that commit.
     *
     * @return array{0: PDO, 1: string}
     */
    private function repository(): array
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $config = json_decode((string) file_get_contents($root . '/knossos.json'), true, flags: JSON_THROW_ON_ERROR);
        $config['policies'] = [['id' => 'core-stays-out-of-edge', 'from_boundary' => 'Core', 'deny_targets' => ['Edge']]];
        file_put_contents($root . '/knossos.json', json_encode($config, JSON_THROW_ON_ERROR));
        $this->runFixtureCommand(['git', 'init', '--quiet', '--initial-branch=main', $root]);
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '--quiet', '-m', 'first']);
        $this->rescan($pdo, $root);

        return [$pdo, $root];
    }

    /** On a branch: the greeter now calls the caller back (a new cycle crossing into Edge), and a class nothing uses. */
    private function branchWork(PDO $pdo, string $root): void
    {
        $this->git($root, ['checkout', '--quiet', '-b', 'feat']);
        file_put_contents($root . '/src/Core/Greeter.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello, ' . \$name . (new \\App\\Caller())->run();\n    }\n}\n");
        file_put_contents($root . '/src/Core/Unused.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Unused\n{\n}\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '--quiet', '-m', 'branch work']);
        $this->rescan($pdo, $root);
    }

    #[Group('query')]
    public function testTheBranchIsComparedWithTheSnapshotAtItsMergeBase(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            $this->branchWork($pdo, $root);
            $diff = (new BranchDiffService($pdo))->diff($root);
            assertSame('ok', $diff['status']);
            assertSame(['feat', 'main', 1], [$diff['branch'], $diff['default_branch'], $diff['ahead']]);
            assertSame(['exact', 0], [$diff['base']['match'], $diff['base']['commits']]);
            assertSame($diff['merge_base']['rev'], $diff['base']['rev']);
            $c = $diff['comparison'];
            // The greeter's new call crosses from Core into Edge.
            assertGreaterThanOrEqual(1, $c['crossing']['count']);
            $first = $c['crossing']['items'][0];
            assertSame(['Core', 'Edge'], [$first['source']['boundary'], $first['target']['boundary']]);
            assertSame('src/Core/Greeter.php', $first['source']['path']);
            // It closes a cycle that was not there.
            assertSame(1, $c['cycles']['count']);
            assertSame(2, $c['cycles']['items'][0]['size']);
            // The class nothing uses is new dead code; nothing is a hub this small.
            assertContains('App\\Unused', array_column($c['dead_code']['items'], 'canonical_name'));
            assertSame(0, $c['hubs']['count']);
            // The forbidden dependency is a new violation.
            assertGreaterThanOrEqual(1, $c['violations']['count']);
            assertSame('core-stays-out-of-edge', $c['violations']['items'][0]['policy_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheComparisonListsExactlyWhatTheBranchChanged(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            $this->branchWork($pdo, $root);
            $c = (new BranchDiffService($pdo))->diff($root)['comparison'];
            unset($c['base']);
            $greet = ['name' => 'greet', 'canonical_name' => 'App\\Greeter::greet', 'kind' => 'method', 'path' => 'src/Core/Greeter.php', 'line' => 9, 'boundary' => 'Core'];
            $caller = ['name' => 'Caller', 'canonical_name' => 'App\\Caller', 'kind' => 'class', 'path' => 'src/Edge/Caller.php', 'line' => 7, 'boundary' => 'Edge'];
            $run = ['name' => 'run', 'canonical_name' => 'App\\Caller::run', 'kind' => 'method', 'path' => 'src/Edge/Caller.php', 'line' => 9, 'boundary' => 'Edge'];
            $violation = static fn(string $target, string $kind): array => ['policy_id' => 'core-stays-out-of-edge', 'source' => 'App\\Greeter::greet', 'source_kind' => 'method', 'target' => $target, 'target_kind' => $kind];
            // Everything the comparison says, exactly: reading less of each graph must not change a word of it.
            assertSame([
                'crossing' => ['count' => 2, 'items' => [['source' => $greet, 'target' => $caller], ['source' => $greet, 'target' => $run]]],
                'cycles' => ['count' => 1, 'items' => [['size' => 2, 'members' => [$run, $greet]]]],
                'hubs' => ['count' => 0, 'items' => []],
                'dead_code' => ['count' => 1, 'items' => [['name' => 'Unused', 'canonical_name' => 'App\\Unused', 'kind' => 'class', 'path' => 'src/Core/Unused.php', 'line' => 7, 'boundary' => 'Core']]],
                'violations' => ['count' => 2, 'items' => [$violation('App\\Caller', 'class'), $violation('App\\Caller::run', 'method')], 'truncated' => false],
            ], $c);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testANewerDefaultBranchIsMatchedToTheNearestSnapshotBeforeItsMergeBase(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            $this->git($root, ['commit', '--quiet', '--allow-empty', '-m', 'second on main']);
            $this->branchWork($pdo, $root);
            $diff = (new BranchDiffService($pdo))->diff($root);
            assertSame('ok', $diff['status']);
            assertSame(['before', 1], [$diff['base']['match'], $diff['base']['commits']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testWithoutASnapshotNearTheMergeBaseItSaysSo(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            $this->branchWork($pdo, $root);
            $pdo->exec('DELETE FROM scan_snapshots');
            $diff = (new BranchDiffService($pdo))->diff($root);
            assertSame('no-snapshot', $diff['status']);
            assertNull($diff['base']);
            assertNull($diff['comparison']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testOnlyASnapshotAfterTheMergeBaseGivesAPartialComparisonThatSaysSo(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            $this->branchWork($pdo, $root);
            // Only the branch's own snapshot is kept: it already holds the branch's one commit.
            [, $main] = $this->runFixtureCommandOutput(['git', '-C', $root, 'rev-parse', 'main']);
            $pdo->prepare('DELETE FROM scan_snapshots WHERE scan_id IN (SELECT id FROM scans WHERE git_head = ?)')->execute([trim($main)]);
            $file = $root . '/src/Edge/Caller.php';
            file_put_contents($file, (string) file_get_contents($file) . "\n");
            $this->git($root, ['commit', '--quiet', '-am', 'more']);
            $this->rescan($pdo, $root);
            $diff = (new BranchDiffService($pdo))->diff($root);
            assertSame('no-snapshot', $diff['status']);
            assertSame(['after', 1], [$diff['base']['match'], $diff['base']['commits']]);
            assertSame(0, $diff['comparison']['cycles']['count']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheDefaultBranchItselfAndAProjectWithoutGitHaveNothingToCompare(): void
    {
        [$pdo, $root] = $this->repository();
        try {
            assertSame('on-default', (new BranchDiffService($pdo))->diff($root)['status']);
        } finally {
            $this->removeTempTree($root);
        }
        [$pdo, , $plain] = $this->scanTempFixture(self::FIXTURE);
        try {
            assertSame('no-git', (new BranchDiffService($pdo))->diff($plain)['status']);
            assertSame('unscanned', (new BranchDiffService($pdo))->diff(sys_get_temp_dir())['status']);
        } finally {
            $this->removeTempTree($plain);
        }
    }
}
